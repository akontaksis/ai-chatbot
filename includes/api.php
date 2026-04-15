<?php
defined( 'ABSPATH' ) || exit;

// ── WooCommerce product context ───────────────────────────────────────────────
function cacb_get_wc_product_context(): string {

    // Feature disabled or WooCommerce not active
    if ( ! get_option( 'cacb_wc_enabled', '0' ) ) {
        return '';
    }
    if ( ! function_exists( 'wc_get_products' ) ) {
        return '';
    }

    $cache_key = 'cacb_wc_products_cache';

    // Try cache first (invalidated on product save/update)
    $cached = get_transient( $cache_key );
    if ( false !== $cached ) {
        return $cached;
    }

    $limit      = max( 10, min( 200, (int) get_option( 'cacb_wc_limit', 50 ) ) );
    $categories = get_option( 'cacb_wc_categories', '' ); // comma-separated slugs or empty = all

    $args = [
        'limit'   => $limit,
        'status'  => 'publish',
        'orderby' => 'date',
        'order'   => 'DESC',
    ];

    // Filter by category if specified
    if ( ! empty( $categories ) ) {
        $args['category'] = array_map( 'trim', explode( ',', $categories ) );
    }

    $products = wc_get_products( $args );

    if ( empty( $products ) ) {
        return '';
    }

    $lines = [ "\n\n---\nΔΙΑΘΕΣΙΜΑ ΠΡΟΪΟΝΤΑ (live από το κατάστημα):" ];

    foreach ( $products as $product ) {
        $name  = $product->get_name();
        $price = $product->get_price();
        $sku   = $product->get_sku();
        $stock = $product->is_in_stock() ? 'Διαθέσιμο' : 'Μη διαθέσιμο';
        $desc  = wp_strip_all_tags( $product->get_short_description() );
        $desc  = $desc ? ' — ' . wp_trim_words( $desc, 20, '...' ) : '';

        // Get category names
        $cat_ids   = $product->get_category_ids();
        $cat_names = [];
        foreach ( $cat_ids as $cat_id ) {
            $term = get_term( $cat_id, 'product_cat' );
            if ( $term && ! is_wp_error( $term ) ) {
                $cat_names[] = $term->name;
            }
        }
        $cats = $cat_names ? ' [' . implode( ', ', $cat_names ) . ']' : '';

        // Format: Name | SKU | Price | Stock | Category | Description
        $line = sprintf(
            '• %s%s | SKU: %s | %s€ | %s%s',
            $name,
            $cats,
            $sku ?: 'N/A',
            $price ?: 'N/A',
            $stock,
            $desc
        );

        $lines[] = $line;
    }

    $lines[] = '---';
    $context = implode( "\n", $lines );

    // Cache for 1 hour — cleared automatically on product update (see hook below)
    set_transient( $cache_key, $context, HOUR_IN_SECONDS );

    return $context;
}

// ── Invalidate product cache when products are saved/updated ──────────────────
add_action( 'woocommerce_update_product', 'cacb_clear_product_cache' );
add_action( 'woocommerce_new_product',    'cacb_clear_product_cache' );
add_action( 'woocommerce_delete_product', 'cacb_clear_product_cache' );
function cacb_clear_product_cache() {
    delete_transient( 'cacb_wc_products_cache' );
}

// ── Register REST route ───────────────────────────────────────────────────────
add_action( 'rest_api_init', 'cacb_register_routes' );
function cacb_register_routes() {
    register_rest_route( 'cacb/v1', '/chat', [
        'methods'             => WP_REST_Server::CREATABLE, // POST only
        'callback'            => 'cacb_handle_chat',
        'permission_callback' => '__return_true',           // Public endpoint — secured via nonce below
        'args'                => [
            'messages' => [
                'required'          => true,
                'type'              => 'array',
                'sanitize_callback' => 'cacb_sanitize_messages',
            ],
            'nonce' => [
                'required' => true,
                'type'     => 'string',
            ],
        ],
    ] );
}

