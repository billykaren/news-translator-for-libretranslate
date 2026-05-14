<?php
/**
 * NT_Meta_Box — "Translations" sidebar panel in the post editor.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NT_Meta_Box {

    public function __construct() {
        add_action( 'add_meta_boxes', array( $this, 'register' ) );
        add_action( 'admin_post_nt_translate', array( $this, 'handle' ) );
        add_action( 'admin_notices', array( $this, 'success_notice' ) );
    }

    private function notice_key() {
        return 'nt_meta_notice_' . get_current_user_id();
    }

    public function register() {
        add_meta_box(
            'nt-translations',
            __( 'Translations', NT_TEXTDOMAIN ),
            array( $this, 'render' ),
            array( 'post', 'page' ),
            'side',
            'default'
        );
    }

    public function render( $post ) {
        global $wpdb;

        $rows  = array();
        $table = NT_DB::table();

        // v2.4.6: read status fresh from the database, not object cache.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE post_id = %d",
                (int) $post->ID
            ),
            ARRAY_A
        );

        if ( is_array( $results ) ) {
            foreach ( $results as $row ) {
                if ( ! empty( $row['language'] ) ) {
                    $rows[ $row['language'] ] = $row;
                }
            }
        }
        ?>
        <style>
        .nt-mb-table { width:100%; border-collapse:collapse; font-size:12px; }
        .nt-mb-table td { padding:5px 3px; vertical-align:middle; }
        .nt-mb-table tr:not(:last-child) td { border-bottom:1px solid #f0f0f0; }
        .nt-mb-status-ok { color:#46b450; }
        .nt-mb-status-no { color:#aaa; }
        </style>
        <table class="nt-mb-table">
        <?php foreach ( NT_LANGS as $code => $name ) : ?>
            <?php
            $translated = isset( $rows[ $code ] ) || (bool) get_post_meta( $post->ID, '_nt_translated_' . $code, true );
            $action_url = wp_nonce_url(
                add_query_arg(
                    array(
                        'action'   => 'nt_translate',
                        'post_id'  => $post->ID,
                        'language' => $code,
                    ),
                    admin_url( 'admin-post.php' )
                ),
                'nt_translate_' . $post->ID
            );
            ?>
            <tr>
                <td><?php echo esc_html( NT_FLAGS[ $code ] . ' ' . $name ); ?></td>
                <td>
                    <?php if ( $translated ) : ?>
                        <span class="nt-mb-status-ok">✔</span>
                        <small><?php
                            $translated_at = isset( $rows[ $code ]['translated_at'] ) ? $rows[ $code ]['translated_at'] : get_post_meta( $post->ID, '_nt_translated_' . $code, true );
                            echo esc_html( $translated_at ? human_time_diff( strtotime( $translated_at ), time() ) . ' ago' : 'available' );
                        ?></small>
                    <?php else : ?>
                        <span class="nt-mb-status-no"><?php esc_html_e( 'Not translated', NT_TEXTDOMAIN ); ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <a class="button button-small" href="<?php echo esc_url( $action_url ); ?>">
                        <?php echo $translated ? esc_html__( 'Re-translate', NT_TEXTDOMAIN ) : esc_html__( 'Translate', NT_TEXTDOMAIN ); ?>
                    </a>
                </td>
                <?php if ( $translated ) : ?>
                    <td>
                        <a href="<?php echo esc_url( nt_get_translated_url( $post->ID, $code ) ); ?>" target="_blank" class="button button-small" rel="noopener noreferrer">
                            <?php esc_html_e( 'View', NT_TEXTDOMAIN ); ?>
                        </a>
                    </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </table>

        <?php
        $translate_all_url = wp_nonce_url(
            add_query_arg(
                array(
                    'action'   => 'nt_translate',
                    'post_id'  => $post->ID,
                    'language' => 'all',
                ),
                admin_url( 'admin-post.php' )
            ),
            'nt_translate_' . $post->ID
        );
        ?>
        <p style="margin-top:10px;">
            <a class="button button-primary" style="width:100%; text-align:center;" href="<?php echo esc_url( $translate_all_url ); ?>">
                <?php esc_html_e( '⚡ Translate All Languages', NT_TEXTDOMAIN ); ?>
            </a>
        </p>
        <?php
    }

    public function handle() {
        $post_id  = isset( $_REQUEST['post_id'] ) ? absint( wp_unslash( $_REQUEST['post_id'] ) ) : 0;
        $language = isset( $_REQUEST['language'] ) ? sanitize_key( wp_unslash( $_REQUEST['language'] ) ) : '';

        if ( ! $post_id || ! check_admin_referer( 'nt_translate_' . $post_id ) ) {
            wp_die( esc_html__( 'Security check failed.', NT_TEXTDOMAIN ) );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', NT_TEXTDOMAIN ) );
        }

        $translator = new NT_Post_Translator();
        $langs      = ( 'all' === $language ) ? array_keys( NT_LANGS ) : array( $language );
        $errors     = array();

        foreach ( $langs as $lang ) {
            $result = $translator->refresh( $post_id, $lang );
            if ( is_wp_error( $result ) ) {
                $errors[] = $lang . ': ' . $result->get_error_message();
            } else {
                update_post_meta( $post_id, '_nt_translated_' . sanitize_key( $lang ), current_time( 'mysql' ) );
            }
        }

        clean_post_cache( $post_id );
        if ( function_exists( 'wp_cache_flush' ) ) {
            wp_cache_flush();
        }

        if ( $errors ) {
            set_transient(
                $this->notice_key(),
                array(
                    'type'    => 'error',
                    'message' => implode( ' | ', $errors ),
                ),
                MINUTE_IN_SECONDS
            );
        } else {
            set_transient(
                $this->notice_key(),
                array(
                    'type'    => 'success',
                    'message' => __( 'Translation complete! /es/, /fr/, and /pt/ pages are live.', NT_TEXTDOMAIN ),
                ),
                MINUTE_IN_SECONDS
            );
        }

        $redirect = get_edit_post_link( $post_id, 'url' );
        wp_safe_redirect( $redirect );
        exit;
    }

    public function success_notice() {
        $notice = get_transient( $this->notice_key() );
        if ( empty( $notice ) || empty( $notice['type'] ) ) {
            return;
        }

        delete_transient( $this->notice_key() );

        $class = ( 'error' === $notice['type'] ) ? 'notice notice-error' : 'notice notice-success is-dismissible';
        $message = isset( $notice['message'] ) ? (string) $notice['message'] : '';
        echo '<div class="' . esc_attr( $class ) . '"><p><strong>News Translator:</strong> ' . esc_html( $message ) . '</p></div>';
    }
}
