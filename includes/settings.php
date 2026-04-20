<?php
defined( 'ABSPATH' ) || exit;

// ── Register settings & admin menu ───────────────────────────────────────────
add_action( 'admin_menu', 'cacb_admin_menu' );
function cacb_admin_menu() {
    add_options_page(
        __( 'AI Chatbot Settings', 'smart-ai-chatbot' ),
        __( 'AI Chatbot', 'smart-ai-chatbot' ),
        'manage_options',
        'smart-ai-chatbot',
        'cacb_settings_page'
    );
}

add_action( 'admin_init', 'cacb_register_settings' );
function cacb_register_settings() {
    // ── Main settings (Settings tab) ─────────────────────────────────────────
    $main_fields = [
        'cacb_system_prompt',
        'cacb_chat_title',
        'cacb_welcome_message',
        'cacb_bubble_position',
        'cacb_primary_color',
        'cacb_wc_enabled',
        'cacb_wc_limit',
        'cacb_logging_enabled',
        'cacb_log_retention',
        'cacb_debug_mode',
        'cacb_privacy_notice',
        'cacb_privacy_url',
    ];
    foreach ( $main_fields as $field ) {
        register_setting( 'cacb_settings_group', $field, [
            'sanitize_callback' => 'cacb_sanitize_option',
        ] );
    }

    // ── Provider settings (AI Providers tab) — separate group ────────────────
    $provider_fields = [
        'cacb_provider',
        'cacb_api_key',
        'cacb_model',
        'cacb_claude_api_key',
        'cacb_claude_model',
        'cacb_max_tokens',
        'cacb_rate_limit',
        'cacb_history_limit',
    ];
    foreach ( $provider_fields as $field ) {
        register_setting( 'cacb_providers_group', $field, [
            'sanitize_callback' => 'cacb_sanitize_option',
        ] );
    }

    // ── RAG settings (Knowledge Base tab) — separate group to avoid cross-tab wipe
    $rag_fields = [
        'cacb_rag_enabled',
        'cacb_rag_top_k',
        'cacb_rag_index_pages',
        'cacb_rag_openai_key',
    ];
    foreach ( $rag_fields as $field ) {
        register_setting( 'cacb_rag_group', $field, [
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
        $raw = base64_decode( substr( $stored, 10 ), true );
        if ( false === $raw || strlen( $raw ) < 29 ) return ''; // nonce(12) + tag(16) + min 1 byte ciphertext
        $nonce = substr( $raw, 0, 12 );
        $tag   = substr( $raw, 12, 16 );
        $enc   = substr( $raw, 28 );
        $dec   = openssl_decrypt( $enc, 'AES-256-GCM', $key, OPENSSL_RAW_DATA, $nonce, $tag );
        return $dec !== false ? $dec : '';
    }

    // Legacy format: AES-256-CBC (backwards compatibility — re-encrypted on next save)
    if ( strpos( $stored, 'cacb_enc:' ) === 0 ) {
        $raw = base64_decode( substr( $stored, 9 ), true );
        if ( false === $raw || strlen( $raw ) < 17 ) return ''; // iv(16) + min 1 byte ciphertext
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
    // Derive the option name from the current filter (pattern: sanitize_option_{name})
    $option_name = str_replace( 'sanitize_option_', '', current_filter() );

    // ── API key fields: encrypt on save, keep existing if left empty ──────────
    $api_key_options = [ 'cacb_api_key', 'cacb_claude_api_key', 'cacb_rag_openai_key' ];
    if ( in_array( $option_name, $api_key_options, true ) ) {
        $value = sanitize_text_field( $value );
        if ( $value === '' ) {
            return get_option( $option_name, '' ); // keep existing encrypted value
        }
        $encrypted = cacb_encrypt_key( $value );
        if ( null === $encrypted ) {
            add_settings_error(
                $option_name,
                'cacb_encrypt_fail',
                __( 'API key δεν μπόρεσε να κρυπτογραφηθεί. Βεβαιωθείτε ότι η PHP έχει ενεργοποιημένη την openssl extension.', 'smart-ai-chatbot' )
            );
            return get_option( $option_name, '' );
        }
        return $encrypted;
    }

    // ── Textarea fields — preserve newlines, strip all HTML ──────────────────
    if ( in_array( $option_name, [ 'cacb_system_prompt', 'cacb_welcome_message' ], true ) ) {
        return wp_kses( $value, [] );
    }

    // ── Boolean toggles — always store '1' or '0' ─────────────────────────────
    $bool_options = [
        'cacb_logging_enabled',
        'cacb_debug_mode',
        'cacb_rag_enabled',
        'cacb_rag_index_pages',
        'cacb_wc_enabled',
    ];
    if ( in_array( $option_name, $bool_options, true ) ) {
        return '1' === $value ? '1' : '0';
    }

    // ── Numeric fields with min/max clamping ──────────────────────────────────
    $numeric_ranges = [
        'cacb_max_tokens'    => [ 100, 2000 ],
        'cacb_rate_limit'    => [ 1,   200  ],
        'cacb_history_limit' => [ 2,   50   ],
        'cacb_wc_limit'      => [ 1,   20   ],
        'cacb_log_retention' => [ 1,   365  ],
        'cacb_rag_top_k'     => [ 1,   10   ],
    ];
    if ( isset( $numeric_ranges[ $option_name ] ) ) {
        [ $min, $max ] = $numeric_ranges[ $option_name ];
        return (string) max( $min, min( $max, (int) $value ) );
    }

    // ── Whitelisted enum fields ───────────────────────────────────────────────
    $whitelists = [
        'cacb_provider'        => [ 'openai', 'claude' ],
        'cacb_model'           => [ 'gpt-5-nano', 'gpt-5-mini', 'gpt-4o-mini', 'gpt-4o', 'gpt-3.5-turbo' ],
        'cacb_claude_model'    => [ 'claude-sonnet-4-6', 'claude-opus-4-6', 'claude-haiku-4-5-20251001' ],
        'cacb_bubble_position' => [ 'right', 'left' ],
    ];
    if ( isset( $whitelists[ $option_name ] ) ) {
        $clean    = sanitize_text_field( $value );
        $allowed  = $whitelists[ $option_name ];
        return in_array( $clean, $allowed, true ) ? $clean : get_option( $option_name, $allowed[0] );
    }

    // ── Privacy URL ───────────────────────────────────────────────────────────
    if ( 'cacb_privacy_url' === $option_name ) {
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
    if ( 'settings_page_smart-ai-chatbot' !== $hook ) return;
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
            'testing'           => __( 'Δοκιμή…', 'smart-ai-chatbot' ),
            'testBtn'           => __( 'Δοκιμή', 'smart-ai-chatbot' ),
            'confirmDelete'     => __( 'Διαγραφή API key; Θα χρειαστεί να το ξαναβάλεις για να λειτουργεί το chatbot.', 'smart-ai-chatbot' ),
            'indexing'          => __( 'Ευρετηρίαση…', 'smart-ai-chatbot' ),
            'indexDone'         => __( 'Ολοκληρώθηκε!', 'smart-ai-chatbot' ),
            'indexError'        => __( 'Σφάλμα κατά την ευρετηρίαση.', 'smart-ai-chatbot' ),
            'confirmClearIndex' => __( 'Διαγραφή ολόκληρου του index; Θα χρειαστεί να ξανακάνεις ευρετηρίαση.', 'smart-ai-chatbot' ),
            'cleared'           => __( 'Index διαγράφηκε.', 'smart-ai-chatbot' ),
        ],
    ] );
}

// ── Settings page HTML ────────────────────────────────────────────────────────
function cacb_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Access denied.', 'smart-ai-chatbot' ) );
    }
    $valid_tabs = [ 'settings', 'providers', 'rag', 'logs' ];
    $active_tab = ( isset( $_GET['tab'] ) && in_array( $_GET['tab'], $valid_tabs, true ) )
        ? sanitize_key( $_GET['tab'] )
        : 'settings';
    ?>
    <div class="wrap cacb-admin">
        <h1>🤖 <?php esc_html_e( 'AI Chatbot Settings', 'smart-ai-chatbot' ); ?></h1>

        <?php settings_errors(); ?>

        <nav class="nav-tab-wrapper" style="margin-bottom:20px">
            <a href="<?php echo esc_url( add_query_arg( 'tab', 'settings' ) ); ?>"
               class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>">
                ⚙️ <?php esc_html_e( 'Ρυθμίσεις', 'smart-ai-chatbot' ); ?>
            </a>
            <a href="<?php echo esc_url( add_query_arg( 'tab', 'providers' ) ); ?>"
               class="nav-tab <?php echo 'providers' === $active_tab ? 'nav-tab-active' : ''; ?>">
                🤖 <?php esc_html_e( 'AI Providers', 'smart-ai-chatbot' ); ?>
            </a>
            <a href="<?php echo esc_url( add_query_arg( 'tab', 'rag' ) ); ?>"
               class="nav-tab <?php echo 'rag' === $active_tab ? 'nav-tab-active' : ''; ?>">
                🧠 <?php esc_html_e( 'Knowledge Base', 'smart-ai-chatbot' ); ?>
            </a>
            <a href="<?php echo esc_url( add_query_arg( 'tab', 'logs' ) ); ?>"
               class="nav-tab <?php echo 'logs' === $active_tab ? 'nav-tab-active' : ''; ?>">
                📋 <?php esc_html_e( 'Logs', 'smart-ai-chatbot' ); ?>
            </a>
        </nav>

        <?php if ( 'logs' === $active_tab ) :
            cacb_render_logs_page();
        elseif ( 'rag' === $active_tab ) :
            cacb_render_rag_page();
        elseif ( 'providers' === $active_tab ) :
            cacb_render_providers_page();
        else : ?>

        <form method="post" action="options.php">
            <?php settings_fields( 'cacb_settings_group' ); ?>

            <div class="cacb-grid">

                <!-- ── System Prompt ── -->
                <div class="cacb-card cacb-card--full">
                    <h2>🧠 System Prompt</h2>
                    <p class="description"><?php esc_html_e( 'Ορίζεις εδώ τι ξέρει το bot: πολιτικές, προϊόντα, τόνο ομιλίας.', 'smart-ai-chatbot' ); ?></p>
                    <textarea name="cacb_system_prompt"
                              rows="10"
                              class="large-text"><?php echo esc_textarea( cacb_get( 'cacb_system_prompt' ) ); ?></textarea>
                </div>

                <!-- ── WooCommerce ── -->
                <div class="cacb-card">
                    <h2>🛒 WooCommerce — Function Calling</h2>

                    <?php if ( ! function_exists( 'wc_get_products' ) ) : ?>
                        <div class="cacb-notice cacb-notice--warn">
                            ⚠️ <?php esc_html_e( 'Το WooCommerce δεν είναι ενεργό σε αυτό το site.', 'smart-ai-chatbot' ); ?>
                        </div>
                    <?php else : ?>

                        <p class="description" style="margin-top:0">
                            <?php esc_html_e( 'Το chatbot αναζητά προϊόντα δυναμικά μέσω Function Calling — φιλτράρει ανά κατηγορία, χρονιά, ποικιλία, περιοχή, χώρα, γλυκύτητα και τιμή απευθείας από τη βάση.', 'smart-ai-chatbot' ); ?>
                        </p>

                        <label style="display:flex;align-items:center;gap:8px;margin-top:8px">
                            <input type="hidden"   name="cacb_wc_enabled" value="0">
                            <input type="checkbox"
                                   id="cacb_wc_enabled"
                                   name="cacb_wc_enabled"
                                   value="1"
                                   <?php checked( cacb_get( 'cacb_wc_enabled', '0' ), '1' ); ?> />
                            <?php esc_html_e( 'Ενεργοποίηση αναζήτησης προϊόντων', 'smart-ai-chatbot' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'Απενεργοποίησε αν θέλεις το chatbot να απαντά μόνο βάσει System Prompt και RAG.', 'smart-ai-chatbot' ); ?></p>

                        <p style="margin-top:14px;margin-bottom:4px">
                            <label for="cacb_wc_limit">
                                <strong><?php esc_html_e( 'Μέγιστα αποτελέσματα ανά αναζήτηση', 'smart-ai-chatbot' ); ?></strong>
                            </label>
                        </p>
                        <input type="number"
                               id="cacb_wc_limit"
                               name="cacb_wc_limit"
                               value="<?php echo esc_attr( cacb_get( 'cacb_wc_limit', 8 ) ); ?>"
                               min="1" max="20" step="1"
                               class="small-text" />
                        <p class="description">
                            <?php esc_html_e( 'Πόσα προϊόντα να επιστρέφει το search_products tool (1-20, default 8).', 'smart-ai-chatbot' ); ?>
                        </p>

                    <?php endif; ?>
                </div>

                <!-- ── Logging ── -->
                <div class="cacb-card">
                    <h2>📋 <?php esc_html_e( 'Καταγραφή (Logging)', 'smart-ai-chatbot' ); ?></h2>

                    <label style="display:flex;align-items:center;gap:8px;margin-top:0">
                        <input type="hidden"   name="cacb_logging_enabled" value="0">
                        <input type="checkbox" name="cacb_logging_enabled" value="1"
                               <?php checked( cacb_get( 'cacb_logging_enabled', '1' ), '1' ); ?> />
                        <?php esc_html_e( 'Ενεργοποίηση καταγραφής συνομιλιών', 'smart-ai-chatbot' ); ?>
                    </label>
                    <p class="description"><?php esc_html_e( 'Αποθηκεύει ερωτήσεις και απαντήσεις στη βάση για επισκόπηση στην καρτέλα Logs.', 'smart-ai-chatbot' ); ?></p>

                    <label style="display:flex;align-items:center;gap:8px;margin-top:14px">
                        <input type="hidden"   name="cacb_debug_mode" value="0">
                        <input type="checkbox" name="cacb_debug_mode" value="1"
                               <?php checked( cacb_get( 'cacb_debug_mode', '0' ), '1' ); ?> />
                        <?php esc_html_e( '🔍 Debug mode — καταγραφή RAG context στα logs', 'smart-ai-chatbot' ); ?>
                    </label>
                    <p class="description"><?php esc_html_e( 'Αποθηκεύει ποια προϊόντα/σελίδες είδε το AI για κάθε ερώτηση. Χρήσιμο για debugging. Απενεργοποίησε σε production.', 'smart-ai-chatbot' ); ?></p>

                    <label><?php esc_html_e( 'Διατήρηση logs (ημέρες)', 'smart-ai-chatbot' ); ?></label>
                    <input type="number"
                           name="cacb_log_retention"
                           value="<?php echo esc_attr( cacb_get( 'cacb_log_retention', 30 ) ); ?>"
                           min="1" max="365" class="small-text" />
                    <p class="description"><?php esc_html_e( 'Εγγραφές παλαιότερες από τόσες ημέρες διαγράφονται αυτόματα.', 'smart-ai-chatbot' ); ?></p>

                    <p style="margin-top:14px">
                        <a href="<?php echo esc_url( add_query_arg( 'tab', 'logs' ) ); ?>" class="button button-secondary">
                            📋 <?php esc_html_e( 'Προβολή Logs', 'smart-ai-chatbot' ); ?>
                        </a>
                    </p>
                </div>

                <!-- ── Appearance ── -->
                <div class="cacb-card">
                    <h2>🎨 Εμφάνιση</h2>

                    <label><?php esc_html_e( 'Τίτλος chat', 'smart-ai-chatbot' ); ?></label>
                    <input type="text"
                           name="cacb_chat_title"
                           value="<?php echo esc_attr( cacb_get( 'cacb_chat_title' ) ); ?>"
                           class="regular-text" />

                    <label><?php esc_html_e( 'Welcome μήνυμα', 'smart-ai-chatbot' ); ?></label>
                    <textarea name="cacb_welcome_message"
                              rows="3"
                              class="large-text"><?php echo esc_textarea( cacb_get( 'cacb_welcome_message' ) ); ?></textarea>

                    <label><?php esc_html_e( 'Ειδοποίηση Απορρήτου', 'smart-ai-chatbot' ); ?></label>
                    <input type="text"
                           name="cacb_privacy_notice"
                           value="<?php echo esc_attr( cacb_get( 'cacb_privacy_notice' ) ); ?>"
                           class="large-text"
                           placeholder="<?php esc_attr_e( 'Οι συνομιλίες ενδέχεται να καταγράφονται.', 'smart-ai-chatbot' ); ?>" />
                    <p class="description"><?php esc_html_e( 'Εμφανίζεται ως μικρό κείμενο κάτω από τον τίτλο. Αφήστε κενό για να κρυφτεί.', 'smart-ai-chatbot' ); ?></p>

                    <label><?php esc_html_e( 'URL Πολιτικής Απορρήτου', 'smart-ai-chatbot' ); ?></label>
                    <input type="url"
                           name="cacb_privacy_url"
                           value="<?php echo esc_attr( cacb_get( 'cacb_privacy_url' ) ); ?>"
                           class="regular-text"
                           placeholder="https://yoursite.com/privacy-policy" />
                    <p class="description"><?php esc_html_e( 'Αν συμπληρωθεί, η ειδοποίηση θα περιλαμβάνει σύνδεσμο "Πολιτική Απορρήτου".', 'smart-ai-chatbot' ); ?></p>

                    <label><?php esc_html_e( 'Primary χρώμα', 'smart-ai-chatbot' ); ?></label>
                    <input type="color"
                           name="cacb_primary_color"
                           value="<?php echo esc_attr( cacb_get( 'cacb_primary_color', '#1a1a2e' ) ); ?>" />

                    <label><?php esc_html_e( 'Θέση bubble', 'smart-ai-chatbot' ); ?></label>
                    <select name="cacb_bubble_position">
                        <option value="right" <?php selected( cacb_get( 'cacb_bubble_position' ), 'right' ); ?>>Κάτω Δεξιά</option>
                        <option value="left"  <?php selected( cacb_get( 'cacb_bubble_position' ), 'left' ); ?>>Κάτω Αριστερά</option>
                    </select>
                </div>

            </div><!-- .cacb-grid -->

            <?php submit_button( __( 'Αποθήκευση', 'smart-ai-chatbot' ) ); ?>
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

