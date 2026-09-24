<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class WFS_Frontend {
    private const AUTH_COOKIE  = 'wfs_upload_auth';
    private const AUTH_TTL     = 28800; // 8 hours.
    private const LOGIN_LIMIT  = 10;
    private const LOGIN_WINDOW = 900; // 15 minutes.

    public static function init(): void {
        add_action( 'wp_ajax_nopriv_wfs_frontend_login', array( __CLASS__, 'ajax_login' ) );
        add_action( 'wp_ajax_wfs_frontend_login', array( __CLASS__, 'ajax_login' ) );
        add_action( 'wp_ajax_nopriv_wfs_frontend_upload', array( __CLASS__, 'ajax_upload' ) );
        add_action( 'wp_ajax_wfs_frontend_upload', array( __CLASS__, 'ajax_upload' ) );
        add_action( 'wp_ajax_nopriv_wfs_frontend_logout', array( __CLASS__, 'ajax_logout' ) );
        add_action( 'wp_ajax_wfs_frontend_logout', array( __CLASS__, 'ajax_logout' ) );
    }

    public static function render_page(): void {
        status_header( 200 );
        nocache_headers();

        $configured    = self::password_is_configured();
        $authenticated = $configured && self::is_authenticated();
        $ajax_url      = admin_url( 'admin-ajax.php' );
        $nonce         = wp_create_nonce( $authenticated ? 'wfs_frontend_upload' : 'wfs_frontend_login' );
        $allowed       = self::allowed_extensions_label();
        ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title><?php echo esc_html__( 'WP FileShelf Upload', 'wp-fileshelf' ); ?></title>
    <link rel="stylesheet" href="<?php echo esc_url( WFS_URL . 'assets/css/frontend.css?ver=' . rawurlencode( WFS_VERSION ) ); ?>">
</head>
<body class="wfs-front-body">
    <main class="wfs-front-shell">
        <section class="wfs-front-card">
            <div class="wfs-front-brand">
                <img src="<?php echo esc_url( WFS_URL . 'assets/images/icon--wp-fileshelf.svg' ); ?>" alt="" width="58" height="58">
                <div>
                    <span class="wfs-front-eyebrow"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
                    <h1><?php esc_html_e( 'WP FileShelf', 'wp-fileshelf' ); ?></h1>
                    <p><?php esc_html_e( 'Staff document upload', 'wp-fileshelf' ); ?></p>
                </div>
            </div>

            <div id="wfs-front-notice" class="wfs-front-notice" hidden></div>

            <?php if ( ! $configured ) : ?>
                <div class="wfs-front-message">
                    <h2><?php esc_html_e( 'Upload access is not configured yet.', 'wp-fileshelf' ); ?></h2>
                    <p><?php esc_html_e( 'A WordPress administrator needs to set the FileShelf upload password first.', 'wp-fileshelf' ); ?></p>
                </div>
            <?php elseif ( ! $authenticated ) : ?>
                <form id="wfs-front-login" class="wfs-front-form" autocomplete="off">
                    <input type="hidden" name="action" value="wfs_frontend_login">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">
                    <label for="wfs-password"><?php esc_html_e( 'Password', 'wp-fileshelf' ); ?></label>
                    <div class="wfs-front-password-control">
                        <input id="wfs-password" name="password" type="password" required autofocus autocomplete="current-password">
                        <button type="button" class="wfs-front-password-toggle" data-wfs-password-toggle="wfs-password" aria-pressed="false"><?php esc_html_e( 'View', 'wp-fileshelf' ); ?></button>
                    </div>
                    <button type="submit"><?php esc_html_e( 'Continue', 'wp-fileshelf' ); ?></button>
                </form>
            <?php else : ?>
                <form id="wfs-front-upload" class="wfs-front-form" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="wfs_frontend_upload">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">

                    <label for="wfs-upload-file"><?php esc_html_e( 'File', 'wp-fileshelf' ); ?></label>
                    <input id="wfs-upload-file" name="file" type="file" required>
                    <?php if ( '' !== $allowed ) : ?>
                        <p class="wfs-front-help"><?php echo esc_html( sprintf( __( 'Allowed: %s', 'wp-fileshelf' ), $allowed ) ); ?></p>
                    <?php endif; ?>

                    <label for="wfs-display-name"><?php esc_html_e( 'Name / Description', 'wp-fileshelf' ); ?></label>
                    <input id="wfs-display-name" name="display_name" type="text" maxlength="255" placeholder="<?php echo esc_attr__( 'Optional internal description', 'wp-fileshelf' ); ?>">

                    <button type="submit"><?php esc_html_e( 'Upload File', 'wp-fileshelf' ); ?></button>
                </form>

                <div id="wfs-upload-result" class="wfs-front-upload-result" hidden>
                    <label for="wfs-upload-result-url"><?php esc_html_e( 'File link', 'wp-fileshelf' ); ?></label>
                    <div class="wfs-front-copy-field">
                        <input id="wfs-upload-result-url" type="text" readonly value="">
                        <button id="wfs-upload-copy" type="button" class="wfs-secondary"><?php esc_html_e( 'Copy Link', 'wp-fileshelf' ); ?></button>
                    </div>
                </div>

                <div class="wfs-front-session-actions">
                    <button id="wfs-front-logout" type="button" class="wfs-front-link-button"><?php esc_html_e( 'Lock upload page', 'wp-fileshelf' ); ?></button>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <div id="wfs-replace-modal" class="wfs-modal" hidden aria-hidden="true">
        <div class="wfs-modal-backdrop" data-wfs-modal-cancel></div>
        <div class="wfs-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="wfs-replace-title">
            <h2 id="wfs-replace-title"><?php esc_html_e( 'Replace existing file?', 'wp-fileshelf' ); ?></h2>
            <p id="wfs-replace-message"></p>
            <p class="wfs-front-help"><?php esc_html_e( 'The existing filename, public link, display name, and original upload date will be kept. The modified date will be updated.', 'wp-fileshelf' ); ?></p>
            <div class="wfs-modal-actions">
                <button type="button" class="wfs-secondary" data-wfs-modal-cancel><?php esc_html_e( 'Cancel', 'wp-fileshelf' ); ?></button>
                <button type="button" id="wfs-confirm-replace"><?php esc_html_e( 'Replace File', 'wp-fileshelf' ); ?></button>
            </div>
        </div>
    </div>

    <script>
        window.WFSFront = <?php echo wp_json_encode(
            array(
                'ajaxUrl'       => $ajax_url,
                'authenticated' => $authenticated,
                'logoutNonce'   => $authenticated ? wp_create_nonce( 'wfs_frontend_logout' ) : '',
                'strings'       => array(
                    'working'       => __( 'Working…', 'wp-fileshelf' ),
                    'uploading'     => __( 'Uploading…', 'wp-fileshelf' ),
                    'uploaded'      => __( 'File uploaded successfully.', 'wp-fileshelf' ),
                    'replaced'      => __( 'Existing file replaced successfully.', 'wp-fileshelf' ),
                    'view'          => __( 'View', 'wp-fileshelf' ),
                    'hide'          => __( 'Hide', 'wp-fileshelf' ),
                    'copyLink'      => __( 'Copy Link', 'wp-fileshelf' ),
                    'copied'        => __( 'Copied!', 'wp-fileshelf' ),
                    'genericError'  => __( 'Something went wrong. Please try again.', 'wp-fileshelf' ),
                    'duplicate'     => __( '%s already exists. Do you want to replace the existing file?', 'wp-fileshelf' ),
                ),
            )
        ); ?>;
    </script>
    <script src="<?php echo esc_url( WFS_URL . 'assets/js/frontend.js?ver=' . rawurlencode( WFS_VERSION ) ); ?>"></script>
</body>
</html>
        <?php
    }

    public static function ajax_login(): void {
        check_ajax_referer( 'wfs_frontend_login', 'nonce' );

        if ( ! self::password_is_configured() ) {
            wp_send_json_error( array( 'message' => __( 'Upload access has not been configured.', 'wp-fileshelf' ) ), 403 );
        }

        $attempts = self::login_attempts();
        if ( $attempts >= self::LOGIN_LIMIT ) {
            wp_send_json_error( array( 'message' => __( 'Too many password attempts. Please try again later.', 'wp-fileshelf' ) ), 429 );
        }

        $password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
        $hash     = (string) get_option( 'wfs_upload_password_hash', '' );

        if ( '' === $password || ! wp_check_password( $password, $hash ) ) {
            self::record_failed_login( $attempts + 1 );
            wp_send_json_error( array( 'message' => __( 'Incorrect password.', 'wp-fileshelf' ) ), 403 );
        }

        delete_transient( self::login_transient_key() );
        self::set_auth_cookie();
        wp_send_json_success( array( 'message' => __( 'Access granted.', 'wp-fileshelf' ) ) );
    }

    public static function ajax_upload(): void {
        check_ajax_referer( 'wfs_frontend_upload', 'nonce' );

        if ( ! self::is_authenticated() ) {
            wp_send_json_error( array( 'message' => __( 'Your FileShelf upload session has expired. Reload the page and sign in again.', 'wp-fileshelf' ) ), 403 );
        }

        if ( empty( $_FILES['file'] ) || ! is_array( $_FILES['file'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Please choose a file to upload.', 'wp-fileshelf' ) ), 400 );
        }

        $display_name = isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '';
        $replace      = ! empty( $_POST['replace_existing'] );
        $result       = WFS_Files::upload_new( $_FILES['file'], $display_name, $replace );

        if ( is_wp_error( $result ) ) {
            if ( 'wfs_duplicate' === $result->get_error_code() ) {
                $data            = $result->get_error_data();
                $data            = is_array( $data ) ? $data : array();
                $data['code']    = 'duplicate';
                $data['message'] = $result->get_error_message();
                wp_send_json_error( $data, 409 );
            }

            wp_send_json_error(
                array(
                    'code'    => $result->get_error_code(),
                    'message' => $result->get_error_message(),
                ),
                400
            );
        }

        wp_send_json_success(
            array(
                'message'  => $replace ? __( 'Existing file replaced successfully.', 'wp-fileshelf' ) : __( 'File uploaded successfully.', 'wp-fileshelf' ),
                'replaced' => $replace,
                'filename' => (string) $result->filename,
                'url'      => WFS_Files::public_url( (string) $result->filename ),
            )
        );
    }

    public static function ajax_logout(): void {
        check_ajax_referer( 'wfs_frontend_logout', 'nonce' );
        self::clear_auth_cookie();
        wp_send_json_success();
    }

    public static function password_is_configured(): bool {
        return '' !== trim( (string) get_option( 'wfs_upload_password_hash', '' ) );
    }

    public static function is_authenticated(): bool {
        if ( ! self::password_is_configured() || empty( $_COOKIE[ self::AUTH_COOKIE ] ) ) {
            return false;
        }

        $cookie = sanitize_text_field( wp_unslash( (string) $_COOKIE[ self::AUTH_COOKIE ] ) );
        $parts  = explode( '.', $cookie, 2 );
        if ( 2 !== count( $parts ) ) {
            return false;
        }

        $expires   = absint( $parts[0] );
        $signature = (string) $parts[1];
        if ( $expires < time() || $expires > time() + self::AUTH_TTL + 60 ) {
            return false;
        }

        $expected = self::auth_signature( $expires );
        return hash_equals( $expected, $signature );
    }

    private static function set_auth_cookie(): void {
        $expires = time() + self::AUTH_TTL;
        $value   = $expires . '.' . self::auth_signature( $expires );
        $path    = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
        $domain  = defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '';

        setcookie(
            self::AUTH_COOKIE,
            $value,
            array(
                'expires'  => $expires,
                'path'     => $path,
                'domain'   => $domain,
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            )
        );
    }

    private static function clear_auth_cookie(): void {
        $path   = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
        $domain = defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '';
        setcookie(
            self::AUTH_COOKIE,
            '',
            array(
                'expires'  => time() - HOUR_IN_SECONDS,
                'path'     => $path,
                'domain'   => $domain,
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            )
        );
    }

    private static function auth_signature( int $expires ): string {
        $password_hash = (string) get_option( 'wfs_upload_password_hash', '' );
        return hash_hmac( 'sha256', $expires . '|' . $password_hash, wp_salt( 'auth' ) );
    }

    private static function login_transient_key(): string {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
        return 'wfs_login_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 32 );
    }

    private static function login_attempts(): int {
        return max( 0, (int) get_transient( self::login_transient_key() ) );
    }

    private static function record_failed_login( int $count ): void {
        set_transient( self::login_transient_key(), max( 1, $count ), self::LOGIN_WINDOW );
    }

    private static function allowed_extensions_label(): string {
        $extensions = array();
        foreach ( WFS_Files::allowed_mime_keys() as $key ) {
            foreach ( explode( '|', $key ) as $extension ) {
                $extension = trim( $extension );
                if ( '' !== $extension ) {
                    $extensions[] = '.' . $extension;
                }
            }
        }

        $extensions = array_values( array_unique( $extensions ) );
        return implode( ', ', $extensions );
    }
}
