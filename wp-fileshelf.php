<?php
/**
 * Plugin Name: WP FileShelf
 * Plugin URI: https://asenka.com/
 * Description: Manage a private staff-uploaded file shelf with stable public file URLs outside the WordPress Media Library.
 * Version: 0.1.0
 * Author: Asenka Interactive
 * Author URI: https://asenka.com/
 * Text Domain: wp-fileshelf
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * License: GPL-3.0-or-later
 *
 * Primary Developer: Brian McLendon
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WFS_VERSION', '0.1.0' );
define( 'WFS_FILE', __FILE__ );
define( 'WFS_PATH', plugin_dir_path( __FILE__ ) );
define( 'WFS_URL', plugin_dir_url( __FILE__ ) );
define( 'WFS_GITHUB_REPOSITORY', 'Asenka-Dev-Team/WP-FileShelf' );
define( 'WFS_STORAGE_DIRNAME', 'wp-fileshelf-uploads' );
define( 'WFS_UPLOAD_ROUTE', 'wpfileshelf' );

require_once WFS_PATH . 'includes/class-wfs-db.php';
require_once WFS_PATH . 'includes/class-wfs-files.php';
require_once WFS_PATH . 'includes/class-wfs-router.php';
require_once WFS_PATH . 'includes/class-wfs-frontend.php';
require_once WFS_PATH . 'includes/class-wfs-updater.php';
require_once WFS_PATH . 'includes/class-wfs-admin.php';

final class WP_FileShelf {
    private static ?WP_FileShelf $instance = null;

    public static function instance(): WP_FileShelf {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        WFS_DB::init();
        WFS_Files::init();
        WFS_Router::init();
        WFS_Frontend::init();
        WFS_Updater::init();

        if ( is_admin() ) {
            WFS_Admin::init();
        }
    }

    public static function activate(): void {
        WFS_DB::install();
        WFS_Files::ensure_storage_directory();

        if ( false === get_option( 'wfs_link_slug', false ) ) {
            add_option( 'wfs_link_slug', 'fileshelf', '', false );
        }

        if ( false === get_option( 'wfs_allowed_mime_keys', false ) ) {
            add_option( 'wfs_allowed_mime_keys', WFS_Files::default_allowed_mime_keys(), '', false );
        }

        if ( false === get_option( 'wfs_delete_on_uninstall', false ) ) {
            add_option( 'wfs_delete_on_uninstall', '0', '', false );
        }

        WFS_Router::register_rewrite_rules();
        flush_rewrite_rules();
        update_option( 'wfs_rewrite_version', WFS_VERSION, false );
        update_option( 'wfs_rewrite_slug', WFS_Router::link_slug(), false );
    }

    public static function deactivate(): void {
        flush_rewrite_rules();
    }
}

register_activation_hook( __FILE__, array( 'WP_FileShelf', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WP_FileShelf', 'deactivate' ) );

add_action(
    'plugins_loaded',
    static function (): void {
        WP_FileShelf::instance();
    }
);
