<?php
/**
 * Plugin Name: Capitano AI Chatbot
 * Plugin URI:  https://github.com/akontaksis
 * Description: AI-powered chatbot supporting OpenAI (GPT), Anthropic (Claude), and Google (Gemini). Production-ready with streaming, rate limiting, security, and full admin controls.
 * Version:     1.2.0
 * Author:      Athanasios Kontaksis
 * License:     GPL-2.0+
 * Text Domain: capitano-chatbot
 */

defined( 'ABSPATH' ) || exit;

define( 'CACB_VERSION',     '1.2.0' );
define( 'CACB_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'CACB_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'CACB_PLUGIN_FILE', __FILE__ );

// ── Includes ──────────────────────────────────────────────────────────────────
require_once CACB_PLUGIN_DIR . 'includes/settings.php';
require_once CACB_PLUGIN_DIR . 'includes/embeddings.php';
require_once CACB_PLUGIN_DIR . 'includes/api.php';
require_once CACB_PLUGIN_DIR . 'includes/logs.php';
require_once CACB_PLUGIN_DIR . 'includes/frontend.php';

// ── Activation ────────────────────────────────────────────────────────────────
register_activation_hook( __FILE__, 'cacb_activate' );
function cacb_activate() {
    // Set default options only if they don't already exist
    $defaults = [
        'cacb_model'            => 'gpt-4o-mini',
        'cacb_max_tokens'       => 500,
        'cacb_rate_limit'       => 20,
        'cacb_history_limit'    => 10,
        'cacb_chat_title'       => 'Πώς μπορώ να βοηθήσω;',
        'cacb_welcome_message'  => 'Γεια σας! Είμαι ο βοηθός του καταστήματος. Πώς μπορώ να σας εξυπηρετήσω;',
        'cacb_bubble_position'  => 'right',
        'cacb_primary_color'    => '#1a1a2e',
        'cacb_system_prompt'    => 'Είσαι ένας εξυπηρετικός βοηθός. Απάντα πάντα στα Ελληνικά με φιλικό και επαγγελματικό τόνο. Αν δεν γνωρίζεις κάτι, πες ότι θα επικοινωνήσει μαζί τους η ομάδα.',
        'cacb_wc_enabled'       => '0',
        'cacb_wc_limit'         => 50,
        'cacb_wc_categories'    => '',
        'cacb_provider'         => 'openai',
        'cacb_claude_api_key'   => '',
        'cacb_claude_model'     => 'claude-sonnet-4-6',
        'cacb_gemini_api_key'   => '',
        'cacb_gemini_model'     => 'gemini-2.0-flash',
        'cacb_logging_enabled'  => '1',
        'cacb_log_retention'    => 30,
        'cacb_privacy_notice'   => 'Οι συνομιλίες ενδέχεται να καταγράφονται.',
        'cacb_privacy_url'      => '',
        // RAG / Knowledge Base
        'cacb_rag_enabled'      => '0',
        'cacb_rag_top_k'        => 5,
        'cacb_rag_index_pages'  => '0',
        'cacb_rag_openai_key'   => '',
    ];
    foreach ( $defaults as $key => $value ) {
        if ( false === get_option( $key ) ) {
            add_option( $key, $value );
        }
    }
    cacb_create_logs_table();
    cacb_create_embeddings_table();
}

// ── Deactivation ──────────────────────────────────────────────────────────────
register_deactivation_hook( __FILE__, 'cacb_deactivate' );
function cacb_deactivate() {
    // Nothing to do on deactivation — settings are preserved
}

// ── Handle manual cache clear from settings page ──────────────────────────────
add_action( 'admin_init', 'cacb_maybe_clear_cache' );
function cacb_maybe_clear_cache() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( isset( $_GET['cacb_clear_cache'] ) && '1' === $_GET['cacb_clear_cache'] ) {
        delete_transient( 'cacb_wc_products_cache' );
        wp_safe_redirect( remove_query_arg( 'cacb_clear_cache' ) );
        exit;
    }
}
