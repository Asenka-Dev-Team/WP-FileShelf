<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

if ( '1' !== get_option( 'wfs_delete_on_uninstall', '0' ) ) {
    // Safe default: preserve files, database metadata, and settings so a later
    // reinstall can reconnect to the existing shelf.
    return;
}

global $wpdb;

$table = $wpdb->prefix . 'wfs_files';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$options = array(
    'wfs_db_version',
    'wfs_link_slug',
    'wfs_allowed_mime_keys',
    'wfs_delete_on_uninstall',
    'wfs_upload_password_hash',
    'wfs_rewrite_version',
    'wfs_rewrite_slug',
);

foreach ( $options as $option ) {
    delete_option( $option );
}

delete_site_transient( 'wfs_github_latest_release' );
delete_site_option( 'wfs_github_update_status' );

// Remove any temporary failed-login throttling records that have not expired yet.
$like_transient = $wpdb->esc_like( '_transient_wfs_login_' ) . '%';
$like_timeout   = $wpdb->esc_like( '_transient_timeout_wfs_login_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like_transient, $like_timeout ) );

$dir    = trailingslashit( ABSPATH ) . 'wp-fileshelf-uploads';
$marker = trailingslashit( $dir ) . '.wp-fileshelf';

/**
 * Recursively delete a verified FileShelf directory without following symlinks.
 */
$delete_tree = static function ( string $path ) use ( &$delete_tree ): void {
    if ( is_link( $path ) || is_file( $path ) ) {
        @unlink( $path );
        return;
    }

    if ( ! is_dir( $path ) ) {
        return;
    }

    $iterator = new FilesystemIterator( $path, FilesystemIterator::SKIP_DOTS );
    foreach ( $iterator as $item ) {
        $child = $item->getPathname();
        if ( $item->isLink() || $item->isFile() ) {
            @unlink( $child );
        } elseif ( $item->isDir() ) {
            $delete_tree( $child );
        }
    }

    @rmdir( $path );
};

// Multiple independent checks intentionally gate recursive deletion.
if ( is_dir( $dir ) && ! is_link( $dir ) && is_file( $marker ) ) {
    $real_dir  = realpath( $dir );
    $real_root = realpath( ABSPATH );

    if (
        false !== $real_dir
        && false !== $real_root
        && basename( $real_dir ) === 'wp-fileshelf-uploads'
        && dirname( $real_dir ) === rtrim( $real_root, DIRECTORY_SEPARATOR )
    ) {
        $delete_tree( $real_dir );
    }
}

if ( function_exists( 'flush_rewrite_rules' ) ) {
    flush_rewrite_rules( false );
}
