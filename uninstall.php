<?php
/**
 * FolderNest — 移除外掛時的清理。
 *
 * ⚠️ 這裡做的事：
 *   1. 刪除外掛的設定（規則、旗標）
 *   2. 刪除操作記錄資料表
 *   3. 刪除使用狀態快取（postmeta）
 *   4. 刪除外掛建立的兩個分類法底下的所有項目
 *
 * ⚠️ 這裡「絕對不會」做的事：
 *   刪除、移動、改名任何媒體檔案，也不會改動任何媒體的路徑或網址。
 *   移除外掛後，媒體庫會回到「沒有分類」的狀態，但所有檔案完好無損。
 *
 * 若只想停用而不想失去分類，請直接「停用」外掛，不要刪除。
 *
 * @package FolderNest
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

/* ---------- 1. 設定 ---------- */
$options = array(
	'mn_mm_version',
	'mn_mm_auto_rules',
	'mn_mm_notice_dismissed',
	'mn_mm_migrated_from_tkm',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

/* ---------- 2. 操作記錄資料表 ---------- */
$table = $wpdb->prefix . 'mn_media_log';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

/* ---------- 3. 使用狀態快取 ---------- */
foreach ( array( '_mn_mm_usage', '_mn_mm_usage_at' ) as $meta_key ) {
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $meta_key ), array( '%s' ) );
}

/* ---------- 4. 引用索引快取 ---------- */
delete_transient( 'mn_mm_usage_index' );

/* ---------- 5. 分類項目 ---------- */
/*
 * 刪除這兩個分類法底下的所有項目。
 * 這是「分類」而非「內容」，刪掉不會影響任何媒體檔案；
 * 但如果你之後可能重新安裝並想保留分類，請改為直接停用外掛。
 */
foreach ( array( 'mn_media_folder', 'mn_media_tag' ) as $taxonomy ) {
	$term_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
			$taxonomy
		)
	);

	if ( ! $term_ids ) {
		continue;
	}

	foreach ( $term_ids as $term_id ) {
		wp_delete_term( (int) $term_id, $taxonomy );
	}
}

flush_rewrite_rules();
