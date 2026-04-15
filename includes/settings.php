<?php
defined( 'ABSPATH' ) || exit;

// ── Register settings & admin menu ───────────────────────────────────────────
add_action( 'admin_menu', 'cacb_admin_menu' );
function cacb_admin_menu() {
    add_options_page(
        __( 'AI Chatbot Settings', 'capitano-chatbot' ),
        __( 'AI Chatbot', 'capitano-chatbot' ),
        'manage_options',
        'capitano-chatbot',
        'cacb_settings_page'
    );
}

add_action( 'admin_init', 'cacb_register_settings' );
function cacb_register_settings() {
    $fields = [
        'cacb_api_key',
        'cacb_model',
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
        'cacb_provider',
        'cacb_claude_api_key',
        'cacb_claude_model',
        'cacb_gemini_api_key',
        'cacb_gemini_model',
        'cacb_logging_enabled',
        'cacb_log_retention',
        'cacb_privacy_notice',
        'cacb_privacy_url',
        // RAG / Knowledge Base
        'cacb_rag_enabled',
        'cacb_rag_top_k',
        'cacb_rag_index_pages',
        'cacb_rag_openai_key',
    ];
    foreach ( $fields as $field ) {
        register_setting( 'cacb_settings_group', $field, [
            'sanitize_callback' => 'cacb_sanitize_option',
        ] );
    }
}

// ── API key encryption (AES-256-GCM — authenticated encryption) ───────────────
// Returns null on failure so the caller can keep the existing stored value.
function cacb_encrypt_key( string $plain ): ?string {
    if ( ! function_exists( 'openssl_encrypt' ) ) {
        error_log( '[CACB] openssl extension unavailable — API key cannot be encrypted' );
        return null;
    }
    if ( ! defined( 'AUTH_KEY' ) || ! defined( 'SECURE_AUTH_KEY' ) ) {
        error_log( '[CACB] WordPress secret keys not defined — API key cannot be encrypted' );
        return null;
    }

    $key   = hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true ); // 32 raw bytes
    $nonce = random_bytes( 12 );  // 96-bit nonce for GCM
    $tag   = '';
    $enc   = openssl_encrypt( $plain, 'AES-256-GCM', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16 );

    if ( false === $enc ) {
        error_log( '[CACB] openssl_encrypt (GCM) failed unexpectedly' );
        return null;
    }

    // Format: nonce(12) + tag(16) + ciphertext
    return 'cacb_enc2:' . base64_encode( $nonce . $tag . $enc );
}

function cacb_decrypt_key( string $stored ): string {
    if ( ! function_exists( 'openssl_decrypt' ) )          return '';
    if ( ! defined( 'AUTH_KEY' ) || ! defined( 'SECURE_AUTH_KEY' ) ) return '';

    $key = hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true );

    // Current format: AES-256-GCM (authenticated — tamper-proof)
    if ( strpos( $stored, 'cacb_enc2:' ) === 0 ) {
        $raw = base64_decode( substr( $stored, 10 ) );
        if ( strlen( $raw ) < 29 ) return ''; // nonce(12) + tag(16) + min 1 byte ciphertext
        $nonce = substr( $raw, 0, 12 );
        $tag   = substr( $raw, 12, 16 );
        $enc   = substr( $raw, 28 );
        $dec   = openssl_decrypt( $enc, 'AES-256-GCM', $key, OPENSSL_RAW_DATA, $nonce, $tag );
        return $dec !== false ? $dec : '';
    }

    // Legacy format: AES-256-CBC (backwards compatibility — re-encrypted on next save)
    if ( strpos( $stored, 'cacb_enc:' ) === 0 ) {
        $raw = base64_decode( substr( $stored, 9 ) );
        if ( strlen( $raw ) < 17 ) return ''; // iv(16) + min 1 byte ciphertext
        $iv  = substr( $raw, 0, 16 );
        $enc = substr( $raw, 16 );
        $dec = openssl_decrypt( $enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        return $dec !== false ? $dec : '';
    }

    // Plain text (stored before any encryption existed) — returned as-is
    return $stored;
}

