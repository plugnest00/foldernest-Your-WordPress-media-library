<?php
/**
 * PlugNest Media Folders — 自動分類引擎。
 *
 * 依「檔名 / 副檔名 / 類型 / 尺寸 / 年份」等條件，把媒體自動歸入資料夾。
 *
 * 兩個安全設計：
 *   1. 一定要先「試跑預覽」——先看清楚會怎麼分，確認了才真的寫入。
 *   2. 規則可設「只處理尚未分類的媒體」，預設開啟，避免覆蓋手動整理好的結果。
 *
 * 整個過程只寫入分類關係（term_relationships），不碰任何檔案。
 *
 * @package PlugNest Media Folders
 */

defined( 'ABSPATH' ) || exit;

class MN_MM_Auto {

	/** 規則存放的 option key。 */
	const RULES_OPTION = 'mn_mm_auto_rules';

	/** 工作狀態的 transient 前綴。 */
	const JOB_PREFIX = 'mn_mm_job_';

	/** 每批處理筆數。 */
	const BATCH = 120;

	/**
	 * 預設規則（通用版）。
	 *
	 * 以 MIME 類型分流的四條入門規則：Videos / Audio / Pictures / Documents。
	 * 只在使用者按下「套用」時才會建立資料夾（平時不建、不動任何媒體）；
	 * 已分類的媒體預設不動（only_unassigned）。
	 *
	 * @return array
	 */
	public static function default_rules() {
		$rule = static function ( $id, $label, $group ) {
			return array(
				'id'              => $id,
				'label'           => $label,
				'enabled'         => true,
				'only_unassigned' => true,
				'match'           => array(
					'filename_contains' => '',
					'filename_regex'    => '',
					'ext_in'            => '',
					'mime_group'        => $group,
					'min_width'         => 0,
					'max_width'         => 0,
					'year'              => 0,
				),
				'target_folder'   => $label,
				'add_tags'        => array(),
			);
		};

		return array(
			$rule( 'r_videos', __( 'Videos', 'plugnest-media-folders' ), 'video' ),
			$rule( 'r_audio', __( 'Audio', 'plugnest-media-folders' ), 'audio' ),
			$rule( 'r_pictures', __( 'Pictures', 'plugnest-media-folders' ), 'image' ),
			$rule( 'r_documents', __( 'Documents', 'plugnest-media-folders' ), 'document' ),
		);
	}

	/**
	 * 讀取規則（未設定過則回傳預設）。
	 *
	 * @return array
	 */
	public static function get_rules() {
		$rules = get_option( self::RULES_OPTION );
		if ( ! is_array( $rules ) || empty( $rules ) ) {
			return self::default_rules();
		}
		return $rules;
	}

