<?php
/**
 * PlugNest Media Folders — 使用狀態掃描。
 *
 * 回答一個很實際的問題：「這個媒體到底還有沒有人在用？」
 *
 * 做法：掃描全站文章內容、Elementor 資料、特色圖片等，建立「被引用的
 * 檔案／ID 索引」，再逐一比對每個媒體。索引只建立一次並快取，之後
 * 分批標記，避免單次請求逾時。
 *
 * ⚠️ 本模組只「標記」與「顯示」，永遠不會自動刪除任何媒體。
 *    刪除是不可逆的，必須由人看過之後自己決定。
 *
 * @package PlugNest Media Folders
 */

defined( 'ABSPATH' ) || exit;

class MN_MM_Usage {

	/** 索引快取 key。 */
	const INDEX_KEY = 'mn_mm_usage_index';

	/** 索引快取有效秒數。 */
	const INDEX_TTL = 1800;

	/** 掃描的來源 meta key。 */
	private static function source_meta_keys() {
		return array(
			'_thumbnail_id',                 // 特色圖片
			'_elementor_data',               // Elementor 版面資料
			'_elementor_page_settings',      // Elementor 頁面設定（含背景圖）
			'_elementor_css',                // 少數情況會帶 URL
			'_wp_page_template',
			'_mn_event_image',
		);
	}

