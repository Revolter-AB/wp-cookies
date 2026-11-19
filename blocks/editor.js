( function ( wp ) {
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var ToggleControl = wp.components.ToggleControl;
	var ServerSideRender = wp.serverSideRender;

	function makeEdit( name, panelTitle, toggles ) {
		return function ( props ) {
			var controls = toggles.map( function ( toggle ) {
				return el( ToggleControl, {
					key: toggle.attr,
					label: toggle.label,
					checked: !! props.attributes[ toggle.attr ],
					onChange: function ( value ) {
						var change = {};
						change[ toggle.attr ] = value;
						props.setAttributes( change );
					}
				} );
			} );
			var blockProps = wp.blockEditor.useBlockProps();
			var preview = ServerSideRender ?
				el( ServerSideRender, { block: name, attributes: props.attributes } ) :
				el( 'p', {}, __( 'Preview unavailable in this editor.', 'revolter-cookies' ) );
			return el(
				'div',
				blockProps,
				el( InspectorControls, {}, el( PanelBody, { title: panelTitle, initialOpen: true }, controls ) ),
				preview
			);
		};
	}

	wp.blocks.registerBlockType( 'revolter-cookies/full-page', {
		edit: makeEdit( 'revolter-cookies/full-page', __( 'Sections', 'revolter-cookies' ), [
			{ attr: 'showWhat', label: __( 'What are cookies', 'revolter-cookies' ) },
			{ attr: 'showHow', label: __( 'How we use cookies', 'revolter-cookies' ) },
			{ attr: 'showManage', label: __( 'Managing cookies', 'revolter-cookies' ) },
			{ attr: 'showLegal', label: __( 'Legal basis', 'revolter-cookies' ) },
			{ attr: 'showRights', label: __( 'Your rights', 'revolter-cookies' ) },
			{ attr: 'showConsent', label: __( 'Your consent', 'revolter-cookies' ) }
		] ),
		save: function () {
			return null;
		}
	} );

	wp.blocks.registerBlockType( 'revolter-cookies/cookies-table', {
		edit: makeEdit( 'revolter-cookies/cookies-table', __( 'Settings', 'revolter-cookies' ), [
			{ attr: 'showSettingsLink', label: __( 'Show link to change settings', 'revolter-cookies' ) }
		] ),
		save: function () {
			return null;
		}
	} );
}( window.wp ) );
