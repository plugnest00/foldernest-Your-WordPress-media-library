/**
 * FolderNest — 媒體框（wp.media）整合（前端）。
 *
 * 對所有 wp.media 框（古騰堡、Elementor、小工具、upload.php 網格模式……）生效：
 *   1. 「上傳文件」頁籤：加上「上傳到資料夾」下拉，選好再傳，
 *      multipart 直接帶 mn_mm_folder + nonce，後端 MN_MM_Upload::assign_folder() 歸類。
 *   2. 「插入媒體」瀏覽頁籤：加上資料夾篩選下拉，重新查詢只看某資料夾（含子資料夾）。
 *
 * 為什麼有兩條注入路徑（重要背景，改版前必讀）：
 *   A) class 替換：wp.media.view.AttachmentsBrowser.extend(...) 覆蓋 global class
 *      —— 對「開框時才動態讀取 global」的 frame 有效（如 Select frame 的 browseContent）。
 *   B) MediaFrame 原型包裹：wp.media.view.MediaFrame.prototype.open 原型「就地」包裹
 *      + 開框後 DOM 注入 —— 對「閉包捕獲了原始類別」的 frame（如 Post frame 的
 *      insert 內容、Elementor 自訂 frame）也有效，因為所有 frame 子類共用同一條
 *      MediaFrame 原型鏈。
 *   兩條路徑冪等（注入前先查 .mn-mm-modal-filter 是否存在），不會重複。
 *
 * 資料夾參數走請求「頂層」（ajaxPrefilter 注入），因為核心 wp_ajax_query_attachments
 * 會用白名單把 query 物件裡的自訂鍵剝掉（後端從 $_REQUEST 讀）。
 */
