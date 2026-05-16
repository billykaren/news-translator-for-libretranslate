<?php
/**
 * NT_RankMath — overrides RankMath's SEO output on translated pages.
 *
 * On /es/ and /fr/ pages, we replace:
 *   - <title> tag
 *   - meta description
 *   - og:title / og:description / og:url / og:locale
 *   - twitter:title / twitter:description
 *   - focus keyword (used by RM schema)
 *   - canonical URL (handled by NT_Hreflang, but we double up here)
 *
 * RankMath exposes filters for each of these — no core file edits needed.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NT_RankMath {

    public static function init() {
        // Only hook when we're on a translated page.
        add_action( 'template_redirect', array( __CLASS__, 'maybe_hook' ), 5 );
    }

    public static function maybe_hook() {
        if ( ! isset( $GLOBALS['nt_current_lang'] ) ) return;

        // ── Document title ────────────────────────────────────────────────────
        add_filter( 'rank_math/frontend/title',             array( __CLASS__, 'filter_title' ) );
        add_filter( 'pre_get_document_title',               array( __CLASS__, 'filter_title' ) );  // WP core fallback

        // ── Meta description ──────────────────────────────────────────────────
        add_filter( 'rank_math/frontend/description',       array( __CLASS__, 'filter_desc' ) );

        // ── Open Graph ────────────────────────────────────────────────────────
        add_filter( 'rank_math/opengraph/facebook/og_title',       array( __CLASS__, 'filter_og_title' ) );
        add_filter( 'rank_math/opengraph/facebook/og_description',  array( __CLASS__, 'filter_og_desc' ) );
        add_filter( 'rank_math/opengraph/facebook/og_url',          array( __CLASS__, 'filter_og_url' ) );
        add_filter( 'rank_math/opengraph/facebook/og_locale',       array( __CLASS__, 'filter_og_locale' ) );

        // ── Twitter card ──────────────────────────────────────────────────────
        add_filter( 'rank_math/opengraph/twitter/twitter_title',       array( __CLASS__, 'filter_tw_title' ) );
        add_filter( 'rank_math/opengraph/twitter/twitter_description', array( __CLASS__, 'filter_tw_desc' ) );

        // ── Canonical ─────────────────────────────────────────────────────────
        add_filter( 'rank_math/frontend/canonical',         array( 'NT_Hreflang', 'filter_canonical' ) );

        // ── Robots: allow indexing of translated pages ─────────────────────
        add_filter( 'rank_math/frontend/robots',            array( __CLASS__, 'filter_robots' ) );

        // ── Schema / JSON-LD ─────────────────────────────────────────────────
        add_filter( 'rank_math/snippet/rich_snippet_article_entity', array( __CLASS__, 'filter_schema_article' ) );
        add_filter( 'rank_math/json_ld',                             array( __CLASS__, 'filter_jsonld' ), 99 );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function row() {
        $lang    = $GLOBALS['nt_current_lang']    ?? null;
        $post_id = $GLOBALS['nt_current_post_id'] ?? null;
        if ( ! $lang || ! $post_id ) return null;
        return NT_DB::get( $post_id, $lang );
    }

    // ── Filter callbacks ──────────────────────────────────────────────────────

    public static function filter_title( $title ) {
        $row = self::row();
        if ( ! $row ) return $title;
        $seo_title = ! empty( $row['seo_title'] ) ? $row['seo_title'] : $row['title'];
        return $seo_title . ' – ' . get_bloginfo( 'name' );
    }

    public static function filter_desc( $desc ) {
        $row = self::row();
        if ( ! $row ) return $desc;
        return ! empty( $row['seo_desc'] ) ? $row['seo_desc'] : wp_trim_words( wp_strip_all_tags( $row['excerpt'] ?: $row['content'] ), 30 );
    }

    public static function filter_og_title( $val ) {
        $row = self::row();
        if ( ! $row ) return $val;
        return ! empty( $row['og_title'] ) ? $row['og_title'] : $row['title'];
    }

    public static function filter_og_desc( $val ) {
        $row = self::row();
        if ( ! $row ) return $val;
        return ! empty( $row['og_desc'] ) ? $row['og_desc'] : self::filter_desc( $val );
    }

    public static function filter_og_url( $val ) {
        $lang    = $GLOBALS['nt_current_lang']    ?? null;
        $post_id = $GLOBALS['nt_current_post_id'] ?? null;
        if ( ! $lang || ! $post_id ) return $val;
        return nt_get_translated_url( $post_id, $lang );
    }

    public static function filter_og_locale( $val ) {
        $lang = $GLOBALS['nt_current_lang'] ?? null;
        return $lang ? ( NT_LOCALES[ $lang ] ?? $val ) : $val;
    }

    public static function filter_tw_title( $val ) {
        $row = self::row();
        if ( ! $row ) return $val;
        return ! empty( $row['twitter_title'] ) ? $row['twitter_title'] : $row['title'];
    }

    public static function filter_tw_desc( $val ) {
        $row = self::row();
        if ( ! $row ) return $val;
        return ! empty( $row['twitter_desc'] ) ? $row['twitter_desc'] : self::filter_desc( $val );
    }

    public static function filter_robots( $robots ) {
        // Ensure translated pages are indexable (no noindex sneaking in).
        unset( $robots['noindex'] );
        $robots['index']  = 'index';
        $robots['follow'] = 'follow';
        return $robots;
    }

    /**
     * Update Article schema entity with translated headline / description.
     */
    public static function filter_schema_article( $entity ) {
        $row = self::row();
        if ( ! $row ) return $entity;

        $lang = $GLOBALS['nt_current_lang'] ?? null;

        if ( ! empty( $row['title'] ) )    $entity['headline']    = $row['title'];
        if ( ! empty( $row['seo_desc'] ) ) $entity['description'] = $row['seo_desc'];
        if ( $lang ) $entity['inLanguage'] = NT_LOCALES[ $lang ] ?? $lang;

        return $entity;
    }

    /**
     * Patch the full JSON-LD output: update @graph url and webpage name.
     */
    public static function filter_jsonld( $data ) {
        $row     = self::row();
        $lang    = $GLOBALS['nt_current_lang']    ?? null;
        $post_id = $GLOBALS['nt_current_post_id'] ?? null;

        if ( ! $row || ! $lang || ! $post_id ) return $data;

        $url = nt_get_translated_url( $post_id, $lang );

        foreach ( $data as &$node ) {
            if ( isset( $node['@type'] ) && in_array( $node['@type'], array( 'WebPage', 'NewsArticle', 'Article' ), true ) ) {
                $node['url']  = $url;
                $node['name'] = $row['seo_title'] ?: $row['title'];
                if ( $lang ) $node['inLanguage'] = NT_LOCALES[ $lang ] ?? $lang;
            }
        }

        return $data;
    }
}
