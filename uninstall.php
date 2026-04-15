<?php
/**
 * Runs automatically when the plugin is deleted from WP Admin → Plugins.
 * Removes ALL plugin data from the database cleanly.
 */

// WordPress security check — must be present
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// ── Delete all plugin options ─────────────────────────────────────────────────
$options = [
    // OpenAI
    'cacb_api_key',
    'cacb_model',
    // Anthropic Claude
    'cacb_claude_api_key',
    'cacb_claude_model',
    // Google Gemini
    'cacb_gemini_api_key',
    'cacb_gemini_model',
    // Active provider
    'cacb_provider',
    // General settings
    'cacb_max_tokens',
    'cacb_rate_limit',
    'cacb_history_limit',
    'cacb_system_prompt',
    'cacb_chat_title',
    'cacb_welcome_message',
    'cacb_bubble_position',
    'cacb_primary_color',
    'cacb_wc_enabled',
    'cacb_wc_limit',
    'cacb_wc_categories',
    // Logging
    'cacb_logging_enabled',
    'cacb_log_retention',
    'cacb_db_version',
    // Privacy
    'cacb_privacy_notice',
    'cacb_privacy_url',
    // RAG / Knowledge Base
    'cacb_rag_enabled',
    'cacb_rag_top_k',
    'cacb_rag_index_pages',
    'cacb_rag_openai_key',
];

foreach ( $options as $option ) {
    delete_option( $option );
}

// ── Drop plugin tables ────────────────────────────────────────────────────────
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cacb_logs" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cacb_embeddings" );

// ── Delete all rate limit transients ─────────────────────────────────────────
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_cacb_rl_%'
        OR option_name LIKE '_transient_timeout_cacb_rl_%'
        OR option_name = '_transient_cacb_wc_products_cache'
        OR option_name = '_transient_timeout_cacb_wc_products_cache'"
);
