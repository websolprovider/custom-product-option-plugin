/**
 * Nimblix Product Options — Frontend behavior.
 *
 * Handles:
 *  - Show/hide fields based on conditional logic rules.
 *  - Duplicating a field's option block once per unit of quantity, for
 *    fields marked "quantity based".
 *  - A live price summary (Product total / Options total / Grand total).
 *  - A light client-side validation pass before add-to-cart (the
 *    authoritative check still happens server-side).
 */
/* global jQuery, NPO_Frontend_I18n, NPO_Currency */

( function ( $ ) {
	'use strict';

	var i18n     = window.NPO_Frontend_I18n || {};
	var currency = window.NPO_Currency || { symbol: '$', position: 'left', decimals: 2, decimalSep: '.', thousandSep: ',' };

	/**
	 * Format a number the same way wc_price() would, using the store's
	 * currency settings (symbol, position, separators).
	 */
	function formatPrice( amount ) {
		amount = isNaN( amount ) ? 0 : amount;
		var fixed = amount.toFixed( currency.decimals );
		var parts = fixed.split( '.' );
		parts[ 0 ] = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, currency.thousandSep );
		var formatted = parts.join( currency.decimalSep );

		switch ( currency.position ) {
			case 'left':
				return currency.symbol + formatted;
			case 'right':
				return formatted + currency.symbol;
			case 'left_space':
				return currency.symbol + ' ' + formatted;
			case 'right_space':
				return formatted + ' ' + currency.symbol;
			default:
				return currency.symbol + formatted;
		}
	}

	/**
	 * Tiny safe arithmetic evaluator — mirrors includes/class-npo-formula-evaluator.php.
	 * Only +, -, *, /, parentheses, and numbers. No eval().
	 */
	function evaluateFormula( expression ) {
		var tokens = expression.match( /\d+\.\d+|\d+|[()+\-*/]/g ) || [];
		var pos    = 0;

		function parseExpression() {
			var value = parseTerm();
			while ( pos < tokens.length && ( '+' === tokens[ pos ] || '-' === tokens[ pos ] ) ) {
				var op = tokens[ pos ];
				pos++;
				var rhs = parseTerm();
				value = ( '+' === op ) ? value + rhs : value - rhs;
			}
			return value;
		}

		function parseTerm() {
			var value = parseFactor();
			while ( pos < tokens.length && ( '*' === tokens[ pos ] || '/' === tokens[ pos ] ) ) {
				var op = tokens[ pos ];
				pos++;
				var rhs = parseFactor();
				value = ( '*' === op ) ? value * rhs : ( 0 === rhs ? 0 : value / rhs );
			}
			return value;
		}

		function parseFactor() {
			var token = tokens[ pos ];
			if ( undefined === token ) {
				throw new Error( 'Unexpected end of formula' );
			}
			if ( '-' === token ) {
				pos++;
				return -1 * parseFactor();
			}
			if ( '(' === token ) {
				pos++;
				var value = parseExpression();
				if ( ')' !== tokens[ pos ] ) {
					throw new Error( 'Missing closing parenthesis' );
				}
				pos++;
				return value;
			}
			if ( ! isNaN( parseFloat( token ) ) ) {
				pos++;
				return parseFloat( token );
			}
			throw new Error( 'Unexpected token: ' + token );
		}

		try {
			var result = parseExpression();
			return pos === tokens.length ? result : 0;
		} catch ( e ) {
			return 0;
		}
	}

	function evalPricingFormula( formula, basePrice, quantity ) {
		if ( ! formula ) {
			return 0;
		}
		var expr = formula.replace( /\{price\}/gi, String( basePrice ) ).replace( /\{qty\}/gi, String( quantity ) );
		if ( ! /^[0-9+\-*/().\s]+$/.test( expr ) ) {
			return 0;
		}
		return evaluateFormula( expr );
	}

	/**
	 * Split one option's fee into { oneTime, perUnit }, mirroring
	 * NPO_Pricing::get_fee_breakdown() in PHP.
	 */
	function getFeeBreakdown( $input, basePrice, quantity ) {
		var type    = $input.data( 'pricing-type' ) || 'none';
		var amount  = parseFloat( $input.data( 'pricing-amount' ) ) || 0;
		var formula = $input.attr( 'data-pricing-formula' ) || '';
		var oneTime = 0;
		var perUnit = 0;

		switch ( type ) {
			case 'flat':
				oneTime = amount;
				break;
			case 'percentage':
				oneTime = ( basePrice * amount ) / 100;
				break;
			case 'quantity_flat':
				perUnit = amount;
				break;
			case 'percentage_qty':
				perUnit = ( basePrice * amount ) / 100;
				break;
			case 'formula':
				perUnit = evalPricingFormula( formula, basePrice, quantity );
				break;
			default:
				break;
		}

		return { oneTime: oneTime, perUnit: perUnit };
	}

	function getQuantity( $form ) {
		var $qty = $form.find( 'input.qty' ).first();
		var val  = parseInt( $qty.val(), 10 );
		return isNaN( val ) || val < 1 ? 1 : val;
	}

	/**
	 * Recalculate Product total / Options total / Grand total for one
	 * ".npo-product-fields" container and update the summary display.
	 */
	function updatePriceSummary( $container, $form ) {
		var basePrice   = parseFloat( $container.data( 'base-price' ) ) || 0;
		var quantity    = getQuantity( $form );
		var oneTimeSum  = 0;
		var perUnitSum  = 0;

		$container.find( '.npo-field:visible' ).each( function () {
			var $field      = $( this );
			var isQtyBased  = '1' === String( $field.data( 'quantity-based' ) );

			$field.find( '.npo-unit-block' ).each( function () {
				$( this ).find( 'input:checked:not(:disabled)' ).each( function () {
					var breakdown = getFeeBreakdown( $( this ), basePrice, isQtyBased ? 1 : quantity );
					if ( isQtyBased ) {
						oneTimeSum += breakdown.oneTime + breakdown.perUnit;
					} else {
						oneTimeSum += breakdown.oneTime;
						perUnitSum += breakdown.perUnit;
					}
				} );
			} );
		} );

		var unitPrice    = basePrice + perUnitSum + ( oneTimeSum / quantity );
		var productTotal = basePrice * quantity;
		var grandTotal   = unitPrice * quantity;
		var optionsTotal = grandTotal - productTotal;

		$container.find( '.npo-product-total' ).text( formatPrice( productTotal ) );
		$container.find( '.npo-options-total' ).text( formatPrice( optionsTotal ) );
		$container.find( '.npo-grand-total' ).text( formatPrice( grandTotal ) );
	}

	/**
	 * Read the current selection state for one unit block into a plain map
	 * of fieldId -> array of selected option ids (used by conditional
	 * logic, which always evaluates against unit index 0).
	 */
	function getSelectionMap( $container ) {
		var map = {};

		$container.find( '.npo-field' ).each( function () {
			var fieldId = $( this ).data( 'field-id' );
			var $unit0  = $( this ).find( '.npo-unit-block[data-unit-index="0"]' );
			var values  = [];

			$unit0.find( 'input:checked' ).each( function () {
				values.push( $( this ).val() );
			} );

			map[ fieldId ] = values;
		} );

		return map;
	}

	function ruleMatches( rule, selectionMap ) {
		var values = selectionMap[ rule.field_id ] || [];
		var isIn   = values.indexOf( rule.value ) !== -1;
		return 'not_equal' === rule.operator ? ! isIn : isIn;
	}

	function groupMatches( group, selectionMap ) {
		if ( ! group.rules || ! group.rules.length ) {
			return true;
		}
		return group.rules.every( function ( rule ) {
			return ruleMatches( rule, selectionMap );
		} );
	}

	function evaluateConditionalLogic( $container ) {
		var selectionMap = getSelectionMap( $container );

		$container.find( '.npo-field-conditional' ).each( function () {
			var $field = $( this );
			var logic;

			try {
				logic = JSON.parse( $field.attr( 'data-conditional-logic' ) || '{}' );
			} catch ( e ) {
				logic = {};
			}

			var groups = logic.groups || [];
			var visible = ! groups.length || groups.some( function ( group ) {
				return groupMatches( group, selectionMap );
			} );

			$field.toggle( visible );

			// Disable inputs in hidden fields so they don't get submitted
			// (and don't block required-field validation).
			$field.find( 'input' ).prop( 'disabled', ! visible );
		} );
	}

	/**
	 * Wire up quantity-based duplication for a single field.
	 */
	function initQuantityBasedField( $field, $form ) {
		var $unitsWrap = $field.find( '.npo-field-units' );
		var $template  = $field.find( '.npo-unit-template' );

		if ( ! $template.length ) {
			return;
		}

		function syncUnitCount() {
			var qty          = getQuantity( $form );
			var currentUnits = $unitsWrap.find( '.npo-unit-block' ).length;

			if ( qty > currentUnits ) {
				for ( var i = currentUnits; i < qty; i++ ) {
					var html = $template.html().split( '__INDEX__' ).join( i ).split( '__DISPLAY_INDEX__' ).join( i + 1 );
					$unitsWrap.append( html );
				}
			} else if ( qty < currentUnits ) {
				$unitsWrap.find( '.npo-unit-block' ).slice( qty ).remove();
			}
		}

		syncUnitCount();
		$form.on( 'change input', 'input.qty', syncUnitCount );
		// WooCommerce's own quantity +/- buttons trigger a 'change' on the input already.
	}

	function getAddToCartButton( $form ) {
		return $form.find( 'button.single_add_to_cart_button, button[type="submit"]' ).first();
	}

	/**
	 * Re-run validation and reflect the result on the Add to Cart button
	 * itself: disabled while something is missing/invalid, enabled once
	 * everything checks out. Returns the current error list so callers
	 * (e.g. the submit handler) don't have to validate twice.
	 */
	function updateAddToCartButtonState( $container, $form ) {
		var errors  = validateFields( $container, $form );
		var $button = getAddToCartButton( $form );

		if ( errors.length ) {
			$button.prop( 'disabled', true ).addClass( 'npo-disabled' );
		} else {
			$button.prop( 'disabled', false ).removeClass( 'npo-disabled' );
		}

		return errors;
	}

	function initProductFields() {
		$( '.npo-product-fields' ).each( function () {
			var $container = $( this );
			var $form       = $container.closest( 'form.cart' );

			$container.find( '.npo-field-quantity-based' ).each( function () {
				initQuantityBasedField( $( this ), $form );
			} );

			evaluateConditionalLogic( $container );
			updatePriceSummary( $container, $form );
			updateAddToCartButtonState( $container, $form );

			$container.on( 'change', 'input', function () {
				evaluateConditionalLogic( $container );
				updatePriceSummary( $container, $form );
				updateAddToCartButtonState( $container, $form );
			} );

			$form.on( 'change input', 'input.qty', function () {
				updatePriceSummary( $container, $form );
				updateAddToCartButtonState( $container, $form );
			} );

			$form.on( 'submit', function ( e ) {
				var errors = updateAddToCartButtonState( $container, $form );
				if ( errors.length ) {
					e.preventDefault();
					// WooCommerce's own script adds a "loading" spinner class
					// to the button on click, before this submit handler runs.
					// Since we're blocking the submit, nothing will ever clear
					// that class on its own — remove it ourselves so the
					// button doesn't get stuck spinning.
					getAddToCartButton( $form ).removeClass( 'loading' );
					window.alert( errors.join( '\n' ) );
				}
			} );
		} );
	}

	function validateFields( $container, $form ) {
		var errors = [];
		var qty    = getQuantity( $form );

		$container.find( '.npo-field:visible' ).each( function () {
			var $field         = $( this );
			var isCheckbox     = 'checkbox' === $field.data( 'field-type' );
			var isRequired     = $field.find( 'input[data-required="1"]' ).length > 0;
			var isQtyBased     = '1' === String( $field.data( 'quantity-based' ) );
			var unitsToCheck   = isQtyBased ? qty : 1;
			var $meta          = $field.find( '.npo-field-meta' );
			var min            = parseInt( $meta.data( 'min' ), 10 );
			var max            = parseInt( $meta.data( 'max' ), 10 );
			var label          = $field.find( '.npo-field-label' ).text().replace( '*', '' ).trim();

			for ( var u = 0; u < unitsToCheck; u++ ) {
				var $unit    = $field.find( '.npo-unit-block[data-unit-index="' + u + '"]' );
				var $checked = $unit.find( 'input:checked' );

				if ( isRequired && 0 === $checked.length ) {
					errors.push( ( i18n.requiredField || 'Please complete all required options.' ) + ' (' + label + ')' );
					continue;
				}

				if ( isCheckbox ) {
					if ( ! isNaN( min ) && $checked.length < min ) {
						errors.push( label + ': minimum ' + min + ' selection(s) required.' );
					}
					if ( ! isNaN( max ) && $checked.length > max ) {
						errors.push( label + ': maximum ' + max + ' selection(s) allowed.' );
					}
				}
			}
		} );

		return errors;
	}

	$( initProductFields );

} )( jQuery );