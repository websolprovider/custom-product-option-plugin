<?php
/**
 * Copies the customer's option selections from the cart item onto the
 * order line item when the order is placed, using WooCommerce's CRUD
 * methods so it works correctly whether HPOS (High-Performance Order
 * Storage) or legacy post-based orders are in use.
 *
 * @package NimblixProductOptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NPO_Order {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_selections_to_order_item' ), 10, 4 );
		add_action( 'woocommerce_admin_order_item_headers', array( $this, 'noop' ) ); // reserved extension point.
	}

	/**
	 * Fires once per line item while the order is being created.
	 *
	 * @param WC_Order_Item_Product $item          Order line item.
	 * @param string                 $cart_item_key Cart item key.
	 * @param array                  $values        Cart item values.
	 * @param WC_Order               $order         Order object.
	 */
	public function save_selections_to_order_item( $item, $cart_item_key, $values, $order ) {

		if ( empty( $values['npo_selections'] ) ) {
			return;
		}

		foreach ( $values['npo_selections'] as $field ) {

			$unit_summaries = array();

			foreach ( $field['units'] as $unit_index => $unit_selections ) {
				$labels = wp_list_pluck( $unit_selections, 'label' );

				if ( ! empty( $field['quantity_based'] ) ) {
					/* translators: 1: unit number, 2: comma separated option labels */
					$unit_summaries[] = sprintf( __( 'Unit %1$d: %2$s', 'nimblix-product-options' ), $unit_index + 1, implode( ', ', $labels ) );
				} else {
					$unit_summaries[] = implode( ', ', $labels );
				}
			}

			// Human readable value, shown natively in wp-admin order screen,
			// customer emails, and the My Account order view.
			$item->add_meta_data( $field['label'], implode( ' | ', $unit_summaries ), true );
		}

		// Machine readable copy for anything else the store owner (or a
		// future feature) might need to read back out programmatically.
		$item->add_meta_data( '_npo_selections', $values['npo_selections'], true );
	}

	public function noop() {
		// Reserved for future use (e.g. custom formatting of the raw meta
		// key above in the admin order screen).
	}
}
