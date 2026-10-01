<?php
/**
 * FolderNest — 主控制器。
 *
 * @package FolderNest
 */

defined( 'ABSPATH' ) || exit;

class MN_MM_Plugin {

	/** @var MN_MM_Plugin|null */
	private static $instance = null;

	/** @return MN_MM_Plugin */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		/* 分類法必須早於一切註冊（原生媒體庫的篩選依賴它）。 */
		add_action( 'init', array( 'MN_MM_Taxonomy', 'register' ), 5 );

		/* 新上傳的附件立即記錄檔案尺寸（尺寸排序索引）。 */
		add_action( 'add_attachment', array( __CLASS__, 'store_filesize' ) );

		/* 模組：AJAX 與原生整合在後台與 admin-ajax 都要載入。 */
		MN_MM_Ajax::instance();
		MN_MM_Native::instance();

		/*
		 * 上傳時指定資料夾。
		 *
		 * 刻意放在 is_admin() 外面：它掛的 add_attachment 是「資料寫入」hook，
		 * 只要有附件建立就該在場（三個 hook 自己都會判斷頁面與權限）。
		 * 放進 is_admin() 的話，非後台情境（含 CLI 腳本）就完全不會註冊。
		 */
		MN_MM_Upload::instance();

		if ( is_admin() ) {
			MN_MM_Admin::instance();

			/*
			 * 版本升級後重算一次資料夾／標籤數量。
			 * 1.0.1 以前用的是核心的計數規則（漏算未掛在任何文章下的媒體），
			 * 資料庫裡存的是偏低的數字，需要一次修正。
			 */
			add_action( 'admin_init', array( 'MN_MM_Taxonomy', 'maybe_recount' ), 20 );

			/* 舊版資料搬移（冪等；已遷移時只是一次 get_option）。 */
			add_action( 'admin_init', array( __CLASS__, 'migrate_from_tkm' ), 5 );
		}
	}

	/**
	 * 啟用外掛。
	 *
	 * 注意：這裡「只」建立資料表、分類法與預設資料夾，
	 * 完全不觸碰任何媒體檔案。
	 */
	public static function activate() {
		/* 舊版（TKM Media Manager）資料先行搬移，順序在建立資料表之前。 */
		self::migrate_from_tkm();

		self::create_tables();

		/* 立即註冊分類法，否則 flush 時 WP 還不認識它。 */
		MN_MM_Taxonomy::register();

		flush_rewrite_rules();

		update_option( 'mn_mm_version', MN_MM_VERSION );
	}

	/**
	 * 從舊版 TKM Media Manager 搬移資料（一次性，冪等）。
	 *
	 * 改名外掛不改資料：taxonomy slug、資料表、option／meta 鍵全部
	 * 原地改名，分類關係、操作記錄、使用狀態快取原封不動。
	 * 舊外掛資料夾（tkm-media-manager）停用後啟用本外掛即觸發。
	 *
	 * @return void
	 */
	public static function migrate_from_tkm() {
		global $wpdb;

		if ( get_option( 'mn_mm_migrated_from_tkm' ) ) {
			return;
		}

		/* ---------- 1. 分類法改名（term_relationships 不動，只改 term_taxonomy.taxonomy） ---------- */
		foreach (
			array(
				'tkm_media_folder' => 'mn_media_folder',
				'tkm_media_tag'    => 'mn_media_tag',
			) as $old_tax => $new_tax
		) {
			$exists = $wpdb->get_var(
				$wpdb->prepare( "SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s LIMIT 1", $old_tax )
			);
			if ( $exists ) {
				$wpdb->update(
					$wpdb->term_taxonomy,
					array( 'taxonomy' => $new_tax ),
					array( 'taxonomy' => $old_tax )
				);
				clean_taxonomy_cache( $new_tax );
			}
		}

		/* ---------- 2. 操作記錄資料表 RENAME ---------- */
		$old_table = $wpdb->prefix . 'tkm_media_log';
		$new_table = $wpdb->prefix . MN_MM_TBL_LOG;
		if (
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) ) === $old_table
			&& $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_table ) ) !== $new_table
		) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "RENAME TABLE `{$old_table}` TO `{$new_table}`" );
		}

		/* ---------- 3. postmeta 鍵（使用狀態快取） ---------- */
		foreach (
			array(
				'_tkm_mm_usage'    => '_mn_mm_usage',
				'_tkm_mm_usage_at' => '_mn_mm_usage_at',
			) as $old_key => $new_key
		) {
			$wpdb->update(
				$wpdb->postmeta,
				array( 'meta_key' => $new_key ),
				array( 'meta_key' => $old_key )
			);
		}

		/* ---------- 4. termmeta 鍵（資料夾角色） ---------- */
		$wpdb->update(
			$wpdb->termmeta,
			array( 'meta_key' => 'mn_mm_roles' ),
			array( 'meta_key' => 'tkm_mm_roles' )
		);

		/* ---------- 5. option 鍵搬移（舊值存在且新鍵未設才搬） ---------- */
		foreach (
			array(
				'tkm_mm_version'          => 'mn_mm_version',
				'tkm_mm_auto_rules'       => 'mn_mm_auto_rules',
				'tkm_mm_notice_dismissed' => 'mn_mm_notice_dismissed',
			) as $old_opt => $new_opt
		) {
			$old_value = get_option( $old_opt );
			if ( null !== $old_value && false === get_option( $new_opt ) ) {
				update_option( $new_opt, $old_value );
			}
			if ( null !== $old_value ) {
				delete_option( $old_opt );
			}
		}

		update_option( 'mn_mm_migrated_from_tkm', 1, false );
	}

	/**
	 * 停用外掛。
	 *
	 * 刻意「不」刪除任何分類資料：分類關係是使用者的心血，
	 * 停用後再啟用應該原封不動。清除資料請用 uninstall.php。
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * 建立操作記錄資料表。
	 */
	public static function create_tables() {
		global $wpdb;

		$table   = $wpdb->prefix . MN_MM_TBL_LOG;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			action VARCHAR(40) NOT NULL DEFAULT '',
			object_count INT UNSIGNED NOT NULL DEFAULT 0,
			payload LONGTEXT NULL,
			undone TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY action (action),
			KEY undone (undone)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * 資料表名稱。
	 *
	 * @return string
	 */
	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . MN_MM_TBL_LOG;
	}

	/**
	 * Pro 是否啟用。
	 *
	 * 免費版本身永遠回 false；Pro 外掛（FolderNest Pro）在授權
	 * 有效時掛 `mn_mm_pro` 過濾器回 true 解鎖。收費功能（清理精靈、
	 * 待補 Alt 批次、匯入/匯出、資料夾角色）在後端端點與前端 UI 兩側
	 * 都用這個函式把關。
	 *
	 * @return bool
	 */
	public static function is_pro() {
		/**
		 * Filters whether the Pro add-on is active.
		 *
		 * @param bool $pro True when a valid Pro license is active.
		 */
		return (bool) apply_filters( 'mn_mm_pro', false );
	}

	/**
	 * 附件建立時記錄檔案尺寸（bytes，尺寸排序索引用）。
	 *
	 * @param int $attachment_id
	 */
	public static function store_filesize( $attachment_id ) {
		$file = get_attached_file( (int) $attachment_id );
		if ( $file && file_exists( $file ) ) {
			update_post_meta( (int) $attachment_id, '_mn_mm_filesize', (int) wp_filesize( $file ) );
		}
	}
}
