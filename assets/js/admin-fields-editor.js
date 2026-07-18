/**
 * Nimblix Product Options — Admin Fields Editor
 *
 * Hydrates every ".npo-fields-editor" container on the page into an
 * interactive repeater: add/remove fields, add/remove/reorder options,
 * and build conditional-logic rule groups. On submit, the whole model is
 * serialized back into the container's hidden JSON textarea.
 */
/* global jQuery, NPO_Admin, ajaxurl */

( function ( $ ) {
	'use strict';

	var i18n         = ( window.NPO_Admin && NPO_Admin.i18n ) || {};
	var pricingTypes = ( window.NPO_Admin && NPO_Admin.pricingTypes ) || {};

	function uid( prefix ) {
		return prefix + '_' + Math.random().toString( 36 ).substr( 2, 9 );
	}

	function newOption() {
		return {
			id: uid( 'opt' ),
			label: '',
			pricing_type: 'none',
			pricing_amount: 0,
			selected: false
		};
	}

	function newField( type ) {
		return {
			id: uid( 'npo' ),
			type: type,
			label: i18n.newFieldLabel || 'New Field',
			instructions: '',
			required: false,
			min_choices: '',
			max_choices: '',
			quantity_based: false,
			options: [ newOption() ],
			conditional_enabled: false,
			conditional_logic: { groups: [] }
		};
	}

	function pricingOptionsHtml( selected ) {
		var html = '';
		$.each( pricingTypes, function ( value, label ) {
			html += '<option value="' + value + '"' + ( value === selected ? ' selected="selected"' : '' ) + '>' + label + '</option>';
		} );
		return html;
	}

	/**
	 * One editor instance, bound to a single ".npo-fields-editor" element.
	 */
	function FieldsEditor( el ) {
		this.$root  = $( el );
		this.$store = this.$root.find( '.npo-fields-json-store' );
		this.$list  = this.$root.find( '.npo-fields-list' );
		this.fields = [];

		try {
			this.fields = JSON.parse( this.$store.val() || '[]' ) || [];
		} catch ( e ) {
			this.fields = [];
		}

		this.bindEvents();
		this.render();
	}

	FieldsEditor.prototype.bindEvents = function () {
		var self = this;

		this.$root.on( 'click', '.npo-add-field-btn', function ( e ) {
			e.preventDefault();
			self.fields.push( newField( 'checkbox' ) );
			self.render();
		} );

		this.$root.on( 'click', '.npo-remove-field', function ( e ) {
			e.preventDefault();
			if ( ! window.confirm( i18n.removeField || 'Remove this field?' ) ) {
				return;
			}
			var index = $( this ).closest( '.npo-field-panel' ).data( 'index' );
			self.fields.splice( index, 1 );
			self.render();
		} );

		this.$root.on( 'click', '.npo-field-panel-header', function ( e ) {
			if ( $( e.target ).closest( '.npo-remove-field' ).length ) {
				return;
			}
			$( this ).closest( '.npo-field-panel' ).toggleClass( 'is-open' );
		} );

		this.$root.on( 'input change', '[data-model]', function () {
			self.syncFromInputs();
		} );

		this.$root.on( 'click', '.npo-add-option-btn', function ( e ) {
			e.preventDefault();
			var index = $( this ).closest( '.npo-field-panel' ).data( 'index' );
			self.fields[ index ].options.push( newOption() );
			self.render( index );
		} );

		this.$root.on( 'click', '.npo-remove-option', function ( e ) {
			e.preventDefault();
			var $panel     = $( this ).closest( '.npo-field-panel' );
			var fieldIndex = $panel.data( 'index' );
			var optIndex   = $( this ).closest( '.npo-option-row' ).data( 'index' );
			self.fields[ fieldIndex ].options.splice( optIndex, 1 );
			self.render( fieldIndex );
		} );

		this.$root.on( 'click', '.npo-remove-rule', function ( e ) {
			e.preventDefault();
			var $panel     = $( this ).closest( '.npo-field-panel' );
			var fieldIndex = $panel.data( 'index' );
			var groupIndex = $( this ).closest( '.npo-rule-group' ).data( 'index' );
			var ruleIndex  = $( this ).closest( '.npo-rule-row' ).data( 'index' );
			var rules      = self.fields[ fieldIndex ].conditional_logic.groups[ groupIndex ].rules;
			rules.splice( ruleIndex, 1 );
			if ( ! rules.length ) {
				self.fields[ fieldIndex ].conditional_logic.groups.splice( groupIndex, 1 );
			}
			self.render( fieldIndex );
		} );

		this.$root.on( 'click', '.npo-add-rule-group', function ( e ) {
			e.preventDefault();
			var index = $( this ).closest( '.npo-field-panel' ).data( 'index' );
			self.fields[ index ].conditional_logic.groups.push( { rules: [ { field_id: '', operator: 'equal', value: '' } ] } );
			self.render( index );
		} );

		this.$root.on( 'click', '.npo-add-rule', function ( e ) {
			e.preventDefault();
			var $panel     = $( this ).closest( '.npo-field-panel' );
			var fieldIndex = $panel.data( 'index' );
			var groupIndex = $( this ).closest( '.npo-rule-group' ).data( 'index' );
			self.fields[ fieldIndex ].conditional_logic.groups[ groupIndex ].rules.push( { field_id: '', operator: 'equal', value: '' } );
			self.render( fieldIndex );
		} );

		this.$root.on( 'click', '.npo-remove-rule-group', function ( e ) {
			e.preventDefault();
			var $panel     = $( this ).closest( '.npo-field-panel' );
			var fieldIndex = $panel.data( 'index' );
			var groupIndex = $( this ).closest( '.npo-rule-group' ).data( 'index' );
			self.fields[ fieldIndex ].conditional_logic.groups.splice( groupIndex, 1 );
			self.render( fieldIndex );
		} );

		// Keep the hidden JSON store in sync right before the post is saved.
		this.$root.closest( 'form' ).on( 'submit', function () {
			self.syncFromInputs();
			self.$store.val( JSON.stringify( self.fields ) );
		} );
	};

	/**
	 * Read every [data-model] input currently in the DOM back into
	 * this.fields, without a full re-render (keeps focus/typing smooth).
	 */
	FieldsEditor.prototype.syncFromInputs = function () {
		var self = this;

		this.$list.find( '.npo-field-panel' ).each( function () {
			var $panel = $( this );
			var index  = $panel.data( 'index' );
			var field  = self.fields[ index ];

			if ( ! field ) {
				return;
			}

			$panel.find( '> .npo-field-panel-body [data-model]' ).each( function () {
				var $input = $( this );
				var model  = $input.data( 'model' );

				// Skip inputs that belong to a nested option/rule row; those
				// are handled separately below.
				if ( $input.closest( '.npo-option-row' ).length || $input.closest( '.npo-rule-row' ).length ) {
					return;
				}

				if ( 'checkbox' === $input.attr( 'type' ) ) {
					field[ model ] = $input.is( ':checked' );
				} else {
					field[ model ] = $input.val();
				}
			} );

			$panel.find( '.npo-option-row' ).each( function () {
				var $row     = $( this );
				var optIndex = $row.data( 'index' );
				var option   = field.options[ optIndex ];

				if ( ! option ) {
					return;
				}

				$row.find( '[data-model]' ).each( function () {
					var $input = $( this );
					var model  = $input.data( 'model' );
					if ( 'checkbox' === $input.attr( 'type' ) ) {
						option[ model ] = $input.is( ':checked' );
					} else if ( 'pricing_amount' === model ) {
						option[ model ] = parseFloat( $input.val() ) || 0;
					} else {
						option[ model ] = $input.val();
					}
				} );
			} );

			field.conditional_logic.groups.forEach( function ( group, groupIndex ) {
				group.rules.forEach( function ( rule, ruleIndex ) {
					var $row = $panel.find(
						'.npo-rule-group[data-index="' + groupIndex + '"] .npo-rule-row[data-index="' + ruleIndex + '"]'
					);
					$row.find( '[data-model]' ).each( function () {
						var $input = $( this );
						rule[ $input.data( 'model' ) ] = $input.val();
					} );
				} );
			} );
		} );
	};

	FieldsEditor.prototype.otherFieldOptions = function ( currentIndex, selectedId ) {
		var html = '<option value="">' + '&mdash;' + '</option>';
		$.each( this.fields, function ( i, f ) {
			if ( i === currentIndex ) {
				return;
			}
			html += '<option value="' + f.id + '"' + ( f.id === selectedId ? ' selected="selected"' : '' ) + '>' + ( f.label || f.id ) + '</option>';
		} );
		return html;
	};

	FieldsEditor.prototype.render = function ( focusIndex ) {
		var self = this;
		this.$list.empty();

		$.each( this.fields, function ( index, field ) {
			self.$list.append( self.renderFieldPanel( field, index ) );
		} );

		this.initSortables();

		if ( 'undefined' !== typeof focusIndex ) {
			this.$list.find( '.npo-field-panel[data-index="' + focusIndex + '"]' ).addClass( 'is-open' );
		}
	};

	FieldsEditor.prototype.renderFieldPanel = function ( field, index ) {
		var self       = this;
		var typeLabel  = 'checkbox' === field.type ? ( i18n.checkboxField || 'Checkboxes' ) : ( i18n.radioField || 'Radio Buttons' );
		var $panel     = $( '<div class="npo-field-panel" data-index="' + index + '"></div>' );

		var $header = $(
			'<div class="npo-field-panel-header">' +
				'<span class="npo-drag-handle" title="Drag to reorder">&#9776;</span>' +
				'<span class="npo-field-type-badge">' + typeLabel + '</span>' +
				'<span class="npo-field-panel-title">' + ( field.label || '(' + ( i18n.newFieldLabel || 'New Field' ) + ')' ) + '</span>' +
				'<button type="button" class="button-link npo-remove-field" aria-label="Remove field">&times;</button>' +
			'</div>'
		);

		var $body = $( '<div class="npo-field-panel-body"></div>' );

		// --- Type -------------------------------------------------------
		$body.append(
			'<div class="npo-row">' +
				'<label>Type</label>' +
				'<select data-model="type">' +
					'<option value="checkbox"' + ( 'checkbox' === field.type ? ' selected' : '' ) + '>' + ( i18n.checkboxField || 'Checkboxes' ) + '</option>' +
					'<option value="radio"' + ( 'radio' === field.type ? ' selected' : '' ) + '>' + ( i18n.radioField || 'Radio Buttons' ) + '</option>' +
				'</select>' +
			'</div>'
		);

		// --- Label --------------------------------------------------------
		$body.append(
			'<div class="npo-row">' +
				'<label>Label</label>' +
				'<input type="text" data-model="label" value="' + self.escAttr( field.label ) + '" placeholder="e.g. Select Weeks" />' +
			'</div>'
		);

		// --- Instructions ---------------------------------------------------
		$body.append(
			'<div class="npo-row">' +
				'<label>Instructions</label>' +
				'<input type="text" data-model="instructions" value="' + self.escAttr( field.instructions ) + '" placeholder="Shown as helper text under the label" />' +
			'</div>'
		);

		// --- Required -----------------------------------------------------
		$body.append(
			'<div class="npo-row npo-row-inline">' +
				'<label><input type="checkbox" data-model="required" ' + ( field.required ? 'checked' : '' ) + ' /> Required</label>' +
			'</div>'
		);

		// --- Options --------------------------------------------------------
		var $optionsWrap = $(
			'<div class="npo-options-repeater">' +
				'<label class="npo-section-label">Options</label>' +
				'<div class="npo-options-header">' +
					'<span class="npo-col-drag"></span>' +
					'<span class="npo-col-label">Option label</span>' +
					'<span class="npo-col-pricing">Adjust pricing</span>' +
					'<span class="npo-col-amount">Pricing amount</span>' +
					'<span class="npo-col-selected">Selected</span>' +
					'<span class="npo-col-remove"></span>' +
				'</div>' +
				'<div class="npo-options-list"></div>' +
			'</div>'
		);
		var $optionsList = $optionsWrap.find( '.npo-options-list' );

		$.each( field.options, function ( optIndex, option ) {
			$optionsList.append( self.renderOptionRow( option, optIndex ) );
		} );

		$optionsWrap.append( '<p><button type="button" class="button button-secondary npo-add-option-btn">' + ( i18n.addOption || 'Add option' ) + '</button></p>' );
		$body.append( $optionsWrap );

		// --- Min / Max (checkbox only) --------------------------------------
		var $minMax = $( '<div class="npo-row npo-row-split npo-minmax-row" ' + ( 'checkbox' !== field.type ? 'style="display:none;"' : '' ) + '>' +
			'<div><label>Minimum choices needed</label><input type="number" min="0" data-model="min_choices" value="' + self.escAttr( field.min_choices ) + '" /></div>' +
			'<div><label>Maximum choices allowed</label><input type="number" min="0" data-model="max_choices" value="' + self.escAttr( field.max_choices ) + '" /></div>' +
		'</div>' );
		$body.append( $minMax );

		// --- Quantity based ---------------------------------------------------
		$body.append(
			'<div class="npo-row npo-row-inline">' +
				'<label><input type="checkbox" data-model="quantity_based" ' + ( field.quantity_based ? 'checked' : '' ) + ' /> Quantity based ' +
				'<span class="npo-hint">(this field repeats once per unit of quantity ordered)</span></label>' +
			'</div>'
		);

		// --- Conditional logic --------------------------------------------------
		$body.append( this.renderConditionalSection( field, index ) );

		$panel.append( $header ).append( $body );

		// Toggle min/max visibility when type changes.
		$body.find( '[data-model="type"]' ).on( 'change', function () {
			$minMax.toggle( 'checkbox' === $( this ).val() );
			$header.find( '.npo-field-type-badge' ).text( 'checkbox' === $( this ).val() ? ( i18n.checkboxField || 'Checkboxes' ) : ( i18n.radioField || 'Radio Buttons' ) );
		} );

		// Live-update the panel title as the label is typed.
		$body.find( '[data-model="label"]' ).on( 'input', function () {
			var val = $( this ).val();
			$header.find( '.npo-field-panel-title' ).text( val || '(' + ( i18n.newFieldLabel || 'New Field' ) + ')' );
		} );

		return $panel;
	};

	FieldsEditor.prototype.renderOptionRow = function ( option, optIndex ) {
		var self       = this;
		var isFormula  = 'formula' === option.pricing_type;
		var showAmount = 'none' !== option.pricing_type && ! isFormula;
		var $row       = $( '<div class="npo-option-row" data-index="' + optIndex + '"></div>' );

		$row.html(
			'<span class="npo-drag-handle npo-option-drag" title="Drag to reorder">&#9776;</span>' +
			'<input type="text" class="npo-option-label-input" data-model="label" value="' + self.escAttr( option.label ) + '" placeholder="Option label" />' +
			'<select class="npo-option-pricing-type" data-model="pricing_type">' + pricingOptionsHtml( option.pricing_type ) + '</select>' +
			'<span class="npo-option-amount-slot">' +
				'<input type="number" step="0.01" class="npo-option-pricing-amount" data-model="pricing_amount" value="' + self.escAttr( option.pricing_amount ) + '" style="' + ( showAmount ? '' : 'display:none;' ) + '" />' +
				'<input type="text" class="npo-option-pricing-formula" data-model="pricing_formula" value="' + self.escAttr( option.pricing_formula ) + '" placeholder="{price} * 0.1 + 2" style="' + ( isFormula ? '' : 'display:none;' ) + '" />' +
			'</span>' +
			'<span class="npo-option-selected"><input type="checkbox" data-model="selected" title="Default selected" ' + ( option.selected ? 'checked' : '' ) + ' /></span>' +
			'<button type="button" class="button-link npo-remove-option" aria-label="Remove option">&times;</button>'
		);

		$row.find( '.npo-option-pricing-type' ).on( 'change', function () {
			var val = $( this ).val();
			$row.find( '.npo-option-pricing-amount' ).toggle( 'none' !== val && 'formula' !== val );
			$row.find( '.npo-option-pricing-formula' ).toggle( 'formula' === val );
		} );

		return $row;
	};

	FieldsEditor.prototype.renderConditionalSection = function ( field, fieldIndex ) {
		var self = this;
		var $wrap = $(
			'<div class="npo-conditional-section">' +
				'<label class="npo-row-inline"><input type="checkbox" class="npo-conditional-toggle" data-model="conditional_enabled" ' + ( field.conditional_enabled ? 'checked' : '' ) + ' /> Conditionals ' +
				'<span class="npo-hint">Only show this field when conditional rules are true.</span></label>' +
				'<div class="npo-conditional-body" style="' + ( field.conditional_enabled ? '' : 'display:none;' ) + '"></div>' +
			'</div>'
		);

		var $body = $wrap.find( '.npo-conditional-body' );

		$.each( field.conditional_logic.groups, function ( groupIndex, group ) {
			$body.append( self.renderRuleGroup( group, groupIndex, fieldIndex ) );
		} );

		if ( ! field.conditional_logic.groups.length ) {
			$body.append( '<p><button type="button" class="button button-secondary npo-add-rule-group">' + ( i18n.addRuleGroup || 'Add new rule group' ) + '</button></p>' );
		}

		$wrap.find( '.npo-conditional-toggle' ).on( 'change', function () {
			$body.toggle( $( this ).is( ':checked' ) );
		} );

		return $wrap;
	};

	FieldsEditor.prototype.renderRuleGroup = function ( group, groupIndex, fieldIndex ) {
		var self = this;

		var $group = $( '<div class="npo-rule-group" data-index="' + groupIndex + '"></div>' );
		var $rulesList = $( '<div class="npo-rules-list"></div>' );

		$.each( group.rules, function ( ruleIndex, rule ) {
			$rulesList.append( self.renderRuleRow( rule, ruleIndex, groupIndex, fieldIndex ) );
		} );

		$group.append( $rulesList );
		$group.append(
			'<p class="npo-rule-group-actions">' +
				'<button type="button" class="button-link npo-add-rule">+ ' + ( i18n.and || 'And' ) + '</button>' +
			'</p>'
		);

		var $container = $( '<div></div>' );
		$container.append( $group );
		$container.append( '<button type="button" class="npo-or-trigger npo-add-rule-group">' + ( i18n.or || 'Or' ) + '</button>' );

		return $container;
	};

	FieldsEditor.prototype.optionsForField = function ( fieldId, selectedValue ) {
		var field = null;
		$.each( this.fields, function ( i, f ) {
			if ( f.id === fieldId ) {
				field = f;
			}
		} );

		if ( ! field ) {
			return '<option value="">&mdash;</option>';
		}

		var html = '';
		$.each( field.options, function ( i, opt ) {
			html += '<option value="' + opt.id + '"' + ( opt.id === selectedValue ? ' selected="selected"' : '' ) + '>' + opt.label + '</option>';
		} );

		return html || '<option value="">&mdash;</option>';
	};

	FieldsEditor.prototype.renderRuleRow = function ( rule, ruleIndex, groupIndex, fieldIndex ) {
		var self = this;
		var $row = $( '<div class="npo-rule-row" data-index="' + ruleIndex + '"></div>' );

		$row.html(
			'<select class="npo-rule-field-select" data-model="field_id">' + self.otherFieldOptions( fieldIndex, rule.field_id ) + '</select>' +
			'<select data-model="operator">' +
				'<option value="equal"' + ( 'equal' === rule.operator ? ' selected' : '' ) + '>' + ( i18n.equal || 'Value is equal to' ) + '</option>' +
				'<option value="not_equal"' + ( 'not_equal' === rule.operator ? ' selected' : '' ) + '>' + ( i18n.notEqual || 'Value is not equal to' ) + '</option>' +
			'</select>' +
			'<select class="npo-rule-value-select" data-model="value">' + self.optionsForField( rule.field_id, rule.value ) + '</select>' +
			'<button type="button" class="button-link npo-remove-rule" aria-label="Remove rule">&times;</button>'
		);

		// When the referenced field changes, repopulate the value dropdown
		// with that field's actual options instead of a free-text value.
		$row.find( '.npo-rule-field-select' ).on( 'change', function () {
			var newFieldId = $( this ).val();
			$row.find( '.npo-rule-value-select' ).html( self.optionsForField( newFieldId, '' ) );
		} );

		return $row;
	};

	FieldsEditor.prototype.initSortables = function () {
		var self = this;

		if ( ! $.fn.sortable ) {
			return;
		}

		this.$list.sortable( {
			handle: '> .npo-field-panel-header .npo-drag-handle',
			items: '> .npo-field-panel',
			axis: 'y',
			update: function () {
				self.syncFromInputs();
				var newOrder = [];
				self.$list.find( '.npo-field-panel' ).each( function () {
					newOrder.push( self.fields[ $( this ).data( 'index' ) ] );
				} );
				self.fields = newOrder;
				self.render();
			}
		} );

		this.$list.find( '.npo-options-list' ).sortable( {
			handle: '.npo-option-drag',
			items: '> .npo-option-row',
			axis: 'y',
			update: function () {
				self.syncFromInputs();
				var $panel     = $( this ).closest( '.npo-field-panel' );
				var fieldIndex = $panel.data( 'index' );
				var field      = self.fields[ fieldIndex ];
				var newOptions = [];
				$( this ).find( '.npo-option-row' ).each( function () {
					newOptions.push( field.options[ $( this ).data( 'index' ) ] );
				} );
				field.options = newOptions;
				self.render( fieldIndex );
			}
		} );
	};

	FieldsEditor.prototype.escAttr = function ( value ) {
		return $( '<div>' ).text( null === value || undefined === value ? '' : value ).html().replace( /"/g, '&quot;' );
	};

	$( function () {
		$( '.npo-fields-editor' ).each( function () {
			new FieldsEditor( this );
		} );
	} );

} )( jQuery );
