<?php
/**
 * NT_LibreTranslate_API — thin HTTP wrapper around LibreTranslate.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NT_LibreTranslate_API {

    private $api_url;
    private $api_key;

    public function __construct() {
        $this->api_url = trailingslashit( get_option( 'nt_api_url', 'https://libretranslate.com' ) );
        $this->api_key = get_option( 'nt_api_key', '' );
    }

    /**
     * Translate a string from English to $target language.
     *
     * @param  string $text   Source text / HTML.
     * @param  string $target Language code ('es', 'fr', …).
     * @return string|WP_Error
     */
    public function translate( $text, $target ) {
        if ( empty( trim( $text ) ) ) return '';

        $body = array(
            'q'      => $text,
            'source' => 'en',
            'target' => $target,
            'format' => 'html',
        );
        if ( $this->api_key ) $body['api_key'] = $this->api_key;

        $response = wp_remote_post( $this->api_url . 'translate', array(
            'timeout' => 45,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $body ),
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'lt_connect', sprintf(
                /* translators: %s: error message returned while connecting to LibreTranslate. */
                __( 'LibreTranslate connection failed: %s', 'news-translator-pro' ),
                $response->get_error_message()
            ) );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            return new WP_Error( 'lt_http', sprintf(
                /* translators: 1: HTTP response code, 2: error message returned by LibreTranslate. */
                __( 'LibreTranslate returned %1$d: %2$s', 'news-translator-pro' ),
                $code,
                $data['error'] ?? 'Unknown error'
            ) );
        }

        return $data['translatedText'] ?? new WP_Error( 'lt_empty', __( 'Empty translation response.', 'news-translator-pro' ) );
    }

    /** Quick connectivity test. Returns true|WP_Error. */
    public function test() {
        $r = $this->translate( 'Hello world', 'es' );
        return is_wp_error( $r ) ? $r : true;
    }
}
