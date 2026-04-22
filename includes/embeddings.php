<?php
/**
 * Smart AI Chatbot — RAG (Retrieval-Augmented Generation) Engine
 *
 * Handles vector embeddings, semantic indexing of WordPress pages/FAQ,
 * cosine-similarity retrieval, and context injection.
 *
 * Embedding provider:
 *   - OpenAI → text-embedding-3-small (1 536 dims)
 *     OpenAI provider uses the main API key; Claude uses the dedicated cacb_rag_openai_key.
 */

defined( 'ABSPATH' ) || exit;

// ═══════════════════════════════════════════════════════════════════════════════
// DATABASE
// ═══════════════════════════════════════════════════════════════════════════════

function cacb_create_embeddings_table(): void {
    global $wpdb;
    $table           = $wpdb->prefix . 'cacb_embeddings';
    $charset_collate = $wpdb->get_charset_collate();

    // chunk_index: 0 for products (single embedding) or 0..n for page chunks.
    // chunk_text:  stores the raw text of this chunk, used directly in RAG context.
    $sql = "CREATE TABLE {$table} (
        id           bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        object_type  varchar(20)         NOT NULL DEFAULT 'product',
        object_id    bigint(20) UNSIGNED NOT NULL,
        chunk_index  smallint(5) UNSIGNED NOT NULL DEFAULT 0,
        chunk_text   mediumtext,
        content_hash char(32)            NOT NULL,
        embedding    longtext            NOT NULL,
        dims         smallint(5) UNSIGNED NOT NULL DEFAULT 1536,
        indexed_at   datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY   idx_object (object_type, object_id, chunk_index),
        KEY          idx_type   (object_type)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

/**
 * Runs once on admin_init to add chunk columns + fix the unique key on existing
 * installations that were created before v1.2.6.
 */
function cacb_maybe_migrate_chunks_schema(): void {
    global $wpdb;
    $table = $wpdb->prefix . 'cacb_embeddings';

    // Bail if the table doesn't exist yet (fresh install, activation hasn't run)
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( ! $exists ) {
        return;
    }

    // phpcs:disable WordPress.DB.DirectDatabaseQuery

    // Ensure chunk_index column exists
    $has_chunk_idx = $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'chunk_index'" );
    if ( ! $has_chunk_idx ) {
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN chunk_index smallint(5) UNSIGNED NOT NULL DEFAULT 0 AFTER object_id" );
    }

    // Ensure chunk_text column exists
    $has_chunk_text = $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'chunk_text'" );
    if ( ! $has_chunk_text ) {
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN chunk_text mediumtext AFTER chunk_index" );
    }

    // Ensure the UNIQUE KEY includes chunk_index. Check the actual column list
    // of idx_object — if it doesn't contain chunk_index, rebuild the index.
    $idx_cols = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'idx_object'" );
    $cols     = array_map( static fn( $r ) => $r->Column_name, $idx_cols ?: [] );
    if ( ! in_array( 'chunk_index', $cols, true ) ) {
        // Clean up any stale rows first (data from pre-migration single-chunk era)
        // so DROP INDEX + ADD UNIQUE doesn't fail on duplicates.
        $wpdb->query( "DELETE FROM {$table}" );
        $wpdb->query( "ALTER TABLE {$table} DROP INDEX idx_object" );
        $wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY idx_object (object_type, object_id, chunk_index)" );
        error_log( '[CACB-RAG] Migrated idx_object to include chunk_index (data cleared for reindex)' );
    }

    // phpcs:enable
}

// ═══════════════════════════════════════════════════════════════════════════════
// PROVIDER DETECTION
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Returns the embedding provider: always 'openai' or 'none'.
 * Claude has no embeddings API — falls back to cacb_rag_openai_key.
 *
 * @return string 'openai'|'none'
 */
function cacb_get_embedding_provider(): string {
    $key = cacb_get_embedding_key();
    return ! empty( $key ) ? 'openai' : 'none';
}

/**
 * Returns the decrypted API key for the embedding provider.
 *
 * @return string
 */
function cacb_get_embedding_key(): string {
    if ( 'claude' === get_option( 'cacb_provider', 'openai' ) ) {
        return cacb_decrypt_key( get_option( 'cacb_rag_openai_key', '' ) );
    }
    return cacb_decrypt_key( get_option( 'cacb_api_key', '' ) );
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
        return new WP_Error( 'no_embed_key', __( 'Δεν υπάρχει διαθέσιμο embedding API key.', 'smart-ai-chatbot' ) );
    }

    // Truncate to stay within API token limits (~8 000 tokens ≈ 32 000 chars)
    $text = mb_substr( wp_strip_all_tags( $text ), 0, 8000 );

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
function cacb_product_to_text( $product, int $desc_limit = 200 ): string {
    $parts = [ $product->get_name() ];

    // Categories — single query via wp_get_post_terms (avoids N+1 on get_term per ID)
    $cat_terms = wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'names' ] );
    if ( ! is_wp_error( $cat_terms ) && ! empty( $cat_terms ) ) {
        $parts[] = 'Κατηγορία: ' . implode( ', ', $cat_terms );
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

    // Attributes (global taxonomy + custom local)
    $attributes = $product->get_attributes();
    foreach ( $attributes as $attribute ) {
        $label = wc_attribute_label( $attribute->get_name(), $product );

        if ( $attribute->is_taxonomy() ) {
            $terms = wp_get_post_terms( $product->get_id(), $attribute->get_name(), [ 'fields' => 'names' ] );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                $parts[] = $label . ': ' . implode( ', ', $terms );
            }
        } else {
            $values = $attribute->get_options();
            if ( ! empty( $values ) ) {
                $parts[] = $label . ': ' . implode( ', ', $values );
            }
        }
    }

    // Description (short preferred, then full)
    $desc = wp_strip_all_tags( $product->get_short_description() );
    if ( ! $desc ) {
        $desc = wp_strip_all_tags( $product->get_description() );
    }
    if ( $desc ) {
        $parts[] = $desc_limit > 0 ? wp_trim_words( $desc, $desc_limit, '' ) : $desc;
    }

    return implode( ' | ', $parts );
}