// ── Sanitize incoming messages array ─────────────────────────────────────────
function cacb_sanitize_messages( $messages ) {
    if ( ! is_array( $messages ) ) {
        return [];
    }
    $clean = [];
    foreach ( $messages as $msg ) {
        if ( ! isset( $msg['role'], $msg['content'] ) ) {
            continue;
        }
        $role = sanitize_text_field( $msg['role'] );
        if ( ! in_array( $role, [ 'user', 'assistant' ], true ) ) {
            continue; // Only allow user/assistant from client
        }
        $clean[] = [
            'role'    => $role,
            'content' => sanitize_textarea_field( $msg['content'] ),
        ];
    }
    return $clean;
}

// ── Rate limiting via transients ──────────────────────────────────────────────
function cacb_check_rate_limit(): bool {
    $limit = (int) get_option( 'cacb_rate_limit', 20 );
    $ip    = cacb_get_client_ip();
    $key   = 'cacb_rl_' . hash( 'sha256', $ip );
    $count = (int) get_transient( $key );

    if ( $count >= $limit ) {
        return false; // limit exceeded
    }

    set_transient( $key, $count + 1, HOUR_IN_SECONDS );
    return true;
}

function cacb_get_client_ip(): string {
    $headers = [
        'HTTP_CF_CONNECTING_IP',   // Cloudflare
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR',
    ];
    foreach ( $headers as $header ) {
        if ( ! empty( $_SERVER[ $header ] ) ) {
            $ip = trim( explode( ',', $_SERVER[ $header ] )[0] );
            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

// ── Main chat handler ─────────────────────────────────────────────────────────
function cacb_handle_chat( WP_REST_Request $request ) {

    // 1. Verify nonce (CSRF protection)
    $nonce = sanitize_text_field( $request->get_param( 'nonce' ) );
    if ( ! wp_verify_nonce( $nonce, 'cacb_chat_nonce' ) ) {
        return new WP_Error( 'invalid_nonce', __( 'Security check failed.', 'smart-ai-chatbot' ), [ 'status' => 403 ] );
    }

    // 2. Rate limit check
    if ( ! cacb_check_rate_limit() ) {
        return new WP_Error(
            'rate_limit',
            __( 'Έχετε φτάσει το όριο μηνυμάτων. Παρακαλώ δοκιμάστε αργότερα.', 'smart-ai-chatbot' ),
            [ 'status' => 429 ]
        );
    }

    // 3. Get provider and resolve API key + model
    $provider = sanitize_text_field( get_option( 'cacb_provider', 'openai' ) );

    switch ( $provider ) {
        case 'claude':
            $api_key = defined( 'CACB_CLAUDE_API_KEY' )
                ? CACB_CLAUDE_API_KEY
                : cacb_decrypt_key( get_option( 'cacb_claude_api_key', '' ) );
            $model = sanitize_text_field( get_option( 'cacb_claude_model', 'claude-sonnet-4-6' ) );
            break;
        case 'gemini':
            $api_key = defined( 'CACB_GEMINI_API_KEY' )
                ? CACB_GEMINI_API_KEY
                : cacb_decrypt_key( get_option( 'cacb_gemini_api_key', '' ) );
            $model = sanitize_text_field( get_option( 'cacb_gemini_model', 'gemini-2.0-flash' ) );
            break;
        default:
            $provider = 'openai';
            $api_key  = defined( 'CACB_OPENAI_API_KEY' )
                ? CACB_OPENAI_API_KEY
                : cacb_decrypt_key( get_option( 'cacb_api_key', '' ) );
            $model = sanitize_text_field( get_option( 'cacb_model', 'gpt-4o-mini' ) );
            break;
    }

    if ( empty( $api_key ) ) {
        return new WP_Error( 'no_api_key', __( 'API key not configured.', 'smart-ai-chatbot' ), [ 'status' => 500 ] );
    }

    // 4. Build messages
    $history_limit   = max( 2, (int) get_option( 'cacb_history_limit', 10 ) );
    $client_messages = $request->get_param( 'messages' ); // already sanitized via args

    if ( count( $client_messages ) > $history_limit ) {
        $client_messages = array_slice( $client_messages, - $history_limit );
    }

    $system_prompt = sanitize_textarea_field( get_option( 'cacb_system_prompt', '' ) );
    $system_prompt .= cacb_get_smart_context( $client_messages );
    $max_tokens = min( 2000, max( 100, (int) get_option( 'cacb_max_tokens', 500 ) ) );

    // 5. Call the selected provider
    switch ( $provider ) {
        case 'claude':
            $result = cacb_call_claude( $client_messages, $api_key, $model, $max_tokens, $system_prompt );
            break;
        case 'gemini':
            $result = cacb_call_gemini( $client_messages, $api_key, $model, $max_tokens, $system_prompt );
            break;
        default:
            $messages = array_merge(
                [ [ 'role' => 'system', 'content' => $system_prompt ] ],
                $client_messages
            );
            $result = cacb_call_openai( $messages, $api_key, $model, $max_tokens );
            break;
    }

    if ( is_wp_error( $result ) ) {
        return $result;
    }

    // 6. Log the exchange
    $last_user_msg = '';
    foreach ( array_reverse( $client_messages ) as $msg ) {
        if ( 'user' === $msg['role'] ) { $last_user_msg = $msg['content']; break; }
    }
    cacb_log_exchange( $provider, $model, $last_user_msg, $result );

    // 7. Return sanitized reply
    return rest_ensure_response( [
        'reply' => wp_kses( $result, [
            'br' => [], 'strong' => [], 'em' => [], 'a' => [ 'href' => [], 'target' => [] ],
        ] ),
    ] );
}

// ── Provider: OpenAI ──────────────────────────────────────────────────────────
function cacb_call_openai( array $messages, string $api_key, string $model, int $max_tokens ) {
    $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
        'timeout' => 30,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode( [
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => $max_tokens,
            'temperature' => 0.7,
        ] ),
    ] );

    if ( is_wp_error( $response ) ) {
        error_log( '[CACB] OpenAI connection error: ' . $response->get_error_message() );
        return new WP_Error( 'openai_unreachable', __( 'Δεν ήταν δυνατή η σύνδεση. Παρακαλώ δοκιμάστε αργότερα.', 'smart-ai-chatbot' ), [ 'status' => 502 ] );
    }

    $http_code = wp_remote_retrieve_response_code( $response );
    $body      = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $http_code !== 200 ) {
        $err = $body['error']['message'] ?? 'Unknown OpenAI error';
        error_log( "[CACB] OpenAI API error {$http_code}: {$err}" );
        return new WP_Error( 'openai_error', __( 'Παρουσιάστηκε σφάλμα. Παρακαλώ δοκιμάστε αργότερα.', 'smart-ai-chatbot' ), [ 'status' => 502 ] );
    }

    $reply = $body['choices'][0]['message']['content'] ?? '';
    if ( empty( $reply ) ) {
        return new WP_Error( 'empty_response', __( 'Κενή απάντηση από το AI.', 'smart-ai-chatbot' ), [ 'status' => 502 ] );
    }
    return $reply;
}

