<?php
/**
 * A tiny, dependency-free arithmetic expression evaluator.
 *
 * Supports +, -, *, /, parentheses, unary minus, and decimal numbers only.
 * Deliberately does NOT use eval() — WordPress.org plugin review rejects
 * any use of eval(), and this also keeps the calculation genuinely safe
 * regardless of what a store owner types into a formula field.
 *
 * @package NimblixProductOptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NPO_Formula_Evaluator {

	/**
	 * Evaluate a pure arithmetic expression (numbers and + - * / ( ) only).
	 *
	 * @param string $expression Expression, e.g. "10 * (2 + 1) / 3".
	 *
	 * @return float
	 *
	 * @throws Exception If the expression is malformed.
	 */
	public static function evaluate( $expression ) {
		$tokens = self::tokenize( $expression );
		$pos    = 0;
		$result = self::parse_expression( $tokens, $pos );

		if ( $pos !== count( $tokens ) ) {
			throw new Exception( 'Unexpected token in formula.' );
		}

		return $result;
	}

	/**
	 * @param string $expression
	 * @return array List of tokens: numbers (float) and operator strings.
	 */
	private static function tokenize( $expression ) {
		preg_match_all( '/\d+\.\d+|\d+|[()+\-*\/]/', $expression, $matches );
		return $matches[0];
	}

	/**
	 * expression := term (('+' | '-') term)*
	 */
	private static function parse_expression( $tokens, &$pos ) {
		$value = self::parse_term( $tokens, $pos );

		while ( isset( $tokens[ $pos ] ) && in_array( $tokens[ $pos ], array( '+', '-' ), true ) ) {
			$op = $tokens[ $pos ];
			++$pos;
			$rhs = self::parse_term( $tokens, $pos );
			$value = ( '+' === $op ) ? $value + $rhs : $value - $rhs;
		}

		return $value;
	}

	/**
	 * term := factor (('*' | '/') factor)*
	 */
	private static function parse_term( $tokens, &$pos ) {
		$value = self::parse_factor( $tokens, $pos );

		while ( isset( $tokens[ $pos ] ) && in_array( $tokens[ $pos ], array( '*', '/' ), true ) ) {
			$op = $tokens[ $pos ];
			++$pos;
			$rhs = self::parse_factor( $tokens, $pos );
			if ( '*' === $op ) {
				$value = $value * $rhs;
			} else {
				$value = ( 0 == $rhs ) ? 0 : $value / $rhs; // phpcs:ignore Universal.Operators.StrictComparisons -- intentional loose compare for numeric 0.
			}
		}

		return $value;
	}

	/**
	 * factor := number | '(' expression ')' | '-' factor
	 */
	private static function parse_factor( $tokens, &$pos ) {

		if ( ! isset( $tokens[ $pos ] ) ) {
			throw new Exception( 'Unexpected end of formula.' );
		}

		$token = $tokens[ $pos ];

		if ( '-' === $token ) {
			++$pos;
			return -1 * self::parse_factor( $tokens, $pos );
		}

		if ( '(' === $token ) {
			++$pos;
			$value = self::parse_expression( $tokens, $pos );
			if ( ! isset( $tokens[ $pos ] ) || ')' !== $tokens[ $pos ] ) {
				throw new Exception( 'Missing closing parenthesis in formula.' );
			}
			++$pos;
			return $value;
		}

		if ( is_numeric( $token ) ) {
			++$pos;
			return (float) $token;
		}

		throw new Exception( 'Unexpected token in formula: ' . $token );
	}
}
