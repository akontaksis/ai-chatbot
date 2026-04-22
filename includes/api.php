<?php
defined( 'ABSPATH' ) || exit;

// ── Nonce refresh endpoint (called when nonce expires after 12-24h) ───────────
add_action( 'wp_ajax_cacb_refresh_nonce',        'cacb_ajax_refresh_nonce' );
add_action( 'wp_ajax_nopriv_cacb_refresh_nonce', 'cacb_ajax_refresh_nonce' );
function cacb_ajax_refresh_nonce(): void {
    wp_send_json_success( [ 'nonce' => wp_create_nonce( 'cacb_chat_nonce' ) ] );
}

// ── Add to cart endpoint ──────────────────────────────────────────────────────
add_action( 'wp_ajax_cacb_add_to_cart',        'cacb_ajax_add_to_cart' );
add_action( 'wp_ajax_nopriv_cacb_add_to_cart', 'cacb_ajax_add_to_cart' );
function cacb_ajax_add_to_cart(): void {
    if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'cacb_chat_nonce' ) ) {
        error_log( '[CACB] add_to_cart: invalid nonce' );
        wp_send_json_error( [ 'reason' => 'invalid_nonce' ], 403 );
    }
    if ( ! function_exists( 'WC' ) ) {
        error_log( '[CACB] add_to_cart: WooCommerce not active' );
        wp_send_json_error( [ 'reason' => 'no_woocommerce' ], 400 );
    }

    // WC cart / session are not bootstrapped on admin-ajax.php by default —
    // wc_load_cart() initialises WC()->session, ->customer and ->cart.
    if ( function_exists( 'wc_load_cart' ) ) {
        wc_load_cart();
    }
    if ( ! WC()->cart ) {
        error_log( '[CACB] add_to_cart: WC()->cart still null after wc_load_cart()' );
        wp_send_json_error( [ 'reason' => 'cart_unavailable' ], 500 );
    }

    $product_id = absint( $_POST['product_id'] ?? 0 );
    $quantity   = max( 1, absint( $_POST['quantity'] ?? 1 ) );
    if ( ! $product_id ) {
        wp_send_json_error( [ 'reason' => 'missing_product_id' ], 400 );
    }

    $product = wc_get_product( $product_id );
    if ( ! $product ) {
        error_log( '[CACB] add_to_cart: product not found id=' . $product_id );
        wp_send_json_error( [ 'reason' => 'product_not_found', 'id' => $product_id ], 404 );
    }
    if ( ! $product->is_purchasable() ) {
        error_log( '[CACB] add_to_cart: product not purchasable id=' . $product_id );
        wp_send_json_error( [ 'reason' => 'not_purchasable', 'id' => $product_id ], 400 );
    }
    if ( $product->is_type( 'variable' ) ) {
        error_log( '[CACB] add_to_cart: variable product needs variation id=' . $product_id );
        wp_send_json_error( [ 'reason' => 'variable_product', 'id' => $product_id ], 400 );
    }

    // Capture wc_add_notice() messages emitted during add_to_cart (stock, etc.)
    $result = WC()->cart->add_to_cart( $product_id, $quantity );

    if ( $result ) {
        // Force cart totals recalculation so fragments reflect the new item
        WC()->cart->calculate_totals();

        // Collect the mini-cart fragments WooCommerce + themes register
        ob_start();
        woocommerce_mini_cart();
        $mini_cart = ob_get_clean();

        $fragments = apply_filters(
            'woocommerce_add_to_cart_fragments',
            [
                'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>',
            ]
        );

        wp_send_json_success( [
            'cart_count'    => WC()->cart->get_cart_contents_count(),
            'cart_item_key' => $result,
            'fragments'     => $fragments,
            'cart_hash'     => WC()->cart->get_cart_hash(),
        ] );
    }

    // Collect any error notices WooCommerce queued during the failed add.
    $notices = function_exists( 'wc_get_notices' ) ? wc_get_notices( 'error' ) : [];
    $msgs    = array_map( static function ( $n ) {
        return is_array( $n ) ? ( $n['notice'] ?? '' ) : (string) $n;
    }, $notices );
    if ( function_exists( 'wc_clear_notices' ) ) {
        wc_clear_notices();
    }
    error_log( '[CACB] add_to_cart: failed id=' . $product_id . ' notices=' . wp_json_encode( $msgs ) );
    wp_send_json_error( [ 'reason' => 'add_failed', 'notices' => $msgs ], 400 );
}