// ── Provider: Anthropic Claude ────────────────────────────────────────────────
function cacb_call_claude( array $client_messages, string $api_key, string $model, int $max_tokens, string $system_prompt ) {
    $payload = [
        'model'      => $model,
        'max_tokens' => $max_tokens,
        'messages'   => $client_messages,
    ];
    if ( ! empty( $system_prompt ) ) {
        $payload['system'] = $system_prompt;
    }

    $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
        'timeout' => 30,
        'headers' => [
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
            'Content-Type'      => 'application/json',
        ],
        'body' => wp_json_encode( $payload ),
    ] );

    if ( is_wp_error( $response ) ) {
        error_log( '[CACB] Claude connection error: ' . $response->get_error_message() );
        return new WP_Error( 'claude_unreachable', __( 'Δεν ήταν δυνατή η σύνδεση. Παρακαλώ δοκιμάστε αργότερα.', 'smart-ai-chatbot' ), [ 'status' => 502 ] );
    }

    $http_code = wp_remote_retrieve_response_code( $response );
    $body      = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $http_code !== 200 ) {
        $err = $body['error']['message'] ?? 'Unknown Claude error';
        error_log( "[CACB] Claude API error {$http_code}: {$err}" );
        return new WP_Error( 'claude_error', __( 'Παρουσιάστηκε σφάλμα. Παρακαλώ δοκιμάστε αργότερα.', 'smart-ai-chatbot' ), [ 'status' => 502 ] );
    }

    $reply = $body['content'][0]['text'] ?? '';
    if ( empty( $reply ) ) {
        return new WP_Error( 'empty_response', __( 'Κενή απάντηση από το AI.', 'smart-ai-chatbot' ), [ 'status' => 502 ] );
    }
    return $reply;
}

