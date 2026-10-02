<?php
/**
 * PlugNest Media Folders — 媒體查詢引擎。
 *
 * 把「資料夾 + 標籤 + 類型 + 日期 + 使用狀態 + 關鍵字」這些條件
 * 組合成一次查詢，回傳前端要用的完整資料。
 *
 * 全程使用 WP_Query / 分類法 API，不涉及任何檔案系統操作。
 *
 * @package PlugNest Media Folders
 */

defined( 'ABSPATH' ) || exit;

class MN_MM_Query {

	/**
	 * 查詢媒體。
	 *
	 * @param array $args 查詢條件。
	 * @return array
	 */
	public static function get_media( $args = array() ) {
		$a = wp_parse_args(
			$args,
			array(
				'folder_id'        => 0,
				'include_children' => true,
				'tag_ids'          => array(),
				'mime_group'       => '',
				'year'             => 0,
				'month'            => 0,
				'usage'            => '',
				'alt_missing'      => false,
				'search'           => '',
				'paged'            => 1,
				'per_page'         => 60,
				'orderby'          => 'date',
				'order'            => 'DESC',
			)
		);

		$per_page = max( 1, min( 200, (int) $a['per_page'] ) );
		$paged    = max( 1, (int) $a['paged'] );

		$q = array(
			'post_type'      => 'attachment',
			'post_status'    => array( 'inherit', 'private', 'publish' ),
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'order'          => ( 'ASC' === strtoupper( $a['order'] ) ) ? 'ASC' : 'DESC',
		);

		/* ---------- 排序 ---------- */
		switch ( $a['orderby'] ) {
			case 'title':
				$q['orderby'] = 'title';
				break;
			case 'id':
				$q['orderby'] = 'ID';
				break;
			case 'modified':
				$q['orderby'] = 'modified';
				break;
			default:
				$q['orderby'] = 'date';
		}

		/* ---------- 關鍵字 ---------- */
		if ( '' !== trim( (string) $a['search'] ) ) {
			$q['s'] = trim( (string) $a['search'] );
		}

		/* ---------- 類型 ---------- */
		$mime_types = self::mime_group_to_types( $a['mime_group'] );
		if ( $mime_types ) {
			$q['post_mime_type'] = $mime_types;
		}

		/* ---------- 日期 ---------- */
		if ( (int) $a['year'] > 0 ) {
			$dq = array( 'year' => (int) $a['year'] );
			if ( (int) $a['month'] > 0 ) {
				$dq['month'] = (int) $a['month'];
			}
			$q['date_query'] = array( $dq );
		}

		/* ---------- 分類法 ---------- */
		$tax_query = array();

		if ( 'none' === $a['folder_id'] ) {
			$tax_query[] = array(
				'taxonomy' => MN_MM_TAX_FOLDER,
				'operator' => 'NOT EXISTS',
			);
		} elseif ( (int) $a['folder_id'] > 0 ) {
			$ids = ! empty( $a['include_children'] )
				? MN_MM_Taxonomy::get_descendant_ids( (int) $a['folder_id'] )
				: array( (int) $a['folder_id'] );

			$tax_query[] = array(
				'taxonomy' => MN_MM_TAX_FOLDER,
				'field'    => 'term_id',
				'terms'    => $ids,
				'operator' => 'IN',
			);
		}

		if ( ! empty( $a['tag_ids'] ) ) {
			$tax_query[] = array(
				'taxonomy' => MN_MM_TAX_TAG,
				'field'    => 'term_id',
				'terms'    => array_map( 'intval', (array) $a['tag_ids'] ),
				'operator' => 'IN',
			);
		}

		/* ---------- 受限資料夾（角色限制）排除 ---------- */
		$restricted = MN_MM_Taxonomy::restricted_folder_ids_for_user();
		if ( ! empty( $restricted ) ) {
			$tax_query[] = array(
				'taxonomy' => MN_MM_TAX_FOLDER,
				'field'    => 'term_id',
				'terms'    => $restricted,
				'operator' => 'NOT IN',
			);
		}

		if ( $tax_query ) {
			$tax_query['relation'] = 'AND';
			$q['tax_query']        = $tax_query;
		}

		/* ---------- 使用狀態 + 缺 Alt ---------- */
		$meta_query = array();

		switch ( $a['usage'] ) {
			case 'used':
				$meta_query[] = array( 'key' => MN_MM_META_USAGE, 'value' => '1', 'compare' => '=' );
				break;
			case 'unused':
				$meta_query[] = array( 'key' => MN_MM_META_USAGE, 'value' => '0', 'compare' => '=' );
				break;
			case 'unscanned':
				$meta_query[] = array( 'key' => MN_MM_META_USAGE, 'compare' => 'NOT EXISTS' );
				break;
		}

		/* 缺替代文字（圖片）：alt meta 不存在或空字串 */
		if ( ! empty( $a['alt_missing'] ) ) {
			$meta_query[] = array(
				'relation' => 'OR',
				array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_wp_attachment_image_alt', 'value' => '' ),
			);
		}

		if ( count( $meta_query ) === 1 ) {
			$q['meta_query'] = $meta_query;
		} elseif ( $meta_query ) {
			$q['meta_query']              = $meta_query;
			$q['meta_query']['relation'] = 'AND';
		}

		$query = new WP_Query( $q );

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = self::format_item( $post );
		}

