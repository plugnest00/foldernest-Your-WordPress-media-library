<?php
/**
 * FolderNest — AJAX 端點。
 *
 * 所有寫入操作都在這裡集中處理，統一做權限檢查與 nonce 驗證，
 * 並且每一次寫入都會留下可還原的操作記錄。
 *
 * @package FolderNest
 */

defined( 'ABSPATH' ) || exit;

class MN_MM_Ajax {

	/** @var MN_MM_Ajax|null */
	private static $instance = null;

	/** 單次批次操作允許的最大媒體數。 */
	const MAX_BULK = 2000;

	/** @return MN_MM_Ajax */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$map = array(
			'load_media'       => 'load_media',
			'get_item'         => 'get_item',
			'save_item'        => 'save_item',
			'bulk_folder'      => 'bulk_folder',
			'bulk_tag'         => 'bulk_tag',
			'bulk_edit'        => 'bulk_edit',
			'media_delete'     => 'media_delete',
			'post_media'       => 'post_media',
			'folder_color'     => 'folder_color',
			'folder_star'      => 'folder_star',
			'filesize_build'   => 'filesize_build',
			'folder_create'    => 'folder_create',
			'folder_rename'    => 'folder_rename',
			'folder_delete'    => 'folder_delete',
			'folder_move'      => 'folder_move',
			'tag_create'       => 'tag_create',
			'tag_delete'       => 'tag_delete',
			'auto_preview'     => 'auto_preview',
			'auto_start'       => 'auto_start',
			'auto_step'        => 'auto_step',
			'auto_cancel'      => 'auto_cancel',
			'auto_save_rules'  => 'auto_save_rules',
			'auto_reset_rules' => 'auto_reset_rules',
			'usage_scan'       => 'usage_scan',
			'usage_flush'      => 'usage_flush',
			'log_list'         => 'log_list',
			'log_undo'         => 'log_undo',
			'stats'            => 'stats',
			'tree'             => 'tree',
		);