// ── AI Providers page ─────────────────────────────────────────────────────────
function cacb_render_providers_page(): void {
    $current_provider = cacb_get( 'cacb_provider', 'openai' );
    $has_key          = ! empty( cacb_get( 'cacb_api_key' ) );
    $has_claude_key   = ! empty( cacb_get( 'cacb_claude_api_key' ) );
    ?>

    <form method="post" action="options.php">
        <?php settings_fields( 'cacb_providers_group' ); ?>

        <div class="cacb-grid">

            <!-- ── Provider Selector ── -->
            <div class="cacb-card cacb-card--full">
                <h2>🤖 Ενεργός AI Provider</h2>
                <p class="description"><?php esc_html_e( 'Επίλεξε ποιον AI πάροχο θέλεις να χρησιμοποιεί το chatbot. Το αντίστοιχο API key πρέπει να είναι συμπληρωμένο παρακάτω.', 'smart-ai-chatbot' ); ?></p>
                <label><?php esc_html_e( 'Ενεργός Provider', 'smart-ai-chatbot' ); ?></label>
                <select name="cacb_provider" id="cacb_provider">
                    <?php
                    $providers = [
                        'openai' => 'OpenAI (GPT)',
                        'claude' => 'Anthropic (Claude)',
                    ];
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

                <label><?php esc_html_e( 'API Key', 'smart-ai-chatbot' ); ?></label>
                <input type="password"
                       name="cacb_api_key"
                       value=""
                       class="regular-text"
                       autocomplete="new-password"
                       placeholder="<?php echo esc_attr( $has_key ? '••••••••••••••••' : 'sk-...' ); ?>" />
                <p class="description">
                    <?php if ( $has_key ) : ?>
                        ✅ <?php esc_html_e( 'API key αποθηκευμένο κρυπτογραφημένα (AES-256). Άφησε κενό για να το κρατήσεις ή γράψε νέο για να το αντικαταστήσεις.', 'smart-ai-chatbot' ); ?>
                    <?php else : ?>
                        <?php esc_html_e( 'Θα αποθηκευτεί κρυπτογραφημένο. Μην το μοιράζεσαι.', 'smart-ai-chatbot' ); ?>
                    <?php endif; ?>
                </p>

                <label><?php esc_html_e( 'Model', 'smart-ai-chatbot' ); ?></label>
                <select name="cacb_model">
                    <?php
                    $models = [
                        'gpt-5-nano'    => 'GPT-5 Nano (Νέο, γρήγορο & οικονομικό)',
                        'gpt-5-mini'    => 'GPT-5 Mini (Νέο, ισχυρό & γρήγορο)',
                        'gpt-4o-mini'   => 'GPT-4o Mini (Γρήγορο & φθηνό — προτεινόμενο)',
                        'gpt-4o'        => 'GPT-4o (Πιο έξυπνο, πιο ακριβό)',
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
                        🔍 <?php esc_html_e( 'Δοκιμή', 'smart-ai-chatbot' ); ?>
                    </button>
                    <?php if ( $has_key ) : ?>
                    <button type="button" class="button cacb-delete-key cacb-btn-danger" data-option="cacb_api_key">
                        🗑 <?php esc_html_e( 'Διαγραφή κλειδιού', 'smart-ai-chatbot' ); ?>
                    </button>
                    <?php endif; ?>
                    <span id="cacb-test-status-openai" class="cacb-test-status"></span>
                </div>
            </div>

            <!-- ── Claude (Anthropic) ── -->
            <div class="cacb-card cacb-card--provider" data-provider="claude">
                <h2>🟣 Anthropic (Claude)</h2>

                <label><?php esc_html_e( 'API Key', 'smart-ai-chatbot' ); ?></label>
                <input type="password"
                       name="cacb_claude_api_key"
                       value=""
                       class="regular-text"
                       autocomplete="new-password"
                       placeholder="<?php echo esc_attr( $has_claude_key ? '••••••••••••••••' : 'sk-ant-...' ); ?>" />
                <p class="description">
                    <?php if ( $has_claude_key ) : ?>
                        ✅ <?php esc_html_e( 'API key αποθηκευμένο κρυπτογραφημένα (AES-256). Άφησε κενό για να το κρατήσεις.', 'smart-ai-chatbot' ); ?>
                    <?php else : ?>
                        <?php esc_html_e( 'Θα αποθηκευτεί κρυπτογραφημένο. Μην το μοιράζεσαι.', 'smart-ai-chatbot' ); ?>
                    <?php endif; ?>
                </p>

                <label><?php esc_html_e( 'Model', 'smart-ai-chatbot' ); ?></label>
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
                        🔍 <?php esc_html_e( 'Δοκιμή', 'smart-ai-chatbot' ); ?>
                    </button>
                    <?php if ( $has_claude_key ) : ?>
                    <button type="button" class="button cacb-delete-key cacb-btn-danger" data-option="cacb_claude_api_key">
                        🗑 <?php esc_html_e( 'Διαγραφή κλειδιού', 'smart-ai-chatbot' ); ?>
                    </button>
                    <?php endif; ?>
                    <span id="cacb-test-status-claude" class="cacb-test-status"></span>
                </div>
            </div>

            <!-- ── Limits ── -->
            <div class="cacb-card">
                <h2>⚡ Limits & Ασφάλεια</h2>

                <label><?php esc_html_e( 'Max μηνύματα ανά ώρα / IP', 'smart-ai-chatbot' ); ?></label>
                <input type="number"
                       name="cacb_rate_limit"
                       value="<?php echo esc_attr( cacb_get( 'cacb_rate_limit', 20 ) ); ?>"
                       min="1" max="200" class="small-text" />
                <p class="description"><?php esc_html_e( 'Προστασία κατά κατάχρησης. Προτεινόμενο: 20.', 'smart-ai-chatbot' ); ?></p>

                <label><?php esc_html_e( 'Max tokens απάντησης', 'smart-ai-chatbot' ); ?></label>
                <input type="number"
                       name="cacb_max_tokens"
                       value="<?php echo esc_attr( cacb_get( 'cacb_max_tokens', 500 ) ); ?>"
                       min="100" max="2000" class="small-text" />
                <p class="description"><?php esc_html_e( 'Έλεγχος κόστους. Προτεινόμενο: 500.', 'smart-ai-chatbot' ); ?></p>

                <label><?php esc_html_e( 'Μηνύματα ιστορικού', 'smart-ai-chatbot' ); ?></label>
                <input type="number"
                       name="cacb_history_limit"
                       value="<?php echo esc_attr( cacb_get( 'cacb_history_limit', 10 ) ); ?>"
                       min="2" max="50" class="small-text" />
                <p class="description"><?php esc_html_e( 'Πόσα τελευταία μηνύματα να θυμάται. Προτεινόμενο: 10.', 'smart-ai-chatbot' ); ?></p>
            </div>

        </div><!-- .cacb-grid -->

        <?php submit_button( __( 'Αποθήκευση', 'smart-ai-chatbot' ) ); ?>
    </form>
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
            <h2 style="margin-top:0">🧠 RAG — Semantic Search για Σελίδες & FAQ</h2>
            <p class="description">
                <?php esc_html_e( 'Ευρετηριάζει WordPress σελίδες (FAQ, πολιτική αποστολής, κ.λπ.) με vector embeddings. Για κάθε ερώτηση βρίσκει τα πιο σχετικά τμήματα και τα εισάγει στο system prompt. Τα προϊόντα αναζητούνται ξεχωριστά μέσω Function Calling.', 'smart-ai-chatbot' ); ?>
            </p>

            <form method="post" action="options.php">
                <?php settings_fields( 'cacb_rag_group' ); ?>

                <label style="display:flex;align-items:center;gap:8px;margin-top:16px;font-weight:600">
                    <input type="hidden"   name="cacb_rag_enabled" value="0">
                    <input type="checkbox" name="cacb_rag_enabled" value="1"
                           id="cacb_rag_enabled"
                           <?php checked( $rag_enabled, '1' ); ?> />
                    <?php esc_html_e( 'Ενεργοποίηση RAG (Semantic Search)', 'smart-ai-chatbot' ); ?>
                </label>
                <p class="description" style="margin-left:28px"><?php esc_html_e( 'Βρίσκει τα πιο σχετικά chunks από σελίδες/FAQ για κάθε ερώτηση χρήστη.', 'smart-ai-chatbot' ); ?></p>

                <div id="cacb-rag-options" <?php echo '1' === $rag_enabled ? '' : 'style="opacity:.45;pointer-events:none"'; ?>>

                    <label style="display:block;font-weight:600;margin-top:16px;margin-bottom:4px">
                        <?php esc_html_e( 'Top-K αποτελέσματα', 'smart-ai-chatbot' ); ?>
                    </label>
                    <input type="number" name="cacb_rag_top_k"
                           value="<?php echo esc_attr( $top_k ); ?>"
                           min="1" max="10" class="small-text" />
                    <p class="description"><?php esc_html_e( 'Πόσα σχετικά αποτελέσματα να εισάγει στο prompt. Προτεινόμενο: 5.', 'smart-ai-chatbot' ); ?></p>

                    <label style="display:flex;align-items:center;gap:8px;margin-top:16px;font-weight:600">
                        <input type="hidden"   name="cacb_rag_index_pages" value="0">
                        <input type="checkbox" name="cacb_rag_index_pages" value="1"
                               <?php checked( $index_pages, '1' ); ?> />
                        <?php esc_html_e( 'Ευρετηρίαση σελίδων WordPress (FAQ, Shipping, κ.λπ.)', 'smart-ai-chatbot' ); ?>
                    </label>
                    <p class="description" style="margin-left:28px"><?php esc_html_e( 'Εκτός από τα προϊόντα, το chatbot μπορεί να απαντά και με βάση το περιεχόμενο των σελίδων σου.', 'smart-ai-chatbot' ); ?></p>

                    <?php if ( 'claude' === $provider ) : ?>
                    <div class="cacb-notice cacb-notice--warn" style="margin-top:16px">
                        ⚠️ <?php esc_html_e( 'Ο Claude δεν έχει Embeddings API. Χρειάζεται ένα OpenAI key αποκλειστικά για RAG (δεν χρησιμοποιείται για chat).', 'smart-ai-chatbot' ); ?>
                    </div>

                    <label style="display:block;font-weight:600;margin-top:12px;margin-bottom:4px">
                        <?php esc_html_e( 'OpenAI API Key (μόνο για Embeddings)', 'smart-ai-chatbot' ); ?>
                    </label>
                    <input type="password" name="cacb_rag_openai_key" value=""
                           class="regular-text" autocomplete="new-password"
                           placeholder="<?php echo esc_attr( $has_rag_key ? '••••••••••••••••' : 'sk-...' ); ?>" />
                    <p class="description">
                        <?php if ( $has_rag_key ) : ?>
                            ✅ <?php esc_html_e( 'Key αποθηκευμένο κρυπτογραφημένα. Άφησε κενό για να το κρατήσεις.', 'smart-ai-chatbot' ); ?>
                        <?php else : ?>
                            <?php esc_html_e( 'Αποκτά δωρεάν credits από platform.openai.com. Θα αποθηκευτεί κρυπτογραφημένο.', 'smart-ai-chatbot' ); ?>
                        <?php endif; ?>
                    </p>
                    <?php if ( $has_rag_key ) : ?>
                    <button type="button" class="button cacb-delete-key cacb-btn-danger"
                            data-option="cacb_rag_openai_key" style="margin-top:6px">
                        🗑 <?php esc_html_e( 'Διαγραφή κλειδιού', 'smart-ai-chatbot' ); ?>
                    </button>
                    <?php endif; ?>
                    <?php endif; // claude ?>

                </div><!-- #cacb-rag-options -->

                <?php submit_button( __( 'Αποθήκευση', 'smart-ai-chatbot' ) ); ?>
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
            <h2 style="margin-top:0">📊 <?php esc_html_e( 'Κατάσταση Index', 'smart-ai-chatbot' ); ?></h2>

            <div id="cacb-rag-status-wrap">
                <span class="spinner is-active" style="float:none;margin:0 8px 0 0"></span>
                <?php esc_html_e( 'Φόρτωση…', 'smart-ai-chatbot' ); ?>
            </div>

            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px">
                <button type="button" id="cacb-rag-index-pages" class="button button-primary">
                    📄 <?php esc_html_e( 'Index Σελίδων / FAQ', 'smart-ai-chatbot' ); ?>
                </button>
                <button type="button" id="cacb-rag-clear" class="button cacb-btn-danger">
                    🗑 <?php esc_html_e( 'Καθαρισμός Index', 'smart-ai-chatbot' ); ?>
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