// ── Register REST routes ──────────────────────────────────────────────────────
add_action( 'rest_api_init', 'cacb_register_routes' );
function cacb_register_routes() {
    register_rest_route( 'cacb/v1', '/chat', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'cacb_handle_chat',
        'permission_callback' => '__return_true',
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

    register_rest_route( 'cacb/v1', '/product/(?P<id>\d+)', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'cacb_rest_get_product',
        'permission_callback' => '__return_true',
        'args'                => [
            'id' => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
        ],
    ] );
}

// ── Product card data endpoint ────────────────────────────────────────────────
function cacb_rest_get_product( WP_REST_Request $request ) {
    if ( ! function_exists( 'wc_get_product' ) ) {
        return new WP_Error( 'no_wc', '', [ 'status' => 404 ] );
    }
    $id      = (int) $request->get_param( 'id' );
    $product = wc_get_product( $id );
    if ( ! $product || ! $product->is_visible() ) {
        return new WP_Error( 'not_found', '', [ 'status' => 404 ] );
    }
    $image_id  = $product->get_image_id();
    $image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';
    return rest_ensure_response( [
        'name'          => $product->get_name(),
        'price'         => wc_format_decimal( $product->get_price(), 2 ),
        'regular_price' => wc_format_decimal( $product->get_regular_price(), 2 ),
        'sale_price'    => $product->is_on_sale() ? wc_format_decimal( $product->get_sale_price(), 2 ) : '',
        'image'         => $image_url ?: '',
        'url'           => get_permalink( $id ),
    ] );
}

// ── Sanitize incoming messages array ─────────────────────────────────────────
define( 'CACB_MAX_MSG_CHARS', 4000 );

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
            continue;
        }
        $content = sanitize_textarea_field( $msg['content'] );
        if ( mb_strlen( $content ) > CACB_MAX_MSG_CHARS ) {
            $content = mb_substr( $content, 0, CACB_MAX_MSG_CHARS );
        }
        $clean[] = [
            'role'    => $role,
            'content' => $content,
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
        return false;
    }

    set_transient( $key, $count + 1, HOUR_IN_SECONDS );
    return true;
}

