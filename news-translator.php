<?php
/**
 * Plugin Name: News Translator Pro
 * Plugin URI:  https://thedemocracyadvocate.com
 * Description: Translates news posts into /es/, /fr/, and /pt/ subdirectories with full SEO, RankMath, XML sitemap, and Google News sitemap support.
 * Version: 2.6.4
 * Author:      The Democracy Advocate
 * Donate link: https://www.paypal.com/donate/?hosted_button_id=G69AUBDK36GD8
License:     GPL-2.0+
 * Text Domain: news-translator-pro
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'NT_VERSION',  '2.6.4' );
define( 'NT_DIR',      plugin_dir_path( __FILE__ ) );
define( 'NT_URL',      plugin_dir_url( __FILE__ ) );
define( 'NT_LANGS',    array( 'es' => 'Spanish', 'fr' => 'French', 'pt' => 'Portuguese' ) );
define( 'NT_LOCALES',  array( 'es' => 'es_ES',   'fr' => 'fr_FR',   'pt' => 'pt_BR' ) );
define( 'NT_FLAGS',    array( 'es' => '🇪🇸',      'fr' => '🇫🇷',    'pt' => '🇧🇷' ) );
define( 'NT_TEXTDOMAIN', 'news-translator-pro' );

// ── Core classes ──────────────────────────────────────────────────────────────
require_once NT_DIR . 'includes/class-db.php';
require_once NT_DIR . 'includes/class-libretranslate-api.php';
require_once NT_DIR . 'includes/class-post-translator.php';
require_once NT_DIR . 'includes/class-rewrite.php';
require_once NT_DIR . 'includes/class-frontend.php';
require_once NT_DIR . 'includes/class-hreflang.php';
require_once NT_DIR . 'includes/class-sitemap.php';
require_once NT_DIR . 'includes/class-rankmath.php';

// ── Admin classes ─────────────────────────────────────────────────────────────
if ( is_admin() ) {
    require_once NT_DIR . 'admin/class-settings-page.php';
    require_once NT_DIR . 'admin/class-meta-box.php';
}

// ── Activation / Deactivation ─────────────────────────────────────────────────
register_activation_hook( __FILE__, 'nt_activation_flush' );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

/**
 * Activation: install DB table then register + flush rewrite rules.
 *
 * register_activation_hook() fires at parse time, before plugins_loaded, so
 * we must call NT_DB::install() and NT_Rewrite::add_rules() directly here
 * rather than relying on nt_boot() to have run first.
 */
function nt_activation_flush() {
    NT_DB::install();
    NT_Rewrite::add_rules();
    flush_rewrite_rules();
}

// ── Boot ──────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'nt_boot' );
function nt_boot() {
    if ( get_option( 'nt_db_version' ) !== NT_VERSION ) {
        NT_DB::install();
    }

    NT_Rewrite::init();
    NT_Frontend::init();
    NT_Hreflang::init();
    NT_Sitemap::init();
    NT_RankMath::init();

    if ( is_admin() ) {
        new NT_Settings_Page();
        new NT_Meta_Box();
    }
}



function nt_bump_ui_translation_cache_version() {
    if ( class_exists( 'NT_Frontend' ) ) {
        NT_Frontend::bump_ui_cache_version();
        return;
    }

    update_option( 'nt_ui_cache_version', (string) time(), false );
}



// ── Frontend translated locale / html lang handling ─────────────────────────
add_filter( 'locale', 'nt_filter_translated_locale', 999 );
add_filter( 'determine_locale', 'nt_filter_translated_locale', 999 );
add_filter( 'language_attributes', 'nt_filter_language_attributes', 999, 2 );

function nt_current_translated_locale() {
    $lang = $GLOBALS['nt_current_lang'] ?? null;

    if ( ! $lang && ! is_admin() && isset( $_SERVER['REQUEST_URI'] ) ) {
        $path  = trim( (string) parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ), '/' );
        $first = $path ? strtok( $path, '/' ) : '';
        if ( $first && isset( NT_LANGS[ $first ] ) ) {
            $lang = $first;
        }
    }

    if ( $lang && isset( NT_LOCALES[ $lang ] ) ) {
        return NT_LOCALES[ $lang ];
    }

    return null;
}

