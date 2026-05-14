<?php
/**
 * NT_Rewrite — registers /es/ and /fr/ subdirectory rewrite rules.
 *
 * URL patterns:
 *   /es/                    → Spanish archive
 *   /es/page/2/             → paginated archive
 *   /es/{slug}/             → single translated post
 *   /fr/                    → French archive
 *   /fr/page/2/             → paginated archive
 *   /fr/{slug}/             → single translated post
 *   /nt-sitemap.xml         → standalone translated sitemap
 *
 * ── Why add_rewrite_tag() is used alongside add_rewrite_rule() ────────────────
 * WordPress strips query vars it doesn't recognise before handing them to
 * WP_Query. There are two ways to whitelist a var:
 *   (a) add it to the query_vars filter
 *   (b) call add_rewrite_tag()
 * We do BOTH: add_rewrite_tag() writes the var into WP_Rewrite::$extra_permastructs
 * so it is always present regardless of hook order, and add_query_vars() covers
 * the filter path. This prevents the "rules match but vars get stripped" bug that
 * causes redirects to the homepage.
 *
 * ── Why add_rules() contains no add_action( 'init', … ) wrapper ─────────────
 * NT_Rewrite::init() is called from plugins_loaded. When NT_Rewrite::init()
 * calls add_action( 'init', 'add_rules' ) the 'init' hook fires correctly on
 * every normal page request. However during plugin activation register_activation_hook()
 * fires AFTER 'init' has already run, so an inner 'init' action never fires and
 * flush_rewrite_rules() sees no new rules. By making add_rules() callable directly
 * (no inner hook) both the normal boot path and the activation path work correctly.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NT_Rewrite {

    public static function init() {
        // Hook add_rules onto 'init' for normal page requests.
        add_action( 'init',              array( __CLASS__, 'add_rules' ) );
        add_filter( 'query_vars',        array( __CLASS__, 'add_query_vars' ) );
        add_filter( 'request',           array( __CLASS__, 'inject_request_vars' ), 0 );
        add_action( 'parse_request',     array( __CLASS__, 'parse_request_fallback' ), 0 );
        add_filter( 'redirect_canonical', array( __CLASS__, 'filter_canonical_redirect' ), 0, 2 );
        add_action( 'template_redirect', array( __CLASS__, 'maybe_disable_canonical_redirect' ), 0 );
        add_action( 'template_redirect', array( __CLASS__, 'handle_request' ), 0 );
        add_filter( 'post_link',          array( __CLASS__, 'filter_post_link' ), 10, 3 );
        add_filter( 'post_type_link',     array( __CLASS__, 'filter_post_type_link' ), 10, 4 );
    }

    // ── Rules ─────────────────────────────────────────────────────────────────

    /**
     * Register all rewrite rules and rewrite tags.
     *
     * Safe to call directly (e.g. from activation hook) as well as via 'init'.
     */
    public static function add_rules() {
        // ── Register rewrite tags so WordPress never strips our query vars ────
        // add_rewrite_tag( '%nt_lang%', '(es|fr)' ) whitelists the var at the
        // WP_Rewrite level, independently of the query_vars filter.
        add_rewrite_tag( '%nt_lang%', '(' . implode( '|', array_keys( NT_LANGS ) ) . ')' );
        add_rewrite_tag( '%nt_slug%', '([^/]+)' );
        add_rewrite_tag( '%nt_sitemap%', '(1)' );

        foreach ( array_keys( NT_LANGS ) as $lang ) {
            // Archive: /es/  or  /fr/
            add_rewrite_rule(
                '^(' . $lang . ')/?$',
                'index.php?nt_lang=$matches[1]',
                'top'
            );
            // Paginated archive: /es/page/2/
            add_rewrite_rule(
                '^(' . $lang . ')/page/([0-9]+)/?$',
                'index.php?nt_lang=$matches[1]&paged=$matches[2]',
                'top'
            );
            // Single post: /es/my-slug/
            add_rewrite_rule(
                '^(' . $lang . ')/([^/]+)/?$',
                'index.php?nt_lang=$matches[1]&nt_slug=$matches[2]',
                'top'
            );
        }

        // Standalone sitemap: /nt-sitemap.xml
        // Registered here (not in NT_Sitemap::init()) so it shares this single
        // 'init' callback and is always present when flush_rewrite_rules() runs.
        add_rewrite_rule(
            '^nt-sitemap\.xml$',
            'index.php?nt_sitemap=1',
            'top'
        );
    }

    /**
     * Whitelist our custom query vars via the query_vars filter.
     * add_rewrite_tag() above covers the WP_Rewrite path; this covers WP_Query.
     */
    public static function add_query_vars( $vars ) {
        $vars[] = 'nt_lang';
        $vars[] = 'nt_slug';
        $vars[] = 'nt_sitemap';
        return $vars;
    }

    /**
     * Flush rewrite rules.
     * Call directly — do NOT wrap in add_action( 'init', … ) from here,
     * as activation hooks fire after 'init' has already run.
     */
    public static function flush() {
        self::add_rules();
        flush_rewrite_rules();
    }




    /**
     * Parse the raw request path early and force our custom vars into the
     * main request before other plugins can redirect it away.
     */
    private static function parse_request_path() {
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        $path = trim( (string) wp_parse_url( $request_uri, PHP_URL_PATH ), '/' );

        if ( $path === 'nt-sitemap.xml' ) {
            return array( 'nt_sitemap' => 1 );
        }

        if ( $path === '' ) {
            return array();
        }

        $parts = array_values( array_filter( explode( '/', $path ) ) );
        if ( empty( $parts[0] ) || ! array_key_exists( $parts[0], NT_LANGS ) ) {
            return array();
        }

        $vars = array( 'nt_lang' => $parts[0] );

        if ( isset( $parts[1] ) && $parts[1] === 'page' && ! empty( $parts[2] ) ) {
            $vars['paged'] = (int) $parts[2];
        } elseif ( ! empty( $parts[1] ) ) {
            $vars['nt_slug'] = sanitize_title( $parts[1] );
        }

        return $vars;
    }

    /**
     * Inject vars during request parsing so translated routes resolve before
     * canonical logic, SEO plugins, or home-page fallbacks get involved.
     */
    public static function inject_request_vars( $query_vars ) {
        $forced = self::parse_request_path();
        if ( empty( $forced ) ) {
            return $query_vars;
        }

        foreach ( $forced as $key => $value ) {
            $query_vars[ $key ] = $value;
        }

        return $query_vars;
    }

    /**
     * Reinforce the same vars on the WP request object itself.
     */
    public static function parse_request_fallback( $wp ) {
        $forced = self::parse_request_path();
        if ( empty( $forced ) ) {
            return;
        }

        foreach ( $forced as $key => $value ) {
            $wp->query_vars[ $key ] = $value;
            $wp->matched_query      = isset( $wp->matched_query ) ? $wp->matched_query : '';
        }
    }

    /**
     * Hard-stop canonical redirects for translated routes and the standalone
     * sitemap. Returning false here is stronger than removing the action later.
     */
    public static function filter_canonical_redirect( $redirect_url, $requested_url ) {
        if ( get_query_var( 'nt_sitemap' ) || self::is_translated_request() || ! empty( self::parse_request_path() ) ) {
            return false;
        }

        return $redirect_url;
    }

    /**
     * Stop WordPress from redirecting custom translated routes back to the
     * English homepage before our renderer gets a chance to run.
     */
    public static function maybe_disable_canonical_redirect() {
        if ( get_query_var( 'nt_sitemap' ) || self::is_translated_request() ) {
            remove_action( 'template_redirect', 'redirect_canonical' );
        }
    }

    /**
     * Detect translated requests both after rewrite parsing and as a fallback
     * from the raw request URI when caches or competing plugins interfere.
     */
    private static function is_translated_request() {
        $lang = get_query_var( 'nt_lang' );
        if ( $lang && array_key_exists( $lang, NT_LANGS ) ) {
            return true;
        }

        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        $path = trim( (string) wp_parse_url( $request_uri, PHP_URL_PATH ), '/' );
        if ( $path === '' ) {
            return false;
        }

        $parts = explode( '/', $path );
        return isset( $parts[0] ) && array_key_exists( $parts[0], NT_LANGS );
    }

    // ── Request handler ───────────────────────────────────────────────────────

    public static function handle_request() {
        $lang = get_query_var( 'nt_lang' );

        // Handle sitemap first (no lang var needed).
        if ( get_query_var( 'nt_sitemap' ) ) {
            NT_Sitemap::serve_standalone();
            // serve_standalone() calls exit; control never returns here.
        }

        if ( ( ! $lang || ! array_key_exists( $lang, NT_LANGS ) ) && self::is_translated_request() ) {
            $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
            $path = trim( (string) wp_parse_url( $request_uri, PHP_URL_PATH ), '/' );
            $parts = array_values( array_filter( explode( '/', $path ) ) );
            if ( ! empty( $parts[0] ) && array_key_exists( $parts[0], NT_LANGS ) ) {
                $lang = $parts[0];
                set_query_var( 'nt_lang', $lang );
                if ( ! empty( $parts[1] ) ) {
                    set_query_var( 'nt_slug', sanitize_title( $parts[1] ) );
                }
            }
        }

        if ( ! $lang || ! array_key_exists( $lang, NT_LANGS ) ) return;

        $slug = get_query_var( 'nt_slug' );

        if ( $slug ) {
            self::render_single( $lang, $slug );
        } else {
            self::render_language_root( $lang );
        }
    }

    // ── Single post ───────────────────────────────────────────────────────────

    private static function render_single( $lang, $slug ) {
        $post_id = NT_DB::get_post_id_by_slug( $lang, $slug );

        if ( ! $post_id ) {
            $post = get_page_by_path( $slug, OBJECT, 'post' );
            if ( $post ) $post_id = $post->ID;
        }

        if ( ! $post_id ) {
            global $wp_query;
            $wp_query->set_404();
            status_header( 404 );
            nocache_headers();
            include get_404_template();
            exit;
        }

        $translation = NT_DB::get( $post_id, $lang );

        if ( ! $translation ) {
            $translator  = new NT_Post_Translator();
            $result      = $translator->get_or_create( $post_id, $lang );
            $translation = is_wp_error( $result ) ? null : $result;
        }

        if ( ! $translation ) {
            wp_safe_redirect( get_permalink( $post_id ), 302 );
            exit;
        }

        $GLOBALS['nt_current_lang']        = $lang;
        $GLOBALS['nt_current_post_id']     = $post_id;
        $GLOBALS['nt_current_translation'] = $translation;
        $GLOBALS['nt_original_permalink']  = get_permalink( $post_id );

        self::setup_post_globals( $post_id, $translation );

        // v2.3.5: NT_Hreflang::init() already hooks wp_head once. Do not add duplicate head tags here.

        status_header( 200 );
        nocache_headers();
        // v2.3.5: Do not start a full-page output buffer.
        include get_single_template() ?: get_index_template();
        exit;
    }

    private static function setup_post_globals( $post_id, $translation ) {
        $original_post = get_post( $post_id );
        if ( ! $original_post ) return;

        // v2.3.6: Work on a clone so translated slugs/titles do not pollute WP's object cache.
        $post = clone $original_post;

        $post->post_title   = $translation['title'];
        $post->post_content = $translation['content'];
        $post->post_excerpt = $translation['excerpt'] ?: $translation['title'];
        $post->post_name    = $translation['slug'];

        $GLOBALS['post'] = $post;

        global $wp_query, $wp_the_query;
        $wp_query->posts              = array( $post );
        $wp_query->post               = $post;
        $wp_query->found_posts        = 1;
        $wp_query->post_count         = 1;
        $wp_query->current_post       = -1;
        $wp_query->in_the_loop        = false;
        $wp_query->max_num_pages      = 1;
        $wp_query->is_single          = true;
        $wp_query->is_singular        = true;
        $wp_query->is_home            = false;
        $wp_query->is_front_page      = false;
        $wp_query->is_archive         = false;
        $wp_query->is_post_type_archive = false;
        $wp_query->is_page            = false;
        $wp_query->is_search          = false;
        $wp_query->is_feed            = false;
        $wp_query->is_404             = false;
        $wp_query->queried_object     = $post;
        $wp_query->queried_object_id  = $post_id;
        $wp_query->query              = array( 'p' => $post_id, 'post_type' => 'post' );
        $wp_query->query_vars['p']    = $post_id;
        $wp_query->query_vars['page_id'] = 0;
        $wp_query->query_vars['name'] = $post->post_name;
        $wp_query->query_vars['post_type'] = 'post';

        // Keep both WP query globals in sync so SEO/ad plugins see a real single post.
        $wp_the_query = $wp_query;
        $GLOBALS['wp_the_query'] = $wp_query;

        setup_postdata( $post );
        // Prime and rewind the loop. Site Kit and other plugins sometimes look for
        // a fully initialized main Loop, not just is_single() flags.
        if ( method_exists( $wp_query, 'the_post' ) && method_exists( $wp_query, 'rewind_posts' ) ) {
            $wp_query->the_post();
            $wp_query->rewind_posts();
        }
        $GLOBALS['post'] = $post;
    }


    private static function add_translated_loop_filters() {
        static $added = false;
        if ( $added ) {
            return;
        }
        $added = true;

        add_filter( 'the_title', array( __CLASS__, 'filter_archive_title' ), 10, 2 );
        add_filter( 'the_content', array( __CLASS__, 'filter_archive_content' ), 10, 1 );
        add_filter( 'get_the_excerpt', array( __CLASS__, 'filter_archive_excerpt' ), 10, 2 );
        add_filter( 'the_excerpt', array( __CLASS__, 'filter_the_excerpt_output' ), 10, 1 );
    }

    private static function render_language_root( $lang ) {
        $show_on_front = get_option( 'show_on_front' );
        if ( $show_on_front === 'page' && (int) get_option( 'page_on_front' ) ) {
            self::render_front_page( $lang, (int) get_option( 'page_on_front' ) );
        }

        self::render_archive( $lang );
    }

    private static function render_front_page( $lang, $front_id ) {
        $post = get_post( $front_id );
        if ( ! $post ) {
            self::render_archive( $lang );
        }

        $GLOBALS['nt_current_lang']    = $lang;
        $GLOBALS['nt_current_post_id'] = $front_id;
        $GLOBALS['post']               = $post;

        setup_postdata( $post );

        global $wp_query;
        $wp_query->posts               = array( $post );
        $wp_query->post                = $post;
        $wp_query->found_posts         = 1;
        $wp_query->post_count          = 1;
        $wp_query->max_num_pages       = 1;
        $wp_query->is_page             = true;
        $wp_query->is_singular         = true;
        $wp_query->is_home             = false;
        $wp_query->is_front_page       = true;
        $wp_query->is_archive          = false;
        $wp_query->is_404              = false;
        $wp_query->queried_object      = $post;
        $wp_query->queried_object_id   = $front_id;
        $GLOBALS['wp_the_query']       = $wp_query;

        self::add_translated_loop_filters();
        // v2.3.5: NT_Hreflang::init() already hooks wp_head once. Do not add duplicate head tags here.

        status_header( 200 );
        nocache_headers();
        // v2.3.5: Do not start a full-page output buffer.
        include get_front_page_template() ?: get_page_template() ?: get_index_template();
        exit;
    }

    // ── Archive ───────────────────────────────────────────────────────────────

    private static function render_archive( $lang ) {
        $GLOBALS['nt_current_lang'] = $lang;
        $paged = max( 1, (int) get_query_var( 'paged' ) );
        $is_front = ( empty( get_query_var( 'nt_slug' ) ) && $paged === 1 );

        global $wpdb;
        $table       = '`' . str_replace( '`', '``', NT_DB::table() ) . '`';
        $posts_table = '`' . str_replace( '`', '``', $wpdb->posts ) . '`';
        $sql = $wpdb->prepare(
            "SELECT t.post_id FROM {$table} t
             INNER JOIN {$posts_table} p ON p.ID = t.post_id
             WHERE t.language = %s AND p.post_status = 'publish' AND p.post_type = 'post'
             ORDER BY p.post_date DESC",
            $lang
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $post_ids = $wpdb->get_col( $sql );

        $per_page = (int) get_option( 'posts_per_page', 10 );
        $offset   = ( $paged - 1 ) * $per_page;
        $page_ids = array_slice( $post_ids, $offset, $per_page );

        global $wp_query;
        $wp_query = new WP_Query( array(
            'post__in'            => ! empty( $page_ids ) ? $page_ids : array( 0 ),
            'orderby'             => 'post__in',
            'posts_per_page'      => $per_page,
            'ignore_sticky_posts' => true,
        ) );

        $wp_query->max_num_pages = max( 1, (int) ceil( count( $post_ids ) / max( 1, $per_page ) ) );
        $wp_query->found_posts   = count( $post_ids );
        $wp_query->is_404        = false;
        $wp_query->is_singular   = false;
        $wp_query->is_single     = false;
        $wp_query->is_page       = false;
        $wp_query->is_archive    = ! $is_front;
        $wp_query->is_post_type_archive = ! $is_front;
        $wp_query->is_home       = $is_front;
        $wp_query->is_front_page = $is_front;
        $GLOBALS['wp_the_query'] = $wp_query;

        self::add_translated_loop_filters();
        // v2.3.5: NT_Hreflang::init() already hooks wp_head once. Do not add duplicate head tags here.

        status_header( 200 );
        nocache_headers();

        $template = '';
        if ( $is_front ) {
            $template = get_front_page_template();
            if ( ! $template ) {
                $template = get_home_template();
            }
            if ( ! $template ) {
                $template = get_archive_template();
            }
        } else {
            $template = get_home_template();
            if ( ! $template ) {
                $template = get_archive_template();
            }
        }

        if ( ! $template ) {
            $template = get_index_template();
        }

        // v2.3.5: Do not start a full-page output buffer.
        include $template;
        exit;
    }

    public static function filter_archive_title( $title, $post_id ) {
        $lang = $GLOBALS['nt_current_lang'] ?? null;
        if ( ! $lang ) return $title;
        $row = NT_DB::get( $post_id, $lang );
        return $row ? $row['title'] : $title;
    }

    public static function filter_archive_content( $content ) {
        $lang    = $GLOBALS['nt_current_lang'] ?? null;
        $post_id = get_the_ID();
        if ( ! $lang || ! $post_id ) return $content;
        $row = NT_DB::get( $post_id, $lang );
        if ( ! $row ) return $content;

        $rendered = self::render_translated_content( $row['content'] );

        // v2.3.9: class-rewrite replaces the_content on translated pages after
        // the frontend switcher filter may have already run. Re-add the switcher
        // here so /es/, /fr/, and /pt/ posts keep their language buttons.
        if ( false === strpos( $rendered, 'nt-switcher' ) && class_exists( 'NT_Frontend' ) && method_exists( 'NT_Frontend', 'prepend_switcher' ) ) {
            $rendered = NT_Frontend::prepend_switcher( $rendered );
        }

        return $rendered;
    }

    public static function filter_archive_excerpt( $excerpt, $post ) {
        $lang = $GLOBALS['nt_current_lang'] ?? null;
        if ( ! $lang ) return $excerpt;
        $row = NT_DB::get( $post->ID, $lang );
        return ( $row && $row['excerpt'] ) ? $row['excerpt'] : $excerpt;
    }

    /**
     * Render stored translated content through WordPress block and shortcode parsers
     * without recursively calling the_content. This prevents raw Gutenberg comments
     * from appearing above images and allows shortcode/ad blocks to render normally.
     */
    private static function render_translated_content( $content ) {
        if ( ! is_string( $content ) || $content === '' ) {
            return $content;
        }

        if ( function_exists( 'do_blocks' ) ) {
            $content = do_blocks( $content );
        }

        $content = shortcode_unautop( $content );
        $content = do_shortcode( $content );

        if ( false === strpos( $content, '<p' ) && false === strpos( $content, '<figure' ) ) {
            $content = wpautop( $content );
        }

        return $content;
    }


    public static function filter_the_excerpt_output( $excerpt ) {
        $post = get_post();
        if ( ! $post ) {
            return $excerpt;
        }

        return self::filter_archive_excerpt( $excerpt, $post );
    }


    public static function filter_post_link( $permalink, $post, $leavename ) {
        $lang = $GLOBALS['nt_current_lang'] ?? null;
        if ( ! $lang || empty( $post->ID ) || get_post_type( $post ) !== 'post' ) return $permalink;

        $row = NT_DB::get( $post->ID, $lang );
        return $row ? nt_get_translated_url( $post->ID, $lang ) : $permalink;
    }

    public static function filter_post_type_link( $post_link, $post, $leavename, $sample ) {
        return self::filter_post_link( $post_link, $post, $leavename );
    }

}

// ── Global URL helper ─────────────────────────────────────────────────────────

function nt_get_translated_url( $post_id, $lang ) {
    $lang = sanitize_key( $lang );
    $row  = NT_DB::get( $post_id, $lang );

    if ( is_array( $row ) && ! empty( $row['slug'] ) ) {
        $slug = $row['slug'];
    } else {
        $post = get_post( $post_id );
        $slug = $post ? $post->post_name : sanitize_title( get_the_title( $post_id ) );
    }

    return trailingslashit( home_url( '/' . $lang . '/' . sanitize_title( $slug ) ) );
}

