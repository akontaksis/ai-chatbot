<?php
defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'cacb_enqueue_assets' );
function cacb_enqueue_assets() {
    wp_enqueue_style(
        'cacb-chat',
        CACB_PLUGIN_URL . 'assets/chat.css',
        [],
        CACB_VERSION
    );

    wp_enqueue_script(
        'cacb-chat',
        CACB_PLUGIN_URL . 'assets/chat.js',
        [],
        CACB_VERSION,
        true // footer
    );

    // Pass PHP config to JS — no sensitive data exposed
    wp_localize_script( 'cacb-chat', 'cacbConfig', [
        'apiUrl'         => esc_url( rest_url( 'cacb/v1/chat' ) ),
        'streamUrl'      => esc_url( admin_url( 'admin-ajax.php' ) ),
        'nonce'          => wp_create_nonce( 'cacb_chat_nonce' ),
        'welcomeMessage' => esc_html( get_option( 'cacb_welcome_message', 'Γεια σας! Πώς μπορώ να σας εξυπηρετήσω;' ) ),
        'errorMessage'   => esc_html__( 'Κάτι πήγε στραβά. Παρακαλώ δοκιμάστε αργότερα.', 'capitano-chatbot' ),
    ] );
}

// ── Inject chat HTML before </body> ──────────────────────────────────────────
add_action( 'wp_footer', 'cacb_render_chat_html' );
function cacb_render_chat_html() {
    $color     = sanitize_hex_color( get_option( 'cacb_primary_color', '#1a1a2e' ) );
    $pos_class = get_option( 'cacb_bubble_position', 'right' ) === 'left' ? 'cacb--left' : 'cacb--right';
    ?>
    <div id="cacb-wrapper" class="<?php echo esc_attr( $pos_class ); ?>" style="--cacb-primary:<?php echo esc_attr( $color ); ?>;" aria-label="<?php esc_attr_e( 'AI Chatbot', 'capitano-chatbot' ); ?>" role="complementary">

        <!-- Bubble button -->
        <button id="cacb-bubble" type="button" aria-label="<?php esc_attr_e( 'Άνοιγμα chatbot', 'capitano-chatbot' ); ?>" aria-expanded="false" aria-controls="cacb-window">
            <svg id="cacb-icon-open" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            <svg id="cacb-icon-close" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true" style="display:none"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>

        <!-- Chat window -->
        <div id="cacb-window" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Chat', 'capitano-chatbot' ); ?>" hidden>
            <div id="cacb-header">
                <span id="cacb-header-dot" aria-hidden="true"></span>
                <span id="cacb-title"><?php echo esc_html( get_option( 'cacb_chat_title', 'Πώς μπορώ να βοηθήσω;' ) ); ?></span>
                <button id="cacb-clear-btn" type="button" aria-label="<?php esc_attr_e( 'Καθαρισμός συνομιλίας', 'capitano-chatbot' ); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                </button>
                <button id="cacb-close-btn" type="button" aria-label="<?php esc_attr_e( 'Κλείσιμο', 'capitano-chatbot' ); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            <?php
            $privacy_notice = get_option( 'cacb_privacy_notice', '' );
            if ( $privacy_notice ) :
                $privacy_url = get_option( 'cacb_privacy_url', '' );
            ?>
            <div id="cacb-privacy">
                <?php echo esc_html( $privacy_notice ); ?>
                <?php if ( $privacy_url ) : ?>
                <a href="<?php echo esc_url( $privacy_url ); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e( 'Πολιτική Απορρήτου', 'capitano-chatbot' ); ?>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div id="cacb-messages" role="log" aria-live="polite" aria-label="<?php esc_attr_e( 'Μηνύματα', 'capitano-chatbot' ); ?>"></div>

            <div id="cacb-typing" hidden aria-label="<?php esc_attr_e( 'Ο βοηθός γράφει', 'capitano-chatbot' ); ?>">
                <span></span><span></span><span></span>
            </div>

            <div id="cacb-input-area">
                <textarea id="cacb-input"
                          rows="1"
                          maxlength="1000"
                          placeholder="<?php esc_attr_e( 'Γράψτε το μήνυμά σας…', 'capitano-chatbot' ); ?>"
                          aria-label="<?php esc_attr_e( 'Μήνυμα', 'capitano-chatbot' ); ?>"></textarea>
                <button id="cacb-send" type="button" aria-label="<?php esc_attr_e( 'Αποστολή', 'capitano-chatbot' ); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                </button>
            </div>
        </div>

    </div>
    <?php
}
