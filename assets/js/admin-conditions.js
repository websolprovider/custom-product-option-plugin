/**
 * Nimblix Product Options — Conditions rule builder.
 *
 * Builds the "when should this field group be displayed" rule groups on
 * the Field Group edit screen: OR'd groups of AND'd rules, each rule
 * currently being "Product [is/is not] equal to [a searchable product]".
 */
/* global jQuery, NPO_Conditions, ajaxurl */

( function ( $ ) {
	'use strict';

	var i18n = ( window.NPO_Conditions && NPO_Conditions.i18n ) || {};

	var existingProductLabels = {};
	try {
		existingProductLabels = JSON.parse( $( '#npo-existing-products-data' ).text() || '{}' ) || {};
	} catch ( e ) {
		existingProductLabels = {};
	}

	function newRule() {
		return { type: 'product', operator: 'equal', value: '' };
	}

	function ConditionsEditor( el ) {
		this.$root  = $( el );
		this.$store = this.$root.find( '.npo-conditions-json-store' );
		this.$list  = this.$root.find( '.npo-conditions-groups' );
		this.data   = { groups: [] };
		this.productLabelCache = $.extend( {}, existingProductLabels );

		try {
			this.data = JSON.parse( this.$store.val() || '{"groups":[]}' ) || { groups: [] };
		} catch ( e ) {
			this.data = { groups: [] };
		}

		this.bindEvents();
		this.render();
	}

	ConditionsEditor.prototype.bindEvents = function () {
		var self = this;

		this.$root.on( 'click', '.npo-add-condition-group', function ( e ) {
			e.preventDefault();
			self.syncFromInputs();
			self.data.groups.push( { rules: [ newRule() ] } );
			self.render();
		} );

		this.$root.on( 'click', '.npo-add-condition-rule', function ( e ) {
			e.preventDefault();
			self.syncFromInputs();
			var groupIndex = $( this ).closest( '.npo-condition-group' ).data( 'index' );
			self.data.groups[ groupIndex ].rules.push( newRule() );
			self.render();
		} );

		this.$root.on( 'click', '.npo-remove-condition-rule', function ( e ) {
			e.preventDefault();
			var $group     = $( this ).closest( '.npo-condition-group' );
			var groupIndex = $group.data( 'index' );
			var ruleIndex  = $( this ).closest( '.npo-condition-rule' ).data( 'index' );

			self.syncFromInputs();
			self.data.groups[ groupIndex ].rules.splice( ruleIndex, 1 );

			if ( ! self.data.groups[ groupIndex ].rules.length ) {
				self.data.groups.splice( groupIndex, 1 );
			}
			self.render();
		} );

		this.$root.on( 'click', '.npo-remove-condition-group', function ( e ) {
			e.preventDefault();
			self.syncFromInputs();
			var groupIndex = $( this ).closest( '.npo-condition-group' ).data( 'index' );
			self.data.groups.splice( groupIndex, 1 );
			self.render();
		} );

		this.$root.closest( 'form' ).on( 'submit', function () {
			self.syncFromInputs();
			self.$store.val( JSON.stringify( self.data ) );
		} );
	};

	ConditionsEditor.prototype.syncFromInputs = function () {
		var self = this;

		this.$list.find( '.npo-condition-group' ).each( function () {
			var groupIndex = $( this ).data( 'index' );
			var group = self.data.groups[ groupIndex ];
			if ( ! group ) {
				return;
			}

			$( this ).find( '.npo-condition-rule' ).each( function () {
				var ruleIndex = $( this ).data( 'index' );
				var rule = group.rules[ ruleIndex ];
				if ( ! rule ) {
					return;
				}
				var $valueSelect = $( this ).find( '.npo-rule-value' );
				rule.operator = $( this ).find( '.npo-rule-operator' ).val();
				rule.value    = $valueSelect.val();

				var selectedText = $valueSelect.find( 'option:selected' ).text();
				if ( rule.value && selectedText ) {
					self.productLabelCache[ rule.value ] = selectedText;
				}
			} );
		} );
	};

	ConditionsEditor.prototype.render = function () {
		var self = this;
		this.$list.empty();

		$.each( this.data.groups, function ( groupIndex, group ) {

			var $group = $( '<div class="npo-condition-group" data-index="' + groupIndex + '"></div>' );
			var $rulesWrap = $( '<div class="npo-condition-rules"></div>' );

			$.each( group.rules, function ( ruleIndex, rule ) {
				$rulesWrap.append( self.renderRule( rule, ruleIndex ) );
			} );

			$group.append( $rulesWrap );
			$group.append(
				'<p class="npo-condition-group-actions">' +
					'<button type="button" class="button-link npo-add-condition-rule">+ ' + ( i18n.and || 'And' ) + '</button>' +
				'</p>'
			);

			self.$list.append( $group );
			self.$list.append( '<button type="button" class="npo-or-trigger npo-add-condition-group">' + ( i18n.or || 'Or' ) + '</button>' );
		} );

		if ( ! this.data.groups.length ) {
			this.$list.append( '<p><button type="button" class="button button-secondary npo-add-condition-group">' + ( i18n.addRuleGroup || 'Add new rule group' ) + '</button></p>' );
		}

		this.initProductSelects();
	};

	ConditionsEditor.prototype.renderRule = function ( rule, ruleIndex ) {
		var $rule = $( '<div class="npo-condition-rule" data-index="' + ruleIndex + '"></div>' );

		$rule.html(
			'<select class="npo-rule-type" disabled="disabled"><option value="product">' + ( i18n.product || 'Product' ) + '</option></select>' +
			'<select class="npo-rule-operator">' +
				'<option value="equal"' + ( 'equal' === rule.operator ? ' selected' : '' ) + '>' + ( i18n.equal || 'Is equal to' ) + '</option>' +
				'<option value="not_equal"' + ( 'not_equal' === rule.operator ? ' selected' : '' ) + '>' + ( i18n.notEqual || 'Is not equal to' ) + '</option>' +
			'</select>' +
			'<select class="npo-rule-value" style="width:260px;"></select>' +
			'<button type="button" class="button-link npo-remove-condition-rule" aria-label="Remove rule">&times;</button>'
		);

		var $valueSelect = $rule.find( '.npo-rule-value' );
		if ( rule.value && this.productLabelCache[ rule.value ] ) {
			$valueSelect.append( '<option value="' + rule.value + '" selected="selected">' + this.productLabelCache[ rule.value ] + '</option>' );
		}

		return $rule;
	};

	ConditionsEditor.prototype.initProductSelects = function () {
		this.$list.find( '.npo-rule-value' ).each( function () {
			var $el = $( this );

			if ( $el.data( 'npo-initialized' ) ) {
				return;
			}
			$el.data( 'npo-initialized', true );

			var init = $.fn.selectWoo || $.fn.select2;
			if ( ! init ) {
				return;
			}

			init.call( $el, {
				ajax: {
					url: ( window.NPO_Conditions && NPO_Conditions.ajaxUrl ) || ajaxurl,
					dataType: 'json',
					delay: 250,
					data: function ( params ) {
						return {
							term: params.term,
							action: 'woocommerce_json_search_products_and_variations',
							security: ( window.NPO_Conditions && NPO_Conditions.productSearchNonce ) || ''
						};
					},
					processResults: function ( data ) {
						var results = [];
						if ( data ) {
							$.each( data, function ( id, text ) {
								results.push( { id: id, text: text } );
							} );
						}
						return { results: results };
					},
					cache: true
				},
				minimumInputLength: 2,
				placeholder: i18n.searchProducts || 'Search for a product…',
				width: '100%'
			} );
		} );
	};

	$( function () {
		$( '.npo-conditions-editor' ).each( function () {
			new ConditionsEditor( this );
		} );
	} );

} )( jQuery );