function cacb_get_client_ip(): string {
    $headers = [
        'HTTP_CF_CONNECTING_IP',
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

// ── Tool definitions for WooCommerce product search ───────────────────────────

/**
 * Reads term names from a WC attribute taxonomy.
 * Returns string[] of names, or [] on failure.
 */
function cacb_get_attribute_terms( string $slug ): array {
    $terms = get_terms( [
        'taxonomy'   => 'pa_' . $slug,
        'hide_empty' => true,
        'fields'     => 'names',
    ] );
    return ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? $terms : [];
}

function cacb_get_tool_definitions(): array {
    if ( ! function_exists( 'get_terms' ) || ! function_exists( 'wc_get_products' ) ) {
        return [];
    }

    // ── Product categories ────────────────────────────────────────────────────
    $terms      = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true ] );
    $cat_labels = [];
    $cat_slugs  = [];
    if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
        foreach ( $terms as $term ) {
            if ( 'uncategorized' === $term->slug ) continue;
            $cat_labels[] = $term->name . ' (' . $term->slug . ')';
            $cat_slugs[]  = $term->slug;
        }
    }

    // ── WC product attributes ─────────────────────────────────────────────────
    $years      = cacb_get_attribute_terms( 'xronia' );
    $varieties  = cacb_get_attribute_terms( 'poikilia' );
    $regions    = cacb_get_attribute_terms( 'perioxi' );
    $origins    = cacb_get_attribute_terms( 'proeleusi' );
    $sweetness  = cacb_get_attribute_terms( 'glykytita' );

    // ── Build properties ──────────────────────────────────────────────────────
    $properties = [
        'keyword'   => [
            'type'        => 'string',
            'description' => 'Λέξη-κλειδί ελεύθερης αναζήτησης στον τίτλο/περιγραφή (παραγωγός, σειρά, κλπ).',
        ],
        'max_price' => [
            'type'        => 'number',
            'description' => 'Μέγιστη τιμή σε ευρώ (π.χ. 15 για "κάτω από 15€").',
        ],
        'min_price' => [
            'type'        => 'number',
            'description' => 'Ελάχιστη τιμή σε ευρώ (π.χ. 30 για "πάνω από 30€").',
        ],
    ];

    if ( ! empty( $cat_slugs ) ) {
        $properties['category'] = [
            'type'        => 'string',
            'description' => 'Κατηγορία προϊόντος. Διαθέσιμες: ' . implode( ', ', $cat_labels ) . '. Χρησιμοποίησε το slug.',
            'enum'        => $cat_slugs,
        ];
    }

    if ( ! empty( $years ) ) {
        $properties['year'] = [
            'type'        => 'string',
            'description' => 'Χρονιά/Vintage (π.χ. 2019).',
            'enum'        => $years,
        ];
    }

    if ( ! empty( $varieties ) ) {
        $properties['grape_variety'] = [
            'type'        => 'string',
            'description' => 'Ποικιλία σταφυλιού (π.χ. Ασύρτικο, Xinomavro, Chardonnay).',
            'enum'        => $varieties,
        ];
    }

    if ( ! empty( $regions ) ) {
        $properties['region'] = [
            'type'        => 'string',
            'description' => 'Περιοχή παραγωγής (π.χ. Σαντορίνη, Λήμνος, Veneto).',
            'enum'        => $regions,
        ];
    }

    if ( ! empty( $origins ) ) {
        $properties['origin'] = [
            'type'        => 'string',
            'description' => 'Χώρα προέλευσης (π.χ. Ελλάδα, Γαλλία, Ιταλία).',
            'enum'        => $origins,
        ];
    }

    if ( ! empty( $sweetness ) ) {
        $properties['sweetness'] = [
            'type'        => 'string',
            'description' => 'Γλυκύτητα: Ξηρό, Ημίξηρο, Ημίγλυκο, Γλυκό, Brut.',
            'enum'        => $sweetness,
        ];
    }

    $max_results = max( 1, min( 20, (int) get_option( 'cacb_wc_limit', 8 ) ) );

    return [
        'name'        => 'search_products',
        'description' => "Αναζήτηση προϊόντων στο κατάστημα με φίλτρα. Κάλεσε αυτό το tool όταν ο χρήστης ρωτάει για προϊόντα, τιμές, διαθεσιμότητα ή θέλει σύσταση. Επιστρέφει έως {$max_results} αποτελέσματα. Για εύρος τιμών χρησιμοποίησε min_price και max_price μαζί (π.χ. 20-40€: min_price=20, max_price=40). ΣΗΜΑΝΤΙΚΟ: Όταν ο χρήστης αναφέρει συγκεκριμένο τύπο προϊόντος (π.χ. 'κρασί', 'μπύρα'), χρησιμοποίησε πάντα το κατάλληλο category slug για να αποφύγεις άσχετα αποτελέσματα.",
        'parameters'  => [
            'type'       => 'object',
            'properties' => $properties,
        ],
    ];
}

