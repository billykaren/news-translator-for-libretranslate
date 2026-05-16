<?php
/**
 * NT_Hreflang — outputs one clean set of hreflang tags and filters canonical URLs.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NT_Hreflang {

    public static function init() {
        add_action( 'wp_head', array( __CLASS__, 'output_tags' ), 2 );

        // Let Rank Math/Yoast keep normal English canonical handling, but correct translated URLs.
        add_filter( 'rank_math/frontend/canonical', array( __CLASS__, 'filter_canonical' ), 20 );
        add_filter( 'wpseo_canonical',               array( __CLASS__, 'filter_canonical' ), 20 );
    }

    public static function output_tags() {
        if ( is_admin() || is_feed() || is_robots() ) {
            return;
        }

        // v2.3.4: Never let a translated post bleed canonical/hreflang onto the homepage.
        if ( is_front_page() || is_home() ) {
            self::output_home_tags();
            return;
        }

        $post_id = self::current_post_id();
        if ( ! $post_id || get_post_type( $post_id ) !== 'post' ) {
            return;
        }

        $links  = array();
        $en_url = ! empty( $GLOBALS['nt_original_permalink'] ) ? $GLOBALS['nt_original_permalink'] : get_permalink( $post_id );

        $links[] = array( 'hreflang' => 'x-default', 'href' => $en_url );
        $links[] = array( 'hreflang' => 'en',        'href' => $en_url );

        foreach ( NT_LANGS as $code => $name ) {
            $row = NT_DB::get( $post_id, $code );
            if ( ! $row ) {
                continue;
            }
            $links[] = array(
                'hreflang' => self::locale( $code ),
                'href'     => nt_get_translated_url( $post_id, $code ),
            );
        }

        self::print_links( $links );
    }

    private static function output_home_tags() {
        // Homepage has no translated article alternate. Keep it clean and canonical to itself.
        $home = home_url( '/' );
        self::print_links( array(
            array( 'hreflang' => 'x-default', 'href' => $home ),
            array( 'hreflang' => 'en',        'href' => $home ),
        ) );
    }

    private static function print_links( $links ) {
        $seen = array();
        foreach ( $links as $link ) {
            $key = $link['hreflang'] . '|' . $link['href'];
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            printf(
                '<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
                esc_attr( $link['hreflang'] ),
                esc_url( $link['href'] )
            );
        }
    }

    public static function filter_canonical( $canonical ) {
        if ( is_admin() || is_feed() || is_robots() ) {
            return $canonical;
        }

        if ( is_front_page() || is_home() ) {
            return home_url( '/' );
        }

        if ( isset( $GLOBALS['nt_current_lang'] ) ) {
            $lang    = $GLOBALS['nt_current_lang'];
            $post_id = $GLOBALS['nt_current_post_id'] ?? 0;
            if ( $post_id && isset( NT_LANGS[ $lang ] ) ) {
                return nt_get_translated_url( $post_id, $lang );
            }
        }

        return $canonical;
    }

    private static function current_post_id() {
        if ( isset( $GLOBALS['nt_current_post_id'] ) && $GLOBALS['nt_current_post_id'] ) {
            return (int) $GLOBALS['nt_current_post_id'];
        }

        if ( is_single() && get_post_type() === 'post' ) {
            return (int) get_the_ID();
        }

        return 0;
    }

    private static function locale( $code ) {
        return NT_LOCALES[ $code ] ?? $code;
    }
}