function nt_filter_translated_locale( $locale ) {
    $translated_locale = nt_current_translated_locale();
    return $translated_locale ?: $locale;
}

function nt_filter_language_attributes( $output, $doctype = 'html' ) {
    $locale = nt_current_translated_locale();
    if ( ! $locale ) {
        return $output;
    }

    $lang = str_replace( '_', '-', $locale );

    if ( preg_match( '/\slang=("|\')[^"\']*("|\')/i', $output ) ) {
        $output = preg_replace( '/\slang=("|\')[^"\']*("|\')/i', ' lang="' . esc_attr( $lang ) . '"', $output, 1 );
    } else {
        $output = 'lang="' . esc_attr( $lang ) . '" ' . $output;
    }

    return $output;
}

// v2.3.6: Force translated document language context so /es/, /fr/, and /pt/ pages
// do not advertise themselves as en-US in the <html> tag.

// ── Auto-translate on publish ─────────────────────────────────────────────────
add_action( 'transition_post_status', 'nt_auto_translate_on_publish', 10, 3 );
function nt_auto_translate_on_publish( $new, $old, $post ) {
    if ( $new !== 'publish' || $old === 'publish' ) return;
    if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) return;
    if ( ! get_option( 'nt_auto_translate', 0 ) )   return;

    $translator = new NT_Post_Translator();
    foreach ( array_keys( NT_LANGS ) as $lang ) {
        $translator->get_or_create( $post->ID, $lang );
    }
}


// ── UI translation cache invalidation ─────────────────────────────────────────
add_action( 'save_post', 'nt_bump_ui_translation_cache_version', 10, 0 );
add_action( 'wp_update_nav_menu', 'nt_bump_ui_translation_cache_version', 10, 0 );
add_action( 'customize_save_after', 'nt_bump_ui_translation_cache_version', 10, 0 );
add_action( 'updated_option', 'nt_maybe_bump_ui_translation_cache_version_on_option_change', 10, 3 );

function nt_maybe_bump_ui_translation_cache_version_on_option_change( $option, $old_value, $value ) {
    if ( $old_value === $value ) {
        return;
    }

    $watched = array(
        'blogname',
        'blogdescription',
        'nt_auto_translate',
        'widget_text',
        'sidebars_widgets',
        'theme_mods_' . get_option( 'stylesheet' ),
        'nav_menu_options',
    );

    if ( in_array( $option, $watched, true ) || strpos( $option, 'widget_' ) === 0 || strpos( $option, 'theme_mods_' ) === 0 ) {
        nt_bump_ui_translation_cache_version();
    }
}


// v2.3.5: Legacy ntp_add_hreflang_tags() removed.
// NT_Hreflang now owns all canonical and hreflang output to prevent duplicates and homepage hijacking.



// ── Translated page AdSense fallback for Site Kit ─────────────────────────────
// v2.3.7: Some Site Kit AdSense injections do not fire on custom translated
// rewrite URLs even after single-post query emulation. On translated pages only,
// output the same Auto Ads loader using Site Kit's saved AdSense client ID.
add_action( 'wp_head', 'nt_translated_adsense_fallback', 99 );
function nt_translated_adsense_fallback() {
    if ( is_admin() || empty( $GLOBALS['nt_current_lang'] ) ) {
        return;
    }

    $client_id = '';
    $settings  = get_option( 'googlesitekit_adsense_settings', array() );

    if ( is_array( $settings ) && ! empty( $settings['clientID'] ) ) {
        $client_id = $settings['clientID'];
    }

    if ( ! $client_id ) {
        $client_id = 'ca-pub-4492604985484667';
    }

    if ( strpos( $client_id, 'ca-pub-' ) !== 0 ) {
        return;
    }

    echo "
<!-- News Translator Pro v2.4.1 translated-page AdSense fallback -->
";
    echo '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . esc_attr( $client_id ) . '" crossorigin="anonymous"></script>' . "
";
}
