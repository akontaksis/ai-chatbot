<?php
defined( 'ABSPATH' ) || exit;

// ── Create the logs table (called on activation and on DB version bump) ────────
function cacb_create_logs_table(): void {
    global $wpdb;
    $table           = $wpdb->prefix . 'cacb_logs';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id         bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
        provider   varchar(20)         NOT NULL DEFAULT '',
        model      varchar(100)        NOT NULL DEFAULT '',
        user_msg   text                NOT NULL,
        bot_reply  text                NOT NULL,
        ip_hash    varchar(64)         NOT NULL DEFAULT '',
        PRIMARY KEY  (id),
        KEY idx_created (created_at)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

// ── Auto-create table when DB version changes (handles plugin upgrades) ────────
add_action( 'admin_init', 'cacb_maybe_upgrade_db' );
function cacb_maybe_upgrade_db(): void {
    if ( get_option( 'cacb_db_version' ) !== CACB_VERSION ) {
        cacb_create_logs_table();
        cacb_create_embeddings_table();
        update_option( 'cacb_db_version', CACB_VERSION );
    }
}

// ── Write a log entry ─────────────────────────────────────────────────────────
function cacb_log_exchange( string $provider, string $model, string $user_msg, string $bot_reply ): void {
    if ( get_option( 'cacb_logging_enabled', '1' ) !== '1' ) return;

    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . 'cacb_logs',
        [
            'provider'  => $provider,
            'model'     => $model,
            'user_msg'  => $user_msg,
            'bot_reply' => $bot_reply,
            'ip_hash'   => cacb_log_ip_hash(),
        ],
        [ '%s', '%s', '%s', '%s', '%s' ]
    );

    // Prune on ~10% of writes to avoid overhead every request
    if ( wp_rand( 1, 10 ) === 1 ) {
        cacb_prune_logs();
    }
}

function cacb_log_ip_hash(): string {
    $headers = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ];
    foreach ( $headers as $h ) {
        if ( ! empty( $_SERVER[ $h ] ) ) {
            $ip = trim( explode( ',', (string) $_SERVER[ $h ] )[0] );
            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                return hash( 'sha256', $ip );
            }
        }
    }
    return hash( 'sha256', '0.0.0.0' );
}

function cacb_prune_logs(): void {
    $days = max( 1, (int) get_option( 'cacb_log_retention', 30 ) );
    global $wpdb;
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}cacb_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
        $days
    ) );
}

// ── AJAX: JS sends the full exchange after streaming completes ─────────────────
add_action( 'wp_ajax_nopriv_cacb_log', 'cacb_ajax_log_exchange' );
add_action( 'wp_ajax_cacb_log',        'cacb_ajax_log_exchange' );
function cacb_ajax_log_exchange(): void {
    $nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
    if ( ! wp_verify_nonce( $nonce, 'cacb_chat_nonce' ) ) wp_die();

    $user_msg  = sanitize_textarea_field( wp_unslash( $_POST['user_msg']  ?? '' ) );
    $bot_reply = sanitize_textarea_field( wp_unslash( $_POST['bot_reply'] ?? '' ) );

    if ( ! empty( $user_msg ) && ! empty( $bot_reply ) ) {
        $provider = sanitize_text_field( get_option( 'cacb_provider', 'openai' ) );
        switch ( $provider ) {
            case 'claude': $model = sanitize_text_field( get_option( 'cacb_claude_model', 'claude-sonnet-4-6' ) ); break;
            case 'gemini': $model = sanitize_text_field( get_option( 'cacb_gemini_model', 'gemini-2.0-flash' ) );  break;
            default:       $model = sanitize_text_field( get_option( 'cacb_model', 'gpt-4o-mini' ) );              break;
        }
        cacb_log_exchange( $provider, $model, $user_msg, $bot_reply );
    }

    wp_send_json_success();
}

