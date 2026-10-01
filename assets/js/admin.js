/**
 * FolderNest — 後台互動邏輯。
 *
 * 全部使用原生 JavaScript，不依賴 jQuery。
 * 所有寫入都經過 AJAX，並在畫面上即時反映結果。
 *
 * @package FolderNest
 */

( function () {
	'use strict';

	if ( typeof window.MN_MM === 'undefined' ) {
		return;
	}

	const CFG = window.MN_MM;

	/* wp.i18n（PHP 端已把 wp-i18n 註冊為依賴）；帶後備避免載入順序問題。 */
	const i18n = window.wp && window.wp.i18n ? window.wp.i18n : {};
	const __ = i18n.__ || function ( s ) { return s; };
	const sprintf = i18n.sprintf || function ( s ) { return s; };

	/* =========================================================
	 *  狀態
	 * ========================================================= */

	const state = {
		folderId: 0,
		tagIds: [],
		mimeGroup: '',
		usage: '',
		search: '',
		sort: 'date-DESC',
		includeChildren: true,
		/* 檢視模式：grid（縮略圖）/ list（列表）。記在 localStorage。 */
		view: localStorage.getItem( 'mn-mm-view' ) === 'list' ? 'list' : 'grid',
		paged: 1,
		perPage: CFG.perPage || 60,
		total: 0,
		pages: 1,
		items: [],
		selected: new Set(),
		selectAll: false,
		dragIds: null,
		/* 檔案拖到特定資料夾列上放開時的「一次性」上傳目標（term_id，0=無）。 */
		uploadFolderOverride: 0,
		current: null,
		currentTags: [],
		collapsed: new Set(),
		folders: CFG.folders || [],
		tags: CFG.tags || [],
		stats: CFG.stats || {},
		rules: CFG.rules || [],
		folderPaths: CFG.folderPaths || [],
		dirty: false,
	};

	/* =========================================================
	 *  小工具
	 * ========================================================= */

	const $ = ( sel, root ) => ( root || document ).querySelector( sel );

	function esc( str ) {
		return String( str === null || str === undefined ? '' : str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	/**
	 * 資料夾顏色小工具：服務端存「不含 # 的 hex」，CSS 需要帶 #。
	 * colHex('#a23') → '#aa2233'（3 位展開）；不合法回空字串。
	 */
	function colHex( c ) {
		if ( ! c ) {
			return '';
		}
		let h = String( c ).replace( '#', '' ).toLowerCase();
		if ( 3 === h.length ) {
			h = h.split( '' ).map( ( x ) => x + x ).join( '' );
		}
		return /^[0-9a-f]{6}$/.test( h ) ? '#' + h : '';
	}

	/** colVars('#a23') → '--mn-c:#aa2233;--mn-c-soft:rgba(…,.16);--mn-c-hover:rgba(…,.3)' */
	function colVars( c ) {
		const hex = colHex( c );
		if ( ! hex ) {
			return '';
		}
		const r = parseInt( hex.slice( 1, 3 ), 16 );
		const g = parseInt( hex.slice( 3, 5 ), 16 );
		const b = parseInt( hex.slice( 5, 7 ), 16 );
		return (
			'--mn-c:' + hex +
			';--mn-c-soft:rgba(' + r + ',' + g + ',' + b + ',.16)' +
			';--mn-c-hover:rgba(' + r + ',' + g + ',' + b + ',.3)'
		);
	}

	let toastTimer = null;

	/** 使用者是否按下了「中止」，用來讓自動分類的迴圈停下來。 */
	let autoCancelled = false;

	function toast( message, type ) {
		const el = $( '#mn-mm-toast' );
		if ( ! el ) {
			return;
		}
		el.textContent = message;
		el.className = 'mn-mm-toast' + ( type ? ' is-' + type : '' );
		el.hidden = false;
		clearTimeout( toastTimer );
		toastTimer = setTimeout( () => {
			el.hidden = true;
		}, 3200 );
	}

	function progress( show, text, percent, cancellable ) {
		const wrap = $( '#mn-mm-progress' );
		const label = $( '#mn-mm-progress-text' );
		const fill = $( '#mn-mm-progress-fill' );
		const cancel = $( '#mn-mm-progress-cancel' );

		if ( ! wrap ) {
			return;
		}

		wrap.hidden = ! show;
		if ( show ) {
			label.textContent = text || __( 'Processing…', 'foldernest' );
			fill.style.width = ( percent || 0 ) + '%';
			cancel.hidden = ! cancellable;
		}
	}

	/**
	 * 呼叫 AJAX 端點。
	 *
	 * @param {string} action 不含前綴的動作名稱。
	 * @param {Object} data   參數。
	 * @return {Promise<Object>}
	 */
	async function api( action, data ) {
		const body = new FormData();
		body.append( 'action', 'mn_mm_' + action );
		body.append( 'nonce', CFG.nonce );

		Object.keys( data || {} ).forEach( ( key ) => {
			const value = data[ key ];
			if ( Array.isArray( value ) ) {
				value.forEach( ( v ) => body.append( key + '[]', v ) );
			} else if ( value !== undefined && value !== null ) {
				body.append( key, value );
			}
		} );

		const response = await fetch( CFG.ajaxUrl, {
			method: 'POST',
			body,
			credentials: 'same-origin',
		} );

		let json;
		try {
			json = await response.json();
		} catch ( e ) {
			throw new Error( __( 'Invalid server response. Please reload the page.', 'foldernest' ) );
		}

		if ( ! json || ! json.success ) {
			const msg = json && json.data && json.data.message ? json.data.message : __( 'Operation failed.', 'foldernest' );
			throw new Error( msg );
		}

		return json.data;
	}

	function debounce( fn, wait ) {
		let timer = null;
		return function () {
			const args = arguments;
			clearTimeout( timer );
			timer = setTimeout( () => fn.apply( this, args ), wait );
		};
	}

	/* =========================================================
	 *  統計
	 * ========================================================= */

	function renderStats( stats ) {
		state.stats = stats;

		const items = [
			{ value: stats.total, label: __( 'Total media', 'foldernest' ) },
			{ value: stats.folders, label: __( 'Folders', 'foldernest' ) },
			{ value: stats.unassigned, label: __( 'Unassigned', 'foldernest' ), cls: 'mn-mm-stat--warn' },
			{ value: stats.used, label: __( 'In use', 'foldernest' ) },
			{ value: stats.unused, label: __( 'Unused', 'foldernest' ), cls: 'mn-mm-stat--alert' },
			{ value: stats.unscanned, label: __( 'Not scanned', 'foldernest' ) },
		];

		$( '#mn-mm-stats' ).innerHTML = items
			.map(
				( it ) =>
					'<div class="mn-mm-stat ' +
					( it.cls || '' ) +
					'"><div class="mn-mm-stat-value">' +
					esc( it.value ) +
					'</div><div class="mn-mm-stat-label">' +
					esc( it.label ) +
					'</div></div>'
			)
			.join( '' );
	}

	/* =========================================================
	 *  資料夾樹
	 * ========================================================= */

	function renderTree() {
		const tree = $( '#mn-mm-tree' );
		const rows = [];

		rows.push(
			item( {
				key: 'all',
				name: __( 'All media', 'foldernest' ),
				count: state.stats.total,
				depth: 0,
				active: state.folderId === 0,
			} )
		);
		rows.push(
			item( {
				key: 'none',
				name: __( 'Unassigned', 'foldernest' ),
				count: state.stats.unassigned,
				depth: 0,
				active: state.folderId === 'none',
				warn: true,
			} )
		);

		/* 釘選（星標）資料夾：平鋪在樹頂，點擊直接跳轉；★ 可點擊取消釘選。 */
		const pinned = state.folders.filter( ( f ) => f.star );
		if ( pinned.length ) {
			rows.push( '<li class="mn-mm-tree-pinned-label">' + __( 'Pinned', 'foldernest' ) + '</li>' );
			pinned.forEach( ( f ) => {
				rows.push(
					item( {
						key: 'star-' + f.term_id,
						name: f.name,
						count: f.count,
						depth: 0,
						active: state.folderId === f.term_id,
						termId: f.term_id,
						color: f.color,
						star: 1,
						pinned: true,
					} )
				);
			} );
		}

		/*
		 * 這裡一定要用 visibleFolders()，不能用 state.folders。
		 * 用後者的話收合狀態只會改變箭頭，子層還是照樣全部渲染出來
		 * ——看起來就是「怎麼點都收不起來」。
		 */
		visibleFolders().forEach( ( f ) => {
			rows.push(
				item( {
					key: f.term_id,
					name: f.name,
					count: f.count,
					depth: f.depth,
					active: state.folderId === f.term_id,
					termId: f.term_id,
					color: f.color,
					star: f.star,
					hasChildren: state.folders.some( ( c ) => c.parent === f.term_id ),
				} )
			);
		} );

		tree.innerHTML = rows.join( '' );

		function item( o ) {
			const pad = 10 + ( o.depth || 0 ) * 14;
			const collapsed = state.collapsed.has( o.termId );
			const toggle = o.hasChildren
				? '<span class="mn-mm-tree-toggle" data-toggle="' +
				  o.termId +
				  '">' +
				  ( collapsed ? '▶' : '▼' ) +
				  '</span>'
				: '<span class="mn-mm-tree-toggle"></span>';

			const colorDot = o.color
				? '<span class="mn-mm-tree-dot" style="background:' + esc( colHex( o.color ) ) + '"></span>'
				: '';
			/* 有設定角色的資料夾：名稱後挂鎖徽章（告知受限中）。 */
			const lockBadge = ( o.roles && o.roles.length )
				? ' <span class="mn-mm-tree-lock" title="' + esc( __( 'Folder access roles', 'foldernest' ) ) + '">🔒</span>'
				: '';

			/* 釘選列的 ★ 是可點擊的取消鈕（其餘區域點擊＝跳轉）。 */
			if ( o.pinned ) {
				return (
					'<li class="mn-mm-tree-item' +
					( o.active ? ' is-active' : '' ) +
					( o.color ? ' is-colored' : '' ) +
					' is-pinned" style="padding-left:' +
					pad +
					'px' +
					( o.color ? ';' + colVars( o.color ) : '' ) +
					'" data-key="' +
					o.key +
					'"' +
					( o.termId !== undefined ? ' data-term="' + o.termId + '"' : '' ) +
					'>' +
					'<button type="button" class="mn-mm-tree-star is-starred" data-star="' +
					o.termId +
					'" data-starred="1" title="' + __( 'Unpin folder', 'foldernest' ) + '">★</button>' +
					colorDot +
					'<span class="mn-mm-tree-name">' +
					esc( o.name ) +
					'</span>' +
					'<span class="mn-mm-tree-count">' +
					esc( o.count || 0 ) +
					'</span>' +
					'</li>'
				);
			}

			/* 🔒 存取角色鈕：免費版也顯示，但帶鎖定樣式（點擊→購買引導）。 */
			const rolesBtn =
				'<button type="button" data-roles="' +
				o.termId +
				'" title="' + __( 'Folder access roles', 'foldernest' ) + '" class="' +
				( CFG.pro ? '' : 'mn-mm-pro-locked' ) +
				'">🔒</button>';
			const starBtn =
				'<button type="button" data-star="' +
				o.termId +
				'" data-starred="' +
				( o.star ? '1' : '0' ) +
				'" title="' + __( 'Pin folder', 'foldernest' ) + '" class="' +
				( o.star ? 'is-starred' : '' ) +
				'">★</button>';
			const colorBtn =
				'<button type="button" data-color="' +
				o.termId +
				'" data-current="' +
				esc( o.color || '' ) +
				'" title="' + __( 'Folder color', 'foldernest' ) + '">🎨</button>';
			const actions =
				o.termId !== undefined
					? '<span class="mn-mm-tree-actions">' +
					  '<button type="button" data-rename="' +
					  o.termId +
					  '" title="' + __( 'Rename', 'foldernest' ) + '">✎</button>' +
					  colorBtn +
					  starBtn +
					  rolesBtn +
					  '<button type="button" class="mn-mm-danger" data-delete="' +
					  o.termId +
					  '" title="' + __( 'Delete', 'foldernest' ) + '">✕</button>' +
					  '</span>'
					: '';

			return (
				'<li class="mn-mm-tree-item' +
				( o.active ? ' is-active' : '' ) +
				( o.color ? ' is-colored' : '' ) +
				'" style="padding-left:' +
				pad +
				'px' +
				( o.color ? ';' + colVars( o.color ) : '' ) +
				'" data-key="' +
				o.key +
				'"' +
				( o.termId !== undefined ? ' data-term="' + o.termId + '"' : '' ) +
				'>' +
					toggle +
					colorDot +
					'<span class="mn-mm-tree-name">' +
					esc( o.name ) +
					lockBadge +
					'</span>' +
				'<span class="mn-mm-tree-count">' +
				esc( o.count || 0 ) +
				'</span>' +
				actions +
				'</li>'
			);
		}

		/* 上傳列的目標資料夾提示跟著目前瀏覽的資料夾走 */
		if ( typeof updateUploadTarget === 'function' ) {
			updateUploadTarget();
		}
	}

	/**
	 * 依收合狀態過濾出要顯示的資料夾（含子層判斷）。
	 */
	function visibleFolders() {
		const byId = {};
		state.folders.forEach( ( f ) => {
			byId[ f.term_id ] = f;
		} );

		return state.folders.filter( ( f ) => {
			let p = f.parent;
			let guard = 0;
			while ( p && byId[ p ] && guard < 30 ) {
				if ( state.collapsed.has( p ) ) {
					return false;
				}
				p = byId[ p ].parent;
				guard++;
			}
			return true;
		} );
	}

	/**
	 * 頁內上傳的目標資料夾 term_id（0 = 不指定，落在未分類）。
	 * IIFE 頂層宣告：renderTree 與上傳區塊都會用。
	 */
	function uploadTargetFolderId() {
		if ( state.folderId === 'none' || state.folderId === 0 ) {
			return 0;
		}
		return parseInt( state.folderId, 10 ) || 0;
	}

	/**
	 * 上傳列的目標資料夾提示（跟隨目前瀏覽的資料夾）。
	 */
	function updateUploadTarget() {
		const el = document.querySelector( '#mn-mm-upload-target' );
		if ( ! el ) {
			return;
		}
		let name = __( 'All media (uploads land in Unassigned)', 'foldernest' );
		if ( state.folderId === 'none' ) {
			name = __( 'Unassigned', 'foldernest' );
		} else if ( state.folderId > 0 ) {
			const f = state.folders.find( ( x ) => x.term_id === parseInt( state.folderId, 10 ) );
			if ( f ) {
				name = f.name;
			}
		}
		el.textContent = name;
	}

	function renderTags() {
		const box = $( '#mn-mm-tags' );
		if ( ! state.tags.length ) {
			box.innerHTML = '<span class="mn-mm-hint">' + __( 'No tags yet.', 'foldernest' ) + '</span>';
			return;
		}
		box.innerHTML = state.tags
			.map(
				( t ) =>
					'<span class="mn-mm-tag' +
					( state.tagIds.indexOf( t.term_id ) >= 0 ? ' is-active' : '' ) +
					'" data-tag="' +
					t.term_id +
					'">' +
					esc( t.name ) +
					'<span class="mn-mm-tag-count">' +
					esc( t.count ) +
					'</span></span>'
			)
			.join( '' );
	}

	/* =========================================================
	 *  媒體網格
	 * ========================================================= */

	function renderGrid( result ) {
		const grid = $( '#mn-mm-grid' );
		state.items = result.items || [];
		state.total = result.total || 0;
		state.pages = result.pages || 1;

		grid.classList.toggle( 'mn-mm-list', state.view === 'list' );
		renderFoldersBar();

		if ( ! state.items.length ) {
			grid.innerHTML = '<div class="mn-mm-empty">' + esc( CFG.strings.noResult ) + '</div>';
			renderPagination();
			return;
		}

		grid.innerHTML = state.items
			.map( ( item ) => {
				const isSelected = state.selectAll || state.selected.has( item.id );
				const thumb = item.thumb
					? '<img src="' + esc( item.thumb ) + '" alt="" loading="lazy">'
					: '<span class="dashicons ' + iconOf( item.type ) + ' mn-mm-card-icon"></span>';

				let flags = '';
				if ( item.used === 0 ) {
					flags += '<span class="mn-mm-flag mn-mm-flag--unused">' + __( 'Unused', 'foldernest' ) + '</span>';
				} else if ( item.used === 1 ) {
					flags += '<span class="mn-mm-flag mn-mm-flag--used">' + __( 'In use', 'foldernest' ) + '</span>';
				}
				if ( ! item.folders.length ) {
					flags += '<span class="mn-mm-flag mn-mm-flag--unassigned">' + __( 'Unassigned', 'foldernest' ) + '</span>';
				}

				const folderLabel = item.folders.length
					? item.folders.map( ( f ) => f.name ).join( ', ' )
					: __( 'Unassigned', 'foldernest' );

				const size = item.width && item.height ? item.width + '×' + item.height : item.ext;

				return (
					'<div class="mn-mm-card' +
					( isSelected ? ' is-selected' : '' ) +
					'" data-id="' +
					item.id +
					'" draggable="true">' +
					'<div class="mn-mm-card-thumb">' +
					thumb +
					'<span class="mn-mm-card-check">✓</span>' +
					'<span class="mn-mm-card-flags">' +
					flags +
					'</span>' +
					'<button type="button" class="mn-mm-card-copy" data-url="' +
					esc( item.url ) +
					'" title="' + __( 'Copy URL', 'foldernest' ) + '"><span class="dashicons dashicons-admin-links"></span></button>' +
					'</div>' +
					'<div class="mn-mm-card-body">' +
					'<div class="mn-mm-card-name" title="' +
					esc( item.filename || item.title ) +
					'">' +
					esc( item.filename || item.title ) +
					'</div>' +
					'<div class="mn-mm-card-meta"><span>' +
					esc( item.filesize_human ) +
					'</span><span>' +
					esc( size ) +
					'</span><span class="mn-mm-card-date">' +
					esc( item.date_human ) +
					'</span></div>' +
					'<div class="mn-mm-card-folder' +
					( item.folders.length ? '' : ' is-none' ) +
					'" title="' +
					esc( folderLabel ) +
					'">' +
					esc( folderLabel ) +
					'</div>' +
					'</div>' +
					'</div>'
				);
			} )
			.join( '' );

		renderPagination();
		renderBulkbar();
	}

	function iconOf( type ) {
		if ( type === 'audio' ) {
			return 'dashicons-format-audio';
		}
		if ( type === 'video' ) {
			return 'dashicons-format-video';
		}
		if ( type === 'document' ) {
			return 'dashicons-media-document';
		}
		return 'dashicons-format-image';
	}

	/* =========================================================
	 *  資料夾磚（中間面板）
	 *
	 *  瀏覽「全部媒體」時顯示根層資料夾；進入資料夾則顯示其
	 *  子資料夾＋麵包屑。媒體卡可拖進磚裡歸類；從桌面拖檔案
	 *  進磚＝直接傳進該資料夾（與左側樹同一個 override 機制）。
	 * ========================================================= */

	function renderFoldersBar() {
		const bar = $( '#mn-mm-folders' );
		if ( ! bar ) {
			return;
		}
		if ( state.folderId === 'none' ) {
			bar.hidden = true;
			bar.innerHTML = '';
			return;
		}

		const currentId = state.folderId > 0 ? parseInt( state.folderId, 10 ) : 0;
		const kids = state.folders
			.filter( ( f ) => ( f.parent || 0 ) === currentId )
			.sort( ( a, b ) => {
				/* 釘選優先，再按名稱。 */
				if ( !! a.star !== !! b.star ) {
					return a.star ? -1 : 1;
				}
				return a.name.localeCompare( b.name, undefined, { sensitivity: 'base' } );
			} );

		/* 麵包屑：目前資料夾往回走到根 */
		const byId = {};
		state.folders.forEach( ( f ) => {
			byId[ f.term_id ] = f;
		} );
		const crumbs = [];
		if ( currentId > 0 ) {
			let cur = byId[ currentId ];
			let guard = 0;
			while ( cur && guard < 20 ) {
				crumbs.unshift( cur );
				cur = byId[ cur.parent ];
				guard++;
			}
		}

		let html = '<nav class="mn-mm-crumbs">';
		html +=
			'<button type="button" class="mn-mm-crumb' +
			( currentId === 0 ? ' is-here' : '' ) +
			'" data-crumb="0">' +
			__( 'All media', 'foldernest' ) +
			'</button>';
		crumbs.forEach( ( c, i ) => {
			html += '<span class="sep">›</span>';
			html +=
				'<button type="button" class="mn-mm-crumb' +
				( i === crumbs.length - 1 ? ' is-here' : '' ) +
				'" data-crumb="' +
				c.term_id +
				'">' +
				esc( c.name ) +
				'</button>';
		} );
		html += '</nav>';

		html += '<div class="mn-mm-tiles">';
		kids.forEach( ( f ) => {
			html +=
				'<button type="button" class="mn-mm-folder-tile' +
				( f.color ? ' is-colored' : '' ) +
				'" data-tile="' +
				f.term_id +
				'" title="' +
				esc( f.name ) +
				'"' +
				( f.color ? ' style="' + colVars( f.color ) + '"' : '' ) +
				'>' +
				'<span class="dashicons dashicons-portfolio"></span>' +
				'<span class="mn-mm-tile-name">' +
				( f.star ? '<span class="mn-mm-tree-star">★</span>' : '' ) +
				esc( f.name ) +
				'</span>' +
				'<span class="mn-mm-tile-count">' +
				esc( f.count || 0 ) +
				'</span>' +
				'</button>';
		} );
		html +=
			'<button type="button" class="mn-mm-folder-tile is-add" data-tile-add="' +
			currentId +
			'" title="' +
			__( 'Add new folder', 'foldernest' ) +
			'">' +
			'<span class="dashicons dashicons-plus-alt2"></span>' +
			'<span class="mn-mm-tile-name">' +
			__( 'Add', 'foldernest' ) +
			'</span>' +
			'</button>';
		html += '</div>';

		bar.hidden = false;
		bar.innerHTML = html;
	}

	/**
	 * 把目前選取（或正在拖曳）的媒體移動到指定資料夾（replace 語意）。
	 * 資料夾磚的投放目標；左側樹的 drop 走同一端點。
	 */
	async function moveSelectionToFolder( folderId ) {
		const ids = state.dragIds && state.dragIds.length ? state.dragIds : selectedIds();
		const selectAll = state.selectAll && ! state.dragIds ? 1 : 0;
		state.dragIds = null;
		if ( ! ids.length && ! selectAll ) {
			return;
		}
		try {
			const data = await api(
				'bulk_folder',
				Object.assign(
					{ ids, select_all: selectAll, target_folder_id: folderId, mode: 'replace' },
					currentScope()
				)
			);
			toast( data.message, 'ok' );
			state.selected.clear();
			state.selectAll = false;
			await refresh( true );
		} catch ( err ) {
			toast( err.message, 'error' );
		}
	}

	function renderPagination() {
		const box = $( '#mn-mm-pagination' );
		if ( state.total <= state.perPage ) {
			box.innerHTML =
				'<span class="mn-mm-pagination-info">' +
				sprintf( __( '%d media in total', 'foldernest' ), state.total ) +
				'</span>';
			return;
		}

		box.innerHTML =
			'<button type="button" class="button" data-page="' +
			( state.paged - 1 ) +
			'"' +
			( state.paged <= 1 ? ' disabled' : '' ) +
			'>' + __( 'Previous', 'foldernest' ) + '</button>' +
			'<span class="mn-mm-pagination-info">' +
			sprintf( __( 'Page %1$d of %2$d, %3$d media in total', 'foldernest' ), state.paged, state.pages, state.total ) +
			'</span>' +
			'<button type="button" class="button" data-page="' +
			( state.paged + 1 ) +
			'"' +
			( state.paged >= state.pages ? ' disabled' : '' ) +
			'>' + __( 'Next', 'foldernest' ) + '</button>';
	}

	/* =========================================================
	 *  批次操作列
	 * ========================================================= */

	function renderBulkbar() {
		const bar = $( '#mn-mm-bulkbar' );
		const count = state.selectAll ? state.total : state.selected.size;

		bar.hidden = count === 0;
		$( '#mn-mm-selected-count' ).textContent = count;

		if ( count === 0 ) {
			return;
		}

		/* 重建資料夾下拉，但保留使用者已選的值。 */
		const sel = $( '#mn-mm-bulk-folder' );
		const previous = sel.value;
		const options = [ '<option value="">' + __( '— Select folder —', 'foldernest' ) + '</option>' ];
		state.folders.forEach( ( f ) => {
			options.push(
				'<option value="' +
					f.term_id +
					'">' +
					esc( '　'.repeat( f.depth ) + f.name ) +
					'</option>'
			);
		} );
		sel.innerHTML = options.join( '' );
		sel.value = previous;
	}

	/* =========================================================
	 *  詳細面板
	 * ========================================================= */

	async function openInspector( id ) {
		try {
			const item = await api( 'get_item', { id } );
			state.current = item;
			renderInspector( item );
		} catch ( e ) {
			toast( e.message, 'error' );
		}
	}

	function renderInspector( item ) {
		/* 切換媒體前，先停掉上一個正在播放的音頻／視頻 */
		document
			.querySelectorAll( '#mn-mm-inspector-body audio, #mn-mm-inspector-body video' )
			.forEach( ( el ) => {
				try {
					el.pause();
				} catch ( e ) {}
			} );
		if ( document.fullscreenElement ) {
			document.exitFullscreen().catch( () => {} );
		}

		$( '#mn-mm-inspector-empty' ).hidden = true;
		const body = $( '#mn-mm-inspector-body' );
		body.hidden = false;

		/* 預覽：圖片顯示原圖；音頻／視頻給原生播放器（可播放／暫停、
		 * 視頻另有全螢幕鈕）；其他類型維持圖示。 */
		let preview = '';
		if ( item.type === 'audio' ) {
			preview =
				'<div class="mn-mm-inspector-preview mn-mm-preview-audio">' +
				'<span class="dashicons ' + iconOf( item.type ) + '"></span>' +
				'<audio controls preload="none" src="' + esc( item.url ) + '"></audio>' +
				'</div>';
		} else if ( item.type === 'video' ) {
			preview =
				'<div class="mn-mm-inspector-preview mn-mm-preview-video">' +
				'<video controls preload="metadata" src="' + esc( item.url ) + '"></video>' +
				'</div>';
		} else if ( item.thumb ) {
			preview = '<div class="mn-mm-inspector-preview"><img src="' + esc( item.url ) + '" alt=""></div>';
		} else {
			preview = '<div class="mn-mm-inspector-preview"><span class="dashicons ' + iconOf( item.type ) + '"></span></div>';
		}

		const folderOptions = [ '<option value="">' + __( '— Unassigned —', 'foldernest' ) + '</option>' ]
			.concat(
				state.folders.map(
					( f ) =>
						'<option value="' +
						f.term_id +
						'"' +
						( item.folders.some( ( x ) => x.term_id === f.term_id ) ? ' selected' : '' ) +
						'>' +
						esc( '　'.repeat( f.depth ) + f.name ) +
						'</option>'
				)
			)
			.join( '' );

		const tagChips = state.tags
			.map(
				( t ) =>
					'<span class="mn-mm-tag' +
					( item.tags.some( ( x ) => x.term_id === t.term_id ) ? ' is-active' : '' ) +
					'" data-inspector-tag="' +
					t.term_id +
					'">' +
					esc( t.name ) +
					'</span>'
			)
			.join( '' );

		const usageText =
			item.used === 1
				? '<span style="color:#007017">' + __( 'In use', 'foldernest' ) + '</span>'
				: item.used === 0
				? '<span style="color:#b32d2e">' + __( 'Unused', 'foldernest' ) + '</span>'
				: __( 'Not scanned', 'foldernest' );

		body.innerHTML =
			preview +
			'<div class="mn-mm-inspector-file">' +
			esc( item.filename ) +
			'</div>' +
			'<div class="mn-mm-inspector-actions">' +
			'<a class="button button-small" href="' +
			esc( CFG.editUrl + '?post=' + item.id + '&action=edit' ) +
			'" target="_blank" rel="noopener">' + __( 'Open in native editor', 'foldernest' ) + '</a>' +
			'<button type="button" class="button button-small" data-copy="' +
			esc( item.url ) +
			'">' + __( 'Copy URL', 'foldernest' ) + '</button>' +
			'<button type="button" class="button button-small mn-mm-danger-btn" data-inspector-delete="' +
			esc( item.id ) +
			'" title="' + __( 'Delete permanently (skips trash, cannot be undone)', 'foldernest' ) + '">' + __( 'Delete permanently', 'foldernest' ) + '</button>' +
			'</div>' +
			'<div class="mn-mm-field-row"><label>' + __( 'Title', 'foldernest' ) + '</label><input type="text" id="mn-mm-f-title" value="' +
			esc( item.title ) +
			'"></div>' +
			'<div class="mn-mm-field-row"><label>' + __( 'Alt text', 'foldernest' ) + '</label><input type="text" id="mn-mm-f-alt" value="' +
			esc( item.alt ) +
			'"></div>' +
			'<div class="mn-mm-field-row"><label>' + __( 'Caption', 'foldernest' ) + '</label><input type="text" id="mn-mm-f-caption" value="' +
			esc( item.caption ) +
			'"></div>' +
			'<div class="mn-mm-field-row"><label>' + __( 'Description', 'foldernest' ) + '</label><textarea id="mn-mm-f-desc">' +
			esc( item.description ) +
			'</textarea></div>' +
			'<div class="mn-mm-field-row"><label>' + __( 'Folder', 'foldernest' ) + '</label><select id="mn-mm-f-folder">' +
			folderOptions +
			'</select></div>' +
			'<div class="mn-mm-field-row"><label>' + __( 'Tags', 'foldernest' ) + '</label><div class="mn-mm-tags">' +
			( tagChips || '<span class="mn-mm-hint">' + __( 'No tags yet.', 'foldernest' ) + '</span>' ) +
			'</div></div>' +
			'<button type="button" class="button button-primary" id="mn-mm-save-item">' + __( 'Save changes', 'foldernest' ) + '</button>' +
			'<ul class="mn-mm-meta-list">' +
			'<li>' + __( 'File size: ', 'foldernest' ) + '<strong>' +
			esc( item.filesize_human ) +
			'</strong></li>' +
			'<li>' + __( 'Dimensions: ', 'foldernest' ) + '<strong>' +
			esc( item.width && item.height ? item.width + ' × ' + item.height : '—' ) +
			'</strong></li>' +
			'<li>' + __( 'Type: ', 'foldernest' ) + '<strong>' +
			esc( item.mime ) +
			'</strong></li>' +
			'<li>' + __( 'Uploaded: ', 'foldernest' ) + '<strong>' +
			esc( item.date_human ) +
			'</strong></li>' +
			'<li>' + __( 'Usage: ', 'foldernest' ) + '<strong>' +
			usageText +
			'</strong></li>' +
			'<li>ID: <strong>' +
			esc( item.id ) +
			'</strong></li>' +
			'</ul>';

		state.currentTags = item.tags.map( ( t ) => t.term_id );
	}

	async function saveCurrentItem() {
		if ( ! state.current ) {
			return;
		}

		const folderVal = $( '#mn-mm-f-folder' ).value;

		const payload = {
			id: state.current.id,
			title: $( '#mn-mm-f-title' ).value,
			alt: $( '#mn-mm-f-alt' ).value,
			caption: $( '#mn-mm-f-caption' ).value,
			description: $( '#mn-mm-f-desc' ).value,
			folder_id: folderVal === '' ? 0 : folderVal,
			tag_ids: state.currentTags || [],
		};

		try {
			const item = await api( 'save_item', payload );
			state.current = item;
			renderInspector( item );
			toast( __( 'Saved.', 'foldernest' ), 'ok' );
			await refresh( false );
		} catch ( e ) {
			toast( e.message, 'error' );
		}
	}

	/* =========================================================
	 *  資料載入
	 * ========================================================= */

	/*
	 * 媒體查詢的請求序號。
	 *
	 * 每次 loadMedia() 都遞增，回應回來時若已經不是最新的一次就整批丟棄。
	 * 沒有這個防護時，頁面剛載入的「全部媒體」請求可能比使用者搶先點的
	 * 那個資料夾還晚回來，把畫面上的結果蓋回舊的（左側已選 B，網格卻顯示全部）。
	 */
	let mediaReq = 0;

	async function loadMedia() {
		/* 重新載入會把卡片整個換掉，進行中的框選就沒有意義了。 */
		cancelHold();
		if ( marquee ) {
			marqueeCancel();
		}

		const grid = $( '#mn-mm-grid' );
		grid.innerHTML = Array.from( { length: 12 } )
			.map( () => '<div class="mn-mm-skeleton"></div>' )
			.join( '' );

		const req   = ++mediaReq;
		const parts = state.sort.split( '-' );

		try {
			const result = await api( 'load_media', {
				folder_id: state.folderId,
				include_children: state.includeChildren ? 1 : 0,
				tag_ids: state.tagIds,
				mime_group: state.mimeGroup,
				usage: state.usage,
				search: state.search,
				paged: state.paged,
				per_page: state.perPage,
				orderby: parts[ 0 ],
				order: parts[ 1 ],
			} );

			/* 已經有更新的請求發出，這次的結果就過期了，不要蓋掉畫面。 */
			if ( req !== mediaReq ) {
				return;
			}

			renderGrid( result );
		} catch ( e ) {
			if ( req !== mediaReq ) {
				return;
			}
			grid.innerHTML = '<div class="mn-mm-empty">' + esc( e.message ) + '</div>';
		}
	}

	async function loadTree() {
		try {
			const data = await api( 'tree', {} );
			state.folders = data.folders || [];
			state.tags = data.tags || [];
			state.folderPaths = data.folder_paths || state.folderPaths;
			renderStats( data.stats || {} );
			renderTree();
			renderTags();
			renderBulkbar();
		} catch ( e ) {
			toast( e.message, 'error' );
		}
	}

	/**
	 * 重新整理（可選是否一併重載樹）。
	 *
	 * @param {boolean} withTree
	 */
	async function refresh( withTree ) {
		if ( withTree ) {
			await loadTree();
		}
		await loadMedia();
	}

	/* =========================================================
	 *  批次操作
	 * ========================================================= */

	function selectedIds() {
		return state.selectAll ? [] : Array.from( state.selected );
	}

	/**
	 * 目前的篩選條件（select_all 模式時，後端要靠這組條件重查）。
	 */
	function currentScope() {
		return {
			folder_id: state.folderId,
			include_children: state.includeChildren ? 1 : 0,
			tag_ids: state.tagIds,
			mime_group: state.mimeGroup,
			usage: state.usage,
			search: state.search,
		};
	}

	function toggleSelection( id, card ) {
		/* 一旦手動調整選取，就退出「全選」模式，改為明確清單。 */
		state.selectAll = false;

		if ( state.selected.has( id ) ) {
			state.selected.delete( id );
		} else {
			state.selected.add( id );
		}

		if ( card ) {
			card.classList.toggle( 'is-selected', state.selected.has( id ) );
		}
		renderBulkbar();
	}

	/* =========================================================
	 *  拉框選取
	 *
	 *  兩種啟動方式：
	 *    1. 在網格空白處按下並拖曳 → 立即開始
	 *    2. 在卡片上「長按」約 0.35 秒後拖曳 → 開始
	 *       （短按直接拖曳仍是原本的「拖到資料夾」，不會被搶走）
	 *
	 *  按住 Ctrl / Cmd / Shift 拉框 = 累加選取，否則為取代選取。
	 * ========================================================= */

	const MARQUEE_HOLD_MS = 350; /* 長按多久才進入框選 */
	const MARQUEE_TOLERANCE = 6; /* 長按期間容忍的位移（px） */
	const MARQUEE_EDGE = 64; /* 自動捲動的感應邊界（px） */
	const MARQUEE_EDGE_MAX = 20; /* 自動捲動的最大速度（px / frame） */

	let marquee = null;
	let marqueeRaf = 0;
	let holdTimer = null;
	let holdCard = null;
	let holdPoint = null;
	let suppressClickUntil = 0;

	function marqueeBox() {
		let box = document.getElementById( 'mn-mm-marquee' );
		if ( ! box ) {
			box = document.createElement( 'div' );
			box.id = 'mn-mm-marquee';
			box.className = 'mn-mm-marquee';
			box.hidden = true;
			document.body.appendChild( box );
		}
		return box;
	}

	function cancelHold() {
		if ( holdTimer ) {
			window.clearTimeout( holdTimer );
			holdTimer = null;
		}
		holdCard = null;
		holdPoint = null;
	}

	/**
	 * 開始框選。
	 *
	 * 卡片座標在開始時就換算成「文件座標」快取起來，
	 * 之後每一帧只做純數學比對，不必反覆讀取 getBoundingClientRect，
	 * 而且中途捲動頁面也不會錯位。
	 */
	function marqueeStart( x, y, additive, owner ) {
		const sx = window.scrollX;
		const sy = window.scrollY;

		marquee = {
			additive: !! additive,
			owner: owner || null,
			startX: x,
			startY: y,
			curX: x,
			curY: y,
			docX: x + sx,
			docY: y + sy,
			base: new Set( state.selected ),
			/* 若原本處於「全選」模式，累加框選時整頁都該維持選取。 */
			wasSelectAll: !! state.selectAll,
			page: new Set(),
			cards: [],
			gridTop: null,
			gridLeft: null,
		};
		marqueeResync();

		document.body.classList.add( 'mn-mm-marquee-on' );
		marqueeBox().hidden = false;

		if ( ! marqueeRaf ) {
			marqueeRaf = window.requestAnimationFrame( marqueeLoop );
		}
	}

	/**
	 * 重新量測卡片位置。
	 *
	 * 框選途中版面可能位移——最常見的是「批次操作列」在第一張卡片被選中時
	 * 冒出來，把整個網格往下推。若沿用位移前的座標，選取範圍就會跟畫面上的
	 * 框差一截。這裡每帧只讀一次網格位置，真的變了才重量所有卡片。
	 */
	function marqueeResync() {
		const m = marquee;
		const grid = $( '#mn-mm-grid' );
		const g = grid.getBoundingClientRect();
		const sx = window.scrollX;
		const sy = window.scrollY;
		const top = g.top + sy;
		const left = g.left + sx;

		if ( top === m.gridTop && left === m.gridLeft ) {
			return;
		}

		m.gridTop = top;
		m.gridLeft = left;
		m.cards = Array.from( grid.querySelectorAll( '.mn-mm-card' ) ).map( ( el ) => {
			const r = el.getBoundingClientRect();
			return {
				el: el,
				id: parseInt( el.dataset.id, 10 ),
				/* 以目前的畫面狀態為基準，重新量測不會讓已選取的卡片跳掉 */
				on: el.classList.contains( 'is-selected' ),
				left: r.left + sx,
				top: r.top + sy,
				right: r.right + sx,
				bottom: r.bottom + sy,
			};
		} );
		m.page = new Set( m.cards.map( ( c ) => c.id ) );
	}

	/* 框選期間持續跑，順便處理「拖到視窗邊緣自動捲動」。 */
	function marqueeLoop() {
		marqueeRaf = 0;
		if ( ! marquee ) {
			return;
		}
		marqueeScroll();
		marqueeResync();
		marqueeDraw();
		marqueeRaf = window.requestAnimationFrame( marqueeLoop );
	}

	function marqueeScroll() {
		const m = marquee;
		const vh = window.innerHeight;
		let dy = 0;

		if ( m.curY < MARQUEE_EDGE ) {
			dy = -Math.ceil( ( ( MARQUEE_EDGE - m.curY ) / MARQUEE_EDGE ) * MARQUEE_EDGE_MAX );
		} else if ( m.curY > vh - MARQUEE_EDGE ) {
			dy = Math.ceil( ( ( m.curY - ( vh - MARQUEE_EDGE ) ) / MARQUEE_EDGE ) * MARQUEE_EDGE_MAX );
		}

		if ( dy ) {
			window.scrollBy( 0, dy );
		}
	}

	function marqueeDraw() {
		const m = marquee;
		const sx = window.scrollX;
		const sy = window.scrollY;

		const x1 = Math.min( m.startX, m.curX );
		const y1 = Math.min( m.startY, m.curY );
		const x2 = Math.max( m.startX, m.curX );
		const y2 = Math.max( m.startY, m.curY );

		const box = marqueeBox();
		box.style.left = x1 + 'px';
		box.style.top = y1 + 'px';
		box.style.width = x2 - x1 + 'px';
		box.style.height = y2 - y1 + 'px';

		/* 框選範圍換算成文件座標，才能跟快取的卡片座標直接比對。 */
		const d1x = Math.min( m.docX, m.curX + sx );
		const d2x = Math.max( m.docX, m.curX + sx );
		const d1y = Math.min( m.docY, m.curY + sy );
		const d2y = Math.max( m.docY, m.curY + sy );

		m.cards.forEach( ( c ) => {
			const hit = c.right >= d1x && c.left <= d2x && c.bottom >= d1y && c.top <= d2y;
			const on = hit || ( m.additive && ( m.base.has( c.id ) || m.wasSelectAll ) );
			if ( c.on !== on ) {
				c.on = on;
				c.el.classList.toggle( 'is-selected', on );
			}
		} );

		marqueePreview();
	}

	/* 框選過程中即時更新「已選 N 個」與批次操作列的可見度。 */
	function marqueePreview() {
		const m = marquee;

		/* 「全選」模式下累加框選，選取範圍不變，直接沿用總數。 */
		if ( m.wasSelectAll && m.additive ) {
			$( '#mn-mm-bulkbar' ).hidden = state.total === 0;
			$( '#mn-mm-selected-count' ).textContent = state.total;
			return;
		}

		let count = 0;

		m.base.forEach( ( id ) => {
			if ( ! m.page.has( id ) ) {
				count++;
			}
		} );
		m.cards.forEach( ( c ) => {
			if ( c.on ) {
				count++;
			}
		} );

		$( '#mn-mm-bulkbar' ).hidden = count === 0;
		$( '#mn-mm-selected-count' ).textContent = count;
	}

	function marqueeEnd() {
		const m = marquee;
		marquee = null;

		document.body.classList.remove( 'mn-mm-marquee-on' );
		marqueeBox().hidden = true;
		restoreDraggable( m );
		suppressClickUntil = Date.now() + 300;

		/* 「全選」＋累加框選 → 等於沒有變化，維持原本的全選狀態。 */
		if ( m.wasSelectAll && m.additive ) {
			state.selectAll = true;
			state.selected = m.base;
			m.cards.forEach( ( c ) => c.el.classList.add( 'is-selected' ) );
			renderBulkbar();
			return;
		}

		const next = new Set( m.base );
		if ( ! m.additive ) {
			m.page.forEach( ( id ) => next.delete( id ) );
		}
		m.cards.forEach( ( c ) => {
			if ( c.on ) {
				next.add( c.id );
			}
		} );

		state.selectAll = false;
		state.selected = next;

		m.cards.forEach( ( c ) => c.el.classList.toggle( 'is-selected', next.has( c.id ) ) );

		renderBulkbar();
	}

	/* 中途放棄框選（Esc）：還原成框選前的狀態。 */
	function marqueeCancel() {
		const m = marquee;
		marquee = null;

		document.body.classList.remove( 'mn-mm-marquee-on' );
		marqueeBox().hidden = true;

		m.cards.forEach( ( c ) => c.el.classList.toggle( 'is-selected', m.base.has( c.id ) ) );

		restoreDraggable( m );
		suppressClickUntil = Date.now() + 300;
		renderBulkbar();
	}

	function restoreDraggable( m ) {
		if ( m.owner ) {
			m.owner.draggable = true;
		}
	}

	async function doBulk( kind ) {
		const ids = selectedIds();
		const selectAll = state.selectAll ? 1 : 0;

		if ( ! ids.length && ! selectAll ) {
			toast( CFG.strings.noSelection, 'error' );
			return;
		}

		const base = Object.assign( { ids, select_all: selectAll }, currentScope() );

		try {
			let data;

			if ( kind === 'folder-move' || kind === 'folder-add' ) {
				const folderId = $( '#mn-mm-bulk-folder' ).value;
				if ( ! folderId ) {
					toast( __( 'Select a target folder first.', 'foldernest' ), 'error' );
					return;
				}
				data = await api(
					'bulk_folder',
					Object.assign( {}, base, {
						target_folder_id: folderId,
						mode: kind === 'folder-add' ? 'add' : 'replace',
					} )
				);
			} else if ( kind === 'folder-clear' ) {
				data = await api( 'bulk_folder', Object.assign( {}, base, { mode: 'clear' } ) );
			} else if ( kind === 'media-delete' ) {
				/* 破壞性操作：二次確認。可見的選取媒體若有「使用中」，特別警示。 */
				const usedCount = state.items.filter(
					( i ) => state.selected.has( i.id ) && i.used === 1
				).length;

				let msg = selectAll
					? sprintf( __( 'Permanently delete ALL %d matching media?\nThe files and all thumbnails will be removed. This cannot be undone!', 'foldernest' ), state.total )
					: sprintf( __( 'Permanently delete %d media?\nThe files and all thumbnails will be removed. This cannot be undone!', 'foldernest' ), ids.length );
				if ( usedCount > 0 ) {
					msg += '\n\n' + sprintf( __( 'Warning: %d of them are in use by posts; deleting will leave missing images in your content.', 'foldernest' ), usedCount );
				}
				if ( ! window.confirm( msg ) ) {
					return;
				}

				data = await api( 'media_delete', base );
			} else if ( kind === 'tag-add' || kind === 'tag-remove' ) {
				const names = $( '#mn-mm-bulk-tags' ).value.trim();
				if ( ! names ) {
					toast( __( 'Enter tag names.', 'foldernest' ), 'error' );
					return;
				}
				data = await api(
					'bulk_tag',
					Object.assign( {}, base, {
						tag_names: names,
						mode: kind === 'tag-remove' ? 'remove' : 'add',
					} )
				);
			} else if ( kind === 'bulk-edit' ) {
				const fields = {
					title: $( '#mn-mm-edit-title' ).value,
					alt: $( '#mn-mm-edit-alt' ).value,
					caption: $( '#mn-mm-edit-caption' ).value,
					description: $( '#mn-mm-edit-desc' ).value,
				};
				if ( ! fields.title && ! fields.alt && ! fields.caption && ! fields.description ) {
					toast( __( 'Fill in at least one field.', 'foldernest' ), 'error' );
					return;
				}
				data = await api(
					'bulk_edit',
					Object.assign( {}, base, {
						fields,
						mode: $( '#mn-mm-edit-mode' ).value,
					} )
				);
			}

			if ( data && data.message ) {
				toast( data.message, 'ok' );
			}

			state.selected.clear();
			state.selectAll = false;
			await refresh( true );

			if ( state.current ) {
				openInspector( state.current.id );
			}
		} catch ( e ) {
			toast( e.message, 'error' );
		}
	}

	/* =========================================================
	 *  資料夾 / 標籤 CRUD
	 * ========================================================= */

	/**
	 * 資料夾顏色彈出層：8 色快速色板＋原生調色盤自訂。含關閉鈕、
	 * 選定後自動關閉；點外面／Esc 也可關閉。
	 */
	const COLOR_SWATCHES = [ '', '#e65054', '#dba617', '#00a32a', '#2271b1', '#7a2ee6', '#f06fa0', '#646970' ];

	function openColorPop( anchor ) {
		let pop = document.getElementById( 'mn-mm-color-pop' );
		if ( ! pop ) {
			pop = document.createElement( 'div' );
			pop.id = 'mn-mm-color-pop';
			pop.className = 'mn-mm-color-pop';
			document.body.appendChild( pop );

			document.addEventListener( 'mousedown', ( e ) => {
				if ( ! pop.hidden && ! e.target.closest( '#mn-mm-color-pop' ) && ! e.target.closest( '[data-color]' ) ) {
					pop.hidden = true;
				}
			} );
			document.addEventListener( 'keydown', ( e ) => {
				if ( 'Escape' === e.key && ! pop.hidden ) {
					pop.hidden = true;
				}
			} );
		}

		const termId  = parseInt( anchor.dataset.color, 10 );
		const current = anchor.dataset.current || '';
		pop.innerHTML =
			'<div class="mn-mm-color-pop-head">' +
			'<span class="mn-mm-color-pop-title">' + __( 'Folder color', 'foldernest' ) + '</span>' +
			'<button type="button" class="mn-mm-color-pop-close" title="' + __( 'Close', 'foldernest' ) + '">✕</button>' +
			'</div>' +
			'<div class="mn-mm-color-swatches">' +
			COLOR_SWATCHES.map( ( c ) =>
				'<button type="button" class="mn-mm-color-swatch' +
				( c === current ? ' is-current' : '' ) +
				( '' === c ? ' is-none' : '' ) +
				'" style="' + ( c ? 'background:' + c : '' ) + '" data-swatch="' +
				c +
				'" title="' + ( c ? c : __( 'No color', 'foldernest' ) ) + '"></button>'
			).join( '' ) +
			'</div>' +
			'<label class="mn-mm-color-custom">' +
			__( 'Custom', 'foldernest' ) +
			' <input type="color" value="' + ( current || '#2271b1' ) + '">' +
			'</label>';

		const close = () => {
			pop.hidden = true;
		};
		pop.querySelector( '.mn-mm-color-pop-close' ).addEventListener( 'click', close );

		const apply = async ( color ) => {
			close();
			try {
				const d = await api( 'folder_color', {
					term_id: termId,
					color,
				} );
				state.folders = d.folders || state.folders;
				renderTree();
				renderFoldersBar();
				toast( d.message, 'ok' );
			} catch ( err ) {
				toast( err.message, 'error' );
			}
		};

		pop.querySelectorAll( '[data-swatch]' ).forEach( ( sw ) => {
			sw.addEventListener( 'click', () => apply( sw.dataset.swatch ) );
		} );
		pop.querySelector( 'input[type="color"]' ).addEventListener( 'change', ( e ) => apply( e.target.value ) );

		const r = anchor.getBoundingClientRect();
		pop.hidden = false;
		pop.style.left = Math.min( r.left, window.innerWidth - 220 ) + 'px';
		pop.style.top = r.bottom + 6 + 'px';
	}

	async function addFolder( parent ) {
		const name = window.prompt( __( 'New folder name:', 'foldernest' ) );
		if ( ! name ) {
			return;
		}
		try {
			await api( 'folder_create', { name, parent: parent || 0 } );
			await loadTree();
			toast( __( 'Folder created.', 'foldernest' ), 'ok' );
		} catch ( e ) {
			toast( e.message, 'error' );
		}
	}

	async function renameFolder( termId ) {
		const current = state.folders.find( ( f ) => f.term_id === termId );
		const name = window.prompt( __( 'New folder name:', 'foldernest' ), current ? current.name : '' );
		if ( ! name ) {
			return;
		}
		try {
			await api( 'folder_rename', { term_id: termId, name } );
			await loadTree();
			toast( __( 'Folder renamed.', 'foldernest' ), 'ok' );
		} catch ( e ) {
			toast( e.message, 'error' );
		}
	}

	async function deleteFolder( termId ) {
		const f = state.folders.find( ( x ) => x.term_id === termId );
		const count = f ? ( f.count || 0 ) : 0;

		/* 深刪除對話框為 Pro 功能：有媒體且 Pro 才走。 */
		if ( CFG.pro && count > 0 && window.MN_MM_Pro ) {
			window.MN_MM_Pro.deepDelete( termId, f ? f.name : '', count );
			return;
		}

		if ( ! window.confirm( CFG.strings.confirmDeleteFolder ) ) {
			return;
		}
		try {
			await api( 'folder_delete', { term_id: termId } );
			if ( state.folderId === termId ) {
				state.folderId = 0;
			}
			await refresh( true );
			toast( __( 'Folder deleted.', 'foldernest' ), 'ok' );
		} catch ( e ) {
			toast( e.message, 'error' );
		}
	}

	

	async function addTag() {
		const name = window.prompt( __( 'New tag name:', 'foldernest' ) );
		if ( ! name ) {
			return;
		}
		try {
			await api( 'tag_create', { name } );
			await loadTree();
			toast( __( 'Tag created.', 'foldernest' ), 'ok' );
		} catch ( e ) {
			toast( e.message, 'error' );
		}
	}

	/* =========================================================
	 *  自動分類
	 * ========================================================= */

	function renderRules() {
		const box = $( '#mn-mm-rules' );

		const pathOptions = state.folderPaths
			.map( ( p ) => '<option value="' + esc( p.path ) + '">' + esc( p.path ) + '</option>' )
			.join( '' );

		const datalist = '<datalist id="mn-mm-folder-paths">' + pathOptions + '</datalist>';

		if ( ! state.rules.length ) {
			box.innerHTML =
				'<div class="mn-mm-empty">' + __( 'No rules yet. Click "Add rule" below to start.', 'foldernest' ) + '</div>' + datalist;
			return;
		}

		box.innerHTML =
			state.rules
				.map( ( rule, idx ) => {
				const m = rule.match || {};

				return (
					'<div class="mn-mm-rule-card' +
					( rule.enabled ? '' : ' is-disabled' ) +
					'" data-index="' +
					idx +
					'">' +
					'<div class="mn-mm-rule-head">' +
					'<label class="mn-mm-check"><input type="checkbox" data-field="enabled"' +
					( rule.enabled ? ' checked' : '' ) +
					'>' + __( 'Enable', 'foldernest' ) + '</label>' +
					'<input type="text" data-field="label" value="' +
					esc( rule.label ) +
					'" placeholder="' + __( 'Rule name', 'foldernest' ) + '">' +
					'<button type="button" class="button button-small" data-rule-move="up">↑</button>' +
					'<button type="button" class="button button-small" data-rule-move="down">↓</button>' +
					'<button type="button" class="button button-small" data-rule-delete>' + __( 'Delete', 'foldernest' ) + '</button>' +
					'</div>' +
					'<div class="mn-mm-rule-grid">' +
					'<label>' + __( 'Filename contains (comma-separated, any)', 'foldernest' ) + '<input type="text" data-match="filename_contains" value="' +
					esc( m.filename_contains || '' ) +
					'" placeholder="' + __( 'e.g. asmr,drama', 'foldernest' ) + '"></label>' +
					'<label>' + __( 'File extensions (comma-separated)', 'foldernest' ) + '<input type="text" data-match="ext_in" value="' +
					esc( m.ext_in || '' ) +
					'" placeholder="' + __( 'e.g. mp3,m4a', 'foldernest' ) + '"></label>' +
					'<label>' + __( 'Filename regex (advanced, optional)', 'foldernest' ) + '<input type="text" data-match="filename_regex" value="' +
					esc( m.filename_regex || '' ) +
					'" placeholder="' + __( 'e.g. ^3star-', 'foldernest' ) + '"></label>' +
					'<label>' + __( 'Type', 'foldernest' ) + '<select data-match="mime_group">' +
					option( '', __( 'Any', 'foldernest' ), m.mime_group ) +
					option( 'image', __( 'Image', 'foldernest' ), m.mime_group ) +
					option( 'audio', __( 'Audio', 'foldernest' ), m.mime_group ) +
					option( 'video', __( 'Video', 'foldernest' ), m.mime_group ) +
					option( 'document', __( 'Document', 'foldernest' ), m.mime_group ) +
					'</select></label>' +
					'<label>' + __( 'Min width (px, 0 = any)', 'foldernest' ) + '<input type="number" data-match="min_width" value="' +
					esc( m.min_width || 0 ) +
					'"></label>' +
					'<label>' + __( 'Year (0 = any)', 'foldernest' ) + '<input type="number" data-match="year" value="' +
					esc( m.year || 0 ) +
					'"></label>' +
					'</div>' +
					'<div class="mn-mm-rule-grid">' +
					'<label>' + __( 'Target folder (use / for nesting; folders are created automatically)', 'foldernest' ) +
					'<input type="text" data-field="target_folder" list="mn-mm-folder-paths" value="' +
					esc( rule.target_folder || '' ) +
					'" placeholder="' + __( 'e.g. Videos/ASMR', 'foldernest' ) + '"></label>' +
					'<label>' + __( 'Also add tags (comma-separated, optional)', 'foldernest' ) + '<input type="text" data-field="add_tags" value="' +
					esc( ( rule.add_tags || [] ).join( ',' ) ) +
					'"></label>' +
					'</div>' +
					'<div class="mn-mm-rule-foot">' +
					'<label class="mn-mm-check"><input type="checkbox" data-field="only_unassigned"' +
					( rule.only_unassigned ? ' checked' : '' ) +
					'>' + __( 'Only process unassigned media (recommended)', 'foldernest' ) + '</label>' +
					'</div>' +
					'</div>'
				);
			} )
				.join( '' ) + datalist;

		function option( value, label, current ) {
			return (
				'<option value="' +
				value +
				'"' +
				( String( current || '' ) === value ? ' selected' : '' ) +
				'>' +
				label +
				'</option>'
			);
		}
	}

	/**
	 * 把畫面上的規則讀回物件。
	 */
	function collectRules() {
		const cards = document.querySelectorAll( '.mn-mm-rule-card' );
		const rules = [];

		cards.forEach( ( card ) => {
			const get = ( sel ) => {
				const el = card.querySelector( sel );
				return el ? el.value : '';
			};
			const checked = ( sel ) => {
				const el = card.querySelector( sel );
				return el ? el.checked : false;
			};

			rules.push( {
				id: state.rules[ card.dataset.index ]
					? state.rules[ card.dataset.index ].id
					: undefined,
				label: get( '[data-field="label"]' ),
				enabled: checked( '[data-field="enabled"]' ),
				only_unassigned: checked( '[data-field="only_unassigned"]' ),
				match: {
					filename_contains: get( '[data-match="filename_contains"]' ),
					filename_regex: get( '[data-match="filename_regex"]' ),
					ext_in: get( '[data-match="ext_in"]' ),
					mime_group: get( '[data-match="mime_group"]' ),
					min_width: parseInt( get( '[data-match="min_width"]' ), 10 ) || 0,
					max_width: 0,
					year: parseInt( get( '[data-match="year"]' ), 10 ) || 0,
				},
				target_folder: get( '[data-field="target_folder"]' ),
				add_tags: get( '[data-field="add_tags"]' )
					.split( ',' )
					.map( ( s ) => s.trim() )
					.filter( Boolean ),
			} );
		} );

		return rules;
	}

	function autoScope() {
		const picked = document.querySelector( 'input[name="mn-mm-auto-scope"]:checked' );
		const value = picked ? picked.value : 'all';

		const scope = {
			folder_id: 0,
			usage: '',
			mime_group: '',
			search: '',
		};

		if ( value === 'unassigned' ) {
			scope.folder_id = 'none';
		} else if ( value === 'image' || value === 'audio' || value === 'video' ) {
			scope.mime_group = value;
		}

		return scope;
	}

	async function saveRules( silent ) {
		try {
			const data = await api( 'auto_save_rules', {
				rules: JSON.stringify( collectRules() ),
			} );
			state.rules = data.rules || [];
			renderRules();
			if ( ! silent ) {
				toast( data.message || __( 'Rules saved.', 'foldernest' ), 'ok' );
			}
			return true;
		} catch ( e ) {
			toast( e.message, 'error' );
			return false;
		}
	}

	async function previewAuto() {
		try {
			await saveRules( true );
			const data = await api( 'auto_preview', {
				rules: JSON.stringify( state.rules ),
				...autoScope(),
			} );
			renderPreview( data );
		} catch ( e ) {
			toast( e.message, 'error' );
		}
	}

	function renderPreview( data ) {
		const box = $( '#mn-mm-preview' );
		box.hidden = false;

		const rows = data.rules
			.filter( ( r ) => r.count > 0 || r.skipped > 0 )
			.map( ( r ) => {
				const samples = r.samples.length
					? '<div class="mn-mm-preview-samples">' + __( 'Examples:', 'foldernest' ) + ' ' +
					  r.samples.map( ( s ) => esc( s.filename ) ).join( ', ' ) +
					  '</div>'
					: '';

				return (
					'<div class="mn-mm-preview-row"><span>' +
					esc( r.label ) +
					' → <span class="mn-mm-preview-target">' +
					esc( r.target ) +
					'</span></span><span><strong>' +
					esc( r.count ) +
					'</strong> ' + __( 'items', 'foldernest' ) +
					( r.skipped ? sprintf( __( ' (skipped %d)', 'foldernest' ), esc( r.skipped ) ) : '' ) +
					'</span></div>' +
					samples
				);
			} )
			.join( '' );

		box.innerHTML =
			'<div class="mn-mm-preview-head">' + __( 'Dry run (nothing has been saved yet)', 'foldernest' ) + '</div>' +
			( rows || '<div class="mn-mm-preview-row">' + __( 'No media matches the current rules.', 'foldernest' ) + '</div>' ) +
			'<div class="mn-mm-preview-row"><span>' + __( 'Media in scope', 'foldernest' ) + '</span><span><strong>' +
			esc( data.candidates ) +
			'</strong></span></div>' +
			'<div class="mn-mm-preview-row"><span>' + __( 'No rule matched', 'foldernest' ) + '</span><span><strong>' +
			esc( data.unmatched ) +
			'</strong></span></div>' +
			'<div class="mn-mm-preview-row"><span>' + __( 'Skipped (already assigned)', 'foldernest' ) + '</span><span><strong>' +
			esc( data.skipped ) +
			'</strong></span></div>' +
			'<div class="mn-mm-preview-row"><span>' + __( 'Will be assigned', 'foldernest' ) + '</span><span><strong>' +
			esc( data.will_apply ) +
			'</strong> ' + __( 'items', 'foldernest' ) + '</span></div>';
	}

	async function applyAuto() {
		if ( ! window.confirm( CFG.strings.confirmApply ) ) {
			return;
		}

		const ok = await saveRules( true );
		if ( ! ok ) {
			return;
		}

		try {
			await api( 'auto_start', {
				rules: JSON.stringify( state.rules ),
				...autoScope(),
			} );

			progress( true, __( 'Auto-assigning…', 'foldernest' ), 0, true );
			await runAutoSteps();
		} catch ( e ) {
			progress( false );
			toast( e.message, 'error' );
		}
	}

	async function runAutoSteps() {
		let done = false;
		let guard = 0;

		while ( ! done && guard < 500 ) {
			if ( autoCancelled ) {
				autoCancelled = false;
				progress( false );
				return;
			}

			guard++;
			const data = await api( 'auto_step', {} );

			const percent = data.total ? Math.round( ( data.processed / data.total ) * 100 ) : 100;
			progress(
				true,
				sprintf( __( 'Auto-assigning… %1$d / %2$d (assigned %3$d, skipped %4$d)', 'foldernest' ), data.processed, data.total, data.applied, data.skipped ),
				percent,
				true
			);

			done = !! data.done;

			if ( done ) {
				progress( false );
				toast(
					sprintf( __( 'Auto-assign complete: %1$d assigned, %2$d skipped. Undo it from Activity Log if needed.', 'foldernest' ), data.applied, data.skipped ),
					'ok'
				);
				$( '#mn-mm-preview' ).hidden = true;
				closeModal( '#mn-mm-modal-auto' );
				state.selected.clear();
				await refresh( true );
			}
		}

		if ( guard >= 500 ) {
			progress( false );
			toast( __( 'Too many batches; stopped. Run again to continue.', 'foldernest' ), 'error' );
		}
	}

	/* =========================================================
	 *  使用狀態掃描
	 * ========================================================= */

	async function scanUsage() {
		try {
			progress( true, __( 'Analyzing site references…', 'foldernest' ), 2, false );

			let offset = 0;
			let done = false;
			let guard = 0;
			let total = 0;

			while ( ! done && guard < 500 ) {
				guard++;
				const data = await api( 'usage_scan', {
					offset,
					limit: 200,
					rebuild: guard === 1 ? 1 : 0,
				} );

				total = data.total;
				offset = data.processed;
				done = !! data.done;

				const percent = total ? Math.round( ( offset / total ) * 100 ) : 100;
				progress( true, sprintf( __( 'Scanning usage… %1$d / %2$d', 'foldernest' ), offset, total ), percent, false );
			}

			progress( false );
			toast( sprintf( __( 'Scan complete: %d media processed.', 'foldernest' ), offset ), 'ok' );
			await refresh( true );
		} catch ( e ) {
			progress( false );
			toast( e.message, 'error' );
		}
	}

	/* =========================================================
	 *  操作記錄
	 * ========================================================= */

	async function openLogs() {
		openModal( '#mn-mm-modal-logs' );
		const box = $( '#mn-mm-log-list' );
		box.innerHTML = '<div class="mn-mm-loading">' + __( 'Loading…', 'foldernest' ) + '</div>';

		try {
			const data = await api( 'log_list', { limit: 40 } );
			const logs = data.logs || [];

			if ( ! logs.length ) {
				box.innerHTML = '<div class="mn-mm-empty">' + __( 'No activity yet.', 'foldernest' ) + '</div>';
				return;
			}

			box.innerHTML = logs
				.map(
					( log ) =>
						'<div class="mn-mm-log-item">' +
						'<div class="mn-mm-log-main">' +
						'<div class="mn-mm-log-action">' +
						esc( log.label ) +
						'　<span style="font-weight:400;color:#646970;">' +
						sprintf( __( '%d media', 'foldernest' ), esc( log.object_count ) ) +
						'</span></div>' +
						'<div class="mn-mm-log-meta">' +
						esc( log.created_at ) +
						'　·　' +
						esc( log.user_name ) +
						'</div>' +
						'</div>' +
						( parseInt( log.undone, 10 ) === 1
							? '<span class="mn-mm-log-undone">' + __( 'Undone', 'foldernest' ) + '</span>'
							: log.undoable
							? '<button type="button" class="button button-small" data-undo="' +
							  log.id +
							  '">' + __( 'Undo', 'foldernest' ) + '</button>'
							: '<span class="mn-mm-log-undone">' + __( 'Cannot undo', 'foldernest' ) + '</span>' ) +
						'</div>'
				)
				.join( '' );
		} catch ( e ) {
			box.innerHTML = '<div class="mn-mm-empty">' + esc( e.message ) + '</div>';
		}
	}

	async function undoLog( logId ) {
		if ( ! window.confirm( __( 'Undo this action?', 'foldernest' ) ) ) {
			return;
		}
		try {
			const data = await api( 'log_undo', { log_id: logId } );
			toast( data.message || __( 'Undone.', 'foldernest' ), 'ok' );
			await openLogs();
			await refresh( true );
		} catch ( e ) {
			toast( e.message, 'error' );
		}
	}

	/* =========================================================
	 *  對話框
	 * ========================================================= */

	function openModal( sel ) {
		const el = $( sel );
		if ( el ) {
			el.hidden = false;
		}
	}

	function closeModal( sel ) {
		const el = $( sel );
		if ( el ) {
			el.hidden = true;
		}
	}

	/**
	 * Pro 購買引導（免費版點鎖定功能時彈出）。
	 */
	function openUpsell() {
		const box = $( '#mn-mm-upsell-features' );
		if ( box && ! box.dataset.filled ) {
			box.innerHTML = CFG.proFeatures
				.map( ( f ) => '<li>' + esc( f ) + '</li>' )
				.join( '' );
			box.dataset.filled = '1';
		}
		$( '#mn-mm-upsell-buy' ).href = CFG.buyUrl;
		openModal( '#mn-mm-modal-upsell' );
	}

	/* =========================================================
	 *  事件綁定
	 * ========================================================= */

	function bindEvents() {
		/* ---------- Pro 鎖定攔截（免費版：點 Pro 功能 → 購買引導）----------
		 * 捕獲階段攔截 .mn-mm-pro-locked，擋在功能 handler 之前。 */
		document.addEventListener(
			'click',
			( e ) => {
				if ( CFG.pro ) {
					return;
				}
				const locked = e.target.closest( '.mn-mm-pro-locked' );
				if ( locked ) {
					e.preventDefault();
					e.stopImmediatePropagation();
					openUpsell();
				}
			},
			true
		);

		/* ---------- 資料夾樹 ---------- */
		$( '#mn-mm-tree' ).addEventListener( 'click', async ( e ) => {
			const toggle = e.target.closest( '[data-toggle]' );
			if ( toggle ) {
				const id = parseInt( toggle.dataset.toggle, 10 );
				if ( state.collapsed.has( id ) ) {
					state.collapsed.delete( id );
				} else {
					state.collapsed.add( id );
				}
				renderTree();
				return;
			}

			const rename = e.target.closest( '[data-rename]' );
			if ( rename ) {
				renameFolder( parseInt( rename.dataset.rename, 10 ) );
				return;
			}

			/* 星標釘選（切換） */
			const starBtn = e.target.closest( '[data-star]' );
			if ( starBtn ) {
				try {
					const d = await api( 'folder_star', {
						term_id: parseInt( starBtn.dataset.star, 10 ),
						star: '1' === starBtn.dataset.starred ? 0 : 1,
					} );
					state.folders = d.folders || state.folders;
					renderTree();
					renderFoldersBar();
				} catch ( err ) {
					toast( err.message, 'error' );
				}
				return;
			}

			/* 資料夾顏色：小彈出色板 */
			const colorBtn = e.target.closest( '[data-color]' );
			if ( colorBtn ) {
				openColorPop( colorBtn );
				return;
			}

			const del = e.target.closest( '[data-delete]' );
			if ( del ) {
				deleteFolder( parseInt( del.dataset.delete, 10 ) );
				return;
			}

			/* 資料夾存取角色限制 */
			const rolesBtn = e.target.closest( '[data-roles]' );
			if ( rolesBtn ) {
				if ( window.MN_MM_Pro ) { window.MN_MM_Pro.openRoles( parseInt( rolesBtn.dataset.roles, 10 ) ); } else { openUpsell(); }
				return;
			}

			const row = e.target.closest( '.mn-mm-tree-item' );
			if ( ! row ) {
				return;
			}

			const key = row.dataset.key;
			/* 釘選列 key 為 'star-<id>' */
			if ( 0 === key.indexOf( 'star-' ) ) {
				state.folderId = parseInt( key.slice( 5 ), 10 );
			} else {
				state.folderId = key === 'all' ? 0 : key === 'none' ? 'none' : parseInt( key, 10 );
			}
			state.paged = 1;
			renderTree();
			loadMedia();
		} );

		/* 拖拉媒體到資料夾 = 移動；拖到「未分類」= 移出所有資料夾。
		 * 注意：後端 bulk_folder 的目標資料夾參數是 target_folder_id
		 * （與篩選用的 folder_id 區分），送錯鍵名會一直回「無效的目標資料夾」。
		 * 從作業系統拖「檔案」進來（上傳）是另一種手勢：types 含 Files，
		 * 資料夾列只做 override 標記，實際上傳交給 plupload 的整頁 dropzone。 */
		function isFileDrag( e ) {
			try {
				const types = e.dataTransfer && e.dataTransfer.types;
				return !! types && Array.prototype.indexOf.call( types, 'Files' ) !== -1;
			} catch ( err ) {
				return false;
			}
		}

		$( '#mn-mm-tree' ).addEventListener( 'dragover', ( e ) => {
			if ( isFileDrag( e ) ) {
				/* 檔案拖到資料夾列 → 該列即上傳目標，亮起來。 */
				const fRow = e.target.closest( '.mn-mm-tree-item[data-term]' );
				if ( fRow ) {
					e.preventDefault();
					if ( e.dataTransfer ) {
						e.dataTransfer.dropEffect = 'copy';
					}
					document
						.querySelectorAll( '#mn-mm-tree .is-drop-target' )
						.forEach( ( r ) => {
							if ( r !== fRow ) {
								r.classList.remove( 'is-drop-target' );
							}
						} );
					fRow.classList.add( 'is-drop-target' );
				}
				return;
			}

			const row = e.target.closest(
				'.mn-mm-tree-item[data-term], .mn-mm-tree-item[data-key="none"]'
			);
			if ( ! row ) {
				return;
			}
			e.preventDefault();
			if ( e.dataTransfer ) {
				e.dataTransfer.dropEffect = 'move';
			}
			/* 只亮目前這一列，離開的列要清掉 */
			document
				.querySelectorAll( '#mn-mm-tree .is-drop-target' )
				.forEach( ( r ) => {
					if ( r !== row ) {
						r.classList.remove( 'is-drop-target' );
					}
				} );
			row.classList.add( 'is-drop-target' );
		} );

		$( '#mn-mm-tree' ).addEventListener( 'dragleave', ( e ) => {
			if ( isFileDrag( e ) ) {
				return;
			}
			const row = e.target.closest(
				'.mn-mm-tree-item[data-term], .mn-mm-tree-item[data-key="none"]'
			);
			if ( row ) {
				row.classList.remove( 'is-drop-target' );
			}
		} );

		$( '#mn-mm-tree' ).addEventListener( 'drop', async ( e ) => {
			const isFile = isFileDrag( e );

			/* 檔案拖到特定資料夾列上放開 → 標記 override，檔案會傳進該資料夾。
			 * 不 stopPropagation：事件續往上冒給 plupload 的整頁 dropzone 處理。 */
			if ( isFile ) {
				const fRow = e.target.closest( '.mn-mm-tree-item[data-term]' );
				if ( fRow ) {
					e.preventDefault();
					state.uploadFolderOverride = parseInt( fRow.dataset.term, 10 ) || 0;
					fRow.classList.remove( 'is-drop-target' );
				}
				return;
			}

			const row = e.target.closest(
				'.mn-mm-tree-item[data-term], .mn-mm-tree-item[data-key="none"]'
			);
			if ( ! row ) {
				return;
			}
			e.preventDefault();
			row.classList.remove( 'is-drop-target' );

			const ids = state.dragIds && state.dragIds.length ? state.dragIds : selectedIds();
			const selectAll = state.selectAll && ! state.dragIds ? 1 : 0;
			state.dragIds = null;
			if ( ! ids.length && ! selectAll ) {
				return;
			}

			const isNone = row.dataset.key === 'none';
			try {
				const data = await api(
					'bulk_folder',
					Object.assign( isNone
						? { ids, select_all: selectAll, mode: 'clear' }
						: {
							ids,
							select_all: selectAll,
							target_folder_id: parseInt( row.dataset.term, 10 ),
							mode: 'replace',
						}, currentScope() )
				);
				toast( data.message, 'ok' );
				state.selected.clear();
				state.selectAll = false;
				await refresh( true );
			} catch ( err ) {
				toast( err.message, 'error' );
			}
		} );

		/* ---------- 資料夾磚（中間面板） ---------- */
		const foldersBar = $( '#mn-mm-folders' );
		if ( foldersBar ) {
			foldersBar.addEventListener( 'click', ( e ) => {
				const crumb = e.target.closest( '[data-crumb]' );
				if ( crumb ) {
					state.folderId = parseInt( crumb.dataset.crumb, 10 ) || 0;
					state.paged = 1;
					renderTree();
					loadMedia();
					return;
				}
				const add = e.target.closest( '[data-tile-add]' );
				if ( add ) {
					addFolder( parseInt( add.dataset.tileAdd, 10 ) || 0 );
					return;
				}
				const tile = e.target.closest( '[data-tile]' );
				if ( tile ) {
					state.folderId = parseInt( tile.dataset.tile, 10 );
					state.paged = 1;
					renderTree();
					loadMedia();
				}
			} );

			foldersBar.addEventListener( 'dragover', ( e ) => {
				const tile = e.target.closest( '[data-tile]' );
				foldersBar.querySelectorAll( '.is-drop-target' ).forEach( ( t ) => {
					if ( t !== tile ) {
						t.classList.remove( 'is-drop-target' );
					}
				} );
				if ( ! tile ) {
					return;
				}
				e.preventDefault();
				if ( e.dataTransfer && ! isFileDrag( e ) ) {
					e.dataTransfer.dropEffect = 'move';
				}
				tile.classList.add( 'is-drop-target' );
			} );

			foldersBar.addEventListener( 'dragleave', ( e ) => {
				const tile = e.target.closest( '[data-tile]' );
				if ( tile ) {
					tile.classList.remove( 'is-drop-target' );
				}
			} );

			foldersBar.addEventListener( 'drop', ( e ) => {
				const tile = e.target.closest( '[data-tile]' );
				if ( ! tile ) {
					return;
				}
				tile.classList.remove( 'is-drop-target' );

				if ( isFileDrag( e ) ) {
					/* 桌面檔案拖進磚 = 直接傳進該資料夾；不攔截事件，
					 * 讓 plupload 的整頁拖放區接手上傳。 */
					e.preventDefault();
					state.uploadFolderOverride = parseInt( tile.dataset.tile, 10 ) || 0;
					return;
				}

				e.preventDefault();
				moveSelectionToFolder( parseInt( tile.dataset.tile, 10 ) );
			} );
		}

		/* ---------- 標籤篩選 ---------- */
		$( '#mn-mm-tags' ).addEventListener( 'click', ( e ) => {
			const tag = e.target.closest( '[data-tag]' );
			if ( ! tag ) {
				return;
			}
			const id = parseInt( tag.dataset.tag, 10 );
			const idx = state.tagIds.indexOf( id );
			if ( idx >= 0 ) {
				state.tagIds.splice( idx, 1 );
			} else {
				state.tagIds.push( id );
			}
			state.paged = 1;
			renderTags();
			loadMedia();
		} );

		/* ---------- 工具列 ---------- */
		$( '#mn-mm-search' ).addEventListener(
			'input',
			debounce( ( e ) => {
				state.search = e.target.value;
				state.paged = 1;
				loadMedia();
			}, 350 )
		);

		$( '#mn-mm-type' ).addEventListener( 'change', ( e ) => {
			state.mimeGroup = e.target.value;
			state.paged = 1;
			loadMedia();
		} );

		$( '#mn-mm-usage' ).addEventListener( 'change', ( e ) => {
			state.usage = e.target.value;
			state.paged = 1;
			loadMedia();
		} );

		$( '#mn-mm-sort' ).addEventListener( 'change', async ( e ) => {
			state.sort = e.target.value;
			state.paged = 1;

			/* 尺寸排序：索引未建滿時先分批補建（有進度提示）。 */
			if ( 0 === state.sort.indexOf( 'size-' ) && ( state.stats.filesize_missing || 0 ) > 0 ) {
				try {
					let offset = 0;
					let done = false;
					let guard = 0;
					while ( ! done && guard < 2000 ) {
						guard++;
						const d = await api( 'filesize_build', { offset, limit: 300 } );
						offset = d.processed;
						done = d.done;
						progress(
							true,
							sprintf( __( 'Building file size index… %d remaining', 'foldernest' ), d.missing ),
							0,
							false
						);
					}
					progress( false );
					const t = await api( 'tree', {} );
					state.stats = t.stats || state.stats;
				} catch ( err ) {
					progress( false );
					toast( err.message, 'error' );
				}
			}

			loadMedia();
		} );

		$( '#mn-mm-include-children' ).addEventListener( 'change', ( e ) => {
			state.includeChildren = e.target.checked;
			state.paged = 1;
			loadMedia();
		} );

		/* ---------- 檢視模式切換（縮略圖 / 列表） ---------- */
		function applyView() {
			const grid = $( '#mn-mm-grid' );
			if ( grid ) {
				grid.classList.toggle( 'mn-mm-list', state.view === 'list' );
			}
			const gb = $( '#mn-mm-view-grid' );
			const lb = $( '#mn-mm-view-list' );
			if ( gb ) {
				gb.classList.toggle( 'is-active', state.view !== 'list' );
			}
			if ( lb ) {
				lb.classList.toggle( 'is-active', state.view === 'list' );
			}
		}

		$( '#mn-mm-view-grid' ).addEventListener( 'click', () => {
			state.view = 'grid';
			localStorage.setItem( 'mn-mm-view', 'grid' );
			applyView();
		} );

		$( '#mn-mm-view-list' ).addEventListener( 'click', () => {
			state.view = 'list';
			localStorage.setItem( 'mn-mm-view', 'list' );
			applyView();
		} );

		applyView();

		/* ---------- 網格：複製網址 ---------- */
		async function copyToClipboard( text ) {
			try {
				if ( navigator.clipboard && window.isSecureContext ) {
					await navigator.clipboard.writeText( text );
					return true;
				}
			} catch ( e ) {}

			/* 後備：暫存 textarea + execCommand（非安全連線環境用） */
			const ta = document.createElement( 'textarea' );
			ta.value = text;
			ta.style.position = 'fixed';
			ta.style.opacity = '0';
			document.body.appendChild( ta );
			ta.select();
			let ok = false;
			try {
				ok = document.execCommand( 'copy' );
			} catch ( e ) {
				ok = false;
			}
			ta.remove();
			return ok;
		}

		$( '#mn-mm-grid' ).addEventListener( 'click', async ( e ) => {
			const btn = e.target.closest( '.mn-mm-card-copy' );
			if ( ! btn ) {
				return;
			}
			e.preventDefault();
			e.stopImmediatePropagation();
			const ok = await copyToClipboard( btn.dataset.url );
			toast( ok ? __( 'URL copied to clipboard.', 'foldernest' ) : __( 'Copy failed. Copy it manually from the details panel.', 'foldernest' ), ok ? 'ok' : 'error' );
		} );

		/* ---------- 網格：選取與詳情 ---------- */
		$( '#mn-mm-grid' ).addEventListener( 'click', ( e ) => {
			/* 剛結束框選 → 忽略瀏覽器補上的這次 click */
			if ( Date.now() < suppressClickUntil ) {
				return;
			}

			/* 複製網址按鈕有自己的處理器 */
			if ( e.target.closest( '.mn-mm-card-copy' ) ) {
				return;
			}

			const card = e.target.closest( '.mn-mm-card' );
			if ( ! card ) {
				return;
			}
			const id = parseInt( card.dataset.id, 10 );

			/* 勾選框、或按住 Ctrl/Cmd/Shift → 切換選取 */
			if ( e.target.closest( '.mn-mm-card-check' ) || e.ctrlKey || e.metaKey || e.shiftKey ) {
				toggleSelection( id, card );
				return;
			}

			/* 點卡片其他位置 → 開詳細面板（不動選取狀態） */
			openInspector( id );
		} );

		/* ---------- 網格：拉框選取 ---------- */
		$( '#mn-mm-grid' ).addEventListener( 'mousedown', ( e ) => {
			if ( e.button !== 0 ) {
				return;
			}

			/* 勾選框與複製鈕自己會處理，不要搶手勢 */
			if ( e.target.closest( '.mn-mm-card-check' ) || e.target.closest( '.mn-mm-card-copy' ) ) {
				return;
			}

			const additive = e.ctrlKey || e.metaKey || e.shiftKey;
			const card = e.target.closest( '.mn-mm-card' );

			/* 空白處 → 立即開始框選 */
			if ( ! card ) {
				e.preventDefault();
				marqueeStart( e.clientX, e.clientY, additive, null );
				return;
			}

			/* 卡片上 → 長按才轉為框選；短按拖曳仍維持「拖到資料夾」 */
			holdCard = card;
			holdPoint = { x: e.clientX, y: e.clientY };
			holdTimer = window.setTimeout( () => {
				holdTimer = null;
				if ( ! holdPoint ) {
					return;
				}
				const owner = holdCard;
				owner.draggable = false; /* 免得原生拖曳把手勢搶走 */
				marqueeStart( holdPoint.x, holdPoint.y, additive, owner );
			}, MARQUEE_HOLD_MS );
		} );

		/* 長按／框選期間的全域滑鼠事件 */
		document.addEventListener( 'mousemove', ( e ) => {
			if ( holdTimer ) {
				const moved =
					Math.abs( e.clientX - holdPoint.x ) + Math.abs( e.clientY - holdPoint.y );
				if ( moved > MARQUEE_TOLERANCE ) {
					cancelHold(); /* 使用者在拖曳，不是在長按 */
				}
				return;
			}
			if ( marquee ) {
				marquee.curX = e.clientX;
				marquee.curY = e.clientY;
			}
		} );

		document.addEventListener( 'mouseup', () => {
			if ( holdTimer ) {
				cancelHold(); /* 短按 → 交給 click 處理 */
				return;
			}
			if ( marquee ) {
				marqueeEnd();
			}
		} );

		/* 視窗失焦時 mouseup 可能收不到，補一道安全網避免框選卡住。 */
		window.addEventListener( 'blur', () => {
			cancelHold();
			if ( marquee ) {
				marqueeEnd();
			}
		} );

		/* 拖拉卡片 */
		$( '#mn-mm-grid' ).addEventListener( 'dragstart', ( e ) => {
			/* 框選進行中不允許拖曳卡片 */
			if ( marquee ) {
				e.preventDefault();
				return;
			}

			const card = e.target.closest( '.mn-mm-card' );
			if ( ! card ) {
				return;
			}
			const id = parseInt( card.dataset.id, 10 );
			/* 拖的卡片在選取集合內 → 拖整個選取；否則只拖它自己 */
			state.dragIds = state.selected.has( id ) ? selectedIds() : [ id ];
			e.dataTransfer.effectAllowed = 'move';
			/* Firefox 沒有 setData 不會啟動拖曳 */
			e.dataTransfer.setData( 'text/plain', String( id ) );
			/* 左側樹進入「可投放」狀態 */
			const tree = document.querySelector( '#mn-mm-tree' );
			if ( tree ) {
				tree.classList.add( 'mn-mm-tree-droppable' );
			}
		} );

		window.addEventListener( 'dragend', () => {
			state.dragIds = null;
			const tree = document.querySelector( '#mn-mm-tree' );
			if ( tree ) {
				tree.classList.remove( 'mn-mm-tree-droppable' );
			}
			document
				.querySelectorAll( '#mn-mm-tree .is-drop-target' )
				.forEach( ( r ) => r.classList.remove( 'is-drop-target' ) );
		} );

		/* ---------- 分頁 ---------- */
		$( '#mn-mm-pagination' ).addEventListener( 'click', ( e ) => {
			const btn = e.target.closest( '[data-page]' );
			if ( ! btn ) {
				return;
			}
			const page = parseInt( btn.dataset.page, 10 );
			if ( page < 1 || page > state.pages ) {
				return;
			}
			state.paged = page;
			loadMedia();
			$( '#mn-mm-grid' ).scrollIntoView( { behavior: 'smooth', block: 'start' } );
		} );

		/* ---------- 批次操作列 ---------- */
		$( '#mn-mm-select-all' ).addEventListener( 'click', () => {
			state.selectAll = true;
			state.selected.clear();
			document
				.querySelectorAll( '.mn-mm-card' )
				.forEach( ( c ) => c.classList.add( 'is-selected' ) );
			renderBulkbar();
			toast(
				sprintf( __( 'Selected all %d matching media (across pages).', 'foldernest' ), state.total ),
				'ok'
			);
		} );

		$( '#mn-mm-clear-selection' ).addEventListener( 'click', () => {
			state.selectAll = false;
			state.selected.clear();
			document
				.querySelectorAll( '.mn-mm-card.is-selected' )
				.forEach( ( c ) => c.classList.remove( 'is-selected' ) );
			renderBulkbar();
		} );

		$( '#mn-mm-bulkbar' ).addEventListener( 'click', ( e ) => {
			const btn = e.target.closest( '[data-bulk]' );
			if ( btn ) {
				doBulk( btn.dataset.bulk );
			}
		} );

		$( '#mn-mm-toggle-edit' ).addEventListener( 'click', () => {
			const box = $( '#mn-mm-bulk-edit' );
			box.hidden = ! box.hidden;
		} );

		$( '#mn-mm-add-folder' ).addEventListener( 'click', () => {
			const parent =
				typeof state.folderId === 'number' && state.folderId > 0 ? state.folderId : 0;
			addFolder( parent );
		} );

		$( '#mn-mm-add-tag' ).addEventListener( 'click', addTag );

		/* ---------- 詳細面板 ---------- */
		$( '#mn-mm-inspector' ).addEventListener( 'click', async ( e ) => {
			if ( e.target.closest( '#mn-mm-save-item' ) ) {
				saveCurrentItem();
				return;
			}

			/* 永久刪除（單個） */
			const delBtn = e.target.closest( '[data-inspector-delete]' );
			if ( delBtn ) {
				const id = parseInt( delBtn.dataset.inspectorDelete, 10 );
				const item = state.current;
				let msg = __( 'Permanently delete this media?\nThe files and all thumbnails will be removed. This cannot be undone!', 'foldernest' );
				if ( item && item.used === 1 ) {
					msg += '\n\n' + __( 'Warning: this media is currently in use by posts; deleting will leave missing images in your content.', 'foldernest' );
				}
				if ( ! window.confirm( msg ) ) {
					return;
				}
				try {
					const data = await api( 'media_delete', { ids: [ id ] } );
					toast( data.message, 'ok' );
					state.current = null;
					$( '#mn-mm-inspector-empty' ).hidden = false;
					$( '#mn-mm-inspector-body' ).hidden = true;
					await refresh( true );
				} catch ( err ) {
					toast( err.message, 'error' );
				}
				return;
			}

			const copy = e.target.closest( '[data-copy]' );
			if ( copy ) {
				const text = copy.dataset.copy;
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard
						.writeText( text )
						.then( () => toast( __( 'URL copied.', 'foldernest' ), 'ok' ) )
						.catch( () => toast( __( 'Copy failed. Select and copy manually.', 'foldernest' ), 'error' ) );
				} else {
					toast( __( 'Auto-copy is not supported in this browser. Select and copy manually.', 'foldernest' ), 'error' );
				}
				return;
			}

			const tag = e.target.closest( '[data-inspector-tag]' );
			if ( tag ) {
				const id = parseInt( tag.dataset.inspectorTag, 10 );
				const idx = state.currentTags.indexOf( id );
				if ( idx >= 0 ) {
					state.currentTags.splice( idx, 1 );
				} else {
					state.currentTags.push( id );
				}
				tag.classList.toggle( 'is-active' );
			}
		} );

		/* ---------- 頂部按鈕 ---------- */
		$( '#mn-mm-btn-scan' ).addEventListener( 'click', scanUsage );
		$( '#mn-mm-btn-logs' ).addEventListener( 'click', openLogs );
		$( '#mn-mm-btn-auto' ).addEventListener( 'click', () => {
			renderRules();
			openModal( '#mn-mm-modal-auto' );
		} );

		/* ---------- 頁內上傳（方案 A 按鈕 + 方案 B 拖放區）----------
		 * 上傳完成偵測統一由 bindUploadCompletion() 驅動（FileUploaded 計數、
		 * UploadComplete 才刷新 —— 佇列的 add 是「排隊」不是「完成」）。 */
		let uploadedCount = 0;

		function bindUploadCompletion( wrapper ) {
			if ( ! wrapper || ! wrapper.uploader || wrapper.__mnCompletionBound ) {
				return;
			}
			wrapper.__mnCompletionBound = true;

			wrapper.uploader.bind( 'FileUploaded', () => {
				uploadedCount += 1;
			} );

			wrapper.uploader.bind( 'UploadComplete', async () => {
				const n = uploadedCount;
				uploadedCount = 0;
				state.uploadFolderOverride = 0; /* 整批完成才清一次性目標 */
				if ( n > 0 ) {
					await refresh( true );
					toast( sprintf( __( 'Uploaded %d media. The list and folders have been updated.', 'foldernest' ), n ), 'ok' );
				}
				const bar = document.querySelector( '#mn-mm-upload-progress' );
				if ( bar ) {
					bar.hidden = true;
				}
			} );

			wrapper.uploader.bind( 'Error', ( up, err ) => {
				toast( ( err && err.message ) ? sprintf( __( 'Upload failed: %s', 'foldernest' ), err.message ) : __( 'Upload failed.', 'foldernest' ), 'error' );
			} );
		}

		let uploadFrame = null;

		$( '#mn-mm-btn-upload' ).addEventListener( 'click', () => {
			if ( typeof window.wp === 'undefined' || ! window.wp.media ) {
				toast( __( 'Media scripts are not loaded. Please reload the page.', 'foldernest' ), 'error' );
				return;
			}
			uploadedCount = 0;

			if ( ! uploadFrame ) {
				uploadFrame = window.wp.media( {
					title: __( 'Upload media', 'foldernest' ),
					frame: 'post',
					multiple: true,
				} );
				/* 開啟後直接停在「上傳文件」頁籤。 */
				uploadFrame.on( 'open', () => {
					window.setTimeout( () => {
						/* 上傳頁籤的標籤依核心 l10n 比對（任何語言都成立），
						 * 再以常見譯文與 /upload/ 正則後備。 */
						const l10n = window.wp.media.view && window.wp.media.view.l10n ? String( window.wp.media.view.l10n.uploadFiles || '' ) : '';
						window
							.jQuery( uploadFrame.$el.find( '.media-menu-item' ) )
							.filter( function () {
								const t = window.jQuery( this ).text();
								return (
									( l10n && t.indexOf( l10n ) !== -1 ) ||
									t.indexOf( '上传' ) !== -1 ||
									t.indexOf( '上傳' ) !== -1 ||
									/upload/i.test( t )
								);
							} )
							.first()
							.trigger( 'click' );
					}, 50 );
				} );
			}
			uploadFrame.open();
		} );

		/* ---------- 頁內上傳（方案 B）：plupload 拖放區 ----------
		 * 建構 wp.Uploader 包裝器實例（核心會處理 nonce/上限/mime，
		 * 並把完成的附件加進 wp.Uploader.queue）。
		 * 拖放範圍＝整個外掛頁面（.mn-mm-wrap）：拖到哪個資料夾列就傳進
		 * 那個資料夾（uploadFolderOverride），其餘位置＝目前瀏覽的資料夾。
		 * updateUploadTarget / uploadTargetFolderId 在 IIFE 頂層（renderTree 也會呼叫）。 */
		if ( window.wp && window.wp.Uploader && window.plupload && CFG.uploadNonce ) {
			try {
				const mmUploader = new window.wp.Uploader( {
					container: document.getElementById( 'mn-mm-upload-dropzone' ),
					browser:   document.getElementById( 'mn-mm-upload-browse' ),
					dropzone:  document.querySelector( '.mn-mm-wrap' ),
					params:    { mn_mm_nonce: CFG.uploadNonce },
				} );

				if ( mmUploader.supported && mmUploader.uploader ) {
					bindUploadCompletion( mmUploader );

					/* 每個檔案上傳前：注入目標資料夾與歸類 nonce。
					 * 有 override（檔案拖到特定資料夾列上放開）用 override，
					 * 否則用目前瀏覽的資料夾。override 到 UploadComplete 才清，
					 * 多檔拖到同一個資料夾時整批都用同一個目標。 */
					mmUploader.uploader.bind( 'BeforeUpload', ( up ) => {
						const target = state.uploadFolderOverride || uploadTargetFolderId();
						const current = Object.assign(
							{},
							up.getOption ? up.getOption( 'multipart_params' ) : up.settings.multipart_params,
							{ mn_mm_folder: String( target ), mn_mm_nonce: CFG.uploadNonce }
						);
						up.setOption( 'multipart_params', current );
					} );

					mmUploader.uploader.bind( 'UploadProgress', ( up ) => {
						const bar = document.querySelector( '#mn-mm-upload-progress' );
						if ( bar ) {
							bar.hidden = false;
							const fill = document.querySelector( '#mn-mm-upload-progress-fill' );
							const text = document.querySelector( '#mn-mm-upload-progress-text' );
							if ( fill ) {
								fill.style.width = up.total.percent + '%';
							}
							if ( text ) {
								text.textContent = sprintf( __( 'Uploading %1$d / %2$d (%3$d%%)', 'foldernest' ), up.total.uploaded, up.total.count, up.total.percent );
							}
						}
					} );

					/* 暴露實例供除錯與測試 */
					window.MN_MM.uploader = mmUploader;
				}
			} catch ( e ) {
				/* 環境不支援時靜默跳過，不影響其他功能。 */
			}

			/* 整頁都是拖放區的收尾：掉在頁面其他地方（側邊選單、管理列上）
			 * 也不要讓瀏覽器直接開啟檔案。 */
			window.addEventListener( 'dragover', ( e ) => {
				if ( e.dataTransfer && Array.prototype.indexOf.call( e.dataTransfer.types || [], 'Files' ) !== -1 ) {
					e.preventDefault();
				}
			} );
			window.addEventListener( 'drop', ( e ) => {
				if ( e.dataTransfer && Array.prototype.indexOf.call( e.dataTransfer.types || [], 'Files' ) !== -1 ) {
					e.preventDefault();
				}
			} );
		}

		/* ---------- 自動分類對話框 ---------- */
		$( '#mn-mm-preview-btn' ).addEventListener( 'click', previewAuto );
		$( '#mn-mm-apply-btn' ).addEventListener( 'click', applyAuto );
		$( '#mn-mm-save-rules' ).addEventListener( 'click', () => saveRules( false ) );

		$( '#mn-mm-reset-rules' ).addEventListener( 'click', async () => {
			if ( ! window.confirm( __( 'Reset to the default rules? Your current rules will be overwritten.', 'foldernest' ) ) ) {
				return;
			}
			try {
				const data = await api( 'auto_reset_rules', {} );
				state.rules = data.rules || [];
				renderRules();
				toast( data.message, 'ok' );
			} catch ( e ) {
				toast( e.message, 'error' );
			}
		} );

		$( '#mn-mm-add-rule' ).addEventListener( 'click', () => {
			state.rules = collectRules();
			state.rules.push( {
				id: '',
				label: __( 'New rule', 'foldernest' ),
				enabled: true,
				only_unassigned: true,
				match: {
					filename_contains: '',
					filename_regex: '',
					ext_in: '',
					mime_group: '',
					min_width: 0,
					max_width: 0,
					year: 0,
				},
				target_folder: '',
				add_tags: [],
			} );
			renderRules();
		} );

		$( '#mn-mm-rules' ).addEventListener( 'click', ( e ) => {
			const card = e.target.closest( '.mn-mm-rule-card' );
			if ( ! card ) {
				return;
			}
			const idx = parseInt( card.dataset.index, 10 );

			if ( e.target.closest( '[data-rule-delete]' ) ) {
				state.rules = collectRules();
				state.rules.splice( idx, 1 );
				renderRules();
				return;
			}

			const move = e.target.closest( '[data-rule-move]' );
			if ( move ) {
				state.rules = collectRules();
				const dir = move.dataset.ruleMove === 'up' ? -1 : 1;
				const target = idx + dir;
				if ( target >= 0 && target < state.rules.length ) {
					const tmp = state.rules[ idx ];
					state.rules[ idx ] = state.rules[ target ];
					state.rules[ target ] = tmp;
					renderRules();
				}
			}
		} );

		/* ---------- 操作記錄 ---------- */
		$( '#mn-mm-log-list' ).addEventListener( 'click', ( e ) => {
			const btn = e.target.closest( '[data-undo]' );
			if ( btn ) {
				undoLog( parseInt( btn.dataset.undo, 10 ) );
			}
		} );

		/* ---------- 對話框關閉 ---------- */
		document.querySelectorAll( '.mn-mm-modal' ).forEach( ( modal ) => {
			modal.addEventListener( 'click', ( e ) => {
				if ( e.target === modal || e.target.closest( '[data-close]' ) ) {
					modal.hidden = true;
				}
			} );
		} );

		document.addEventListener( 'keydown', ( e ) => {
			if ( e.key !== 'Escape' ) {
				return;
			}

			/* 框選進行中 → 先取消框選 */
			if ( holdTimer ) {
				cancelHold();
				return;
			}
			if ( marquee ) {
				marqueeCancel();
				return;
			}

			document.querySelectorAll( '.mn-mm-modal' ).forEach( ( m ) => {
				m.hidden = true;
			} );
		} );

		$( '#mn-mm-progress-cancel' ).addEventListener( 'click', async () => {
			autoCancelled = true;
			try {
				const data = await api( 'auto_cancel', {} );
				progress( false );
				toast( data.message, 'ok' );
				await refresh( true );
			} catch ( e ) {
				toast( e.message, 'error' );
			}
		} );
	}

	
	/* Pro 等附加外掛的擴展點（wp.org 規範：Pro 代碼在 Pro 外掛內，
	 * 透過此 API 與免費版協作）。 */
	window.MN_MM_UI = { state, $, esc, __, sprintf, toast, api, refresh, loadTree, renderTree, renderFoldersBar, currentScope, selectedIds, openModal, closeModal, progress, loadMedia };

/* =========================================================
	 *  啟動
	 * ========================================================= */

	document.addEventListener( 'DOMContentLoaded', () => {
		renderStats( state.stats );
		renderTree();
		renderTags();
		bindEvents();
		loadMedia();
	} );
} )();
