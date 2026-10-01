<?php
/**
 * FolderNest — 分類法註冊與預設資料夾。
 *
 * 這是整個外掛的核心機制，也是「不動物件路徑」的關鍵：
 *
 * WordPress 的媒體（attachment）本身沒有分類功能，但 WordPress 的分類法系統
 * （register_taxonomy）可以掛到任何 post type 上，包含 attachment。
 * 分類關係儲存在 wp_term_relationships / wp_term_taxonomy 兩張表，
 * 與檔案本身（wp_posts.guid、_wp_attached_file、wp-content/uploads/… 實體路徑）
 * 完全無關。
 *
 * 因此本外掛：
 *   - 只寫入分類關係與 postmeta
 *   - 從不呼叫 wp_upload_dir 去改寫路徑、從不移動檔案、從不更新 guid / _wp_attached_file
 *   - rewrite => false，連新的 URL 規則都不會產生
 *
 * @package FolderNest
 */

defined( 'ABSPATH' ) || exit;

class MN_MM_Taxonomy {

	/**
	 * 計入「媒體數量」的附件狀態。
	 *
	 * 必須與 MN_MM_Query::get_media() / MN_MM_Usage::total() 用的清單一致，
	 * 否則左側資料夾的數字會跟點下去之後網格顯示的「共 N 個媒體」對不上。
	 *
	 * @return string[]
	 */
	public static function attachment_statuses() {
		return array( 'inherit', 'private', 'publish' );
	}

	/**
	 * 把狀態清單組成 SQL 的 IN 片段。
	 *
	 * 內容是寫死的常數，不含任何外部輸入。
	 *
	 * @return string
	 */
	private static function status_in_sql() {
		return "'" . implode( "', '", self::attachment_statuses() ) . "'";
	}

	/**
	 * 註冊兩個分類法。
	 *
	 * 掛在 init，且必須每次都註冊（不能只在啟用時註冊），
	 * 否則後台的分類介面、篩選、以及 term 查詢都會失效。
	 */
	public static function register() {
		$caps = array(
			'manage_terms' => 'upload_files',
			'edit_terms'   => 'upload_files',
			'delete_terms' => 'upload_files',
			'assign_terms' => 'upload_files',
		);

		/* ---------- 資料夾（階層式，可無限層） ---------- */
		register_taxonomy(
			MN_MM_TAX_FOLDER,
			array( 'attachment' ),
				array(
					'labels'             => array(
						'name'          => __( 'Media folders', 'foldernest' ),
						'singular_name' => __( 'Media folder', 'foldernest' ),
						'search_items'  => __( 'Search folders', 'foldernest' ),
						'all_items'     => __( 'All folders', 'foldernest' ),
						'parent_item'   => __( 'Parent folder', 'foldernest' ),
						'edit_item'     => __( 'Edit folder', 'foldernest' ),
						'update_item'   => __( 'Update folder', 'foldernest' ),
						'add_new_item'  => __( 'Add new folder', 'foldernest' ),
						'new_item_name' => __( 'New folder name', 'foldernest' ),
						'menu_name'     => __( 'Folders', 'foldernest' ),
					),
				'hierarchical'       => true,
				/*
				 * public => false + show_ui => true 是刻意的組合：
				 * 前台不會產生任何 term archive、不佔用 URL、不影響 SEO，
				 * 但後台（含原生媒體庫）可以正常使用這個分類。
				 */
				'public'             => false,
				'show_ui'            => true,
				'show_in_menu'       => false,
				'show_admin_column'  => true,
				'show_in_rest'       => true,
				'show_in_quick_edit' => false,
				'rewrite'            => false,
				'query_var'          => false,
				'capabilities'       => $caps,
				/*
				 * 覆寫核心的預設計數規則，見 update_term_count() 的說明。
				 * 不覆寫的話，未掛在任何文章下的媒體（post_parent = 0）永遠不會被算進 count。
				 */
				'update_count_callback' => array( __CLASS__, 'update_term_count' ),
			)
		);

		/* ---------- 標籤（扁平，可多重指派） ---------- */
		register_taxonomy(
			MN_MM_TAX_TAG,
			array( 'attachment' ),
				array(
					'labels'             => array(
						'name'          => __( 'Media tags', 'foldernest' ),
						'singular_name' => __( 'Media tag', 'foldernest' ),
						'search_items'  => __( 'Search tags', 'foldernest' ),
						'all_items'     => __( 'All tags', 'foldernest' ),
						'edit_item'     => __( 'Edit tag', 'foldernest' ),
						'update_item'   => __( 'Update tag', 'foldernest' ),
						'add_new_item'  => __( 'Add new tag', 'foldernest' ),
						'new_item_name' => __( 'New tag name', 'foldernest' ),
						'menu_name'     => __( 'Tags', 'foldernest' ),
					),
				'hierarchical'       => false,
				'public'             => false,
				'show_ui'            => true,
				'show_in_menu'       => false,
				'show_admin_column'  => true,
				'show_in_rest'       => true,
				'show_in_quick_edit' => false,
				'rewrite'            => false,
				'query_var'          => false,
				'capabilities'       => $caps,
				'update_count_callback' => array( __CLASS__, 'update_term_count' ),
			)
		);
	}