// ── Sanitize all options ──────────────────────────────────────────────────────
function cacb_sanitize_option( $value ) {
    $filter = current_filter();

    // All API key fields: encrypt on save, keep existing if left empty
    $api_key_options = [ 'cacb_api_key', 'cacb_claude_api_key', 'cacb_gemini_api_key', 'cacb_rag_openai_key' ];
    foreach ( $api_key_options as $opt ) {
        if ( strpos( $filter, $opt ) !== false ) {
            $value = sanitize_text_field( $value );
            if ( $value === '' ) {
                return get_option( $opt, '' ); // keep existing encrypted value
            }
            $encrypted = cacb_encrypt_key( $value );
            if ( null === $encrypted ) {
                // Encryption unavailable — keep existing value and warn admin
                add_settings_error(
                    $opt,
                    'cacb_encrypt_fail',
                    __( 'API key δεν μπόρεσε να κρυπτογραφηθεί. Βεβαιωθείτε ότι η PHP έχει ενεργοποιημένη την openssl extension.', 'capitano-chatbot' )
                );
                return get_option( $opt, '' );
            }
            return $encrypted;
        }
    }

    // Textarea fields — allow newlines, strip all HTML
    if ( strpos( $filter, 'system_prompt' ) !== false ||
         strpos( $filter, 'welcome'       ) !== false ) {
        return wp_kses( $value, [] );
    }

    // Boolean toggles — always store '1' or '0'
    $bool_options = [ 'cacb_logging_enabled', 'cacb_rag_enabled', 'cacb_rag_index_pages' ];
    foreach ( $bool_options as $opt ) {
        if ( strpos( $filter, $opt ) !== false ) {
            return '1' === $value ? '1' : '0';
        }
    }

    // Retention days — integer between 1 and 365
    if ( strpos( $filter, 'cacb_log_retention' ) !== false ) {
        return (string) max( 1, min( 365, (int) $value ) );
    }

    // RAG top-K — integer between 1 and 10
    if ( strpos( $filter, 'cacb_rag_top_k' ) !== false ) {
        return (string) max( 1, min( 10, (int) $value ) );
    }

    // Privacy policy URL — sanitize as URL
    if ( strpos( $filter, 'cacb_privacy_url' ) !== false ) {
        return esc_url_raw( $value );
    }

    return sanitize_text_field( $value );
}

// ── Helper: get option with fallback ─────────────────────────────────────────
function cacb_get( $key, $fallback = '' ) {
    return get_option( $key, $fallback );
}

// ── Enqueue admin assets on plugin settings page only ─────────────────────────
add_action( 'admin_enqueue_scripts', 'cacb_admin_enqueue' );
function cacb_admin_enqueue( string $hook ): void {
    if ( 'settings_page_capitano-chatbot' !== $hook ) return;
    wp_enqueue_script(
        'cacb-admin',
        CACB_PLUGIN_URL . 'assets/admin.js',
        [ 'jquery' ],
        CACB_VERSION,
        true
    );
    wp_localize_script( 'cacb-admin', 'cacbAdmin', [
        'nonce'   => wp_create_nonce( 'cacb_admin_nonce' ),
        'ajaxUrl' => esc_url( admin_url( 'admin-ajax.php' ) ),
        'i18n'    => [
            'testing'           => __( 'Δοκιμή…', 'capitano-chatbot' ),
            'testBtn'           => __( 'Δοκιμή', 'capitano-chatbot' ),
            'confirmDelete'     => __( 'Διαγραφή API key; Θα χρειαστεί να το ξαναβάλεις για να λειτουργεί το chatbot.', 'capitano-chatbot' ),
            'indexing'          => __( 'Ευρετηρίαση…', 'capitano-chatbot' ),
            'indexDone'         => __( 'Ολοκληρώθηκε!', 'capitano-chatbot' ),
            'indexError'        => __( 'Σφάλμα κατά την ευρετηρίαση.', 'capitano-chatbot' ),
            'confirmClearIndex' => __( 'Διαγραφή ολόκληρου του index; Θα χρειαστεί να ξανακάνεις ευρετηρίαση.', 'capitano-chatbot' ),
            'cleared'           => __( 'Index διαγράφηκε.', 'capitano-chatbot' ),
        ],
    ] );
}

