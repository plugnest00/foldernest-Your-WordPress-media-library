<?php
/**
 * FolderNest — 後台管理頁面。
 *
 * @package FolderNest
 */

defined( 'ABSPATH' ) || exit;

class MN_MM_Admin {

	/** @var MN_MM_Admin|null */
	private static $instance = null;

	/** 選單 slug。 */
	const SLUG = 'foldernest';

	/** @return MN_MM_Admin */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'block_editor_assets' ) );
	}

	/**
	 * 選單項目。
	 *
	 * 圖標用外掛自帶的原創 nest SVG（data URI）：後台深淺色側欄都清晰，
	 * 不依賴 dashicons、也沒有版權顧慮。
	 */
	public function menu() {
		$icon = 'dashicons-images-alt2';
		$svg  = MN_MM_PATH . 'assets/icon.svg';
		if ( is_readable( $svg ) ) {
			$icon = 'data:image/svg+xml;base64,' . base64_encode( (string) file_get_contents( $svg ) );
		}

		add_menu_page(
			__( 'FolderNest', 'foldernest' ),
			__( 'FolderNest', 'foldernest' ),
			'upload_files',
			self::SLUG,
			array( $this, 'render_page' ),
			$icon,
			58
		);
	}

	/**
	 * 是否為本外掛頁面。
	 *
	 * @return bool
	 */
	private function is_plugin_page() {
		return isset( $_GET['page'] ) && self::SLUG === $_GET['page']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * 資源版本號。
	 *
	 * 以「檔案修改時間」為準，覆蓋檔案後瀏覽器快取會自動失效，
	 * 不必每次改 CSS/JS 都記得手動升版號（漏升就會看到舊畫面）。
	 * 取不到檔案時退回版號常數。
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
	 * 載入資源。
	 *
	 * @param string $hook
	 */
	public function assets( $hook ) {
		if ( ! $this->is_plugin_page() ) {
			return;
		}

		/*
		 * 頁內上傳（方案 A）：確保核心媒體腳本與 _wpMediaViewsL10n（上傳 nonce、
		 * 大小上限、mime 白名單）都在 —— 沒有這個，開出來的媒體框無法上傳。
		 * 頁面上本來就有部分媒體腳本（wp.media / plupload），wp_enqueue_media()
		 * 補的是設定與缺的 handle；已載入的重複 handle 不會印第二次。
		 */
		if ( current_user_can( 'upload_files' ) ) {
			wp_enqueue_media();
		}

		wp_enqueue_style(
			'mn-mm-admin',
			MN_MM_URL . 'assets/css/admin.css',
			array(),
			$this->asset_ver( 'assets/css/admin.css' )
		);

		wp_enqueue_script(
			'mn-mm-admin',
			MN_MM_URL . 'assets/js/admin.js',
			array( 'wp-i18n' ),
			$this->asset_ver( 'assets/js/admin.js' ),
			true
		);
		wp_set_script_translations( 'mn-mm-admin', 'foldernest', MN_MM_PATH . 'languages' );

		wp_localize_script(
			'mn-mm-admin',
			'MN_MM',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'mn_mm' ),
				'uploadNonce' => wp_create_nonce( MN_MM_Upload::NONCE_ACTION ),
				'pro'         => MN_MM_Plugin::is_pro() ? 1 : 0,
				'buyUrl'      => 'https://foldernest.plugnest.dev/',
				'proFeatures' => array(
					__( 'Unused-media cleanup wizard', 'foldernest' ),
					__( 'Missing-alt batch workflow', 'foldernest' ),
					__( 'Folder import/export & import from other folder plugins', 'foldernest' ),
					__( 'Folder access roles', 'foldernest' ),
					__( 'Delete folder together with its files (typed confirmation)', 'foldernest' ),
				),
				'roles'       => MN_MM_Taxonomy::get_role_names(),
				'perPage'     => 60,
				'folders'     => MN_MM_Taxonomy::get_folder_tree( true ),
				'tags'        => $this->get_tags(),
				'stats'       => MN_MM_Query::stats(),
				'rules'       => MN_MM_Auto::get_rules(),
				'folderPaths' => MN_MM_Auto::get_folder_paths(),
				'editUrl'     => admin_url( 'post.php' ),
				'uploadUrl'   => admin_url( 'upload.php' ),
				'strings'     => array(
					'confirmDeleteFolder' => __( 'Delete this folder?

Media and physical files inside are kept; subfolders move up one level.', 'foldernest' ),
					'confirmDeleteTag'    => __( 'Delete this tag? The media itself is not affected.', 'foldernest' ),
					'confirmApply'        => __( 'Apply auto-assign? You can undo it from Activity Log afterwards.', 'foldernest' ),
					'noSelection'         => __( 'Select some media first.', 'foldernest' ),
					'loading'             => __( 'Loading…', 'foldernest' ),
					'noResult'            => __( 'No media matches the current filters.', 'foldernest' ),
				),
			)
		);
	}

	/**
	 * 標籤清單。
	 *
	 * @return array
	 */
	private function get_tags() {
		$terms = get_terms(
			array(
				'taxonomy'   => MN_MM_TAX_TAG,
				'hide_empty' => false,
			)
		);
		$out = array();
		if ( is_wp_error( $terms ) ) {
			return $out;
		}
		foreach ( $terms as $t ) {
			$out[] = array(
				'term_id' => (int) $t->term_id,
				'name'    => $t->name,
				'count'   => (int) $t->count,
			);
		}
		return $out;
	}

	/**
	 * Gutenberg 編輯器資源：文件設定側欄的「媒體資料夾」面板。
	 */
	public function block_editor_assets() {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		wp_enqueue_script(
			'mn-mm-gutenberg',
			MN_MM_URL . 'assets/js/gutenberg.js',
			array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-data', 'wp-i18n' ),
			$this->asset_ver( 'assets/js/gutenberg.js' ),
			true
		);
		wp_set_script_translations( 'mn-mm-gutenberg', 'foldernest', MN_MM_PATH . 'languages' );

		wp_localize_script(
			'mn-mm-gutenberg',
			'MN_MM_GUT',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'mn_mm' ),
				'folders' => MN_MM_Taxonomy::get_folder_tree( true ),
			)
		);
	}

	/**
	 * 頁面骨架。
	 */
	public function render_page() {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'Your account does not have permission to manage media.', 'foldernest' ) );
		}
		?>
		<div class="wrap mn-mm-wrap">

			<div class="mn-mm-header">
				<div class="mn-mm-header-left">
					<h1 class="mn-mm-title">
						<?php esc_html_e( 'FolderNest', 'foldernest' ); ?>
						<span class="mn-mm-safe-badge" title="<?php esc_attr_e( 'This plugin only writes classification data; it never moves or rewrites media files.', 'foldernest' ); ?>"><?php esc_html_e( 'File paths untouched', 'foldernest' ); ?></span>
					</h1>
					<p class="mn-mm-sub"><?php esc_html_e( 'Organize media with folders and tags. Everything writes to the database only — media URLs and physical files never change.', 'foldernest' ); ?></p>
				</div>
				<div class="mn-mm-header-actions">
					<button type="button" class="button button-primary" id="mn-mm-btn-upload">
						<span class="dashicons dashicons-upload"></span> <?php esc_html_e( 'Upload media', 'foldernest' ); ?>
					</button>
					<?php $mn_mm_pro_locked = MN_MM_Plugin::is_pro() ? '' : ' mn-mm-pro-locked'; ?>
					<button type="button" class="button<?php echo esc_attr( $mn_mm_pro_locked ); ?>" id="mn-mm-btn-cleanup" title="<?php esc_attr_e( 'Pro feature', 'foldernest' ); ?>">
						<span class="dashicons dashicons-editor-unlink"></span> <?php esc_html_e( 'Clean up unused', 'foldernest' ); ?>
					</button>
					<button type="button" class="button<?php echo esc_attr( $mn_mm_pro_locked ); ?>" id="mn-mm-btn-alt" title="<?php esc_attr_e( 'Pro feature', 'foldernest' ); ?>">
						<span class="dashicons dashicons-universal-access-alt"></span> <?php esc_html_e( 'Missing alt', 'foldernest' ); ?>
					</button>
					<button type="button" class="button" id="mn-mm-btn-scan">
						<span class="dashicons dashicons-search"></span> <?php esc_html_e( 'Scan usage', 'foldernest' ); ?>
					</button>
					<button type="button" class="button" id="mn-mm-btn-auto">
						<span class="dashicons dashicons-networking"></span> <?php esc_html_e( 'Auto-assign', 'foldernest' ); ?>
					</button>
					<button type="button" class="button" id="mn-mm-btn-logs">
						<span class="dashicons dashicons-backup"></span> <?php esc_html_e( 'Activity log', 'foldernest' ); ?>
					</button>
				</div>
			</div>

			<div class="mn-mm-stats" id="mn-mm-stats"></div>

			<!-- ============ 頁內上傳列（方案 B：拖放區） ============ -->
			<div class="mn-mm-uploadbar" id="mn-mm-upload-dropzone">
				<button type="button" class="button button-primary" id="mn-mm-upload-browse">
					<span class="dashicons dashicons-upload"></span> <?php esc_html_e( 'Select files to upload', 'foldernest' ); ?>
				</button>
				<span class="mn-mm-uploadbar-hint">
					<?php
					/* 提示內含強調標籤，拆成三段輸出。 */
					echo esc_html__( 'Or drop files here — actually', 'foldernest' ) . ' <strong>' . esc_html__( 'the whole page is a dropzone', 'foldernest' ) . '</strong>: ' . esc_html__( 'drop onto a folder row and the files land in that folder; drop anywhere else and they land in', 'foldernest' ) . ' <strong id="mn-mm-upload-target">' . esc_html__( 'the folder you are browsing', 'foldernest' ) . '</strong>' . esc_html__( ' (it follows the folder selected on the left).', 'foldernest' );
					?>
				</span>
				<div class="mn-mm-uploadbar-progress" id="mn-mm-upload-progress" hidden>
					<span class="mn-mm-uploadbar-progress-text" id="mn-mm-upload-progress-text"></span>
					<div class="mn-mm-progress-bar"><span id="mn-mm-upload-progress-fill"></span></div>
				</div>
			</div>

			<!-- ============ 資料夾樹的資料夾列動作由 JS 生成；roles 鈕為 🔒 ============ -->

			<div class="mn-mm-layout">

				<!-- ============ 左：資料夾樹 ============ -->
				<aside class="mn-mm-panel mn-mm-sidebar">
					<div class="mn-mm-panel-head">
						<span><?php esc_html_e( 'Folders', 'foldernest' ); ?></span>
					<button type="button" class="mn-mm-icon-btn" id="mn-mm-add-folder" title="<?php esc_attr_e( 'Add new folder', 'foldernest' ); ?>">＋</button>
						</div>
					<div class="mn-mm-exportbar">
						<button type="button" class="button-link mn-mm-mini-link<?php echo esc_attr( $mn_mm_pro_locked ); ?>" id="mn-mm-export-folders"><?php esc_html_e( 'Export', 'foldernest' ); ?></button>
						<span>·</span>
						<button type="button" class="button-link mn-mm-mini-link<?php echo esc_attr( $mn_mm_pro_locked ); ?>" id="mn-mm-import-folders"><?php esc_html_e( 'Import', 'foldernest' ); ?></button>
						<span>·</span>
						<button type="button" class="button-link mn-mm-mini-link<?php echo esc_attr( $mn_mm_pro_locked ); ?>" id="mn-mm-import-plugins"><?php esc_html_e( 'From plugins', 'foldernest' ); ?></button>
					</div>
								<ul class="mn-mm-tree" id="mn-mm-tree"></ul>

					<div class="mn-mm-panel-head mn-mm-panel-head--sub">
						<span><?php esc_html_e( 'Tags', 'foldernest' ); ?></span>
						<button type="button" class="mn-mm-icon-btn" id="mn-mm-add-tag" title="<?php esc_attr_e( 'Add new tag', 'foldernest' ); ?>">＋</button>
					</div>
					<div class="mn-mm-tags" id="mn-mm-tags"></div>
				</aside>

				<!-- ============ 中：媒體網格 ============ -->
				<section class="mn-mm-panel mn-mm-main">

					<div class="mn-mm-toolbar">
						<input type="search" id="mn-mm-search" class="mn-mm-search" placeholder="<?php esc_attr_e( 'Search filename or title…', 'foldernest' ); ?>">

						<select id="mn-mm-type">
							<option value=""><?php esc_html_e( 'All types', 'foldernest' ); ?></option>
							<option value="image"><?php esc_html_e( 'Image', 'foldernest' ); ?></option>
							<option value="audio"><?php esc_html_e( 'Audio', 'foldernest' ); ?></option>
							<option value="video"><?php esc_html_e( 'Video', 'foldernest' ); ?></option>
							<option value="document"><?php esc_html_e( 'Document', 'foldernest' ); ?></option>
						</select>

						<select id="mn-mm-usage">
							<option value=""><?php esc_html_e( 'Usage status: All', 'foldernest' ); ?></option>
							<option value="used"><?php esc_html_e( 'In use', 'foldernest' ); ?></option>
							<option value="unused"><?php esc_html_e( 'Unused', 'foldernest' ); ?></option>
							<option value="unscanned"><?php esc_html_e( 'Not scanned', 'foldernest' ); ?></option>
						</select>

						<select id="mn-mm-sort">
							<option value="date-DESC"><?php esc_html_e( 'Newest first', 'foldernest' ); ?></option>
							<option value="date-ASC"><?php esc_html_e( 'Oldest first', 'foldernest' ); ?></option>
							<option value="title-ASC"><?php esc_html_e( 'Title A→Z', 'foldernest' ); ?></option>
							<option value="title-DESC"><?php esc_html_e( 'Title Z→A', 'foldernest' ); ?></option>
							<option value="size-DESC"><?php esc_html_e( 'File size high→low', 'foldernest' ); ?></option>
							<option value="size-ASC"><?php esc_html_e( 'File size low→high', 'foldernest' ); ?></option>
							<option value="ID-DESC"><?php esc_html_e( 'ID high→low', 'foldernest' ); ?></option>
						</select>

						<label class="mn-mm-check">
							<input type="checkbox" id="mn-mm-include-children" checked>
							<?php esc_html_e( 'Include subfolders', 'foldernest' ); ?>
						</label>

						<div class="mn-mm-view-toggle" role="group" aria-label="<?php esc_attr_e( 'View mode', 'foldernest' ); ?>">
							<button type="button" id="mn-mm-view-grid" class="mn-mm-view-btn" title="<?php esc_attr_e( 'Grid view', 'foldernest' ); ?>">
								<span class="dashicons dashicons-grid-view"></span>
							</button>
							<button type="button" id="mn-mm-view-list" class="mn-mm-view-btn" title="<?php esc_attr_e( 'List view', 'foldernest' ); ?>">
								<span class="dashicons dashicons-list-view"></span>
							</button>
						</div>

						<p class="mn-mm-marquee-hint">
							<?php esc_html_e( 'Hold and drag on empty space, or press a card briefly and drag, to box-select multiple items. Hold Ctrl/Cmd while dragging to add to the selection.', 'foldernest' ); ?>
							<?php esc_html_e( 'Drag cards onto a folder on the left to move them (multi-select works); drop on "Unassigned" to remove folders.', 'foldernest' ); ?>
						</p>
					</div>

					<!-- 批次操作列 -->
					<div class="mn-mm-bulkbar" id="mn-mm-bulkbar" hidden>
						<div class="mn-mm-bulkbar-count">
							<?php esc_html_e( 'Selected', 'foldernest' ); ?> <strong id="mn-mm-selected-count">0</strong>
							<button type="button" class="mn-mm-link" id="mn-mm-select-all"><?php esc_html_e( 'Select all matching', 'foldernest' ); ?></button>
							<button type="button" class="mn-mm-link" id="mn-mm-clear-selection"><?php esc_html_e( 'Clear selection', 'foldernest' ); ?></button>
						</div>

						<div class="mn-mm-bulkbar-row">
							<div class="mn-mm-field">
								<label><?php esc_html_e( 'Folder', 'foldernest' ); ?></label>
								<select id="mn-mm-bulk-folder"></select>
							</div>
							<button type="button" class="button button-primary" data-bulk="folder-move"><?php esc_html_e( 'Move to', 'foldernest' ); ?></button>
							<button type="button" class="button" data-bulk="folder-add"><?php esc_html_e( 'Add', 'foldernest' ); ?></button>
							<button type="button" class="button" data-bulk="folder-clear"><?php esc_html_e( 'Remove from folders', 'foldernest' ); ?></button>
							<button type="button" class="button mn-mm-danger-btn" data-bulk="media-delete" title="<?php esc_attr_e( 'Delete permanently (skips trash, cannot be undone)', 'foldernest' ); ?>"><?php esc_html_e( 'Delete permanently', 'foldernest' ); ?></button>
						</div>

						<div class="mn-mm-bulkbar-row">
							<div class="mn-mm-field">
								<label><?php esc_html_e( 'Tags', 'foldernest' ); ?></label>
								<input type="text" id="mn-mm-bulk-tags" placeholder="<?php esc_attr_e( 'Comma-separated; missing tags are created', 'foldernest' ); ?>">
							</div>
							<button type="button" class="button" data-bulk="tag-add"><?php esc_html_e( 'Add tags', 'foldernest' ); ?></button>
							<button type="button" class="button" data-bulk="tag-remove"><?php esc_html_e( 'Remove tags', 'foldernest' ); ?></button>
						</div>

						<div class="mn-mm-bulkbar-row mn-mm-bulkbar-row--edit">
							<button type="button" class="button" id="mn-mm-toggle-edit">
								<?php esc_html_e( 'Batch edit fields', 'foldernest' ); ?> <span class="dashicons dashicons-arrow-down-alt2"></span>
							</button>
						</div>

						<div class="mn-mm-bulk-edit" id="mn-mm-bulk-edit" hidden>
							<p class="mn-mm-hint">
								<?php esc_html_e( 'Variables:', 'foldernest' ); ?> <code>{filename}</code> <?php esc_html_e( 'full filename', 'foldernest' ); ?>,
								<code>{stem}</code> <?php esc_html_e( 'filename without extension', 'foldernest' ); ?>,
								<code>{ext}</code> <?php esc_html_e( 'extension', 'foldernest' ); ?>,
								<code>{index}</code> <?php esc_html_e( 'counter', 'foldernest' ); ?>,
								<code>{original}</code> <?php esc_html_e( 'original value', 'foldernest' ); ?>.
								<?php esc_html_e( 'Empty fields are left unchanged.', 'foldernest' ); ?>
							</p>
							<div class="mn-mm-field-grid">
								<label><?php esc_html_e( 'Title', 'foldernest' ); ?> <input type="text" id="mn-mm-edit-title" placeholder="<?php esc_attr_e( 'e.g. {stem}', 'foldernest' ); ?>"></label>
								<label><?php esc_html_e( 'Alt text', 'foldernest' ); ?> <input type="text" id="mn-mm-edit-alt" placeholder="<?php esc_attr_e( 'e.g. {stem}', 'foldernest' ); ?>"></label>
								<label><?php esc_html_e( 'Caption', 'foldernest' ); ?> <input type="text" id="mn-mm-edit-caption"></label>
								<label><?php esc_html_e( 'Description', 'foldernest' ); ?> <input type="text" id="mn-mm-edit-desc"></label>
							</div>
							<div class="mn-mm-bulkbar-row">
								<div class="mn-mm-field">
									<label><?php esc_html_e( 'Apply mode', 'foldernest' ); ?></label>
									<select id="mn-mm-edit-mode">
										<option value="replace"><?php esc_html_e( 'Replace original value', 'foldernest' ); ?></option>
										<option value="append"><?php esc_html_e( 'Append after original', 'foldernest' ); ?></option>
										<option value="prepend"><?php esc_html_e( 'Prepend before original', 'foldernest' ); ?></option>
									</select>
								</div>
								<button type="button" class="button button-primary" data-bulk="bulk-edit"><?php esc_html_e( 'Apply fields', 'foldernest' ); ?></button>
							</div>
						</div>
					</div>

					<div class="mn-mm-folders" id="mn-mm-folders" hidden></div>
					<div class="mn-mm-grid" id="mn-mm-grid"></div>
					<div class="mn-mm-pagination" id="mn-mm-pagination"></div>
				</section>

				<!-- ============ 右：詳細資料 ============ -->
				<aside class="mn-mm-panel mn-mm-inspector" id="mn-mm-inspector">
					<div class="mn-mm-inspector-empty" id="mn-mm-inspector-empty">
						<span class="dashicons dashicons-format-image"></span>
						<p><?php esc_html_e( 'Click any media to edit its details here', 'foldernest' ); ?></p>
					</div>
					<div class="mn-mm-inspector-body" id="mn-mm-inspector-body" hidden></div>
				</aside>

			</div>
		</div>

		<!-- ============ 自動分類對話框 ============ -->
		<div class="mn-mm-modal" id="mn-mm-modal-auto" hidden>
			<div class="mn-mm-modal-box mn-mm-modal-box--wide">
				<div class="mn-mm-modal-head">
					<h2><?php esc_html_e( 'Auto-assign', 'foldernest' ); ?></h2>
					<button type="button" class="mn-mm-modal-close" data-close>×</button>
				</div>
				<div class="mn-mm-modal-body">

					<div class="mn-mm-notice">
						<?php esc_html_e( 'Rules are evaluated top-down; the first matching rule wins.', 'foldernest' ); ?>
						<?php esc_html_e( 'Run "Dry run" to preview the result before applying.', 'foldernest' ); ?>
					</div>

					<div class="mn-mm-scope">
						<strong><?php esc_html_e( 'Scope:', 'foldernest' ); ?></strong>
						<label class="mn-mm-check"><input type="radio" name="mn-mm-auto-scope" value="all" checked> <?php esc_html_e( 'All media', 'foldernest' ); ?></label>
						<label class="mn-mm-check"><input type="radio" name="mn-mm-auto-scope" value="unassigned"> <?php esc_html_e( 'Unassigned only', 'foldernest' ); ?></label>
						<label class="mn-mm-check"><input type="radio" name="mn-mm-auto-scope" value="image"> <?php esc_html_e( 'Images only', 'foldernest' ); ?></label>
						<label class="mn-mm-check"><input type="radio" name="mn-mm-auto-scope" value="audio"> <?php esc_html_e( 'Audio only', 'foldernest' ); ?></label>
						<label class="mn-mm-check"><input type="radio" name="mn-mm-auto-scope" value="video"> <?php esc_html_e( 'Videos only', 'foldernest' ); ?></label>
					</div>

					<div id="mn-mm-rules"></div>

					<div class="mn-mm-rules-actions">
						<button type="button" class="button" id="mn-mm-add-rule">＋ <?php esc_html_e( 'New rule', 'foldernest' ); ?></button>
						<button type="button" class="button" id="mn-mm-save-rules"><?php esc_html_e( 'Save rules', 'foldernest' ); ?></button>
						<button type="button" class="button" id="mn-mm-reset-rules"><?php esc_html_e( 'Restore default rules', 'foldernest' ); ?></button>
					</div>

					<div class="mn-mm-preview" id="mn-mm-preview" hidden></div>
				</div>
				<div class="mn-mm-modal-foot">
					<button type="button" class="button" id="mn-mm-preview-btn"><?php esc_html_e( 'Dry run', 'foldernest' ); ?></button>
					<button type="button" class="button button-primary" id="mn-mm-apply-btn"><?php esc_html_e( 'Apply now', 'foldernest' ); ?></button>
					<button type="button" class="button-link" data-close><?php esc_html_e( 'Close', 'foldernest' ); ?></button>
				</div>
			</div>
		</div>

		<!-- ============ 操作記錄對話框 ============ -->
		<div class="mn-mm-modal" id="mn-mm-modal-logs" hidden>
			<div class="mn-mm-modal-box">
				<div class="mn-mm-modal-head">
					<h2><?php esc_html_e( 'Activity log', 'foldernest' ); ?></h2>
					<button type="button" class="mn-mm-modal-close" data-close>×</button>
				</div>
				<div class="mn-mm-modal-body">
					<div class="mn-mm-notice">
						<?php esc_html_e( 'Every batch action keeps a snapshot and can be undone with one click. Only classification and field changes are recorded — files are never touched.', 'foldernest' ); ?>
					</div>
					<div id="mn-mm-log-list"></div>
				</div>
			</div>
		</div>

		<!-- ============ Pro 購買引導 ============ -->
		<div class="mn-mm-modal" id="mn-mm-modal-upsell" hidden>
			<div class="mn-mm-modal-box">
				<div class="mn-mm-modal-head">
					<h2><?php esc_html_e( 'FolderNest Pro', 'foldernest' ); ?></h2>
					<button type="button" class="mn-mm-modal-close" data-close>×</button>
				</div>
				<div class="mn-mm-modal-body">
					<p><?php esc_html_e( 'This feature is part of FolderNest Pro:', 'foldernest' ); ?></p>
					<ul id="mn-mm-upsell-features" style="margin:8px 0 12px 18px;list-style:disc">
						<li><?php esc_html_e( 'Unused-media cleanup wizard', 'foldernest' ); ?></li>
						<li><?php esc_html_e( 'Missing-alt batch workflow', 'foldernest' ); ?></li>
						<li><?php esc_html_e( 'Folder import/export & import from other folder plugins', 'foldernest' ); ?></li>
						<li><?php esc_html_e( 'Folder access roles', 'foldernest' ); ?></li>
						<li><?php esc_html_e( 'Delete folder together with its files (typed confirmation)', 'foldernest' ); ?></li>
					</ul>
					<p><?php esc_html_e( 'Annual license, activate instantly with the key we email you.', 'foldernest' ); ?></p>
				</div>
				<div class="mn-mm-modal-foot">
					<a class="button button-primary" id="mn-mm-upsell-buy" href="https://foldernest.plugnest.dev/" target="_blank" rel="noopener"><?php esc_html_e( 'Buy FolderNest Pro', 'foldernest' ); ?></a>
					<button type="button" class="button-link" data-close><?php esc_html_e( 'Close', 'foldernest' ); ?></button>
				</div>
			</div>
		</div>

		<!-- ============ 進度遮罩 ============ -->
		<div class="mn-mm-progress" id="mn-mm-progress" hidden>
			<div class="mn-mm-progress-box">
				<div class="mn-mm-spinner"></div>
				<p id="mn-mm-progress-text"><?php esc_html_e( 'Processing…', 'foldernest' ); ?></p>
				<div class="mn-mm-progress-bar"><span id="mn-mm-progress-fill"></span></div>
				<button type="button" class="button" id="mn-mm-progress-cancel" hidden><?php esc_html_e( 'Cancel', 'foldernest' ); ?></button>
			</div>
		</div>

		<div class="mn-mm-toast" id="mn-mm-toast" hidden></div>
		<?php
	}
}
