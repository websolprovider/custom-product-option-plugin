<?php
/**
 * Renders the fields editor UI (used on both the global Field Group screen
 * and the per-product "Product Options" metabox), and renders the actual
 * front-end form fields shown on the single product page.
 *
 * @package NimblixProductOptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NPO_Field_Renderer {

	/**
	 * Print the admin "fields editor" block: a hidden JSON store plus the
	 * container that our JS (assets/js/admin-fields-editor.js) hydrates
	 * into an interactive repeater.
	 *
	 * @param string $input_name Name attribute for the hidden JSON field.
	 * @param array  $fields     Existing field configuration to pre-load.
	 */
	public static function render_admin_editor( $input_name, $fields ) {

		$fields = is_array( $fields ) ? $fields : array();
		?>
		<div class="npo-fields-editor" data-input-name="<?php echo esc_attr( $input_name ); ?>">
			<textarea
				name="<?php echo esc_attr( $input_name ); ?>"
				class="npo-fields-json-store"
				style="display:none;"
			><?php echo esc_textarea( wp_json_encode( array_values( $fields ) ) ); ?></textarea>

			<div class="npo-fields-list"></div>

			<p class="npo-add-field-row">
				<button type="button" class="button button-secondary npo-add-field-btn">
					<?php esc_html_e( '+ Add Field', 'nimblix-product-options' ); ?>
				</button>
			</p>
		</div>
		<?php
	}

	/**
	 * Sanitize a raw fields array coming from $_POST (already json_decode'd)
	 * into a safe, well-formed structure before saving to post meta.
	 *
	 * @param array $raw_fields Decoded JSON array from the client.
	 *
	 * @return array
	 */
	public static function sanitize_fields( $raw_fields ) {

		if ( ! is_array( $raw_fields ) ) {
			return array();
		}

		$clean = array();

		foreach ( $raw_fields as $field ) {

			if ( empty( $field['type'] ) || ! in_array( $field['type'], array( 'checkbox', 'radio' ), true ) ) {
				continue;
			}

			$field_id = ! empty( $field['id'] ) ? sanitize_key( $field['id'] ) : 'npo_' . wp_generate_password( 8, false, false );

			$options = array();
			if ( ! empty( $field['options'] ) && is_array( $field['options'] ) ) {
				foreach ( $field['options'] as $option ) {
					if ( ! isset( $option['label'] ) || '' === trim( (string) $option['label'] ) ) {
						continue;
					}
					$options[] = array(
						'id'              => ! empty( $option['id'] ) ? sanitize_key( $option['id'] ) : 'opt_' . wp_generate_password( 6, false, false ),
						'label'           => sanitize_text_field( $option['label'] ),
						'pricing_type'    => isset( $option['pricing_type'] ) && array_key_exists( $option['pricing_type'], NPO_Pricing::get_pricing_types() )
							? $option['pricing_type']
							: NPO_Pricing::TYPE_NONE,
						'pricing_amount'  => isset( $option['pricing_amount'] ) ? floatval( $option['pricing_amount'] ) : 0,
						'pricing_formula' => isset( $option['pricing_formula'] ) ? sanitize_text_field( $option['pricing_formula'] ) : '',
						'selected'        => ! empty( $option['selected'] ),
					);
				}
			}

			$conditional_logic = array( 'groups' => array() );
			if ( ! empty( $field['conditional_logic']['groups'] ) && is_array( $field['conditional_logic']['groups'] ) ) {
				foreach ( $field['conditional_logic']['groups'] as $group ) {
					if ( empty( $group['rules'] ) || ! is_array( $group['rules'] ) ) {
						continue;
					}
					$clean_rules = array();
					foreach ( $group['rules'] as $rule ) {
						if ( empty( $rule['field_id'] ) ) {
							continue;
						}
						$clean_rules[] = array(
							'field_id' => sanitize_key( $rule['field_id'] ),
							'operator' => in_array( $rule['operator'] ?? '', array( 'equal', 'not_equal' ), true ) ? $rule['operator'] : 'equal',
							'value'    => sanitize_key( $rule['value'] ?? '' ),
						);
					}
					if ( $clean_rules ) {
						$conditional_logic['groups'][] = array( 'rules' => $clean_rules );
					}
				}
			}

			$clean[] = array(
				'id'                  => $field_id,
				'type'                => $field['type'],
				'label'               => sanitize_text_field( $field['label'] ?? '' ),
				'instructions'        => sanitize_text_field( $field['instructions'] ?? '' ),
				'required'            => ! empty( $field['required'] ),
				'min_choices'         => isset( $field['min_choices'] ) && '' !== $field['min_choices'] ? absint( $field['min_choices'] ) : '',
				'max_choices'         => isset( $field['max_choices'] ) && '' !== $field['max_choices'] ? absint( $field['max_choices'] ) : '',
				'quantity_based'      => ! empty( $field['quantity_based'] ),
				'options'             => $options,
				'conditional_enabled' => ! empty( $field['conditional_enabled'] ),
				'conditional_logic'   => $conditional_logic,
			);
		}

		return $clean;
	}

	/**
	 * Render one field on the single product page front-end.
	 *
	 * Inputs are always namespaced with a "unit index", e.g.
	 * npo_fields[field_id][0][] — for a normal field there is always
	 * exactly one unit (index 0). For a "quantity based" field, our
	 * front-end JS clones the unit block once per quantity ordered, so the
	 * customer can make a separate selection for each unit.
	 *
	 * @param array      $field   Sanitized field configuration.
	 * @param WC_Product $product Current product.
	 * @param string     $source  'group:<id>' or 'local', used for reference/debugging.
	 */
	public static function render_frontend_field( $field, $product, $source = 'local' ) {

		$has_rules = ! empty( $field['conditional_enabled'] ) && ! empty( $field['conditional_logic']['groups'] );

		$wrapper_classes = array( 'npo-field', 'npo-field-' . esc_attr( $field['type'] ) );
		if ( $has_rules ) {
			$wrapper_classes[] = 'npo-field-conditional';
		}
		if ( ! empty( $field['quantity_based'] ) ) {
			$wrapper_classes[] = 'npo-field-quantity-based';
		}
		?>
		<div
			class="<?php echo esc_attr( implode( ' ', $wrapper_classes ) ); ?>"
			data-field-id="<?php echo esc_attr( $field['id'] ); ?>"
			data-field-type="<?php echo esc_attr( $field['type'] ); ?>"
			data-quantity-based="<?php echo ! empty( $field['quantity_based'] ) ? '1' : '0'; ?>"
			<?php if ( $has_rules ) : ?>
				data-conditional-logic="<?php echo esc_attr( wp_json_encode( $field['conditional_logic'] ) ); ?>"
			<?php endif; ?>
		>
			<div class="npo-field-label-row">
				<label class="npo-field-label">
					<?php echo esc_html( $field['label'] ); ?>
					<?php if ( ! empty( $field['required'] ) ) : ?>
						<span class="npo-required">*</span>
					<?php endif; ?>
				</label>
				<?php if ( ! empty( $field['instructions'] ) ) : ?>
					<p class="npo-field-instructions"><?php echo esc_html( $field['instructions'] ); ?></p>
				<?php endif; ?>
			</div>

			<div class="npo-field-units">
				<?php self::render_unit_block( $field, $product, 0 ); ?>
			</div>

			<?php if ( ! empty( $field['quantity_based'] ) ) : ?>
				<template class="npo-unit-template">
					<?php self::render_unit_block( $field, $product, '__INDEX__' ); ?>
				</template>
			<?php endif; ?>

			<input type="hidden" class="npo-field-meta" data-min="<?php echo esc_attr( $field['min_choices'] ); ?>" data-max="<?php echo esc_attr( $field['max_choices'] ); ?>" />
		</div>
		<?php
	}

	/**
	 * Render a single "unit" (one repetition, for quantity-based fields) of
	 * a field's option list.
	 *
	 * @param array      $field       Sanitized field configuration.
	 * @param WC_Product $product     Current product.
	 * @param int|string $unit_index  Numeric index, or the '__INDEX__' placeholder used inside the <template>.
	 */
	private static function render_unit_block( $field, $product, $unit_index ) {

		$input_base    = 'npo_fields[' . $field['id'] . '][' . $unit_index . ']';
		$group_name    = 'radio' === $field['type'] ? $input_base : $input_base . '[]';
		$show_fee_text = 'yes' === NPO_Settings::instance()->get_settings()['show_price_next_to_option'];
		?>
		<div class="npo-unit-block" data-unit-index="<?php echo esc_attr( $unit_index ); ?>">
			<?php if ( ! empty( $field['quantity_based'] ) ) : ?>
				<p class="npo-unit-label">
					<?php
					/* translators: %s: unit number placeholder, replaced client-side for cloned units */
					echo esc_html( sprintf( __( 'Unit #%s', 'nimblix-product-options' ), is_numeric( $unit_index ) ? $unit_index + 1 : '__DISPLAY_INDEX__' ) );
					?>
				</p>
			<?php endif; ?>

			<div class="npo-field-options">
				<?php foreach ( $field['options'] as $option ) : ?>
					<?php
					$fee_display = NPO_Pricing::calculate_option_fee( $option, $product->get_price(), 1 );
					?>
					<label class="npo-option">
						<input
							type="<?php echo 'checkbox' === $field['type'] ? 'checkbox' : 'radio'; ?>"
							name="<?php echo esc_attr( $group_name ); ?>"
							value="<?php echo esc_attr( $option['id'] ); ?>"
							data-pricing-type="<?php echo esc_attr( $option['pricing_type'] ); ?>"
							data-pricing-amount="<?php echo esc_attr( $option['pricing_amount'] ); ?>"
							data-pricing-formula="<?php echo esc_attr( $option['pricing_formula'] ?? '' ); ?>"
							<?php checked( ! empty( $option['selected'] ) ); ?>
							<?php echo ! empty( $field['required'] ) ? 'data-required="1"' : ''; ?>
						/>
						<span class="npo-option-text">
							<?php echo esc_html( $option['label'] ); ?>
							<?php if ( $show_fee_text && $fee_display > 0 && NPO_Pricing::TYPE_NONE !== $option['pricing_type'] ) : ?>
								<span class="npo-option-fee">(<?php echo wp_kses_post( wc_price( $fee_display ) ); ?>)</span>
							<?php endif; ?>
						</span>
					</label>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
}
