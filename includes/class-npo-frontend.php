<?php
/**
 * Renders the option fields on the single product page and validates
 * the customer's selections before allowing add-to-cart.
 *
 * @package NimblixProductOptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NPO_Frontend {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_fields' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_on_add_to_cart' ), 10, 3 );
	}

	public function enqueue_assets() {
		if ( ! is_product() ) {
			return;
		}

		wp_enqueue_style(
			'npo-frontend',
			NPO_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			npo_asset_version( 'assets/css/frontend.css' )
		);

		wp_enqueue_script(
			'npo-frontend',
			NPO_PLUGIN_URL . 'assets/js/frontend.js',
			array( 'jquery' ),
			npo_asset_version( 'assets/js/frontend.js' ),
			true
		);

		wp_localize_script(
			'npo-frontend',
			'NPO_Frontend_I18n',
			array(
				'requiredField' => __( 'Please complete all required options.', 'nimblix-product-options' ),
				/* translators: %d: minimum number of choices */
				'minChoices'    => __( 'Please select at least %d option(s) for "%s".', 'nimblix-product-options' ),
				/* translators: %d: maximum number of choices */
				'maxChoices'    => __( 'Please select no more than %d option(s) for "%s".', 'nimblix-product-options' ),
			)
		);

		wp_localize_script(
			'npo-frontend',
			'NPO_Currency',
			array(
				'symbol'         => get_woocommerce_currency_symbol(),
				'position'       => get_option( 'woocommerce_currency_pos' ),
				'decimals'       => wc_get_price_decimals(),
				'decimalSep'     => wc_get_price_decimal_separator(),
				'thousandSep'    => wc_get_price_thousand_separator(),
			)
		);
	}

	/**
	 * Get the merged, ordered list of sanitized fields that apply to a product:
	 * assigned global groups first (in menu_order), then this product's own
	 * local fields.
	 *
	 * @param WC_Product $product Product object.
	 *
	 * @return array
	 */
	public static function get_fields_for_product( $product ) {

		$product_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$fields     = array();

		$group_ids = NPO_Assignment::get_group_ids_for_product( $product_id );
		foreach ( $group_ids as $group_id ) {
			$group_fields = get_post_meta( $group_id, '_npo_fields', true );
			if ( is_array( $group_fields ) ) {
				foreach ( $group_fields as $field ) {
					$field['npo_source'] = 'group:' . $group_id;
					$fields[]            = $field;
				}
			}
		}

		$local_fields = get_post_meta( $product_id, '_npo_local_fields', true );
		if ( is_array( $local_fields ) ) {
			foreach ( $local_fields as $field ) {
				$field['npo_source'] = 'local';
				$fields[]            = $field;
			}
		}

		return $fields;
	}

	public function render_fields() {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$fields = self::get_fields_for_product( $product );

		if ( ! $fields ) {
			return;
		}
		?>
		<div
			class="npo-product-fields"
			data-product-id="<?php echo esc_attr( $product->get_id() ); ?>"
			data-base-price="<?php echo esc_attr( $product->get_price() ); ?>"
		>
			<?php foreach ( $fields as $field ) : ?>
				<?php NPO_Field_Renderer::render_frontend_field( $field, $product, $field['npo_source'] ?? 'local' ); ?>
			<?php endforeach; ?>

			<div class="npo-price-summary">
				<div class="npo-price-row">
					<span class="npo-price-label"><?php esc_html_e( 'Product total', 'nimblix-product-options' ); ?></span>
					<span class="npo-price-value npo-product-total"><?php echo wp_kses_post( wc_price( $product->get_price() ) ); ?></span>
				</div>
				<div class="npo-price-row">
					<span class="npo-price-label"><?php esc_html_e( 'Options total', 'nimblix-product-options' ); ?></span>
					<span class="npo-price-value npo-options-total"><?php echo wp_kses_post( wc_price( 0 ) ); ?></span>
				</div>
				<div class="npo-price-row npo-price-row-grand">
					<span class="npo-price-label"><?php esc_html_e( 'Grand total', 'nimblix-product-options' ); ?></span>
					<span class="npo-price-value npo-grand-total"><?php echo wp_kses_post( wc_price( $product->get_price() ) ); ?></span>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Server-side safety net: re-validate required / min / max rules even
	 * if JavaScript was bypassed.
	 *
	 * @param bool $passed        Whether validation currently passes.
	 * @param int  $product_id    Product ID being added to cart.
	 * @param int  $quantity      Quantity being added.
	 *
	 * @return bool
	 */
	public function validate_on_add_to_cart( $passed, $product_id, $quantity ) {

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return $passed;
		}

		$fields = self::get_fields_for_product( $product );
		if ( ! $fields ) {
			return $passed;
		}

		$submitted   = isset( $_POST['npo_fields'] ) ? wp_unslash( $_POST['npo_fields'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$unit_count  = ! empty( $_POST['npo_fields'] ) ? max( 1, (int) $quantity ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		foreach ( $fields as $field ) {

			$field_units = ( isset( $submitted[ $field['id'] ] ) && is_array( $submitted[ $field['id'] ] ) )
				? $submitted[ $field['id'] ]
				: array();

			$units_to_check = ! empty( $field['quantity_based'] ) ? max( 1, (int) $unit_count ) : 1;

			for ( $unit_index = 0; $unit_index < $units_to_check; $unit_index++ ) {

				$unit_value = $field_units[ $unit_index ] ?? null;
				$selected   = 'checkbox' === $field['type']
					? ( is_array( $unit_value ) ? array_map( 'sanitize_text_field', $unit_value ) : array() )
					: ( $unit_value ? array( sanitize_text_field( $unit_value ) ) : array() );

				if ( ! empty( $field['required'] ) && empty( $selected ) ) {
					/* translators: %s: field label */
					wc_add_notice( sprintf( __( '"%s" is required.', 'nimblix-product-options' ), $field['label'] ), 'error' );
					$passed = false;
					continue;
				}

				if ( 'checkbox' === $field['type'] ) {
					if ( '' !== $field['min_choices'] && count( $selected ) < (int) $field['min_choices'] ) {
						/* translators: 1: minimum count, 2: field label */
						wc_add_notice( sprintf( __( 'Please select at least %1$d option(s) for "%2$s".', 'nimblix-product-options' ), (int) $field['min_choices'], $field['label'] ), 'error' );
						$passed = false;
					}
					if ( '' !== $field['max_choices'] && count( $selected ) > (int) $field['max_choices'] ) {
						/* translators: 1: maximum count, 2: field label */
						wc_add_notice( sprintf( __( 'Please select no more than %1$d option(s) for "%2$s".', 'nimblix-product-options' ), (int) $field['max_choices'], $field['label'] ), 'error' );
						$passed = false;
					}
				}
			}
		}

		return $passed;
	}
}