	/**
	 * 建立引用索引。
	 *
	 * @param bool $force 是否強制重建。
	 * @return array array( 'ids' => array, 'paths' => array, 'names' => array, 'built_at' => int )
	 */
	public static function build_index( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::INDEX_KEY );
			if ( is_array( $cached ) && ! empty( $cached['built_at'] ) ) {
				return $cached;
			}
		}

		global $wpdb;

		$ids   = array();
		$paths = array();
		$names = array();

		/* ---------- 1. meta 中的直接 ID 引用 ---------- */
		$keys         = self::source_meta_keys();
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders})",
				$keys
			),
			ARRAY_A
		);

		if ( $rows ) {
			foreach ( $rows as $row ) {
				$value = (string) $row['meta_value'];

				if ( '_thumbnail_id' === $row['meta_key'] && is_numeric( $value ) ) {
					$ids[ (int) $value ] = 1;
				}

				/* 從任何來源字串中抓出 URL 與 ID */
				self::harvest( $value, $ids, $paths, $names );
			}
		}

		/* ---------- 2. 文章內容 ---------- */
		$contents = $wpdb->get_col(
			"SELECT post_content FROM {$wpdb->posts}
			 WHERE post_type NOT IN ('attachment','revision','nav_menu_item','custom_css','customize_changeset')
			   AND post_status NOT IN ('trash','auto-draft','inherit')"
		);

		if ( $contents ) {
			foreach ( $contents as $text ) {
				self::harvest( (string) $text, $ids, $paths, $names );
			}
		}

		/* ---------- 3. 網站識別圖示（site_icon 存 ID） ---------- */
		$site_icon = (int) get_option( 'site_icon' );
		if ( $site_icon > 0 ) {
			$ids[ $site_icon ] = 1;
		}

		$index = array(
			'ids'      => $ids,
			'paths'    => $paths,
			'names'    => $names,
			'built_at' => time(),
			'sources'  => array(
				'meta_rows' => $rows ? count( $rows ) : 0,
				'contents'  => $contents ? count( $contents ) : 0,
			),
		);

		set_transient( self::INDEX_KEY, $index, self::INDEX_TTL );

		return $index;
	}

	/**
	 * 從一段字串中蒐集引用線索。
	 *
	 * @param string $text
	 * @param array  $ids   （傳址）
	 * @param array  $paths （傳址）
	 * @param array  $names （傳址）
	 */
	private static function harvest( $text, &$ids, &$paths, &$names ) {
		if ( '' === $text ) {
			return;
		}

		/* JSON 轉義還原：Elementor _elementor_data 內的 URL 存成
		 * https:\/\/…\/wp-content\/uploads\/…，不還原的話
		 * 「wp-content/uploads/」的比對永遠失敗 → 大量實際使用中的
		 * 媒體（尤其只用 URL 引用、沒有附件 ID 的音頻／視頻）被誤判未使用。 */
		if ( strpos( $text, '\\/' ) !== false ) {
			$text = str_replace( '\\/', '/', $text );
		}

		/* JSON \uXXXX 跳脫還原：json_encode 預設會把非 ASCII 字元轉成
		 * \u6155\u65af 這種十六進位形式，中文／日文檔名的音頻（例：慕斯、
		 * 夕立等角色語音）在 Elementor 資料中整串檔名都是跳脫字元，
		 * 不還原的話即使斜線已修正，檔名比對仍會失敗。
		 * 先合併代理對（BMP 外字元），再解碼單一碼位。 */
		if ( strpos( $text, '\\u' ) !== false ) {
			$text = preg_replace_callback(
				'/\\\\u([dD][89aAbB][0-9a-fA-F]{2})\\\\u([dD][cdefCDEF][0-9a-fA-F]{2})/',
				static function ( $m ) {
					$cp = ( hexdec( $m[1] ) - 0xD800 ) * 0x400 + ( hexdec( $m[2] ) - 0xDC00 ) + 0x10000;
					return mb_decode_numericentity( '&#' . $cp . ';', array( 0, 0x10FFFF, 0, 0x1FFFFF ), 'UTF-8' );
				},
				$text
			);
			$text = preg_replace_callback(
				'/\\\\u([0-9a-fA-F]{4})/',
				static function ( $m ) {
					return mb_decode_numericentity( '&#' . hexdec( $m[1] ) . ';', array( 0, 0x10FFFF, 0, 0x1FFFFF ), 'UTF-8' );
				},
				$text
			);
		}

		/* (a) wp-image-123 這種標記 */
		if ( preg_match_all( '/wp-image-(\d+)/i', $text, $m ) ) {
			foreach ( $m[1] as $id ) {
				$ids[ (int) $id ] = 1;
			}
		}

		/* (b) uploads 路徑 */
		if ( preg_match_all( '#wp-content/uploads/([^"\'<>\s\)\]]+)#i', $text, $m ) ) {
			foreach ( $m[1] as $raw ) {
				$raw = preg_replace( '/[?#].*$/', '', $raw );  // 去掉 query / fragment
				$raw = urldecode( $raw );
				$raw = trim( $raw, '\\/' );
				if ( '' === $raw ) {
					continue;
				}
				$base            = self::strip_size( $raw );
				$paths[ $base ]  = 1;
				$names[ basename( $base ) ] = 1;
			}
		}

		/* (c) Elementor JSON 中的 "id":123（保守起見一律視為附件 ID，
		       寧可誤判為「使用中」也不要誤判為「未使用」） */
		if ( false !== strpos( $text, '"id"' ) && preg_match_all( '/"id"\s*:\s*"?(\d+)"?/i', $text, $m ) ) {
			foreach ( $m[1] as $id ) {
				$id = (int) $id;
				if ( $id > 0 ) {
					$ids[ $id ] = 1;
				}
			}
		}
	}

	/**
	 * 去掉檔名中的尺寸後綴與副檔名。
	 *
	 * 例：2026/09/3star-kaori-1024x576.jpg → 2026/09/3star-kaori
	 *     2026/09/photo-scaled.jpg         → 2026/09/photo
	 *
	 * @param string $file
	 * @return string
	 */
	public static function strip_size( $file ) {
		$file = (string) $file;
		$file = preg_replace( '/-\d+x\d+(?=\.[a-zA-Z0-9]+$)/', '', $file );
		$file = preg_replace( '/-(scaled|rotated|edit)(?=\.[a-zA-Z0-9]+$)/', '', $file );
		$file = preg_replace( '/\.[a-zA-Z0-9]+$/', '', $file );
		return $file;
	}

	/**
	 * 判斷單一媒體是否被引用。
	 *
	 * @param int   $id
	 * @param array $index
	 * @return bool
	 */
	public static function is_used( $id, $index ) {
		$id = (int) $id;

		if ( isset( $index['ids'][ $id ] ) ) {
			return true;
		}

		$file = get_post_meta( $id, '_wp_attached_file', true );
		if ( ! $file ) {
			return false;
		}

		$base = self::strip_size( $file );
		if ( isset( $index['paths'][ $base ] ) ) {
			return true;
		}

		if ( isset( $index['names'][ basename( $base ) ] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * 媒體總數。
	 *
	 * @return int
	 */
	public static function total() {
		$counts = wp_count_posts( 'attachment' );
		$total  = 0;
		foreach ( array( 'inherit', 'private', 'publish' ) as $st ) {
			if ( isset( $counts->$st ) ) {
				$total += (int) $counts->$st;
			}
		}
		return $total;
	}

	/**
	 * 掃描一批媒體並寫入使用狀態。
	 *
	 * @param int  $offset
	 * @param int  $limit
	 * @param bool $rebuild_index 是否強制重建索引。
	 * @return array
	 */
	public static function scan_batch( $offset = 0, $limit = 200, $rebuild_index = false ) {
		$index = self::build_index( $rebuild_index );
		$total = self::total();

		$ids = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => array( 'inherit', 'private', 'publish' ),
				'posts_per_page'         => max( 1, min( 500, (int) $limit ) ),
				'offset'                 => max( 0, (int) $offset ),
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		$used_count   = 0;
		$unused_count = 0;
		$now          = time();

		foreach ( $ids as $id ) {
			$used = self::is_used( $id, $index );
			update_post_meta( $id, MN_MM_META_USAGE, $used ? 1 : 0 );
			update_post_meta( $id, MN_MM_META_USAGE_AT, $now );
			if ( $used ) {
				$used_count++;
			} else {
				$unused_count++;
			}
		}

		$processed = (int) $offset + count( $ids );

		return array(
			'offset'    => (int) $offset,
			'processed' => $processed,
			'batch'     => count( $ids ),
			'total'     => $total,
			'used'      => $used_count,
			'unused'    => $unused_count,
			'done'      => $processed >= $total || 0 === count( $ids ),
		);
	}

	/**
	 * 清除索引快取。
	 */
	public static function flush_index() {
		delete_transient( self::INDEX_KEY );
	}
}