// ── AJAX: wipe a stored API key ───────────────────────────────────────────────
add_action( 'wp_ajax_cacb_delete_key', 'cacb_ajax_delete_key' );
function cacb_ajax_delete_key(): void {
    check_ajax_referer( 'cacb_admin_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die();

    $option  = sanitize_text_field( wp_unslash( $_POST['option'] ?? '' ) );
    $allowed = [ 'cacb_api_key', 'cacb_claude_api_key', 'cacb_gemini_api_key', 'cacb_rag_openai_key' ];
    if ( ! in_array( $option, $allowed, true ) ) {
        wp_send_json_error( esc_html__( 'Μη έγκυρο πεδίο.', 'capitano-chatbot' ) );
        return;
    }

    // delete_option bypasses sanitize_callback (unlike update_option which
    // triggers cacb_sanitize_option and returns the existing value for empty input)
    delete_option( $option );
    wp_send_json_success();
}

// ── AJAX: verify an API key with a live call ──────────────────────────────────
add_action( 'wp_ajax_cacb_test_key', 'cacb_ajax_test_key' );
function cacb_ajax_test_key(): void {
    check_ajax_referer( 'cacb_admin_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die();

    $provider = sanitize_text_field( wp_unslash( $_POST['provider'] ?? '' ) );
    $result   = cacb_do_key_test( $provider );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    } else {
        wp_send_json_success( $result );
    }
}

/**
 * Makes a minimal API call to each provider to validate the stored key.
 *
 * @return string|WP_Error  Success: human-readable "Provider / model" string. Error: WP_Error.
 */
function cacb_do_key_test( string $provider ) {
    switch ( $provider ) {

        case 'openai':
            $key   = cacb_decrypt_key( get_option( 'cacb_api_key', '' ) );
            $model = sanitize_text_field( get_option( 'cacb_model', 'gpt-4o-mini' ) );
            if ( empty( $key ) ) {
                return new WP_Error( 'no_key', __( 'Δεν υπάρχει αποθηκευμένο API key.', 'capitano-chatbot' ) );
            }
            $res = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $key,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => wp_json_encode( [
                    'model'      => $model,
                    'messages'   => [ [ 'role' => 'user', 'content' => 'Hi' ] ],
                    'max_tokens' => 5,
                ] ),
                'timeout' => 20,
            ] );
            if ( is_wp_error( $res ) ) return $res;
            if ( 200 === (int) wp_remote_retrieve_response_code( $res ) ) return "OpenAI / {$model}";
            $body = json_decode( wp_remote_retrieve_body( $res ), true );
            return new WP_Error( 'api', $body['error']['message'] ?? ( 'HTTP ' . wp_remote_retrieve_response_code( $res ) ) );

        case 'claude':
            $key   = cacb_decrypt_key( get_option( 'cacb_claude_api_key', '' ) );
            $model = sanitize_text_field( get_option( 'cacb_claude_model', 'claude-sonnet-4-6' ) );
            if ( empty( $key ) ) {
                return new WP_Error( 'no_key', __( 'Δεν υπάρχει αποθηκευμένο API key.', 'capitano-chatbot' ) );
            }
            $res = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
                'headers' => [
                    'x-api-key'         => $key,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type'      => 'application/json',
                ],
                'body'    => wp_json_encode( [
                    'model'      => $model,
                    'messages'   => [ [ 'role' => 'user', 'content' => 'Hi' ] ],
                    'max_tokens' => 5,
                ] ),
                'timeout' => 20,
            ] );
            if ( is_wp_error( $res ) ) return $res;
            if ( 200 === (int) wp_remote_retrieve_response_code( $res ) ) return "Claude / {$model}";
            $body = json_decode( wp_remote_retrieve_body( $res ), true );
            return new WP_Error( 'api', $body['error']['message'] ?? ( 'HTTP ' . wp_remote_retrieve_response_code( $res ) ) );

        case 'gemini':
            $key   = cacb_decrypt_key( get_option( 'cacb_gemini_api_key', '' ) );
            $model = sanitize_text_field( get_option( 'cacb_gemini_model', 'gemini-2.0-flash' ) );
            if ( empty( $key ) ) {
                return new WP_Error( 'no_key', __( 'Δεν υπάρχει αποθηκευμένο API key.', 'capitano-chatbot' ) );
            }
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
                . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $key );
            $res = wp_remote_post( $url, [
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [
                    'contents'         => [ [ 'role' => 'user', 'parts' => [ [ 'text' => 'Hi' ] ] ] ],
                    'generationConfig' => [ 'maxOutputTokens' => 5 ],
                ] ),
                'timeout' => 20,
            ] );
            if ( is_wp_error( $res ) ) return $res;
            if ( 200 === (int) wp_remote_retrieve_response_code( $res ) ) return "Gemini / {$model}";
            $body = json_decode( wp_remote_retrieve_body( $res ), true );
            return new WP_Error( 'api', $body['error']['message'] ?? ( 'HTTP ' . wp_remote_retrieve_response_code( $res ) ) );

        default:
            return new WP_Error( 'invalid', __( 'Άγνωστος provider.', 'capitano-chatbot' ) );
    }
}