// ── Execute product search tool call ─────────────────────────────────────────
function cacb_execute_search_products( array $args ): string {
    if ( ! function_exists( 'wc_get_products' ) ) {
        return 'Το WooCommerce δεν είναι ενεργό.';
    }
    error_log( '[CACB] search_products args: ' . wp_json_encode( $args ) );

    $query_args = [
        'limit'   => max( 1, min( 20, (int) get_option( 'cacb_wc_limit', 8 ) ) ),
        'status'  => 'publish',
        'orderby' => 'date',
        'order'   => 'DESC',
    ];

    if ( ! empty( $args['category'] ) ) {
        $query_args['category'] = [ sanitize_text_field( $args['category'] ) ];
    }

    $price_meta = [];
    if ( ! empty( $args['min_price'] ) ) {
        $price_meta[] = [ 'key' => '_price', 'value' => (float) $args['min_price'], 'compare' => '>=', 'type' => 'NUMERIC' ];
    }
    if ( ! empty( $args['max_price'] ) ) {
        $price_meta[] = [ 'key' => '_price', 'value' => (float) $args['max_price'], 'compare' => '<=', 'type' => 'NUMERIC' ];
    }
    if ( ! empty( $price_meta ) ) {
        $query_args['meta_query'] = $price_meta; // phpcs:ignore WordPress.DB.SlowDBQuery
    }

    if ( ! empty( $args['keyword'] ) ) {
        $query_args['s'] = sanitize_text_field( $args['keyword'] );
    }

    // ── Attribute filters via tax_query ───────────────────────────────────────
    $tax_query = [];

    $attr_map = [
        'year'         => 'pa_xronia',
        'grape_variety'=> 'pa_poikilia',
        'region'       => 'pa_perioxi',
        'origin'       => 'pa_proeleusi',
        'sweetness'    => 'pa_glykytita',
    ];

    foreach ( $attr_map as $arg_key => $taxonomy ) {
        if ( ! empty( $args[ $arg_key ] ) ) {
            $tax_query[] = [
                'taxonomy' => $taxonomy,
                'field'    => 'name',
                'terms'    => [ sanitize_text_field( $args[ $arg_key ] ) ],
            ];
        }
    }

    if ( ! empty( $tax_query ) ) {
        if ( count( $tax_query ) > 1 ) {
            $tax_query['relation'] = 'AND';
        }
        $query_args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery
    }

    $products = wc_get_products( $query_args );
    error_log( '[CACB] query_args: ' . wp_json_encode( $query_args ) );
    error_log( '[CACB] products found: ' . count( $products ) );

    if ( empty( $products ) ) {
        return 'Δεν βρέθηκαν προϊόντα με τα συγκεκριμένα κριτήρια.';
    }

    $lines = [];
    foreach ( $products as $product ) {
        $lines[] = '• ' . cacb_product_to_text( $product, 150 ) . ' | ID:' . $product->get_id();
    }

    $result = implode( "\n", $lines );
    error_log( '[CACB] tool result: ' . $result );
    return $result;
}

// ── Main chat handler ─────────────────────────────────────────────────────────
function cacb_handle_chat( WP_REST_Request $request ) {

    // 1. Verify nonce
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

    if ( 'claude' === $provider ) {
        $api_key = defined( 'CACB_CLAUDE_API_KEY' )
            ? CACB_CLAUDE_API_KEY
            : cacb_decrypt_key( get_option( 'cacb_claude_api_key', '' ) );
        $model = sanitize_text_field( get_option( 'cacb_claude_model', 'claude-sonnet-4-6' ) );
    } else {
        $provider = 'openai';
        $api_key  = defined( 'CACB_OPENAI_API_KEY' )
            ? CACB_OPENAI_API_KEY
            : cacb_decrypt_key( get_option( 'cacb_api_key', '' ) );
        $model = sanitize_text_field( get_option( 'cacb_model', 'gpt-4o-mini' ) );
    }

    if ( empty( $api_key ) ) {
        return new WP_Error( 'no_api_key', __( 'API key not configured.', 'smart-ai-chatbot' ), [ 'status' => 500 ] );
    }

    // 4. Build messages
    $history_limit   = max( 2, (int) get_option( 'cacb_history_limit', 10 ) );
    $client_messages = $request->get_param( 'messages' );

    if ( count( $client_messages ) > $history_limit ) {
        $client_messages = array_slice( $client_messages, - $history_limit );
    }

    // System prompt + RAG context (pages/FAQ only — products via function calling)
    $system_prompt = sanitize_textarea_field( get_option( 'cacb_system_prompt', '' ) );
    $rag_context   = cacb_get_smart_context( $client_messages );
    $system_prompt .= $rag_context;

    // Product card instruction — active whenever WooCommerce is available
    if ( function_exists( 'wc_get_product' ) ) {
        $system_prompt .= "\n\nΌταν αναφέρεις συγκεκριμένο προϊόν από τα αποτελέσματα αναζήτησης, πρόσθεσε [PRODUCT:123] μετά το όνομά του, όπου 123 είναι ο αριθμός ID του προϊόντος.";
    }

    $max_tokens = min( 2000, max( 100, (int) get_option( 'cacb_max_tokens', 500 ) ) );

    // Tool definitions — only when WooCommerce is active and enabled in settings
    $wc_active = function_exists( 'wc_get_products' ) && '1' === get_option( 'cacb_wc_enabled', '0' );
    $tools     = $wc_active ? cacb_get_tool_definitions() : [];

    // 5. Call provider
    if ( 'claude' === $provider ) {
        $result = cacb_call_claude( $client_messages, $api_key, $model, $max_tokens, $system_prompt, $tools );
    } else {
        $result = cacb_call_openai( $client_messages, $api_key, $model, $max_tokens, $system_prompt, $tools );
    }

    if ( is_wp_error( $result ) ) {
        return $result;
    }

    // 6. Log the exchange
    $last_user_msg = '';
    foreach ( array_reverse( $client_messages ) as $msg ) {
        if ( 'user' === $msg['role'] ) { $last_user_msg = $msg['content']; break; }
    }
    cacb_log_exchange( $provider, $model, $last_user_msg, $result, $rag_context );

    // 7. Return sanitized reply
    return rest_ensure_response( [
        'reply' => wp_kses( $result, [
            'br' => [], 'strong' => [], 'em' => [], 'a' => [ 'href' => [], 'target' => [] ],
        ] ),
    ] );
}

