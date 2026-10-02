<?php
/**
 * PlugNest Media Folders — 操作記錄與還原。
 *
 * 批次操作最大的風險是「按下去就回不去了」。這裡為每一次批次操作
 * 留下完整的 before/after 快照，讓任何一次操作都能一鍵還原。
 *
 * @package PlugNest Media Folders
 */

defined( 'ABSPATH' ) || exit;

class MN_MM_Log {

	/** 單筆記錄最多保存的項目數；超過則僅記錄統計，不提供還原。 */
	const MAX_ITEMS = 2000;

	/**
	 * 寫入一筆操作記錄。
	 *
	 * @param string   $action  操作代號，例如 'assign_folder'。
	 * @param array    $items   項目陣列：array( array( 'id' => int, 'before' => mixed, 'after' => mixed ), … )
	 * @param array    $context 額外資訊（例如 taxonomy、模式）。
	 * @param int|null $count   自訂筆數（用於沒有逐項明細的操作，例如掃描）。
	 * @return int 記錄 ID，失敗回 0。
	 */
	public static function add( $action, $items, $context = array(), $count = null ) {
		global $wpdb;

		$has_items = ( null === $count );
		$count     = $has_items ? count( $items ) : (int) $count;

		/* 項目過多時仍記錄，但標記為不可還原，避免資料表被撐爆。 */
		$payload = array(
			'context'   => $context,
			'items'     => ( $has_items && $count <= self::MAX_ITEMS ) ? $items : array(),
			'undoable'  => $has_items && $count <= self::MAX_ITEMS,
			'truncated' => $count > self::MAX_ITEMS,
		);

		$ok = $wpdb->insert(
			MN_MM_Plugin::log_table(),
			array(
				'created_at'   => current_time( 'mysql' ),
				'user_id'      => get_current_user_id(),
				'action'       => $action,
				'object_count' => $count,
				'payload'      => wp_json_encode( $payload ),
				'undone'       => 0,
			),
			array( '%s', '%d', '%s', '%d', '%s', '%d' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * 取得最近的記錄。
	 *
	 * @param int $limit
	 * @return array
	 */
	public static function recent( $limit = 30 ) {
		global $wpdb;

		$table = MN_MM_Plugin::log_table();
		$limit = max( 1, min( 200, (int) $limit ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, created_at, user_id, action, object_count, undone, payload
				 FROM {$table} ORDER BY id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return array();
		}

		foreach ( $rows as &$row ) {
			$data                  = json_decode( (string) $row['payload'], true );
			$row['undoable']       = is_array( $data ) && ! empty( $data['undoable'] );
			$row['label']          = self::action_label( $row['action'] );
			$row['user_name']      = self::user_name( (int) $row['user_id'] );
			unset( $row['payload'] );
		}
		unset( $row );

		return $rows;
	}

	/**
	 * 還原一筆記錄。
	 *
	 * @param int $log_id
	 * @return array|WP_Error array( 'restored' => int ) 或錯誤。
	 */
	public static function undo( $log_id ) {
		global $wpdb;

		$table = MN_MM_Plugin::log_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $log_id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Activity entry not found.', 'plugnest-media-folders' ) );
		}
		if ( (int) $row['undone'] === 1 ) {
			return new WP_Error( 'already_undone', __( 'This action has already been undone.', 'plugnest-media-folders' ) );
		}

		$data = json_decode( (string) $row['payload'], true );
		if ( ! is_array( $data ) || empty( $data['undoable'] ) || empty( $data['items'] ) ) {
			return new WP_Error( 'not_undoable', __( 'This action has no restorable snapshot (too many items, or the record is corrupted).', 'plugnest-media-folders' ) );
		}

		$restored = 0;
		foreach ( $data['items'] as $item ) {
			if ( empty( $item['id'] ) ) {
				continue;
			}
			$id = (int) $item['id'];
			if ( ! get_post( $id ) ) {
				continue; // 媒體已被刪除，略過。
			}
			self::restore_item( $id, $item );
			$restored++;
		}

		$wpdb->update( $table, array( 'undone' => 1 ), array( 'id' => (int) $log_id ), array( '%d' ), array( '%d' ) );

		return array( 'restored' => $restored );
	}

	/**
	 * 還原單一項目。
	 *
	 * before 的結構依操作類型而異，這裡統一處理：
	 *   - 'terms' => array( taxonomy => int[] )   分類關係
	 *   - 'meta'  => array( meta_key => string )  文章欄位／meta
	 *
	 * @param int   $id
	 * @param array $item
	 */
	private static function restore_item( $id, $item ) {
		$before = isset( $item['before'] ) && is_array( $item['before'] ) ? $item['before'] : array();

		/* 分類關係 */
		if ( ! empty( $before['terms'] ) && is_array( $before['terms'] ) ) {
			foreach ( $before['terms'] as $tax => $term_ids ) {
				if ( ! taxonomy_exists( $tax ) ) {
					continue;
				}
				wp_set_object_terms( $id, array_map( 'intval', (array) $term_ids ), $tax, false );
			}
		}

		/* 文章欄位與 meta */
		if ( ! empty( $before['meta'] ) && is_array( $before['meta'] ) ) {
			$post_fields = array( 'post_title', 'post_excerpt', 'post_content', 'post_author' );
			$update      = array( 'ID' => $id );

			foreach ( $before['meta'] as $key => $value ) {
				if ( in_array( $key, $post_fields, true ) ) {
					if ( 'post_author' === $key ) {
						$update['post_author'] = (int) $value;
					} else {
						$update[ $key ] = (string) $value;
					}
				} else {
					update_post_meta( $id, $key, $value );
				}
			}

			if ( count( $update ) > 1 ) {
				wp_update_post( $update );
			}
		}
	}

	/**
	 * 建立快照：抓出這些媒體目前的分類關係。
	 *
	 * @param int[]  $ids
	 * @param string $taxonomy
	 * @return array
	 */
	public static function snapshot_terms( $ids, $taxonomy ) {
		$snap = array();
		foreach ( $ids as $id ) {
			$id           = (int) $id;
			$snap[ $id ]  = array_map( 'intval', (array) wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'ids' ) ) );
		}
		return $snap;
	}

	/**
	 * 操作代號 → 可讀標籤。
	 *
	 * @param string $action
	 * @return string
	 */
	public static function action_label( $action ) {
		$map = array(
			'assign_folder'  => __( 'Assign folder', 'plugnest-media-folders' ),
			'add_tag'        => __( 'Add tags', 'plugnest-media-folders' ),
			'remove_tag'     => __( 'Remove tags', 'plugnest-media-folders' ),
			'bulk_edit'      => __( 'Batch edit fields', 'plugnest-media-folders' ),
			'auto_classify'  => __( 'Auto-assign', 'plugnest-media-folders' ),
			'usage_scan'     => __( 'Usage scan', 'plugnest-media-folders' ),
		);
		return isset( $map[ $action ] ) ? $map[ $action ] : $action;
	}

	/**
	 * 使用者顯示名稱。
	 *
	 * @param int $user_id
	 * @return string
	 */
	private static function user_name( $user_id ) {
		if ( $user_id <= 0 ) {
			return __( 'System', 'plugnest-media-folders' );
		}
		$u = get_userdata( $user_id );
		return $u ? $u->display_name : sprintf( __( 'User #%d', 'plugnest-media-folders' ), $user_id );
	}
}