// ── AJAX: erase all log records ───────────────────────────────────────────────
add_action( 'wp_ajax_cacb_clear_logs', 'cacb_ajax_clear_logs' );
function cacb_ajax_clear_logs(): void {
    check_ajax_referer( 'cacb_admin_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die();
    global $wpdb;
    $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}cacb_logs" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    wp_send_json_success();
}

// ── Admin: log viewer ─────────────────────────────────────────────────────────
function cacb_render_logs_page(): void {
    global $wpdb;
    $table  = $wpdb->prefix . 'cacb_logs';
    $per_pg = 20;
    $page   = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
    $offset = ( $page - 1 ) * $per_pg;

    $pf    = sanitize_text_field( $_GET['cacb_provider'] ?? '' );
    $where = $pf ? $wpdb->prepare( 'WHERE provider = %s', $pf ) : '';

    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );
    $pages = (int) ceil( $total / $per_pg );
    $logs  = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
        $per_pg, $offset
    ) );
    // phpcs:enable

    $nonce = wp_create_nonce( 'cacb_admin_nonce' );
    ?>

    <div class="cacb-logs-wrap">

        <div class="cacb-logs-toolbar">
            <span>
                <strong><?php echo number_format_i18n( $total ); ?></strong>
                <?php esc_html_e( 'εγγραφές', 'capitano-chatbot' ); ?>
                &nbsp;·&nbsp;
                <?php esc_html_e( 'Retention', 'capitano-chatbot' ); ?>:
                <strong><?php echo (int) get_option( 'cacb_log_retention', 30 ); ?></strong>
                <?php esc_html_e( 'ημέρες', 'capitano-chatbot' ); ?>
            </span>
            <span class="cacb-logs-actions">
                <form method="get" style="display:inline-flex;gap:6px;align-items:center">
                    <input type="hidden" name="page" value="capitano-chatbot">
                    <input type="hidden" name="tab"  value="logs">
                    <select name="cacb_provider" onchange="this.form.submit()">
                        <option value=""><?php esc_html_e( '— Όλοι οι Providers —', 'capitano-chatbot' ); ?></option>
                        <?php foreach ( [ 'openai', 'claude', 'gemini' ] as $p ) : ?>
                            <option value="<?php echo esc_attr( $p ); ?>" <?php selected( $pf, $p ); ?>>
                                <?php echo esc_html( ucfirst( $p ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <button type="button" class="button button-secondary" id="cacb-clear-logs"
                        data-nonce="<?php echo esc_attr( $nonce ); ?>">
                    🗑 <?php esc_html_e( 'Διαγραφή όλων', 'capitano-chatbot' ); ?>
                </button>
            </span>
        </div>

        <?php if ( empty( $logs ) ) : ?>
            <div class="cacb-notice cacb-notice--info" style="margin-top:12px">
                ℹ️ <?php esc_html_e( 'Δεν υπάρχουν εγγραφές ακόμα. Οι συνομιλίες καταγράφονται αυτόματα μετά από κάθε απάντηση.', 'capitano-chatbot' ); ?>
            </div>
        <?php else : ?>

        <table class="widefat striped cacb-log-table">
            <thead>
                <tr>
                    <th style="width:110px"><?php esc_html_e( 'Ημερομηνία', 'capitano-chatbot' ); ?></th>
                    <th style="width:130px"><?php esc_html_e( 'Provider / Model', 'capitano-chatbot' ); ?></th>
                    <th><?php esc_html_e( 'Ερώτηση χρήστη', 'capitano-chatbot' ); ?></th>
                    <th><?php esc_html_e( 'Απάντηση AI', 'capitano-chatbot' ); ?></th>
                    <th style="width:90px"><?php esc_html_e( 'IP hash', 'capitano-chatbot' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $logs as $log ) :
                    $u_short = mb_substr( $log->user_msg,  0, 120 );
                    $b_short = mb_substr( $log->bot_reply, 0, 120 );
                    $u_long  = mb_strlen( $log->user_msg )  > 120;
                    $b_long  = mb_strlen( $log->bot_reply ) > 120;
                ?>
                <tr>
                    <td style="vertical-align:top;font-size:12px;white-space:nowrap">
                        <?php echo esc_html( wp_date( 'd/m/y', strtotime( $log->created_at ) ) ); ?><br>
                        <span style="color:#888"><?php echo esc_html( wp_date( 'H:i', strtotime( $log->created_at ) ) ); ?></span>
                    </td>
                    <td style="vertical-align:top">
                        <span class="cacb-badge cacb-badge-<?php echo esc_attr( $log->provider ); ?>">
                            <?php echo esc_html( $log->provider ); ?>
                        </span>
                        <div style="font-size:11px;color:#666;margin-top:4px;word-break:break-all">
                            <?php echo esc_html( $log->model ); ?>
                        </div>
                    </td>
                    <td style="vertical-align:top;word-break:break-word;font-size:13px">
                        <span class="cacb-cell-text"><?php echo esc_html( $u_short ); ?><?php echo $u_long ? '…' : ''; ?></span>
                        <?php if ( $u_long ) : ?>
                            <a href="#" class="cacb-expand" data-full="<?php echo esc_attr( $log->user_msg ); ?>">
                                <?php esc_html_e( '[περισσότερα]', 'capitano-chatbot' ); ?>
                            </a>
                        <?php endif; ?>
                    </td>
                    <td style="vertical-align:top;word-break:break-word;font-size:13px">
                        <span class="cacb-cell-text"><?php echo esc_html( $b_short ); ?><?php echo $b_long ? '…' : ''; ?></span>
                        <?php if ( $b_long ) : ?>
                            <a href="#" class="cacb-expand" data-full="<?php echo esc_attr( $log->bot_reply ); ?>">
                                <?php esc_html_e( '[περισσότερα]', 'capitano-chatbot' ); ?>
                            </a>
                        <?php endif; ?>
                    </td>
                    <td style="vertical-align:top;font-size:11px;color:#aaa;word-break:break-all">
                        <?php echo esc_html( substr( $log->ip_hash, 0, 10 ) ); ?>…
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ( $pages > 1 ) :
            $base = add_query_arg( [
                'page'          => 'capitano-chatbot',
                'tab'           => 'logs',
                'cacb_provider' => $pf,
            ], admin_url( 'options-general.php' ) );
            echo '<div style="margin-top:12px">';
            echo paginate_links( [
                'base'      => $base . '&paged=%#%',
                'format'    => '',
                'current'   => $page,
                'total'     => $pages,
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            ] );
            echo '</div>';
        endif; ?>

        <?php endif; // empty($logs) ?>

    </div><!-- .cacb-logs-wrap -->

    <style>
        .cacb-logs-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 0;
        }
        .cacb-logs-actions { display: flex; gap: 8px; align-items: center; }
        .cacb-log-table { margin-top: 0; }
        .cacb-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .4px;
        }
        .cacb-badge-openai { background: #e8f5e9; color: #1b5e20; }
        .cacb-badge-claude { background: #f3e5f5; color: #4a148c; }
        .cacb-badge-gemini { background: #e3f2fd; color: #0d47a1; }
        .cacb-expand { font-size: 12px; margin-left: 4px; }
    </style>
    <script>
    ( function () {
        // Expand truncated cells
        document.querySelectorAll( '.cacb-expand' ).forEach( function ( a ) {
            a.addEventListener( 'click', function ( e ) {
                e.preventDefault();
                this.previousElementSibling.textContent = this.dataset.full;
                this.remove();
            } );
        } );

        // Clear all logs
        var clearBtn = document.getElementById( 'cacb-clear-logs' );
        if ( clearBtn ) {
            clearBtn.addEventListener( 'click', function () {
                if ( ! confirm( '<?php echo esc_js( __( 'Διαγραφή ΟΛΩΝ των logs; Δεν αναιρείται.', 'capitano-chatbot' ) ); ?>' ) ) return;
                clearBtn.disabled = true;
                jQuery.post( ajaxurl, { action: 'cacb_clear_logs', nonce: clearBtn.dataset.nonce }, function ( res ) {
                    if ( res.success ) { location.reload(); }
                    else { clearBtn.disabled = false; alert( 'Error' ); }
                } );
            } );
        }
    } )();
    </script>
    <?php
}