// ── Provider: OpenAI ──────────────────────────────────────────────────────────

/**
 * GPT-5 and o1/o3 reasoning models require 'max_completion_tokens' and do not
 * accept the legacy 'max_tokens' parameter. They also reject non-default
 * temperature values — only temperature=1 is allowed.
 */
function cacb_openai_is_new_family( string $model ): bool {
    return str_starts_with( $model, 'gpt-5' )
        || str_starts_with( $model, 'o1' )
        || str_starts_with( $model, 'o3' );
}

function cacb_call_openai( array $client_messages, string $api_key, string $model, int $max_tokens, string $system_prompt, array $tools = [] ) {
    $messages = array_merge(
        [ [ 'role' => 'system', 'content' => $system_prompt ] ],
        $client_messages
    );

    $is_new = cacb_openai_is_new_family( $model );

    $payload = [
        'model'    => $model,
        'messages' => $messages,
    ];

    if ( $is_new ) {
        // Reasoning models spend internal tokens on thinking before producing output.
        // Enforce a 1 500-token floor so finish_reason never hits "length" on short answers.
        $payload['max_completion_tokens'] = max( 1500, $max_tokens );
    } else {
        $payload['max_tokens']  = $max_tokens;
        $payload['temperature'] = 0.2;
    }

    if ( ! empty( $tools ) ) {
        $payload['tools'] = [ [
            'type'     => 'function',
            'function' => [
                'name'        => $tools['name'],
                'description' => $tools['description'],
                'parameters'  => $tools['parameters'],
            ],
        ] ];
        $payload['tool_choice'] = 'auto';
    }

    $headers = [
        'Authorization' => 'Bearer ' . $api_key,
        'Content-Type'  => 'application/json',
    ];

    $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
        'timeout' => 30,
        'headers' => $headers,
        'body'    => wp_json_encode( $payload ),
    ] );

    if ( is_wp_error( $response ) ) {
        error_log( '[CACB] OpenAI connection error: ' . $response->get_error_message() );
        return new WP_Error( 'openai_unreachable', __( 'Δεν ήταν δυνατή η σύνδεση. Παρακαλώ δοκιμάστε αργότερα.', 'smart-ai-chatbot' ), [ 'status' => 502 ] );
    }

    $http_code = wp_remote_retrieve_response_code( $response );
    $body      = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $http_code !== 200 || ! is_array( $body ) ) {
        $err = is_array( $body ) ? ( $body['error']['message'] ?? 'Unknown OpenAI error' ) : 'Invalid response body';
        error_log( "[CACB] OpenAI API error {$http_code}: {$err}" );
        return new WP_Error( 'openai_error', __( 'Παρουσιάστηκε σφάλμα. Παρακαλώ δοκιμάστε αργότερα.', 'smart-ai-chatbot' ), [ 'status' => 502 ] );
    }

    $choice      = $body['choices'][0] ?? [];
    $finish      = $choice['finish_reason'] ?? '';
    $asst_msg    = $choice['message'] ?? [];

    // ── Tool call: execute and make second request ────────────────────────────
    if ( 'tool_calls' === $finish && ! empty( $asst_msg['tool_calls'] ) ) {
        // Append assistant turn (contains ALL parallel tool_calls)
        $messages[] = $asst_msg;

        // OpenAI spec: every tool_call_id MUST have a matching tool message.
        // gpt-5-* can emit multiple parallel calls — loop over all of them.
        foreach ( $asst_msg['tool_calls'] as $tool_call ) {
            $tool_args   = json_decode( $tool_call['function']['arguments'] ?? '{}', true ) ?: [];
            $tool_result = cacb_execute_search_products( $tool_args );
            $messages[] = [
                'role'         => 'tool',
                'tool_call_id' => $tool_call['id'],
                'content'      => $tool_result,
            ];
        }

        $payload2 = [
            'model'    => $model,
            'messages' => $messages,
        ];
        if ( $is_new ) {
            $payload2['max_completion_tokens'] = max( 1500, $max_tokens );
        } else {
            $payload2['max_tokens']  = $max_tokens;
            $payload2['temperature'] = 0.2;
        }

        $response2 = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
            'timeout' => 30,
            'headers' => $headers,
            'body'    => wp_json_encode( $payload2 ),
        ] );

        if ( is_wp_error( $response2 ) ) {
            error_log( '[CACB] OpenAI connection error (2nd call): ' . $response2->get_error_message() );
            return new WP_Error( 'openai_unreachable', __( 'Δεν ήταν δυνατή η σύνδεση. Παρακαλώ δοκιμάστε αργότερα.', 'smart-ai-chatbot' ), [ 'status' => 502 ] );
        }

        $http_code2 = wp_remote_retrieve_response_code( $response2 );
        $body2      = json_decode( wp_remote_retrieve_body( $response2 ), true );

        if ( $http_code2 !== 200 || ! is_array( $body2 ) ) {
            $err = is_array( $body2 ) ? ( $body2['error']['message'] ?? 'Unknown OpenAI error' ) : 'Invalid response body';
            error_log( "[CACB] OpenAI API error (2nd call) {$http_code2}: {$err}" );
            return new WP_Error( 'openai_error', __( 'Παρουσιάστηκε σφάλμα. Παρακαλώ δοκιμάστε αργότερα.', 'smart-ai-chatbot' ), [ 'status' => 502 ] );
        }

        $reply = $body2['choices'][0]['message']['content'] ?? '';
        if ( empty( $reply ) ) {
            error_log( '[CACB] OpenAI empty reply (2nd call). model=' . $model . ' choice=' . wp_json_encode( $body2['choices'][0] ?? [] ) );
            return new WP_Error( 'empty_response', __( 'Κενή απάντηση από το AI.', 'smart-ai-chatbot' ), [ 'status' => 502 ] );
        }
        return $reply;
    }

    // ── Direct answer (no tool call) ──────────────────────────────────────────
    $reply = $asst_msg['content'] ?? '';
    if ( empty( $reply ) ) {
        error_log( '[CACB] OpenAI empty reply (direct). model=' . $model . ' finish=' . $finish . ' choice=' . wp_json_encode( $choice ) );
        return new WP_Error( 'empty_response', __( 'Κενή απάντηση από το AI.', 'smart-ai-chatbot' ), [ 'status' => 502 ] );
    }
    return $reply;
}

