<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class WFS_Files {
    private const STORAGE_VERSION = '2';

    public static function init(): void {
        self::maybe_migrate_legacy_storage();
        self::ensure_storage_directory();
    }

    /**
     * Return the active physical storage directory.
     *
     * v0.1.3 moves FileShelf storage into WP_CONTENT_DIR. If an upgrade from an
     * older release cannot be migrated safely, keep using the verified legacy
     * directory until a later request can complete the migration.
     */
    public static function storage_dir(): string {
        if ( self::STORAGE_VERSION !== (string) get_option( 'wfs_storage_version', '' ) && self::legacy_storage_is_valid() ) {
            return self::legacy_storage_dir();
        }

        return self::preferred_storage_dir();
    }

    private static function preferred_storage_dir(): string {
        return trailingslashit( WP_CONTENT_DIR ) . WFS_STORAGE_DIRNAME;
    }

    private static function legacy_storage_dir(): string {
        return trailingslashit( ABSPATH ) . WFS_STORAGE_DIRNAME;
    }

    private static function legacy_storage_is_valid(): bool {
        $legacy = self::legacy_storage_dir();
        $target = self::preferred_storage_dir();

        if ( self::same_path( $legacy, $target ) ) {
            return false;
        }

        return is_dir( $legacy )
            && ! is_link( $legacy )
            && is_file( trailingslashit( $legacy ) . '.wp-fileshelf' );
    }

    public static function marker_path(): string {
        return trailingslashit( self::storage_dir() ) . '.wp-fileshelf';
    }

    public static function ensure_storage_directory(): bool {
        return self::ensure_directory_at( self::storage_dir() );
    }

    private static function ensure_directory_at( string $dir ): bool {
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return false;
        }

        if ( is_link( $dir ) ) {
            return false;
        }

        $marker = trailingslashit( $dir ) . '.wp-fileshelf';
        if ( ! file_exists( $marker ) ) {
            @file_put_contents( $marker, "WP FileShelf storage directory\n" );
        }

        $index = trailingslashit( $dir ) . 'index.php';
        if ( ! file_exists( $index ) ) {
            @file_put_contents( $index, "<?php\n// Silence is golden.\n" );
        }

        // Apache / LiteSpeed: prevent direct HTTP access to the physical shelf.
        $htaccess = trailingslashit( $dir ) . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            $rules = "# WP FileShelf\nOptions -Indexes\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
            @file_put_contents( $htaccess, $rules );
        }

        // IIS equivalent. Nginx ignores both .htaccess and web.config and may
        // require a server-level deny rule for the physical storage URL.
        $webconfig = trailingslashit( $dir ) . 'web.config';
        if ( ! file_exists( $webconfig ) ) {
            $config = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . '<configuration><system.webServer><security><authorization><remove users="*" roles="" verbs="" />'
                . '<add accessType="Deny" users="*" /></authorization></security></system.webServer></configuration>' . "\n";
            @file_put_contents( $webconfig, $config );
        }

        return is_dir( $dir ) && is_writable( $dir ) && is_file( $marker );
    }

    /**
     * Move pre-v0.1.3 root-level storage into WP_CONTENT_DIR without risking
     * deletion of the old shelf when the copy cannot be verified.
     */
    private static function maybe_migrate_legacy_storage(): void {
        if ( self::STORAGE_VERSION === (string) get_option( 'wfs_storage_version', '' ) ) {
            return;
        }

        $legacy = self::legacy_storage_dir();
        $target = self::preferred_storage_dir();

        if ( self::same_path( $legacy, $target ) ) {
            if ( self::ensure_directory_at( $target ) ) {
                update_option( 'wfs_storage_version', self::STORAGE_VERSION, false );
            }
            return;
        }

        if ( ! self::legacy_storage_is_valid() ) {
            if ( self::ensure_directory_at( $target ) ) {
                update_option( 'wfs_storage_version', self::STORAGE_VERSION, false );
            }
            return;
        }

        // Fast path when the source and destination are on the same filesystem.
        if ( ! file_exists( $target ) ) {
            $parent = dirname( $target );
            if ( is_dir( $parent ) && is_writable( $parent ) && @rename( $legacy, $target ) ) {
                if ( self::ensure_directory_at( $target ) ) {
                    update_option( 'wfs_storage_version', self::STORAGE_VERSION, false );
                }
                return;
            }
        }

        // Cross-filesystem / restricted-host fallback: copy, verify, then remove.
        if ( ! self::ensure_directory_at( $target ) ) {
            return;
        }

        if ( ! self::copy_tree_verified( $legacy, $target ) ) {
            return;
        }

        if ( ! self::trees_match( $legacy, $target ) ) {
            return;
        }

        self::delete_tree( $legacy );

        if ( ! is_dir( $legacy ) && self::ensure_directory_at( $target ) ) {
            update_option( 'wfs_storage_version', self::STORAGE_VERSION, false );
        }
    }

    private static function copy_tree_verified( string $source, string $destination ): bool {
        if ( ! is_dir( $source ) || is_link( $source ) || ! self::ensure_directory_at( $destination ) ) {
            return false;
        }

        try {
            $iterator = new FilesystemIterator( $source, FilesystemIterator::SKIP_DOTS );
        } catch ( UnexpectedValueException $e ) {
            return false;
        }

        foreach ( $iterator as $item ) {
            $src = $item->getPathname();
            $dst = trailingslashit( $destination ) . $item->getFilename();

            if ( $item->isLink() ) {
                return false;
            }

            if ( $item->isDir() ) {
                if ( ! self::copy_tree_verified( $src, $dst ) ) {
                    return false;
                }
                continue;
            }

            if ( ! $item->isFile() ) {
                return false;
            }

            if ( file_exists( $dst ) ) {
                if ( ! is_file( $dst ) || ! self::files_match( $src, $dst ) ) {
                    // Protection/support files may be regenerated by v0.1.3.
                    if ( ! in_array( $item->getFilename(), array( '.wp-fileshelf', '.htaccess', 'index.php', 'web.config' ), true ) ) {
                        return false;
                    }
                } else {
                    continue;
                }
            }

            if ( ! @copy( $src, $dst ) || ! self::files_match( $src, $dst ) ) {
                return false;
            }
        }

        return true;
    }

    private static function trees_match( string $source, string $destination ): bool {
        try {
            $iterator = new FilesystemIterator( $source, FilesystemIterator::SKIP_DOTS );
        } catch ( UnexpectedValueException $e ) {
            return false;
        }

        foreach ( $iterator as $item ) {
            if ( $item->isLink() ) {
                return false;
            }

            $dst = trailingslashit( $destination ) . $item->getFilename();
            if ( $item->isDir() ) {
                if ( ! is_dir( $dst ) || ! self::trees_match( $item->getPathname(), $dst ) ) {
                    return false;
                }
            } elseif ( $item->isFile() ) {
                if ( ! is_file( $dst ) || ! self::files_match( $item->getPathname(), $dst ) ) {
                    return false;
                }
            } else {
                return false;
            }
        }

        return true;
    }

    private static function files_match( string $a, string $b ): bool {
        if ( ! is_file( $a ) || ! is_file( $b ) ) {
            return false;
        }

        $size_a = @filesize( $a );
        $size_b = @filesize( $b );
        if ( false === $size_a || false === $size_b || $size_a !== $size_b ) {
            return false;
        }

        $hash_a = @hash_file( 'sha256', $a );
        $hash_b = @hash_file( 'sha256', $b );
        return is_string( $hash_a ) && is_string( $hash_b ) && hash_equals( $hash_a, $hash_b );
    }

    private static function delete_tree( string $path ): void {
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
                self::delete_tree( $child );
            }
        }

        @rmdir( $path );
    }

    private static function same_path( string $a, string $b ): bool {
        $normalize = static function ( string $path ): string {
            return strtolower( rtrim( wp_normalize_path( $path ), '/' ) );
        };

        return $normalize( $a ) === $normalize( $b );
    }

    /**
     * @return array<string,string>
     */
    public static function supported_mimes(): array {
        $mimes = get_allowed_mime_types();
        return is_array( $mimes ) ? $mimes : array();
    }

    /**
     * @return string[]
     */
    public static function default_allowed_mime_keys(): array {
        $keys = array();
        foreach ( self::supported_mimes() as $extensions => $mime ) {
            $parts = explode( '|', (string) $extensions );
            if ( in_array( 'pdf', $parts, true ) || 'application/pdf' === $mime ) {
                $keys[] = (string) $extensions;
            }
        }

        return $keys;
    }

    /**
     * @return string[]
     */
    public static function allowed_mime_keys(): array {
        $saved = get_option( 'wfs_allowed_mime_keys', self::default_allowed_mime_keys() );
        if ( ! is_array( $saved ) ) {
            return self::default_allowed_mime_keys();
        }

        $supported = self::supported_mimes();
        return array_values( array_intersect( array_map( 'strval', $saved ), array_keys( $supported ) ) );
    }

    /**
     * @return array<string,string>
     */
    public static function allowed_mimes(): array {
        $supported = self::supported_mimes();
        $allowed   = array();

        foreach ( self::allowed_mime_keys() as $key ) {
            if ( isset( $supported[ $key ] ) ) {
                $allowed[ $key ] = $supported[ $key ];
            }
        }

        return $allowed;
    }

    public static function filename_key( string $filename ): string {
        return hash( 'sha256', strtolower( $filename ) );
    }

    public static function get_by_id( int $id ): ?object {
        global $wpdb;
        $table = WFS_DB::files_table();
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ) );
        return is_object( $row ) ? $row : null;
    }

    public static function get_by_filename( string $filename ): ?object {
        global $wpdb;
        $table = WFS_DB::files_table();
        $key   = self::filename_key( sanitize_file_name( $filename ) );
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE filename_key = %s LIMIT 1", $key ) );
        return is_object( $row ) ? $row : null;
    }

    /**
     * @return array{items:array<int,object>,total:int,pages:int,page:int}
     */
    public static function get_list( int $page = 1, int $per_page = 20, string $orderby = 'modified_at', string $order = 'desc', string $search = '' ): array {
        global $wpdb;

        $sortable = array( 'filename', 'display_name', 'created_at', 'modified_at' );
        if ( ! in_array( $orderby, $sortable, true ) ) {
            $orderby = 'modified_at';
        }
        $order = 'asc' === strtolower( $order ) ? 'ASC' : 'DESC';

        $page     = max( 1, $page );
        $per_page = max( 1, min( 100, $per_page ) );
        $table    = WFS_DB::files_table();
        $where    = '';
        $params   = array();

        if ( '' !== trim( $search ) ) {
            $like     = '%' . $wpdb->esc_like( trim( $search ) ) . '%';
            $where    = ' WHERE filename LIKE %s OR display_name LIKE %s ';
            $params[] = $like;
            $params[] = $like;
        }

        $count_sql = "SELECT COUNT(*) FROM {$table}{$where}";
        $total     = empty( $params )
            ? (int) $wpdb->get_var( $count_sql )
            : (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );

        $pages  = max( 1, (int) ceil( $total / $per_page ) );
        $page   = min( $page, $pages );
        $offset = ( $page - 1 ) * $per_page;

        $sql        = "SELECT * FROM {$table}{$where} ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d";
        $list_params = $params;
        $list_params[] = $per_page;
        $list_params[] = $offset;
        $prepared = $wpdb->prepare( $sql, $list_params );
        $items    = $wpdb->get_results( $prepared );

        return array(
            'items' => is_array( $items ) ? $items : array(),
            'total' => $total,
            'pages' => $pages,
            'page'  => $page,
        );
    }

    public static function public_url( string $filename ): string {
        $slug = WFS_Router::link_slug();
        return home_url( '/' . rawurlencode( $slug ) . '/' . rawurlencode( $filename ) );
    }

    /**
     * @param array<string,mixed> $file A PHP upload array.
     * @return array<string,mixed>|WP_Error
     */
    public static function validate_upload( array $file ): array|WP_Error {
        if ( empty( $file['name'] ) || ! is_string( $file['name'] ) ) {
            return new WP_Error( 'wfs_no_file', __( 'Please choose a file to upload.', 'wp-fileshelf' ) );
        }

        $error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if ( UPLOAD_ERR_OK !== $error ) {
            return new WP_Error( 'wfs_upload_error', self::upload_error_message( $error ) );
        }

        $tmp_name = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
        if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
            return new WP_Error( 'wfs_invalid_upload', __( 'The uploaded file could not be verified.', 'wp-fileshelf' ) );
        }

        $filename = sanitize_file_name( wp_unslash( $file['name'] ) );
        if ( '' === $filename ) {
            return new WP_Error( 'wfs_invalid_filename', __( 'The uploaded filename is not valid.', 'wp-fileshelf' ) );
        }

        $allowed = self::allowed_mimes();
        if ( empty( $allowed ) ) {
            return new WP_Error( 'wfs_no_allowed_types', __( 'No file types are currently enabled in WP FileShelf.', 'wp-fileshelf' ) );
        }

        $checked = wp_check_filetype_and_ext( $tmp_name, $filename, $allowed );
        $ext     = isset( $checked['ext'] ) ? (string) $checked['ext'] : '';
        $type    = isset( $checked['type'] ) ? (string) $checked['type'] : '';

        // Some document formats are not deeply sniffable by PHP. Fall back to the
        // configured WordPress extension map, but never accept an unconfigured extension.
        if ( '' === $ext || '' === $type ) {
            $by_name = wp_check_filetype( $filename, $allowed );
            $ext     = isset( $by_name['ext'] ) ? (string) $by_name['ext'] : '';
            $type    = isset( $by_name['type'] ) ? (string) $by_name['type'] : '';
        }

        if ( '' === $ext || '' === $type ) {
            return new WP_Error( 'wfs_type_not_allowed', __( 'That file type is not allowed by WP FileShelf.', 'wp-fileshelf' ) );
        }

        $size = isset( $file['size'] ) ? max( 0, (int) $file['size'] ) : 0;
        if ( 0 === $size ) {
            $size = (int) @filesize( $tmp_name );
        }

        if ( $size > wp_max_upload_size() ) {
            return new WP_Error( 'wfs_too_large', __( 'That file is larger than the WordPress upload limit.', 'wp-fileshelf' ) );
        }

        return array(
            'filename' => $filename,
            'tmp_name' => $tmp_name,
            'mime_type'=> $type,
            'extension'=> strtolower( $ext ),
            'size'     => $size,
        );
    }

    /**
     * Create a new shelf item or replace an exact filename match.
     *
     * @param array<string,mixed> $file
     * @return object|WP_Error
     */
    public static function upload_new( array $file, string $display_name = '', bool $replace_existing = false ): object {
        global $wpdb;

        $validated = self::validate_upload( $file );
        if ( is_wp_error( $validated ) ) {
            return $validated;
        }

        $filename = (string) $validated['filename'];
        $existing = self::get_by_filename( $filename );

        if ( $existing ) {
            if ( ! $replace_existing ) {
                $error = new WP_Error( 'wfs_duplicate', sprintf( __( 'A file named %s already exists.', 'wp-fileshelf' ), $filename ) );
                $error->add_data(
                    array(
                        'id'           => (int) $existing->id,
                        'filename'     => (string) $existing->filename,
                        'display_name' => (string) $existing->display_name,
                        'url'          => self::public_url( (string) $existing->filename ),
                    )
                );
                return $error;
            }

            return self::replace_record( (int) $existing->id, $file, null );
        }

        if ( ! self::ensure_storage_directory() ) {
            return new WP_Error( 'wfs_storage_unwritable', __( 'The WP FileShelf storage directory is not writable.', 'wp-fileshelf' ) );
        }

        $target = trailingslashit( self::storage_dir() ) . $filename;
        if ( file_exists( $target ) ) {
            return new WP_Error( 'wfs_orphan_conflict', __( 'A physical file with that name already exists but is not registered in FileShelf. Rename it or remove it before uploading.', 'wp-fileshelf' ) );
        }

        if ( ! @move_uploaded_file( (string) $validated['tmp_name'], $target ) ) {
            return new WP_Error( 'wfs_move_failed', __( 'WP FileShelf could not move the uploaded file into storage.', 'wp-fileshelf' ) );
        }

        @chmod( $target, 0644 );

        $now      = current_time( 'mysql' );
        $checksum = is_file( $target ) ? (string) @hash_file( 'sha256', $target ) : '';
        $size     = is_file( $target ) ? (int) @filesize( $target ) : (int) $validated['size'];
        $name     = sanitize_text_field( $display_name );

        $inserted = $wpdb->insert(
            WFS_DB::files_table(),
            array(
                'filename'          => $filename,
                'filename_key'      => self::filename_key( $filename ),
                'display_name'      => $name,
                'original_filename' => $filename,
                'mime_type'         => (string) $validated['mime_type'],
                'file_size'         => max( 0, $size ),
                'checksum'          => $checksum,
                'created_at'        => $now,
                'modified_at'       => $now,
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
        );

        if ( false === $inserted ) {
            @unlink( $target );
            return new WP_Error( 'wfs_db_insert_failed', __( 'The file was uploaded, but FileShelf could not save its database record.', 'wp-fileshelf' ) );
        }

        return self::get_by_id( (int) $wpdb->insert_id ) ?: (object) array();
    }

    /**
     * Update display name and optionally replace the physical file while preserving filename/link.
     *
     * @param array<string,mixed>|null $replacement_file
     * @return object|WP_Error
     */
    public static function update_record( int $id, string $display_name, ?array $replacement_file = null ): object {
        global $wpdb;

        $record = self::get_by_id( $id );
        if ( ! $record ) {
            return new WP_Error( 'wfs_not_found', __( 'That FileShelf item no longer exists.', 'wp-fileshelf' ) );
        }

        if ( null !== $replacement_file && ! empty( $replacement_file['name'] ) ) {
            $replaced = self::replace_record( $id, $replacement_file, $display_name );
            if ( is_wp_error( $replaced ) ) {
                return $replaced;
            }
            return $replaced;
        }

        $updated = $wpdb->update(
            WFS_DB::files_table(),
            array( 'display_name' => sanitize_text_field( $display_name ) ),
            array( 'id' => $id ),
            array( '%s' ),
            array( '%d' )
        );

        if ( false === $updated ) {
            return new WP_Error( 'wfs_db_update_failed', __( 'WP FileShelf could not update that file record.', 'wp-fileshelf' ) );
        }

        return self::get_by_id( $id ) ?: $record;
    }

    /**
     * @param array<string,mixed> $file
     * @return object|WP_Error
     */
    public static function replace_record( int $id, array $file, ?string $display_name = null ): object {
        global $wpdb;

        $record = self::get_by_id( $id );
        if ( ! $record ) {
            return new WP_Error( 'wfs_not_found', __( 'That FileShelf item no longer exists.', 'wp-fileshelf' ) );
        }

        $validated = self::validate_upload( $file );
        if ( is_wp_error( $validated ) ) {
            return $validated;
        }

        $existing_ext = strtolower( (string) pathinfo( (string) $record->filename, PATHINFO_EXTENSION ) );
        $incoming_ext = strtolower( (string) $validated['extension'] );
        if ( '' === $existing_ext || $existing_ext !== $incoming_ext ) {
            return new WP_Error(
                'wfs_extension_mismatch',
                sprintf(
                    __( 'Replacement files must use the same .%s extension as the existing file.', 'wp-fileshelf' ),
                    $existing_ext ?: '?'
                )
            );
        }

        if ( ! self::ensure_storage_directory() ) {
            return new WP_Error( 'wfs_storage_unwritable', __( 'The WP FileShelf storage directory is not writable.', 'wp-fileshelf' ) );
        }

        $target = trailingslashit( self::storage_dir() ) . (string) $record->filename;
        $temp   = trailingslashit( self::storage_dir() ) . '.wfs-' . wp_generate_password( 18, false, false ) . '.tmp';
        $backup = trailingslashit( self::storage_dir() ) . '.wfs-' . wp_generate_password( 18, false, false ) . '.bak';

        if ( ! @move_uploaded_file( (string) $validated['tmp_name'], $temp ) ) {
            return new WP_Error( 'wfs_move_failed', __( 'WP FileShelf could not stage the replacement file.', 'wp-fileshelf' ) );
        }

        $had_original = is_file( $target );
        if ( $had_original && ! @rename( $target, $backup ) ) {
            @unlink( $temp );
            return new WP_Error( 'wfs_backup_failed', __( 'WP FileShelf could not safely stage the existing file for replacement.', 'wp-fileshelf' ) );
        }

        if ( ! @rename( $temp, $target ) ) {
            @unlink( $temp );
            if ( $had_original && is_file( $backup ) ) {
                @rename( $backup, $target );
            }
            return new WP_Error( 'wfs_replace_failed', __( 'WP FileShelf could not replace the existing file.', 'wp-fileshelf' ) );
        }

        @chmod( $target, 0644 );

        $new_display_name = null !== $display_name ? sanitize_text_field( $display_name ) : (string) $record->display_name;
        $checksum         = is_file( $target ) ? (string) @hash_file( 'sha256', $target ) : '';
        $size             = is_file( $target ) ? (int) @filesize( $target ) : (int) $validated['size'];
        $now              = current_time( 'mysql' );

        $updated = $wpdb->update(
            WFS_DB::files_table(),
            array(
                'display_name'      => $new_display_name,
                'original_filename' => sanitize_file_name( wp_unslash( (string) $file['name'] ) ),
                'mime_type'         => (string) $validated['mime_type'],
                'file_size'         => max( 0, $size ),
                'checksum'          => $checksum,
                'modified_at'       => $now,
            ),
            array( 'id' => $id ),
            array( '%s', '%s', '%s', '%d', '%s', '%s' ),
            array( '%d' )
        );

        if ( false === $updated ) {
            @unlink( $target );
            if ( $had_original && is_file( $backup ) ) {
                @rename( $backup, $target );
            }
            return new WP_Error( 'wfs_db_update_failed', __( 'The replacement was rolled back because FileShelf could not update its database metadata.', 'wp-fileshelf' ) );
        }

        if ( is_file( $backup ) ) {
            @unlink( $backup );
        }

        return self::get_by_id( $id ) ?: $record;
    }

    public static function delete_record( int $id ): bool {
        global $wpdb;

        $record = self::get_by_id( $id );
        if ( ! $record ) {
            return false;
        }

        $path = trailingslashit( self::storage_dir() ) . (string) $record->filename;
        if ( is_file( $path ) && ! @unlink( $path ) ) {
            return false;
        }

        return false !== $wpdb->delete( WFS_DB::files_table(), array( 'id' => $id ), array( '%d' ) );
    }

    public static function serve_file( string $filename ): void {
        $filename = sanitize_file_name( rawurldecode( $filename ) );
        $record   = self::get_by_filename( $filename );

        if ( ! $record ) {
            status_header( 404 );
            nocache_headers();
            exit;
        }

        $path = trailingslashit( self::storage_dir() ) . (string) $record->filename;
        if ( ! is_file( $path ) || ! is_readable( $path ) ) {
            status_header( 404 );
            nocache_headers();
            exit;
        }

        while ( ob_get_level() ) {
            ob_end_clean();
        }

        $mime = (string) $record->mime_type;
        if ( '' === $mime ) {
            $checked = wp_check_filetype( (string) $record->filename );
            $mime    = ! empty( $checked['type'] ) ? (string) $checked['type'] : 'application/octet-stream';
        }

        status_header( 200 );
        header( 'Content-Type: ' . $mime );
        header( 'Content-Length: ' . (string) filesize( $path ) );
        header( 'Content-Disposition: inline; filename="' . str_replace( '"', '', (string) $record->filename ) . '"' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Cache-Control: no-cache, must-revalidate' );
        header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', (int) filemtime( $path ) ) . ' GMT' );
        if ( ! empty( $record->checksum ) ) {
            header( 'ETag: "' . sanitize_text_field( (string) $record->checksum ) . '"' );
        }

        $handle = fopen( $path, 'rb' );
        if ( false === $handle ) {
            status_header( 500 );
            exit;
        }

        fpassthru( $handle );
        fclose( $handle );
        exit;
    }

    private static function upload_error_message( int $error ): string {
        return match ( $error ) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => __( 'The uploaded file is too large.', 'wp-fileshelf' ),
            UPLOAD_ERR_PARTIAL => __( 'The file upload was interrupted. Please try again.', 'wp-fileshelf' ),
            UPLOAD_ERR_NO_FILE => __( 'Please choose a file to upload.', 'wp-fileshelf' ),
            UPLOAD_ERR_NO_TMP_DIR => __( 'The server is missing its temporary upload directory.', 'wp-fileshelf' ),
            UPLOAD_ERR_CANT_WRITE => __( 'The server could not write the uploaded file.', 'wp-fileshelf' ),
            UPLOAD_ERR_EXTENSION => __( 'A server extension stopped the upload.', 'wp-fileshelf' ),
            default => __( 'The upload failed.', 'wp-fileshelf' ),
        };
    }
}
