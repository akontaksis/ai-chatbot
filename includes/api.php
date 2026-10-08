<?php
defined( 'ABSPATH' ) || exit;

// ── Nonce refresh endpoint — used by add-to-cart when a cached page carries a
// nonce older than 12-24h (the chat endpoint itself has no nonce) ─────────────
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
        // WP stores "&" in titles as "&amp;"; chat.js escapes again, so send plain text
        'name'          => html_entity_decode( $product->get_name(), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
        'price'         => wc_format_decimal( $product->get_price(), 2 ),
        'regular_price' => wc_format_decimal( $product->get_regular_price(), 2 ),
        'sale_price'    => $product->is_on_sale() ? wc_format_decimal( $product->get_sale_price(), 2 ) : '',
        'image'         => $image_url ?: '',
        'url'           => get_permalink( $id ),
    ] );
}

// ── Sanitize incoming messages array ─────────────────────────────────────────
define( 'CACB_MAX_MSG_CHARS', 4000 );
define( 'CACB_MAX_HISTORY_CHARS', 12000 );

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

// Only REMOTE_ADDR is trusted: proxy headers (X-Forwarded-For, CF-Connecting-IP)
// are client-controlled and would let anyone bypass the rate limit.
function cacb_get_client_ip(): string {
    $ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
    return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
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
        'min_price'     => [
            'type'        => 'number',
            'description' => 'Ελάχιστη τιμή σε ευρώ (π.χ. 30 για "πάνω από 30€").',
        ],
        'sort_by_price' => [
            'type'        => 'string',
            'description' => 'Ταξινόμηση αποτελεσμάτων κατά τιμή. Χρησιμοποίησε "asc" για φθηνότερα πρώτα (π.χ. "ποιο είναι το φθηνότερο;"), "desc" για ακριβότερα πρώτα.',
            'enum'        => [ 'asc', 'desc' ],
        ],
        'on_sale' => [
            'type'        => 'boolean',
            'description' => 'Αν είναι true, επιστρέφει μόνο προϊόντα σε προσφορά/έκπτωση (π.χ. "τι έχει προσφορά;", "τι είναι σε έκπτωση;").',
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
        'description' => "Αναζήτηση προϊόντων στο κατάστημα με φίλτρα. Επιστρέφει έως {$max_results} αποτελέσματα. ΥΠΟΧΡΕΩΤΙΚΟ: Κάλεσε ΠΑΝΤΑ αυτό το tool όταν ο χρήστης ρωτάει για οποιοδήποτε προϊόν (π.χ. 'έχετε πατατάκια;', 'έχετε κρασί;'), τιμές, διαθεσιμότητα ή σύσταση. ΠΟΤΕ μη λες ότι δεν διαθέτετε κάποιο προϊόν χωρίς να κάνεις πρώτα αναζήτηση με keyword. Για εύρος τιμών χρησιμοποίησε min_price και max_price μαζί (π.χ. 20-40€: min_price=20, max_price=40). Όταν ο χρήστης αναφέρει συγκεκριμένο τύπο προϊόντος, χρησιμοποίησε το κατάλληλο category slug αν υπάρχει· αλλιώς χρησιμοποίησε το keyword. Κάθε νέα ερώτηση είναι νέα αναζήτηση: βάλε min_price/max_price ΜΟΝΟ αν ο χρήστης αναφέρει τιμή στην τρέχουσα ερώτηση — μην κρατάς όριο τιμής από προηγούμενη ερώτηση. Για «φθηνότερο»/«ακριβότερο» χρησιμοποίησε sort_by_price χωρίς όριο τιμής.",
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

    $sort = strtolower( sanitize_text_field( $args['sort_by_price'] ?? '' ) );

    // In-stock products first, then out-of-stock: '_stock_status' sorts
    // alphabetically as instock < onbackorder < outofstock. Out-of-stock items
    // are kept (not filtered) so the bot can still say "we have it, sold out".
    $meta_query = [
        'cacb_stock' => [ 'key' => '_stock_status', 'compare' => 'EXISTS' ],
    ];
    $orderby = [ 'cacb_stock' => 'ASC' ];

    if ( in_array( $sort, [ 'asc', 'desc' ], true ) ) {
        $meta_query['cacb_price'] = [ 'key' => '_price', 'compare' => 'EXISTS', 'type' => 'NUMERIC' ];
        $orderby['cacb_price']    = strtoupper( $sort );
    } else {
        $orderby['date'] = 'DESC';
    }

    if ( ! empty( $args['min_price'] ) ) {
        $meta_query[] = [ 'key' => '_price', 'value' => (float) $args['min_price'], 'compare' => '>=', 'type' => 'NUMERIC' ];
    }
    if ( ! empty( $args['max_price'] ) ) {
        $meta_query[] = [ 'key' => '_price', 'value' => (float) $args['max_price'], 'compare' => '<=', 'type' => 'NUMERIC' ];
    }
    if ( ! empty( $args['on_sale'] ) ) {
        $meta_query[] = [ 'key' => '_sale_price', 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC' ];
    }

    // WP_Query directly, not wc_get_products(): WooCommerce's data store drops
    // any 'meta_query' passed to wc_get_products(), so the price, on-sale and
    // stock-order clauses above were silently ignored.
    $query_args = [
        'post_type'           => 'product',
        'post_status'         => 'publish',
        'posts_per_page'      => max( 1, min( 20, (int) get_option( 'cacb_wc_limit', 8 ) ) ),
        'fields'              => 'ids',
        'no_found_rows'       => true,
        'ignore_sticky_posts' => true,
        'meta_query'          => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery
        'orderby'             => $orderby,
    ];

    if ( ! empty( $args['keyword'] ) ) {
        $query_args['s'] = sanitize_text_field( $args['keyword'] );
    }

    // ── Category + attribute filters via tax_query ────────────────────────────
    $tax_query = [];

    if ( ! empty( $args['category'] ) ) {
        $tax_query[] = [
            'taxonomy' => 'product_cat',
            'field'    => 'slug',
            'terms'    => [ sanitize_title( $args['category'] ) ],
        ];
    }

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

    $query    = new WP_Query( $query_args );
    $products = array_filter( array_map( 'wc_get_product', $query->posts ) );

    $trace_head = 'search_products ' . wp_json_encode( $args, JSON_UNESCAPED_UNICODE );

    if ( empty( $products ) ) {
        $result = 'Δεν βρέθηκαν προϊόντα με τα συγκεκριμένα κριτήρια.';
        cacb_tool_trace( $trace_head . "\n" . $result );
        return $result;
    }

    // Out-of-stock products go in a separate section: in one flat list the
    // model picked them for "cheapest"/"recommend" because it compares prices,
    // not list positions.
    $in_stock = [];
    $sold_out = [];
    foreach ( $products as $product ) {
        $line = '• ' . cacb_product_to_text( $product, 150 ) . ' | ID:' . $product->get_id();
        if ( $product->is_in_stock() ) {
            $in_stock[] = $line;
        } else {
            $sold_out[] = $line;
        }
    }

    $sections = [];
    $sections[] = empty( $in_stock )
        ? 'ΔΙΑΘΕΣΙΜΑ: κανένα διαθέσιμο προϊόν με αυτά τα κριτήρια.'
        : "ΔΙΑΘΕΣΙΜΑ (πρότεινε μόνο από αυτά· για «φθηνότερο/ακριβότερο» σύγκρινε μόνο αυτά):\n" . implode( "\n", $in_stock );
    if ( ! empty( $sold_out ) ) {
        $sections[] = "ΕΞΑΝΤΛΗΜΕΝΑ (μην τα προτείνεις. Αν ο πελάτης ρώτησε για αυτό ακριβώς το προϊόν ή τον παραγωγό του, πες ότι το έχουμε αλλά είναι προσωρινά εξαντλημένο):\n" . implode( "\n", $sold_out );
    }
    $result = implode( "\n\n", $sections );

    cacb_tool_trace( $trace_head . "\n" . $result );
    return $result;
}

/**
 * Collects the product searches made during this request so they can be
 * stored in the log next to the RAG context (debug mode only).
 * Call with a string to record an entry; call with no argument to read all.
 *
 * @return string[]
 */
function cacb_tool_trace( ?string $entry = null ): array {
    static $trace = [];
    if ( null !== $entry ) {
        $trace[] = $entry;
    }
    return $trace;
}

// ── Main chat handler ─────────────────────────────────────────────────────────
function cacb_handle_chat( WP_REST_Request $request ) {

    // 1. Rate limit check (the public nonce added no real protection and broke logged-in users)
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

    // The history comes from the browser, so also cap its total size: without
    // this one request could carry history_limit × CACB_MAX_MSG_CHARS chars.
    // Keep the newest messages that fit; the latest one is always kept.
    $kept  = [];
    $chars = 0;
    foreach ( array_reverse( $client_messages ) as $msg ) {
        $len = mb_strlen( $msg['content'] );
        if ( ! empty( $kept ) && $chars + $len > CACB_MAX_HISTORY_CHARS ) {
            break;
        }
        $chars += $len;
        array_unshift( $kept, $msg );
    }
    // Claude requires the conversation to start with a user turn
    while ( ! empty( $kept ) && 'user' !== $kept[0]['role'] ) {
        array_shift( $kept );
    }
    $client_messages = $kept;

    if ( empty( $client_messages ) ) {
        return new WP_Error( 'no_messages', __( 'Δεν υπάρχει μήνυμα.', 'smart-ai-chatbot' ), [ 'status' => 400 ] );
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
    // In debug mode the log shows the product searches too (filters + results),
    // so wrong answers can be traced to the exact tool call.
    $log_context = $rag_context;
    $trace       = cacb_tool_trace();
    if ( ! empty( $trace ) ) {
        $log_context .= "\n\n--- ΑΝΑΖΗΤΗΣΕΙΣ ΠΡΟΪΟΝΤΩΝ ---\n" . implode( "\n\n", $trace );
    }
    cacb_log_exchange( $provider, $model, $last_user_msg, $result, trim( $log_context ) );

    // 7. Return the reply as plain text. chat.js escapes it before rendering, so
    // running wp_kses here only double-encoded "&" into a visible "&amp;" and
    // stripped text like "< 15€".
    return rest_ensure_response( [ 'reply' => $result ] );
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

    // Tool loop: Claude may search, read the results, then search again. Every
    // follow-up request must keep 'tools' defined because the conversation now
    // contains tool_use / tool_result blocks — the API rejects it otherwise.
    $max_rounds = 3;
    for ( $round = 1; $round <= $max_rounds; $round++ ) {
        if ( ! empty( $tools ) && $round === $max_rounds ) {
            // Last round: force a text answer so we never end on a bare tool_use
            $payload['tool_choice'] = [ 'type' => 'none' ];
        }

        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'timeout' => 30,
            'headers' => $headers,
            'body'    => wp_json_encode( $payload ),
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( "[CACB] Claude connection error (round {$round}): " . $response->get_error_message() );
            return new WP_Error( 'claude_unreachable', __( 'Δεν ήταν δυνατή η σύνδεση. Παρακαλώ δοκιμάστε αργότερα.', 'smart-ai-chatbot' ), [ 'status' => 502 ] );
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $http_code !== 200 || ! is_array( $body ) ) {
            $err = is_array( $body ) ? ( $body['error']['message'] ?? 'Unknown Claude error' ) : 'Invalid response body';
            error_log( "[CACB] Claude API error (round {$round}) {$http_code}: {$err}" );
            return new WP_Error( 'claude_error', __( 'Παρουσιάστηκε σφάλμα. Παρακαλώ δοκιμάστε αργότερα.', 'smart-ai-chatbot' ), [ 'status' => 502 ] );
        }

        $content = is_array( $body['content'] ?? null ) ? $body['content'] : [];

        // Claude can request several searches in one turn — every tool_use block
        // needs its own tool_result in the next user message.
        $tool_results = [];
        if ( 'tool_use' === ( $body['stop_reason'] ?? '' ) ) {
            foreach ( $content as $block ) {
                if ( 'tool_use' !== ( $block['type'] ?? '' ) ) {
                    continue;
                }
                $tool_args = $block['input'] ?? [];
                if ( ! is_array( $tool_args ) ) {
                    error_log( '[CACB] Claude tool_use input not array: ' . var_export( $tool_args, true ) );
                    $tool_args = [];
                }
                $tool_results[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => $block['id'],
                    'content'     => cacb_execute_search_products( $tool_args ),
                ];
            }
        }

        if ( empty( $tool_results ) ) {
            // Final answer — join all text blocks (there can be more than one)
            $texts = [];
            foreach ( $content as $block ) {
                if ( 'text' === ( $block['type'] ?? '' ) && '' !== trim( (string) ( $block['text'] ?? '' ) ) ) {
                    $texts[] = $block['text'];
                }
            }
            $reply = trim( implode( "\n\n", $texts ) );
            if ( '' === $reply ) {
                error_log( '[CACB] Claude empty reply. model=' . $model . ' stop=' . ( $body['stop_reason'] ?? '' ) );
                return new WP_Error( 'empty_response', __( 'Κενή απάντηση από το AI.', 'smart-ai-chatbot' ), [ 'status' => 502 ] );
            }
            return $reply;
        }

        // json_decode(..., true) turns an empty `input: {}` into [], which would be
        // re-encoded as a JSON array and rejected — restore it as an object.
        foreach ( $content as &$block ) {
            if ( 'tool_use' === ( $block['type'] ?? '' ) && empty( $block['input'] ) ) {
                $block['input'] = new stdClass();
            }
        }
        unset( $block );

        $payload['messages'][] = [ 'role' => 'assistant', 'content' => $content ];
        $payload['messages'][] = [ 'role' => 'user', 'content' => $tool_results ];
    }

    // Unreachable: the last round forces tool_choice 'none'
    return new WP_Error( 'empty_response', __( 'Κενή απάντηση από το AI.', 'smart-ai-chatbot' ), [ 'status' => 502 ] );
}
