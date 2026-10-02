/**
 * PlugNest Media Folders — Gutenberg 側欄。
 *
 * 在區塊編輯器的「文件」設定側欄加入「媒體資料夾」面板：
 * 列出直接附屬於這篇文章的媒體（含精選圖片），每個可即時指派資料夾。
 * 使用 wp.element.createElement，不需建置流程。
 */
( function () {
	if ( typeof window.wp === 'undefined' || ! window.wp.plugins || ! window.wp.editPost || ! window.MN_MM_GUT ) {
		return;
	}

	var CFG = window.MN_MM_GUT;
	var __ = window.wp.i18n && window.wp.i18n.__ ? window.wp.i18n.__ : function ( s ) { return s; };
	var el = window.wp.element.createElement;
	var useState = window.wp.element.useState;
	var useEffect = window.wp.element.useEffect;
	var useSelect = window.wp.data && window.wp.data.useSelect ? window.wp.data.useSelect : null;

	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"]/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ c ];
		} );
	}

	function Panel() {
		var postId = useSelect ? useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostId();
		}, [] ) : 0;

		var state = useState( { loading: true, items: [], error: '' } );
		var items = state[ 0 ];
		var setItems = state[ 1 ];

		useEffect( function () {
			if ( ! postId ) { return; }
			var body = new FormData();
			body.append( 'action', 'mn_mm_post_media' );
			body.append( 'nonce', CFG.nonce );
			body.append( 'post_id', postId );
			fetch( CFG.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( j ) {
					if ( j && j.success ) { setItems( { loading: false, items: j.data.items || [], error: '' } ); }
					else { setItems( { loading: false, items: [], error: __( 'Load failed', 'plugnest-media-folders' ) } ); }
				} )
				.catch( function () { setItems( { loading: false, items: [], error: __( 'Load failed', 'plugnest-media-folders' ) } ); } );
		}, [ postId ] );

		function setFolder( id, folderId ) {
			var body = new FormData();
			body.append( 'action', 'mn_mm_save_item' );
			body.append( 'nonce', CFG.nonce );
			body.append( 'id', id );
			body.append( 'folder_id', folderId );
			fetch( CFG.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( j ) {
					if ( ! j.success ) { window.alert( j.data && j.data.message ? j.data.message : __( 'Save failed', 'plugnest-media-folders' ) ); }
				} );
		}

		var rows;
		if ( items.loading ) {
			rows = [ el( 'p', { key: 'l' }, __( 'Loading…', 'plugnest-media-folders' ) ) ];
		} else if ( items.error ) {
			rows = [ el( 'p', { key: 'e' }, items.error ) ];
		} else if ( ! items.length ) {
			rows = [ el( 'p', { key: 'n' }, __( 'No media is directly attached to this post (featured image included).', 'plugnest-media-folders' ) ) ];
		} else {
			rows = items.map( function ( it, idx ) {
				var current = it.folders && it.folders.length ? String( it.folders[ 0 ].term_id ) : '';
				var options = [ el( 'option', { key: 'none', value: '' }, __( '— Unassigned —', 'plugnest-media-folders' ) ) ]
					.concat( ( CFG.folders || [] ).map( function ( f, i ) {
						return el( 'option', { key: f.term_id, value: String( f.term_id ) },
							'\u00A0\u00A0\u00A0'.repeat( f.depth || 0 ) + f.name + ' (' + ( f.count || 0 ) + ')' );
					} ) );
				return el( 'div', { key: it.id || idx, style: { marginBottom: '12px' } },
					el( 'div', { style: { fontSize: '12px', marginBottom: '4px', wordBreak: 'break-all' } },
						esc( it.filename || it.title ) ),
					el( 'select', {
						value: current,
						style: { width: '100%' },
						onChange: function ( e ) { setFolder( it.id, e.target.value ); },
					}, options )
				);
			} );
		}

		return el( window.wp.editPost.PluginDocumentSettingPanel, {
			name: 'mn-mm-folders',
			title: __( 'Media folders', 'plugnest-media-folders' ),
			icon: 'images-alt2',
			initialOpen: false,
		}, rows );
	}

	window.wp.plugins.registerPlugin( 'mn-mm-folders-panel', {
		render: Panel,
		icon: 'images-alt2',
	} );
}() );
