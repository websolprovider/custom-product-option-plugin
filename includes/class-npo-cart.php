<?php
/**
 * Captures the customer's submitted option selections into the cart item,
 * adjusts the cart item price accordingly, and displays the selections in
 * the cart and checkout tables.
 *
 * @package NimblixProductOptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NPO_Cart {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'get_cart_item_from_session' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'adjust_price' ), 20, 1 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_in_cart' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_price', array( $this, 'maybe_show_from_price' ), 10, 3 );
	}

	/**
	 * Pull the raw npo_fields[field_id][unit_index] POST data into a
	 * normalized structure:
	 *
	 * array( field_id => array(
	 *     'label'          => ...,
	 *     'type'           => ...,
	 *     'quantity_based' => bool,
	 *     'units'          => array( unit_index => array( option_id => array('label','pricing_type','pricing_amount') ) ),
	 * ) )
	 *
	 * @param WC_Product $product Product being added.
	 *
	 * @return array
	 */
	private function parse_submitted_fields( $product ) {

		if ( empty( $_POST['npo_fields'] ) || ! is_array( $_POST['npo_fields'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return array();
		}

		$raw    = wp_unslash( $_POST['npo_fields'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$fields = NPO_Frontend::get_fields_for_product( $product );
		$result = array();

		foreach ( $fields as $field ) {

			if ( ! isset( $raw[ $field['id'] ] ) || ! is_array( $raw[ $field['id'] ] ) ) {
				continue;
			}

			$units = array();

			foreach ( $raw[ $field['id'] ] as $unit_index => $submitted_value ) {

				$unit_index   = absint( $unit_index );
				$selected_ids = is_array( $submitted_value )
					? array_map( 'sanitize_text_field', $submitted_value )
					: array( sanitize_text_field( $submitted_value ) );

				$selections = array();
				foreach ( $field['options'] as $option ) {
					if ( in_array( $option['id'], $selected_ids, true ) ) {
						$selections[ $option['id'] ] = array(
							'label'           => $option['label'],
							'pricing_type'    => $option['pricing_type'],
							'pricing_amount'  => $option['pricing_amount'],
							'pricing_formula' => $option['pricing_formula'] ?? '',
						);
					}
				}

				if ( $selections ) {
					$units[ $unit_index ] = $selections;
				}
			}

			if ( ! $units ) {
				continue;
			}

			$result[ $field['id'] ] = array(
				'label'          => $field['label'],
				'type'           => $field['type'],
				'quantity_based' => ! empty( $field['quantity_based'] ),
				'units'          => $units,
			);
		}

		return $result;
	}

	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {

		$product    = wc_get_product( $variation_id ? $variation_id : $product_id );
		$selections = $product ? $this->parse_submitted_fields( $product ) : array();

		if ( $selections ) {
			$cart_item_data['npo_selections'] = $selections;
			// Ensures visually identical option choices are still treated as
			// separate cart line items when the selections differ.
			$cart_item_data['unique_key'] = md5( wp_json_encode( $selections ) . microtime() );
		}

		return $cart_item_data;
	}

	public function get_cart_item_from_session( $cart_item, $values ) {
		if ( isset( $values['npo_selections'] ) ) {
			$cart_item['npo_selections'] = $values['npo_selections'];
		}
		return $cart_item;
	}

	/**
	 * Adjust each cart line's unit price to reflect selected options.
	 *
	 * @param WC_Cart $cart Cart object.
	 */
	public function adjust_price( $cart ) {

		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {

			if ( empty( $cart_item['npo_selections'] ) || ! isset( $cart_item['data'] ) ) {
				continue;
			}

			$product        = $cart_item['data'];
			$base_price     = (float) $product->get_price();
			$quantity       = max( 1, (int) $cart_item['quantity'] );
			$one_time_total = 0.0;
			$per_unit_total = 0.0;

			foreach ( $cart_item['npo_selections'] as $field ) {

				$is_quantity_based_field = ! empty( $field['quantity_based'] );

				foreach ( $field['units'] as $unit_selections ) {
					foreach ( $unit_selections as $option ) {

						// Inside a quantity-based field, each unit already
						// represents exactly one unit of quantity, so {qty}
						// in a formula should resolve to 1 for that unit.
						$formula_quantity = $is_quantity_based_field ? 1 : $quantity;
						$breakdown        = NPO_Pricing::get_fee_breakdown( $option, $base_price, $formula_quantity );

						if ( $is_quantity_based_field ) {
							// Each "unit" here already corresponds to one unit of
							// quantity, so its whole fee (flat + per-unit, since a
							// per-unit type is redundant inside a quantity-based
							// field) is a one-off contribution to the line total.
							$one_time_total += $breakdown['one_time'] + $breakdown['per_unit'];
						} else {
							$one_time_total += $breakdown['one_time'];
							$per_unit_total += $breakdown['per_unit'];
						}
					}
				}
			}

			if ( $one_time_total > 0 || $per_unit_total > 0 ) {
				$new_unit_price = $base_price + $per_unit_total + ( $one_time_total / $quantity );
				$product->set_price( round( $new_unit_price, wc_get_price_decimals() ) );
			}
		}
	}

	/**
	 * Show selected options underneath the product name in cart/checkout.
	 *
	 * @param array $item_data Existing item data rows.
	 * @param array $cart_item Cart item.
	 *
	 * @return array
	 */
	public function display_in_cart( $item_data, $cart_item ) {

		if ( empty( $cart_item['npo_selections'] ) ) {
			return $item_data;
		}

		foreach ( $cart_item['npo_selections'] as $field ) {

			$unit_summaries = array();

			foreach ( $field['units'] as $unit_index => $unit_selections ) {
				$labels = wp_list_pluck( $unit_selections, 'label' );
				$labels = array_map( 'esc_html', $labels );

				if ( ! empty( $field['quantity_based'] ) ) {
					/* translators: 1: unit number, 2: comma separated option labels */
					$unit_summaries[] = sprintf( __( 'Unit %1$d: %2$s', 'nimblix-product-options' ), $unit_index + 1, implode( ', ', $labels ) );
				} else {
					$unit_summaries[] = implode( ', ', $labels );
				}
			}

			$item_data[] = array(
				'key'     => $field['label'],
				'value'   => implode( ' | ', $unit_summaries ),
				'display' => '',
			);
		}

		return $item_data;
	}

	/**
	 * Purely cosmetic: nothing to change here for v1, kept as an extension
	 * point for showing "from $X" pricing in a future version.
	 *
	 * @param string $price     Formatted price HTML.
	 * @param array  $cart_item Cart item.
	 * @param string $cart_item_key Cart item key.
	 *
	 * @return string
	 */
	public function maybe_show_from_price( $price, $cart_item, $cart_item_key ) {
		return $price;
	}
}
