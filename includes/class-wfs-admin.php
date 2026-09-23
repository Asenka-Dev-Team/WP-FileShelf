<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class WFS_Admin {
    private const PAGE_SLUG = 'wp-fileshelf';
    private const PER_PAGE  = 20;

    public static function init(): void {
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
        add_action( 'admin_head', array( __CLASS__, 'admin_menu_icon_css' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

        add_action( 'wp_ajax_wfs_render_admin_view', array( __CLASS__, 'ajax_render_admin_view' ) );
        add_action( 'wp_ajax_wfs_upload_file', array( __CLASS__, 'ajax_upload_file' ) );
        add_action( 'wp_ajax_wfs_update_file', array( __CLASS__, 'ajax_update_file' ) );
        add_action( 'wp_ajax_wfs_delete_file', array( __CLASS__, 'ajax_delete_file' ) );
        add_action( 'wp_ajax_wfs_save_settings', array( __CLASS__, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_wfs_save_advanced', array( __CLASS__, 'ajax_save_advanced' ) );
        add_action( 'wp_ajax_wfs_check_updates', array( __CLASS__, 'ajax_check_updates' ) );

        add_filter( 'plugin_action_links_' . plugin_basename( WFS_FILE ), array( __CLASS__, 'plugin_action_links' ) );
        add_filter( 'plugin_row_meta', array( __CLASS__, 'plugin_row_meta' ), 10, 2 );
    }

    public static function admin_menu(): void {
        add_menu_page(
            __( 'WP FileShelf', 'wp-fileshelf' ),
            __( 'WP FileShelf', 'wp-fileshelf' ),
            'manage_options',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_page' ),
            WFS_URL . 'assets/images/icon--wp-fileshelf.svg',
            58
        );
    }

    public static function admin_menu_icon_css(): void {
        ?>
        <style id="wfs-admin-menu-icon-css">
            #adminmenu .toplevel_page_<?php echo esc_attr( self::PAGE_SLUG ); ?> .wp-menu-image img {
                width: 20px !important;
                height: 20px !important;
                max-width: 20px !important;
                max-height: 20px !important;
                object-fit: contain;
            }
        </style>
        <?php
    }

    public static function enqueue_assets( string $hook ): void {
        if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
            return;
        }

        wp_enqueue_style( 'dashicons' );
        wp_enqueue_style( 'wfs-admin', WFS_URL . 'assets/css/admin.css', array(), WFS_VERSION );
        wp_enqueue_script( 'wfs-admin', WFS_URL . 'assets/js/admin.js', array( 'jquery' ), WFS_VERSION, true );

        wp_localize_script(
            'wfs-admin',
            'WFSAdmin',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'pageUrl' => admin_url( 'admin.php?page=' . self::PAGE_SLUG ),
                'nonce'   => wp_create_nonce( 'wfs_admin' ),
                'strings' => array(
                    'loading'       => __( 'Loading…', 'wp-fileshelf' ),
                    'saving'        => __( 'Saving…', 'wp-fileshelf' ),
                    'uploading'     => __( 'Uploading…', 'wp-fileshelf' ),
                    'deleting'      => __( 'Deleting…', 'wp-fileshelf' ),
                    'checking'      => __( 'Checking…', 'wp-fileshelf' ),
                    'copied'        => __( 'Copied!', 'wp-fileshelf' ),
                    'view'          => __( 'View', 'wp-fileshelf' ),
                    'hide'          => __( 'Hide', 'wp-fileshelf' ),
                    'genericError'  => __( 'Something went wrong. Please try again.', 'wp-fileshelf' ),
                    'confirmDelete' => __( 'Delete this file permanently? Its public FileShelf link will stop working. This cannot be undone.', 'wp-fileshelf' ),
                ),
            )
        );
    }

    public static function plugin_action_links( array $links ): array {
        array_unshift(
            $links,
            '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=settings' ) ) . '">' . esc_html__( 'Settings', 'wp-fileshelf' ) . '</a>'
        );
        return $links;
    }

    public static function plugin_row_meta( array $links, string $file ): array {
        if ( plugin_basename( WFS_FILE ) === $file ) {
            $links[] = '<a href="https://asenka.com/" target="_blank" rel="noopener noreferrer">Asenka.com</a>';
        }
        return $links;
    }

    public static function ajax_render_admin_view(): void {
        self::ajax_guard();
        $tab   = self::tab_from_request();
        $state = self::list_state_from_request();
        self::ajax_send_page( array_merge( array( 'tab' => $tab ), 'files' === $tab ? $state : array() ) );
    }

    public static function ajax_upload_file(): void {
        self::ajax_guard();

        if ( empty( $_FILES['file'] ) || ! is_array( $_FILES['file'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Please choose a file to upload.', 'wp-fileshelf' ) ), 400 );
        }

        $display_name = isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '';
        $result       = WFS_Files::upload_new( $_FILES['file'], $display_name, false );

        if ( is_wp_error( $result ) ) {
            $message = $result->get_error_message();
            if ( 'wfs_duplicate' === $result->get_error_code() ) {
                $message .= ' ' . __( 'Use the pencil icon in Files to replace the existing item while keeping its link.', 'wp-fileshelf' );
            }
            wp_send_json_error( array( 'message' => $message ), 400 );
        }

        self::ajax_send_page(
            array(
                'tab'        => 'files',
                'orderby'    => 'modified_at',
                'order'      => 'desc',
                'paged'      => 1,
                'wfs_notice' => 'uploaded',
            ),
            array(
                'file' => array(
                    'id'       => (int) $result->id,
                    'filename' => (string) $result->filename,
                    'url'      => WFS_Files::public_url( (string) $result->filename ),
                ),
            )
        );
    }

    public static function ajax_update_file(): void {
        self::ajax_guard();

        $id           = isset( $_POST['file_id'] ) ? absint( $_POST['file_id'] ) : 0;
        $display_name = isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '';
        $replacement  = isset( $_FILES['replacement_file'] ) && is_array( $_FILES['replacement_file'] ) && ! empty( $_FILES['replacement_file']['name'] )
            ? $_FILES['replacement_file']
            : null;

        $result = WFS_Files::update_record( $id, $display_name, $replacement );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }

        $state = self::list_state_from_request();
        self::ajax_send_page(
            array_merge(
                array(
                    'tab'        => 'files',
                    'wfs_notice' => null !== $replacement ? 'replaced' : 'updated',
                ),
                $state
            )
        );
    }

    public static function ajax_delete_file(): void {
        self::ajax_guard();

        $id     = isset( $_POST['file_id'] ) ? absint( $_POST['file_id'] ) : 0;
        $record = WFS_Files::get_by_id( $id );
        if ( ! $record || ! WFS_Files::delete_record( $id ) ) {
            wp_send_json_error( array( 'message' => __( 'WP FileShelf could not delete that file.', 'wp-fileshelf' ) ), 400 );
        }

        $state = self::list_state_from_request();
        self::ajax_send_page(
            array_merge(
                array(
                    'tab'        => 'files',
                    'wfs_notice' => 'deleted',
                ),
                $state
            )
        );
    }

    public static function ajax_save_settings(): void {
        self::ajax_guard();

        $slug = isset( $_POST['wfs_link_slug'] ) ? sanitize_title( wp_unslash( $_POST['wfs_link_slug'] ) ) : '';
        if ( '' === $slug ) {
            wp_send_json_error( array( 'message' => __( 'Please enter a public file link path.', 'wp-fileshelf' ) ), 400 );
        }

        $slug_error = self::validate_link_slug( $slug );
        if ( is_wp_error( $slug_error ) ) {
            wp_send_json_error( array( 'message' => $slug_error->get_error_message() ), 400 );
        }

        $clear_password = ! empty( $_POST['wfs_clear_password'] );
        $new_password   = isset( $_POST['wfs_upload_password'] ) ? (string) wp_unslash( $_POST['wfs_upload_password'] ) : '';
        if ( ! $clear_password && '' !== $new_password && strlen( $new_password ) < 8 ) {
            wp_send_json_error( array( 'message' => __( 'Use an upload password with at least 8 characters.', 'wp-fileshelf' ) ), 400 );
        }

        $old_slug = WFS_Router::link_slug();
        update_option( 'wfs_link_slug', $slug, false );

        if ( $clear_password ) {
            delete_option( 'wfs_upload_password_hash' );
        } elseif ( '' !== $new_password ) {
            update_option( 'wfs_upload_password_hash', wp_hash_password( $new_password ), false );
        }

        if ( $old_slug !== $slug ) {
            WFS_Router::register_rewrite_rules();
            flush_rewrite_rules( false );
            update_option( 'wfs_rewrite_slug', $slug, false );
            update_option( 'wfs_rewrite_version', WFS_VERSION, false );
        }

        self::ajax_send_page(
            array(
                'tab'        => 'settings',
                'wfs_notice' => 'settings-saved',
            )
        );
    }

    public static function ajax_save_advanced(): void {
        self::ajax_guard();

        $posted    = isset( $_POST['wfs_allowed_mime_keys'] ) && is_array( $_POST['wfs_allowed_mime_keys'] )
            ? array_map( 'strval', wp_unslash( $_POST['wfs_allowed_mime_keys'] ) )
            : array();
        $supported = WFS_Files::supported_mimes();
        $allowed   = array_values( array_intersect( $posted, array_keys( $supported ) ) );

        update_option( 'wfs_allowed_mime_keys', $allowed, false );
        update_option( 'wfs_delete_on_uninstall', ! empty( $_POST['wfs_delete_on_uninstall'] ) ? '1' : '0', false );

        self::ajax_send_page(
            array(
                'tab'        => 'advanced',
                'wfs_notice' => 'advanced-saved',
            )
        );
    }

    public static function ajax_check_updates(): void {
        self::ajax_guard();
        $status = WFS_Updater::force_check();
        self::ajax_send_page(
            array(
                'tab'        => 'settings',
                'wfs_notice' => 'update-checked',
            ),
            array( 'diagnostics' => $status )
        );
    }

    private static function ajax_guard(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-fileshelf' ) ), 403 );
        }
        check_ajax_referer( 'wfs_admin', 'nonce' );
    }

    private static function validate_link_slug( string $slug ): bool|WP_Error {
        $reserved = array(
            WFS_UPLOAD_ROUTE,
            WFS_STORAGE_DIRNAME,
            'wp-admin',
            'wp-content',
            'wp-includes',
            'wp-json',
        );

        if ( in_array( $slug, $reserved, true ) ) {
            return new WP_Error( 'wfs_reserved_slug', __( 'That link path is reserved by WordPress or WP FileShelf. Please choose another.', 'wp-fileshelf' ) );
        }

        $old_slug = WFS_Router::link_slug();
        if ( $slug !== $old_slug && function_exists( 'url_to_postid' ) ) {
            $post_id = url_to_postid( home_url( '/' . $slug . '/' ) );
            if ( $post_id > 0 ) {
                return new WP_Error( 'wfs_slug_conflict', __( 'A WordPress page or post already uses that path. Please choose another.', 'wp-fileshelf' ) );
            }
        }

        return true;
    }

    private static function available_tabs(): array {
        return array( 'files', 'settings', 'advanced' );
    }

    private static function tab_from_request(): string {
        $raw = isset( $_GET['tab'] )
            ? $_GET['tab']
            : ( $_POST['tab'] ?? 'files' );
        $tab = sanitize_key( wp_unslash( (string) $raw ) );
        return in_array( $tab, self::available_tabs(), true ) ? $tab : 'files';
    }

    /**
     * @return array{orderby:string,order:string,paged:int,s:string}
     */
    private static function list_state_from_request(): array {
        $raw_orderby = isset( $_GET['orderby'] ) ? $_GET['orderby'] : ( $_POST['orderby'] ?? 'modified_at' );
        $raw_order   = isset( $_GET['order'] ) ? $_GET['order'] : ( $_POST['order'] ?? 'desc' );
        $raw_paged   = isset( $_GET['paged'] ) ? $_GET['paged'] : ( $_POST['paged'] ?? 1 );
        $raw_search  = isset( $_GET['s'] ) ? $_GET['s'] : ( $_POST['s'] ?? '' );

        $orderby = sanitize_key( wp_unslash( (string) $raw_orderby ) );
        $order   = strtolower( sanitize_key( wp_unslash( (string) $raw_order ) ) );
        $paged   = max( 1, absint( $raw_paged ) );
        $search  = sanitize_text_field( wp_unslash( (string) $raw_search ) );

        if ( ! in_array( $orderby, array( 'filename', 'display_name', 'created_at', 'modified_at' ), true ) ) {
            $orderby = 'modified_at';
        }

        return array(
            'orderby' => $orderby,
            'order'   => 'asc' === $order ? 'asc' : 'desc',
            'paged'   => $paged,
            's'       => $search,
        );
    }

    private static function ajax_send_page( array $query, array $extra = array() ): void {
        $old_get = $_GET;
        $_GET    = array_merge( array( 'page' => self::PAGE_SLUG ), $query );

        ob_start();
        self::render_page();
        $html = (string) ob_get_clean();
        $_GET = $old_get;

        $url_args = array( 'page' => self::PAGE_SLUG, 'tab' => $query['tab'] ?? 'files' );
        foreach ( array( 'orderby', 'order', 'paged', 's' ) as $key ) {
            if ( isset( $query[ $key ] ) && '' !== (string) $query[ $key ] ) {
                $url_args[ $key ] = $query[ $key ];
            }
        }

        $payload = array_merge(
            array(
                'html' => $html,
                'url'  => add_query_arg( $url_args, admin_url( 'admin.php' ) ),
            ),
            $extra
        );

        wp_send_json_success( $payload );
    }

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage WP FileShelf.', 'wp-fileshelf' ) );
        }

        $tab = self::tab_from_request();
        ?>
        <div class="wrap wfs-wrap">
            <header class="wfs-page-header">
                <div class="wfs-brand">
                    <div class="wfs-logo-shell">
                        <img src="<?php echo esc_url( WFS_URL . 'assets/images/icon--wp-fileshelf.svg' ); ?>" alt="">
                    </div>
                    <div class="wfs-info-shell">
                        <span class="wfs-eyebrow"><?php esc_html_e( 'Asenka Interactive', 'wp-fileshelf' ); ?></span>
                        <h1><?php esc_html_e( 'WP FileShelf', 'wp-fileshelf' ); ?></h1>
                        <p class="quip"><?php esc_html_e( 'Stable file links. Simple staff-managed storage.', 'wp-fileshelf' ); ?></p>
                    </div>
                </div>
            </header>

            <?php self::render_tabs( $tab ); ?>
            <?php self::render_notice(); ?>

            <div class="wfs-view">
                <?php
                switch ( $tab ) {
                    case 'settings':
                        self::render_settings_tab();
                        break;
                    case 'advanced':
                        self::render_advanced_tab();
                        break;
                    case 'files':
                    default:
                        self::render_files_tab();
                        break;
                }
                ?>
            </div>

            <footer class="wfs-footer">
                <span><?php echo esc_html( 'WP FileShelf ' . WFS_VERSION ); ?></span>
                <span aria-hidden="true">•</span>
                <a href="https://asenka.com/" target="_blank" rel="noopener noreferrer">Asenka Interactive</a>
            </footer>
        </div>
        <?php
    }

    private static function render_tabs( string $active ): void {
        $tabs = array(
            'files'    => __( 'Files', 'wp-fileshelf' ),
            'settings' => __( 'Settings', 'wp-fileshelf' ),
            'advanced' => __( 'Advanced', 'wp-fileshelf' ),
        );
        ?>
        <nav class="nav-tab-wrapper wfs-tabs" aria-label="<?php echo esc_attr__( 'WP FileShelf sections', 'wp-fileshelf' ); ?>">
            <?php foreach ( $tabs as $slug => $label ) : ?>
                <?php $url = add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => $slug ), admin_url( 'admin.php' ) ); ?>
                <a class="nav-tab <?php echo $active === $slug ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>" data-wfs-nav>
                    <?php echo esc_html( $label ); ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php
    }

    private static function render_notice(): void {
        $notice = isset( $_GET['wfs_notice'] ) ? sanitize_key( wp_unslash( $_GET['wfs_notice'] ) ) : '';
        $map    = array(
            'uploaded'       => __( 'File uploaded to FileShelf.', 'wp-fileshelf' ),
            'updated'        => __( 'File details updated.', 'wp-fileshelf' ),
            'replaced'       => __( 'File replaced. Its existing filename and public link were preserved.', 'wp-fileshelf' ),
            'deleted'        => __( 'File deleted from FileShelf.', 'wp-fileshelf' ),
            'settings-saved' => __( 'FileShelf settings saved.', 'wp-fileshelf' ),
            'advanced-saved' => __( 'Advanced settings saved.', 'wp-fileshelf' ),
            'update-checked' => __( 'GitHub update check completed.', 'wp-fileshelf' ),
        );

        if ( isset( $map[ $notice ] ) ) {
            echo '<div class="notice notice-success is-dismissible wfs-ajax-notice"><p>' . esc_html( $map[ $notice ] ) . '</p></div>';
        }
    }

    private static function render_files_tab(): void {
        $state = self::list_state_from_request();
        $list  = WFS_Files::get_list( $state['paged'], self::PER_PAGE, $state['orderby'], $state['order'], $state['s'] );
        ?>
        <?php self::render_upload_section(); ?>

        <section class="wfs-card">
            <div class="wfs-card-heading wfs-card-heading-files">
                <div>
                    <span class="wfs-eyebrow"><?php esc_html_e( 'Directory', 'wp-fileshelf' ); ?></span>
                    <h2><?php esc_html_e( 'Files', 'wp-fileshelf' ); ?></h2>
                    <p><?php echo esc_html( sprintf( _n( '%d file', '%d files', (int) $list['total'], 'wp-fileshelf' ), (int) $list['total'] ) ); ?></p>
                </div>
                <form class="wfs-search-form" data-wfs-search>
                    <input type="search" name="s" value="<?php echo esc_attr( $state['s'] ); ?>" placeholder="<?php echo esc_attr__( 'Search filename or name…', 'wp-fileshelf' ); ?>">
                    <button type="submit" class="button"><?php esc_html_e( 'Search', 'wp-fileshelf' ); ?></button>
                    <?php if ( '' !== $state['s'] ) : ?>
                        <a class="button" data-wfs-nav href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'files' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Clear', 'wp-fileshelf' ); ?></a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="wfs-table-wrap">
                <table class="widefat fixed striped wfs-table">
                    <thead>
                        <tr>
                            <?php self::render_sortable_th( __( 'Filename', 'wp-fileshelf' ), 'filename', $state ); ?>
                            <?php self::render_sortable_th( __( 'Name', 'wp-fileshelf' ), 'display_name', $state ); ?>
                            <?php self::render_sortable_th( __( 'Uploaded', 'wp-fileshelf' ), 'created_at', $state ); ?>
                            <?php self::render_sortable_th( __( 'Modified', 'wp-fileshelf' ), 'modified_at', $state ); ?>
                            <th class="wfs-actions-column"><?php esc_html_e( 'Actions', 'wp-fileshelf' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $list['items'] ) ) : ?>
                            <tr><td colspan="5" class="wfs-empty-state"><?php esc_html_e( 'No FileShelf files found.', 'wp-fileshelf' ); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ( $list['items'] as $item ) : ?>
                                <?php self::render_file_row( $item, $state ); ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php self::render_pagination( $list, $state ); ?>
        </section>
        <?php
    }

    private static function render_file_row( object $item, array $state ): void {
        $url      = WFS_Files::public_url( (string) $item->filename );
        $uploaded = self::format_date( (string) $item->created_at );
        $modified = self::format_date( (string) $item->modified_at );
        ?>
        <tr class="wfs-file-row" data-file-id="<?php echo esc_attr( (string) $item->id ); ?>">
            <td>
                <a class="wfs-file-title" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( (string) $item->filename ); ?></a>
                <span class="wfs-meta"><?php echo esc_html( size_format( (int) $item->file_size ) ); ?> · <?php echo esc_html( (string) $item->mime_type ); ?></span>
            </td>
            <td><?php echo '' !== (string) $item->display_name ? esc_html( (string) $item->display_name ) : '<span class="wfs-muted">—</span>'; ?></td>
            <td><?php echo esc_html( $uploaded ); ?></td>
            <td><?php echo esc_html( $modified ); ?></td>
            <td class="wfs-actions-column">
                <div class="wfs-row-actions">
                    <button type="button" class="button button-small wfs-button-with-icon" data-wfs-edit="<?php echo esc_attr( (string) $item->id ); ?>" title="<?php echo esc_attr__( 'Edit / replace', 'wp-fileshelf' ); ?>">
                        <span class="dashicons dashicons-edit" aria-hidden="true"></span><span><?php esc_html_e( 'Edit', 'wp-fileshelf' ); ?></span>
                    </button>
                    <button type="button" class="button button-small wfs-button-with-icon" data-wfs-copy="<?php echo esc_attr( $url ); ?>" title="<?php echo esc_attr__( 'Copy link', 'wp-fileshelf' ); ?>">
                        <span class="dashicons dashicons-admin-links" aria-hidden="true"></span><span><?php esc_html_e( 'Copy Link', 'wp-fileshelf' ); ?></span>
                    </button>
                    <button type="button" class="button button-small wfs-button-with-icon wfs-delete-button" data-wfs-delete="<?php echo esc_attr( (string) $item->id ); ?>" title="<?php echo esc_attr__( 'Delete file', 'wp-fileshelf' ); ?>">
                        <span class="dashicons dashicons-trash" aria-hidden="true"></span><span><?php esc_html_e( 'Delete', 'wp-fileshelf' ); ?></span>
                    </button>
                </div>
            </td>
        </tr>
        <tr class="wfs-edit-row" data-wfs-edit-row="<?php echo esc_attr( (string) $item->id ); ?>" hidden>
            <td colspan="5">
                <form class="wfs-edit-form" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="wfs_update_file">
                    <input type="hidden" name="file_id" value="<?php echo esc_attr( (string) $item->id ); ?>">
                    <input type="hidden" name="orderby" value="<?php echo esc_attr( $state['orderby'] ); ?>">
                    <input type="hidden" name="order" value="<?php echo esc_attr( $state['order'] ); ?>">
                    <input type="hidden" name="paged" value="<?php echo esc_attr( (string) $state['paged'] ); ?>">
                    <input type="hidden" name="s" value="<?php echo esc_attr( $state['s'] ); ?>">

                    <label>
                        <span><?php esc_html_e( 'Name / Description', 'wp-fileshelf' ); ?></span>
                        <input type="text" name="display_name" maxlength="255" value="<?php echo esc_attr( (string) $item->display_name ); ?>">
                    </label>
                    <label class="wfs-replacement-field">
                        <span><?php esc_html_e( 'Replace file', 'wp-fileshelf' ); ?></span>
                        <input type="file" name="replacement_file">
                        <small><?php echo esc_html( sprintf( __( 'Optional. Must remain a .%s file. The public filename/link stays %s.', 'wp-fileshelf' ), pathinfo( (string) $item->filename, PATHINFO_EXTENSION ), (string) $item->filename ) ); ?></small>
                    </label>
                    <div class="wfs-edit-actions">
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Changes', 'wp-fileshelf' ); ?></button>
                        <button type="button" class="button" data-wfs-edit-cancel><?php esc_html_e( 'Cancel', 'wp-fileshelf' ); ?></button>
                    </div>
                </form>
            </td>
        </tr>
        <?php
    }

    private static function render_sortable_th( string $label, string $column, array $state ): void {
        $is_current = $state['orderby'] === $column;
        $next_order = $is_current && 'asc' === $state['order'] ? 'desc' : 'asc';
        $url        = add_query_arg(
            array(
                'page'    => self::PAGE_SLUG,
                'tab'     => 'files',
                'orderby' => $column,
                'order'   => $next_order,
                'paged'   => 1,
                's'       => $state['s'],
            ),
            admin_url( 'admin.php' )
        );
        $class = $is_current ? 'sorted ' . esc_attr( $state['order'] ) : 'sortable desc';
        echo '<th class="' . esc_attr( $class ) . '"><a data-wfs-nav href="' . esc_url( $url ) . '"><span>' . esc_html( $label ) . '</span><span class="sorting-indicators"><span class="sorting-indicator asc"></span><span class="sorting-indicator desc"></span></span></a></th>';
    }

    private static function render_pagination( array $list, array $state ): void {
        if ( (int) $list['pages'] <= 1 ) {
            return;
        }

        $base = add_query_arg(
            array(
                'page'    => self::PAGE_SLUG,
                'tab'     => 'files',
                'orderby' => $state['orderby'],
                'order'   => $state['order'],
                's'       => $state['s'],
                'paged'   => '%#%',
            ),
            admin_url( 'admin.php' )
        );

        $base = str_replace( '%25%23%25', '%#%', $base );
        $links = paginate_links(
            array(
                'base'      => $base,
                'format'    => '',
                'current'   => (int) $list['page'],
                'total'     => (int) $list['pages'],
                'type'      => 'array',
                'prev_text' => '‹',
                'next_text' => '›',
            )
        );

        if ( empty( $links ) || ! is_array( $links ) ) {
            return;
        }
        ?>
        <div class="tablenav bottom wfs-pagination"><div class="tablenav-pages">
            <span class="displaying-num"><?php echo esc_html( sprintf( _n( '%d item', '%d items', (int) $list['total'], 'wp-fileshelf' ), (int) $list['total'] ) ); ?></span>
            <span class="pagination-links">
                <?php foreach ( $links as $link ) : ?>
                    <?php echo wp_kses_post( str_replace( '<a ', '<a data-wfs-nav ', $link ) ); ?>
                <?php endforeach; ?>
            </span>
        </div></div>
        <?php
    }

    private static function render_upload_section(): void {
        $allowed = self::allowed_extensions_label();
        ?>
        <section class="wfs-card wfs-upload-section">
            <div class="wfs-card-heading">
                <div>
                    <span class="wfs-eyebrow"><?php esc_html_e( 'Add document', 'wp-fileshelf' ); ?></span>
                    <h2><?php esc_html_e( 'Upload File', 'wp-fileshelf' ); ?></h2>
                    <p><?php esc_html_e( 'Add a new file to FileShelf. Existing filenames can be replaced from the directory below.', 'wp-fileshelf' ); ?></p>
                </div>
            </div>

            <form class="wfs-upload-form wfs-upload-inline-form" enctype="multipart/form-data">
                <input type="hidden" name="action" value="wfs_upload_file">
                <div class="wfs-field-group">
                    <label for="wfs-admin-file"><?php esc_html_e( 'File', 'wp-fileshelf' ); ?></label>
                    <input id="wfs-admin-file" type="file" name="file" required>
                    <?php if ( '' !== $allowed ) : ?>
                        <p class="description"><?php echo esc_html( sprintf( __( 'Allowed: %s', 'wp-fileshelf' ), $allowed ) ); ?></p>
                    <?php else : ?>
                        <p class="description wfs-warning-text"><?php esc_html_e( 'No file types are currently enabled. Choose file types under Advanced first.', 'wp-fileshelf' ); ?></p>
                    <?php endif; ?>
                </div>
                <div class="wfs-field-group">
                    <label for="wfs-admin-display-name"><?php esc_html_e( 'Name / Description', 'wp-fileshelf' ); ?></label>
                    <input id="wfs-admin-display-name" type="text" name="display_name" maxlength="255" placeholder="<?php echo esc_attr__( 'Optional internal description', 'wp-fileshelf' ); ?>">
                </div>
                <div class="wfs-form-actions">
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Upload File', 'wp-fileshelf' ); ?></button>
                </div>
            </form>
        </section>
        <?php
    }

    private static function render_settings_tab(): void {
        $slug         = WFS_Router::link_slug();
        $has_password = WFS_Frontend::password_is_configured();
        $diagnostics  = WFS_Updater::get_diagnostics();
        $example      = home_url( '/' . $slug . '/nj.pdf' );
        $upload_url   = home_url( '/' . WFS_UPLOAD_ROUTE . '/' );
        ?>
        <section class="wfs-card">
            <div class="wfs-card-heading">
                <div>
                    <span class="wfs-eyebrow"><?php esc_html_e( 'Access & links', 'wp-fileshelf' ); ?></span>
                    <h2><?php esc_html_e( 'Settings', 'wp-fileshelf' ); ?></h2>
                </div>
            </div>

            <form class="wfs-settings-form">
                <input type="hidden" name="action" value="wfs_save_settings">
                <div class="wfs-settings-grid">
                    <div class="wfs-setting-panel">
                        <h3><?php esc_html_e( 'Staff Upload Page', 'wp-fileshelf' ); ?></h3>
                        <p><?php esc_html_e( 'Anyone with this URL and the configured password can upload allowed file types.', 'wp-fileshelf' ); ?></p>
                        <div class="wfs-copy-field">
                            <input type="text" readonly value="<?php echo esc_attr( $upload_url ); ?>">
                            <button type="button" class="button" data-wfs-copy="<?php echo esc_attr( $upload_url ); ?>"><?php esc_html_e( 'Copy', 'wp-fileshelf' ); ?></button>
                        </div>

                        <div class="wfs-field-group">
                            <label for="wfs-upload-password"><?php esc_html_e( 'Upload password', 'wp-fileshelf' ); ?></label>
                            <div class="wfs-password-control">
                                <input id="wfs-upload-password" type="password" name="wfs_upload_password" minlength="8" autocomplete="new-password" placeholder="<?php echo esc_attr( $has_password ? __( 'Leave blank to keep current password', 'wp-fileshelf' ) : __( 'Set an upload password', 'wp-fileshelf' ) ); ?>">
                                <button type="button" class="button wfs-password-toggle" data-wfs-password-toggle="wfs-upload-password" aria-pressed="false"><?php esc_html_e( 'View', 'wp-fileshelf' ); ?></button>
                            </div>
                            <p class="description">
                                <?php echo $has_password ? esc_html__( 'A password is currently set. For security, saved passwords are hashed and cannot be displayed. Use View/Hide while entering a new password.', 'wp-fileshelf' ) : esc_html__( 'No password is currently set, so the public upload form is locked. Use View/Hide while entering a password if needed.', 'wp-fileshelf' ); ?>
                            </p>
                        </div>
                        <?php if ( $has_password ) : ?>
                            <label class="wfs-check-label"><input type="checkbox" name="wfs_clear_password" value="1"> <?php esc_html_e( 'Clear password and lock the public upload form', 'wp-fileshelf' ); ?></label>
                        <?php endif; ?>
                    </div>

                    <div class="wfs-setting-panel">
                        <h3><?php esc_html_e( 'Public File Links', 'wp-fileshelf' ); ?></h3>
                        <p><?php esc_html_e( 'This path becomes the public prefix for every FileShelf file.', 'wp-fileshelf' ); ?></p>
                        <div class="wfs-slug-control">
                            <span><?php echo esc_html( trailingslashit( home_url( '/' ) ) ); ?></span>
                            <input type="text" name="wfs_link_slug" value="<?php echo esc_attr( $slug ); ?>" required pattern="[A-Za-z0-9_-]+">
                            <span>/filename.pdf</span>
                        </div>
                        <p class="description"><?php echo esc_html( sprintf( __( 'Example: %s', 'wp-fileshelf' ), $example ) ); ?></p>
                    </div>
                </div>

                <div class="wfs-form-actions">
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'wp-fileshelf' ); ?></button>
                </div>
            </form>
        </section>

        <section class="wfs-card">
            <div class="wfs-card-heading">
                <div>
                    <span class="wfs-eyebrow"><?php esc_html_e( 'GitHub Releases', 'wp-fileshelf' ); ?></span>
                    <h2><?php esc_html_e( 'Plugin Updates', 'wp-fileshelf' ); ?></h2>
                </div>
            </div>
            <div class="wfs-diagnostics">
                <div><span><?php esc_html_e( 'Installed', 'wp-fileshelf' ); ?></span><strong><?php echo esc_html( (string) $diagnostics['installed_version'] ); ?></strong></div>
                <div><span><?php esc_html_e( 'Latest release', 'wp-fileshelf' ); ?></span><strong><?php echo esc_html( '' !== (string) $diagnostics['latest_version'] ? (string) $diagnostics['latest_version'] : '—' ); ?></strong></div>
                <div><span><?php esc_html_e( 'Connection', 'wp-fileshelf' ); ?></span><strong><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) $diagnostics['connection'] ) ) ); ?></strong></div>
                <div><span><?php esc_html_e( 'Last checked', 'wp-fileshelf' ); ?></span><strong><?php echo ! empty( $diagnostics['last_checked'] ) ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $diagnostics['last_checked'] ) ) : '—'; ?></strong></div>
            </div>
            <?php if ( ! empty( $diagnostics['message'] ) ) : ?><p class="description"><?php echo esc_html( (string) $diagnostics['message'] ); ?></p><?php endif; ?>
            <form class="wfs-update-check-form">
                <input type="hidden" name="action" value="wfs_check_updates">
                <button type="submit" class="button wfs-button-with-icon"><span class="dashicons dashicons-update" aria-hidden="true"></span><span><?php esc_html_e( 'Check for Updates', 'wp-fileshelf' ); ?></span></button>
                <a class="button wfs-button-with-icon" href="<?php echo esc_url( WFS_Updater::releases_url() ); ?>" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-external" aria-hidden="true"></span><span><?php esc_html_e( 'GitHub Releases', 'wp-fileshelf' ); ?></span></a>
            </form>
        </section>
        <?php
    }

    private static function render_advanced_tab(): void {
        $supported = WFS_Files::supported_mimes();
        $selected  = WFS_Files::allowed_mime_keys();
        $groups    = self::group_mimes( $supported );
        $delete    = '1' === get_option( 'wfs_delete_on_uninstall', '0' );
        ?>
        <form class="wfs-advanced-form">
            <input type="hidden" name="action" value="wfs_save_advanced">

            <section class="wfs-card">
                <div class="wfs-card-heading">
                    <div>
                        <span class="wfs-eyebrow"><?php esc_html_e( 'Upload policy', 'wp-fileshelf' ); ?></span>
                        <h2><?php esc_html_e( 'Allowed File Types', 'wp-fileshelf' ); ?></h2>
                        <p><?php esc_html_e( 'These choices apply to both the WordPress admin uploader and the password-protected staff uploader.', 'wp-fileshelf' ); ?></p>
                    </div>
                    <div class="wfs-inline-actions">
                        <button type="button" class="button" data-wfs-select-all-mimes><?php esc_html_e( 'Select All', 'wp-fileshelf' ); ?></button>
                        <button type="button" class="button" data-wfs-clear-mimes><?php esc_html_e( 'Clear', 'wp-fileshelf' ); ?></button>
                    </div>
                </div>

                <div class="wfs-mime-groups">
                    <?php foreach ( $groups as $group => $mimes ) : ?>
                        <fieldset class="wfs-mime-group">
                            <legend><?php echo esc_html( $group ); ?></legend>
                            <?php foreach ( $mimes as $extensions => $mime ) : ?>
                                <label>
                                    <input type="checkbox" name="wfs_allowed_mime_keys[]" value="<?php echo esc_attr( $extensions ); ?>" <?php checked( in_array( $extensions, $selected, true ) ); ?>>
                                    <span class="wfs-extension-label"><?php echo esc_html( '.' . str_replace( '|', ' / .', $extensions ) ); ?></span>
                                    <small><?php echo esc_html( $mime ); ?></small>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="wfs-card">
                <div class="wfs-card-heading">
                    <div>
                        <span class="wfs-eyebrow"><?php esc_html_e( 'Removal', 'wp-fileshelf' ); ?></span>
                        <h2><?php esc_html_e( 'Uninstall Behavior', 'wp-fileshelf' ); ?></h2>
                    </div>
                </div>
                <label class="wfs-danger-option">
                    <input type="checkbox" name="wfs_delete_on_uninstall" value="1" <?php checked( $delete ); ?>>
                    <span>
                        <strong><?php esc_html_e( 'Delete all FileShelf data when this plugin is deleted', 'wp-fileshelf' ); ?></strong>
                        <small><?php esc_html_e( 'When enabled, deleting WP FileShelf removes the wp-fileshelf-uploads directory, stored files, FileShelf database table, settings, and update cache. Deactivating never deletes files. Default is off.', 'wp-fileshelf' ); ?></small>
                    </span>
                </label>
            </section>

            <div class="wfs-form-actions wfs-form-actions-sticky">
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Advanced Settings', 'wp-fileshelf' ); ?></button>
            </div>
        </form>
        <?php
    }

    /**
     * @param array<string,string> $mimes
     * @return array<string,array<string,string>>
     */
    private static function group_mimes( array $mimes ): array {
        $groups = array(
            __( 'Images', 'wp-fileshelf' )    => array(),
            __( 'Documents', 'wp-fileshelf' ) => array(),
            __( 'Audio', 'wp-fileshelf' )     => array(),
            __( 'Video', 'wp-fileshelf' )     => array(),
            __( 'Other', 'wp-fileshelf' )     => array(),
        );

        foreach ( $mimes as $extensions => $mime ) {
            if ( str_starts_with( $mime, 'image/' ) ) {
                $groups[ __( 'Images', 'wp-fileshelf' ) ][ $extensions ] = $mime;
            } elseif ( str_starts_with( $mime, 'audio/' ) ) {
                $groups[ __( 'Audio', 'wp-fileshelf' ) ][ $extensions ] = $mime;
            } elseif ( str_starts_with( $mime, 'video/' ) ) {
                $groups[ __( 'Video', 'wp-fileshelf' ) ][ $extensions ] = $mime;
            } elseif ( str_starts_with( $mime, 'application/' ) || str_starts_with( $mime, 'text/' ) ) {
                $groups[ __( 'Documents', 'wp-fileshelf' ) ][ $extensions ] = $mime;
            } else {
                $groups[ __( 'Other', 'wp-fileshelf' ) ][ $extensions ] = $mime;
            }
        }

        return array_filter( $groups );
    }

    private static function allowed_extensions_label(): string {
        $extensions = array();
        foreach ( WFS_Files::allowed_mime_keys() as $key ) {
            foreach ( explode( '|', $key ) as $ext ) {
                $ext = trim( $ext );
                if ( '' !== $ext ) {
                    $extensions[] = '.' . $ext;
                }
            }
        }
        return implode( ', ', array_values( array_unique( $extensions ) ) );
    }

    private static function format_date( string $mysql_date ): string {
        if ( '' === $mysql_date || '0000-00-00 00:00:00' === $mysql_date ) {
            return '—';
        }
        return mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $mysql_date );
    }
}
