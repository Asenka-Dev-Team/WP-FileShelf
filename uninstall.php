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
    'wfs_storage_version',
    'wfs_link_slug',
    'wfs_upload_slug', // Legacy v0.1.4 option.
    'wfs_allowed_mime_keys',
    'wfs_delete_on_uninstall',
    'wfs_upload_password_hash',
    'wfs_upload_password_cipher', // Legacy v0.1.4 option.
    'wfs_rewrite_version',
    'wfs_rewrite_slug',
    'wfs_rewrite_upload_slug', // Legacy v0.1.4 option.
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

    try {
        $iterator = new FilesystemIterator( $path, FilesystemIterator::SKIP_DOTS );
    } catch ( UnexpectedValueException $e ) {
        return;
    }

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

/**
 * Delete only a marker-verified FileShelf directory whose direct parent is the
 * expected WordPress directory. This deliberately refuses arbitrary paths.
 */
$delete_verified_shelf = static function ( string $dir, string $expected_parent ) use ( $delete_tree ): void {
    $marker = trailingslashit( $dir ) . '.wp-fileshelf';

    if ( ! is_dir( $dir ) || is_link( $dir ) || ! is_file( $marker ) ) {
        return;
    }

    $real_dir    = realpath( $dir );
    $real_parent = realpath( $expected_parent );

    if (
        false === $real_dir
        || false === $real_parent
        || 'wp-fileshelf-uploads' !== basename( $real_dir )
        || rtrim( dirname( $real_dir ), DIRECTORY_SEPARATOR ) !== rtrim( $real_parent, DIRECTORY_SEPARATOR )
    ) {
        return;
    }

    $delete_tree( $real_dir );
};

// v0.1.3+ storage location.
$delete_verified_shelf(
    trailingslashit( WP_CONTENT_DIR ) . 'wp-fileshelf-uploads',
    WP_CONTENT_DIR
);

// Also clean the pre-v0.1.3 root location if a migration was interrupted and
// the verified legacy directory still exists.
$legacy_dir = trailingslashit( ABSPATH ) . 'wp-fileshelf-uploads';
$new_dir    = trailingslashit( WP_CONTENT_DIR ) . 'wp-fileshelf-uploads';
if ( wp_normalize_path( $legacy_dir ) !== wp_normalize_path( $new_dir ) ) {
    $delete_verified_shelf( $legacy_dir, ABSPATH );
}

if ( function_exists( 'flush_rewrite_rules' ) ) {
    flush_rewrite_rules( false );
}