(function () {
	'use strict';

	if ( typeof window.wp === 'undefined' || ! window.wp.media || typeof window.MN_MM_MODAL === 'undefined' ) {
		return;
	}

	var L = window.MN_MM_MODAL;
	var $ = window.jQuery;

	/* 目前選擇（整個後台 session 記憶，跨媒體框共用） */
	var currentUpload = '';
	var currentFilter = '';

	/* 已建立的 wp.Uploader 包裝器實例清單（選資料夾時逐一套用 param）。
	 * 核心 plupload 實例在建構時深拷貝 Uploader.defaults —— 之後改 defaults
	 * 不影響既有實例，所以建立時要登記、變更時要對實例呼叫 param()。
	 * 暴露在 MN_MM_MODAL.uploaders 供除錯。 */
	var mnUploaders = [];

	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"]/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ c ];
		} );
	}

	function optionHtml( value, label, depth, selected ) {
		var pad = '';
		for ( var i = 0; i < ( depth || 0 ); i++ ) {
			pad += '\u00A0\u00A0\u00A0';
		}
		return '<option value="' + esc( value ) + '"' + ( selected ? ' selected' : '' ) + '>' + pad + esc( label ) + '</option>';
	}

	function folderOptions( current, mode ) {
		var html = '';
		if ( 'filter' === mode ) {
			/* 瀏覽篩選：空值＝所有資料夾，另有「未分類」 */
			html += optionHtml( '', L.strings.all, 0, '' === current );
			html += optionHtml( 'none', L.strings.unassigned, 0, 'none' === current );
		} else {
			/* 上傳：空值＝不指定（之後再分類） */
			html += optionHtml( '', L.strings.unspecified, 0, '' === current );
		}
		( L.folders || [] ).forEach( function ( f ) {
			html += optionHtml( String( f.term_id ), f.name + ' (' + ( f.count || 0 ) + ')', f.depth, String( f.term_id ) === String( current ) );
		} );
		return html;
	}

	/* 把目前選擇寫進上傳器的 multipart：
	   1) Uploader.defaults（之後新建的實例會帶）
	   2) 所有已登記的實例（core param() 直接改 plupload settings） */
	function applyUploadParams() {
		if ( window.wp.Uploader && window.wp.Uploader.defaults ) {
			if ( ! window.wp.Uploader.defaults.multipart_params ) {
				window.wp.Uploader.defaults.multipart_params = {};
			}
			window.wp.Uploader.defaults.multipart_params[ L.field ] = currentUpload;
			window.wp.Uploader.defaults.multipart_params.mn_mm_nonce = L.nonce;
		}
		if ( window.wp.uploader && window.wp.uploader.settings ) {
			if ( ! window.wp.uploader.settings.multipart_params ) {
				window.wp.uploader.settings.multipart_params = {};
			}
			window.wp.uploader.settings.multipart_params[ L.field ] = currentUpload;
			window.wp.uploader.settings.multipart_params.mn_mm_nonce = L.nonce;
		}
		mnUploaders.forEach( function ( u ) {
			if ( typeof u.param === 'function' ) {
				u.param( L.field, currentUpload );
				u.param( 'mn_mm_nonce', L.nonce );
			}
		} );
	}

	/* 篩選套用的共用邏輯（下拉與 chips 共用）：記憶選擇 → 同步上傳預設 →
	   同步畫面上所有下拉 → 重新查詢。 */
	function applyFolderFilter( value, getLibrary ) {
		currentFilter = value;
		/* 瀏覽篩選同步成上傳預設：正在看哪個資料夾，上傳就進哪個資料夾。 */
		currentUpload = ( 'none' === currentFilter ) ? '' : currentFilter;
		applyUploadParams();
		/* 已顯示中的下拉與 chips 同步（跨 frame 一併）。 */
		$( '.mn-mm-modal-upload-picker select' ).each( function () {
			if ( this.value !== currentUpload ) {
				this.value = currentUpload;
			}
		} );
		$( '.mn-mm-modal-folder-select' ).each( function () {
			if ( this.value !== currentFilter ) {
				this.value = currentFilter;
			}
		} );
		$( '.mn-mm-modal-chips' ).each( function () {
			renderChipsInto( $( this ), getLibrary );
		} );

		var lib = getLibrary();
		if ( lib && lib.props ) {
			/* query 模式的 collection 在 props 變化時會重建 Query（_requery → mirror）。 */
			lib.props.set( 'mn_mm_folder', currentFilter );
			/* 核心 prepare() 只在 infiniteScrolling 模式才會於空清單時自動 more()；
			   「載入更多」按鈕模式下 reset 後沒人抓資料，這裡明確補一次。 */
			if ( typeof lib.more === 'function' ) {
				lib.more();
			}
		}
	}

	function bindFolderSelect( $select, getLibrary ) {
		$select.on( 'change', function () {
			applyFolderFilter( this.value, getLibrary );
		} );
	}

	/* ---------- 資料夾 chips（下拉下方的快速切換列） ----------
	 * All →（目前路徑麵包屑）→（目前層的子資料夾）。
	 * 資料來源與下拉同一份 L.folders。 */
	function chipsModel() {
		var folders = L.folders || [];
		var byId = {};
		folders.forEach( function ( f ) {
			byId[ f.term_id ] = f;
		} );

		var chips = [ { value: '', label: L.strings.all, depth: 0, active: '' === currentFilter } ];

		if ( 'none' === currentFilter ) {
			chips.push( { value: 'none', label: L.strings.unassigned, depth: 0, active: true } );
			return chips;
		}

		var curId = parseInt( currentFilter, 10 ) || 0;
		if ( curId > 0 ) {
			/* 麵包屑：根 → … → 目前 */
			var path = [];
			var cur = byId[ curId ];
			var guard = 0;
			while ( cur && guard < 20 ) {
				path.unshift( cur );
				cur = byId[ cur.parent ];
				guard++;
			}
			path.forEach( function ( f, i ) {
				chips.push( { value: String( f.term_id ), label: f.name, depth: 0, active: i === path.length - 1 } );
			} );
		}

		/* 目前層的子資料夾（根層篩選時 = 頂層資料夾） */
		folders
			.filter( function ( f ) {
				return ( f.parent || 0 ) === curId;
			} )
			.sort( function ( a, b ) {
				return a.name.localeCompare( b.name, undefined, { sensitivity: 'base' } );
			} )
			.slice( 0, 14 )
			.forEach( function ( f ) {
				chips.push( { value: String( f.term_id ), label: f.name, depth: 0, active: false } );
			} );

		return chips;
	}

	function renderChipsInto( $bar, getLibrary ) {
		var chips = chipsModel();
		var html = chips
			.map( function ( c ) {
				return (
					'<button type="button" class="mn-mm-chip' +
					( c.active ? ' is-active' : '' ) +
					'" data-chip="' + esc( c.value ) + '">' +
					esc( c.label ) +
					'</button>'
				);
			} )
			.join( '' );
		$bar.html( html );
		$bar.find( '[data-chip]' ).on( 'click', function () {
			applyFolderFilter( this.getAttribute( 'data-chip' ), getLibrary );
		} );
	}

	function buildFilterHtml( frame ) {
		var html =
			'<div class="mn-mm-modal-filter">' +
				'<label class="screen-reader-text">' + esc( L.strings.filterBy ) + '</label>' +
				'<select class="mn-mm-modal-folder-select">' + folderOptions( currentFilter, 'filter' ) + '</select>' +
				'<div class="mn-mm-modal-chips"></div>' +
			'</div>';
		return html;
	}

	/* ---------- 0) 瀏覽查詢：把資料夾掛在請求頂層 ----------
	 * 核心 wp_ajax_query_attachments 用白名單（array_intersect_key）過濾 query
	 * 物件，自訂鍵在 ajax_query_attachments_args 過濾器之前就被剝掉。
	 * 空值不注入＝全部資料夾。 */
	if ( window.jQuery ) {
		window.jQuery.ajaxPrefilter( function ( options ) {
			if ( '' === currentFilter || ! options.data || options.data.indexOf( 'action=query-attachments' ) === -1 ) {
				return;
			}
			if ( options.data.indexOf( '&mn_mm_folder=' ) === -1 ) {
				options.data += '&mn_mm_folder=' + encodeURIComponent( currentFilter );
			}
		} );
	}

	/* ---------- 1) 「上傳文件」頁籤：上傳到資料夾 ---------- */

	if ( wp.media.view.UploaderInline ) {
		var origUploaderRender = wp.media.view.UploaderInline.prototype.render;
		wp.media.view.UploaderInline = wp.media.view.UploaderInline.extend( {
			render: function () {
				origUploaderRender.apply( this, arguments );

				this.$el.find( '.mn-mm-modal-upload-picker' ).remove();

				var $picker = $(
					'<div class="mn-mm-modal-upload-picker">' +
						'<label class="mn-mm-modal-upload-label">' + esc( L.strings.uploadTo ) + '</label>' +
						'<select class="mn-mm-modal-upload-select">' + folderOptions( currentUpload, 'upload' ) + '</select>' +
					'</div>'
				);

				$picker.find( 'select' ).on( 'change', function () {
					currentUpload = this.value;
					applyUploadParams();
				} );

				/* 插在拖放區／「選擇檔案」按鈕上方 */
				var $browser = this.$el.find( '.browser' );
				if ( $browser.length ) {
					$picker.insertBefore( $browser );
				} else {
					this.$el.prepend( $picker );
				}

				applyUploadParams();
				return this;
			}
		} );
	}

	/* ---------- 1b) 上傳器實例註冊與參數套用 ----------
	 * 實際的 wp.Uploader 包裝器實例在 UploaderWindow.ready() 建立
	 * （不是 UploaderInline），這裡包 ready：實例一建立就登記追蹤，
	 * 並把目前選擇寫進它的 multipart。 */
	if ( wp.media.view.UploaderWindow ) {
		var origUploaderWindowReady = wp.media.view.UploaderWindow.prototype.ready;
		wp.media.view.UploaderWindow = wp.media.view.UploaderWindow.extend( {
			ready: function () {
				var result = origUploaderWindowReady.apply( this, arguments );
				if ( this.uploader && typeof this.uploader.param === 'function' ) {
					if ( mnUploaders.indexOf( this.uploader ) === -1 ) {
						mnUploaders.push( this.uploader );
					}
					this.uploader.param( L.field, currentUpload );
					this.uploader.param( 'mn_mm_nonce', L.nonce );
				}
				return result;
			}
		} );
	}

	/* 除錯用：上傳器實例清單 */
	window.MN_MM_MODAL.uploaders = mnUploaders;

	/* ---------- 2) B 路徑：MediaFrame 原型包裹（通用） ----------
	 * 所有 frame（Select／Post／Elementor 自訂）共用同一條 MediaFrame 原型鏈，
	 * 原型就地包裹對全部生效；開框後把篩選下拉注入 .media-toolbar-secondary
	 * （若 A 路徑已加過則跳過）。toolbar 可能稍晚才進 DOM，重試至多 6 秒。 */
	function injectFilterIntoFrame( frame ) {
		if ( ! frame || ! frame.$el ) {
			return;
		}
		var tries = 0;
		( function tick() {
			var $tb = frame.$el.find( '.media-toolbar-secondary' );
			if ( $tb.length ) {
				if ( $tb.find( '.mn-mm-modal-filter' ).length === 0 ) {
					var $filter = $( buildFilterHtml( frame ) );
					bindFolderSelect( $filter.find( 'select' ), function () {
						var s = frame.state ? frame.state() : null;
						return ( s && s.get ) ? s.get( 'library' ) : null;
					} );
					$tb.append( $filter );
					renderChipsInto( $filter.find( '.mn-mm-modal-chips' ), function () {
						var s = frame.state ? frame.state() : null;
						return ( s && s.get ) ? s.get( 'library' ) : null;
					} );
					/* 還原跨框記憶的篩選 */
					if ( '' !== currentFilter ) {
						var sel = $filter.find( 'select' ).val( currentFilter )[ 0 ];
						if ( sel ) {
							$( sel ).trigger( 'change' );
						}
					}
				}
				return;
			}
			tries += 1;
			if ( tries < 40 ) {
				window.setTimeout( tick, 150 );
			}
		}() );
	}

	if ( wp.media.view.MediaFrame && wp.media.view.MediaFrame.prototype && ! wp.media.view.MediaFrame.prototype.__mnOpenWrapped ) {
		var origFrameOpen = wp.media.view.MediaFrame.prototype.open;
		wp.media.view.MediaFrame.prototype.open = function () {
			var result = origFrameOpen.apply( this, arguments );
			injectFilterIntoFrame( this );
			return result;
		};
		wp.media.view.MediaFrame.prototype.__mnOpenWrapped = true;
	}

	/* ---------- 3) A 路徑：「插入媒體」瀏覽頁籤（Select frame 等） ----------
	 * class 替換；B 路徑的注入對已涵蓋的 frame 會自動跳過（冪等）。 */
	if ( wp.media.view.AttachmentsBrowser ) {
		var origCreateToolbar = wp.media.view.AttachmentsBrowser.prototype.createToolbar;
		wp.media.view.AttachmentsBrowser = wp.media.view.AttachmentsBrowser.extend( {
			createToolbar: function () {
				origCreateToolbar.apply( this, arguments );

				var browser = this;
				var $host = this.toolbar.$el.find( '.media-toolbar-secondary' );
				if ( ! $host.length ) {
					$host = this.toolbar.$el;
				}
				$host.find( '.mn-mm-modal-filter' ).remove();

				var $filter = $( buildFilterHtml( browser ) );
				bindFolderSelect( $filter.find( 'select' ), function () {
					return browser.collection;
				} );
				$host.append( $filter );
				renderChipsInto( $filter.find( '.mn-mm-modal-chips' ), function () {
					return browser.collection;
				} );

				/* 進畫面就套用上次選的篩選（跨媒體框記憶）。此時多半在首次載入前，
				   props 先設好，第一次 query-attachments 就會帶上資料夾條件。 */
				if ( '' !== currentFilter && browser.collection && browser.collection.props ) {
					browser.collection.props.set( 'mn_mm_folder', currentFilter );
				}
			}
		} );
	}
}() );