// ── Provider: Google Gemini ───────────────────────────────────────────────────
function cacb_call_gemini( array $client_messages, string $api_key, string $model, int $max_tokens, string $system_prompt ) {
    // Map roles: Gemini uses "user" and "model" (not "assistant")
    $contents = [];
    foreach ( $client_messages as $msg ) {
        $contents[] = [
            'role'  => $msg['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [ [ 'text' => $msg['content'] ] ],
        ];
    }

    $payload = [
        'contents'         => $contents,
        'generationConfig' => [
            'maxOutputTokens' => $max_tokens,
            'temperature'     => 0.7,
        ],
    ];

    if ( ! empty( $system_prompt ) ) {
        $payload['system_instruction'] = [
            'parts' => [ [ 'text' => $system_prompt ] ],
        ];
    }

    $url = add_query_arg(
        'key',
        $api_key,
        'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent'
    );

    $response = wp_remote_post( $url, [
        'timeout' => 30,
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode( $payload ),
    ] );

    if ( is_wp_error( $response ) ) {
        error_log( '[CACB] Gemini connection error: ' . $response->get_error_message() );
        return new WP_Error( 'gemini_unreachable', __( 'Δεν ήταν δυνατή η σύνδεση. Παρακαλώ δοκιμάστε αργότερα.', 'smart-ai-chatbot' ), [ 'status' => 502 ] );
    }

    $http_code = wp_remote_retrieve_response_code( $response );
    $body      = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $http_code !== 200 ) {
        $err = $body['error']['message'] ?? 'Unknown Gemini error';
        error_log( "[CACB] Gemini API error {$http_code}: {$err}" );
        return new WP_Error( 'gemini_error', __( 'Παρουσιάστηκε σφάλμα. Παρακαλώ δοκιμάστε αργότερα.', 'smart-ai-chatbot' ), [ 'status' => 502 ] );
    }

    $reply = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if ( empty( $reply ) ) {
        return new WP_Error( 'empty_response', __( 'Κενή απάντηση από το AI.', 'smart-ai-chatbot' ), [ 'status' => 502 ] );
    }
    return $reply;
}

// ═══════════════════════════════════════════════════════════════════════════════
// STREAMING (SSE) — admin-ajax endpoint, one chunk at a time via cURL
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_nopriv_cacb_stream', 'cacb_handle_stream' );
add_action( 'wp_ajax_cacb_stream',        'cacb_handle_stream' );