	/**
	 * 自訂的術語計數 callback（取代核心的 _update_post_term_count）。
	 *
	 * 為什麼要自己寫：
	 *
	 * WordPress 對 attachment 的預設計數 SQL 是
	 *   post_status IN ('publish')
	 *   OR ( post_status = 'inherit' AND post_parent > 0
	 *        AND (父文章的 post_status) IN ('publish') )
	 * 也就是「只有父文章已發佈的媒體才算數」。
	 *
	 * 本站有近半數媒體是直接上傳到媒體庫、沒有掛在任何文章下（post_parent = 0），
	 * 這些檔案一律不被計數，於是資料夾數量長期偏低；而且搬動這類檔案時
	 * 重算出來的數字仍然是 0，看起來就像「數量根本沒更新」。
	 *
	 * 這裡改成：只要是這個分類法底下、狀態有效的附件，一律計數。
	 *
	 * @param int[]              $terms    term_taxonomy_id 陣列。
	 * @param WP_Taxonomy|string $taxonomy 分類法（僅為符合 callback 簽名）。
	 */
	public static function update_term_count( $terms, $taxonomy = null ) {
		global $wpdb;

		unset( $taxonomy );

		$tt_ids = array_values( array_unique( array_map( 'intval', (array) $terms ) ) );
		if ( empty( $tt_ids ) ) {
			return;
		}

		$in_tt     = implode( ',', $tt_ids );
		$in_status = self::status_in_sql();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			"SELECT tr.term_taxonomy_id AS tt_id, COUNT( DISTINCT tr.object_id ) AS c
			   FROM {$wpdb->term_relationships} tr
			   INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
			  WHERE tr.term_taxonomy_id IN ({$in_tt})
			    AND p.post_type = 'attachment'
			    AND p.post_status IN ({$in_status})
			  GROUP BY tr.term_taxonomy_id",
			ARRAY_A
		);

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row['tt_id'] ] = (int) $row['c'];
		}

		foreach ( $tt_ids as $tt_id ) {
			$wpdb->update(
				$wpdb->term_taxonomy,
				array( 'count' => isset( $counts[ $tt_id ] ) ? $counts[ $tt_id ] : 0 ),
				array( 'term_taxonomy_id' => $tt_id )
			);
		}

		/*
		 * 傳空字串代表 $tt_ids 是 term_taxonomy_id（與核心 wp_update_term_count_now() 一致）。
		 * 這個呼叫同時會更新 terms 群的 last_changed，讓 get_terms() 的查詢快取失效。
		 */
		clean_term_cache( $tt_ids, '', false );
	}

	/**
	 * 取得資料夾樹（扁平化為帶 depth 的陣列，方便前端渲染）。
	 *
	 * @param bool $with_count 是否附帶每個資料夾的媒體數量。
	 * @return array
	 */
	public static function get_folder_tree( $with_count = true ) {
		$terms = get_terms(
			array(
				'taxonomy'   => MN_MM_TAX_FOLDER,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$counts = array();
		if ( $with_count ) {
			$counts = self::count_attachments( $terms );
		}

		/* 依 parent 分組後深度優先展開 */
		$by_parent = array();
		foreach ( $terms as $t ) {
			$by_parent[ (int) $t->parent ][] = $t;
		}

		$out = array();
		self::flatten( $by_parent, 0, 0, $counts, $out );
		return $out;
	}

	/**
	 * 即時計算每個資料夾的媒體數量（含子孫資料夾，且不重複計算）。
	 *
	 * 不直接信任 wp_term_taxonomy.count，理由有二：
	 *   1. 舊資料庫裡存的是依核心規則（只算父文章已發佈的媒體）算出來的錯誤數字；
	 *   2. 就算修好了 callback，第三方外掛或直接 SQL 寫入也可能讓 count 走鐘。
	 * 這裡一律以 term_relationships × wp_posts 的實際筆數為準，數字永遠不會過期。
	 *
	 * 為什麼是「子樹去重後的不同附件數」而不是「逐層相加」：
	 * 一個媒體可以同時被指派到多個資料夾（例如同時放在父層與子層），
	 * 逐層相加會把它算兩次；而前端點資料夾時用的是 tax_query IN + GROUP BY posts.ID，
	 * 只會算一次。兩邊必須一致，樹上的數字才會等於網格顯示的「共 N 個媒體」。
	 *
	 * @param array $terms get_terms() 回傳的 term 物件陣列。
	 * @return array term_id => 數量（子樹去重）。
	 */
	private static function count_attachments( $terms ) {
		global $wpdb;

		$parent_of = array();
		$own       = array();
		$tt_ids    = array();

		foreach ( $terms as $t ) {
			$tid               = (int) $t->term_id;
			$tt_ids[]          = (int) $t->term_taxonomy_id;
			$parent_of[ $tid ] = (int) $t->parent;
			$own[ $tid ]       = array();
		}

		if ( $tt_ids ) {
			$in_tt     = implode( ',', array_unique( $tt_ids ) );
			$in_status = self::status_in_sql();

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				"SELECT tt.term_id AS term_id, tr.object_id AS object_id
				   FROM {$wpdb->term_relationships} tr
				   INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				   INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
				  WHERE tr.term_taxonomy_id IN ({$in_tt})
				    AND p.post_type = 'attachment'
				    AND p.post_status IN ({$in_status})",
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$tid = (int) $row['term_id'];
				if ( isset( $own[ $tid ] ) ) {
					// 用 object_id 當 key，天然去重。
					$own[ $tid ][ (int) $row['object_id'] ] = true;
				}
			}
		}

		/* 子 → 父 分組。 */
		$children = array();
		foreach ( $terms as $t ) {
			$tid = (int) $t->term_id;
			$children[ $parent_of[ $tid ] ][] = $tid;
		}

		$done   = array();
		$counts = array();
		foreach ( $terms as $t ) {
			$tid            = (int) $t->term_id;
			$set            = self::subtree_objects( $tid, $children, $own, $done );
			$counts[ $tid ] = count( $set );
		}

		return $counts;
	}

	/**
	 * 取得某個資料夾「自己 + 所有子孫」的媒體 ID 集合（去重）。
	 *
	 * 就地快取在 $own 裡，同一棵子樹只算一次。
	 *
	 * @param int   $tid      資料夾 term_id。
	 * @param array $children parent => [child, ...]。
	 * @param array $own      term_id => [object_id => true]，會被就地改寫成子樹集合。
	 * @param array $done     已算過的 term_id。
	 * @param int   $depth    遞迴深度（防止異常資料造成無限遞迴）。
	 * @return array object_id => true
	 */
	private static function subtree_objects( $tid, $children, &$own, &$done, $depth = 0 ) {
		if ( isset( $done[ $tid ] ) ) {
			return $own[ $tid ];
		}

		$set = isset( $own[ $tid ] ) ? $own[ $tid ] : array();

		if ( $depth < 60 && ! empty( $children[ $tid ] ) ) {
			foreach ( $children[ $tid ] as $child ) {
				// 陣列聯集（key 為 object_id），重複的媒體只會留一份。
				$set = $set + self::subtree_objects( $child, $children, $own, $done, $depth + 1 );
			}
		}

		$own[ $tid ]  = $set;
		$done[ $tid ] = true;

		return $set;
	}

	/**
	 * 重算所有資料夾（與標籤）的 count 欄位。
	 *
	 * 用途：把舊版依核心規則算出來的錯誤數字一次修正回來。
	 *
	 * @return int 重算的資料夾數。
	 */
	public static function recount_all() {
		$n = 0;

		foreach ( array( MN_MM_TAX_FOLDER, MN_MM_TAX_TAG ) as $tax ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $tax,
					'hide_empty' => false,
				)
			);

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			$tt_ids = array();
			foreach ( $terms as $t ) {
				$tt_ids[] = (int) $t->term_taxonomy_id;
			}

			self::update_term_count( $tt_ids );

			if ( MN_MM_TAX_FOLDER === $tax ) {
				$n = count( $tt_ids );
			}
		}

		return $n;
	}

	/**
	 * 版本有變動時自動重算一次。
	 *
	 * 掛在 admin_init：分類法要到 init（優先度 5）才註冊，admin_init 在其後。
	 * 只在版本字串不同時執行，所以平常每個請求只多一次 get_option()。
	 */
	public static function maybe_recount() {
		if ( get_option( 'mn_mm_version' ) === MN_MM_VERSION ) {
			return;
		}

		self::recount_all();

		update_option( 'mn_mm_version', MN_MM_VERSION );
	}

	/**
	 * 遞迴展開資料夾樹。
	 */
	private static function flatten( $by_parent, $parent, $depth, $counts, &$out ) {
		if ( empty( $by_parent[ $parent ] ) ) {
			return;
		}
		foreach ( $by_parent[ $parent ] as $t ) {
			$roles_raw = get_term_meta( $t->term_id, 'mn_mm_roles', true );
			$out[]     = array(
				'term_id' => (int) $t->term_id,
				'name'    => $t->name,
				'slug'    => $t->slug,
				'parent'  => (int) $t->parent,
				'depth'   => $depth,
				'count'   => isset( $counts[ $t->term_id ] ) ? $counts[ $t->term_id ] : 0,
				'color'   => (string) get_term_meta( $t->term_id, 'mn_mm_color', true ),
				'star'    => (int) get_term_meta( $t->term_id, 'mn_mm_star', true ),
				'roles'   => is_array( $roles_raw ) ? array_values( array_map( 'strval', $roles_raw ) ) : array(),
			);
			self::flatten( $by_parent, (int) $t->term_id, $depth + 1, $counts, $out );
		}
	}

	/**
	 * 取得某個資料夾的所有子孫 term_id（含自己）。
	 *
	 * 用途：點上層資料夾時，要連子資料夾的媒體一起顯示。
	 *
	 * @param int $term_id
	 * @return int[]
	 */
	public static function get_descendant_ids( $term_id ) {
		$term_id = (int) $term_id;
		if ( $term_id <= 0 ) {
			return array();
		}

		$ids  = array( $term_id );
		$kids = get_term_children( $term_id, MN_MM_TAX_FOLDER );
		if ( ! is_wp_error( $kids ) ) {
			$ids = array_merge( $ids, array_map( 'intval', $kids ) );
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * 資料夾角色限制：對目前使用者「不可見」的資料夾 ID（含子孫）。
	 *
	 * 規則：資料夾的 term meta `mn_mm_roles` 有設角色時，只有具備其中
	 * 任何一個角色的使用者能看見；沒設＝所有人可見。
	 * 管理員（manage_options）一律全看。
	 *
	 * @return int[]
	 */
	public static function restricted_folder_ids_for_user() {
		/* 請求級快取：同一個請求裡（load_media／tree／get_ids…）會呼叫多次，
		 * 每次都要 get_terms＋逐 term 讀 meta，快取後只跑一次。 */
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		$user = wp_get_current_user();
		if ( $user && current_user_can( 'manage_options' ) ) {
			$cache = array();
			return $cache;
		}

		$user_roles = $user ? (array) $user->roles : array();
		$terms      = get_terms(
			array(
				'taxonomy'   => MN_MM_TAX_FOLDER,
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$hidden = array();
		foreach ( $terms as $t ) {
			$roles = get_term_meta( $t->term_id, 'mn_mm_roles', true );
			if ( empty( $roles ) || ! is_array( $roles ) ) {
				continue;
			}
			if ( ! array_intersect( $roles, $user_roles ) ) {
				$hidden = array_merge( $hidden, self::get_descendant_ids( (int) $t->term_id ) );
			}
		}

		$cache = array_values( array_unique( array_map( 'intval', $hidden ) ) );
		return $cache;
	}

	/**
	 * 全站可用角色清單（slug => 顯示名稱）。
	 *
	 * @return array
	 */
	public static function get_role_names() {
		$roles = wp_roles();
		return $roles ? $roles->get_names() : array();
	}
}
