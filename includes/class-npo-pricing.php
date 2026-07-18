<?php
/**
 * Shared pricing math used by both the cart price adjustment and any
 * front-end price preview.
 *
 * @package NimblixProductOptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NPO_Pricing {

	const TYPE_NONE           = 'none';
	const TYPE_FLAT           = 'flat';
	const TYPE_QUANTITY_FLAT  = 'quantity_flat';
	const TYPE_PERCENTAGE     = 'percentage';
	const TYPE_PERCENTAGE_QTY = 'percentage_qty';
	const TYPE_FORMULA        = 'formula';

	/**
	 * Return the available pricing types, keyed by value.
	 *
	 * @return array
	 */
	public static function get_pricing_types() {
		return array(
			self::TYPE_NONE           => __( 'No price change', 'nimblix-product-options' ),
			self::TYPE_FLAT           => __( 'Flat fee', 'nimblix-product-options' ),
			self::TYPE_QUANTITY_FLAT  => __( 'Quantity based flat fee', 'nimblix-product-options' ),
			self::TYPE_PERCENTAGE     => __( 'Percentage based fee', 'nimblix-product-options' ),
			self::TYPE_PERCENTAGE_QTY => __( 'Quantity based percentage fee', 'nimblix-product-options' ),
			self::TYPE_FORMULA        => __( 'Formula based pricing', 'nimblix-product-options' ),
		);
	}

	/**
	 * Pricing types that use the plain numeric "amount" input in the admin UI
	 * (as opposed to the formula text input).
	 *
	 * @return array
	 */
	public static function get_amount_based_types() {
		return array( self::TYPE_FLAT, self::TYPE_QUANTITY_FLAT, self::TYPE_PERCENTAGE, self::TYPE_PERCENTAGE_QTY );
	}

	/**
	 * Calculate the fee added by a single selected option, for *display
	 * purposes* only (e.g. the "+ $5.00" shown next to an option on the
	 * product page, assuming a quantity of 1).
	 *
	 * @param array $option     Option config (pricing_type, pricing_amount, pricing_formula).
	 * @param float $base_price The product's base (unit) price.
	 * @param int   $quantity   Quantity to preview at (defaults to 1).
	 *
	 * @return float
	 */
	public static function calculate_option_fee( $option, $base_price, $quantity = 1 ) {
		$quantity  = max( 1, (int) $quantity );
		$breakdown = self::get_fee_breakdown( $option, $base_price, $quantity );

		return $breakdown['one_time'] + ( $breakdown['per_unit'] * $quantity );
	}

	/**
	 * Split an option's configured fee into a "one time" component (charged
	 * once no matter how many units are in the cart) and a "per unit"
	 * component (multiplied by the line's quantity). This mirrors how
	 * WooCommerce Product Add-ons style plugins treat "Flat fee" (one-time)
	 * versus "Quantity based flat fee" (scales with quantity).
	 *
	 * @param array $option     Option config: pricing_type, pricing_amount, pricing_formula.
	 * @param float $base_price The product's base (unit) price.
	 * @param int   $quantity   Quantity, used only to resolve {qty} inside a formula.
	 *
	 * @return array {
	 *     @type float $one_time Fee charged once regardless of quantity.
	 *     @type float $per_unit Fee charged per unit of quantity.
	 * }
	 */
	public static function get_fee_breakdown( $option, $base_price, $quantity = 1 ) {

		$pricing_type = $option['pricing_type'] ?? self::TYPE_NONE;
		$amount       = isset( $option['pricing_amount'] ) ? (float) $option['pricing_amount'] : 0.0;
		$formula      = isset( $option['pricing_formula'] ) ? (string) $option['pricing_formula'] : '';
		$base_price   = (float) $base_price;
		$quantity     = max( 1, (int) $quantity );
		$decimals     = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;

		$one_time = 0.0;
		$per_unit = 0.0;

		switch ( $pricing_type ) {

			case self::TYPE_FLAT:
				$one_time = $amount;
				break;

			case self::TYPE_PERCENTAGE:
				$one_time = round( ( $base_price * $amount ) / 100, $decimals );
				break;

			case self::TYPE_QUANTITY_FLAT:
				$per_unit = $amount;
				break;

			case self::TYPE_PERCENTAGE_QTY:
				$per_unit = round( ( $base_price * $amount ) / 100, $decimals );
				break;

			case self::TYPE_FORMULA:
				$per_unit = round( self::evaluate_formula( $formula, $base_price, $quantity ), $decimals );
				break;

			case self::TYPE_NONE:
			default:
				break;
		}

		return array(
			'one_time' => $one_time,
			'per_unit' => $per_unit,
		);
	}

	/**
	 * Safely evaluate a pricing formula. Supports the placeholders {price}
	 * and {qty}, plus +, -, *, /, parentheses, and decimal numbers.
	 * Never uses eval() — the expression is parsed and computed manually
	 * by NPO_Formula_Evaluator.
	 *
	 * @param string $formula    Formula string, e.g. "{price} * 0.1 + 2".
	 * @param float  $base_price Value to substitute for {price}.
	 * @param int    $quantity   Value to substitute for {qty}.
	 *
	 * @return float Result of the calculation, or 0 if the formula is invalid.
	 */
	public static function evaluate_formula( $formula, $base_price, $quantity ) {

		if ( '' === trim( (string) $formula ) ) {
			return 0.0;
		}

		$expression = str_ireplace(
			array( '{price}', '{qty}' ),
			array( (string) (float) $base_price, (string) (int) $quantity ),
			$formula
		);

		// Only digits, whitespace, decimal points, and + - * / ( ) may
		// remain once the known placeholders are substituted.
		if ( ! preg_match( '/^[0-9+\-*\/().\s]+$/', $expression ) ) {
			return 0.0;
		}

		if ( ! class_exists( 'NPO_Formula_Evaluator' ) ) {
			return 0.0;
		}

		try {
			return NPO_Formula_Evaluator::evaluate( $expression );
		} catch ( Exception $e ) {
			return 0.0;
		}
	}
}
