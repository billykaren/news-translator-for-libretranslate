<?php
/**
 * NT_Frontend — language switcher plus lightweight UI translation helpers.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NT_Frontend {

    private static $switcher_rendered = false;

    public static function init() {
        // v2.4.6: Do not prepend to the_content. ReviewNews also has an after-title hook;
        // using both created duplicate button bars.
        // v2.4: Render the language switcher through multiple front-end paths so
        // theme/template changes or translated-content replacement cannot hide it.
        add_action( 'reviewnews_after_post_title', array( __CLASS__, 'echo_switcher_after_title' ), 20 );
        add_action( 'wp_footer',              array( __CLASS__, 'echo_switcher_footer_fallback' ), 1 );
        add_action( 'wp_footer',              array( __CLASS__, 'echo_homepage_floating_switcher' ), 2 );
        add_action( 'wp_enqueue_scripts',     array( __CLASS__, 'enqueue' ) );
        // v2.3.3: Full-page output buffering disabled. It broke theme sidebars, widgets, and ad injections.
        // add_action( 'template_redirect',      array( __CLASS__, 'maybe_start_global_buffer' ), 1 );

        // Translated UI bits for /fr/ and /es/ views.
        add_filter( 'option_blogname',        array( __CLASS__, 'filter_blogname' ) );
        add_filter( 'option_blogdescription', array( __CLASS__, 'filter_blogdescription' ) );
        add_filter( 'bloginfo',               array( __CLASS__, 'filter_bloginfo' ), 10, 2 );
        add_filter( 'pre_get_document_title', array( __CLASS__, 'filter_document_title' ) );
        add_filter( 'document_title_parts',   array( __CLASS__, 'filter_document_title_parts' ) );
        add_filter( 'wp_title',               array( __CLASS__, 'filter_document_title' ), 10, 3 );
        add_filter( 'get_the_author_description', array( __CLASS__, 'filter_author_description' ) );
        add_filter( 'wp_nav_menu_objects',    array( __CLASS__, 'filter_nav_menu_objects' ), 10, 2 );
        add_filter( 'get_the_subtitle',       array( __CLASS__, 'filter_subtitle' ), 10, 2 );
        add_filter( 'the_subtitle',           array( __CLASS__, 'filter_subtitle' ), 10, 2 );
        add_filter( 'get_post_metadata',      array( __CLASS__, 'filter_subtitle_meta' ), 10, 4 );
        add_filter( 'get_the_excerpt',        array( __CLASS__, 'filter_excerpt' ), 10, 2 );
        add_filter( 'the_excerpt',            array( __CLASS__, 'filter_excerpt_output' ), 10, 1 );
        add_filter( 'widget_title',           array( __CLASS__, 'filter_widget_title' ), 10, 3 );
        add_filter( 'widget_text',            array( __CLASS__, 'filter_widget_text' ), 10, 3 );
        add_filter( 'widget_text_content',    array( __CLASS__, 'filter_widget_text' ), 10, 3 );
        add_filter( 'the_title',              array( __CLASS__, 'filter_post_title' ), 10, 2 );
        add_filter( 'single_post_title',      array( __CLASS__, 'translate_ui_string' ), 10, 1 );
        add_filter( 'list_cats',              array( __CLASS__, 'filter_term_name' ), 10, 2 );
        add_filter( 'single_cat_title',       array( __CLASS__, 'translate_ui_string' ), 10, 2 );
    }

    public static function enqueue() {
        if ( ! is_single() && ! isset( $GLOBALS['nt_current_lang'] ) && ! self::is_front_page_language_picker_context() ) return;

        wp_enqueue_style(
            'news-translator-pro',
            NT_URL . 'assets/frontend.css',
            array(),
            NT_VERSION
        );
    }

    public static function maybe_start_global_buffer() {
        // v2.3.3: disabled intentionally. Do not start a full-page HTML buffer.
        return;
    }

    public static function prepend_switcher( $content ) {
        $html = self::build_current_post_switcher_html( true );
        if ( ! $html || false !== strpos( $content, 'nt-switcher' ) ) {
            return $content;
        }

        self::$switcher_rendered = true;
        return $html . $content;
    }

    /**
     * v2.4: ReviewNews and translated routes can bypass or replace the_content.
     * Output the switcher immediately after the post title when the theme hook exists.
     */
    public static function echo_switcher_after_title() {
        $html = self::build_current_post_switcher_html( true );
        if ( ! $html ) {
            return;
        }

        self::$switcher_rendered = true;
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * v2.4: Last-resort no-surgery fallback. If earlier hooks did not place the
     * switcher, inject it near the title/content from wp_footer. This keeps the
     * buttons visible while preserving Site Kit/AdSense query-context fixes.
     */
    public static function echo_switcher_footer_fallback() {
        // v2.4.1: Do NOT trust self::$switcher_rendered here. Other theme or
        // translation filters can replace the_content after our early filter ran,
        // leaving PHP convinced the switcher exists while the actual DOM does not.
        // Instead, always print a tiny DOM-level safety net on singular post views
        // and let JavaScript insert the switcher only when .nt-switcher is absent.
        if ( is_admin() ) {
            return;
        }

        $html = self::build_current_post_switcher_html( true );
        if ( ! $html ) {
            return;
        }

        $json = wp_json_encode( $html );
        if ( ! $json ) {
            return;
        }

        echo "\n<script id=\"nt-switcher-fallback-js\">\n";
        echo "(function(){var h=" . $json . ";function ntInsertSwitcher(){if(document.querySelector('.nt-switcher'))return;var t=document.querySelector('.entry-title');var c=document.querySelector('.entry-content');var a=document.querySelector('article');var w=document.createElement('div');w.className='nt-switcher-fallback-wrap';w.innerHTML=h;if(t&&t.parentNode){t.parentNode.insertBefore(w,t.nextSibling);return;}if(c&&c.parentNode){c.parentNode.insertBefore(w,c);return;}if(a){a.insertBefore(w,a.firstChild);}}if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',ntInsertSwitcher);}else{ntInsertSwitcher();}setTimeout(ntInsertSwitcher,500);})();\n";
        echo "</script>\n";
    }

    /**
     * v2.6.1: Render a fixed homepage language picker. The old homepage picker
     * depended on full-page buffering, which is intentionally disabled.
     */
    public static function echo_homepage_floating_switcher() {
        if ( is_admin() || ! self::is_front_page_language_picker_context() ) {
            return;
        }

        $lang = $GLOBALS['nt_current_lang'] ?? self::current_language_from_request_path();
        echo self::build_front_page_switcher_html( $lang, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    private static function is_front_page_language_picker_context() {
        if ( is_front_page() || is_home() ) {
            return true;
        }

        $path = self::current_request_path();
        return ( '' !== $path && isset( NT_LANGS[ $path ] ) );
    }

    private static function current_language_from_request_path() {
        $path = self::current_request_path();
        return ( $path && isset( NT_LANGS[ $path ] ) ) ? $path : '';
    }

    private static function current_request_path() {
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        return trim( (string) wp_parse_url( $request_uri, PHP_URL_PATH ), '/' );
    }

    private static function build_current_post_switcher_html( $include_notice = false ) {
        $post_id = self::resolve_current_post_id_for_switcher();
        if ( ! $post_id ) {
            return '';
        }

        $post = get_post( $post_id );
        if ( ! $post || 'post' !== $post->post_type ) {
            return '';
        }

        $current_lang = isset( $GLOBALS['nt_current_lang'] ) ? (string) $GLOBALS['nt_current_lang'] : 'en';

        $items  = array();
        $items[] = self::switcher_item( 'en', '🇺🇸', 'English', self::get_english_permalink( $post_id ), $current_lang );

        foreach ( NT_LANGS as $code => $name ) {
            /*
             * v2.4.6:
             * Always show article-specific language buttons.
             * If a translation row exists, use its translated slug.
             * If not, use /{lang}/{original-post-slug}/ so the rewrite handler can create it on demand.
             */
            $url  = ( $code === $current_lang ) ? self::current_request_url() : self::get_translation_permalink_for_switcher( $post_id, $code );
            $flag = NT_FLAGS[ $code ] ?? '';
            $items[] = self::switcher_item( $code, $flag, $name, $url, $current_lang );
        }

        $html  = '<nav class="nt-switcher" aria-label="' . esc_attr__( 'Language switcher', 'news-translator-pro' ) . '">';
        $html .= '<span class="nt-switcher__label">' . esc_html__( 'Read in:', 'news-translator-pro' ) . '</span> ';
        $html .= implode( ' &nbsp; ', $items );
        $html .= '</nav>';

        if ( $include_notice && 'en' !== $current_lang ) {
            $html .= self::get_translation_notice_html( $current_lang );
        }

        return $html;
    }

    private static function get_translation_permalink_for_switcher( $post_id, $lang ) {
        $row = NT_DB::get( $post_id, $lang );
        if ( is_array( $row ) && ! empty( $row['slug'] ) ) {
            return nt_get_translated_url( $post_id, $lang );
        }

        $post = get_post( $post_id );
        $slug = $post ? $post->post_name : sanitize_title( get_the_title( $post_id ) );

        return trailingslashit( home_url( '/' . sanitize_key( $lang ) . '/' . sanitize_title( $slug ) ) );
    }


    public static function filter_blogname( $value ) {
        return self::translate_ui_string( $value );
    }

    public static function filter_blogdescription( $value ) {
        return self::translate_ui_string( $value );
    }

    public static function filter_bloginfo( $output, $show ) {
        if ( in_array( $show, array( 'name', 'description' ), true ) ) {
            return self::translate_ui_string( $output );
        }

        return $output;
    }

    public static function filter_document_title( $title ) {
        return self::translate_ui_string( $title );
    }

    public static function filter_document_title_parts( $parts ) {
        if ( ! is_array( $parts ) ) {
            return $parts;
        }

        foreach ( $parts as $key => $value ) {
            if ( is_string( $value ) ) {
                $parts[ $key ] = self::translate_ui_string( $value );
            }
        }

        return $parts;
    }

    public static function filter_author_description( $value ) {
        return self::translate_ui_string( $value );
    }

    public static function filter_subtitle( $value ) {
        $lang = $GLOBALS['nt_current_lang'] ?? '';
        $post_id = (int) ( $GLOBALS['nt_current_post_id'] ?? get_the_ID() );

        if ( $lang && $post_id ) {
            $row = NT_DB::get( $post_id, $lang );
            if ( $row && ! empty( $row['subtitle'] ) ) {
                return $row['subtitle'];
            }
        }

        return self::translate_ui_string( $value );
    }

    /**
     * v2.3.8: ReviewNews reads _aft_subtitle directly with get_post_meta(), so
     * normal subtitle filters do not always run. Intercept that meta lookup only
     * on translated pages and return the translated subtitle from our table.
     */
    public static function filter_subtitle_meta( $value, $object_id, $meta_key, $single ) {
        $lang = $GLOBALS['nt_current_lang'] ?? '';
        if ( ! $lang || is_admin() ) {
            return $value;
        }

        if ( ! in_array( $meta_key, array( '_aft_subtitle', 'aft_subtitle' ), true ) ) {
            return $value;
        }

        $post_id = (int) ( $GLOBALS['nt_current_post_id'] ?? $object_id );
        if ( ! $post_id ) {
            return $value;
        }

        $row = NT_DB::get( $post_id, $lang );
        if ( $row && ! empty( $row['subtitle'] ) ) {
            return $single ? $row['subtitle'] : array( $row['subtitle'] );
        }

        // Fallback: use original subtitle only. Do not trigger live translation on frontend.
        remove_filter( 'get_post_metadata', array( __CLASS__, 'filter_subtitle_meta' ), 10 );
        $original = get_post_meta( $post_id, '_aft_subtitle', true );
        add_filter( 'get_post_metadata', array( __CLASS__, 'filter_subtitle_meta' ), 10, 4 );

        if ( is_string( $original ) && trim( $original ) !== '' ) {
            return $single ? $original : array( $original );
        }

        return $value;
    }


    public static function filter_excerpt( $value, $post = null ) {
        $lang = $GLOBALS['nt_current_lang'] ?? '';
        if ( ! $lang ) return $value;

        $post_id = 0;
        if ( is_object( $post ) && ! empty( $post->ID ) ) {
            $post_id = (int) $post->ID;
        } elseif ( is_numeric( $post ) ) {
            $post_id = (int) $post;
        } elseif ( get_the_ID() ) {
            $post_id = (int) get_the_ID();
        }

        if ( $post_id ) {
            $row = NT_DB::get( $post_id, $lang );
            if ( $row && ! empty( $row['excerpt'] ) ) {
                return $row['excerpt'];
            }
        }

        return self::translate_ui_string( $value, $lang );
    }

    public static function filter_excerpt_output( $value ) {
        return self::filter_excerpt( $value, get_post() );
    }

    public static function filter_widget_title( $title ) {
        return self::translate_ui_string( $title );
    }

    public static function filter_widget_text( $text ) {
        return self::translate_ui_string( $text );
    }

    public static function filter_post_title( $title, $post_id = 0 ) {
        $lang = $GLOBALS['nt_current_lang'] ?? '';
        if ( ! $lang || ! $post_id || is_admin() ) {
            return $title;
        }

        $row = NT_DB::get( (int) $post_id, $lang );
        if ( $row && ! empty( $row['title'] ) ) {
            return $row['title'];
        }

        return $title;
    }

    public static function filter_term_name( $name ) {
        return self::translate_ui_string( $name );
    }

    public static function filter_nav_menu_objects( $items, $args ) {
        $lang = $GLOBALS['nt_current_lang'] ?? '';
        if ( ! $lang || empty( $items ) ) return $items;

        foreach ( $items as $item ) {
            if ( ! empty( $item->title ) ) {
                $item->title = self::translate_ui_string( $item->title, $lang );
            }
            if ( ! empty( $item->attr_title ) ) {
                $item->attr_title = self::translate_ui_string( $item->attr_title, $lang );
            }
            if ( ! empty( $item->url ) ) {
                $item->url = self::translate_menu_url( $item->url, $lang );
            }
        }

        return $items;
    }

    /**
     * v2.4.1: Resolve the original post ID for the switcher using every reliable
     * source available. This avoids losing the buttons when translated requests
     * are routed through custom query vars or cloned post objects.
     */
    private static function resolve_current_post_id_for_switcher() {
        $candidates = array(
            $GLOBALS['nt_current_post_id'] ?? 0,
            get_queried_object_id(),
            get_the_ID(),
        );

        $global_post = $GLOBALS['post'] ?? null;
        if ( is_object( $global_post ) && ! empty( $global_post->ID ) ) {
            $candidates[] = (int) $global_post->ID;
        }

        foreach ( $candidates as $candidate ) {
            $candidate = (int) $candidate;
            if ( $candidate && get_post_type( $candidate ) === 'post' ) {
                return $candidate;
            }
        }

        // Last-resort translated-route lookup from /es/slug/, /fr/slug/, /pt/slug/.
        if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
            $path  = trim( (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ), '/' );
            $parts = array_values( array_filter( explode( '/', $path ) ) );
            if ( ! empty( $parts[0] ) && ! empty( $parts[1] ) && isset( NT_LANGS[ $parts[0] ] ) ) {
                $found = NT_DB::get_post_id_by_slug( $parts[0], sanitize_title( $parts[1] ) );
                if ( $found && get_post_type( (int) $found ) === 'post' ) {
                    return (int) $found;
                }
            }
        }

        return 0;
    }

    private static function current_request_url() {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host   = $_SERVER['HTTP_HOST'] ?? wp_parse_url( home_url(), PHP_URL_HOST );
        $uri    = $_SERVER['REQUEST_URI'] ?? '/';
        return esc_url_raw( $scheme . $host . $uri );
    }

    private static function get_english_permalink( $post_id ) {
        $saved_lang        = $GLOBALS['nt_current_lang'] ?? null;
        $saved_post_id     = $GLOBALS['nt_current_post_id'] ?? null;
        $saved_translation = $GLOBALS['nt_current_translation'] ?? null;

        unset( $GLOBALS['nt_current_lang'], $GLOBALS['nt_current_post_id'], $GLOBALS['nt_current_translation'] );
        $url = get_permalink( $post_id );

        if ( null !== $saved_lang ) {
            $GLOBALS['nt_current_lang'] = $saved_lang;
        }
        if ( null !== $saved_post_id ) {
            $GLOBALS['nt_current_post_id'] = $saved_post_id;
        }
        if ( null !== $saved_translation ) {
            $GLOBALS['nt_current_translation'] = $saved_translation;
        }

        return $url;
    }

    private static function build_front_page_switcher_html( $lang, $floating = false ) {
        $current = $lang ?: 'en';
        $items   = array();

        $items[] = self::switcher_item( 'en', '🇺🇸', 'English', trailingslashit( home_url( '/' ) ), $current );
        foreach ( NT_LANGS as $code => $name ) {
            $items[] = self::switcher_item( $code, NT_FLAGS[ $code ], $name, trailingslashit( home_url( '/' . $code ) ), $current );
        }

        $classes = $floating ? 'nt-switcher nt-switcher--front nt-switcher--floating-home' : 'nt-switcher nt-switcher--front';
        $html  = '<nav class="' . esc_attr( $classes ) . '" aria-label="' . esc_attr__( 'Language switcher', 'news-translator-pro' ) . '">';
        $html .= '<span class="nt-switcher__label">' . esc_html__( 'Read in:', 'news-translator-pro' ) . '</span> ';
        $html .= implode( ' &nbsp; ', $items );
        $html .= '</nav>';

        return $html;
    }

    private static function switcher_item( $code, $flag, $name, $url, $current ) {
        $active = ( $code === $current ) ? ' class="nt-switcher__link nt-switcher__link--active" aria-current="page"' : ' class="nt-switcher__link"';
        return '<a href="' . esc_url( $url ) . '"' . $active . '>' . esc_html( $flag . ' ' . $name ) . '</a>';
    }


    private static function get_translation_notice_html( $lang ) {
        $messages = array(
            'fr' => 'Traduction automatique : ce contenu a ete traduit par machine et peut contenir des erreurs.',
            'es' => 'Traduccion automatica: este contenido fue traducido por maquina y puede contener errores.',
            'pt' => 'Aviso de traducao automatica: este conteudo foi traduzido automaticamente e pode conter erros.',
        );

        $message = $messages[ $lang ] ?? 'Machine translation notice: this content was translated automatically and may contain errors.';

        return '<div class="nt-translation-notice" role="note">' . esc_html( $message ) . '</div>';
    }

    private static function get_manual_ui_translation( $text, $lang ) {
        $trimmed = trim( wp_strip_all_tags( (string) $text ) );
        if ( $trimmed === '' || ! $lang ) {
            return null;
        }

        $manual_ui = array(
            'fr' => array(
                'The Democracy Advocate' => 'Le Defenseur de la Democratie',
            ),
            'es' => array(
                'The Democracy Advocate' => 'El Defensor de la Democracia',
            ),
        );

        return $manual_ui[ $lang ][ $trimmed ] ?? null;
    }

    public static function get_ui_cache_version() {
        return (string) get_option( 'nt_ui_cache_version', '1' );
    }

    public static function bump_ui_cache_version() {
        update_option( 'nt_ui_cache_version', (string) time(), false );
    }

    public static function translate_ui_string( $text, $lang = '' ) {
        $lang = $lang ?: ( $GLOBALS['nt_current_lang'] ?? '' );
        if ( ! $lang || ! is_string( $text ) ) return $text;

        $trimmed = trim( wp_strip_all_tags( $text ) );
        if ( $trimmed === '' ) return $text;

        $manual = self::get_manual_ui_translation( $text, $lang );
        if ( is_string( $manual ) && $manual !== '' ) {
            return $manual;
        }

        static $runtime_cache = array();
        $version = self::get_ui_cache_version();
        $cache_key = $version . ':' . $lang . ':' . md5( $text );
        if ( isset( $runtime_cache[ $cache_key ] ) ) {
            return $runtime_cache[ $cache_key ];
        }

        $transient_key = 'nt_ui_' . md5( $cache_key );
        $cached = get_transient( $transient_key );
        if ( is_string( $cached ) && $cached !== '' ) {
            $runtime_cache[ $cache_key ] = $cached;
            return $cached;
        }

        // v2.6.2: Never call LibreTranslate from frontend/UI rendering on cache miss.
        // Frontend must serve manual translations or cached strings only to prevent runtime Gunicorn/API hits.
        // Missing UI strings should fall back to original text until admin cache warmup/manual translation occurs.
        return $text;
    }

    private static function translate_menu_url( $url, $lang ) {
        $home = trailingslashit( home_url() );
        if ( strpos( $url, $home ) !== 0 ) return $url;

        $relative = ltrim( wp_parse_url( $url, PHP_URL_PATH ) ?: '', '/' );
        if ( $relative === '' ) {
            return trailingslashit( home_url( '/' . $lang ) );
        }

        if ( preg_match( '#^(es|fr)(/|$)#', $relative ) ) {
            return $url;
        }

        $post_id = url_to_postid( $url );
        if ( $post_id && get_post_type( $post_id ) === 'post' ) {
            $row = NT_DB::get( $post_id, $lang );
            if ( $row ) {
                return nt_get_translated_url( $post_id, $lang );
            }
        }

        return $url;
    }

    public static function start_output_buffer( $lang = '' ) {
        // v2.3.4: Hard-disabled. Full-page output buffering breaks ads, widgets, sidebars, and SEO plugins.
        return;
    }

    public static function filter_full_page_html( $html ) {
        if ( ! is_string( $html ) || $html === '' ) {
            return $html;
        }

        $lang = $GLOBALS['nt_current_lang'] ?? '';
        $request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        $request_path = trim( (string) wp_parse_url( $request_uri, PHP_URL_PATH ), '/' );
        $is_front_like = is_front_page() || is_home() || in_array( $request_path, array( 'es', 'fr', 'pt' ), true );

        if ( $is_front_like ) {
            $switcher_html = self::build_front_page_switcher_html( $lang );
            if ( strpos( $html, 'nt-switcher--front' ) === false ) {
                $html = preg_replace( '#<body([^>]*)>#i', '<body$1>' . $switcher_html, $html, 1 );
            }
        }

        if ( ! $lang ) {
            return $html;
        }

        $html = preg_replace_callback(
            '#<(h[1-6]|div|p|span)([^>]*class="[^"]*(?:reviewnews-subtitle|aft-post-excerpt-and-meta|post-subtitle|entry-subtitle)[^"]*"[^>]*)>(.*?)</\1>#is',
            function( $matches ) use ( $lang ) {
                $raw = html_entity_decode( wp_strip_all_tags( $matches[3] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                $translated = self::translate_ui_string( trim( $raw ), $lang );
                return '<' . $matches[1] . $matches[2] . '>' . esc_html( $translated ) . '</' . $matches[1] . '>';
            },
            $html
        );

        $site_title = get_option( 'blogname' );
        if ( is_string( $site_title ) && trim( $site_title ) !== '' ) {
            $translated_site_title = self::translate_ui_string( $site_title, $lang );
            if ( $translated_site_title && $translated_site_title !== $site_title ) {
                $patterns = array(
                    '#(<a[^>]*rel="home"[^>]*>)\s*' . preg_quote( $site_title, '#' ) . '\s*(</a>)#u',
                    '#(<a[^>]*href="' . preg_quote( trailingslashit( home_url( '/' ) ), '#' ) . '"[^>]*>)\s*' . preg_quote( $site_title, '#' ) . '\s*(</a>)#u',
                    '#(<(?:h1|h2|div|span|p)[^>]*class="[^"]*(?:site-title|site-branding|custom-logo-name|navbar-brand|logo-title|site-header|brand|branding)[^"]*"[^>]*>)\s*' . preg_quote( $site_title, '#' ) . '\s*(</(?:h1|h2|div|span|p)>)#u',
                    '#(<title[^>]*>)\s*' . preg_quote( $site_title, '#' ) . '\s*(</title>)#u',
                );

                foreach ( $patterns as $pattern ) {
                    $html = preg_replace( $pattern, '$1' . esc_html( $translated_site_title ) . '$2', $html );
                }

                $html = preg_replace_callback(
                    '#<(a|h1|h2|div|span|p)([^>]*)>(.*?)</\1>#isu',
                    function( $matches ) use ( $site_title, $translated_site_title ) {
                        $inner = html_entity_decode( wp_strip_all_tags( $matches[3] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                        if ( trim( $inner ) !== $site_title ) {
                            return $matches[0];
                        }
                        return '<' . $matches[1] . $matches[2] . '>' . esc_html( $translated_site_title ) . '</' . $matches[1] . '>';
                    },
                    $html
                );

                if ( strpos( $html, $site_title ) !== false && ( is_front_page() || is_home() || in_array( $request_path, array( 'es', 'fr' ), true ) ) ) {
                    $html = str_replace( '>' . $site_title . '<', '>' . esc_html( $translated_site_title ) . '<', $html );
                }
            }
        }

        return $html;
    }

}
