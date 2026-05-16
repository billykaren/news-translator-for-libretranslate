<?php
/**
 * NT_Post_Translator — translates a post and all its SEO meta via LibreTranslate.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NT_Post_Translator {

    /** @var NT_LibreTranslate_API */
    private $api;

    public function __construct() {
        $this->api = new NT_LibreTranslate_API();
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Return cached translation or create a fresh one.
     *
     * @return array|WP_Error  Keys: title, content, excerpt, slug, seo_*
     */
    public function get_or_create( $post_id, $lang ) {
        $cached = NT_DB::get( $post_id, $lang );
        if ( $cached ) return $cached;
        return $this->create( $post_id, $lang );
    }

    /**
     * Force a fresh translation (ignores cache).
     *
     * @return array|WP_Error
     */
    public function refresh( $post_id, $lang ) {
        NT_DB::delete( $post_id, $lang );
        return $this->create( $post_id, $lang );
    }

    /**
     * Translate and store only the ReviewNews/AFT subtitle for an existing translation.
     *
     * Used by the delayed publish finalizer so first-publish translation can stay immediate
     * while subtitle meta still has a chance to finish saving.
     *
     * @return true|false|WP_Error
     */
    public function refresh_subtitle( $post_id, $lang ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'no_post', __( 'Post not found.', 'news-translator-pro' ) );
        }

        $source_subtitle = get_post_meta( $post_id, '_aft_subtitle', true );
        if ( ! is_string( $source_subtitle ) || '' === trim( $source_subtitle ) ) {
            $source_subtitle = get_post_meta( $post_id, 'aft_subtitle', true );
        }

        if ( ! is_string( $source_subtitle ) || '' === trim( $source_subtitle ) ) {
            return false;
        }

        $translated = $this->translate_field( $source_subtitle, $lang );
        if ( is_wp_error( $translated ) ) {
            return $translated;
        }

        $existing = NT_DB::get( $post_id, $lang );
        if ( ! $existing ) {
            return $this->create( $post_id, $lang );
        }

        return NT_DB::update_subtitle( $post_id, $lang, $translated );
    }

    // ── Core translation ──────────────────────────────────────────────────────

    private function create( $post_id, $lang ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'no_post', __( 'Post not found.', 'news-translator-pro' ) );
        }

        // ── 1. Core post fields ───────────────────────────────────────────────

        $title = $this->translate_field( $post->post_title, $lang );
        if ( is_wp_error( $title ) ) return $title;

        // v2.3.5: Translate raw saved post content, not fully rendered the_content.
        // Rendering the_content here expands ad scripts, widgets, shortcodes, and theme injections before translation,
        // which can strip or corrupt Google ads and other frontend output on translated pages.
        $source_content = $post->post_content;

        // Some themes/page builders produce huge filtered HTML that can choke translation.
        if ( strlen( wp_strip_all_tags( $source_content ) ) > 12000 ) {
            $source_content = wpautop( $post->post_content );
        }

        $content = $this->translate_field( $source_content, $lang );
        if ( is_wp_error( $content ) ) {
            $fallback_content = wpautop( $post->post_content );
            $content = $this->translate_field( $fallback_content, $lang );
            if ( is_wp_error( $content ) ) return $content;
        }

        $excerpt = '';
        if ( ! empty( $post->post_excerpt ) ) {
            $t = $this->translate_field( $post->post_excerpt, $lang );
            if ( ! is_wp_error( $t ) ) $excerpt = $t;
        }

        // v2.3.8: Translate ReviewNews post subtitle meta so translated pages do not
        // show the original English subtitle under the translated headline.
        $subtitle = '';
        $source_subtitle = get_post_meta( $post_id, '_aft_subtitle', true );
        if ( ! is_string( $source_subtitle ) || trim( $source_subtitle ) === '' ) {
            $source_subtitle = get_post_meta( $post_id, 'aft_subtitle', true );
        }
        if ( is_string( $source_subtitle ) && trim( $source_subtitle ) !== '' ) {
            $t = $this->translate_field( $source_subtitle, $lang );
            if ( ! is_wp_error( $t ) ) {
                $subtitle = $t;
            }
        }

        // Build a translated slug from the translated title.
        $slug = sanitize_title( $title );

        // ── 2. RankMath SEO fields ────────────────────────────────────────────

        $rm = $this->translate_rankmath( $post_id, $lang );

        // ── 3. Persist ────────────────────────────────────────────────────────

        $row = array_merge( array(
            'title'   => $title,
            'content' => $content,
            'excerpt'  => $excerpt,
            'subtitle' => $subtitle,
            'slug'     => $slug,
        ), $rm );

        $saved = NT_DB::save( $post_id, $lang, $row );
        if ( is_wp_error( $saved ) ) {
            return $saved;
        }

        $fresh = NT_DB::get( $post_id, $lang );
        if ( ! $fresh ) {
            return new WP_Error( 'nt_db_readback_failed', __( 'Translation was created but could not be read back from the database.', 'news-translator-pro' ) );
        }

        update_post_meta( $post_id, '_nt_translated_' . sanitize_key( $lang ), current_time( 'mysql' ) );

        return $fresh;
    }

    // ── RankMath helpers ──────────────────────────────────────────────────────

    /**
     * Translate all RankMath meta fields for a post.
     *
     * @return array  Associative array of translated seo_* fields.
     */
    private function translate_rankmath( $post_id, $lang ) {
        $out = array(
            'seo_title'     => '',
            'seo_desc'      => '',
            'seo_keywords'  => '',
            'og_title'      => '',
            'og_desc'       => '',
            'twitter_title' => '',
            'twitter_desc'  => '',
            'focus_kw'      => '',
        );

        // RankMath stores meta under these keys.
        $map = array(
            'seo_title'     => 'rank_math_title',
            'seo_desc'      => 'rank_math_description',
            'seo_keywords'  => 'rank_math_keywords',
            'og_title'      => 'rank_math_og_title',
            'og_desc'       => 'rank_math_og_description',
            'twitter_title' => 'rank_math_twitter_title',
            'twitter_desc'  => 'rank_math_twitter_description',
            'focus_kw'      => 'rank_math_focus_keyword',
        );

        foreach ( $map as $our_key => $rm_key ) {
            $value = get_post_meta( $post_id, $rm_key, true );
            if ( empty( $value ) ) continue;

            // RankMath titles often contain %title% tokens — strip before translating.
            $clean = $this->strip_rm_tokens( $value );
            if ( empty( trim( $clean ) ) ) {
                $out[ $our_key ] = $value; // keep as-is if nothing left
                continue;
            }

            $translated = $this->translate_field( $clean, $lang );
            $out[ $our_key ] = is_wp_error( $translated ) ? $clean : $translated;
        }

        return $out;
    }

    /**
     * Remove RankMath variable tokens like %title%, %sitename%, %sep%.
     */
    private function strip_rm_tokens( $text ) {
        return trim( preg_replace( '/%[a-z_]+%/', '', $text ) );
    }

    // ── Utility ───────────────────────────────────────────────────────────────

    /**
     * Protect Gutenberg block comments, scripts, styles, iframes, and shortcodes before sending
     * content to LibreTranslate. Translators can otherwise expose comments like
     * wp:image {"id":123} as visible text or corrupt ad/script markup.
     *
     * @return array{0:string,1:array}
     */
    private function protect_markup_tokens( $text ) {
        $tokens = array();
        $i      = 0;

        $pattern = '/(<!--\s*\/?wp:[\s\S]*?-->|<script\b[\s\S]*?<\/script>|<style\b[\s\S]*?<\/style>|<iframe\b[\s\S]*?<\/iframe>|\[[A-Za-z0-9_\-]+(?:\s+[^\]]*)?\](?:[\s\S]*?\[\/[A-Za-z0-9_\-]+\])?)/i';

        $text = preg_replace_callback( $pattern, function ( $matches ) use ( &$tokens, &$i ) {
            $key = ' ZZZNTPTOKEN' . $i . 'ENDZZZ ';
            $tokens[ $key ] = $matches[0];
            $i++;
            return $key;
        }, $text );

        return array( $text, $tokens );
    }

    /**
     * Restore protected markup tokens after translation. Also repairs the common case where
     * LibreTranslate trims or normalizes whitespace around the token text.
     */
    private function restore_markup_tokens( $text, $tokens ) {
        if ( empty( $tokens ) ) {
            return NT_DB::clean_symbolic_tokens( $text );
        }

        $index = 0;
        foreach ( $tokens as $key => $value ) {
            $compact = trim( $key );
            $text = str_replace( $key, $value, $text );
            $text = str_replace( $compact, $value, $text );

            // v2.6.7: LibreTranslate may strip or mutate placeholder brackets.
            // Restore known token-number variants before the final cleanup pass.
            $pattern = '/(?:□|\s|⟪|\[|\{|ZZZ)*NTP(?:ROTECT)?(?:TOKEN)?\s*' . preg_quote( (string) $index, '/' ) . '\s*(?:END)?(?:ZZZ|⟫|\]|\})*/iu';
            $text = preg_replace( $pattern, $value, $text );
            $index++;
        }

        return NT_DB::clean_symbolic_tokens( $text );
    }

    /**
     * Translate a single text field, with structural WordPress markup protected.
     */
    private function translate_field( $text, $lang ) {
        if ( empty( trim( $text ) ) ) return $text;

        list( $protected, $tokens ) = $this->protect_markup_tokens( $text );
        $translated = $this->api->translate( $protected, $lang );

        if ( is_wp_error( $translated ) ) {
            return $translated;
        }

        return NT_DB::clean_symbolic_tokens( $this->restore_markup_tokens( $translated, $tokens ) );
    }
}
