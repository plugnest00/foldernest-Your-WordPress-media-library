/**
 * PlugNest Media Folders — 上傳時指定資料夾（前端）。
 *
 * 「媒體 → 新增媒體」(media-new.php) 用的是核心的 plupload：
 * wp-includes/js/plupload/handlers.js 在 DOM ready 時呼叫全域的 uploader_init()，
 * 建立 uploader 後存進全域變數 uploader。
 *
 * 為什麼不直接補 plupload 的 prototype：
 *   plupload.Uploader.prototype 是與 EventTarget 共用的同一個物件
 *   （plupload.js: plupload.Uploader.prototype = o.EventTarget.instance），
 *   亂補會影響到頁面上所有上傳器。所以改成包一層 uploader_init()。
 *
 * BeforeUpload 是 plupload 官方支援的時機（plupload.js 在觸發 UploadFile 之前
 * 先觸發 BeforeUpload），在裡面改 up.settings.multipart_params 最安全。
 */
( function () {
	'use strict';

	var FIELD = 'mn_mm_folder';
	var NONCE = 'mn_mm_nonce';

	/**
	 * 目前選到的資料夾（空字串＝不指定）。
	 */
	function currentFolder() {
		var sel = document.getElementById( 'mn-mm-upload-folder' );
		return sel ? sel.value : '';
	}

	/**
	 * 表單上的 nonce 值。
	 */
	function currentNonce() {
		var el = document.getElementById( 'mn-mm-upload-nonce' );
		return el ? el.value : '';
	}

	/**
	 * 把選擇寫進「這一次」上傳的參數裡。
	 *
	 * @param {Object} up plupload 上傳器實例。
	 */
	function applyParams( up ) {
		if ( ! up || ! up.settings ) {
			return;
		}
		if ( ! up.settings.multipart_params ) {
			up.settings.multipart_params = {};
		}

		var folder = currentFolder();

		if ( folder ) {
			up.settings.multipart_params[ FIELD ] = folder;
			up.settings.multipart_params[ NONCE ] = currentNonce();
		} else {
			delete up.settings.multipart_params[ FIELD ];
			delete up.settings.multipart_params[ NONCE ];
		}
	}

	/**
	 * 掛上 BeforeUpload。
	 *
	 * @param {Object} uploader plupload 上傳器實例。
	 */
	function attach( uploader ) {
		if ( ! uploader || typeof uploader.bind !== 'function' ) {
			return;
		}
		if ( uploader.mnMmBound ) {
			return;
		}
		uploader.mnMmBound = true;

		uploader.bind( 'BeforeUpload', function ( up ) {
			applyParams( up );
		} );
	}

	/*
	 * 主要路徑：我們的腳本在核心 handlers.js 之後、DOM ready 之前執行，
	 * 所以包一層 uploader_init()，等它把 uploader 建好就掛上事件。
	 */
	var originalInit = window.uploader_init;

	if ( typeof originalInit === 'function' ) {
		window.uploader_init = function () {
			var result = originalInit.apply( this, arguments );
			attach( window.uploader );
			return result;
		};
	}

	/* 備援：若上面的包裝沒趕上（載入順序不同），直接讀全域 uploader。 */
	function attachFromGlobal() {
		attach( window.uploader );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', attachFromGlobal );
	} else {
		attachFromGlobal();
	}

	window.addEventListener( 'load', attachFromGlobal );
} )();