function cacb_handle_stream(): void {

    // 1. SSE headers first — must be sent before ANY output so errors are also SSE-formatted
    while ( ob_get_level() > 0 ) {
        ob_end_clean();
    }
    header( 'Content-Type: text/event-stream; charset=UTF-8' );
    header( 'Cache-Control: no-cache, no-store' );
    header( 'X-Accel-Buffering: no' ); // Disable nginx buffering

    // 2. Nonce
    $nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
    if ( ! wp_verify_nonce( $nonce, 'cacb_chat_nonce' ) ) {
        cacb_sse_error( __( 'Security check failed.', 'smart-ai-chatbot' ) );
        wp_die();
    }

    // 3. Rate limit
    if ( ! cacb_check_rate_limit() ) {
        cacb_sse_error( __( 'Έχετε φτάσει το όριο μηνυμάτων. Παρακαλώ δοκιμάστε αργότερα.', 'smart-ai-chatbot' ) );
        wp_die();
    }

    // 4. Messages
    $raw      = json_decode( wp_unslash( $_POST['messages'] ?? '[]' ), true );
    $messages = cacb_sanitize_messages( is_array( $raw ) ? $raw : [] );
    if ( empty( $messages ) ) {
        cacb_sse_error( __( 'No messages provided.', 'smart-ai-chatbot' ) );
        wp_die();
    }

    // 5. Provider + key + model
    $provider = sanitize_text_field( get_option( 'cacb_provider', 'openai' ) );
    switch ( $provider ) {
        case 'claude':
            $api_key = defined( 'CACB_CLAUDE_API_KEY' )
                ? CACB_CLAUDE_API_KEY
                : cacb_decrypt_key( get_option( 'cacb_claude_api_key', '' ) );
            $model = sanitize_text_field( get_option( 'cacb_claude_model', 'claude-sonnet-4-6' ) );
            break;
        case 'gemini':
            $api_key = defined( 'CACB_GEMINI_API_KEY' )
                ? CACB_GEMINI_API_KEY
                : cacb_decrypt_key( get_option( 'cacb_gemini_api_key', '' ) );
            $model = sanitize_text_field( get_option( 'cacb_gemini_model', 'gemini-2.0-flash' ) );
            break;
        default:
            $provider = 'openai';
            $api_key  = defined( 'CACB_OPENAI_API_KEY' )
                ? CACB_OPENAI_API_KEY
                : cacb_decrypt_key( get_option( 'cacb_api_key', '' ) );
            $model = sanitize_text_field( get_option( 'cacb_model', 'gpt-4o-mini' ) );
    }

    if ( empty( $api_key ) ) {
        cacb_sse_error( __( 'API key not configured.', 'smart-ai-chatbot' ) );
        wp_die();
    }

    // 6. Build context
    $history_limit = max( 2, (int) get_option( 'cacb_history_limit', 10 ) );
    if ( count( $messages ) > $history_limit ) {
        $messages = array_slice( $messages, - $history_limit );
    }
    $system_prompt  = sanitize_textarea_field( get_option( 'cacb_system_prompt', '' ) );
    $system_prompt .= cacb_get_smart_context( $messages );
    $max_tokens     = min( 2000, max( 100, (int) get_option( 'cacb_max_tokens', 500 ) ) );

    // 7. Delegate to provider
    switch ( $provider ) {
        case 'claude':
            cacb_stream_claude( $messages, $api_key, $model, $max_tokens, $system_prompt );
            break;
        case 'gemini':
            cacb_stream_gemini( $messages, $api_key, $model, $max_tokens, $system_prompt );
            break;
        default:
            $with_system = array_merge(
                [ [ 'role' => 'system', 'content' => $system_prompt ] ],
                $messages
            );
            cacb_stream_openai( $with_system, $api_key, $model, $max_tokens );
    }

    wp_die();
}

// ── SSE output helpers ────────────────────────────────────────────────────────
function cacb_sse_chunk( string $text ): void {
    echo 'data: ' . wp_json_encode( [ 't' => $text ] ) . "\n\n";
    if ( ob_get_level() > 0 ) ob_flush();
    flush();
}

function cacb_sse_done(): void {
    echo "data: [DONE]\n\n";
    if ( ob_get_level() > 0 ) ob_flush();
    flush();
}

function cacb_sse_error( string $msg ): void {
    echo 'data: ' . wp_json_encode( [ 'e' => $msg ] ) . "\n\n";
    if ( ob_get_level() > 0 ) ob_flush();
    flush();
    cacb_sse_done();
}

// ── Streaming: OpenAI ─────────────────────────────────────────────────────────
function cacb_stream_openai( array $messages, string $api_key, string $model, int $max_tokens ): void {
    if ( ! function_exists( 'curl_init' ) ) {
        cacb_sse_error( __( 'cURL extension not available on this server.', 'smart-ai-chatbot' ) );
        return;
    }

    $done = false;
    $ch   = curl_init( 'https://api.openai.com/v1/chat/completions' );
    curl_setopt_array( $ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => wp_json_encode( [
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => $max_tokens,
            'temperature' => 0.7,
            'stream'      => true,
        ] ),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_WRITEFUNCTION  => static function ( $ch, $data ) use ( &$done ): int {
            if ( $done ) return strlen( $data );
            foreach ( explode( "\n", $data ) as $line ) {
                $line = trim( $line );
                if ( strpos( $line, 'data: ' ) !== 0 ) continue;
                $payload = substr( $line, 6 );
                if ( $payload === '[DONE]' ) { $done = true; break; }
                $json = json_decode( $payload, true );
                $text = $json['choices'][0]['delta']['content'] ?? '';
                if ( $text !== '' ) cacb_sse_chunk( $text );
            }
            return strlen( $data );
        },
    ] );
    curl_exec( $ch );
    if ( curl_errno( $ch ) ) {
        error_log( '[CACB] OpenAI stream error: ' . curl_error( $ch ) );
    }
    curl_close( $ch );
    cacb_sse_done();
}