// ── Provider: Anthropic Claude ────────────────────────────────────────────────
function cacb_call_claude( array $client_messages, string $api_key, string $model, int $max_tokens, string $system_prompt, array $tools = [] ) {
    $payload = [
        'model'       => $model,
        'max_tokens'  => $max_tokens,
        'temperature' => 0.2,
        'messages'    => $client_messages,
    ];

    if ( ! empty( $system_prompt ) ) {
        $payload['system'] = $system_prompt;
    }

    if ( ! empty( $tools ) ) {
        $payload['tools'] = [ [
            'name'         => $tools['name'],
            'description'  => $tools['description'],
            'input_schema' => $tools['parameters'],
        ] ];
    }

    $headers = [
        'x-api-key'         => $api_key,
        'anthropic-version' => '2023-06-01',
        'Content-Type'      => 'application/json',
    ];

    $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
        'timeout' => 30,
        'headers' => $headers,
        'body'    => wp_json_encode( $payload ),
    ] );

    if ( is_wp_error( $response ) ) {
        error_log( '[CACB] Claude connection error: ' . $response->get_error_message() );
        return new WP_Error( 'claude_unreachable', __( 'Δεν ήταν δυνατή η σύνδεση. Παρακαλώ δοκιμάστε αργότερα.', 'smart-ai-chatbot' ), [ 'status' => 502 ] );
    }

    $http_code = wp_remote_retrieve_response_code( $response );
    $body      = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $http_code !== 200 || ! is_array( $body ) ) {
        $err = is_array( $body ) ? ( $body['error']['message'] ?? 'Unknown Claude error' ) : 'Invalid response body';
        error_log( "[CACB] Claude API error {$http_code}: {$err}" );
        return new WP_Error( 'claude_error', __( 'Παρουσιάστηκε σφάλμα. Παρακαλώ δοκιμάστε αργότερα.', 'smart-ai-chatbot' ), [ 'status' => 502 ] );
    }

    // ── Tool use: execute and make second request ─────────────────────────────
    if ( 'tool_use' === ( $body['stop_reason'] ?? '' ) ) {
        $tool_use = null;
        foreach ( ( $body['content'] ?? [] ) as $block ) {
            if ( 'tool_use' === ( $block['type'] ?? '' ) ) {
                $tool_use = $block;
                break;
            }
        }

        if ( $tool_use ) {
            $tool_args = $tool_use['input'] ?? [];
            if ( ! is_array( $tool_args ) ) {
                error_log( '[CACB] Claude tool_use input not array: ' . var_export( $tool_args, true ) );
                $tool_args = [];
            }
            $tool_result = cacb_execute_search_products( $tool_args );

            // Append assistant turn + tool result as user message
            $messages   = $client_messages;
            $messages[] = [ 'role' => 'assistant', 'content' => $body['content'] ];
            $messages[] = [
                'role'    => 'user',
                'content' => [ [
                    'type'        => 'tool_result',
                    'tool_use_id' => $tool_use['id'],
                    'content'     => $tool_result,
                ] ],
            ];

            $response2 = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
                'timeout' => 30,
                'headers' => $headers,
                'body'    => wp_json_encode( [
                    'model'       => $model,
                    'max_tokens'  => $max_tokens,
                    'temperature' => 0.2,
                    'system'      => $system_prompt,
                    'messages'    => $messages,
                ] ),
            ] );

            if ( is_wp_error( $response2 ) ) {
                error_log( '[CACB] Claude connection error (2nd call): ' . $response2->get_error_message() );
                return new WP_Error( 'claude_unreachable', __( 'Δεν ήταν δυνατή η σύνδεση. Παρακαλώ δοκιμάστε αργότερα.', 'smart-ai-chatbot' ), [ 'status' => 502 ] );
            }

            $http_code2 = wp_remote_retrieve_response_code( $response2 );
            $body2      = json_decode( wp_remote_retrieve_body( $response2 ), true );

            if ( $http_code2 !== 200 || ! is_array( $body2 ) ) {
                $err = is_array( $body2 ) ? ( $body2['error']['message'] ?? 'Unknown Claude error' ) : 'Invalid response body';
                error_log( "[CACB] Claude API error (2nd call) {$http_code2}: {$err}" );
                return new WP_Error( 'claude_error', __( 'Παρουσιάστηκε σφάλμα. Παρακαλώ δοκιμάστε αργότερα.', 'smart-ai-chatbot' ), [ 'status' => 502 ] );
            }

            $reply = $body2['content'][0]['text'] ?? '';
            if ( empty( $reply ) ) {
                return new WP_Error( 'empty_response', __( 'Κενή απάντηση από το AI.', 'smart-ai-chatbot' ), [ 'status' => 502 ] );
            }
            return $reply;
        }
    }

    // ── Direct answer (no tool use) ───────────────────────────────────────────
    $reply = $body['content'][0]['text'] ?? '';
    if ( empty( $reply ) ) {
        return new WP_Error( 'empty_response', __( 'Κενή απάντηση από το AI.', 'smart-ai-chatbot' ), [ 'status' => 502 ] );
    }
    return $reply;
}