// ═══════════════════════════════════════════════════════════════════════════════
// CHUNKING
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Splits plain text into overlapping word-based chunks.
 *
 * Default: 200-word chunks with 40-word overlap so context is preserved
 * across chunk boundaries (e.g. a sentence split between two chunks).
 *
 * @param  string $text          Plain text to chunk.
 * @param  int    $chunk_words   Target words per chunk.
 * @param  int    $overlap_words Words repeated from the end of the previous chunk.
 * @return string[]              Array of chunk strings (at least one element).
 */
function cacb_chunk_text( string $text, int $chunk_words = 200, int $overlap_words = 40 ): array {
    $words = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
    $total = count( $words );

    if ( $total === 0 ) {
        return [ $text ];
    }

    // If the whole text fits in one chunk, return it as-is
    if ( $total <= $chunk_words ) {
        return [ implode( ' ', $words ) ];
    }

    $step   = max( 1, $chunk_words - $overlap_words );
    $chunks = [];

    for ( $i = 0; $i < $total; $i += $step ) {
        $slice    = array_slice( $words, $i, $chunk_words );
        $chunks[] = implode( ' ', $slice );

        // Stop when the last word of this chunk reaches the end
        if ( $i + $chunk_words >= $total ) {
            break;
        }
    }

    return $chunks;
}

// ═══════════════════════════════════════════════════════════════════════════════
// INDEXING
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Extracts plain text from a page, with special handling for Elementor pages.
 * Elementor stores content in _elementor_data (JSON) — post_content is empty.
 *
 * @param  int     $post_id
 * @param  WP_Post $post
 * @return string
 */
/**
 * Clean HTML/shortcodes while preserving word boundaries.
 * Replaces tags with spaces so </p><p> doesn't glue adjacent words together.
 */
