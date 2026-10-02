<?php
/**
 * PlugNest Media Folders — 原生媒體庫整合。
 *
 * 不改寫原生媒體庫的介面，只在既有畫面上「加」東西：
 *   - 列表模式加上資料夾／標籤篩選下拉
 *   - 列表加上「資料夾」欄位
 *   - 提供一鍵跳转到完整管理頁
 *
 * 這樣平常在原生媒體庫工作的人不會被打斷，
 * 需要大規模整理時再進專屬頁面。
 *
 * @package PlugNest Media Folders
 */

defined( 'ABSPATH' ) || exit;

class MN_MM_Native {

	/** @var MN_MM_Native|null */
	private static $instance = null;

	/** @return MN_MM_Native */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'restrict_manage_posts', array( $this, 'filter_dropdowns' ) );
		add_filter( 'manage_media_columns', array( $this, 'add_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'apply_filter' ) );
		add_action( 'admin_notices', array( $this, 'admin_notice' ) );
	}

	/**
	 * 是否在原生媒體庫列表頁。
	 *
	 * @return bool
	 */
	private function is_media_list_screen() {
		global $pagenow;
		return is_admin() && 'upload.php' === $pagenow;
	}

	/**
	 * 篩選下拉。
	 */
	public function filter_dropdowns() {
		if ( ! $this->is_media_list_screen() ) {
			return;
		}

		$current_folder = isset( $_GET['mn_mm_folder'] ) ? sanitize_text_field( wp_unslash( $_GET['mn_mm_folder'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$folders = MN_MM_Taxonomy::get_folder_tree( true );

		echo '<label for="mn-mm-folder-filter" class="screen-reader-text">' . esc_html__( 'Filter by folder', 'plugnest-media-folders' ) . '</label>';
		echo '<select name="mn_mm_folder" id="mn-mm-folder-filter">';
		echo '<option value="">' . esc_html__( 'All folders', 'plugnest-media-folders' ) . '</option>';
		echo '<option value="none"' . selected( $current_folder, 'none', false ) . '>' . esc_html__( 'Unassigned', 'plugnest-media-folders' ) . '</option>';

		foreach ( $folders as $f ) {
			$prefix = str_repeat( '&nbsp;&nbsp;&nbsp;', (int) $f['depth'] );
			printf(
				'<option value="%d"%s>%s%s (%d)</option>',
				(int) $f['term_id'],
				selected( $current_folder, (string) $f['term_id'], false ),
				$prefix, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( $f['name'] ),
				(int) $f['count']
			);
		}
		echo '</select>';

		/* 標籤篩選 */
		$tags = get_terms(
			array(
				'taxonomy'   => MN_MM_TAX_TAG,
				'hide_empty' => false,
			)
		);

		if ( ! is_wp_error( $tags ) && ! empty( $tags ) ) {
			$current_tag = isset( $_GET['mn_mm_tag'] ) ? (int) $_GET['mn_mm_tag'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			echo '<label for="mn-mm-tag-filter" class="screen-reader-text">' . esc_html__( 'Filter by tag', 'plugnest-media-folders' ) . '</label>';
			echo '<select name="mn_mm_tag" id="mn-mm-tag-filter">';
			echo '<option value="0">' . esc_html__( 'All tags', 'plugnest-media-folders' ) . '</option>';
			foreach ( $tags as $t ) {
				printf(
					'<option value="%d"%s>%s (%d)</option>',
					(int) $t->term_id,
					selected( $current_tag, (int) $t->term_id, false ),
					esc_html( $t->name ),
					(int) $t->count
				);
			}
			echo '</select>';
		}

		/* 使用狀態 */
		$current_usage = isset( $_GET['mn_mm_usage'] ) ? sanitize_text_field( wp_unslash( $_GET['mn_mm_usage'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<label for="mn-mm-usage-filter" class="screen-reader-text">' . esc_html__( 'Filter by usage status', 'plugnest-media-folders' ) . '</label>';
		echo '<select name="mn_mm_usage" id="mn-mm-usage-filter">';
		printf( '<option value=""%s>%s</option>', selected( $current_usage, '', false ), esc_html__( 'Usage status: All', 'plugnest-media-folders' ) );
		printf( '<option value="used"%s>%s</option>', selected( $current_usage, 'used', false ), esc_html__( 'In use', 'plugnest-media-folders' ) );
		printf( '<option value="unused"%s>%s</option>', selected( $current_usage, 'unused', false ), esc_html__( 'Unused', 'plugnest-media-folders' ) );
		printf( '<option value="unscanned"%s>%s</option>', selected( $current_usage, 'unscanned', false ), esc_html__( 'Not scanned', 'plugnest-media-folders' ) );
		echo '</select>';

		/* 管理頁連結 */
		$url = admin_url( 'admin.php?page=plugnest-media-folders' );
		echo '<a href="' . esc_url( $url ) . '" class="button" style="margin-left:6px;">' . esc_html__( 'Open PlugNest Media Folders', 'plugnest-media-folders' ) . '</a>';
	}

	/**
	 * 加上「資料夾」欄位。
	 *
	 * @param array $columns
	 * @return array
	 */
	public function add_column( $columns ) {
		if ( ! $this->is_media_list_screen() ) {
			return $columns;
		}

		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['mn_mm_folder'] = __( 'Folder', 'plugnest-media-folders' );
			}
		}
		return $new;
	}

	/**
	 * 欄位內容。
	 *
	 * @param string $column
	 * @param int    $post_id
	 */
	public function render_column( $column, $post_id ) {
		if ( 'mn_mm_folder' !== $column ) {
			return;
		}

		$terms = wp_get_object_terms( (int) $post_id, MN_MM_TAX_FOLDER, array( 'fields' => 'names' ) );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			echo '<span style="color:#b32d2e;">' . esc_html__( 'Unassigned', 'plugnest-media-folders' ) . '</span>';
			return;
		}

		echo esc_html( implode( ', ', $terms ) );
	}

	/**
	 * 套用篩選條件。
	 *
	 * @param WP_Query $query
	 */
	public function apply_filter( $query ) {
		if ( ! $this->is_media_list_screen() || ! $query->is_main_query() ) {
			return;
		}
		if ( 'attachment' !== $query->get( 'post_type' ) ) {
			return;
		}

		$tax_query = (array) $query->get( 'tax_query' );

		/* 資料夾 */
		$folder = isset( $_GET['mn_mm_folder'] ) ? sanitize_text_field( wp_unslash( $_GET['mn_mm_folder'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $folder ) {
			if ( 'none' === $folder ) {
				$tax_query[] = array(
					'taxonomy' => MN_MM_TAX_FOLDER,
					'operator' => 'NOT EXISTS',
				);
			} else {
				$tax_query[] = array(
					'taxonomy'         => MN_MM_TAX_FOLDER,
					'field'            => 'term_id',
					'terms'            => MN_MM_Taxonomy::get_descendant_ids( (int) $folder ),
					'include_children' => false,
				);
			}
		}

		/* 標籤 */
		$tag = isset( $_GET['mn_mm_tag'] ) ? (int) $_GET['mn_mm_tag'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $tag > 0 ) {
			$tax_query[] = array(
				'taxonomy' => MN_MM_TAX_TAG,
				'field'    => 'term_id',
				'terms'    => array( $tag ),
			);
		}

		if ( $tax_query ) {
			$tax_query['relation'] = 'AND';
			$query->set( 'tax_query', $tax_query );
		}

		/* 使用狀態 */
		$usage = isset( $_GET['mn_mm_usage'] ) ? sanitize_text_field( wp_unslash( $_GET['mn_mm_usage'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $usage ) {
			$meta_query = (array) $query->get( 'meta_query' );

			switch ( $usage ) {
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

			$query->set( 'meta_query', $meta_query );
		}
	}

	/**
	 * 首次使用提示。
	 */
	public function admin_notice() {
		if ( ! $this->is_media_list_screen() ) {
			return;
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		if ( get_option( 'mn_mm_notice_dismissed' ) ) {
			return;
		}
		if ( isset( $_GET['mn_mm_hide_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			update_option( 'mn_mm_notice_dismissed', 1, false );
			return;
		}

		$dismiss = add_query_arg( 'mn_mm_hide_notice', 1 );
		$manage  = admin_url( 'admin.php?page=plugnest-media-folders' );

		printf(
			'<div class="notice notice-info is-dismissible"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a>　<a href="%5$s">%6$s</a></p></div>',
			esc_html__( 'PlugNest Media Folders is now active.', 'plugnest-media-folders' ),
			esc_html__( 'Media can now be organized with folders and tags — file paths and URLs are never touched.', 'plugnest-media-folders' ),
			esc_url( $manage ),
			esc_html__( 'Open PlugNest Media Folders', 'plugnest-media-folders' ),
			esc_url( $dismiss ),
			esc_html__( 'Dismiss', 'plugnest-media-folders' )
		);
	}
}