// ── Settings page HTML ────────────────────────────────────────────────────────
function cacb_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Access denied.', 'capitano-chatbot' ) );
    }
    $valid_tabs = [ 'settings', 'rag', 'logs' ];
    $active_tab = ( isset( $_GET['tab'] ) && in_array( $_GET['tab'], $valid_tabs, true ) )
        ? sanitize_key( $_GET['tab'] )
        : 'settings';
    ?>
    <div class="wrap cacb-admin">
        <h1>🤖 <?php esc_html_e( 'AI Chatbot Settings', 'capitano-chatbot' ); ?></h1>

        <?php settings_errors(); ?>

        <nav class="nav-tab-wrapper" style="margin-bottom:20px">
            <a href="<?php echo esc_url( add_query_arg( 'tab', 'settings' ) ); ?>"
               class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>">
                ⚙️ <?php esc_html_e( 'Ρυθμίσεις', 'capitano-chatbot' ); ?>
            </a>
            <a href="<?php echo esc_url( add_query_arg( 'tab', 'rag' ) ); ?>"
               class="nav-tab <?php echo 'rag' === $active_tab ? 'nav-tab-active' : ''; ?>">
                🧠 <?php esc_html_e( 'Knowledge Base', 'capitano-chatbot' ); ?>
            </a>
            <a href="<?php echo esc_url( add_query_arg( 'tab', 'logs' ) ); ?>"
               class="nav-tab <?php echo 'logs' === $active_tab ? 'nav-tab-active' : ''; ?>">
                📋 <?php esc_html_e( 'Logs', 'capitano-chatbot' ); ?>
            </a>
        </nav>

        <?php if ( 'logs' === $active_tab ) :
            cacb_render_logs_page();
        elseif ( 'rag' === $active_tab ) :
            cacb_render_rag_page();
        else : ?>

        <form method="post" action="options.php">
            <?php settings_fields( 'cacb_settings_group' ); ?>

            <div class="cacb-grid">

                <!-- ── Provider Selector ── -->
                <div class="cacb-card cacb-card--full">
                    <h2>🤖 AI Provider</h2>
                    <p class="description"><?php esc_html_e( 'Επίλεξε ποιον AI πάροχο θέλεις να χρησιμοποιεί το chatbot. Το αντίστοιχο API key πρέπει να είναι συμπληρωμένο παρακάτω.', 'capitano-chatbot' ); ?></p>
                    <label><?php esc_html_e( 'Ενεργός Provider', 'capitano-chatbot' ); ?></label>
                    <select name="cacb_provider" id="cacb_provider">
                        <?php
                        $providers = [
                            'openai' => 'OpenAI (GPT)',
                            'claude' => 'Anthropic (Claude)',
                            'gemini' => 'Google (Gemini)',
                        ];
                        $current_provider = cacb_get( 'cacb_provider', 'openai' );
                        foreach ( $providers as $val => $label ) {
                            printf(
                                '<option value="%s" %s>%s</option>',
                                esc_attr( $val ),
                                selected( $current_provider, $val, false ),
                                esc_html( $label )
                            );
                        }
                        ?>
                    </select>
                </div>

                <!-- ── OpenAI ── -->
                <div class="cacb-card cacb-card--provider" data-provider="openai">
                    <h2>🔑 OpenAI</h2>

                    <label><?php esc_html_e( 'API Key', 'capitano-chatbot' ); ?></label>
                    <?php $has_key = ! empty( cacb_get( 'cacb_api_key' ) ); ?>
                    <input type="password"
                           name="cacb_api_key"
                           value=""
                           class="regular-text"
                           autocomplete="new-password"
                           placeholder="<?php echo esc_attr( $has_key ? '••••••••••••••••' : 'sk-...' ); ?>" />
                    <p class="description">
                        <?php if ( $has_key ) : ?>
                            ✅ <?php esc_html_e( 'API key αποθηκευμένο κρυπτογραφημένα (AES-256). Άφησε κενό για να το κρατήσεις ή γράψε νέο για να το αντικαταστήσεις.', 'capitano-chatbot' ); ?>
                        <?php else : ?>
                            <?php esc_html_e( 'Θα αποθηκευτεί κρυπτογραφημένο. Μην το μοιράζεσαι.', 'capitano-chatbot' ); ?>
                        <?php endif; ?>
                    </p>

                    <label><?php esc_html_e( 'Model', 'capitano-chatbot' ); ?></label>
                    <select name="cacb_model">
                        <?php
                        $models = [
                            'gpt-4o-mini' => 'GPT-4o Mini (Γρήγορο & φθηνό — προτεινόμενο)',
                            'gpt-4o'      => 'GPT-4o (Πιο έξυπνο, πιο ακριβό)',
                            'gpt-3.5-turbo' => 'GPT-3.5 Turbo (Φθηνότατο)',
                        ];
                        $current = cacb_get( 'cacb_model', 'gpt-4o-mini' );
                        foreach ( $models as $val => $label ) {
                            printf(
                                '<option value="%s" %s>%s</option>',
                                esc_attr( $val ),
                                selected( $current, $val, false ),
                                esc_html( $label )
                            );
                        }
                        ?>
                    </select>

                    <div class="cacb-key-actions">
                        <button type="button" class="button cacb-test-key" data-provider="openai">
                            🔍 <?php esc_html_e( 'Δοκιμή', 'capitano-chatbot' ); ?>
                        </button>
                        <?php if ( $has_key ) : ?>
                        <button type="button" class="button cacb-delete-key cacb-btn-danger" data-option="cacb_api_key">
                            🗑 <?php esc_html_e( 'Διαγραφή κλειδιού', 'capitano-chatbot' ); ?>
                        </button>
                        <?php endif; ?>
                        <span id="cacb-test-status-openai" class="cacb-test-status"></span>
                    </div>
                </div>

                <!-- ── Claude (Anthropic) ── -->
                <div class="cacb-card cacb-card--provider" data-provider="claude">
                    <h2>🟣 Anthropic (Claude)</h2>

                    <label><?php esc_html_e( 'API Key', 'capitano-chatbot' ); ?></label>
                    <?php $has_claude_key = ! empty( cacb_get( 'cacb_claude_api_key' ) ); ?>
                    <input type="password"
                           name="cacb_claude_api_key"
                           value=""
                           class="regular-text"
                           autocomplete="new-password"
                           placeholder="<?php echo esc_attr( $has_claude_key ? '••••••••••••••••' : 'sk-ant-...' ); ?>" />
                    <p class="description">
                        <?php if ( $has_claude_key ) : ?>
                            ✅ <?php esc_html_e( 'API key αποθηκευμένο κρυπτογραφημένα (AES-256). Άφησε κενό για να το κρατήσεις.', 'capitano-chatbot' ); ?>
                        <?php else : ?>
                            <?php esc_html_e( 'Θα αποθηκευτεί κρυπτογραφημένο. Μην το μοιράζεσαι.', 'capitano-chatbot' ); ?>
                        <?php endif; ?>
                    </p>

                    <label><?php esc_html_e( 'Model', 'capitano-chatbot' ); ?></label>
                    <select name="cacb_claude_model">
                        <?php
                        $claude_models = [
                            'claude-sonnet-4-6'         => 'Claude Sonnet 4.6 (Ισορροπία — προτεινόμενο)',
                            'claude-opus-4-6'           => 'Claude Opus 4.6 (Πιο έξυπνο, πιο ακριβό)',
                            'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5 (Γρήγορο & φθηνό)',
                        ];
                        $current_claude = cacb_get( 'cacb_claude_model', 'claude-sonnet-4-6' );
                        foreach ( $claude_models as $val => $label ) {
                            printf(
                                '<option value="%s" %s>%s</option>',
                                esc_attr( $val ),
                                selected( $current_claude, $val, false ),
                                esc_html( $label )
                            );
                        }
                        ?>
                    </select>

                    <div class="cacb-key-actions">
                        <button type="button" class="button cacb-test-key" data-provider="claude">
                            🔍 <?php esc_html_e( 'Δοκιμή', 'capitano-chatbot' ); ?>
                        </button>
                        <?php if ( $has_claude_key ) : ?>
                        <button type="button" class="button cacb-delete-key cacb-btn-danger" data-option="cacb_claude_api_key">
                            🗑 <?php esc_html_e( 'Διαγραφή κλειδιού', 'capitano-chatbot' ); ?>
                        </button>
                        <?php endif; ?>
                        <span id="cacb-test-status-claude" class="cacb-test-status"></span>
                    </div>
                </div>

                <!-- ── Google Gemini ── -->
                <div class="cacb-card cacb-card--provider" data-provider="gemini">
                    <h2>🔵 Google (Gemini)</h2>

                    <label><?php esc_html_e( 'API Key', 'capitano-chatbot' ); ?></label>
                    <?php $has_gemini_key = ! empty( cacb_get( 'cacb_gemini_api_key' ) ); ?>
                    <input type="password"
                           name="cacb_gemini_api_key"
                           value=""
                           class="regular-text"
                           autocomplete="new-password"
                           placeholder="<?php echo esc_attr( $has_gemini_key ? '••••••••••••••••' : 'AIza...' ); ?>" />
                    <p class="description">
                        <?php if ( $has_gemini_key ) : ?>
                            ✅ <?php esc_html_e( 'API key αποθηκευμένο κρυπτογραφημένα (AES-256). Άφησε κενό για να το κρατήσεις.', 'capitano-chatbot' ); ?>
                        <?php else : ?>
                            <?php esc_html_e( 'Θα αποθηκευτεί κρυπτογραφημένο. Μην το μοιράζεσαι.', 'capitano-chatbot' ); ?>
                        <?php endif; ?>
                    </p>

                    <label><?php esc_html_e( 'Model', 'capitano-chatbot' ); ?></label>
                    <select name="cacb_gemini_model">
                        <?php
                        $gemini_models = [
                            'gemini-2.0-flash' => 'Gemini 2.0 Flash (Γρήγορο — προτεινόμενο)',
                            'gemini-1.5-pro'   => 'Gemini 1.5 Pro (Πιο έξυπνο)',
                            'gemini-1.5-flash' => 'Gemini 1.5 Flash (Φθηνότατο)',
                        ];
                        $current_gemini = cacb_get( 'cacb_gemini_model', 'gemini-2.0-flash' );
                        foreach ( $gemini_models as $val => $label ) {
                            printf(
                                '<option value="%s" %s>%s</option>',
                                esc_attr( $val ),
                                selected( $current_gemini, $val, false ),
                                esc_html( $label )
                            );
                        }
                        ?>
                    </select>

                    <div class="cacb-key-actions">
                        <button type="button" class="button cacb-test-key" data-provider="gemini">
                            🔍 <?php esc_html_e( 'Δοκιμή', 'capitano-chatbot' ); ?>
                        </button>
                        <?php if ( $has_gemini_key ) : ?>
                        <button type="button" class="button cacb-delete-key cacb-btn-danger" data-option="cacb_gemini_api_key">
                            🗑 <?php esc_html_e( 'Διαγραφή κλειδιού', 'capitano-chatbot' ); ?>
                        </button>
                        <?php endif; ?>
                        <span id="cacb-test-status-gemini" class="cacb-test-status"></span>
                    </div>
                </div>

                <!-- ── Limits ── -->
                <div class="cacb-card">
                    <h2>⚡ Limits & Ασφάλεια</h2>

                    <label><?php esc_html_e( 'Max μηνύματα ανά ώρα / IP', 'capitano-chatbot' ); ?></label>
                    <input type="number"
                           name="cacb_rate_limit"
                           value="<?php echo esc_attr( cacb_get( 'cacb_rate_limit', 20 ) ); ?>"
                           min="1" max="200" class="small-text" />
                    <p class="description"><?php esc_html_e( 'Προστασία κατά κατάχρησης. Προτεινόμενο: 20.', 'capitano-chatbot' ); ?></p>

                    <label><?php esc_html_e( 'Max tokens απάντησης', 'capitano-chatbot' ); ?></label>
                    <input type="number"
                           name="cacb_max_tokens"
                           value="<?php echo esc_attr( cacb_get( 'cacb_max_tokens', 500 ) ); ?>"
                           min="100" max="2000" class="small-text" />
                    <p class="description"><?php esc_html_e( 'Έλεγχος κόστους. Προτεινόμενο: 500.', 'capitano-chatbot' ); ?></p>

                    <label><?php esc_html_e( 'Μηνύματα ιστορικού', 'capitano-chatbot' ); ?></label>
                    <input type="number"
                           name="cacb_history_limit"
                           value="<?php echo esc_attr( cacb_get( 'cacb_history_limit', 10 ) ); ?>"
                           min="2" max="50" class="small-text" />
                    <p class="description"><?php esc_html_e( 'Πόσα τελευταία μηνύματα να θυμάται. Προτεινόμενο: 10.', 'capitano-chatbot' ); ?></p>
                </div>

                <!-- ── System Prompt ── -->
                <div class="cacb-card cacb-card--full">
                    <h2>🧠 System Prompt</h2>
                    <p class="description"><?php esc_html_e( 'Ορίζεις εδώ τι ξέρει το bot: πολιτικές, προϊόντα, τόνο ομιλίας.', 'capitano-chatbot' ); ?></p>
                    <textarea name="cacb_system_prompt"
                              rows="10"
                              class="large-text"><?php echo esc_textarea( cacb_get( 'cacb_system_prompt' ) ); ?></textarea>
                </div>

                <!-- ── WooCommerce ── -->
                <div class="cacb-card">
                    <h2>🛒 WooCommerce Προϊόντα</h2>

                    <?php if ( ! function_exists( 'wc_get_products' ) ) : ?>
                        <div class="cacb-notice cacb-notice--warn">
                            ⚠️ <?php esc_html_e( 'Το WooCommerce δεν είναι ενεργό σε αυτό το site.', 'capitano-chatbot' ); ?>
                        </div>
                    <?php else : ?>

                        <label style="display:flex;align-items:center;gap:8px;margin-top:0;">
                            <input type="checkbox"
                                   name="cacb_wc_enabled"
                                   value="1"
                                   <?php checked( cacb_get( 'cacb_wc_enabled', '0' ), '1' ); ?> />
                            <?php esc_html_e( 'Ενεργοποίηση — προσθέτει live προϊόντα στο system prompt', 'capitano-chatbot' ); ?>
                        </label>

                        <div id="cacb-wc-options" <?php echo cacb_get( 'cacb_wc_enabled' ) ? '' : 'style="opacity:.45;pointer-events:none"'; ?>>

                            <label><?php esc_html_e( 'Μέγιστος αριθμός προϊόντων', 'capitano-chatbot' ); ?></label>
                            <input type="number"
                                   name="cacb_wc_limit"
                                   value="<?php echo esc_attr( cacb_get( 'cacb_wc_limit', 50 ) ); ?>"
                                   min="10" max="200" class="small-text" />
                            <p class="description"><?php esc_html_e( 'Προτεινόμενο: 50. Περισσότερα = περισσότερα tokens.', 'capitano-chatbot' ); ?></p>

                            <label><?php esc_html_e( 'Φιλτράρισμα κατηγοριών (προαιρετικό)', 'capitano-chatbot' ); ?></label>
                            <input type="text"
                                   name="cacb_wc_categories"
                                   value="<?php echo esc_attr( cacb_get( 'cacb_wc_categories' ) ); ?>"
                                   class="regular-text"
                                   placeholder="krasia, meli, elaiolado" />
                            <p class="description"><?php esc_html_e( 'Slugs κατηγοριών χωρισμένα με κόμμα. Άδειο = όλες οι κατηγορίες.', 'capitano-chatbot' ); ?></p>

                            <?php
                            $cache_exists = ( false !== get_transient( 'cacb_wc_products_cache' ) );
                            if ( $cache_exists ) :
                            ?>
                            <div class="cacb-notice cacb-notice--info">
                                ✅ <?php esc_html_e( 'Product cache ενεργό (1 ώρα). Ανανεώνεται αυτόματα όταν αλλάξει προϊόν.', 'capitano-chatbot' ); ?>
                                <a href="<?php echo esc_url( add_query_arg( 'cacb_clear_cache', '1' ) ); ?>"><?php esc_html_e( 'Καθαρισμός τώρα', 'capitano-chatbot' ); ?></a>
                            </div>
                            <?php else : ?>
                            <div class="cacb-notice cacb-notice--info">
                                ℹ️ <?php esc_html_e( 'Cache θα δημιουργηθεί στο επόμενο chat request.', 'capitano-chatbot' ); ?>
                            </div>
                            <?php endif; ?>

                        </div><!-- #cacb-wc-options -->

                        <script>
                        document.querySelector('[name="cacb_wc_enabled"]').addEventListener('change', function() {
                            document.getElementById('cacb-wc-options').style.opacity = this.checked ? '1' : '.45';
                            document.getElementById('cacb-wc-options').style.pointerEvents = this.checked ? '' : 'none';
                        });
                        </script>

                    <?php endif; ?>
                </div>

                <!-- ── Logging ── -->
                <div class="cacb-card">
                    <h2>📋 <?php esc_html_e( 'Καταγραφή (Logging)', 'capitano-chatbot' ); ?></h2>

                    <label style="display:flex;align-items:center;gap:8px;margin-top:0">
                        <input type="hidden"   name="cacb_logging_enabled" value="0">
                        <input type="checkbox" name="cacb_logging_enabled" value="1"
                               <?php checked( cacb_get( 'cacb_logging_enabled', '1' ), '1' ); ?> />
                        <?php esc_html_e( 'Ενεργοποίηση καταγραφής συνομιλιών', 'capitano-chatbot' ); ?>
                    </label>
                    <p class="description"><?php esc_html_e( 'Αποθηκεύει ερωτήσεις και απαντήσεις στη βάση για επισκόπηση στην καρτέλα Logs.', 'capitano-chatbot' ); ?></p>

                    <label><?php esc_html_e( 'Διατήρηση logs (ημέρες)', 'capitano-chatbot' ); ?></label>
                    <input type="number"
                           name="cacb_log_retention"
                           value="<?php echo esc_attr( cacb_get( 'cacb_log_retention', 30 ) ); ?>"
                           min="1" max="365" class="small-text" />
                    <p class="description"><?php esc_html_e( 'Εγγραφές παλαιότερες από τόσες ημέρες διαγράφονται αυτόματα.', 'capitano-chatbot' ); ?></p>

                    <p style="margin-top:14px">
                        <a href="<?php echo esc_url( add_query_arg( 'tab', 'logs' ) ); ?>" class="button button-secondary">
                            📋 <?php esc_html_e( 'Προβολή Logs', 'capitano-chatbot' ); ?>
                        </a>
                    </p>
                </div>

                <!-- ── Appearance ── -->
                <div class="cacb-card">
                    <h2>🎨 Εμφάνιση</h2>

                    <label><?php esc_html_e( 'Τίτλος chat', 'capitano-chatbot' ); ?></label>
                    <input type="text"
                           name="cacb_chat_title"
                           value="<?php echo esc_attr( cacb_get( 'cacb_chat_title' ) ); ?>"
                           class="regular-text" />

                    <label><?php esc_html_e( 'Welcome μήνυμα', 'capitano-chatbot' ); ?></label>
                    <textarea name="cacb_welcome_message"
                              rows="3"
                              class="large-text"><?php echo esc_textarea( cacb_get( 'cacb_welcome_message' ) ); ?></textarea>

                    <label><?php esc_html_e( 'Ειδοποίηση Απορρήτου', 'capitano-chatbot' ); ?></label>
                    <input type="text"
                           name="cacb_privacy_notice"
                           value="<?php echo esc_attr( cacb_get( 'cacb_privacy_notice' ) ); ?>"
                           class="large-text"
                           placeholder="<?php esc_attr_e( 'Οι συνομιλίες ενδέχεται να καταγράφονται.', 'capitano-chatbot' ); ?>" />
                    <p class="description"><?php esc_html_e( 'Εμφανίζεται ως μικρό κείμενο κάτω από τον τίτλο. Αφήστε κενό για να κρυφτεί.', 'capitano-chatbot' ); ?></p>

                    <label><?php esc_html_e( 'URL Πολιτικής Απορρήτου', 'capitano-chatbot' ); ?></label>
                    <input type="url"
                           name="cacb_privacy_url"
                           value="<?php echo esc_attr( cacb_get( 'cacb_privacy_url' ) ); ?>"
                           class="regular-text"
                           placeholder="https://yoursite.com/privacy-policy" />
                    <p class="description"><?php esc_html_e( 'Αν συμπληρωθεί, η ειδοποίηση θα περιλαμβάνει σύνδεσμο "Πολιτική Απορρήτου".', 'capitano-chatbot' ); ?></p>

                    <label><?php esc_html_e( 'Primary χρώμα', 'capitano-chatbot' ); ?></label>
                    <input type="color"
                           name="cacb_primary_color"
                           value="<?php echo esc_attr( cacb_get( 'cacb_primary_color', '#1a1a2e' ) ); ?>" />

                    <label><?php esc_html_e( 'Θέση bubble', 'capitano-chatbot' ); ?></label>
                    <select name="cacb_bubble_position">
                        <option value="right" <?php selected( cacb_get( 'cacb_bubble_position' ), 'right' ); ?>>Κάτω Δεξιά</option>
                        <option value="left"  <?php selected( cacb_get( 'cacb_bubble_position' ), 'left' ); ?>>Κάτω Αριστερά</option>
                    </select>
                </div>

            </div><!-- .cacb-grid -->

            <?php submit_button( __( 'Αποθήκευση', 'capitano-chatbot' ) ); ?>
        </form>

        <?php endif; // end settings tab ?>

    </div>

    <style>
        .cacb-admin h1 { margin-bottom: 24px; }
        .cacb-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            max-width: 1100px;
        }
        .cacb-card {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 24px;
        }
        .cacb-card--full { grid-column: 1 / -1; }
        .cacb-card h2 { margin-top: 0; font-size: 15px; }
        .cacb-card label {
            display: block;
            font-weight: 600;
            margin-top: 16px;
            margin-bottom: 4px;
        }
        .cacb-card input[type="color"] { height: 36px; cursor: pointer; }
        .cacb-notice {
            margin-top: 14px;
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 13px;
        }
        .cacb-notice--warn { background: #fff8e1; border-left: 3px solid #f59e0b; }
        .cacb-notice--info { background: #e8f4fd; border-left: 3px solid #3b82f6; }
        .cacb-notice a { margin-left: 10px; }
        .cacb-card--provider { border: 2px solid #e0e0e0; transition: border-color .2s, box-shadow .2s; }
        .cacb-card--provider.cacb-card--active { border-color: #2271b1; box-shadow: 0 0 0 3px rgba(34,113,177,.15); }
        .cacb-key-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 14px; }
        .cacb-btn-danger { color: #d63638 !important; border-color: #d63638 !important; }
        .cacb-btn-danger:hover { background: #d63638 !important; color: #fff !important; }
        .cacb-test-status { font-size: 13px; font-weight: 600; }
        .cacb-key-ok  { color: #1e6637; }
        .cacb-key-err { color: #d63638; }
        @media (max-width: 782px) {
            .cacb-grid { grid-template-columns: 1fr; }
            .cacb-card--full { grid-column: 1; }
        }
    </style>
    <?php // Provider highlighting and key actions are handled by assets/admin.js ?>
    <?php
}

// ── RAG / Knowledge Base page ─────────────────────────────────────────────────
function cacb_render_rag_page(): void {
    $provider      = get_option( 'cacb_provider', 'openai' );
    $rag_enabled   = get_option( 'cacb_rag_enabled', '0' );
    $top_k         = (int) get_option( 'cacb_rag_top_k', 5 );
    $index_pages   = get_option( 'cacb_rag_index_pages', '0' );
    $has_rag_key   = ! empty( get_option( 'cacb_rag_openai_key', '' ) );
    ?>

    <div class="cacb-grid" style="max-width:1100px">

        <!-- ── RAG Settings form ── -->
        <div class="cacb-card cacb-card--full">
            <h2 style="margin-top:0">🧠 RAG — Semantic Search (Retrieval-Augmented Generation)</h2>
            <p class="description">
                <?php esc_html_e( 'Αντί να στέλνεις όλα τα προϊόντα στο AI, το σύστημα βρίσκει σημαντικά μόνο τα σχετικά με κάθε ερώτηση. Λιγότερα tokens, καλύτερες απαντήσεις, χωρίς όριο προϊόντων.', 'capitano-chatbot' ); ?>
            </p>

            <form method="post" action="options.php">
                <?php settings_fields( 'cacb_settings_group' ); ?>

                <label style="display:flex;align-items:center;gap:8px;margin-top:16px;font-weight:600">
                    <input type="hidden"   name="cacb_rag_enabled" value="0">
                    <input type="checkbox" name="cacb_rag_enabled" value="1"
                           id="cacb_rag_enabled"
                           <?php checked( $rag_enabled, '1' ); ?> />
                    <?php esc_html_e( 'Ενεργοποίηση RAG (Semantic Search)', 'capitano-chatbot' ); ?>
                </label>
                <p class="description" style="margin-left:28px"><?php esc_html_e( 'Χρησιμοποιεί vector embeddings αντί για απλή λίστα προϊόντων.', 'capitano-chatbot' ); ?></p>

                <div id="cacb-rag-options" <?php echo '1' === $rag_enabled ? '' : 'style="opacity:.45;pointer-events:none"'; ?>>

                    <label style="display:block;font-weight:600;margin-top:16px;margin-bottom:4px">
                        <?php esc_html_e( 'Top-K αποτελέσματα', 'capitano-chatbot' ); ?>
                    </label>
                    <input type="number" name="cacb_rag_top_k"
                           value="<?php echo esc_attr( $top_k ); ?>"
                           min="1" max="10" class="small-text" />
                    <p class="description"><?php esc_html_e( 'Πόσα σχετικά αποτελέσματα να εισάγει στο prompt. Προτεινόμενο: 5.', 'capitano-chatbot' ); ?></p>

                    <label style="display:flex;align-items:center;gap:8px;margin-top:16px;font-weight:600">
                        <input type="hidden"   name="cacb_rag_index_pages" value="0">
                        <input type="checkbox" name="cacb_rag_index_pages" value="1"
                               <?php checked( $index_pages, '1' ); ?> />
                        <?php esc_html_e( 'Ευρετηρίαση σελίδων WordPress (FAQ, Shipping, κ.λπ.)', 'capitano-chatbot' ); ?>
                    </label>
                    <p class="description" style="margin-left:28px"><?php esc_html_e( 'Εκτός από τα προϊόντα, το chatbot μπορεί να απαντά και με βάση το περιεχόμενο των σελίδων σου.', 'capitano-chatbot' ); ?></p>

                    <?php if ( 'claude' === $provider ) : ?>
                    <div class="cacb-notice cacb-notice--warn" style="margin-top:16px">
                        ⚠️ <?php esc_html_e( 'Ο Claude δεν έχει Embeddings API. Χρειάζεται ένα OpenAI key αποκλειστικά για RAG (δεν χρησιμοποιείται για chat).', 'capitano-chatbot' ); ?>
                    </div>

                    <label style="display:block;font-weight:600;margin-top:12px;margin-bottom:4px">
                        <?php esc_html_e( 'OpenAI API Key (μόνο για Embeddings)', 'capitano-chatbot' ); ?>
                    </label>
                    <input type="password" name="cacb_rag_openai_key" value=""
                           class="regular-text" autocomplete="new-password"
                           placeholder="<?php echo esc_attr( $has_rag_key ? '••••••••••••••••' : 'sk-...' ); ?>" />
                    <p class="description">
                        <?php if ( $has_rag_key ) : ?>
                            ✅ <?php esc_html_e( 'Key αποθηκευμένο κρυπτογραφημένα. Άφησε κενό για να το κρατήσεις.', 'capitano-chatbot' ); ?>
                        <?php else : ?>
                            <?php esc_html_e( 'Αποκτά δωρεάν credits από platform.openai.com. Θα αποθηκευτεί κρυπτογραφημένο.', 'capitano-chatbot' ); ?>
                        <?php endif; ?>
                    </p>
                    <?php if ( $has_rag_key ) : ?>
                    <button type="button" class="button cacb-delete-key cacb-btn-danger"
                            data-option="cacb_rag_openai_key" style="margin-top:6px">
                        🗑 <?php esc_html_e( 'Διαγραφή κλειδιού', 'capitano-chatbot' ); ?>
                    </button>
                    <?php endif; ?>
                    <?php endif; // claude ?>

                </div><!-- #cacb-rag-options -->

                <?php submit_button( __( 'Αποθήκευση', 'capitano-chatbot' ) ); ?>
            </form>
            <script>
            document.getElementById('cacb_rag_enabled').addEventListener('change', function () {
                var opts = document.getElementById('cacb-rag-options');
                opts.style.opacity      = this.checked ? '1' : '.45';
                opts.style.pointerEvents = this.checked ? '' : 'none';
            });
            </script>
        </div>

        <!-- ── Index Status ── -->
        <div class="cacb-card cacb-card--full" id="cacb-rag-status-card">
            <h2 style="margin-top:0">📊 <?php esc_html_e( 'Κατάσταση Index', 'capitano-chatbot' ); ?></h2>

            <div id="cacb-rag-status-wrap">
                <span class="spinner is-active" style="float:none;margin:0 8px 0 0"></span>
                <?php esc_html_e( 'Φόρτωση…', 'capitano-chatbot' ); ?>
            </div>

            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px">
                <?php if ( function_exists( 'wc_get_products' ) ) : ?>
                <button type="button" id="cacb-rag-index-products" class="button button-primary">
                    ⚡ <?php esc_html_e( 'Index Προϊόντων', 'capitano-chatbot' ); ?>
                </button>
                <?php endif; ?>
                <?php if ( '1' === $index_pages ) : ?>
                <button type="button" id="cacb-rag-index-pages" class="button button-secondary">
                    📄 <?php esc_html_e( 'Index Σελίδων', 'capitano-chatbot' ); ?>
                </button>
                <?php endif; ?>
                <button type="button" id="cacb-rag-clear" class="button cacb-btn-danger">
                    🗑 <?php esc_html_e( 'Καθαρισμός Index', 'capitano-chatbot' ); ?>
                </button>
            </div>

            <!-- Progress bar -->
            <div id="cacb-rag-progress-wrap" style="display:none;margin-top:14px">
                <div style="background:#e0e0e0;border-radius:4px;overflow:hidden;height:10px">
                    <div id="cacb-rag-progress-bar"
                         style="width:0%;background:#2271b1;height:100%;transition:width .2s"></div>
                </div>
                <p id="cacb-rag-progress-text" style="font-size:13px;margin-top:6px"></p>
            </div>
        </div>

    </div><!-- .cacb-grid -->
    <?php
}
