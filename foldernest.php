<?php
/**
 * Plugin Name:       FolderNest
 * Plugin URI:        https://foldernest.plugnest.dev/
 * Description:       Media library management: virtual folders (hierarchical, role-restricted), tags, batch editing, usage scanning, unused cleanup, missing-alt workflow, folder import/export, in-page upload, and Elementor/Gutenberg media modal integration. Never touches file paths.
 * Version:           2.4.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            PlugNest
 * Author URI:        https://www.plugnest.dev/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       foldernest
 * Copyright:       © PlugNest — plugnest.dev
 */

defined( 'ABSPATH' ) || exit;

define( 'MN_MM_VERSION', '2.4.2' );
define( 'MN_MM_FILE', __FILE__ );
define( 'MN_MM_PATH', plugin_dir_path( __FILE__ ) );
define( 'MN_MM_URL', plugin_dir_url( __FILE__ ) );

/**
 * 載入翻譯檔（languages/）。字串皆以 gettext 包裝，繁中／簡中就緒。
 *
 * load_plugin_textdomain（6.7+ 只註冊路徑，交給 JIT）之外補一次
 * 絕對路徑的 load_textdomain：在物件快取殘留舊查找結果、或 JIT 對
 * 自訂路徑解析失效的環境裡，這條是確定性的載入路徑。
 */
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'foldernest', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		if ( ! $locale || 'en_US' === $locale ) {
			return;
		}
		$mo = MN_MM_PATH . 'languages/foldernest-' . $locale . '.mo';
		if ( is_readable( $mo ) ) {
			load_textdomain( 'foldernest', $mo );
		}
	}
);

/**
 * 分類法 slug。
 *
 * ⚠️ 上線後不可更改：一旦有媒體被歸類，改 slug 等於讓既有分類關係全部脫鉤。
 */
define( 'MN_MM_TAX_FOLDER', 'mn_media_folder' );
define( 'MN_MM_TAX_TAG', 'mn_media_tag' );

/** 操作記錄資料表（不含 wpdb 前綴）。 */
define( 'MN_MM_TBL_LOG', 'mn_media_log' );

/** 使用狀態快取的 postmeta key（值為 1/0）。 */
define( 'MN_MM_META_USAGE', '_mn_mm_usage' );
define( 'MN_MM_META_USAGE_AT', '_mn_mm_usage_at' );

require_once MN_MM_PATH . 'includes/class-mn-mm-taxonomy.php';
require_once MN_MM_PATH . 'includes/class-mn-mm-log.php';
require_once MN_MM_PATH . 'includes/class-mn-mm-usage.php';
require_once MN_MM_PATH . 'includes/class-mn-mm-query.php';
require_once MN_MM_PATH . 'includes/class-mn-mm-auto.php';
require_once MN_MM_PATH . 'includes/class-mn-mm-ajax.php';
require_once MN_MM_PATH . 'includes/class-mn-mm-native.php';
require_once MN_MM_PATH . 'includes/class-mn-mm-upload.php';
require_once MN_MM_PATH . 'includes/class-mn-mm-modal.php';
require_once MN_MM_PATH . 'includes/class-mn-mm-admin.php';
require_once MN_MM_PATH . 'includes/class-mn-mm-plugin.php';

MN_MM_Plugin::instance();
MN_MM_Modal::instance();

register_activation_hook( __FILE__, array( 'MN_MM_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MN_MM_Plugin', 'deactivate' ) );
