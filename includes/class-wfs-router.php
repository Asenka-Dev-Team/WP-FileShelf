<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class WFS_Router {
    private static bool $cache_exclusions_bootstrapped = false;

    /**
     * Register cache exclusions as early as possible during normal WordPress
     * plugin loading. Full-page cache drop-ins can run before regular plugins,
     * so an already-cached copy may still need to be purged once on update.
     */
    public static function bootstrap_cache_exclusions(): void {
        if ( self::$cache_exclusions_bootstrapped ) {
            return;
        }

        self::$cache_exclusions_bootstrapped = true;

        // WP Rocket builds its never-cache rules from these filters.
        add_filter( 'rocket_cache_reject_uri', array( __CLASS__, 'rocket_reject_uri' ) );
        add_filter( 'rocket_cache_reject_cookies', array( __CLASS__, 'rocket_reject_cookies' ) );

        // DONOTCACHEPAGE is a de-facto WordPress cache-plugin convention.
        if ( self::is_upload_request_uri() ) {
            self::define_donotcachepage();
        }
    }

    public static function init(): void {
        self::bootstrap_cache_exclusions();

        add_action( 'init', array( __CLASS__, 'register_rewrite_rules' ) );
        add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
        add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_request' ), 0 );
        add_action( 'init', array( __CLASS__, 'maybe_refresh_rewrite_rules' ), 50 );
    }

    public static function link_slug(): string {
        $slug = sanitize_title( (string) get_option( 'wfs_link_slug', 'fileshelf' ) );
        return '' !== $slug ? $slug : 'fileshelf';
    }

    public static function register_rewrite_rules(): void {
        add_rewrite_rule(
            '^' . preg_quote( WFS_UPLOAD_ROUTE, '#' ) . '/?$',
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
            self::mark_upload_page_nocache();
            WFS_Frontend::render_page();
            exit;
        }

        $file = get_query_var( 'wfs_file' );
        if ( is_string( $file ) && '' !== $file ) {
            WFS_Files::serve_file( $file );
        }
    }

    public static function maybe_refresh_rewrite_rules(): void {
        $saved_version = (string) get_option( 'wfs_rewrite_version', '' );
        $saved_slug    = (string) get_option( 'wfs_rewrite_slug', '' );
        $current_slug  = self::link_slug();

        if ( WFS_VERSION === $saved_version && $current_slug === $saved_slug ) {
            return;
        }

        self::register_rewrite_rules();
        flush_rewrite_rules( false );

        // A pre-v0.1.6 full-page cache can otherwise keep serving stale logged-in
        // or logged-out HTML before WordPress gets a chance to inspect the auth
        // cookie. Purge only the staff upload URL during this version refresh.
        self::purge_upload_page_cache();

        update_option( 'wfs_rewrite_version', WFS_VERSION, false );
        update_option( 'wfs_rewrite_slug', $current_slug, false );
    }

    /**
     * Add the fixed staff uploader to WP Rocket's never-cache URI list.
     *
     * @param mixed $uris
     * @return array<int,mixed>
     */
    public static function rocket_reject_uri( $uris ): array {
        $uris = (array) $uris;

        $route = '/' . trim( WFS_UPLOAD_ROUTE, '/' ) . '/';
        if ( ! in_array( $route, $uris, true ) ) {
            $uris[] = $route;
        }

        return $uris;
    }

    /**
     * Keep WP Rocket from serving a shared cache when the FileShelf auth cookie
     * exists, even if another rule later changes URI exclusions.
     *
     * @param mixed $cookies
     * @return array<int,mixed>
     */
    public static function rocket_reject_cookies( $cookies ): array {
        $cookies = (array) $cookies;

        if ( ! in_array( 'wfs_upload_auth', $cookies, true ) ) {
            $cookies[] = 'wfs_upload_auth';
        }

        return $cookies;
    }

    private static function mark_upload_page_nocache(): void {
        self::define_donotcachepage();

        // LiteSpeed Cache exposes an explicit plugin API for marking the current
        // response non-cacheable. Calling the action is harmless when LSCWP is
        // not installed because there will simply be no listeners.
        do_action( 'litespeed_control_set_nocache', 'WP FileShelf staff upload page' );
    }

    private static function define_donotcachepage(): void {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
    }

    private static function is_upload_request_uri(): bool {
        if ( empty( $_SERVER['REQUEST_URI'] ) ) {
            return false;
        }

        $request_uri  = (string) wp_unslash( $_SERVER['REQUEST_URI'] );
        $request_path = wp_parse_url( $request_uri, PHP_URL_PATH );
        $upload_path  = wp_parse_url( home_url( '/' . trim( WFS_UPLOAD_ROUTE, '/' ) . '/' ), PHP_URL_PATH );

        if ( ! is_string( $request_path ) || ! is_string( $upload_path ) ) {
            return false;
        }

        return untrailingslashit( $request_path ) === untrailingslashit( $upload_path );
    }

    private static function purge_upload_page_cache(): void {
        $url = home_url( '/' . trim( WFS_UPLOAD_ROUTE, '/' ) . '/' );

        // WP Rocket: purge only the FileShelf staff uploader URL.
        if ( function_exists( 'rocket_clean_files' ) ) {
            rocket_clean_files( $url );
        }

        // LiteSpeed Cache: official purge-by-URL API.
        do_action( 'litespeed_purge_url', $url );

        // W3 Total Cache exposes a URL-specific purge helper when active.
        if ( function_exists( 'w3tc_flush_url' ) ) {
            w3tc_flush_url( $url );
        }
    }
}
