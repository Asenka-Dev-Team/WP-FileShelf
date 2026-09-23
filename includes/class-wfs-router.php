<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class WFS_Router {
    public static function init(): void {
        add_action( 'init', array( __CLASS__, 'register_rewrite_rules' ) );
        add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
        add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_request' ), 0 );
        add_action( 'init', array( __CLASS__, 'maybe_refresh_rewrite_rules' ), 50 );
    }

    public static function link_slug(): string {
        $slug = sanitize_title( (string) get_option( 'wfs_link_slug', 'fileshelf' ) );
        return '' !== $slug ? $slug : 'fileshelf';
    }

    public static function upload_slug(): string {
        $slug = sanitize_title( (string) get_option( 'wfs_upload_slug', WFS_UPLOAD_ROUTE ) );
        return '' !== $slug ? $slug : WFS_UPLOAD_ROUTE;
    }

    public static function register_rewrite_rules(): void {
        $upload_slug = self::upload_slug();
        add_rewrite_rule(
            '^' . preg_quote( $upload_slug, '#' ) . '/?$',
            'index.php?wfs_upload_page=1',
            'top'
        );

        $slug = self::link_slug();
        add_rewrite_rule(
            '^' . preg_quote( $slug, '#' ) . '/([^/]+)/?$',
            'index.php?wfs_file=$matches[1]',
            'top'
        );
    }

    /**
     * @param string[] $vars
     * @return string[]
     */
    public static function query_vars( array $vars ): array {
        $vars[] = 'wfs_upload_page';
        $vars[] = 'wfs_file';
        return $vars;
    }

    public static function maybe_handle_request(): void {
        if ( get_query_var( 'wfs_upload_page' ) ) {
            WFS_Frontend::render_page();
            exit;
        }

        $file = get_query_var( 'wfs_file' );
        if ( is_string( $file ) && '' !== $file ) {
            WFS_Files::serve_file( $file );
        }
    }

    public static function maybe_refresh_rewrite_rules(): void {
        $saved_version     = (string) get_option( 'wfs_rewrite_version', '' );
        $saved_slug        = (string) get_option( 'wfs_rewrite_slug', '' );
        $saved_upload_slug = (string) get_option( 'wfs_rewrite_upload_slug', '' );
        $current_slug      = self::link_slug();
        $current_upload    = self::upload_slug();

        if ( WFS_VERSION === $saved_version && $current_slug === $saved_slug && $current_upload === $saved_upload_slug ) {
            return;
        }

        self::register_rewrite_rules();
        flush_rewrite_rules( false );
        update_option( 'wfs_rewrite_version', WFS_VERSION, false );
        update_option( 'wfs_rewrite_slug', $current_slug, false );
        update_option( 'wfs_rewrite_upload_slug', $current_upload, false );
    }
}
