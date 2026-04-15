<?php
/**
 * Capitano AI Chatbot — RAG (Retrieval-Augmented Generation) Engine
 *
 * Handles vector embeddings, semantic indexing of WooCommerce products
 * and WordPress pages, cosine-similarity retrieval, and context injection.
 *
 * Embedding providers:
 *   - OpenAI  → text-embedding-3-small (1 536 dims)  — used when chatbot = OpenAI or Claude*
 *   - Gemini  → text-embedding-004     (768  dims)   — used when chatbot = Gemini
 *   * Claude has no embeddings API → requires a separate OpenAI key (cacb_rag_openai_key)
 */

defined( 'ABSPATH' ) || exit;

// ═══════════════════════════════════════════════════════════════════════════════
// DATABASE
// ═══════════════════════════════════════════════════════════════════════════════

function cacb_create_embeddings_table(): void {
    global $wpdb;
    $table           = $wpdb->prefix . 'cacb_embeddings';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id           bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        object_type  varchar(20)         NOT NULL DEFAULT 'product',
        object_id    bigint(20) UNSIGNED NOT NULL,
        content_hash char(32)            NOT NULL,
        embedding    longtext            NOT NULL,
        dims         smallint(5) UNSIGNED NOT NULL DEFAULT 1536,
        indexed_at   datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY   idx_object (object_type, object_id),
        KEY          idx_type   (object_type)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

// ═══════════════════════════════════════════════════════════════════════════════
// PROVIDER DETECTION
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Returns the embedding provider based on the active chat provider.
 * 'openai' | 'gemini' | 'none'
 *
 * @return string
 */
function cacb_get_embedding_provider(): string {
    $chat_provider = get_option( 'cacb_provider', 'openai' );

    if ( 'gemini' === $chat_provider ) {
        $key = cacb_decrypt_key( get_option( 'cacb_gemini_api_key', '' ) );
        return ! empty( $key ) ? 'gemini' : 'none';
    }

    if ( 'claude' === $chat_provider ) {
        // Claude has no embeddings API — fall back to dedicated OpenAI key for RAG
        $rag_key = cacb_decrypt_key( get_option( 'cacb_rag_openai_key', '' ) );
        return ! empty( $rag_key ) ? 'openai' : 'none';
    }

    // OpenAI (default)
    $key = cacb_decrypt_key( get_option( 'cacb_api_key', '' ) );
    return ! empty( $key ) ? 'openai' : 'none';
}

/**
 * Returns the decrypted API key for the embedding provider.
 *
 * @return string
 */
function cacb_get_embedding_key(): string {
    $chat_provider = get_option( 'cacb_provider', 'openai' );

    switch ( $chat_provider ) {
        case 'gemini':
            return cacb_decrypt_key( get_option( 'cacb_gemini_api_key', '' ) );
        case 'claude':
            return cacb_decrypt_key( get_option( 'cacb_rag_openai_key', '' ) );
        default: // openai
            return cacb_decrypt_key( get_option( 'cacb_api_key', '' ) );
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// EMBEDDING GENERATION
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Generates a vector embedding for the given text.
 * Returns float[] on success or WP_Error on failure.
 *
 * @param  string $text
 * @return float[]|WP_Error
 */
function cacb_generate_embedding( string $text ) {
    $provider = cacb_get_embedding_provider();
    $key      = cacb_get_embedding_key();

    if ( 'none' === $provider || empty( $key ) ) {
        return new WP_Error( 'no_embed_key', __( 'Δεν υπάρχει διαθέσιμο embedding API key.', 'capitano-chatbot' ) );
    }

    // Truncate to stay within API token limits (~8 000 tokens ≈ 32 000 chars)
    $text = mb_substr( wp_strip_all_tags( $text ), 0, 8000 );

    if ( 'gemini' === $provider ) {
        return cacb_embed_gemini( $text, $key );
    }

    return cacb_embed_openai( $text, $key );
}

/**
 * OpenAI text-embedding-3-small → 1 536 dimensions.
 *
 * @param  string $text
 * @param  string $key
 * @return float[]|WP_Error
 */
function cacb_embed_openai( string $text, string $key ) {
    $response = wp_remote_post( 'https://api.openai.com/v1/embeddings', [
        'timeout' => 30,
        'headers' => [
            'Authorization' => 'Bearer ' . $key,
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode( [
            'model' => 'text-embedding-3-small',
            'input' => $text,
        ] ),
    ] );

    if ( is_wp_error( $response ) ) {
        error_log( '[CACB-RAG] OpenAI embed connection error: ' . $response->get_error_message() );
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( 200 !== $code ) {
        $err = $body['error']['message'] ?? "HTTP {$code}";
        error_log( "[CACB-RAG] OpenAI embed error {$code}: {$err}" );
        return new WP_Error( 'openai_embed', $err );
    }

    $vec = $body['data'][0]['embedding'] ?? null;
    if ( ! is_array( $vec ) ) {
        return new WP_Error( 'openai_embed_empty', 'Empty embedding returned.' );
    }

    return $vec;
}

/**
 * Google Gemini text-embedding-004 → 768 dimensions.
 *
 * @param  string $text
 * @param  string $key
 * @return float[]|WP_Error
 */
function cacb_embed_gemini( string $text, string $key ) {
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/text-embedding-004:embedContent?key='
        . rawurlencode( $key );

    $response = wp_remote_post( $url, [
        'timeout' => 30,
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode( [
            'model'   => 'models/text-embedding-004',
            'content' => [ 'parts' => [ [ 'text' => $text ] ] ],
        ] ),
    ] );

    if ( is_wp_error( $response ) ) {
        error_log( '[CACB-RAG] Gemini embed connection error: ' . $response->get_error_message() );
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( 200 !== $code ) {
        $err = $body['error']['message'] ?? "HTTP {$code}";
        error_log( "[CACB-RAG] Gemini embed error {$code}: {$err}" );
        return new WP_Error( 'gemini_embed', $err );
    }

    $vec = $body['embedding']['values'] ?? null;
    if ( ! is_array( $vec ) ) {
        return new WP_Error( 'gemini_embed_empty', 'Empty embedding returned.' );
    }

    return $vec;
}

// ═══════════════════════════════════════════════════════════════════════════════
// COSINE SIMILARITY
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Computes cosine similarity between two equal-length float vectors.
 * Returns a value in [-1, 1]; higher = more similar.
 *
 * @param  float[] $a
 * @param  float[] $b
 * @return float
 */
function cacb_cosine_similarity( array $a, array $b ): float {
    $dot  = 0.0;
    $magA = 0.0;
    $magB = 0.0;
    $n    = min( count( $a ), count( $b ) );

    for ( $i = 0; $i < $n; $i++ ) {
        $dot  += $a[ $i ] * $b[ $i ];
        $magA += $a[ $i ] * $a[ $i ];
        $magB += $b[ $i ] * $b[ $i ];
    }

    $denom = sqrt( $magA ) * sqrt( $magB );
    return $denom > 0.0 ? (float) ( $dot / $denom ) : 0.0;
}

// ═══════════════════════════════════════════════════════════════════════════════
// TEXT SERIALIZATION
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Converts a WooCommerce product to a flat text string for embedding.
 *
 * @param  WC_Product $product
 * @return string
 */
function cacb_product_to_text( $product ): string {
    $parts = [ $product->get_name() ];

    // Categories
    $cat_ids = $product->get_category_ids();
    $cats    = [];
    foreach ( $cat_ids as $cat_id ) {
        $term = get_term( $cat_id, 'product_cat' );
        if ( $term && ! is_wp_error( $term ) ) {
            $cats[] = $term->name;
        }
    }
    if ( $cats ) {
        $parts[] = 'Κατηγορία: ' . implode( ', ', $cats );
    }

    // Price & stock
    $price = $product->get_price();
    if ( $price ) {
        $parts[] = 'Τιμή: ' . $price . '€';
    }
    $parts[] = $product->is_in_stock() ? 'Διαθέσιμο' : 'Μη διαθέσιμο';

    // SKU
    $sku = $product->get_sku();
    if ( $sku ) {
        $parts[] = 'SKU: ' . $sku;
    }

    // Description (short preferred, then full)
    $desc = wp_strip_all_tags( $product->get_short_description() );
    if ( ! $desc ) {
        $desc = wp_strip_all_tags( $product->get_description() );
    }
    if ( $desc ) {
        $parts[] = wp_trim_words( $desc, 100, '' );
    }

    return implode( ' | ', $parts );
}

// ═══════════════════════════════════════════════════════════════════════════════
// INDEXING
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Indexes (or re-indexes) a single WooCommerce product.
 * Skips silently if content hash is unchanged.
 * Removes the row if the product no longer exists or is not published.
 *
 * @param  int $product_id
 * @return true|WP_Error
 */
function cacb_index_product( int $product_id ) {
    if ( ! function_exists( 'wc_get_product' ) ) {
        return new WP_Error( 'no_wc', 'WooCommerce not active.' );
    }

    $product = wc_get_product( $product_id );
    if ( ! $product || 'publish' !== $product->get_status() ) {
        cacb_delete_embedding( 'product', $product_id );
        return true;
    }

    $text = cacb_product_to_text( $product );
    $hash = md5( $text );

    global $wpdb;
    $existing_hash = $wpdb->get_var( $wpdb->prepare(
        "SELECT content_hash FROM {$wpdb->prefix}cacb_embeddings WHERE object_type = 'product' AND object_id = %d",
        $product_id
    ) );

    // Skip API call if content is unchanged
    if ( $existing_hash === $hash ) {
        return true;
    }

    $embedding = cacb_generate_embedding( $text );
    if ( is_wp_error( $embedding ) ) {
        return $embedding;
    }

    $json = wp_json_encode( $embedding );
    if ( false === $json ) {
        return new WP_Error( 'json_encode_fail', "Failed to encode embedding for product {$product_id}." );
    }

    $wpdb->replace(
        $wpdb->prefix . 'cacb_embeddings',
        [
            'object_type'  => 'product',
            'object_id'    => $product_id,
            'content_hash' => $hash,
            'embedding'    => $json,
            'dims'         => count( $embedding ),
            'indexed_at'   => current_time( 'mysql' ),
        ],
        [ '%s', '%d', '%s', '%s', '%d', '%s' ]
    );

    return true;
}

/**
 * Indexes (or re-indexes) a single WordPress page.
 *
 * @param  int $post_id
 * @return true|WP_Error
 */
function cacb_index_page( int $post_id ) {
    $post = get_post( $post_id );

    if ( ! $post || 'publish' !== $post->post_status ) {
        cacb_delete_embedding( 'page', $post_id );
        return true;
    }

    $title   = get_the_title( $post_id );
    $content = wp_strip_all_tags( $post->post_content );
    // Limit content to ~4 000 chars (~1 000 tokens) to stay within limits
    $text    = $title . "\n\n" . mb_substr( $content, 0, 4000 );
    $hash    = md5( $text );

    global $wpdb;
    $existing_hash = $wpdb->get_var( $wpdb->prepare(
        "SELECT content_hash FROM {$wpdb->prefix}cacb_embeddings WHERE object_type = 'page' AND object_id = %d",
        $post_id
    ) );

    if ( $existing_hash === $hash ) {
        return true;
    }

    $embedding = cacb_generate_embedding( $text );
    if ( is_wp_error( $embedding ) ) {
        return $embedding;
    }

    $json = wp_json_encode( $embedding );
    if ( false === $json ) {
        return new WP_Error( 'json_encode_fail', "Failed to encode embedding for page {$post_id}." );
    }

    $wpdb->replace(
        $wpdb->prefix . 'cacb_embeddings',
        [
            'object_type'  => 'page',
            'object_id'    => $post_id,
            'content_hash' => $hash,
            'embedding'    => $json,
            'dims'         => count( $embedding ),
            'indexed_at'   => current_time( 'mysql' ),
        ],
        [ '%s', '%d', '%s', '%s', '%d', '%s' ]
    );

    return true;
}

/**
 * Removes a single object's embedding from the index.
 *
 * @param  string $type  'product' | 'page'
 * @param  int    $id
 * @return void
 */
function cacb_delete_embedding( string $type, int $id ): void {
    global $wpdb;
    $wpdb->delete(
        $wpdb->prefix . 'cacb_embeddings',
        [ 'object_type' => $type, 'object_id' => $id ],
        [ '%s', '%d' ]
    );
}

// ═══════════════════════════════════════════════════════════════════════════════
// RETRIEVAL
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Embeds a query string and returns the top-K most similar indexed items,
 * sorted by descending cosine similarity score.
 *
 * @param  string $query
 * @param  int    $top_k
 * @return array  [ ['type' => string, 'id' => int, 'score' => float], ... ]
 */
function cacb_rag_retrieve( string $query, int $top_k = 5 ): array {
    global $wpdb;

    $query_vec = cacb_generate_embedding( $query );
    if ( is_wp_error( $query_vec ) ) {
        error_log( '[CACB-RAG] Query embedding failed: ' . $query_vec->get_error_message() );
        return [];
    }

    // Fetch all stored vectors (acceptable up to ~1 000 items in PHP)
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $rows = $wpdb->get_results(
        "SELECT object_type, object_id, embedding FROM {$wpdb->prefix}cacb_embeddings"
    );

    if ( empty( $rows ) ) {
        return [];
    }

    $scores = [];
    foreach ( $rows as $row ) {
        $vec = json_decode( $row->embedding, true );
        if ( ! is_array( $vec ) ) {
            continue;
        }
        $scores[] = [
            'type'  => $row->object_type,
            'id'    => (int) $row->object_id,
            'score' => cacb_cosine_similarity( $query_vec, $vec ),
        ];
    }

    // Sort descending by similarity
    usort( $scores, static function ( array $a, array $b ): int {
        return $b['score'] <=> $a['score'];
    } );

    return array_slice( $scores, 0, $top_k );
}

/**
 * Builds a context string from RAG results to be appended to the system prompt.
 * Returns an empty string if RAG is disabled, nothing is indexed, or all
 * results fall below the relevance threshold.
 *
 * @param  string $query  Last user message.
 * @return string
 */
function cacb_rag_build_context( string $query ): string {
    $top_k   = max( 1, min( 10, (int) get_option( 'cacb_rag_top_k', 5 ) ) );
    $results = cacb_rag_retrieve( $query, $top_k );

    if ( empty( $results ) ) {
        return '';
    }

    // Minimum relevance threshold — filters out completely unrelated items
    $threshold = 0.25;
    $lines     = [ "\n\n---\nΣΧΕΤΙΚΕΣ ΠΛΗΡΟΦΟΡΙΕΣ ΑΠΟ ΤΟ ΚΑΤΑΣΤΗΜΑ:" ];

    foreach ( $results as $item ) {
        if ( $item['score'] < $threshold ) {
            continue;
        }

        if ( 'product' === $item['type'] && function_exists( 'wc_get_product' ) ) {
            $product = wc_get_product( $item['id'] );
            if ( ! $product ) {
                continue;
            }

            $line = sprintf(
                '• %s | %s€ | %s | SKU: %s',
                $product->get_name(),
                $product->get_price() ?: 'N/A',
                $product->is_in_stock() ? 'Διαθέσιμο' : 'Μη διαθέσιμο',
                $product->get_sku() ?: 'N/A'
            );
            $desc = wp_strip_all_tags( $product->get_short_description() );
            if ( $desc ) {
                $line .= ' — ' . wp_trim_words( $desc, 20, '...' );
            }
            $lines[] = $line;

        } elseif ( 'page' === $item['type'] ) {
            $post = get_post( $item['id'] );
            if ( ! $post ) {
                continue;
            }
            $excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 60, '...' );
            $lines[] = '📄 ' . get_the_title( $item['id'] ) . ': ' . $excerpt;
        }
    }

    // Only the header was added — no results above threshold
    if ( count( $lines ) <= 1 ) {
        return '';
    }

    $lines[] = '---';
    return implode( "\n", $lines );
}

// ═══════════════════════════════════════════════════════════════════════════════
// SMART CONTEXT — called from api.php
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Entry point used by the chat handlers.
 * Chooses RAG or the legacy "stuff all products" strategy automatically.
 *
 * @param  array $messages  Sanitized client messages (role/content pairs).
 * @return string           Context string to append to the system prompt.
 */
function cacb_get_smart_context( array $messages ): string {

    // RAG disabled → fall back to old method
    if ( get_option( 'cacb_rag_enabled', '0' ) !== '1' ) {
        return cacb_get_wc_product_context();
    }

    // Nothing indexed yet → fall back gracefully
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cacb_embeddings" );
    if ( $count === 0 ) {
        return cacb_get_wc_product_context();
    }

    // Extract the last user message as the RAG query
    $query = '';
    foreach ( array_reverse( $messages ) as $msg ) {
        if ( 'user' === $msg['role'] ) {
            $query = $msg['content'];
            break;
        }
    }

    if ( empty( $query ) ) {
        return cacb_get_wc_product_context();
    }

    $context = cacb_rag_build_context( $query );

    // If RAG returned nothing useful, fall back to ensure context is always provided
    return $context !== '' ? $context : cacb_get_wc_product_context();
}

// ═══════════════════════════════════════════════════════════════════════════════
// AUTO-REINDEX HOOKS
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'woocommerce_update_product', 'cacb_auto_reindex_product' );
add_action( 'woocommerce_new_product',    'cacb_auto_reindex_product' );
function cacb_auto_reindex_product( int $product_id ): void {
    if ( get_option( 'cacb_rag_enabled', '0' ) !== '1' ) {
        return;
    }
    // Fire in background via WP-Cron to avoid slowing down the product save
    wp_schedule_single_event( time(), 'cacb_async_index_product', [ $product_id ] );
}

add_action( 'cacb_async_index_product', 'cacb_index_product' );

add_action( 'woocommerce_delete_product', 'cacb_auto_delete_product_embed' );
function cacb_auto_delete_product_embed( int $product_id ): void {
    cacb_delete_embedding( 'product', $product_id );
}

add_action( 'save_post_page', 'cacb_auto_reindex_page_hook', 10, 3 );
function cacb_auto_reindex_page_hook( int $post_id, $post, bool $update ): void {
    if ( get_option( 'cacb_rag_enabled', '0' ) !== '1' )       return;
    if ( get_option( 'cacb_rag_index_pages', '0' ) !== '1' )   return;
    if ( wp_is_post_revision( $post_id ) )                      return;
    if ( 'publish' !== $post->post_status )                     return;
    wp_schedule_single_event( time(), 'cacb_async_index_page', [ $post_id ] );
}

add_action( 'cacb_async_index_page', 'cacb_index_page' );

add_action( 'before_delete_post', 'cacb_auto_delete_page_embed' );
function cacb_auto_delete_page_embed( int $post_id ): void {
    if ( 'page' === get_post_type( $post_id ) ) {
        cacb_delete_embedding( 'page', $post_id );
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// AJAX — ADMIN
// ═══════════════════════════════════════════════════════════════════════════════

// ── Index status ──────────────────────────────────────────────────────────────
add_action( 'wp_ajax_cacb_rag_status', 'cacb_ajax_rag_status' );
function cacb_ajax_rag_status(): void {
    check_ajax_referer( 'cacb_admin_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die();

    global $wpdb;
    $table = $wpdb->prefix . 'cacb_embeddings';

    // phpcs:disable WordPress.DB.DirectDatabaseQuery
    $indexed_products = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE object_type = 'product'" );
    $indexed_pages    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE object_type = 'page'" );
    $last_indexed     = $wpdb->get_var( "SELECT indexed_at FROM {$table} ORDER BY indexed_at DESC LIMIT 1" );
    // phpcs:enable

    $total_products = 0;
    if ( function_exists( 'wc_get_products' ) ) {
        $total_products = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}posts
             WHERE post_type = 'product' AND post_status = 'publish'"
        );
    }

    $total_pages = (int) wp_count_posts( 'page' )->publish;

    wp_send_json_success( [
        'indexed_products' => $indexed_products,
        'indexed_pages'    => $indexed_pages,
        'total_products'   => $total_products,
        'total_pages'      => $total_pages,
        'last_indexed'     => $last_indexed
            ? human_time_diff( strtotime( $last_indexed ), time() )
            : null,
        'provider'         => cacb_get_embedding_provider(),
    ] );
}

// ── Batch index ───────────────────────────────────────────────────────────────
add_action( 'wp_ajax_cacb_rag_index_batch', 'cacb_ajax_rag_index_batch' );
function cacb_ajax_rag_index_batch(): void {
    check_ajax_referer( 'cacb_admin_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die();

    $type   = sanitize_key( wp_unslash( $_POST['object_type'] ?? 'product' ) );
    $offset = max( 0, (int) ( $_POST['offset'] ?? 0 ) );
    $batch  = 5; // small batch to stay within PHP execution time

    $indexed = 0;
    $errors  = [];

    if ( 'product' === $type && function_exists( 'wc_get_products' ) ) {

        $ids = wc_get_products( [
            'limit'   => $batch,
            'offset'  => $offset,
            'status'  => 'publish',
            'return'  => 'ids',
            'orderby' => 'ID',
            'order'   => 'ASC',
        ] );

        foreach ( $ids as $id ) {
            $result = cacb_index_product( (int) $id );
            is_wp_error( $result ) ? $errors[] = $result->get_error_message() : $indexed++;
        }

        global $wpdb;
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}posts
             WHERE post_type = 'product' AND post_status = 'publish'"
        );

        wp_send_json_success( [
            'indexed' => $indexed,
            'offset'  => $offset + count( $ids ),
            'done'    => count( $ids ) < $batch,
            'total'   => $total,
            'errors'  => $errors,
        ] );

    } elseif ( 'page' === $type ) {

        $posts = get_posts( [
            'post_type'   => 'page',
            'post_status' => 'publish',
            'numberposts' => $batch,
            'offset'      => $offset,
            'fields'      => 'ids',
            'orderby'     => 'ID',
            'order'       => 'ASC',
        ] );

        foreach ( $posts as $id ) {
            $result = cacb_index_page( (int) $id );
            is_wp_error( $result ) ? $errors[] = $result->get_error_message() : $indexed++;
        }

        $total = (int) wp_count_posts( 'page' )->publish;

        wp_send_json_success( [
            'indexed' => $indexed,
            'offset'  => $offset + count( $posts ),
            'done'    => count( $posts ) < $batch,
            'total'   => $total,
            'errors'  => $errors,
        ] );

    } else {
        wp_send_json_error( 'Invalid object type.' );
    }
}

// ── Clear index ───────────────────────────────────────────────────────────────
add_action( 'wp_ajax_cacb_rag_clear', 'cacb_ajax_rag_clear' );
function cacb_ajax_rag_clear(): void {
    check_ajax_referer( 'cacb_admin_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die();

    global $wpdb;
    $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}cacb_embeddings" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    wp_send_json_success();
}
