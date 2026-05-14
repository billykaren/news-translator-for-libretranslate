<?php
/**
 * NT_DB — database install and all query helpers.
 *
 * Table: {prefix}news_translations
 *   id, post_id, language, title, content, excerpt, subtitle,
 *   slug, seo_title, seo_desc, seo_keywords,
 *   og_title, og_desc, twitter_title, twitter_desc,
 *   focus_kw, translated_at
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NT_DB {

    const TABLE = 'news_translations';

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Escape a SQL identifier such as a table name.
     *
     * @param string $identifier Raw identifier.
     * @return string
     */
    private static function esc_identifier( $identifier ) {
        return '`' . str_replace( '`', '``', (string) $identifier ) . '`';
    }

    private static function cache_group() {
        return 'news_translator_pro';
    }

    private static function cache_key( $suffix ) {
        return 'nt_' . md5( (string) $suffix );
    }

    private static function flush_post_cache( $post_id ) {
        wp_cache_delete( self::cache_key( 'all_' . (int) $post_id ), self::cache_group() );
        foreach ( array_keys( NT_LANGS ) as $lang ) {
            wp_cache_delete( self::cache_key( 'row_' . (int) $post_id . '_' . $lang ), self::cache_group() );
        }
    }

    private static function flush_language_cache( $lang ) {
        wp_cache_delete( self::cache_key( 'lang_' . $lang ), self::cache_group() );
        wp_cache_delete( self::cache_key( 'missing_count_' . $lang . '_post,page' ), self::cache_group() );
        wp_cache_delete( self::cache_key( 'missing_count_' . $lang . '_post' ), self::cache_group() );
        wp_cache_delete( self::cache_key( 'missing_count_' . $lang . '_page' ), self::cache_group() );
    }

    // ── Schema ────────────────────────────────────────────────────────────────

    public static function install() {
        global $wpdb;
        $t  = self::table();
        $cc = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $t (
            id             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id        BIGINT(20) UNSIGNED NOT NULL,
            language       VARCHAR(10)         NOT NULL,
            title          LONGTEXT            NOT NULL,
            content        LONGTEXT            NOT NULL,
            excerpt        LONGTEXT                NULL,
            subtitle       LONGTEXT                NULL,
            slug           VARCHAR(255)            NULL,
            seo_title      VARCHAR(255)            NULL,
            seo_desc       TEXT                    NULL,
            seo_keywords   TEXT                    NULL,
            og_title       VARCHAR(255)            NULL,
            og_desc        TEXT                    NULL,
            twitter_title  VARCHAR(255)            NULL,
            twitter_desc   TEXT                    NULL,
            focus_kw       VARCHAR(255)            NULL,
            translated_at  DATETIME            NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY   post_language (post_id, language),
            KEY          lang_idx (language)
        ) $cc;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        self::ensure_columns();
        wp_cache_delete( self::cache_key( 'columns' ), self::cache_group() );
        update_option( 'nt_db_version', NT_VERSION );
    }


    /**
     * Ensure columns exist even when a ZIP is replaced over an already-active plugin.
     * WordPress does not always rerun activation hooks during upload replacement.
     */
    private static function ensure_columns() {
        global $wpdb;

        $table = self::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $existing = $wpdb->get_col( "SHOW COLUMNS FROM " . self::esc_identifier( $table ), 0 );
        if ( ! is_array( $existing ) ) {
            return;
        }

        $columns = array(
            'subtitle'      => 'LONGTEXT NULL',
            'seo_title'     => 'VARCHAR(255) NULL',
            'seo_desc'      => 'TEXT NULL',
            'seo_keywords'  => 'TEXT NULL',
            'og_title'      => 'VARCHAR(255) NULL',
            'og_desc'       => 'TEXT NULL',
            'twitter_title' => 'VARCHAR(255) NULL',
            'twitter_desc'  => 'TEXT NULL',
            'focus_kw'      => 'VARCHAR(255) NULL',
        );

        foreach ( $columns as $column => $definition ) {
            if ( in_array( $column, $existing, true ) ) {
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query( "ALTER TABLE " . self::esc_identifier( $table ) . " ADD COLUMN `" . esc_sql( $column ) . "` " . $definition );
        }
    }

    private static function table_columns() {
        global $wpdb;

        $cache_key = self::cache_key( 'columns' );
        $cached = wp_cache_get( $cache_key, self::cache_group() );
        if ( false !== $cached ) {
            return $cached;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $columns = $wpdb->get_col( "SHOW COLUMNS FROM " . self::esc_identifier( self::table() ), 0 );
        if ( ! is_array( $columns ) ) {
            $columns = array();
        }

        wp_cache_set( $cache_key, $columns, self::cache_group(), HOUR_IN_SECONDS );
        return $columns;
    }


    // ── Read ──────────────────────────────────────────────────────────────────

    /** Return a single translation row (ARRAY_A) or null. */
    public static function get( $post_id, $lang ) {
        global $wpdb;
        $post_id = (int) $post_id;
        $lang    = sanitize_key( $lang );
        $cache_key = self::cache_key( 'row_' . $post_id . '_' . $lang );
        $cached = wp_cache_get( $cache_key, self::cache_group() );
        if ( false !== $cached ) {
            return $cached;
        }

        $table = self::esc_identifier( self::table() );
        $sql   = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE post_id = %d AND language = %s",
            $post_id,
            $lang
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row( $sql, ARRAY_A );
        wp_cache_set( $cache_key, $row, self::cache_group(), HOUR_IN_SECONDS );
        return $row;
    }

    /** Return all translation rows for a post. */
    public static function get_all( $post_id ) {
        global $wpdb;
        $post_id = (int) $post_id;
        $cache_key = self::cache_key( 'all_' . $post_id );
        $cached = wp_cache_get( $cache_key, self::cache_group() );
        if ( false !== $cached ) {
            return $cached;
        }

        $table = self::esc_identifier( self::table() );
        $sql   = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE post_id = %d",
            $post_id
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        if ( ! is_array( $rows ) ) {
            $rows = array();
        }
        wp_cache_set( $cache_key, $rows, self::cache_group(), HOUR_IN_SECONDS );
        return $rows;
    }

    /** Return post_id for a given language + slug (for rewrite resolution). */
    public static function get_post_id_by_slug( $lang, $slug ) {
        global $wpdb;
        $table = self::esc_identifier( self::table() );
        $sql   = $wpdb->prepare(
            "SELECT post_id FROM {$table} WHERE language = %s AND slug = %s",
            sanitize_key( $lang ),
            sanitize_title( $slug )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_var( $sql );
    }

    /**
     * Retrieve all published translated posts for a language.
     * Returns rows with post_id, slug, translated_at — used by sitemaps.
     */
    public static function get_all_for_lang( $lang ) {
        global $wpdb;
        $lang = sanitize_key( $lang );
        $cache_key = self::cache_key( 'lang_' . $lang );
        $cached = wp_cache_get( $cache_key, self::cache_group() );
        if ( false !== $cached ) {
            return $cached;
        }

        $table       = self::esc_identifier( self::table() );
        $posts_table = self::esc_identifier( $wpdb->posts );
        $sql = $wpdb->prepare(
            "SELECT t.post_id, t.slug, t.title, t.translated_at
             FROM {$table} t
             INNER JOIN {$posts_table} p ON p.ID = t.post_id
             WHERE t.language = %s AND p.post_status = 'publish' AND p.post_type = 'post'
             ORDER BY p.post_date DESC",
            $lang
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        if ( ! is_array( $rows ) ) {
            $rows = array();
        }
        wp_cache_set( $cache_key, $rows, self::cache_group(), 10 * MINUTE_IN_SECONDS );
        return $rows;
    }

    /**
     * Return up to $limit published posts/pages that are missing a translation.
     */
    public static function get_missing_post_ids( $lang, $post_types = array( 'post', 'page' ), $limit = 10 ) {
        global $wpdb;

        $lang = sanitize_key( $lang );
        $post_types = array_values( array_filter( array_map( 'sanitize_key', (array) $post_types ) ) );
        if ( empty( $post_types ) ) {
            $post_types = array( 'post', 'page' );
        }

        $table       = self::esc_identifier( self::table() );
        $posts_table = self::esc_identifier( $wpdb->posts );
        $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
        $sql = "SELECT p.ID
                FROM {$posts_table} p
                LEFT JOIN {$table} t
                  ON t.post_id = p.ID AND t.language = %s
                WHERE p.post_status = 'publish'
                  AND p.post_type IN ({$placeholders})
                  AND t.id IS NULL
                ORDER BY p.post_date DESC
                LIMIT %d";

        $params = array_merge( array( $lang ), $post_types, array( absint( $limit ) ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) );
    }

    /**
     * Return translated rows whose subtitle column is empty but whose source post has an AFT subtitle.
     */
    public static function get_missing_subtitle_rows( $lang, $post_types = array( 'post', 'page' ), $limit = 10 ) {
        global $wpdb;

        $lang = sanitize_key( $lang );
        $post_types = array_values( array_filter( array_map( 'sanitize_key', (array) $post_types ) ) );
        if ( empty( $post_types ) ) {
            $post_types = array( 'post', 'page' );
        }

        $table       = self::esc_identifier( self::table() );
        $posts_table = self::esc_identifier( $wpdb->posts );
        $meta_table  = self::esc_identifier( $wpdb->postmeta );
        $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

        $sql = "SELECT DISTINCT t.post_id, t.language
                FROM {$table} t
                INNER JOIN {$posts_table} p ON p.ID = t.post_id
                WHERE t.language = %s
                  AND p.post_status = 'publish'
                  AND p.post_type IN ({$placeholders})
                  AND (t.subtitle IS NULL OR t.subtitle = '')
                  AND EXISTS (
                      SELECT 1
                      FROM {$meta_table} pm
                      WHERE pm.post_id = p.ID
                        AND pm.meta_key IN ('_aft_subtitle', 'aft_subtitle')
                        AND TRIM(pm.meta_value) <> ''
                  )
                ORDER BY p.post_date DESC
                LIMIT %d";

        $params = array_merge( array( $lang ), $post_types, array( absint( $limit ) ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Count translated rows whose subtitle column is empty but whose source post has an AFT subtitle.
     */
    public static function count_missing_subtitle_rows( $lang, $post_types = array( 'post', 'page' ) ) {
        global $wpdb;

        $lang = sanitize_key( $lang );
        $post_types = array_values( array_filter( array_map( 'sanitize_key', (array) $post_types ) ) );
        if ( empty( $post_types ) ) {
            $post_types = array( 'post', 'page' );
        }

        $table       = self::esc_identifier( self::table() );
        $posts_table = self::esc_identifier( $wpdb->posts );
        $meta_table  = self::esc_identifier( $wpdb->postmeta );
        $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

        $sql = "SELECT COUNT(DISTINCT t.id)
                FROM {$table} t
                INNER JOIN {$posts_table} p ON p.ID = t.post_id
                WHERE t.language = %s
                  AND p.post_status = 'publish'
                  AND p.post_type IN ({$placeholders})
                  AND (t.subtitle IS NULL OR t.subtitle = '')
                  AND EXISTS (
                      SELECT 1
                      FROM {$meta_table} pm
                      WHERE pm.post_id = p.ID
                        AND pm.meta_key IN ('_aft_subtitle', 'aft_subtitle')
                        AND TRIM(pm.meta_value) <> ''
                  )";

        $params = array_merge( array( $lang ), $post_types );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
    }




    /**
     * Return up to $limit published posts/pages for force retranslation.
     * Uses descending post IDs and an optional cursor so AJAX batches can resume
     * without repeatedly processing the same articles.
     */
    public static function get_retranslate_post_ids( $post_types = array( 'post', 'page' ), $limit = 10, $before_id = 0 ) {
        global $wpdb;

        $post_types = array_values( array_filter( array_map( 'sanitize_key', (array) $post_types ) ) );
        if ( empty( $post_types ) ) {
            $post_types = array( 'post', 'page' );
        }

        $posts_table  = self::esc_identifier( $wpdb->posts );
        $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
        $where_cursor = '';
        $params       = $post_types;

        if ( absint( $before_id ) > 0 ) {
            $where_cursor = ' AND p.ID < %d';
            $params[]     = absint( $before_id );
        }

        $params[] = absint( $limit );

        $sql = "SELECT p.ID
                FROM {$posts_table} p
                WHERE p.post_status = 'publish'
                  AND p.post_type IN ({$placeholders})
                  {$where_cursor}
                ORDER BY p.ID DESC
                LIMIT %d";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) );
    }

    /**
     * Count remaining published posts/pages for force retranslation cursor state.
     */
    public static function count_retranslate_posts( $post_types = array( 'post', 'page' ), $before_id = 0 ) {
        global $wpdb;

        $post_types = array_values( array_filter( array_map( 'sanitize_key', (array) $post_types ) ) );
        if ( empty( $post_types ) ) {
            $post_types = array( 'post', 'page' );
        }

        $posts_table  = self::esc_identifier( $wpdb->posts );
        $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
        $where_cursor = '';
        $params       = $post_types;

        if ( absint( $before_id ) > 0 ) {
            $where_cursor = ' AND p.ID < %d';
            $params[]     = absint( $before_id );
        }

        $sql = "SELECT COUNT(1)
                FROM {$posts_table} p
                WHERE p.post_status = 'publish'
                  AND p.post_type IN ({$placeholders})
                  {$where_cursor}";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
    }

    /**
     * Count published posts/pages that are missing a translation.
     */
    public static function count_missing_posts( $lang, $post_types = array( 'post', 'page' ) ) {
        global $wpdb;

        $lang = sanitize_key( $lang );
        $post_types = array_values( array_filter( array_map( 'sanitize_key', (array) $post_types ) ) );
        if ( empty( $post_types ) ) {
            $post_types = array( 'post', 'page' );
        }

        $cache_key = self::cache_key( 'missing_count_' . $lang . '_' . implode( ',', $post_types ) );
        $cached = wp_cache_get( $cache_key, self::cache_group() );
        if ( false !== $cached ) {
            return (int) $cached;
        }

        $table       = self::esc_identifier( self::table() );
        $posts_table = self::esc_identifier( $wpdb->posts );
        $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
        $sql = "SELECT COUNT(1)
                FROM {$posts_table} p
                LEFT JOIN {$table} t
                  ON t.post_id = p.ID AND t.language = %s
                WHERE p.post_status = 'publish'
                  AND p.post_type IN ({$placeholders})
                  AND t.id IS NULL";

        $params = array_merge( array( $lang ), $post_types );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
        wp_cache_set( $cache_key, $count, self::cache_group(), 5 * MINUTE_IN_SECONDS );
        return $count;
    }

    // ── Write ─────────────────────────────────────────────────────────────────

    /** Insert or update a translation row. */
    public static function save( $post_id, $lang, array $data ) {
        global $wpdb;

        $post_id = (int) $post_id;
        $lang    = sanitize_key( $lang );

        // Repair schema before saving. This fixes active-plugin ZIP replacements
        // where activation hooks did not rerun and newer columns were missing.
        self::install();

        $data['post_id']       = $post_id;
        $data['language']      = $lang;
        $data['translated_at'] = current_time( 'mysql' );

        $columns = self::table_columns();
        if ( $columns ) {
            $data = array_intersect_key( $data, array_flip( $columns ) );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->replace( self::table(), $data );

        if ( false === $result ) {
            return new WP_Error(
                'nt_db_save_failed',
                sprintf(
                    'Translation database save failed: %s',
                    $wpdb->last_error ? $wpdb->last_error : 'unknown database error'
                )
            );
        }

        self::flush_post_cache( $post_id );
        self::flush_language_cache( $lang );
        wp_cache_delete( self::cache_key( 'columns' ), self::cache_group() );
        clean_post_cache( $post_id );

        return true;
    }


    /** Update only the stored translated subtitle for an existing translation row. */
    public static function update_subtitle( $post_id, $lang, $subtitle ) {
        global $wpdb;

        $post_id = (int) $post_id;
        $lang    = sanitize_key( $lang );

        self::install();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->update(
            self::table(),
            array(
                'subtitle'      => (string) $subtitle,
                'translated_at' => current_time( 'mysql' ),
            ),
            array(
                'post_id'  => $post_id,
                'language' => $lang,
            ),
            array( '%s', '%s' ),
            array( '%d', '%s' )
        );

        if ( false === $result ) {
            return new WP_Error(
                'nt_db_subtitle_update_failed',
                sprintf(
                    'Subtitle translation database update failed: %s',
                    $wpdb->last_error ? $wpdb->last_error : 'unknown database error'
                )
            );
        }

        self::flush_post_cache( $post_id );
        self::flush_language_cache( $lang );
        clean_post_cache( $post_id );

        return true;
    }

    /** Delete one language's translation. */
    public static function delete( $post_id, $lang ) {
        global $wpdb;
        $post_id = (int) $post_id;
        $lang    = sanitize_key( $lang );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->delete( self::table(), array( 'post_id' => $post_id, 'language' => $lang ), array( '%d', '%s' ) );
        self::flush_post_cache( $post_id );
        self::flush_language_cache( $lang );
    }

    /** Delete all translations for a post. */
    public static function delete_all( $post_id ) {
        global $wpdb;
        $post_id = (int) $post_id;
        $langs = array_keys( NT_LANGS );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->delete( self::table(), array( 'post_id' => $post_id ), array( '%d' ) );
        self::flush_post_cache( $post_id );
        foreach ( $langs as $lang ) {
            self::flush_language_cache( $lang );
        }
    }
}

// Clean up when a post is permanently deleted.
add_action( 'before_delete_post', array( 'NT_DB', 'delete_all' ) );
