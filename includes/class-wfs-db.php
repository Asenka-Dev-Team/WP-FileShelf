<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class WFS_DB {
    public const DB_VERSION = '0.1.0';

    public static function init(): void {
        add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ), 20 );
    }

    public static function files_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'wfs_files';
    }

    public static function install(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $table           = self::files_table();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            filename varchar(255) NOT NULL,
            filename_key char(64) NOT NULL,
            display_name varchar(255) NOT NULL DEFAULT '',
            original_filename varchar(255) NOT NULL DEFAULT '',
            mime_type varchar(191) NOT NULL DEFAULT '',
            file_size bigint(20) unsigned NOT NULL DEFAULT 0,
            checksum char(64) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            modified_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY filename_key (filename_key),
            KEY filename (filename(191)),
            KEY display_name (display_name(191)),
            KEY created_at (created_at),
            KEY modified_at (modified_at)
        ) {$charset_collate};";

        dbDelta( $sql );
        update_option( 'wfs_db_version', self::DB_VERSION, false );
    }

    public static function maybe_upgrade(): void {
        if ( get_option( 'wfs_db_version' ) !== self::DB_VERSION ) {
            self::install();
        }
    }
}
