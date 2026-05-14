<?php
/**
 * NT_Settings_Page — Settings → News Translator Pro
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NT_Settings_Page {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_init', array( $this, 'settings' ) );
        add_action( 'admin_init', array( $this, 'handle_post_actions' ) );
        add_action( 'admin_notices', array( $this, 'render_admin_notice' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_nt_bulk_translate_batch', array( $this, 'ajax_bulk_translate_batch' ) );
    }

    private function page_slug() {
        return 'news-translator-pro-v2.1.2';
    }

    private function notice_key() {
        return 'nt_settings_notice_' . get_current_user_id();
    }

    private function force_state_key( $languages, $post_types ) {
        $languages  = array_values( array_map( 'sanitize_key', (array) $languages ) );
        $post_types = array_values( array_map( 'sanitize_key', (array) $post_types ) );
        return 'nt_force_retranslate_state_' . get_current_user_id() . '_' . md5( implode( ',', $languages ) . '|' . implode( ',', $post_types ) );
    }

    public function menu() {
        add_options_page(
            __( 'News Translator Pro', NT_TEXTDOMAIN ),
            __( 'News Translator', NT_TEXTDOMAIN ),
            'manage_options',
            $this->page_slug(),
            array( $this, 'render' )
        );
    }

    public function settings() {
        $fields = array(
            'nt_api_url'        => array( 'sanitize_callback' => 'esc_url_raw',        'default' => 'http://libretranslate:5000' ),
            'nt_api_key'        => array( 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
            'nt_auto_translate' => array( 'sanitize_callback' => 'absint',              'default' => 0 ),
        );
        foreach ( $fields as $key => $args ) {
            register_setting( 'nt_settings', $key, $args );
        }

        add_settings_section( 'nt_main', __( 'LibreTranslate API', NT_TEXTDOMAIN ), null, $this->page_slug() );

        add_settings_field(
            'nt_api_url',
            __( 'API URL', NT_TEXTDOMAIN ),
            function() {
                $v = esc_attr( get_option( 'nt_api_url', 'http://libretranslate:5000' ) );
                echo '<input type="url" name="nt_api_url" value="' . esc_attr( $v ) . '" class="regular-text">';
                echo '<p class="description">' . esc_html__( 'Hosted: https://libretranslate.com - Docker internal: http://libretranslate:5000 - LAN server: http://192.168.x.x:5000', NT_TEXTDOMAIN ) . '</p>';
            },
            $this->page_slug(),
            'nt_main'
        );

        add_settings_field(
            'nt_api_key',
            __( 'API Key', NT_TEXTDOMAIN ),
            function() {
                $v = esc_attr( get_option( 'nt_api_key', '' ) );
                echo '<input type="password" name="nt_api_key" value="' . esc_attr( $v ) . '" class="regular-text">';
                echo '<p class="description">' . esc_html__( 'Leave blank for self-hosted instances without authentication.', NT_TEXTDOMAIN ) . '</p>';
            },
            $this->page_slug(),
            'nt_main'
        );

        add_settings_field(
            'nt_auto_translate',
            __( 'Auto-Translate', NT_TEXTDOMAIN ),
            function() {
                $checked = checked( 1, get_option( 'nt_auto_translate', 0 ), false );
                echo '<label><input type="checkbox" name="nt_auto_translate" value="1" ' . $checked . '> ';
                echo esc_html__( 'Automatically translate ES + FR + PT when a post is published.', NT_TEXTDOMAIN ) . '</label>';
            },
            $this->page_slug(),
            'nt_main'
        );
    }

    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'News Translator Pro', NT_TEXTDOMAIN ); ?></h1>

            <form method="post" action="options.php">
                <?php settings_fields( 'nt_settings' ); do_settings_sections( $this->page_slug() ); submit_button(); ?>
            </form>

            <hr>
            <h2><?php esc_html_e( 'Connection Test', NT_TEXTDOMAIN ); ?></h2>
            <form method="post">
                <?php wp_nonce_field( 'nt_test' ); ?>
                <input type="hidden" name="nt_do" value="test">
                <?php submit_button( __( 'Test LibreTranslate', NT_TEXTDOMAIN ), 'secondary' ); ?>
            </form>

            <hr>
            <h2><?php esc_html_e( 'Flush Rewrite Rules', NT_TEXTDOMAIN ); ?></h2>
            <p><?php esc_html_e( 'If /es/, /fr/, or /pt/ URLs return 404, click this.', NT_TEXTDOMAIN ); ?></p>
            <form method="post">
                <?php wp_nonce_field( 'nt_flush' ); ?>
                <input type="hidden" name="nt_do" value="flush">
                <?php submit_button( __( 'Flush Rewrite Rules', NT_TEXTDOMAIN ), 'secondary' ); ?>
            </form>

            <hr>
            <h2><?php esc_html_e( 'Batch Translation Tools', NT_TEXTDOMAIN ); ?></h2>
            <p><?php esc_html_e( 'Translate missing content keeps existing translations. Translate missing subtitles only backfills ReviewNews/AFT subtitles without rebuilding full articles. Force retranslate overwrites stored translated titles, content, excerpts, subtitles, and SEO fields from the current English source.', NT_TEXTDOMAIN ); ?></p>
            <div id="nt-bulk-translator" data-nonce="<?php echo esc_attr( wp_create_nonce( 'nt_bulk_translate' ) ); ?>">
                <p>
                    <label for="nt-bulk-language"><strong><?php esc_html_e( 'Language', NT_TEXTDOMAIN ); ?></strong></label><br>
                    <select id="nt-bulk-language">
                        <option value="all"><?php esc_html_e( 'French + Spanish + Portuguese', NT_TEXTDOMAIN ); ?></option>
                        <option value="fr"><?php esc_html_e( 'French only', NT_TEXTDOMAIN ); ?></option>
                        <option value="es"><?php esc_html_e( 'Spanish only', NT_TEXTDOMAIN ); ?></option>
                        <option value="pt"><?php esc_html_e( 'Portuguese only', NT_TEXTDOMAIN ); ?></option>
                    </select>
                </p>
                <p>
                    <label for="nt-bulk-post-type"><strong><?php esc_html_e( 'Content type', NT_TEXTDOMAIN ); ?></strong></label><br>
                    <select id="nt-bulk-post-type">
                        <option value="all"><?php esc_html_e( 'Posts and pages', NT_TEXTDOMAIN ); ?></option>
                        <option value="post"><?php esc_html_e( 'Posts only', NT_TEXTDOMAIN ); ?></option>
                        <option value="page"><?php esc_html_e( 'Pages only', NT_TEXTDOMAIN ); ?></option>
                    </select>
                </p>
                <p>
                    <label for="nt-bulk-batch-size"><strong><?php esc_html_e( 'Batch size', NT_TEXTDOMAIN ); ?></strong></label><br>
                    <select id="nt-bulk-batch-size">
                        <option value="3">3</option>
                        <option value="5" selected>5</option>
                        <option value="10">10</option>
                    </select>
                </p>
                <p>
                    <button id="nt-bulk-run-missing" class="button button-primary" type="button"><?php esc_html_e( 'Translate Missing Content', NT_TEXTDOMAIN ); ?></button>
                    <button id="nt-bulk-run-subtitles" class="button button-secondary" type="button"><?php esc_html_e( 'Translate Missing Subtitles Only', NT_TEXTDOMAIN ); ?></button>
                    <button id="nt-bulk-run-force" class="button button-secondary" type="button"><?php esc_html_e( 'Force Retranslate All Existing Articles', NT_TEXTDOMAIN ); ?></button>
                </p>
                <p class="description"><?php esc_html_e( 'Force retranslate runs in small AJAX batches. Leave this settings page open until it finishes.', NT_TEXTDOMAIN ); ?></p>
                <div id="nt-bulk-status" class="notice inline" style="display:block;"><p><?php esc_html_e( 'Ready.', NT_TEXTDOMAIN ); ?></p></div>
            </div>

            <hr>
            <h2><?php esc_html_e( 'Translated Sitemap', NT_TEXTDOMAIN ); ?></h2>
            <p><?php
            printf(
                /* translators: %s: sitemap URL */
                wp_kses(
                    __( 'Your standalone translated sitemap is at: %s', NT_TEXTDOMAIN ),
                    array()
                ),
                '<a href="' . esc_url( home_url( '/nt-sitemap.xml' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( home_url( '/nt-sitemap.xml' ) ) . '</a>'
            );
            ?></p>
        
            <hr>
            <h2><?php esc_html_e( 'Support Development', NT_TEXTDOMAIN ); ?></h2>
            <p><?php esc_html_e( 'If this plugin helps your multilingual publishing workflow, support continued development here:', NT_TEXTDOMAIN ); ?></p>
            <p><a href="https://www.paypal.com/donate/?hosted_button_id=G69AUBDK36GD8" target="_blank" rel="noopener noreferrer" class="button button-secondary"><?php esc_html_e( 'Support via PayPal', NT_TEXTDOMAIN ); ?></a></p>

</div>
        <?php
    }

    public function handle_post_actions() {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
        if ( $this->page_slug() !== $page || 'POST' !== strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
            return;
        }

        $nt_do = isset( $_POST['nt_do'] ) ? sanitize_key( wp_unslash( $_POST['nt_do'] ) ) : '';
        if ( 'test' === $nt_do ) {
            check_admin_referer( 'nt_test' );
            $result = ( new NT_LibreTranslate_API() )->test();
            $this->set_notice(
                is_wp_error( $result ) ? 'error' : 'success',
                is_wp_error( $result ) ? $result->get_error_message() : __( 'Connection successful! LibreTranslate is working.', NT_TEXTDOMAIN )
            );
        } elseif ( 'flush' === $nt_do ) {
            check_admin_referer( 'nt_flush' );
            NT_Rewrite::flush();
            $this->set_notice( 'success', __( 'Rewrite rules flushed. /es/, /fr/, /pt/, and /nt-sitemap.xml should now work.', NT_TEXTDOMAIN ) );
        }
    }

    private function set_notice( $type, $message ) {
        set_transient(
            $this->notice_key(),
            array(
                'type'    => sanitize_key( $type ),
                'message' => (string) $message,
            ),
            MINUTE_IN_SECONDS
        );
    }

    public function render_admin_notice() {
        $notice = get_transient( $this->notice_key() );
        if ( empty( $notice ) || empty( $notice['message'] ) ) {
            return;
        }

        delete_transient( $this->notice_key() );

        $class = ( isset( $notice['type'] ) && 'error' === $notice['type'] ) ? 'notice notice-error' : 'notice notice-success';
        echo '<div class="' . esc_attr( $class ) . '"><p><strong>News Translator:</strong> ' . esc_html( (string) $notice['message'] ) . '</p></div>';
    }

    public function enqueue_assets( $hook ) {
        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
        if ( $this->page_slug() !== $page ) {
            return;
        }

        wp_enqueue_script( 'jquery' );
        $script = <<<'JS'
jQuery(function($){
  var $wrap = $('#nt-bulk-translator');
  if (!$wrap.length) { return; }

  var running = false;

  function updateStatus(msg, cls){
    var $status = $('#nt-bulk-status');
    $status.removeClass('notice-success notice-error notice-warning').addClass(cls || '').html('<p>' + msg + '</p>');
  }

  function setButtons(disabled){
    $('#nt-bulk-run-missing, #nt-bulk-run-subtitles, #nt-bulk-run-force').prop('disabled', !!disabled);
  }

  function runBatch(mode, lang, batch, postType, reset){
    $.post(ajaxurl, {
      action: 'nt_bulk_translate_batch',
      nonce: $wrap.data('nonce'),
      language: lang,
      batch_size: batch,
      post_type: postType,
      mode: mode,
      reset: reset ? 1 : 0
    }, function(resp){
      if (!resp || !resp.success) {
        running = false;
        var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Bulk translation failed.';
        if (resp && resp.data && resp.data.errors && resp.data.errors.length) {
          msg += '<br><small>' + resp.data.errors.join('<br>') + '</small>';
        }
        updateStatus(msg, 'notice notice-error');
        setButtons(false);
        return;
      }

      var d = resp.data || {};
      var actionLabel = (mode === 'force') ? 'Retranslated ' : ((mode === 'subtitles') ? 'Subtitle backfilled ' : 'Processed ');
      var message = actionLabel + (d.processed || 0) + ' item(s). Remaining: ' + (d.remaining || 0) + '.';
      if (d.current_language_label) {
        message = d.current_language_label + ': ' + message;
      }
      if (d.errors && d.errors.length) {
        message += '<br><small>' + d.errors.join('<br>') + '</small>';
      }
      updateStatus(message, 'notice notice-warning');

      if (d.remaining > 0 || (d.pending_languages && d.pending_languages.length)) {
        runBatch(mode, lang, batch, postType, false);
        return;
      }

      running = false;
      if (mode === 'force') {
        updateStatus('Force retranslation complete. Existing stored translations were rebuilt from the current English source.', 'notice notice-success');
      } else if (mode === 'subtitles') {
        updateStatus('Subtitle backfill complete. Missing stored subtitles have been translated without rebuilding full articles.', 'notice notice-success');
      } else {
        updateStatus('Bulk translation complete. Missing translated content has been backfilled.', 'notice notice-success');
      }
      setButtons(false);
    });
  }

  $('#nt-bulk-run-missing').on('click', function(e){
    e.preventDefault();
    if (running) { return; }
    running = true;
    setButtons(true);
    updateStatus('Starting missing-content translation. Leave this page open until it finishes.', 'notice notice-warning');
    runBatch('missing', $('#nt-bulk-language').val(), $('#nt-bulk-batch-size').val(), $('#nt-bulk-post-type').val(), true);
  });


  $('#nt-bulk-run-subtitles').on('click', function(e){
    e.preventDefault();
    if (running) { return; }
    running = true;
    setButtons(true);
    updateStatus('Starting subtitle-only backfill. This translates only missing subtitles, not full articles.', 'notice notice-warning');
    runBatch('subtitles', $('#nt-bulk-language').val(), $('#nt-bulk-batch-size').val(), $('#nt-bulk-post-type').val(), true);
  });

  $('#nt-bulk-run-force').on('click', function(e){
    e.preventDefault();
    if (running) { return; }
    var ok = window.confirm('This will overwrite existing translated titles, content, excerpts, subtitles, and SEO fields using the current English source. Continue?');
    if (!ok) { return; }
    running = true;
    setButtons(true);
    updateStatus('Starting force retranslation. Leave this page open until it finishes.', 'notice notice-warning');
    runBatch('force', $('#nt-bulk-language').val(), $('#nt-bulk-batch-size').val(), $('#nt-bulk-post-type').val(), true);
  });
});
JS;
        wp_add_inline_script( 'jquery', $script );
    }

    public function ajax_bulk_translate_batch() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Insufficient permissions.', NT_TEXTDOMAIN ) ), 403 );
        }

        check_ajax_referer( 'nt_bulk_translate', 'nonce' );

        $language   = isset( $_POST['language'] ) ? sanitize_key( wp_unslash( $_POST['language'] ) ) : 'all';
        $batch_size = isset( $_POST['batch_size'] ) ? max( 1, min( 20, absint( wp_unslash( $_POST['batch_size'] ) ) ) ) : 5;
        $post_type  = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : 'all';
        $mode       = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'missing';
        $reset      = ! empty( $_POST['reset'] );

        $post_types = ( 'post' === $post_type ) ? array( 'post' ) : ( ( 'page' === $post_type ) ? array( 'page' ) : array( 'post', 'page' ) );
        $languages  = ( 'all' === $language ) ? array_keys( NT_LANGS ) : array( $language );
        $languages  = array_values( array_filter( $languages, function( $lang ) { return ! empty( NT_LANGS[ $lang ] ); } ) );

        if ( empty( $languages ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'No valid target language selected.', NT_TEXTDOMAIN ) ), 400 );
        }

        if ( 'force' === $mode ) {
            $this->ajax_force_retranslate_batch( $languages, $post_types, $batch_size, $reset );
            return;
        }

        if ( 'subtitles' === $mode ) {
            $this->ajax_subtitle_backfill_batch( $languages, $post_types, $batch_size );
            return;
        }

        $translator       = new NT_Post_Translator();
        $processed        = 0;
        $errors           = array();
        $remaining        = 0;
        $current_language = '';

        foreach ( $languages as $lang ) {
            $missing = NT_DB::get_missing_post_ids( $lang, $post_types, $batch_size );
            if ( empty( $missing ) ) {
                $remaining = 0;
                continue;
            }

            $current_language = $lang;
            foreach ( $missing as $post_id ) {
                $result = $translator->get_or_create( $post_id, $lang );
                if ( is_wp_error( $result ) ) {
                    $errors[] = sprintf( '%s #%d: %s', $lang, $post_id, $result->get_error_message() );
                    continue;
                }
                $processed++;
            }

            $remaining = NT_DB::count_missing_posts( $lang, $post_types );

            if ( 0 === $processed && ! empty( $errors ) ) {
                wp_send_json_error(
                    array(
                        'message'          => esc_html__( 'Bulk translation stopped because the same item is failing repeatedly.', NT_TEXTDOMAIN ),
                        'errors'           => $errors,
                        'current_language' => $lang,
                        'remaining'        => $remaining,
                    ),
                    500
                );
            }
            break;
        }

        $pending_languages = array();
        foreach ( $languages as $lang ) {
            if ( NT_DB::count_missing_posts( $lang, $post_types ) > 0 ) {
                $pending_languages[] = $lang;
            }
        }

        wp_send_json_success(
            array(
                'processed'              => $processed,
                'remaining'              => $remaining,
                'current_language'       => $current_language,
                'current_language_label' => $current_language && isset( NT_LANGS[ $current_language ] ) ? NT_LANGS[ $current_language ] : '',
                'pending_languages'      => $pending_languages,
                'errors'                 => $errors,
            )
        );
    }


    private function ajax_subtitle_backfill_batch( $languages, $post_types, $batch_size ) {
        $api              = new NT_LibreTranslate_API();
        $processed        = 0;
        $errors           = array();
        $remaining        = 0;
        $current_language = '';

        foreach ( $languages as $lang ) {
            $rows = NT_DB::get_missing_subtitle_rows( $lang, $post_types, $batch_size );
            if ( empty( $rows ) ) {
                $remaining = 0;
                continue;
            }

            $current_language = $lang;

            foreach ( $rows as $row ) {
                $post_id = isset( $row['post_id'] ) ? absint( $row['post_id'] ) : 0;
                if ( ! $post_id ) {
                    continue;
                }

                $source_subtitle = get_post_meta( $post_id, '_aft_subtitle', true );
                if ( ! is_string( $source_subtitle ) || '' === trim( $source_subtitle ) ) {
                    $source_subtitle = get_post_meta( $post_id, 'aft_subtitle', true );
                }

                if ( ! is_string( $source_subtitle ) || '' === trim( $source_subtitle ) ) {
                    continue;
                }

                $translated = $api->translate( $source_subtitle, $lang );
                if ( is_wp_error( $translated ) ) {
                    $errors[] = sprintf( '%s #%d subtitle: %s', $lang, $post_id, $translated->get_error_message() );
                    continue;
                }

                $saved = NT_DB::update_subtitle( $post_id, $lang, $translated );
                if ( is_wp_error( $saved ) ) {
                    $errors[] = sprintf( '%s #%d subtitle: %s', $lang, $post_id, $saved->get_error_message() );
                    continue;
                }

                $processed++;
            }

            $remaining = NT_DB::count_missing_subtitle_rows( $lang, $post_types );

            if ( 0 === $processed && ! empty( $errors ) ) {
                wp_send_json_error(
                    array(
                        'message'          => esc_html__( 'Subtitle backfill stopped because the same subtitle is failing repeatedly.', NT_TEXTDOMAIN ),
                        'errors'           => $errors,
                        'current_language' => $lang,
                        'remaining'        => $remaining,
                    ),
                    500
                );
            }
            break;
        }

        $pending_languages = array();
        foreach ( $languages as $lang ) {
            if ( NT_DB::count_missing_subtitle_rows( $lang, $post_types ) > 0 ) {
                $pending_languages[] = $lang;
            }
        }

        wp_send_json_success(
            array(
                'processed'              => $processed,
                'remaining'              => $remaining,
                'current_language'       => $current_language,
                'current_language_label' => $current_language && isset( NT_LANGS[ $current_language ] ) ? NT_LANGS[ $current_language ] : '',
                'pending_languages'      => $pending_languages,
                'errors'                 => $errors,
            )
        );
    }

    private function ajax_force_retranslate_batch( $languages, $post_types, $batch_size, $reset ) {
        $state_key = $this->force_state_key( $languages, $post_types );

        if ( $reset ) {
            delete_option( $state_key );
        }

        $state = get_option( $state_key, array() );
        if ( ! is_array( $state ) ) {
            $state = array();
        }
        if ( empty( $state['cursor'] ) || ! is_array( $state['cursor'] ) ) {
            $state['cursor'] = array();
        }
        if ( empty( $state['complete'] ) || ! is_array( $state['complete'] ) ) {
            $state['complete'] = array();
        }

        $translator       = new NT_Post_Translator();
        $processed        = 0;
        $errors           = array();
        $remaining        = 0;
        $current_language = '';

        foreach ( $languages as $lang ) {
            if ( ! empty( $state['complete'][ $lang ] ) ) {
                continue;
            }

            $cursor = isset( $state['cursor'][ $lang ] ) ? absint( $state['cursor'][ $lang ] ) : 0;
            $ids    = NT_DB::get_retranslate_post_ids( $post_types, $batch_size, $cursor );

            if ( empty( $ids ) ) {
                $state['complete'][ $lang ] = 1;
                $state['cursor'][ $lang ]   = 0;
                update_option( $state_key, $state, false );
                continue;
            }

            $current_language = $lang;

            foreach ( $ids as $post_id ) {
                $result = $translator->refresh( $post_id, $lang );
                if ( is_wp_error( $result ) ) {
                    $errors[] = sprintf( '%s #%d: %s', $lang, $post_id, $result->get_error_message() );
                    continue;
                }
                $processed++;
            }

            $state['cursor'][ $lang ] = min( $ids );
            $remaining = NT_DB::count_retranslate_posts( $post_types, $state['cursor'][ $lang ] );

            if ( 0 === $remaining ) {
                $state['complete'][ $lang ] = 1;
            }

            update_option( $state_key, $state, false );

            if ( 0 === $processed && ! empty( $errors ) ) {
                wp_send_json_error(
                    array(
                        'message'          => esc_html__( 'Force retranslation stopped because the same item is failing repeatedly.', NT_TEXTDOMAIN ),
                        'errors'           => $errors,
                        'current_language' => $lang,
                        'remaining'        => $remaining,
                    ),
                    500
                );
            }
            break;
        }

        $pending_languages = array();
        foreach ( $languages as $lang ) {
            if ( empty( $state['complete'][ $lang ] ) ) {
                $pending_languages[] = $lang;
            }
        }

        if ( empty( $pending_languages ) && 0 === $remaining ) {
            delete_option( $state_key );
        }

        wp_send_json_success(
            array(
                'processed'              => $processed,
                'remaining'              => $remaining,
                'current_language'       => $current_language,
                'current_language_label' => $current_language && isset( NT_LANGS[ $current_language ] ) ? NT_LANGS[ $current_language ] : '',
                'pending_languages'      => $pending_languages,
                'errors'                 => $errors,
            )
        );
    }
}
