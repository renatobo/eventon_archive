/**
 * Editor script for the EventON Archive block.
 *
 * Written in plain ES5 against the wp.* globals on purpose: no JSX, no build
 * step, no node_modules. The block is server-rendered, so the editor only needs
 * a settings panel and a live preview.
 */
( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var ToggleControl = components.ToggleControl;
	var RangeControl = components.RangeControl;

	/**
	 * Event families, injected by eventon_archive_editor_data() as
	 * [ { value: 'event_type_2', label: 'Bike Night' }, … ].
	 *
	 * Falls back to an empty list, which leaves only "All families" selectable.
	 * That is the behaviour before this option existed, so a missing global
	 * degrades instead of breaking the editor.
	 */
	function familyOptions() {
		var injected = window.eventonArchiveFamilies;
		var options = [ { label: __( 'All families', 'eventon_archive' ), value: '' } ];
		var i;

		if ( ! injected || ! injected.length ) {
			return options;
		}

		for ( i = 0; i < injected.length; i++ ) {
			options.push( {
				label: injected[ i ].label,
				value: injected[ i ].value
			} );
		}

		return options;
	}

	/**
	 * Names of the configured families, for help text: "Ride, Bike Night, …".
	 */
	function familyNames() {
		var injected = window.eventonArchiveFamilies;
		var names = [];
		var i;

		if ( ! injected ) {
			return '';
		}

		for ( i = 0; i < injected.length; i++ ) {
			names.push( injected[ i ].label );
		}

		return names.join( ', ' );
	}

	blocks.registerBlockType( 'eventon-archive/archive', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			var controls = el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'Archive settings', 'eventon_archive' ) },
					el( SelectControl, {
						label: __( 'Event family', 'eventon_archive' ),
						help: __( 'Limit the list to one kind of event. The names come from the event types configured in EventON.', 'eventon_archive' ),
						value: attributes.family || '',
						options: familyOptions(),
						onChange: function ( value ) {
							setAttributes( { family: value } );
						}
					} ),
					el( SelectControl, {
						label: __( 'Order', 'eventon_archive' ),
						value: attributes.order,
						options: [
							{ label: __( 'Newest first', 'eventon_archive' ), value: 'desc' },
							{ label: __( 'Oldest first', 'eventon_archive' ), value: 'asc' }
						],
						onChange: function ( value ) {
							setAttributes( { order: value } );
						}
					} ),
					el( SelectControl, {
						label: __( 'Events to include', 'eventon_archive' ),
						value: attributes.show,
						options: [
							{ label: __( 'All events', 'eventon_archive' ), value: 'all' },
							{ label: __( 'Past only', 'eventon_archive' ), value: 'past' },
							{ label: __( 'Upcoming only', 'eventon_archive' ), value: 'future' }
						],
						onChange: function ( value ) {
							setAttributes( { show: value } );
						}
					} ),
					el( RangeControl, {
						label: __( 'Maximum events', 'eventon_archive' ),
						help: __( '0 shows every event. With "Upcoming only", set Order to "Oldest first" or a capped list gives you the events furthest away instead of the next few.', 'eventon_archive' ),
						value: attributes.limit || 0,
						min: 0,
						max: 50,
						allowReset: true,
						resetFallbackValue: 0,
						onChange: function ( value ) {
							setAttributes( { limit: parseInt( value, 10 ) || 0 } );
						}
					} ),
					el( ToggleControl, {
						label: __( 'Group by year and month', 'eventon_archive' ),
						help: __( 'Off gives one flat list with the year on every row. Use that for a short list inside a page, so its headings stay out of the page outline.', 'eventon_archive' ),
						checked: !! attributes.group,
						onChange: function ( value ) {
							setAttributes( { group: !! value } );
						}
					} ),
					el( ToggleControl, {
						label: __( 'Show venue name', 'eventon_archive' ),
						checked: !! attributes.location,
						onChange: function ( value ) {
							setAttributes( { location: !! value } );
						}
					} ),
					el( ToggleControl, {
						label: __( 'Show event category', 'eventon_archive' ),
						help: familyNames()
							? __( 'Adds the event family after each title: ', 'eventon_archive' ) + familyNames() + '.'
							: __( 'Adds the event family after each title.', 'eventon_archive' ),
						checked: !! attributes.category,
						onChange: function ( value ) {
							setAttributes( { category: !! value } );
						}
					} ),
					el( ToggleControl, {
						label: __( 'Show totals', 'eventon_archive' ),
						help: __( 'A strip of counters above the list: all events, one per event family, and members-only. Zero figures are hidden.', 'eventon_archive' ),
						checked: !! attributes.counters,
						onChange: function ( value ) {
							setAttributes( { counters: !! value } );
						}
					} ),
					el( ToggleControl, {
						label: __( 'Year jump links', 'eventon_archive' ),
						// The links target the year headings, so an ungrouped list has
						// nothing to jump to. The renderer forces this off in that
						// case; disabling it here keeps the panel honest.
						help: attributes.group
							? __( 'Shown only when the list spans more than one year.', 'eventon_archive' )
							: __( 'Needs "Group by year and month".', 'eventon_archive' ),
						disabled: ! attributes.group,
						checked: !! attributes.group && !! attributes.nav,
						onChange: function ( value ) {
							setAttributes( { nav: !! value } );
						}
					} )
				)
			);

			var preview = el( serverSideRender, {
				block: 'eventon-archive/archive',
				attributes: attributes
			} );

			return el(
				'div',
				useBlockProps( { className: 'eventon-archive-editor-preview' } ),
				controls,
				el( element.Fragment, null, preview )
			);
		},

		// Server-rendered: nothing is stored in post content but the block comment.
		save: function () {
			return null;
		}
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.serverSideRender,
	window.wp.i18n
);
