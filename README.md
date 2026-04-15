# Smart AI Chatbot — WordPress Plugin

**Version 1.2.5**

AI-powered chatbot για WordPress/WooCommerce με υποστήριξη **OpenAI (GPT)**, **Anthropic (Claude)** και **Google (Gemini)**.
Production-ready με streaming απαντήσεις, **RAG (Retrieval-Augmented Generation)**, AES-256-GCM encryption, rate limiting, και πλήρη admin controls.

---

## Εγκατάσταση

1. Ανέβασε τον φάκελο `smart-ai-chatbot` στο `/wp-content/plugins/`
2. Ενεργοποίησε το plugin από **WP Admin → Plugins**
3. Πήγαινε στο **Settings → AI Chatbot** και:
   - Tab **AI Providers**: επίλεξε provider, συμπλήρωσε API key, επίλεξε model, ρύθμισε limits
   - Tab **Ρυθμίσεις**: System Prompt, WooCommerce, Logging, Εμφάνιση

---

## AI Providers

### OpenAI (GPT)
Αποκτά API key από [platform.openai.com](https://platform.openai.com).

| Model | Περιγραφή |
|---|---|
| `gpt-4o-mini` | Γρήγορο & φθηνό — προτεινόμενο |
| `gpt-4o` | Πιο έξυπνο, πιο ακριβό |
| `gpt-3.5-turbo` | Φθηνότατο |

```php
// wp-config.php (προαιρετικό — έχει προτεραιότητα έναντι DB)
define( 'CACB_OPENAI_API_KEY', 'sk-...' );
```

### Anthropic (Claude)
Αποκτά API key από [console.anthropic.com](https://console.anthropic.com).

| Model | Περιγραφή |
|---|---|
| `claude-sonnet-4-6` | Ισορροπία ταχύτητας/ποιότητας — προτεινόμενο |
| `claude-opus-4-6` | Πιο έξυπνο, πιο ακριβό |
| `claude-haiku-4-5-20251001` | Γρήγορο & φθηνό |

```php
define( 'CACB_CLAUDE_API_KEY', 'sk-ant-...' );
```

### Google Gemini
Αποκτά API key από [aistudio.google.com](https://aistudio.google.com).

| Model | Περιγραφή |
|---|---|
| `gemini-2.0-flash` | Γρήγορο — προτεινόμενο |
| `gemini-1.5-pro` | Πιο έξυπνο |
| `gemini-1.5-flash` | Φθηνότατο |

```php
define( 'CACB_GEMINI_API_KEY', 'AIza...' );
```

---

## System Prompt — Παράδειγμα

```
Είσαι ο βοηθός του καταστήματος Capitano Lemnos.
Πουλάμε κρασιά και τοπικά προϊόντα από τη Λήμνο.

ΑΠΟΣΤΟΛΗ:
- Δωρεάν για παραγγελίες άνω των 50€
- Κόστος αποστολής 4.50€ για παραγγελίες κάτω των 50€
- Παράδοση σε 2-3 εργάσιμες

ΕΠΙΣΤΡΟΦΕΣ:
- Εντός 14 ημερών από την παραλαβή
- Το προϊόν να είναι αναλλοίωτο
- Επικοινωνία: returns@capitanolemnos.gr

ΠΛΗΡΩΜΗ:
- Κάρτα μέσω Viva Wallet
- Αντικαταβολή (+2€)
- Τραπεζική μεταφορά

ΕΠΙΚΟΙΝΩΝΙΑ:
- Email: info@capitanolemnos.gr
- Τηλ: 22540 XXXXX

Απάντα πάντα στα Ελληνικά με φιλικό τόνο.
Αν δεν ξέρεις κάτι, πες ότι θα επικοινωνήσει μαζί τους η ομάδα.
ΜΗΝ δίνεις πληροφορίες για ανταγωνιστές.
```

---

## Αποθήκευση & Διαγραφή Δεδομένων

### API Keys

| Στοιχείο | Λεπτομέρεια |
|---|---|
| **Πού αποθηκεύονται** | Πίνακας `wp_options` της βάσης δεδομένων WordPress |
| **Κλειδιά** | `cacb_api_key`, `cacb_claude_api_key`, `cacb_gemini_api_key` |
| **Μορφή αποθήκευσης** | Κρυπτογραφημένα — prefix `cacb_enc2:` + Base64(nonce + auth_tag + ciphertext) |
| **Αλγόριθμος** | AES-256-GCM (authenticated encryption — ανιχνεύει παραποίηση) |
| **Κλειδί κρυπτογράφησης** | SHA-256(AUTH_KEY + SECURE_AUTH_KEY) από το `wp-config.php` |
| **Nonce** | 12 bytes τυχαία (random_bytes) — διαφορετικό σε κάθε αποθήκευση |
| **Auth tag** | 16 bytes — επαληθεύει ακεραιότητα κατά την αποκρυπτογράφηση |
| **Εναλλακτική αποθήκευση** | Σταθερά στο `wp-config.php` (π.χ. `CACB_OPENAI_API_KEY`) — έχει προτεραιότητα έναντι DB |
| **Εμφάνιση στο admin** | Password field — η τιμή δεν αποστέλλεται ποτέ στον browser |
| **Διαγραφή μέσω admin** | Settings → AI Chatbot → κουμπί "Διαγραφή κλειδιού" → `delete_option()` |
| **Διαγραφή κατά uninstall** | Αυτόματη μέσω `uninstall.php` → `delete_option()` για κάθε κλειδί |
| **Legacy format** | Παλιές αποθηκεύσεις με AES-256-CBC (prefix `cacb_enc:`) αποκρυπτογραφούνται κανονικά, ανανεώνονται σε GCM στην επόμενη αποθήκευση |

> **Σημείωση:** Αν το `openssl` extension δεν είναι διαθέσιμο ή τα WordPress secret keys δεν έχουν οριστεί, το API key δεν αποθηκεύεται και εμφανίζεται σχετικό μήνυμα λάθους στον admin.

---

### IP Address

| Στοιχείο | Λεπτομέρεια |
|---|---|
| **Πού αποθηκεύεται (rate limiting)** | Πίνακας `wp_options` ως WordPress transient |
| **Κλειδί transient** | `_transient_cacb_rl_{SHA-256(IP)}` |
| **Τιμή** | Ακέραιος — αριθμός μηνυμάτων που έχουν σταλεί |
| **Διάρκεια** | 1 ώρα (HOUR_IN_SECONDS) — διαγράφεται αυτόματα |
| **Πού αποθηκεύεται (logs)** | Πίνακας `wp_cacb_logs`, πεδίο `ip_hash` |
| **Μορφή IP στα logs** | SHA-256(IP) — μονής κατεύθυνσης, δεν αντιστρέφεται |
| **Headers που ελέγχονται** | `HTTP_CF_CONNECTING_IP` → `HTTP_X_FORWARDED_FOR` → `HTTP_X_REAL_IP` → `REMOTE_ADDR` |
| **Αρχή ελάχιστων δεδομένων** | Αποθηκεύεται μόνο το hash — η πραγματική IP δεν γράφεται πουθενά |
| **Διαγραφή transients κατά uninstall** | `DELETE FROM wp_options WHERE option_name LIKE '_transient_cacb_rl_%'` |
| **Διαγραφή από logs** | Μαζί με τον πίνακα `wp_cacb_logs` κατά το uninstall |

---

### Ιστορικό Συνομιλίας

Το ιστορικό αποθηκεύεται **σε δύο επίπεδα** ταυτόχρονα:

#### Επίπεδο 1 — Browser (localStorage)

| Στοιχείο | Λεπτομέρεια |
|---|---|
| **Τεχνολογία** | `window.localStorage` — αποθήκευση στον browser του χρήστη |
| **Κλειδί** | `cacb_chatHistory` |
| **Περιεχόμενο** | JSON array με `{ role, content }` για κάθε μήνυμα |
| **Σκοπός** | Επαναφορά συνομιλίας όταν ο χρήστης ανανεώσει τη σελίδα |
| **Διάρκεια** | Αόριστη — παραμένει μέχρι διαγραφής |
| **Σταλμένο στον server;** | Ναι — ως payload σε κάθε request (για context), αλλά δεν αποθηκεύεται ξανά |
| **Διαγραφή από τον χρήστη** | Κουμπί 🗑 στο chat window — καλεί `localStorage.removeItem()` |
| **Διαγραφή από τον server** | Δεν εφαρμόζεται — βρίσκεται αποκλειστικά στον browser |
| **Προστασία corrupt data** | Αν δεν είναι έγκυρο JSON array, αγνοείται και ξεκινά νέα συνομιλία |

#### Επίπεδο 2 — Server (βάση δεδομένων)

| Στοιχείο | Λεπτομέρεια |
|---|---|
| **Πίνακας** | `wp_cacb_logs` (δημιουργείται αυτόματα κατά την ενεργοποίηση) |
| **Πεδία** | `id`, `created_at`, `provider`, `model`, `user_msg`, `bot_reply`, `ip_hash` |
| **Τι καταγράφεται** | Τελευταίο ζεύγος ερώτησης–απάντησης ανά request (όχι ολόκληρο το ιστορικό) |
| **Πότε γράφεται** | Μετά από κάθε επιτυχή απάντηση AI (REST και streaming) |
| **Streaming logging** | Ο browser στέλνει το πλήρες ζεύγος μετά το τέλος του stream (fire-and-forget) |
| **Ενεργοποίηση** | Settings → AI Chatbot → Logging — μπορεί να απενεργοποιηθεί |
| **Retention** | Ρυθμιζόμενο (default: 30 ημέρες) — αυτόματη διαγραφή παλαιών εγγραφών |
| **Διαγραφή από admin** | Settings → Logs → "Διαγραφή όλων" → `TRUNCATE TABLE wp_cacb_logs` |
| **Διαγραφή κατά uninstall** | `DROP TABLE IF EXISTS wp_cacb_logs` |
| **Πρόσβαση** | Μόνο χρήστες με `manage_options` capability |

---

### Πλήρης Χάρτης Δεδομένων

| Δεδομένο | Πού | Μορφή | Διάρκεια | Διαγραφή |
|---|---|---|---|---|
| OpenAI API Key | `wp_options` | AES-256-GCM | Μόνιμη | Admin UI ή uninstall |
| Claude API Key | `wp_options` | AES-256-GCM | Μόνιμη | Admin UI ή uninstall |
| Gemini API Key | `wp_options` | AES-256-GCM | Μόνιμη | Admin UI ή uninstall |
| IP (rate limit) | `wp_options` transient | SHA-256 hash | 1 ώρα | Αυτόματα ή uninstall |
| IP (logs) | `wp_cacb_logs` | SHA-256 hash | Configurable | Admin UI ή uninstall |
| Ιστορικό (browser) | `localStorage` | JSON plaintext | Αόριστη | Κουμπί 🗑 στο chat |
| Μηνύματα (logs) | `wp_cacb_logs` | Plaintext | Configurable | Admin UI ή uninstall |
| Vector Embeddings | `wp_cacb_embeddings` | JSON float array | Μόνιμη | Admin UI ή uninstall |
| Ρυθμίσεις plugin | `wp_options` | Plaintext | Μόνιμη | Uninstall |

---

## Ασφάλεια

| Μηχανισμός | Περιγραφή |
|---|---|
| AES-256-GCM encryption | Τα API keys αποθηκεύονται κρυπτογραφημένα με authenticated encryption |
| WordPress secret keys | Κλειδί κρυπτογράφησης παράγεται από `AUTH_KEY` + `SECURE_AUTH_KEY` |
| wp-config.php constants | Προαιρετική αποθήκευση keys εκτός βάσης για μέγιστη ασφάλεια |
| Nonce verification | Κάθε request επαληθεύεται με WP nonce (CSRF protection) |
| Rate limiting | Max μηνύματα ανά IP ανά ώρα (ρυθμιζόμενο) |
| Input sanitization | Όλα τα inputs sanitized με WP functions |
| Output escaping | Όλα τα outputs escaped πριν εμφανιστούν |
| Capability check | Μόνο `manage_options` έχει πρόσβαση στα settings |
| SSL verification | Όλα τα cURL requests με `CURLOPT_SSL_VERIFYPEER => true` |
| Cloudflare-aware | Σωστή ανάγνωση IP πίσω από proxy/CDN |
| Message length cap | Max 4 000 χαρακτήρες ανά μήνυμα — αποτρέπει API credit drain |
| Enum whitelisting | Provider, model, bubble_position επαληθεύονται έναντι επιτρεπόμενων τιμών |

---

## Αρχιτεκτονική

```
smart-ai-chatbot/
├── smart-ai-chatbot.php   ← Bootstrap, activation, constants
├── uninstall.php          ← Καθαρισμός βάσης κατά τη διαγραφή
├── includes/
│   ├── settings.php       ← Admin page, WP options, AES-256-GCM encryption
│   ├── embeddings.php     ← RAG engine: indexing, embeddings, cosine similarity, retrieval
│   ├── api.php            ← REST endpoint + streaming (SSE) για OpenAI/Claude/Gemini
│   ├── logs.php           ← DB logs, AJAX handlers, log viewer
│   └── frontend.php       ← Asset enqueue + chat HTML output
└── assets/
    ├── chat.js            ← UI logic, SSE streaming, localStorage history
    ├── admin.js           ← Admin panel JS: provider highlight, key test/delete, RAG index
    └── chat.css           ← Styles, animations
```

### Streaming (SSE)

Οι απαντήσεις των AI στέλνονται σε πραγματικό χρόνο μέσω Server-Sent Events:
- **Server**: `admin-ajax.php` → cURL με `CURLOPT_WRITEFUNCTION` → forward chunks στον browser
- **Client**: `fetch` + `ReadableStream` + `TextDecoder` → προοδευτική εμφάνιση κειμένου

---

## Limits (Settings → AI Chatbot → AI Providers → Limits & Ασφάλεια)

| Ρύθμιση | Εύρος | Default | Περιγραφή |
|---|---|---|---|
| Rate limit | 1–200 | 20 | Μέγιστα μηνύματα ανά IP ανά ώρα |
| Max tokens | 100–2000 | 500 | Μέγιστο μέγεθος απάντησης (έλεγχος κόστους) |
| History limit | 2–50 | 10 | Πόσα τελευταία μηνύματα να θυμάται |
| Message length | — | 4 000 | Max χαρακτήρες ανά μήνυμα χρήστη (server-side cap) |

---

## WooCommerce Integration

Αν το WooCommerce είναι ενεργό, μπορείς να ενεργοποιήσεις την αυτόματη ενσωμάτωση προϊόντων στο system prompt:

- Τα προϊόντα φορτώνονται live από το κατάστημα (όνομα, τιμή, SKU, διαθεσιμότητα, κατηγορία)
- Αποθηκεύονται σε cache 1 ώρα — ανανεώνονται αυτόματα όταν αλλάξει προϊόν
- Φιλτράρισμα ανά κατηγορία (slugs χωρισμένα με κόμμα)

---

## RAG — Knowledge Base (Semantic Search)

**Settings → AI Chatbot → Knowledge Base**

Αντί να στέλνεις ολόκληρο τον κατάλογο στο AI σε κάθε μήνυμα, το RAG σύστημα:

1. **Indexing**: Μετατρέπει κάθε προϊόν/σελίδα σε vector embedding και το αποθηκεύει στη βάση.
2. **Retrieval**: Για κάθε ερώτηση χρήστη, βρίσκει τα top-K πιο σχετικά αντικείμενα (cosine similarity).
3. **Augmentation**: Εισάγει **μόνο τα σχετικά** αποτελέσματα στο system prompt.

### Πλεονεκτήματα

| | Χωρίς RAG | Με RAG |
|---|---|---|
| Tokens/request | ~3 000–8 000 | ~300–500 |
| Max προϊόντα | ~200 | Απεριόριστα |
| Ακρίβεια απαντήσεων | Μέτρια | Υψηλή |
| Κόστος indexing | — | ~$0.02 / 1 000 προϊόντα |

### Embedding Providers

| Chat Provider | Embedding API | Key που χρησιμοποιεί |
|---|---|---|
| OpenAI | `text-embedding-3-small` (1 536 dims) | Ίδιο OpenAI key |
| Gemini | `text-embedding-004` (768 dims) | Ίδιο Gemini key |
| Claude | `text-embedding-3-small` | Ξεχωριστό OpenAI key (`cacb_rag_openai_key`) |

> Ο Claude δεν παρέχει Embeddings API. Αν χρησιμοποιείς Claude για chat, χρειάζεσαι ένα επιπλέον OpenAI key αποκλειστικά για embeddings.

### Ρυθμίσεις

| Option | Default | Περιγραφή |
|---|---|---|
| `cacb_rag_enabled` | `0` | Ενεργοποίηση RAG |
| `cacb_rag_top_k` | `5` | Πόσα σχετικά αποτελέσματα να εισάγει στο prompt |
| `cacb_rag_index_pages` | `0` | Ευρετηρίαση WordPress pages εκτός από προϊόντα |
| `cacb_rag_openai_key` | `''` | OpenAI key για embeddings (μόνο για Claude users) |

### Fallback

Αν το RAG είναι ανενεργό ή ο index είναι κενός, το σύστημα επιστρέφει αυτόματα στην παλιά μέθοδο (εισαγωγή όλων των προϊόντων στο prompt).

### Αρχιτεκτονική DB

```
wp_cacb_embeddings
├── id           — AUTO INCREMENT
├── object_type  — 'product' | 'page'
├── object_id    — WooCommerce product ID ή WordPress post ID
├── content_hash — MD5 του κειμένου (αποφυγή περιττών API calls)
├── embedding    — JSON float array (1 536 ή 768 διαστάσεις)
├── dims         — Αριθμός διαστάσεων
└── indexed_at   — Timestamp τελευταίας ευρετηρίασης
```

---

## Changelog

### v1.2.5 — AI Providers tab & security hardening

**Admin panel restructure** (`includes/settings.php`)
- Νέο tab **"🤖 AI Providers"** — provider selector, API keys, models, και limits & ασφάλεια σε ξεχωριστή καρτέλα
- Νέο `cacb_providers_group` settings group — αποθήκευση από οποιοδήποτε tab δεν επηρεάζει τα πεδία των άλλων
- Το tab "Ρυθμίσεις" περιέχει πλέον μόνο: System Prompt, WooCommerce, Logging, Εμφάνιση

**Security fixes**
- CSRF protection στο "Καθαρισμός cache" link — προστέθηκε `wp_nonce_url()` + `check_admin_referer()`
- `wp_unslash()` πριν από κάθε `sanitize_*` σε `$_GET` parameters στο log viewer
- Server-side message length cap (4 000 χαρακτήρες) — αποτρέπει API credit drain από oversized payloads
- `cacb_sanitize_option` rewrite: exact match με `str_replace()` αντί για εύθραυστο `strpos()`, numeric clamping για όλα τα αριθμητικά πεδία, whitelist validation για enums (`cacb_provider`, `cacb_model`, `cacb_bubble_position` κ.ά.)

**Bug fixes**
- WooCommerce toggle JS bug — το `querySelector('[name="cacb_wc_enabled"]')` επέστρεφε το hidden input αντί για το checkbox· διορθώθηκε με `id="cacb_wc_enabled"` + `getElementById()`
- `cacb_wc_enabled` προστέθηκε στα boolean options για consistent `'1'/'0'` storage

---

### v1.2.1 — Bug fixes

**Settings save bug** (`includes/settings.php`)
- Τα RAG settings και τα κύρια settings χρησιμοποιούσαν το ίδιο `cacb_settings_group`. Αποθηκεύοντας από το ένα tab αντικαθιστούσε τα settings του άλλου. Διορθώθηκε με ξεχωριστό `cacb_rag_group` για το Knowledge Base tab.
- Το checkbox `cacb_wc_enabled` δεν είχε hidden field, οπότε δεν μπορούσε να αποενεργοποιηθεί μέσω της φόρμας.

**Elementor page indexing** (`includes/embeddings.php`)
- Οι σελίδες φτιαγμένες με Elementor αποθηκεύουν το κείμενό τους στο `_elementor_data` meta (JSON), όχι στο `post_content`. Προστέθηκε η `cacb_extract_page_text()` που ανιχνεύει Elementor σελίδες και εξάγει κείμενο από τα widgets (heading, text editor, description, κλπ.).

**Index progress error visibility** (`assets/admin.js`)
- Το `runBatchIndex()` έδειχνε πάντα ✅ μετά το τέλος, ακόμα και αν όλα τα items απέτυχαν (π.χ. λάθος API key). Τώρα παρακολουθεί `totalIndexed` και `totalErrors` σε όλα τα batches και εμφανίζει το πραγματικό μήνυμα σφάλματος όταν τίποτα δεν έγινε indexed.

---

### v1.2.0 — RAG / Knowledge Base

- Πλήρης υλοποίηση RAG (Retrieval-Augmented Generation) με vector embeddings
- Υποστήριξη OpenAI `text-embedding-3-small` (1 536 dims) και Gemini `text-embedding-004` (768 dims)
- Νέος πίνακας `wp_cacb_embeddings` στη βάση δεδομένων
- Admin tab "Knowledge Base": status, batch indexing, progress bar, clear index
- Cosine similarity σε pure PHP — fallback στην παλιά μέθοδο αν RAG ανενεργό ή index κενός
- Content hash deduplication — αποφυγή περιττών embedding API calls
- WP-Cron async auto-reindex κατά αποθήκευση προϊόντος/σελίδας
- White-label rebrand: `Capitano AI Chatbot` → `Smart AI Chatbot`

---

### v1.1.0 — Initial release

- OpenAI GPT, Anthropic Claude, Google Gemini support
- Streaming (SSE) απαντήσεις
- AES-256-GCM κρυπτογράφηση API keys
- Rate limiting, history limit, logging
- WooCommerce product context integration
- Privacy notice, bubble position, color customization

---

## Uninstall

Διαγραφή από **WP Admin → Plugins → Delete**:
- Αφαιρεί όλα τα options (συμπεριλαμβανομένων των κρυπτογραφημένων API keys) από `wp_options`
- Αφαιρεί όλα τα rate limit transients
- Αφαιρεί το WooCommerce product cache
- Δεν αφήνει τίποτα πίσω στη βάση