function cacb_clean_text( string $html ): string {
    // Each preg_replace has a null guard — if PCRE limits are hit on huge text,
    // we fall back to the previous value instead of returning an empty string.
    $out = preg_replace( '/\[elementor-tag[^\]]*\]/i', ' ', $html ) ?? $html;
    $out = strip_shortcodes( $out );
    $stripped = preg_replace( '/<[^>]+>/', ' ', $out );
    if ( null !== $stripped ) {
        $out = $stripped;
    }
    $out = html_entity_decode( $out, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    // Use non-unicode regex first (faster, no PCRE unicode limit issues)
    $collapsed = preg_replace( '/\s+/', ' ', $out );
    if ( null !== $collapsed ) {
        $out = $collapsed;
    }
    return trim( $out );
}

function cacb_extract_page_text( int $post_id, WP_Post $post ): string {
    // Elementor: parse _elementor_data JSON and collect text widgets
    if ( 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
        $raw = get_post_meta( $post_id, '_elementor_data', true );
        if ( $raw ) {
            $data = json_decode( $raw, true );
            if ( is_array( $data ) ) {
                $texts = [];

                // Keys that carry config/style, not content — skip these
                $skip_patterns = [
                    '_css_', '_element_', 'elType', 'widgetType', 'settings',
                    '_border', 'background_', 'typography_', 'margin', 'padding',
                    '_align', '_size', '_color', 'font_', 'animation', 'advanced_',
                    '_transform', 'target', 'source', '_icon', 'html_tag',
                ];

                array_walk_recursive( $data, function ( $value, $key ) use ( &$texts, $skip_patterns ) {
                    if ( ! is_string( $value ) ) return;
                    $trimmed = trim( $value );
                    if ( strlen( $trimmed ) < 10 ) return;                      // too short
                    if ( preg_match( '/^[a-z0-9_\-#\/.]+$/i', $trimmed ) ) return; // IDs, slugs, classes, colors
                    if ( preg_match( '/^https?:\/\//', $trimmed ) ) return;       // URLs

                    // Skip config/style keys
                    $key_str = (string) $key;
                    foreach ( $skip_patterns as $p ) {
                        if ( stripos( $key_str, $p ) !== false ) return;
                    }

                    $clean = cacb_clean_text( $value );
                    // Keep only if we have at least one real word (3+ letters)
                    if ( $clean && preg_match( '/\p{L}{3,}/u', $clean ) ) {
                        $texts[] = $clean;
                    }
                } );
                if ( ! empty( $texts ) ) {
                    return implode( "\n\n", array_unique( $texts ) );
                }
            }
        }
    }

    // Default: standard post_content
    return cacb_clean_text( $post->post_content );
}

/**
 * Indexes (or re-indexes) a single WordPress page using 200-word chunks.
 *
 * Each chunk becomes a separate embedding row so that long pages (e.g. a
 * 1 500-word privacy policy) can be retrieved at the relevant passage level
 * rather than all-or-nothing.
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

    // Skip WooCommerce functional pages — they contain no useful content for RAG.
    // Note: 'privacy-policy' is intentionally NOT in this list so it gets chunked
    // and indexed; users often ask about return/privacy policies.
    $system_slugs = [
        'cart', 'checkout', 'my-account', 'order-received',
        'wishlist', 'login', 'register', 'lost-password', 'sample-page',
    ];
    if ( in_array( $post->post_name, $system_slugs, true ) ) {
        cacb_delete_embedding( 'page', $post_id );
        return true;
    }

    // Also skip WooCommerce built-in page IDs (cart, checkout, my-account, shop)
    $wc_page_ids = [];
    foreach ( [ 'cart', 'checkout', 'myaccount', 'shop' ] as $wc_page ) {
        $id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( $wc_page ) : -1;
        if ( $id > 0 ) $wc_page_ids[] = $id;
    }
    if ( in_array( $post_id, $wc_page_ids, true ) ) {
        cacb_delete_embedding( 'page', $post_id );
        return true;
    }

    $title   = get_the_title( $post_id );
    $content = cacb_extract_page_text( $post_id, $post );

    // Skip pages with too little real content (system/empty pages)
    if ( mb_strlen( trim( $content ) ) < 50 ) {
        cacb_delete_embedding( 'page', $post_id );
        return true;
    }

    // Hash the full content so we can skip re-indexing unchanged pages.
    // We store this hash only on chunk 0 and check it before re-indexing.
    $full_hash = md5( $title . $content );

    global $wpdb;
    $existing_hash = $wpdb->get_var( $wpdb->prepare(
        "SELECT content_hash FROM {$wpdb->prefix}cacb_embeddings
         WHERE object_type = 'page' AND object_id = %d AND chunk_index = 0",
        $post_id
    ) );

    if ( $existing_hash === $full_hash ) {
        return true; // content unchanged — skip API calls
    }

    // Content changed (or first index): delete all existing chunks then re-index
    cacb_delete_embedding( 'page', $post_id );

    // Split full content into 200-word overlapping chunks
    $chunks = cacb_chunk_text( $content, 200, 40 );

    foreach ( $chunks as $idx => $chunk_text ) {
        // Prepend the page title to every chunk so each embedding carries context
        $embed_input = $title . "\n\n" . $chunk_text;

        $embedding = cacb_generate_embedding( $embed_input );
        if ( is_wp_error( $embedding ) ) {
            return $embedding; // stop on first API error; partially indexed is OK
        }

        $json = wp_json_encode( $embedding );
        if ( false === $json ) {
            return new WP_Error( 'json_encode_fail', "Failed to encode embedding for page {$post_id} chunk {$idx}." );
        }

        // REPLACE (not INSERT) gracefully handles cases where a previous
        // partial-index run left stale rows with the same (type, id, chunk_index).
        $wpdb->replace(
            $wpdb->prefix . 'cacb_embeddings',
            [
                'object_type'  => 'page',
                'object_id'    => $post_id,
                'chunk_index'  => $idx,
                'chunk_text'   => $chunk_text,
                'content_hash' => ( 0 === $idx ) ? $full_hash : '',
                'embedding'    => $json,
                'dims'         => count( $embedding ),
                'indexed_at'   => current_time( 'mysql' ),
            ],
            [ '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s' ]
        );
    }

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

    // Fetch all stored vectors (acceptable up to ~2 000 rows in PHP)
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $rows = $wpdb->get_results(
        "SELECT object_type, object_id, chunk_index, chunk_text, embedding FROM {$wpdb->prefix}cacb_embeddings"
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
            'type'        => $row->object_type,
            'id'          => (int) $row->object_id,
            'chunk_index' => (int) $row->chunk_index,
            'chunk_text'  => (string) ( $row->chunk_text ?? '' ),
            'score'       => cacb_cosine_similarity( $query_vec, $vec ),
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
    $threshold  = 0.18;
    $lines      = [ "\n\n---\nΣΧΕΤΙΚΕΣ ΠΛΗΡΟΦΟΡΙΕΣ ΑΠΟ ΤΟ ΚΑΤΑΣΤΗΜΑ:" ];
    $seen_pages = []; // deduplicate: include only the best-scoring chunk per page

    foreach ( $results as $item ) {
        if ( $item['score'] < $threshold ) {
            continue;
        }

        if ( 'page' === $item['type'] ) {
            // Results are sorted by score DESC; first occurrence of a page_id is the best chunk
            if ( isset( $seen_pages[ $item['id'] ] ) ) {
                continue;
            }
            $seen_pages[ $item['id'] ] = true;

            $title = get_the_title( $item['id'] );
            if ( ! $title ) {
                continue;
            }

            // Use the stored chunk text — no need to re-extract from post content
            $chunk = trim( $item['chunk_text'] );
            if ( $chunk === '' ) {
                // Fallback for rows migrated from pre-chunking schema (chunk_text was empty)
                $post  = get_post( $item['id'] );
                $chunk = $post ? wp_trim_words( cacb_extract_page_text( $item['id'], $post ), 200, '...' ) : '';
            }

            if ( $chunk !== '' ) {
                $lines[] = '📄 ' . $title . ': ' . $chunk;
            }
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

    // RAG disabled → no context (products handled via function calling)
    if ( get_option( 'cacb_rag_enabled', '0' ) !== '1' ) {
        return '';
    }

    // Nothing indexed yet → no context
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cacb_embeddings" );
    if ( $count === 0 ) {
        return '';
    }

    // Build a context-aware query from the last 3 messages so the RAG
    // understands follow-up questions ("Και αυτά είναι γλυκά;") correctly.
    $recent = array_slice( $messages, -3 );
    $query  = implode( ' ', array_column( $recent, 'content' ) );
    $query  = trim( $query );

    if ( empty( $query ) ) {
        return '';
    }

    return cacb_rag_build_context( $query );
}

// ═══════════════════════════════════════════════════════════════════════════════
// AUTO-REINDEX HOOKS
// ═══════════════════════════════════════════════════════════════════════════════

// Product embeddings are deprecated in v1.4.x — WooCommerce products are now
// retrieved via function calling (see includes/api.php). We still clean up any
// stale rows from pre-1.4.0 installations when a product is deleted.
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
    // COUNT(DISTINCT object_id) so chunked pages count as one entry each
    $indexed_pages = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT object_id) FROM {$table} WHERE object_type = 'page'" );
    $last_indexed  = $wpdb->get_var( "SELECT indexed_at FROM {$table} WHERE object_type = 'page' ORDER BY indexed_at DESC LIMIT 1" );
    // phpcs:enable

    $total_pages = (int) wp_count_posts( 'page' )->publish;

    $data = [
        'indexed_pages' => $indexed_pages,
        'total_pages'   => $total_pages,
        'last_indexed'  => $last_indexed
            ? human_time_diff( strtotime( $last_indexed ), time() )
            : null,
        'provider'      => cacb_get_embedding_provider(),
    ];
    wp_send_json_success( apply_filters( 'cacb_rag_status_data', $data ) );
}

// ── Add list of indexed pages to the status endpoint response ────────────────
add_filter( 'cacb_rag_status_data', 'cacb_rag_add_indexed_list' );
function cacb_rag_add_indexed_list( array $data ): array {
    global $wpdb;
    $table = $wpdb->prefix . 'cacb_embeddings';

    // Fetch all chunks for all indexed pages, grouped per page
    // phpcs:disable WordPress.DB.DirectDatabaseQuery
    $chunks = $wpdb->get_results(
        "SELECT object_id, chunk_index, chunk_text, content_hash, indexed_at
         FROM {$table}
         WHERE object_type = 'page'
         ORDER BY object_id, chunk_index ASC"
    );
    // phpcs:enable

    // Group chunks by object_id
    $grouped = [];
    foreach ( $chunks as $c ) {
        $grouped[ (int) $c->object_id ][] = $c;
    }

    $total_chunks = 0;
    $items        = [];
    foreach ( $grouped as $id => $page_chunks ) {
        $post = get_post( $id );
        if ( ! $post ) continue;

        // Sort by chunk_index ascending
        usort( $page_chunks, static function ( $a, $b ) {
            return (int) $a->chunk_index <=> (int) $b->chunk_index;
        } );

        // Stored hash is on chunk 0 only
        $stored_hash  = $page_chunks[0]->content_hash ?? '';
        $current_text = cacb_extract_page_text( $id, $post );
        $current_hash = md5( $post->post_title . $current_text );
        $stale        = $stored_hash && $current_hash !== $stored_hash;

        // Strip the leading "Title\n\n" that indexing prepends to chunks
        $title_prefix = '/^' . preg_quote( $post->post_title, '/' ) . "\s*\n+/u";
        $chunk_texts  = array_map( static function ( $c ) use ( $title_prefix ) {
            return trim( (string) preg_replace( $title_prefix, '', (string) $c->chunk_text ) );
        }, $page_chunks );

        $latest        = max( array_map( static fn( $c ) => strtotime( $c->indexed_at ), $page_chunks ) );
        $total_chunks += count( $page_chunks );

        $items[] = [
            'title'       => $post->post_title ?: '(untitled)',
            'url'         => get_permalink( $id ),
            'chunks'      => count( $page_chunks ),
            'chunk_texts' => $chunk_texts,
            'ago'         => human_time_diff( $latest, time() ),
            'stale'       => $stale,
            '_sort'       => $latest,
        ];
    }

    // Sort items by most recent indexing first, then strip sort helper
    usort( $items, static function ( $a, $b ) {
        return $b['_sort'] <=> $a['_sort'];
    } );
    $items = array_map( static function ( $it ) {
        unset( $it['_sort'] );
        return $it;
    }, $items );

    $data['items']        = $items;
    $data['total_chunks'] = $total_chunks;
    return $data;
}

// ── Batch index (pages only — products are retrieved via function calling) ───
add_action( 'wp_ajax_cacb_rag_index_batch', 'cacb_ajax_rag_index_batch' );
function cacb_ajax_rag_index_batch(): void {
    check_ajax_referer( 'cacb_admin_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die();

    $type   = sanitize_key( wp_unslash( $_POST['object_type'] ?? 'page' ) );
    $offset = max( 0, (int) ( $_POST['offset'] ?? 0 ) );

    if ( 'page' !== $type ) {
        wp_send_json_error( 'Invalid object type.' );
    }

    // Pages: up to ~10 chunks each → keep batch small to avoid timeouts
    $batch   = 2;
    $indexed = 0;
    $errors  = [];

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