// ── Streaming: Anthropic Claude ───────────────────────────────────────────────
function cacb_stream_claude( array $messages, string $api_key, string $model, int $max_tokens, string $system_prompt ): void {
    if ( ! function_exists( 'curl_init' ) ) {
        cacb_sse_error( __( 'cURL extension not available on this server.', 'smart-ai-chatbot' ) );
        return;
    }

    $payload = [
        'model'      => $model,
        'max_tokens' => $max_tokens,
        'messages'   => $messages,
        'stream'     => true,
    ];
    if ( ! empty( $system_prompt ) ) {
        $payload['system'] = $system_prompt;
    }

    $ch = curl_init( 'https://api.anthropic.com/v1/messages' );
    curl_setopt_array( $ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => wp_json_encode( $payload ),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_WRITEFUNCTION  => static function ( $ch, $data ): int {
            foreach ( explode( "\n", $data ) as $line ) {
                $line = trim( $line );
                if ( strpos( $line, 'data: ' ) !== 0 ) continue;
                $json = json_decode( substr( $line, 6 ), true );
                if ( ! is_array( $json ) ) continue;
                // Claude streams: content_block_delta carries the text
                if ( ( $json['type'] ?? '' ) === 'content_block_delta' ) {
                    $text = $json['delta']['text'] ?? '';
                    if ( $text !== '' ) cacb_sse_chunk( $text );
                }
            }
            return strlen( $data );
        },
    ] );
    curl_exec( $ch );
    if ( curl_errno( $ch ) ) {
        error_log( '[CACB] Claude stream error: ' . curl_error( $ch ) );
    }
    curl_close( $ch );
    cacb_sse_done();
}

// ── Streaming: Google Gemini ──────────────────────────────────────────────────
function cacb_stream_gemini( array $messages, string $api_key, string $model, int $max_tokens, string $system_prompt ): void {
    if ( ! function_exists( 'curl_init' ) ) {
        cacb_sse_error( __( 'cURL extension not available on this server.', 'smart-ai-chatbot' ) );
        return;
    }

    $contents = [];
    foreach ( $messages as $msg ) {
        $contents[] = [
            'role'  => $msg['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [ [ 'text' => $msg['content'] ] ],
        ];
    }

    $payload = [
        'contents'         => $contents,
        'generationConfig' => [
            'maxOutputTokens' => $max_tokens,
            'temperature'     => 0.7,
        ],
    ];
    if ( ! empty( $system_prompt ) ) {
        $payload['system_instruction'] = [ 'parts' => [ [ 'text' => $system_prompt ] ] ];
    }

    // alt=sse makes Gemini return Server-Sent Events format
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
        . rawurlencode( $model )
        . ':streamGenerateContent?alt=sse&key=' . rawurlencode( $api_key );

    $ch = curl_init( $url );
    curl_setopt_array( $ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [ 'Content-Type: application/json' ],
        CURLOPT_POSTFIELDS     => wp_json_encode( $payload ),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_WRITEFUNCTION  => static function ( $ch, $data ): int {
            foreach ( explode( "\n", $data ) as $line ) {
                $line = trim( $line );
                if ( strpos( $line, 'data: ' ) !== 0 ) continue;
                $json = json_decode( substr( $line, 6 ), true );
                if ( ! is_array( $json ) ) continue;
                $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
                if ( $text !== '' ) cacb_sse_chunk( $text );
            }
            return strlen( $data );
        },
    ] );
    curl_exec( $ch );
    if ( curl_errno( $ch ) ) {
        error_log( '[CACB] Gemini stream error: ' . curl_error( $ch ) );
    }
    curl_close( $ch );
    cacb_sse_done();
}
