<?php
/**
 * GitHub release updater for WP FileShelf.
 *
 * Mirrors the release-based update flow used by WP FileTrace.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class WFS_Updater {
    private const CACHE_KEY     = 'wfs_github_latest_release';
    private const STATUS_OPTION = 'wfs_github_update_status';
    private const CACHE_TTL     = 3600;

    private static string $plugin_basename = '';

    public static function init(): void {
        self::$plugin_basename = plugin_basename( WFS_FILE );

        if ( ! self::has_repository() ) {
            if ( is_admin() ) {
                add_action( 'admin_notices', array( __CLASS__, 'repository_not_configured_notice' ) );
            }
            return;
        }

        add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_for_update' ) );
        add_filter( 'plugins_api', array( __CLASS__, 'plugin_information' ), 20, 3 );
        add_filter( 'upgrader_source_selection', array( __CLASS__, 'normalize_github_source_directory' ), 10, 4 );
        add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache_after_upgrade' ), 10, 2 );
        add_action( 'delete_site_transient_update_plugins', array( __CLASS__, 'clear_release_cache' ) );
    }

    public static function repository_not_configured_notice(): void {
        if ( ! current_user_can( 'update_plugins' ) ) {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        echo esc_html__( 'WP FileShelf GitHub updates are not configured yet. Set WFS_GITHUB_REPOSITORY in wp-fileshelf.php before publishing this build.', 'wp-fileshelf' );
        echo '</p></div>';
    }

    public static function check_for_update( $transient ) {
        if ( ! is_object( $transient ) ) {
            return $transient;
        }

        $release = self::get_latest_release();
        if ( ! $release ) {
            return $transient;
        }

        $remote_version = self::normalize_version( (string) ( $release['tag_name'] ?? '' ) );
        $package_url    = self::find_release_package( $release );

        if ( '' === $remote_version || ! version_compare( WFS_VERSION, $remote_version, '<' ) || '' === $package_url ) {
            return $transient;
        }

        $transient->response[ self::$plugin_basename ] = (object) array(
            'id'           => self::repository_url(),
            'slug'         => 'wp-fileshelf',
            'plugin'       => self::$plugin_basename,
            'new_version'  => $remote_version,
            'url'          => self::repository_url(),
            'package'      => $package_url,
            'requires_php' => '8.0',
            'tested'       => '',
        );

        return $transient;
    }

    public static function plugin_information( $result, string $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) || 'wp-fileshelf' !== $args->slug ) {
            return $result;
        }

        $release = self::get_latest_release();
        if ( ! $release ) {
            return $result;
        }

        $remote_version = self::normalize_version( (string) ( $release['tag_name'] ?? '' ) );
        $release_notes  = isset( $release['body'] ) && is_string( $release['body'] ) ? $release['body'] : '';

        return (object) array(
            'name'          => 'WP FileShelf',
            'slug'          => 'wp-fileshelf',
            'version'       => $remote_version ?: WFS_VERSION,
            'author'        => '<a href="https://asenka.com/">Asenka Interactive</a>',
            'homepage'      => self::repository_url(),
            'requires'      => '6.4',
            'requires_php'  => '8.0',
            'download_link' => self::find_release_package( $release ),
            'sections'      => array(
                'description' => 'Staff-managed file storage with stable public file URLs outside the WordPress Media Library.',
                'changelog'   => '' !== trim( $release_notes )
                    ? wpautop( esc_html( $release_notes ) )
                    : '<p>See changelog.md in the WP FileShelf repository for release history.</p>',
            ),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function force_check(): array {
        delete_site_transient( 'update_plugins' );
        self::clear_release_cache();

        $release = self::get_latest_release( true );
        if ( is_array( $release ) && function_exists( 'wp_update_plugins' ) ) {
            wp_update_plugins();
        }

        return self::get_diagnostics( false );
    }

    /**
     * @return array<string,mixed>
     */
    public static function get_diagnostics( bool $refresh_if_empty = true ): array {
        $status = get_site_option( self::STATUS_OPTION, array() );
        $status = is_array( $status ) ? $status : array();

        if ( $refresh_if_empty && empty( $status['last_checked'] ) ) {
            self::get_latest_release();
            $status = get_site_option( self::STATUS_OPTION, array() );
            $status = is_array( $status ) ? $status : array();
        }

        $latest_version = isset( $status['latest_version'] ) ? (string) $status['latest_version'] : '';
        if ( '' === $latest_version ) {
            $cached = get_site_transient( self::CACHE_KEY );
            if ( is_array( $cached ) ) {
                $latest_version = self::normalize_version( (string) ( $cached['tag_name'] ?? '' ) );
            }
        }

        $connection = isset( $status['connection'] ) ? sanitize_key( (string) $status['connection'] ) : 'not_checked';
        if ( ! self::has_repository() ) {
            $connection = 'not_configured';
        }

        return array(
            'installed_version' => WFS_VERSION,
            'latest_version'    => $latest_version,
            'last_checked'      => isset( $status['last_checked'] ) ? absint( $status['last_checked'] ) : 0,
            'connection'        => $connection,
            'http_code'         => isset( $status['http_code'] ) ? absint( $status['http_code'] ) : 0,
            'message'           => isset( $status['message'] ) ? sanitize_text_field( (string) $status['message'] ) : '',
            'update_available'  => '' !== $latest_version && version_compare( WFS_VERSION, $latest_version, '<' ),
            'cache_ttl'         => self::CACHE_TTL,
        );
    }

    public static function releases_url(): string {
        return trailingslashit( self::repository_url() ) . 'releases';
    }

    public static function clear_release_cache(): void {
        delete_site_transient( self::CACHE_KEY );
    }

    public static function clear_cache_after_upgrade( $upgrader, array $options ): void {
        unset( $upgrader );

        if ( 'update' !== ( $options['action'] ?? '' ) || 'plugin' !== ( $options['type'] ?? '' ) ) {
            return;
        }

        $plugins = isset( $options['plugins'] ) && is_array( $options['plugins'] ) ? $options['plugins'] : array();
        if ( in_array( self::$plugin_basename, $plugins, true ) ) {
            self::clear_release_cache();
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function get_latest_release( bool $force = false ): ?array {
        if ( ! $force ) {
            $cached = get_site_transient( self::CACHE_KEY );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }

        $endpoint = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            rawurlencode( self::repository_owner() ),
            rawurlencode( self::repository_name() )
        );

        $response = wp_remote_get(
            $endpoint,
            array(
                'timeout' => 10,
                'headers' => array(
                    'Accept'     => 'application/vnd.github+json',
                    'User-Agent' => 'WP-FileShelf/' . WFS_VERSION,
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            self::record_check_status( 'error', 0, '', $response->get_error_message() );
            return null;
        }

        $http_code = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $http_code ) {
            self::record_check_status(
                'error',
                $http_code,
                '',
                sprintf(
                    /* translators: %d: HTTP status code returned by GitHub. */
                    __( 'GitHub returned HTTP %d.', 'wp-fileshelf' ),
                    $http_code
                )
            );
            return null;
        }

        $release = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $release ) || ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ) {
            self::record_check_status( 'error', $http_code, '', __( 'GitHub did not return a valid normal release.', 'wp-fileshelf' ) );
            return null;
        }

        $latest_version = self::normalize_version( (string) ( $release['tag_name'] ?? '' ) );
        if ( '' === $latest_version ) {
            self::record_check_status( 'error', $http_code, '', __( 'The latest GitHub release has an invalid version tag.', 'wp-fileshelf' ) );
            return null;
        }

        set_site_transient( self::CACHE_KEY, $release, self::CACHE_TTL );
        self::record_check_status( 'connected', $http_code, $latest_version, __( 'Connected to GitHub Releases.', 'wp-fileshelf' ) );

        return $release;
    }

    private static function record_check_status( string $connection, int $http_code, string $latest_version, string $message ): void {
        update_site_option(
            self::STATUS_OPTION,
            array(
                'connection'     => sanitize_key( $connection ),
                'http_code'      => max( 0, $http_code ),
                'latest_version' => sanitize_text_field( $latest_version ),
                'last_checked'   => time(),
                'message'        => sanitize_text_field( $message ),
            )
        );
    }

    private static function find_release_package( array $release ): string {
        if ( empty( $release['zipball_url'] ) || ! is_string( $release['zipball_url'] ) ) {
            return '';
        }

        return esc_url_raw( $release['zipball_url'] );
    }

    public static function normalize_github_source_directory( $source, string $remote_source, $upgrader, array $hook_extra ) {
        unset( $upgrader );

        if ( is_wp_error( $source ) || ! self::is_our_upgrade( $hook_extra ) ) {
            return $source;
        }

        global $wp_filesystem;
        if ( ! $wp_filesystem ) {
            return $source;
        }

        $source = trailingslashit( (string) $source );
        if ( ! $wp_filesystem->exists( $source . 'wp-fileshelf.php' ) ) {
            return new WP_Error(
                'wfs_invalid_update_package',
                __( 'The WP FileShelf GitHub archive does not contain wp-fileshelf.php at its repository root.', 'wp-fileshelf' )
            );
        }

        $normalized_source = trailingslashit( $remote_source ) . 'wp-fileshelf/';
        if ( untrailingslashit( $source ) === untrailingslashit( $normalized_source ) ) {
            return $source;
        }

        if ( $wp_filesystem->exists( $normalized_source ) ) {
            $wp_filesystem->delete( $normalized_source, true );
        }

        if ( ! $wp_filesystem->move( $source, $normalized_source, true ) ) {
            return new WP_Error(
                'wfs_update_directory_normalization_failed',
                __( 'WP FileShelf could not normalize the GitHub update directory.', 'wp-fileshelf' )
            );
        }

        return $normalized_source;
    }

    private static function is_our_upgrade( array $hook_extra ): bool {
        if ( isset( $hook_extra['plugin'] ) && self::$plugin_basename === $hook_extra['plugin'] ) {
            return true;
        }

        if ( isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) && in_array( self::$plugin_basename, $hook_extra['plugins'], true ) ) {
            return true;
        }

        return isset( $hook_extra['slug'] ) && 'wp-fileshelf' === $hook_extra['slug'];
    }

    private static function normalize_version( string $tag ): string {
        $version = ltrim( trim( $tag ), "vV \t\n\r\0\x0B" );
        return preg_match( '/^[0-9]+(?:\.[0-9A-Za-z-]+)+$/', $version ) ? $version : '';
    }

    private static function has_repository(): bool {
        return defined( 'WFS_GITHUB_REPOSITORY' )
            && is_string( WFS_GITHUB_REPOSITORY )
            && '' !== trim( WFS_GITHUB_REPOSITORY )
            && ! in_array( WFS_GITHUB_REPOSITORY, array( 'OWNER/wp-fileshelf', 'OWNER/WP-FileShelf' ), true );
    }

    private static function repository_url(): string {
        return 'https://github.com/' . self::repository_owner() . '/' . self::repository_name() . '/';
    }

    private static function repository_owner(): string {
        $parts = explode( '/', trim( WFS_GITHUB_REPOSITORY, '/' ), 2 );
        return $parts[0] ?? '';
    }

    private static function repository_name(): string {
        $parts = explode( '/', trim( WFS_GITHUB_REPOSITORY, '/' ), 2 );
        return $parts[1] ?? '';
    }
}
