<?php
/**
 * NT_Sitemap — injects translated URLs into:
 *
 *  1. RankMath's main XML sitemap  (rankmath/sitemap/*)
 *  2. RankMath's Google News sitemap (rankmath/sitemap/news-sitemap.xml)
 *  3. WordPress core sitemap (wp_sitemaps_*)
 *  4. A standalone /nt-sitemap.xml as a fallback / supplement
 *
 * How RankMath sitemap hooks work:
 *   - rank_math/sitemap/entry        — filter each URL entry before output
 *   - rank_math/sitemap/urlset       — filter the full <urlset> XML string
 *   - rank_math/sitemap/news_sitemap — filter the full Google News XML string
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NT_Sitemap {

    public static function init() {
        // ── RankMath sitemap integration ─────────────────────────────────────
        add_filter( 'rank_math/sitemap/entry',        array( __CLASS__, 'rm_inject_entry' ),        10, 3 );
        add_filter( 'rank_math/sitemap/urlset',       array( __CLASS__, 'rm_inject_urlset' ),       10, 2 );
        add_filter( 'rank_math/sitemap/news_sitemap', array( __CLASS__, 'rm_inject_news_sitemap' ) );
        add_filter( 'rank_math/sitemap/index',        array( __CLASS__, 'rm_sitemap_index_entry' ) );

        // ── WordPress core sitemap integration ───────────────────────────────
        add_filter( 'wp_sitemaps_posts_entry', array( __CLASS__, 'core_inject_entry' ), 10, 3 );

        // NOTE: The /nt-sitemap.xml rewrite rule is registered by NT_Rewrite::add_rules()
        // and the nt_sitemap query var is whitelisted by NT_Rewrite::add_query_vars().
        // serve_standalone() is called directly from NT_Rewrite::handle_request().
    }

    // ── RankMath: per-URL entry ───────────────────────────────────────────────

    /**
     * For each English post URL in RankMath's sitemap, append alternate hreflang annotations.
     * RankMath calls this filter with ($url_data_array, $object_type, $object).
     *
     * @param  array  $url   Current URL data (loc, lastmod, changefreq, priority, images).
     * @param  string $type  Object type ('post', 'term', …).
     * @param  object $obj   The post or term object.
     * @return array
     */
    public static function rm_inject_entry( $url, $type, $obj ) {
        if ( $type !== 'post' || ! isset( $obj->ID ) ) return $url;

        $post_id = $obj->ID;
        $alts    = array();

        // x-default / en
        $alts[] = array( 'hreflang' => 'x-default', 'href' => get_permalink( $post_id ) );
        $alts[] = array( 'hreflang' => 'en',         'href' => get_permalink( $post_id ) );

        foreach ( NT_LANGS as $code => $name ) {
            $row = NT_DB::get( $post_id, $code );
            if ( ! $row ) continue;
            $alts[] = array(
                'hreflang' => NT_LOCALES[ $code ] ?? $code,
                'href'     => nt_get_translated_url( $post_id, $code ),
            );
        }

        // RankMath supports xhtml:link alternates inside <url>.
        if ( ! isset( $url['xhtml:link'] ) ) $url['xhtml:link'] = array();
        foreach ( $alts as $alt ) {
            $url['xhtml:link'][] = array(
                'rel'      => 'alternate',
                'hreflang' => $alt['hreflang'],
                'href'     => $alt['href'],
            );
        }

        return $url;
    }

    /**
     * Append translated <url> entries to the main post sitemap XML.
     *
     * @param  string $xml   Full sitemap XML.
     * @param  string $type  Sitemap type ('post', 'page', …).
     * @return string
     */
    public static function rm_inject_urlset( $xml, $type ) {
        if ( $type !== 'post' ) return $xml;

        $extra = '';
        foreach ( array_keys( NT_LANGS ) as $lang ) {
            $rows = NT_DB::get_all_for_lang( $lang );
            foreach ( $rows as $row ) {
                $post    = get_post( $row['post_id'] );
                if ( ! $post ) continue;
                $lastmod = gmdate( 'c', strtotime( $row['translated_at'] ) );
                $extra  .= self::url_entry( nt_get_translated_url( $row['post_id'], $lang ), $lastmod );
            }
        }

        if ( $extra ) {
            $xml = str_replace( '</urlset>', $extra . '</urlset>', $xml );
        }

        return $xml;
    }

    /**
     * Append translated entries to RankMath's Google News sitemap.
     *
     * @param  string $xml   Full Google News sitemap XML.
     * @return string
     */
    public static function rm_inject_news_sitemap( $xml ) {
        $extra = '';
        // Google News sitemap window: last 2 days.
        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-2 days' ) );

        foreach ( array_keys( NT_LANGS ) as $lang ) {
            $rows = NT_DB::get_all_for_lang( $lang );
            foreach ( $rows as $row ) {
                if ( $row['translated_at'] < $cutoff ) continue;

                $post = get_post( $row['post_id'] );
                if ( ! $post ) continue;

                $pub_date = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $post->post_date_gmt . ' +0000' ) );
                $locale   = NT_LOCALES[ $lang ] ?? $lang;
                $url      = nt_get_translated_url( $row['post_id'], $lang );

                $extra .= "\t<url>\n";
                $extra .= "\t\t<loc>" . esc_url( $url ) . "</loc>\n";
                $extra .= "\t\t<news:news>\n";
                $extra .= "\t\t\t<news:publication>\n";
                $extra .= "\t\t\t\t<news:name>" . esc_xml( get_bloginfo( 'name' ) ) . "</news:name>\n";
                $extra .= "\t\t\t\t<news:language>" . esc_xml( substr( $locale, 0, 2 ) ) . "</news:language>\n";
                $extra .= "\t\t\t</news:publication>\n";
                $extra .= "\t\t\t<news:publication_date>" . esc_xml( $pub_date ) . "</news:publication_date>\n";
                $extra .= "\t\t\t<news:title>" . esc_xml( $row['title'] ) . "</news:title>\n";
                $extra .= "\t\t</news:news>\n";
                $extra .= "\t</url>\n";
            }
        }

        if ( $extra ) {
            $xml = str_replace( '</urlset>', $extra . '</urlset>', $xml );
        }

        return $xml;
    }

    // ── RankMath sitemap index ────────────────────────────────────────────────

    /**
     * Add our standalone sitemap to RankMath's sitemap index.
     */
    public static function rm_sitemap_index_entry( $index ) {
        $index .= sprintf(
            "\t<sitemap>\n\t\t<loc>%s</loc>\n\t\t<lastmod>%s</lastmod>\n\t</sitemap>\n",
            esc_url( home_url( '/nt-sitemap.xml' ) ),
            esc_xml( gmdate( 'c' ) )
        );
        return $index;
    }

    // ── WordPress core sitemap ────────────────────────────────────────────────

    public static function core_inject_entry( $entry, $post, $post_type ) {
        // Core sitemap doesn't natively support hreflang; we just return the entry.
        // Translated URLs are covered by our standalone sitemap.
        return $entry;
    }

    // ── Standalone sitemap ────────────────────────────────────────────────────

    /**
     * Output the standalone translated sitemap XML.
     * Called directly by NT_Rewrite::handle_request() when nt_sitemap=1 is set.
     */
    public static function serve_standalone() {
        status_header( 200 );
        nocache_headers();
        header( 'Content-Type: application/xml; charset=UTF-8' );
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<?xml-stylesheet type="text/xsl" href="' . esc_url( NT_URL . 'assets/sitemap.xsl' ) . '"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
        echo ' xmlns:xhtml="http://www.w3.org/1999/xhtml"';
        echo ' xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";

        foreach ( array_keys( NT_LANGS ) as $lang ) {
            $rows = NT_DB::get_all_for_lang( $lang );
            foreach ( $rows as $row ) {
                $post    = get_post( $row['post_id'] );
                if ( ! $post ) continue;
                $url     = nt_get_translated_url( $row['post_id'], $lang );
                $lastmod = gmdate( 'c', strtotime( $row['translated_at'] ) );
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- url_entry() escapes each XML value before returning markup.
                echo self::url_entry( $url, $lastmod, '0.8', 'weekly', $row['post_id'], $lang );
            }
        }

        echo '</urlset>';
        exit;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function url_entry( $url, $lastmod, $priority = '0.8', $changefreq = 'weekly', $post_id = 0, $lang = '' ) {
        $xml  = "\t<url>\n";
        $xml .= "\t\t<loc>" . esc_url( $url ) . "</loc>\n";
        $xml .= "\t\t<lastmod>" . esc_xml( $lastmod ) . "</lastmod>\n";
        $xml .= "\t\t<changefreq>" . esc_xml( $changefreq ) . "</changefreq>\n";
        $xml .= "\t\t<priority>" . esc_xml( $priority ) . "</priority>\n";

        if ( $post_id ) {
            $xml .= "\t\t<xhtml:link rel=\"alternate\" hreflang=\"en\" href=\"" . esc_url( get_permalink( $post_id ) ) . "\" />\n";
            $xml .= "\t\t<xhtml:link rel=\"alternate\" hreflang=\"x-default\" href=\"" . esc_url( get_permalink( $post_id ) ) . "\" />\n";
            foreach ( array_keys( NT_LANGS ) as $alt_lang ) {
                $row = NT_DB::get( $post_id, $alt_lang );
                if ( ! $row ) continue;
                $locale = NT_LOCALES[ $alt_lang ] ?? $alt_lang;
                $xml .= "\t\t<xhtml:link rel=\"alternate\" hreflang=\"" . esc_attr( $locale ) . "\" href=\"" . esc_url( nt_get_translated_url( $post_id, $alt_lang ) ) . "\" />\n";
            }
        }

        $xml .= "\t</url>\n";
        return $xml;
    }
}

if ( ! function_exists( 'esc_xml' ) ) {
    function esc_xml( $text ) {
        return htmlspecialchars( $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
    }
}