		return array(
			'items'    => $items,
			'total'    => (int) $query->found_posts,
			'pages'    => (int) $query->max_num_pages,
			'paged'    => $paged,
			'per_page' => $per_page,
		);
	}

	/**
	 * 取得「符合條件的全部 ID」，供批次操作使用。
	 *
	 * 刻意不分頁，一次取完 ID（只取 ID，記憶體成本低）。
	 *
	 * @param array $args 同 get_media 的條件。
	 * @return int[]
	 */
	public static function get_ids( $args = array() ) {
		$q = array(
			'post_type'      => 'attachment',
			'post_status'    => array( 'inherit', 'private', 'publish' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);

		/* 重用 build_args 的條件組裝，只是這裡取全部 ID。 */
		$q = array_merge( $q, self::build_args( $args ) );

		$ids = get_posts( $q );
		return array_map( 'intval', $ids );
	}

	/**
	 * 把參數轉成 WP_Query 參數（供 get_media 與 get_ids 共用）。
	 *
	 * @param array $a
	 * @return array
	 */
	private static function build_args( $a ) {
		$a = wp_parse_args(
			$a,
			array(
				'folder_id'        => 0,
				'include_children' => true,
				'tag_ids'          => array(),
				'mime_group'       => '',
				'year'             => 0,
				'month'            => 0,
				'usage'            => '',
				'search'           => '',
				'orderby'          => 'date',
				'order'            => 'DESC',
			)
		);

		$q = array();

		if ( '' !== trim( (string) $a['search'] ) ) {
			$q['s'] = trim( (string) $a['search'] );
		}

		$mime_types = self::mime_group_to_types( $a['mime_group'] );
		if ( $mime_types ) {
			$q['post_mime_type'] = $mime_types;
		}

		if ( (int) $a['year'] > 0 ) {
			$dq = array( 'year' => (int) $a['year'] );
			if ( (int) $a['month'] > 0 ) {
				$dq['month'] = (int) $a['month'];
			}
			$q['date_query'] = array( $dq );
		}

		$tax_query = array();

		if ( 'none' === $a['folder_id'] ) {
			$tax_query[] = array(
				'taxonomy' => MN_MM_TAX_FOLDER,
				'operator' => 'NOT EXISTS',
			);
		} elseif ( (int) $a['folder_id'] > 0 ) {
			$ids = ! empty( $a['include_children'] )
				? MN_MM_Taxonomy::get_descendant_ids( (int) $a['folder_id'] )
				: array( (int) $a['folder_id'] );

			$tax_query[] = array(
				'taxonomy' => MN_MM_TAX_FOLDER,
				'field'    => 'term_id',
				'terms'    => $ids,
				'operator' => 'IN',
			);
		}

		if ( ! empty( $a['tag_ids'] ) ) {
			$tax_query[] = array(
				'taxonomy' => MN_MM_TAX_TAG,
				'field'    => 'term_id',
				'terms'    => array_map( 'intval', (array) $a['tag_ids'] ),
				'operator' => 'IN',
			);
		}

		/* 受限資料夾排除（select_all 批次同樣遵守，避免越權刪除） */
		$restricted = MN_MM_Taxonomy::restricted_folder_ids_for_user();
		if ( ! empty( $restricted ) ) {
			$tax_query[] = array(
				'taxonomy' => MN_MM_TAX_FOLDER,
				'field'    => 'term_id',
				'terms'    => $restricted,
				'operator' => 'NOT IN',
			);
		}

		if ( $tax_query ) {
			$tax_query['relation'] = 'AND';
			$q['tax_query']        = $tax_query;
		}

		switch ( $a['usage'] ) {
			case 'used':
				$q['meta_query'] = array( array( 'key' => MN_MM_META_USAGE, 'value' => '1', 'compare' => '=' ) );
				break;
			case 'unused':
				$q['meta_query'] = array( array( 'key' => MN_MM_META_USAGE, 'value' => '0', 'compare' => '=' ) );
				break;
			case 'unscanned':
				$q['meta_query'] = array( array( 'key' => MN_MM_META_USAGE, 'compare' => 'NOT EXISTS' ) );
				break;
		}

		$order   = ( 'ASC' === strtoupper( $a['order'] ) ) ? 'ASC' : 'DESC';
		$orderby = in_array( $a['orderby'], array( 'title', 'ID', 'modified', 'date', 'size' ), true ) ? $a['orderby'] : 'date';

		/*
		 * 穩定排序：同值（同一秒上傳、同名標題）時以 ID 收尾，
		 * 分頁才不會在邊界重複或漏掉媒體。
		 */
		if ( 'ID' === $orderby ) {
			$q['orderby'] = array( 'ID' => $order );
		} elseif ( 'size' === $orderby ) {
			/* 檔案尺寸排序：具名 meta 子句（meta_value_num）＋ ID 收尾。 */
			if ( ! isset( $q['meta_query'] ) || ! is_array( $q['meta_query'] ) ) {
				$q['meta_query'] = array();
			}
			$q['meta_query']['mn_size'] = array(
				'key'     => '_mn_mm_filesize',
				'type'    => 'NUMERIC',
				'compare' => 'EXISTS',
			);
			$q['orderby'] = array(
				'mn_size' => $order,
				'ID'      => $order,
			);
		} else {
			$q['orderby'] = array(
				$orderby => $order,
				'ID'     => $order,
			);
		}

		return $q;
	}

	/**
	 * 尚未建立尺寸索引（缺 _mn_mm_filesize）的附件數。
	 *
	 * @return int
	 */
	public static function filesize_missing() {
		global $wpdb;

		return (int) $wpdb->get_var(
			"SELECT COUNT(*)
			   FROM {$wpdb->posts} p
			   LEFT JOIN {$wpdb->postmeta} pm
			         ON pm.post_id = p.ID AND pm.meta_key = '_mn_mm_filesize'
			  WHERE p.post_type = 'attachment'
			    AND p.post_status IN ('inherit','private','publish')
			    AND pm.meta_id IS NULL"
		);
	}

	/**
	 * 類型群組 → MIME 清單。
	 *
	 * @param string $group
	 * @return string[]
	 */
	public static function mime_group_to_types( $group ) {
		switch ( $group ) {
			case 'image':
				return array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml', 'image/bmp', 'image/tiff' );
			case 'audio':
				return array( 'audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/m4a', 'audio/x-m4a', 'audio/wav', 'audio/x-wav', 'audio/ogg', 'audio/flac' );
			case 'video':
				return array( 'video/mp4', 'video/webm', 'video/quicktime', 'video/ogg', 'video/x-m4v' );
			case 'document':
				return array( 'application/pdf', 'text/plain', 'text/vtt', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' );
			default:
				return array();
		}
	}

	/**
	 * 單一媒體 → 前端資料格式。
	 *
	 * @param WP_Post $post
	 * @return array
	 */
	public static function format_item( $post ) {
		$id   = (int) $post->ID;
		$mime = (string) $post->post_mime_type;
		$meta = wp_get_attachment_metadata( $id );

		$file     = (string) get_post_meta( $id, '_wp_attached_file', true );
		$filename = $file ? basename( $file ) : '';
		$filesize = 0;

		if ( $file ) {
			$uploads = wp_get_upload_dir();
			$abs     = trailingslashit( $uploads['basedir'] ) . $file;
			if ( file_exists( $abs ) ) {
				$filesize = (int) filesize( $abs );
			}
		}

		$thumb = '';
		if ( 0 === strpos( $mime, 'image/' ) ) {
			$thumb = (string) wp_get_attachment_image_url( $id, 'thumbnail' );
			if ( ! $thumb ) {
				$thumb = (string) wp_get_attachment_url( $id );
			}
		}

		$used = get_post_meta( $id, MN_MM_META_USAGE, true );
		$used = ( '' === $used ) ? null : (int) $used;

		return array(
			'id'              => $id,
			'title'           => get_the_title( $id ),
			'filename'        => $filename,
			'url'             => (string) wp_get_attachment_url( $id ),
			'mime'            => $mime,
			'type'            => self::mime_group_of( $mime ),
			'ext'             => strtoupper( pathinfo( $filename, PATHINFO_EXTENSION ) ),
			'filesize'        => $filesize,
			'filesize_human'  => size_format( $filesize, 1 ),
			'width'           => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
			'height'          => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
			'thumb'           => $thumb,
			'date'            => $post->post_date,
			'date_human'      => mysql2date( 'Y-m-d', $post->post_date ),
			'author'          => (int) $post->post_author,
			'alt'             => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption'         => (string) $post->post_excerpt,
			'description'     => (string) $post->post_content,
			'folders'         => self::terms_of( $id, MN_MM_TAX_FOLDER ),
			'tags'            => self::terms_of( $id, MN_MM_TAX_TAG ),
			'used'            => $used,
		);
	}

	/**
	 * 取得媒體的分類項目。
	 *
	 * @param int    $id
	 * @param string $taxonomy
	 * @return array
	 */
	private static function terms_of( $id, $taxonomy ) {
		$terms = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'all' ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $t ) {
			$out[] = array(
				'term_id' => (int) $t->term_id,
				'name'    => $t->name,
				'slug'    => $t->slug,
			);
		}
		return $out;
	}

	/**
	 * MIME → 群組代號。
	 *
	 * @param string $mime
	 * @return string
	 */
	public static function mime_group_of( $mime ) {
		$mime = (string) $mime;
		if ( 0 === strpos( $mime, 'image/' ) ) {
			return 'image';
		}
		if ( 0 === strpos( $mime, 'audio/' ) ) {
			return 'audio';
		}
		if ( 0 === strpos( $mime, 'video/' ) ) {
			return 'video';
		}
		return 'other';
	}

	/**
	 * 整體統計。
	 *
	 * @return array
	 */
	public static function stats() {
		global $wpdb;

		$total = MN_MM_Usage::total();

		$base_status = "post_type = 'attachment' AND post_status IN ('inherit','private','publish')";

		/*
		 * 三個計數（未分類／使用中／未被引用）合併為一條查詢：
		 * 條件聚合（COUNT(DISTINCT CASE ...)），原本要跑三趟的掃描現在只要一趟。
		 * DISTINCT 防止一個媒體同時命中多個條件時重複計數。
		 */
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(DISTINCT CASE WHEN NOT EXISTS (
					       SELECT 1 FROM {$wpdb->term_relationships} tr
					       INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
					       WHERE tr.object_id = p.ID AND tt.taxonomy = %s
				   ) THEN p.ID END) AS unassigned,
					COUNT(DISTINCT CASE WHEN pm.meta_value = '1' THEN p.ID END) AS used,
					COUNT(DISTINCT CASE WHEN pm.meta_value = '0' THEN p.ID END) AS unused
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm
				       ON pm.post_id = p.ID AND pm.meta_key = %s
				 WHERE {$base_status}",
				MN_MM_TAX_FOLDER,
				MN_MM_META_USAGE
			),
			ARRAY_A
		);

		$unassigned = isset( $row['unassigned'] ) ? (int) $row['unassigned'] : 0;
		$used       = isset( $row['used'] ) ? (int) $row['used'] : 0;
		$unused     = isset( $row['unused'] ) ? (int) $row['unused'] : 0;

		$scanned = $used + $unused;

		return array(
			'total'            => $total,
			'unassigned'       => $unassigned,
			'used'             => $used,
			'unused'           => $unused,
			'scanned'          => $scanned,
			'unscanned'        => max( 0, $total - $scanned ),
			'filesize_missing' => self::filesize_missing(),
			'folders'          => count( (array) get_terms( array( 'taxonomy' => MN_MM_TAX_FOLDER, 'hide_empty' => false, 'fields' => 'ids' ) ) ),
			'tags'             => count( (array) get_terms( array( 'taxonomy' => MN_MM_TAX_TAG, 'hide_empty' => false, 'fields' => 'ids' ) ) ),
		);
	}
}
