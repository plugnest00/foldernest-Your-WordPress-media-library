<?php
/**
 * PlugNest Media Folders — 上傳時直接指定資料夾。
 *
 * 在「媒體 → 新增媒體」(media-new.php) 的拖放上傳區上方加一個資料夾下拉：
 * 選好資料夾再上傳，檔案就直接歸到該資料夾，不必上傳完再搬一次。
 *
 * 分成兩段：
 *   1. 前端（assets/js/upload.js）：每個檔案上傳前，把選到的資料夾塞進
 *      plupload 的 multipart_params。
 *   2. 後端（本檔的 assign_folder()）：附件建立時（add_attachment）讀取該參數，
 *      把附件指派到對應的資料夾。
 *
 * 與外掛其他部分一致：只動分類關係（wp_term_relationships），
 * 完全不碰實體檔案、不改 guid、不改 _wp_attached_file、不產生任何網址。
 *
 * @package PlugNest Media Folders
 */

defined( 'ABSPATH' ) || exit;

class MN_MM_Upload {

	/** 資料夾欄位名稱（同時是表單欄位與 plupload 參數名）。 */
	const FIELD = 'mn_mm_folder';

	/** nonce 欄位名稱。 */
	const NONCE = 'mn_mm_nonce';

	/** nonce 動作。 */
	const NONCE_ACTION = 'mn_mm_upload';

	/** @var MN_MM_Upload|null */
	private static $instance = null;

	/** @return MN_MM_Upload */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		/*
		 * 插在拖放區「上方」。這個 hook 由核心 media_upload_form() 觸發，
		 * 位置在 <div id="plupload-upload-ui"> 內、<div id="drag-drop-area"> 之前，
		 * 而且整個區塊都在 media-new.php 的 <form id="file-form"> 裡面，
		 * 所以沒有 JS 的環境用表單送出時，這個欄位也會一併帶上。
		 */
		add_action( 'pre-plupload-upload-ui', array( $this, 'render_picker' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'add_attachment', array( $this, 'assign_folder' ) );
	}

	/**
	 * 是否為「媒體 → 新增媒體」頁。
	 *
	 * @return bool
	 */
	private function is_upload_screen() {
		global $pagenow;
		return is_admin() && 'media-new.php' === $pagenow;
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
	 * 載入前端腳本。
	 *
	 * 依賴 plupload-handlers，確保我們在核心的 handlers.js 之後執行
	 * （才接得上它建立的上傳器）。
	 *
	 * @param string $hook 目前頁面的 hook 後綴。
	 */
	public function assets( $hook ) {
		if ( ! $this->is_upload_screen() ) {
			return;
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		wp_enqueue_style(
			'mn-mm-upload',
			MN_MM_URL . 'assets/css/upload.css',
			array(),
			$this->asset_ver( 'assets/css/upload.css' )
		);

		wp_enqueue_script(
			'mn-mm-upload',
			MN_MM_URL . 'assets/js/upload.js',
			array( 'plupload-handlers' ),
			$this->asset_ver( 'assets/js/upload.js' ),
			true
		);
	}

	/**
	 * 輸出資料夾下拉。
	 */
	public function render_picker() {
		if ( ! $this->is_upload_screen() ) {
			return;
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$folders = MN_MM_Taxonomy::get_folder_tree();

		?>
		<div class="mn-mm-upload-picker">
			<label for="mn-mm-upload-folder"><?php esc_html_e( 'Upload to folder:', 'plugnest-media-folders' ); ?></label>
			<select id="mn-mm-upload-folder" name="<?php echo esc_attr( self::FIELD ); ?>">
				<option value=""><?php esc_html_e( '(none — classify later)', 'plugnest-media-folders' ); ?></option>
				<?php foreach ( $folders as $folder ) : ?>
					<option value="<?php echo (int) $folder['term_id']; ?>"><?php
						// 縮排用 &nbsp; 呈現層級，與原生媒體庫的篩選下拉一致。
						echo str_repeat( '&nbsp;&nbsp;&nbsp;', (int) $folder['depth'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo esc_html( $folder['name'] );
					?></option>
				<?php endforeach; ?>
			</select>
			<input
				type="hidden"
				id="mn-mm-upload-nonce"
				name="<?php echo esc_attr( self::NONCE ); ?>"
				value="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>"
			/>
			<p class="description">
				<?php esc_html_e( 'Pick a folder before uploading and files land in it directly. Classification only — file paths and URLs never change.', 'plugnest-media-folders' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * 附件建立時，依上傳時選的資料夾歸類。
	 *
	 * 這裡刻意不拋錯、不中斷上傳：任何一步對不上就安靜跳過，
	 * 檔案照樣上傳成功，只是留在「未分類」。
	 *
	 * @param int $attachment_id 剛建立的附件 ID。
	 */
	public function assign_folder( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- 下面會自己驗 nonce。
		if ( empty( $_REQUEST[ self::FIELD ] ) ) {
			return;
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$nonce = isset( $_REQUEST[ self::NONCE ] )
			? sanitize_text_field( wp_unslash( $_REQUEST[ self::NONCE ] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		$folder_id = absint( $_REQUEST[ self::FIELD ] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $folder_id <= 0 ) {
			return;
		}

		/* 只接受本外掛分類法底下真的存在的資料夾。 */
		$term = get_term( $folder_id, MN_MM_TAX_FOLDER );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}

		/*
		 * 新附件本來就沒有任何資料夾，用 false（取代）語意最清楚。
		 * 這裡會連帶觸發自訂的 update_count_callback，左側數字立刻是對的。
		 */
		wp_set_object_terms( $attachment_id, array( $folder_id ), MN_MM_TAX_FOLDER, false );
	}
}