		foreach ( $map as $action => $method ) {
			add_action( 'wp_ajax_mn_mm_' . $action, array( $this, $method ) );
		}
	}

	/**
	 * 權限與 nonce 檢查。通過則回傳，不通過直接結束請求。
	 */
	private function guard() {
		if ( ! check_ajax_referer( 'mn_mm', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please reload the page.', 'foldernest' ) ), 403 );
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'Your account does not have permission to manage media.', 'foldernest' ) ), 403 );
		}
	}

	

	/**
	 * 讀取 POST 參數。
	 *
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	private function param( $key, $default = '' ) {
		return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $default; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * 讀取 POST 中的目標媒體 ID。
	 *
	 * 兩種模式：
	 *   - select_all = 1：不傳 ID，改用目前的篩選條件在後端重新查詢，
	 *     這樣「選取全部符合條件」才能真正涵蓋跨頁的所有媒體。
	 *   - 預設：讀取前端勾選的 ID 清單。
	 *
	 * @return int[]
	 */
	private function param_ids() {
		$ids = array();

		if ( (bool) $this->param( 'select_all', 0 ) ) {
			$ids = MN_MM_Query::get_ids( $this->param_scope() );
		} else {
			$raw = $this->param( 'ids', array() );
			if ( is_string( $raw ) ) {
				$raw = explode( ',', $raw );
			}
			$ids = array_filter( array_map( 'intval', (array) $raw ) );
		}

		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		if ( count( $ids ) > self::MAX_BULK ) {
			$ids = array_slice( $ids, 0, self::MAX_BULK );
		}

		return $ids;
	}

	/**
	 * 大量寫入前放寬執行時間限制，避免批次中途被 PHP 砍掉。
	 */
	private function relax_time_limit() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	/**
	 * 從 POST 組出查詢範圍。
	 *
	 * @return array
	 */
	private function param_scope() {
		$folder = $this->param( 'folder_id', 0 );
		if ( 'none' !== $folder ) {
			$folder = (int) $folder;
		}

		return array(
			'folder_id'        => $folder,
			'include_children' => (bool) $this->param( 'include_children', 1 ),
			'tag_ids'          => array_filter( array_map( 'intval', (array) $this->param( 'tag_ids', array() ) ) ),
			'mime_group'       => sanitize_text_field( (string) $this->param( 'mime_group', '' ) ),
			'year'             => (int) $this->param( 'year', 0 ),
			'month'            => (int) $this->param( 'month', 0 ),
			'usage'            => sanitize_text_field( (string) $this->param( 'usage', '' ) ),
			'alt_missing'      => (bool) $this->param( 'alt_missing', 0 ),
			'search'           => sanitize_text_field( (string) $this->param( 'search', '' ) ),
		);
	}

	/* =========================================================
	 *  讀取
	 * ========================================================= */

	/**
	 * 載入媒體列表。
	 */
	public function load_media() {
		$this->guard();

		$args                = $this->param_scope();
		$args['paged']       = max( 1, (int) $this->param( 'paged', 1 ) );
		$args['per_page']    = max( 1, min( 200, (int) $this->param( 'per_page', 60 ) ) );
		$args['orderby']     = sanitize_text_field( (string) $this->param( 'orderby', 'date' ) );
		$args['order']       = sanitize_text_field( (string) $this->param( 'order', 'DESC' ) );

		$result = MN_MM_Query::get_media( $args );

		wp_send_json_success( $result );
	}

	/**
	 * 取得單一媒體的完整資料。
	 */
	public function get_item() {
		$this->guard();

		$id   = (int) $this->param( 'id', 0 );
		$post = $id ? get_post( $id ) : null;

		if ( ! $post || 'attachment' !== $post->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Media not found.', 'foldernest' ) ), 404 );
		}

		wp_send_json_success( MN_MM_Query::format_item( $post ) );
	}

	/**
	 * 資料夾樹 + 標籤清單。
	 */
	public function tree() {
		$this->guard();

		$tags = get_terms(
			array(
				'taxonomy'   => MN_MM_TAX_TAG,
				'hide_empty' => false,
			)
		);

		$tag_out = array();
		if ( ! is_wp_error( $tags ) ) {
			foreach ( $tags as $t ) {
				$tag_out[] = array(
					'term_id' => (int) $t->term_id,
					'name'    => $t->name,
					'count'   => (int) $t->count,
				);
			}
		}

		$folder_list = MN_MM_Taxonomy::get_folder_tree( true );

		/* 角色限制：非權限使用者看不到受限資料夾（含子孫）。 */
		$restricted = MN_MM_Taxonomy::restricted_folder_ids_for_user();
		if ( ! empty( $restricted ) ) {
			$folder_list = array_values(
				array_filter(
					$folder_list,
					function ( $f ) use ( $restricted ) {
						return ! in_array( (int) $f['term_id'], $restricted, true );
					}
				)
			);
		}

		wp_send_json_success(
			array(
				'folders'      => $folder_list,
				'tags'         => $tag_out,
				'stats'        => MN_MM_Query::stats(),
				'folder_paths' => MN_MM_Auto::get_folder_paths(),
			)
		);
	}

	/**
	 * 統計數字。
	 */
	public function stats() {
		$this->guard();
		wp_send_json_success( MN_MM_Query::stats() );
	}

	/* =========================================================
	 *  單筆編輯
	 * ========================================================= */

	/**
	 * 儲存單一媒體的欄位與分類。
	 */
	public function save_item() {
		$this->guard();

		$id = (int) $this->param( 'id', 0 );
		$post = $id ? get_post( $id ) : null;

		if ( ! $post || 'attachment' !== $post->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Media not found.', 'foldernest' ) ), 404 );
		}

		$before = array(
			'meta'  => array(
				'post_title'   => $post->post_title,
				'post_excerpt' => $post->post_excerpt,
				'post_content' => $post->post_content,
				'_wp_attachment_image_alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			),
			'terms' => array(
				MN_MM_TAX_FOLDER => array_map( 'intval', (array) wp_get_object_terms( $id, MN_MM_TAX_FOLDER, array( 'fields' => 'ids' ) ) ),
				MN_MM_TAX_TAG    => array_map( 'intval', (array) wp_get_object_terms( $id, MN_MM_TAX_TAG, array( 'fields' => 'ids' ) ) ),
			),
		);

		$title   = sanitize_text_field( (string) $this->param( 'title', $post->post_title ) );
		$alt     = sanitize_text_field( (string) $this->param( 'alt', '' ) );
		$caption = sanitize_text_field( (string) $this->param( 'caption', '' ) );
		$desc    = wp_kses_post( (string) $this->param( 'description', '' ) );

		wp_update_post(
			array(
				'ID'           => $id,
				'post_title'   => $title,
				'post_excerpt' => $caption,
				'post_content' => $desc,
			)
		);

		update_post_meta( $id, '_wp_attachment_image_alt', $alt );

		/* 分類 */
		$folder_id = (int) $this->param( 'folder_id', 0 );
		if ( $folder_id > 0 ) {
			wp_set_object_terms( $id, array( $folder_id ), MN_MM_TAX_FOLDER, false );
		} elseif ( '0' === (string) $this->param( 'folder_id', '' ) ) {
			wp_set_object_terms( $id, array(), MN_MM_TAX_FOLDER, false );
		}

		$tag_ids = array_filter( array_map( 'intval', (array) $this->param( 'tag_ids', array() ) ) );
		wp_set_object_terms( $id, $tag_ids, MN_MM_TAX_TAG, false );

		MN_MM_Log::add(
			'bulk_edit',
			array(
				array(
					'id'     => $id,
					'before' => $before,
					'after'  => array(),
				),
			),
			array( 'mode' => 'single' )
		);

		wp_send_json_success( MN_MM_Query::format_item( get_post( $id ) ) );
	}

	/* =========================================================
	 *  批次操作
	 * ========================================================= */

	/**
	 * 批次指派 / 加入 / 移出資料夾。
	 */
	public function bulk_folder() {
		$this->guard();
		$this->relax_time_limit();

		$ids  = $this->param_ids();
		$mode = sanitize_text_field( (string) $this->param( 'mode', 'add' ) );

		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No media selected.', 'foldernest' ) ), 400 );
		}

		$before = MN_MM_Log::snapshot_terms( $ids, MN_MM_TAX_FOLDER );
		$items  = array();

		if ( 'clear' === $mode ) {
			foreach ( $ids as $id ) {
				wp_set_object_terms( $id, array(), MN_MM_TAX_FOLDER, false );
				$items[] = array(
					'id'     => $id,
					'before' => array( 'terms' => array( MN_MM_TAX_FOLDER => $before[ $id ] ) ),
					'after'  => array(),
				);
			}
			$log_id = MN_MM_Log::add( 'assign_folder', $items, array( 'mode' => 'clear' ) );

			wp_send_json_success(
				array(
					'affected' => count( $ids ),
					'log_id'   => $log_id,
					'message'  => sprintf( __( 'Removed %d media from all folders.', 'foldernest' ), count( $ids ) ),
				)
			);
		}

		/*
		 * 注意：目標資料夾用 target_folder_id，與 param_scope() 的
		 * folder_id（篩選範圍）區分開，否則 select_all 模式會互相蓋掉。
		 */
		$folder_id = (int) $this->param( 'target_folder_id', 0 );
		if ( $folder_id <= 0 || ! term_exists( $folder_id, MN_MM_TAX_FOLDER ) ) {
			wp_send_json_error( array( 'message' => __( 'Select a valid target folder.', 'foldernest' ) ), 400 );
		}

		$append = ( 'add' === $mode );

		foreach ( $ids as $id ) {
			if ( $append ) {
				wp_set_object_terms( $id, array( $folder_id ), MN_MM_TAX_FOLDER, true );
			} else {
				wp_set_object_terms( $id, array( $folder_id ), MN_MM_TAX_FOLDER, false );
			}
			$items[] = array(
				'id'     => $id,
				'before' => array( 'terms' => array( MN_MM_TAX_FOLDER => $before[ $id ] ) ),
				'after'  => array(),
			);
		}

		$log_id = MN_MM_Log::add( 'assign_folder', $items, array( 'mode' => $mode, 'folder_id' => $folder_id ) );

		$name = get_term( $folder_id, MN_MM_TAX_FOLDER );

		wp_send_json_success(
			array(
				'affected' => count( $ids ),
				'log_id'   => $log_id,
				'message'  => sprintf(
					'%1$d media %2$s "%3$s".',
					count( $ids ),
					( $append ? 'added to' : 'moved to' ),
					( $name && ! is_wp_error( $name ) ? $name->name : '' )
				),
			)
		);
	}

	/**
	 * 批次新增 / 移除標籤。
	 */
	public function bulk_tag() {
		$this->guard();
		$this->relax_time_limit();

		$ids  = $this->param_ids();
		$mode = sanitize_text_field( (string) $this->param( 'mode', 'add' ) );

		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No media selected.', 'foldernest' ) ), 400 );
		}

		$before = MN_MM_Log::snapshot_terms( $ids, MN_MM_TAX_TAG );
		$items  = array();
		$names  = array();

		/* 標籤可以用 term_id，也可以直接打名稱（不存在就建立）。 */
		$tag_ids   = array_filter( array_map( 'intval', (array) $this->param( 'tag_ids', array() ) ) );
		$tag_names = array_filter( array_map( 'trim', explode( ',', (string) $this->param( 'tag_names', '' ) ) ) );

		foreach ( $tag_names as $name ) {
			$name = sanitize_text_field( $name );
			if ( '' === $name ) {
				continue;
			}
			$exists = term_exists( $name, MN_MM_TAX_TAG );
			if ( $exists ) {
				$tag_ids[] = (int) ( is_array( $exists ) ? $exists['term_id'] : $exists );
			} elseif ( 'add' === $mode ) {
				$new = wp_insert_term( $name, MN_MM_TAX_TAG );
				if ( ! is_wp_error( $new ) ) {
					$tag_ids[] = (int) $new['term_id'];
				}
			}
		}

		$tag_ids = array_values( array_unique( array_filter( $tag_ids ) ) );

		if ( empty( $tag_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'Specify at least one tag.', 'foldernest' ) ), 400 );
		}

		foreach ( $tag_ids as $tid ) {
			$t = get_term( $tid, MN_MM_TAX_TAG );
			if ( $t && ! is_wp_error( $t ) ) {
				$names[] = $t->name;
			}
		}

		foreach ( $ids as $id ) {
			if ( 'remove' === $mode ) {
				wp_remove_object_terms( $id, $tag_ids, MN_MM_TAX_TAG );
			} else {
				wp_set_object_terms( $id, $tag_ids, MN_MM_TAX_TAG, true );
			}
			$items[] = array(
				'id'     => $id,
				'before' => array( 'terms' => array( MN_MM_TAX_TAG => $before[ $id ] ) ),
				'after'  => array(),
			);
		}

		$log_id = MN_MM_Log::add(
			( 'remove' === $mode ? 'remove_tag' : 'add_tag' ),
			$items,
			array( 'mode' => $mode, 'tag_ids' => $tag_ids )
		);

		wp_send_json_success(
			array(
				'affected' => count( $ids ),
				'log_id'   => $log_id,
				'message'  => sprintf(
					'%1$d media, tags %2$s: %3$s',
					count( $ids ),
					( 'remove' === $mode ? 'removed' : 'added' ),
					implode( ', ', $names )
				),
			)
		);
	}

	/**
	 * 批次編輯欄位（標題 / Alt / 說明 / 作者）。
	 *
	 * 支援變數：{filename}、{ext}、{index}（流水號）、{original}
	 */
	public function bulk_edit() {
		$this->guard();
		$this->relax_time_limit();

		$ids = $this->param_ids();
		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No media selected.', 'foldernest' ) ), 400 );
		}

		$fields = (array) $this->param( 'fields', array() );
		$mode   = sanitize_text_field( (string) $this->param( 'mode', 'replace' ) );

		$items = array();
		$index = 0;

		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}
			$index++;

			$before = array(
				'meta' => array(
					'post_title'               => $post->post_title,
					'post_excerpt'             => $post->post_excerpt,
					'post_content'             => $post->post_content,
					'post_author'              => $post->post_author,
					'_wp_attachment_image_alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
				),
			);

			$file     = (string) get_post_meta( $id, '_wp_attached_file', true );
			$filename = $file ? basename( $file ) : '';
			$stem     = $filename ? pathinfo( $filename, PATHINFO_FILENAME ) : '';
			$ext      = $filename ? pathinfo( $filename, PATHINFO_EXTENSION ) : '';

			$update      = array( 'ID' => $id );
			$meta_update = array();

			foreach ( array( 'title', 'alt', 'caption', 'description' ) as $key ) {
				if ( ! isset( $fields[ $key ] ) || '' === trim( (string) $fields[ $key ] ) ) {
					continue;
				}

				$template = (string) $fields[ $key ];
				$template = str_replace(
					array( '{filename}', '{stem}', '{ext}', '{index}', '{original}' ),
					array( $filename, $stem, $ext, (string) $index, $post->post_title ),
					$template
				);

				switch ( $key ) {
					case 'title':
						$current = $post->post_title;
						$update['post_title'] = $this->merge_value( $current, $template, $mode );
						break;
					case 'caption':
						$current = $post->post_excerpt;
						$update['post_excerpt'] = $this->merge_value( $current, $template, $mode );
						break;
					case 'description':
						$current = $post->post_content;
						$update['post_content'] = $this->merge_value( $current, wp_kses_post( $template ), $mode );
						break;
					case 'alt':
						$current = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
						$meta_update['_wp_attachment_image_alt'] = $this->merge_value( $current, $template, $mode );
						break;
				}
			}

			if ( count( $update ) > 1 ) {
				wp_update_post( $update );
			}
			foreach ( $meta_update as $k => $v ) {
				update_post_meta( $id, $k, $v );
			}

			$items[] = array(
				'id'     => $id,
				'before' => $before,
				'after'  => array(),
			);
		}

		$log_id = MN_MM_Log::add( 'bulk_edit', $items, array( 'mode' => $mode, 'fields' => array_keys( (array) $fields ) ) );

		wp_send_json_success(
			array(
				'affected' => count( $items ),
				'log_id'   => $log_id,
				'message'  => sprintf( __( 'Updated fields for %d media.', 'foldernest' ), count( $items ) ),
			)
		);
	}

	/**
	 * 依模式合併新舊值。
	 *
	 * @param string $current
	 * @param string $value
	 * @param string $mode replace|append|prepend
	 * @return string
	 */
	private function merge_value( $current, $value, $mode ) {
		switch ( $mode ) {
			case 'append':
				return trim( $current . ' ' . $value );
			case 'prepend':
				return trim( $value . ' ' . $current );
			default:
				return $value;
		}
	}

	/* =========================================================
	 *  永久刪除
	 * ========================================================= */

	/**
	 * 永久刪除媒體（不進回收桶、不可還原）。
	 *
	 * 破壞性操作：前端負責二次確認（含「使用中」警示）；
	 * 後端只做權限／nonce／ID 驗證。刪除走核心 wp_delete_attachment( $id, true )，
	 * 連同所有縮圖與 meta 一起清除。操作會留一份稽核記錄（無 undo）。
	 */
	public function media_delete() {
		$this->guard();
		$this->relax_time_limit();

		$ids = $this->param_ids();

		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No media selected.', 'foldernest' ) ), 400 );
		}

		$deleted = 0;
		$failed  = array();
		$names   = array();

		foreach ( $ids as $id ) {
			$post = get_post( $id );

			/* 只刪附件，其他 post type 一律拒絕（防呆）。 */
			if ( ! $post || 'attachment' !== $post->post_type ) {
				$failed[] = $id;
				continue;
			}

			$file    = (string) get_post_meta( $id, '_wp_attached_file', true );
			$names[] = $post->post_title !== '' ? $post->post_title : basename( $file );

			$result = wp_delete_attachment( $id, true );

			if ( $result ) {
				$deleted++;
				/**
				 * 稽核記錄：永久刪除無法 undo，帶 $count 讓記錄標記為
				 * 「不可還原」（不出現復原鈕），名稱留前 20 筆供對照。
				 */
				MN_MM_Log::add(
					'media_delete',
					array(),
					array(
						'permanent' => true,
						'names'     => array_slice( $names, 0, 20 ),
						'file'      => $file,
					),
					count( $ids )
				);
			} else {
				$failed[] = $id;
			}
		}

		$message = sprintf( __( 'Permanently deleted %d media.', 'foldernest' ), $deleted );
		if ( ! empty( $failed ) ) {
			$message .= sprintf( __( '(%d failed)', 'foldernest' ), count( $failed ) );
		}
		/* 批次上限 2000：若剛好刪滿，代表可能還有未使用媒體，提醒再執行一次。 */
		if ( $deleted >= self::MAX_BULK ) {
			$message .= 'More unused media remain beyond the per-run limit. Run it again to continue.';
		}

		wp_send_json_success(
			array(
				'deleted' => $deleted,
				'failed'  => count( $failed ),
				'message' => $message,
			)
		);
	}

	/* =========================================================
	 *  資料夾 / 標籤 CRUD
	 * ========================================================= */

	

	/* =========================================================
	 *  文章附屬媒體（Gutenberg 側欄用）
	 * ========================================================= */

	/**
	 * 列出直接附屬於某文章的媒體（上傳時掛在該文章下的）＋精選圖片。
	 */
	public function post_media() {
		$this->guard();

		$post_id = (int) $this->param( 'post_id', 0 );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => __( 'Post not found.', 'foldernest' ) ), 404 );
		}

		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_parent'    => $post_id,
				'post_status'    => array( 'inherit', 'private', 'publish' ),
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$thumb_id = (int) get_post_thumbnail_id( $post_id );
		if ( $thumb_id > 0 && ! in_array( $thumb_id, $ids, true ) ) {
			array_unshift( $ids, $thumb_id );
		}

		$items = array();
		foreach ( $ids as $id ) {
			$item = MN_MM_Query::format_item( $id );
			if ( $item ) {
				$items[] = $item;
			}
		}

		wp_send_json_success( array( 'items' => $items ) );
	}

	/* =========================================================
	 *  資料夾結構匯入 / 匯出
	 * ========================================================= */

	

	

	/* =========================================================
	 *  資料夾角色限制
	 * ========================================================= */

	

	/**
	 * 設定資料夾顏色（'' = 清除）。
	 */
	public function folder_color() {
		$this->guard();

		$term_id = (int) $this->param( 'term_id', 0 );
		$term    = get_term( $term_id, MN_MM_TAX_FOLDER );
		if ( ! $term || is_wp_error( $term ) ) {
			wp_send_json_error( array( 'message' => __( 'Folder not found.', 'foldernest' ) ), 400 );
		}

		/* 容忍帶 # 前綴（前端 type=color 與色板都會帶）與 3/6 位 hex。 */
		$color = ltrim( strtolower( trim( (string) $this->param( 'color', '' ) ) ), '#' );
		if ( '' !== $color && ! preg_match( '/^[0-9a-f]{3}([0-9a-f]{3})?$/', $color ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid color.', 'foldernest' ) ), 400 );
		}

		if ( '' === $color ) {
			delete_term_meta( $term_id, 'mn_mm_color' );
		} else {
			if ( 3 === strlen( $color ) ) {
				$color = $color[0] . $color[0] . $color[1] . $color[1] . $color[2] . $color[2];
			}
			update_term_meta( $term_id, 'mn_mm_color', '#' . $color );
		}

		wp_send_json_success(
			array(
				'folders' => MN_MM_Taxonomy::get_folder_tree( true ),
				'message' => __( 'Folder color updated.', 'foldernest' ),
			)
		);
	}

	/**
	 * 星標／釘選資料夾（切換）。
	 */
	public function folder_star() {
		$this->guard();

		$term_id = (int) $this->param( 'term_id', 0 );
		$term    = get_term( $term_id, MN_MM_TAX_FOLDER );
		if ( ! $term || is_wp_error( $term ) ) {
			wp_send_json_error( array( 'message' => __( 'Folder not found.', 'foldernest' ) ), 400 );
		}

		$star = (int) $this->param( 'star', 0 );
		if ( $star ) {
			update_term_meta( $term_id, 'mn_mm_star', 1 );
		} else {
			delete_term_meta( $term_id, 'mn_mm_star' );
		}

		wp_send_json_success(
			array(
				'folders' => MN_MM_Taxonomy::get_folder_tree( true ),
				'message' => $star ? __( 'Folder pinned.', 'foldernest' ) : __( 'Folder unpinned.', 'foldernest' ),
			)
		);
	}

	

	

	

	

	/**
	 * 建立檔案尺寸索引（分批；排序用）。
	 * 為缺 _mn_mm_filesize 的附件補寫位元組數。
	 */
	public function filesize_build() {
		$this->guard();
		$this->relax_time_limit();

		$offset = max( 0, (int) $this->param( 'offset', 0 ) );
		$limit  = max( 1, min( 500, (int) $this->param( 'limit', 200 ) ) );

		$ids = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => array( 'inherit', 'private', 'publish' ),
				'posts_per_page'         => $limit,
				'offset'                 => $offset,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'     => '_mn_mm_filesize',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		foreach ( $ids as $id ) {
			$file = get_attached_file( (int) $id );
			$size = ( $file && file_exists( $file ) ) ? (int) wp_filesize( $file ) : 0;
			update_post_meta( (int) $id, '_mn_mm_filesize', $size );
		}

		$missing = (int) MN_MM_Query::filesize_missing();
		$done    = empty( $ids ) || 0 === $missing;

		wp_send_json_success(
			array(
				'processed' => $offset + count( $ids ),
				'missing'   => $missing,
				'done'      => $done,
			)
		);
	}

	/* =========================================================
	 *  資料夾 / 標籤 CRUD
	 * ========================================================= */

	/**
	 * 新增資料夾。
	 */
	public function folder_create() {
		$this->guard();

		$name   = sanitize_text_field( (string) $this->param( 'name', '' ) );
		$parent = (int) $this->param( 'parent', 0 );

		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'Enter a folder name.', 'foldernest' ) ), 400 );
		}

		$exists = term_exists( $name, MN_MM_TAX_FOLDER, $parent );
		if ( $exists ) {
			wp_send_json_error( array( 'message' => __( 'A folder with the same name already exists at this level.', 'foldernest' ) ), 400 );
		}

		$new = wp_insert_term( $name, MN_MM_TAX_FOLDER, array( 'parent' => $parent ) );
		if ( is_wp_error( $new ) ) {
			wp_send_json_error( array( 'message' => $new->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'term_id' => (int) $new['term_id'],
				'folders' => MN_MM_Taxonomy::get_folder_tree( true ),
				'message' => __( 'Folder created.', 'foldernest' ),
			)
		);
	}

	/**
	 * 重新命名資料夾。
	 */
	public function folder_rename() {
		$this->guard();

		$term_id = (int) $this->param( 'term_id', 0 );
		$name    = sanitize_text_field( (string) $this->param( 'name', '' ) );

		if ( $term_id <= 0 || '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'Missing parameters.', 'foldernest' ) ), 400 );
		}

		$result = wp_update_term( $term_id, MN_MM_TAX_FOLDER, array( 'name' => $name ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'folders' => MN_MM_Taxonomy::get_folder_tree( true ),
				'message' => __( 'Folder renamed.', 'foldernest' ),
			)
		);
	}

	/**
	 * 刪除資料夾。
	 *
	 * ⚠️ 只刪除分類本身，媒體與檔案一律保留；
	 *    子資料夾會被提升到上一層，不會連帶消失。
	 */
	public function folder_delete() {
		$this->guard();

		$term_id = (int) $this->param( 'term_id', 0 );
		if ( $term_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Missing parameters.', 'foldernest' ) ), 400 );
		}

		$term = get_term( $term_id, MN_MM_TAX_FOLDER );
		if ( ! $term || is_wp_error( $term ) ) {
			wp_send_json_error( array( 'message' => __( 'Folder not found.', 'foldernest' ) ), 404 );
		}

		/* 先把子資料夾接到上一層，避免整個子樹被孤立。 */
		$children = get_term_children( $term_id, MN_MM_TAX_FOLDER );
		if ( ! is_wp_error( $children ) ) {
			foreach ( $children as $child_id ) {
				wp_update_term( $child_id, MN_MM_TAX_FOLDER, array( 'parent' => (int) $term->parent ) );
			}
		}

		wp_delete_term( $term_id, MN_MM_TAX_FOLDER );

		wp_send_json_success(
			array(
				'folders' => MN_MM_Taxonomy::get_folder_tree( true ),
				'stats'   => MN_MM_Query::stats(),
				'message' => __( 'Folder deleted (the media itself is not affected).', 'foldernest' ),
			)
		);
	}

	/**
	 * 移動資料夾（拖拉改變階層）。
	 */
	public function folder_move() {
		$this->guard();

		$term_id = (int) $this->param( 'term_id', 0 );
		$parent  = (int) $this->param( 'parent', 0 );

		if ( $term_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Missing parameters.', 'foldernest' ) ), 400 );
		}
		if ( $term_id === $parent ) {
			wp_send_json_error( array( 'message' => __( 'A folder cannot be moved into itself.', 'foldernest' ) ), 400 );
		}

		/* 防止把資料夾移到自己的子孫底下（會造成循環）。 */
		if ( $parent > 0 ) {
			$descendants = MN_MM_Taxonomy::get_descendant_ids( $term_id );
			if ( in_array( $parent, $descendants, true ) ) {
				wp_send_json_error( array( 'message' => __( 'A folder cannot be moved into its own subfolder.', 'foldernest' ) ), 400 );
			}
		}

		$result = wp_update_term( $term_id, MN_MM_TAX_FOLDER, array( 'parent' => $parent ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'folders' => MN_MM_Taxonomy::get_folder_tree( true ),
				'message' => __( 'Folder moved.', 'foldernest' ),
			)
		);
	}

	/**
	 * 新增標籤。
	 */
	public function tag_create() {
		$this->guard();

		$name = sanitize_text_field( (string) $this->param( 'name', '' ) );
		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'Enter tag names.', 'foldernest' ) ), 400 );
		}

		$exists = term_exists( $name, MN_MM_TAX_TAG );
		if ( $exists ) {
			wp_send_json_error( array( 'message' => __( 'This tag already exists.', 'foldernest' ) ), 400 );
		}

		$new = wp_insert_term( $name, MN_MM_TAX_TAG );
		if ( is_wp_error( $new ) ) {
			wp_send_json_error( array( 'message' => $new->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'term_id' => (int) $new['term_id'],
				'message' => __( 'Tag created.', 'foldernest' ),
			)
		);
	}

	/**
	 * 刪除標籤。
	 */
	public function tag_delete() {
		$this->guard();

		$term_id = (int) $this->param( 'term_id', 0 );
		if ( $term_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Missing parameters.', 'foldernest' ) ), 400 );
		}

		wp_delete_term( $term_id, MN_MM_TAX_TAG );

		wp_send_json_success( array( 'message' => __( 'Tag deleted.', 'foldernest' ) ) );
	}

	/* =========================================================
	 *  自動分類
	 * ========================================================= */

	/**
	 * 讀取規則（未帶參數）或儲存規則（帶 rules）。
	 */
	public function auto_save_rules() {
		$this->guard();

		$rules = $this->param( 'rules', null );
		if ( null === $rules ) {
			wp_send_json_success( array( 'rules' => MN_MM_Auto::get_rules() ) );
		}

		$decoded = is_array( $rules ) ? $rules : json_decode( (string) $rules, true );
		if ( ! is_array( $decoded ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid rule format.', 'foldernest' ) ), 400 );
		}

		$saved = MN_MM_Auto::save_rules( $decoded );

		wp_send_json_success(
			array(
				'rules'   => $saved,
				'message' => sprintf( __( 'Saved %d rules.', 'foldernest' ), count( $saved ) ),
			)
		);
	}

	/**
	 * 還原成預設規則。
	 */
	public function auto_reset_rules() {
		$this->guard();
		delete_option( MN_MM_Auto::RULES_OPTION );
		wp_send_json_success(
			array(
				'rules'   => MN_MM_Auto::default_rules(),
				'message' => __( 'Restored the default rules.', 'foldernest' ),
			)
		);
	}

	/**
	 * 試跑預覽（不寫入）。
	 */
	public function auto_preview() {
		$this->guard();

		$rules   = json_decode( (string) $this->param( 'rules', '[]' ), true );
		$rules   = is_array( $rules ) ? MN_MM_Auto::save_rules( $rules ) : MN_MM_Auto::get_rules();
		$scope   = $this->param_scope();

		$result = MN_MM_Auto::preview( $rules, $scope );

		wp_send_json_success( $result );
	}

	/**
	 * 開始套用。
	 */
	public function auto_start() {
		$this->guard();

		$rules = json_decode( (string) $this->param( 'rules', '[]' ), true );
		$rules = is_array( $rules ) ? MN_MM_Auto::save_rules( $rules ) : MN_MM_Auto::get_rules();
		$scope = $this->param_scope();

		$result = MN_MM_Auto::start_job( $rules, $scope );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * 執行一批。
	 */
	public function auto_step() {
		$this->guard();

		$result = MN_MM_Auto::step_job();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * 取消。
	 */
	public function auto_cancel() {
		$this->guard();
		$ok = MN_MM_Auto::cancel_job();
		wp_send_json_success(
			array(
				'cancelled' => $ok,
				'message'   => $ok ? __( 'Cancelled. Everything already applied can be undone from Activity Log.', 'foldernest' ) : __( 'No job is running.', 'foldernest' ),
			)
		);
	}

	/* =========================================================
	 *  使用狀態
	 * ========================================================= */

	/**
	 * 分批掃描使用狀態。
	 */
	public function usage_scan() {
		$this->guard();

		$offset  = (int) $this->param( 'offset', 0 );
		$limit   = (int) $this->param( 'limit', 200 );
		$rebuild = (bool) $this->param( 'rebuild', 0 );

		$result = MN_MM_Usage::scan_batch( $offset, $limit, $rebuild );

		if ( $result['done'] ) {
			MN_MM_Log::add(
				'usage_scan',
				array(),
				array( 'total' => $result['total'] ),
				$result['total']
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * 清除索引快取。
	 */
	public function usage_flush() {
		$this->guard();
		MN_MM_Usage::flush_index();
		wp_send_json_success( array( 'message' => __( 'Reference index rebuilt. The next scan will re-analyze site content.', 'foldernest' ) ) );
	}

	/* =========================================================
	 *  操作記錄
	 * ========================================================= */

	/**
	 * 記錄列表。
	 */
	public function log_list() {
		$this->guard();
		wp_send_json_success( array( 'logs' => MN_MM_Log::recent( (int) $this->param( 'limit', 30 ) ) ) );
	}

	/**
	 * 還原。
	 */
	public function log_undo() {
		$this->guard();

		$log_id = (int) $this->param( 'log_id', 0 );
		$result = MN_MM_Log::undo( $log_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'restored' => $result['restored'],
				'stats'    => MN_MM_Query::stats(),
				'message'  => sprintf( __( 'Restored the previous state of %d media.', 'foldernest' ), $result['restored'] ),
			)
		);
	}
}
