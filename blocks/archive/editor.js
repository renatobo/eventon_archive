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
					el( ToggleControl, {
						label: __( 'Show venue name', 'eventon_archive' ),
						checked: !! attributes.location,
						onChange: function ( value ) {
							setAttributes( { location: !! value } );
						}
					} ),
					el( ToggleControl, {
						label: __( 'Show event category', 'eventon_archive' ),
						help: __( 'Adds the event family: Ride, Bike Night, Track Day, MotoGP Watch Party.', 'eventon_archive' ),
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
						checked: !! attributes.nav,
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
