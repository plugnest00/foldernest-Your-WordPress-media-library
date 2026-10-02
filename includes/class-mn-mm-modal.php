<?php
/**
 * PlugNest Media Folders — 媒體框（wp.media）整合。
 *
 * Elementor、古騰堡等「插入媒體」用的都是核心媒體框（wp.media）。
 * 在不改寫框本體的前提下，用 Backbone 擴充加上兩個功能：
 *   1. 「上傳檔案」頁籤：上傳到資料夾 下拉 —— 上傳前就指定資料夾，
 *      與「媒體 → 新增媒體」頁的上傳下拉同一套後端邏輯。
 *   2. 「插入媒體」瀏覽頁籤：資料夾篩選下拉 —— 只看某個資料夾（含子資料夾）的媒體。
 *
 * 後端只有兩件事：
 *   - ajax_query_attachments_args：讓 query-attachments 認得 mn_mm_folder 參數
 *   - 上傳歸類沿用 MN_MM_Upload::assign_folder()（multipart 帶 mn_mm_folder + nonce）
 *
 * 與外掛其他部分一致：只動分類關係，完全不碰檔案與網址。
 *
 * @package PlugNest Media Folders
 */

defined( 'ABSPATH' ) || exit;

class MN_MM_Modal {

	/** @var MN_MM_Modal|null */
	private static $instance = null;

	/** @return MN_MM_Modal */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		/*
		 * Elementor 編輯器頁（post.php?action=elementor）會 remove_all_actions()
		 * 重設腳本印出管線、走自己的印出流程，admin_enqueue_scripts 的入隊
		 * 不會被印出 —— 必須改掛 Elementor 官方的編輯器入隊鉤子才會載入。
		 */
		add_action( 'elementor/editor/before_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'ajax_query_attachments_args', array( $this, 'filter_query' ) );
	}

	/**
	 * 資源版本號（以檔案修改時間為準，覆蓋檔案後瀏覽器快取自動失效）。
	 *
	 * @param string $relative 相對於外掛目錄的檔案路徑。
	 * @return string
	 */
	private function asset_ver( $relative ) {
		$file = MN_MM_PATH . $relative;

		if ( is_readable( $file ) ) {
			$mtime = filemtime( $file );
			if ( $mtime ) {
				return MN_MM_VERSION . '.' . $mtime;
			}
		}

		return MN_MM_VERSION;
	}

	/**
	 * 載入資源。
	 *
	 * 媒體框可能出現在任何後台頁面（文章編輯、Elementor、小工具、自訂器、
	 * upload.php 網格模式……），所以這裡不加頁面判斷，全部後台頁都帶上；
	 * JS 本身會在沒有 wp.media 的環境自動閉嘴，CSS 只有幾行。
	 */
	public function assets() {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		wp_enqueue_style(
			'mn-mm-modal',
			MN_MM_URL . 'assets/css/modal.css',
			array(),
			$this->asset_ver( 'assets/css/modal.css' )
		);

		wp_enqueue_script(
			'mn-mm-modal',
			MN_MM_URL . 'assets/js/modal.js',
			array( 'media-editor' ),
			$this->asset_ver( 'assets/js/modal.js' ),
			true
		);

		wp_localize_script(
			'mn-mm-modal',
			'MN_MM_MODAL',
			array(
				'folders' => MN_MM_Taxonomy::get_folder_tree( true ),
				'field'   => MN_MM_Upload::FIELD,
				'nonce'   => wp_create_nonce( MN_MM_Upload::NONCE_ACTION ),
				'strings' => array(
					'uploadTo'    => __( 'Upload to folder:', 'plugnest-media-folders' ),
					'unspecified' => __( '(none — classify later)', 'plugnest-media-folders' ),
					'filterBy'    => __( 'Folder:', 'plugnest-media-folders' ),
					'all'         => __( 'All folders', 'plugnest-media-folders' ),
					'unassigned'  => __( 'Unassigned', 'plugnest-media-folders' ),
				),
			)
		);
	}

	/**
	 * 讓 query-attachments（媒體框的瀏覽查詢）支援資料夾篩選。
	 *
	 * 資料夾參數由前端 ajaxPrefilter 掛在請求「頂層」（mn_mm_folder），
	 * 因為核心 wp_ajax_query_attachments 會用白名單（array_intersect_key）
	 * 把 query 物件裡的自訂鍵在過濾器執行前就剝掉。
	 *
	 * 兼容另一種傳法：參數放在 query 物件內（$args['mn_mm_folder']）。
	 *
	 * @param array $args query-attachments 的查詢參數。
	 * @return array
	 */
	public function filter_query( $args ) {
		$folder = '';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 唯讀篩選參數，核心本端點亦無 nonce。
		if ( isset( $_REQUEST['mn_mm_folder'] ) ) {
			$folder = sanitize_text_field( wp_unslash( $_REQUEST['mn_mm_folder'] ) );
		} elseif ( isset( $args['mn_mm_folder'] ) ) {
			$folder = sanitize_text_field( (string) $args['mn_mm_folder'] );
			unset( $args['mn_mm_folder'] );
		}
		// phpcs:enable

		if ( '' === $folder ) {
			return $args;
		}

		if ( ! isset( $args['tax_query'] ) || ! is_array( $args['tax_query'] ) ) {
			$args['tax_query'] = array();
		}

		if ( 'none' === $folder ) {
			$args['tax_query'][] = array(
				'taxonomy' => MN_MM_TAX_FOLDER,
				'operator' => 'NOT EXISTS',
			);
			return $args;
		}

		$folder_id = (int) $folder;
		if ( $folder_id <= 0 ) {
			return $args;
		}

		$ids = MN_MM_Taxonomy::get_descendant_ids( $folder_id );
		if ( empty( $ids ) ) {
			$ids = array( $folder_id );
		}

		$args['tax_query'][] = array(
			'taxonomy' => MN_MM_TAX_FOLDER,
			'field'    => 'term_id',
			'terms'    => $ids,
			'operator' => 'IN',
		);

		return $args;
	}
}