	/**
	 * 儲存規則。
	 *
	 * @param array $rules
	 * @return array 正規化後的規則。
	 */
	public static function save_rules( $rules ) {
		$clean = array();
		$i     = 0;

		foreach ( (array) $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$i++;

			$match = isset( $rule['match'] ) && is_array( $rule['match'] ) ? $rule['match'] : array();

			$clean[] = array(
				'id'              => ! empty( $rule['id'] ) ? sanitize_key( $rule['id'] ) : ( 'r_' . $i . '_' . wp_generate_password( 4, false, false ) ),
				'label'           => sanitize_text_field( isset( $rule['label'] ) ? $rule['label'] : ( __( 'Rule', 'plugnest-media-folders' ) . ' ' . $i ) ),
				'enabled'         => ! empty( $rule['enabled'] ),
				'only_unassigned' => ! empty( $rule['only_unassigned'] ),
				'match'           => array(
					'filename_contains' => sanitize_text_field( isset( $match['filename_contains'] ) ? $match['filename_contains'] : '' ),
					'filename_regex'    => sanitize_text_field( isset( $match['filename_regex'] ) ? $match['filename_regex'] : '' ),
					'ext_in'            => sanitize_text_field( isset( $match['ext_in'] ) ? $match['ext_in'] : '' ),
					'mime_group'        => sanitize_text_field( isset( $match['mime_group'] ) ? $match['mime_group'] : '' ),
					'min_width'         => (int) ( isset( $match['min_width'] ) ? $match['min_width'] : 0 ),
					'max_width'         => (int) ( isset( $match['max_width'] ) ? $match['max_width'] : 0 ),
					'year'              => (int) ( isset( $match['year'] ) ? $match['year'] : 0 ),
				),
				'target_folder'   => sanitize_text_field( isset( $rule['target_folder'] ) ? $rule['target_folder'] : '' ),
				'add_tags'        => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( isset( $rule['add_tags'] ) ? $rule['add_tags'] : array() ) ) ) ),
			);
		}

		update_option( self::RULES_OPTION, $clean, false );
		return $clean;
	}

	/**
	 * 依路徑取得或建立資料夾，回傳 term_id。
	 *
	 * 例：'影音匯/ASMR' → 先確保「影音匯」存在，再確保其下的「ASMR」存在。
	 *
	 * @param string $path
	 * @return int
	 */
	public static function ensure_folder_path( $path ) {
		$parts = array_filter( array_map( 'trim', explode( '/', (string) $path ) ) );
		if ( empty( $parts ) ) {
			return 0;
		}

		$parent  = 0;
		$term_id = 0;

		foreach ( $parts as $name ) {
			$existing = term_exists( $name, MN_MM_TAX_FOLDER, $parent );

			if ( $existing ) {
				$term_id = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
			} else {
				$new = wp_insert_term( $name, MN_MM_TAX_FOLDER, array( 'parent' => $parent ) );
				if ( is_wp_error( $new ) ) {
					return 0;
				}
				$term_id = (int) $new['term_id'];
			}

			$parent = $term_id;
		}

		return $term_id;
	}

	/**
	 * 列出所有資料夾的完整路徑（供規則設定介面使用）。
	 *
	 * @return array array( array( 'path' => '影音匯/ASMR', 'term_id' => 12, 'depth' => 1 ), … )
	 */
	public static function get_folder_paths() {
		$terms = get_terms(
			array(
				'taxonomy'   => MN_MM_TAX_FOLDER,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$by_id = array();
		foreach ( $terms as $t ) {
			$by_id[ (int) $t->term_id ] = $t;
		}

		$out = array();
		foreach ( $terms as $t ) {
			$path  = array( $t->name );
			$guard = 0;
			$p     = (int) $t->parent;

			while ( $p > 0 && isset( $by_id[ $p ] ) && $guard < 20 ) {
				array_unshift( $path, $by_id[ $p ]->name );
				$p = (int) $by_id[ $p ]->parent;
				$guard++;
			}

			$out[] = array(
				'path'    => implode( '/', $path ),
				'term_id' => (int) $t->term_id,
				'depth'   => count( $path ) - 1,
			);
		}

		usort(
			$out,
			function ( $a, $b ) {
				return strcmp( $a['path'], $b['path'] );
			}
		);

		return $out;
	}

	/**
	 * 取出候選媒體的輕量資料（一次查詢，避免 N+1）。
	 *
	 * @param array $scope 範圍條件。
	 * @return array array( array( 'id','filename','ext','mime','type','date','assigned' ), … )
	 */
	public static function candidates( $scope ) {
		global $wpdb;

		$ids = MN_MM_Query::get_ids( $scope );
		if ( empty( $ids ) ) {
			return array();
		}

		$id_list      = implode( ',', array_map( 'intval', $ids ) );
		$status_in    = "'inherit','private','publish'";

		/* 一次撈出檔名、MIME、日期。 */
		$rows = $wpdb->get_results(
			"SELECT p.ID, p.post_mime_type, p.post_date, pm.meta_value AS file
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
			 WHERE p.ID IN ({$id_list})
			   AND p.post_type = 'attachment'
			   AND p.post_status IN ({$status_in})",
			ARRAY_A
		);

		if ( ! $rows ) {
			return array();
		}

		/* 已指派的資料夾（用來判斷 only_unassigned）。 */
		$assigned = array();
		$rel_rows = $wpdb->get_results(
			"SELECT tr.object_id, tt.term_id
			 FROM {$wpdb->term_relationships} tr
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			 WHERE tt.taxonomy = '" . esc_sql( MN_MM_TAX_FOLDER ) . "'
			   AND tr.object_id IN ({$id_list})",
			ARRAY_A
		);
		if ( $rel_rows ) {
			foreach ( $rel_rows as $r ) {
				$assigned[ (int) $r['object_id'] ] = 1;
			}
		}

		$out = array();
		foreach ( $rows as $row ) {
			$id   = (int) $row['ID'];
			$file = (string) $row['file'];
			$mime = (string) $row['post_mime_type'];

			$out[] = array(
				'id'       => $id,
				'filename' => $file ? basename( $file ) : '',
				'ext'      => strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ),
				'mime'     => $mime,
				'type'     => MN_MM_Query::mime_group_of( $mime ),
				'date'     => (string) $row['post_date'],
				'assigned' => isset( $assigned[ $id ] ),
			);
		}

		return $out;
	}

	/**
	 * 判斷單一規則是否命中。
	 *
	 * @param array $rule
	 * @param array $ctx 候選資料。
	 * @return bool
	 */
	public static function rule_matches( $rule, $ctx ) {
		$m = isset( $rule['match'] ) && is_array( $rule['match'] ) ? $rule['match'] : array();

		/* 檔名關鍵字（任一命中即可） */
		if ( ! empty( $m['filename_contains'] ) ) {
			$needles = array_filter( array_map( 'trim', explode( ',', $m['filename_contains'] ) ) );
			$hit     = false;
			foreach ( $needles as $needle ) {
				if ( self::stripos_utf8( $ctx['filename'], $needle ) !== false ) {
					$hit = true;
					break;
				}
			}
			if ( ! $hit ) {
				return false;
			}
		}

		/* 檔名正則 */
		if ( ! empty( $m['filename_regex'] ) ) {
			$pattern = '/' . str_replace( '/', '\/', $m['filename_regex'] ) . '/iu';
			$result  = @preg_match( $pattern, $ctx['filename'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $result ) {
				// 正則無效 → 退化成子字串比對，避免整條規則默默失效。
				if ( self::stripos_utf8( $ctx['filename'], $m['filename_regex'] ) === false ) {
					return false;
				}
			} elseif ( 0 === $result ) {
				return false;
			}
		}

		/* 副檔名 */
		if ( ! empty( $m['ext_in'] ) ) {
			$exts = array_filter(
				array_map(
					function ( $e ) {
						return strtolower( trim( $e, " .\t\n\r" ) );
					},
					explode( ',', $m['ext_in'] )
				)
			);
			if ( ! in_array( $ctx['ext'], $exts, true ) ) {
				return false;
			}
		}

		/* 類型群組 */
		if ( ! empty( $m['mime_group'] ) ) {
			if ( $ctx['type'] !== $m['mime_group'] ) {
				return false;
			}
		}

		/* 年份 */
		if ( ! empty( $m['year'] ) ) {
			if ( (int) substr( $ctx['date'], 0, 4 ) !== (int) $m['year'] ) {
				return false;
			}
		}

		/* 寬度（需要時才載入 metadata） */
		if ( ! empty( $m['min_width'] ) || ! empty( $m['max_width'] ) ) {
			$meta   = wp_get_attachment_metadata( $ctx['id'] );
			$width  = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
			if ( ! empty( $m['min_width'] ) && $width < (int) $m['min_width'] ) {
				return false;
			}
			if ( ! empty( $m['max_width'] ) && $width > (int) $m['max_width'] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * 找出第一個命中的規則。
	 *
	 * @param array $rules
	 * @param array $ctx
	 * @return array|null
	 */
	public static function first_match( $rules, $ctx ) {
		foreach ( $rules as $rule ) {
			if ( empty( $rule['enabled'] ) ) {
				continue;
			}
			if ( self::rule_matches( $rule, $ctx ) ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * 試跑預覽：完全不寫入任何資料。
	 *
	 * @param array $rules
	 * @param array $scope
	 * @return array
	 */
	public static function preview( $rules, $scope ) {
		$candidates = self::candidates( $scope );

		$per_rule = array();
		foreach ( $rules as $rule ) {
			$per_rule[ $rule['id'] ] = array(
				'id'      => $rule['id'],
				'label'   => $rule['label'],
				'target'  => $rule['target_folder'],
				'count'   => 0,
				'skipped' => 0,
				'samples' => array(),
			);
		}

		$unmatched = 0;
		$skipped   = 0;

		foreach ( $candidates as $ctx ) {
			$rule = self::first_match( $rules, $ctx );

			if ( ! $rule ) {
				$unmatched++;
				continue;
			}

			/* 只處理未分類時，已分類的媒體會被跳過。 */
			if ( ! empty( $rule['only_unassigned'] ) && ! empty( $ctx['assigned'] ) ) {
				$per_rule[ $rule['id'] ]['skipped']++;
				$skipped++;
				continue;
			}

			$per_rule[ $rule['id'] ]['count']++;
			if ( count( $per_rule[ $rule['id'] ]['samples'] ) < 8 ) {
				$per_rule[ $rule['id'] ]['samples'][] = array(
					'id'       => $ctx['id'],
					'filename' => $ctx['filename'],
				);
			}
		}

		return array(
			'candidates' => count( $candidates ),
			'rules'      => array_values( $per_rule ),
			'unmatched'  => $unmatched,
			'skipped'    => $skipped,
			'will_apply' => max( 0, count( $candidates ) - $unmatched - $skipped ),
		);
	}

	/**
	 * 建立自動分類工作，並寫入一筆「進行中」的操作記錄。
	 *
	 * @param array $rules
	 * @param array $scope
	 * @return array|WP_Error
	 */
	public static function start_job( $rules, $scope ) {
		global $wpdb;

		$candidates = self::candidates( $scope );
		if ( empty( $candidates ) ) {
			return new WP_Error( 'no_candidates', __( 'No media in scope.', 'plugnest-media-folders' ) );
		}

		/* 先寫一筆空的記錄，之後每批把明細 append 進去。 */
		$table = MN_MM_Plugin::log_table();
		$wpdb->insert(
			$table,
			array(
				'created_at'   => current_time( 'mysql' ),
				'user_id'      => get_current_user_id(),
				'action'       => 'auto_classify',
				'object_count' => 0,
				'payload'      => wp_json_encode(
					array(
						'context'  => array( 'scope' => $scope ),
						'items'    => array(),
						'undoable' => true,
					)
				),
				'undone'       => 0,
			),
			array( '%s', '%d', '%s', '%d', '%s', '%d' )
		);

		$log_id = (int) $wpdb->insert_id;

		$job = array(
			'log_id'     => $log_id,
			'offset'     => 0,
			'total'      => count( $candidates ),
			'applied'    => 0,
			'skipped'    => 0,
			'unmatched'  => 0,
			'items'      => array(),   // 累積的還原明細
			'rules'      => $rules,
			'scope'      => $scope,
			'started_at' => time(),
		);

		set_transient( self::JOB_PREFIX . get_current_user_id(), $job, HOUR_IN_SECONDS );

		return array(
			'log_id' => $log_id,
			'total'  => $job['total'],
			'done'   => false,
		);
	}

	/**
	 * 執行一批。
	 *
	 * @return array|WP_Error
	 */
	public static function step_job() {
		global $wpdb;

		$key = self::JOB_PREFIX . get_current_user_id();
		$job = get_transient( $key );

		if ( ! is_array( $job ) ) {
			return new WP_Error( 'no_job', __( 'No auto-assign job is running.', 'plugnest-media-folders' ) );
		}

		$candidates = self::candidates( isset( $job['scope'] ) ? $job['scope'] : array() );

		/* 用 offset 從候選清單切片。 */
		$slice = array_slice( $candidates, (int) $job['offset'], self::BATCH );

		if ( empty( $slice ) ) {
			return self::finish_job( $job, $key );
		}

		$rules = $job['rules'];

		/* 先把每條規則的目標資料夾準備好（同一規則只建立一次）。 */
		$folder_cache = array();
		$tag_cache    = array();

		foreach ( $slice as $ctx ) {
			$rule = self::first_match( $rules, $ctx );

			if ( ! $rule ) {
				$job['unmatched']++;
				continue;
			}

			if ( ! empty( $rule['only_unassigned'] ) && ! empty( $ctx['assigned'] ) ) {
				$job['skipped']++;
				continue;
			}

			$target = (string) $rule['target_folder'];
			if ( '' === $target ) {
				$job['unmatched']++;
				continue;
			}

			if ( ! isset( $folder_cache[ $target ] ) ) {
				$folder_cache[ $target ] = self::ensure_folder_path( $target );
			}
			$folder_id = $folder_cache[ $target ];

			if ( $folder_id <= 0 ) {
				$job['unmatched']++;
				continue;
			}

			/* 快照：還原用。 */
			$before_terms = array_map( 'intval', (array) wp_get_object_terms( $ctx['id'], MN_MM_TAX_FOLDER, array( 'fields' => 'ids' ) ) );
			$before_tags  = array_map( 'intval', (array) wp_get_object_terms( $ctx['id'], MN_MM_TAX_TAG, array( 'fields' => 'ids' ) ) );

			/* 指派資料夾（附加，不移除既有——避免破壞手動分類）。 */
			wp_set_object_terms( $ctx['id'], array( $folder_id ), MN_MM_TAX_FOLDER, true );

			/* 附加標籤。 */
			$tags = isset( $rule['add_tags'] ) ? (array) $rule['add_tags'] : array();
			if ( $tags ) {
				wp_set_object_terms( $ctx['id'], $tags, MN_MM_TAX_TAG, true );
			}

			$job['items'][] = array(
				'id'     => $ctx['id'],
				'before' => array(
					'terms' => array(
						MN_MM_TAX_FOLDER => $before_terms,
						MN_MM_TAX_TAG    => $before_tags,
					),
				),
				'after'  => array(
					'terms' => array(
						MN_MM_TAX_FOLDER => array( $folder_id ),
					),
				),
			);

			$job['applied']++;
		}

		$job['offset'] = (int) $job['offset'] + count( $slice );

		/* 把明細寫回記錄（每批更新一次）。 */
		self::persist_items( $job['log_id'], $job['items'] );

		set_transient( $key, $job, HOUR_IN_SECONDS );

		$done = $job['offset'] >= $job['total'];

		if ( $done ) {
			return self::finish_job( $job, $key );
		}

		return array(
			'log_id'    => $job['log_id'],
			'total'     => $job['total'],
			'processed' => $job['offset'],
			'applied'   => $job['applied'],
			'skipped'   => $job['skipped'],
			'unmatched' => $job['unmatched'],
			'done'      => false,
		);
	}

	/**
	 * 收尾。
	 *
	 * @param array  $job
	 * @param string $key
	 * @return array
	 */
	private static function finish_job( $job, $key ) {
		self::persist_items( $job['log_id'], $job['items'] );
		delete_transient( $key );

		return array(
			'log_id'    => $job['log_id'],
			'total'     => $job['total'],
			'processed' => $job['offset'],
			'applied'   => $job['applied'],
			'skipped'   => $job['skipped'],
			'unmatched' => $job['unmatched'],
			'done'      => true,
		);
	}

	/**
	 * 把明細寫回操作記錄。
	 *
	 * @param int   $log_id
	 * @param array $items
	 */
	private static function persist_items( $log_id, $items ) {
		global $wpdb;

		$log_id = (int) $log_id;
		if ( $log_id <= 0 ) {
			return;
		}

		$table = MN_MM_Plugin::log_table();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT payload FROM {$table} WHERE id = %d", $log_id ),
			ARRAY_A
		);

		$data = $row ? json_decode( (string) $row['payload'], true ) : array();
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$data['items']    = $items;
		$data['undoable'] = true;

		$wpdb->update(
			$table,
			array(
				'payload'      => wp_json_encode( $data ),
				'object_count' => count( $items ),
			),
			array( 'id' => $log_id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * 取消進行中的工作。
	 *
	 * @return bool
	 */
	public static function cancel_job() {
		$key = self::JOB_PREFIX . get_current_user_id();
		$job = get_transient( $key );
		if ( is_array( $job ) ) {
			self::persist_items( $job['log_id'], $job['items'] );
			delete_transient( $key );
			return true;
		}
		return false;
	}

	/**
	 * UTF-8 友善的不分大小寫搜尋。
	 *
	 * @param string $haystack
	 * @param string $needle
	 * @return int|false
	 */
	private static function stripos_utf8( $haystack, $needle ) {
		if ( '' === $needle ) {
			return false;
		}
		if ( function_exists( 'mb_stripos' ) ) {
			return mb_stripos( $haystack, $needle, 0, 'UTF-8' );
		}
		return stripos( $haystack, $needle );
	}
}
