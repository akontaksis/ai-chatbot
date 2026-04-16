# Smart AI Chatbot — WordPress Plugin

**Version 1.4.1**

AI-powered chatbot για WordPress/WooCommerce με υποστήριξη **OpenAI (GPT)** και **Anthropic (Claude)**.
Production-ready με **Function Calling** για WooCommerce προϊόντα (φιλτράρισμα ανά κατηγορία, χρονιά, ποικιλία, περιοχή, χώρα, γλυκύτητα, τιμή), **RAG** για FAQ/σελίδες, **product cards**, AES-256-GCM encryption, rate limiting, και πλήρη admin controls.

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
| **Κλειδιά** | `cacb_api_key`, `cacb_claude_api_key` |
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
| **Πότε γράφεται** | Μετά από κάθε επιτυχή απάντηση AI |
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
│   ├── api.php            ← REST endpoints: chat (function calling) + product card data για OpenAI/Claude
│   ├── logs.php           ← DB logs, AJAX handlers, log viewer
│   └── frontend.php       ← Asset enqueue + chat HTML output
└── assets/
    ├── chat.js            ← UI logic, product cards, localStorage history
    ├── admin.js           ← Admin panel JS: provider highlight, key test/delete, RAG index
    └── chat.css           ← Styles, animations, product card UI
```

### REST API

Όλες οι επικοινωνίες γίνονται μέσω WP REST API:
- **`POST /cacb/v1/chat`** — Αποστολή μηνύματος, λήψη απάντησης AI
- **`GET /cacb/v1/product/{id}`** — Δεδομένα προϊόντος για product cards (όνομα, τιμή, εικόνα, URL)

---

## Limits (Settings → AI Chatbot → AI Providers → Limits & Ασφάλεια)

| Ρύθμιση | Εύρος | Default | Περιγραφή |
|---|---|---|---|
| Rate limit | 1–200 | 20 | Μέγιστα μηνύματα ανά IP ανά ώρα |
| Max tokens | 100–2000 | 500 | Μέγιστο μέγεθος απάντησης (έλεγχος κόστους) |
| History limit | 2–50 | 10 | Πόσα τελευταία μηνύματα να θυμάται |
| Message length | — | 4 000 | Max χαρακτήρες ανά μήνυμα χρήστη (server-side cap) |

---

## WooCommerce Integration — Function Calling

Αν το WooCommerce είναι ενεργό, το chatbot αναζητά προϊόντα μέσω **Function Calling** (tool use). Το LLM αποφασίζει πότε και με ποια φίλτρα να τρέξει αναζήτηση — χωρίς να φορτώνεται ο κατάλογος στο prompt.

### Flow (2-turn)
1. **1ο API call** — αποστέλλεται μαζί με τον ορισμό του tool `search_products`
2. Το LLM επιλέγει φίλτρα και επιστρέφει `tool_call`
3. **`wc_get_products()`** εκτελείται server-side (άμεση PHP κλήση, χωρίς HTTP)
4. **2ο API call** — τα αποτελέσματα δίνονται πίσω στο LLM για τη φυσική απάντηση

### Διαθέσιμα φίλτρα tool

| Parameter | Πηγή | Παράδειγμα |
|---|---|---|
| `category` | WC product categories | "λευκα-κρασια" |
| `year` | WC attribute `pa_xronia` | "2019" |
| `grape_variety` | WC attribute `pa_poikilia` | "Ασύρτικο" |
| `region` | WC attribute `pa_perioxi` | "Σαντορίνη" |
| `origin` | WC attribute `pa_proeleusi` | "Γαλλία" |
| `sweetness` | WC attribute `pa_glykytita` | "Ξηρό" |
| `max_price` | WC price filter | `15` |
| `min_price` | WC price filter | `10` |
| `keyword` | WP full-text search (`s=`) | "Gerovassiliou" |

Τα enums (χρονιές, ποικιλίες, περιοχές κλπ) διαβάζονται **δυναμικά** από το WC — ενημερώνονται αυτόματα με κάθε νέο προϊόν.

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

### Embedding Provider

| Chat Provider | Embedding API | Key που χρησιμοποιεί |
|---|---|---|
| OpenAI | `text-embedding-3-small` (1 536 dims) | Ίδιο OpenAI key |
| Claude | `text-embedding-3-small` (1 536 dims) | Ξεχωριστό OpenAI key (`cacb_rag_openai_key`) |

> Ο Claude δεν παρέχει Embeddings API. Αν χρησιμοποιείς Claude για chat, χρειάζεσαι ένα επιπλέον OpenAI key αποκλειστικά για embeddings.

### Ρυθμίσεις

| Option | Default | Περιγραφή |
|---|---|---|
| `cacb_rag_enabled` | `0` | Ενεργοποίηση RAG |
| `cacb_rag_top_k` | `5` | Πόσα σχετικά αποτελέσματα να εισάγει στο prompt |
| `cacb_rag_index_pages` | `0` | Ευρετηρίαση WordPress pages εκτός από προϊόντα |
| `cacb_rag_openai_key` | `''` | OpenAI key για embeddings (μόνο για Claude users) |

### Αρχιτεκτονική DB

```
wp_cacb_embeddings
├── id           — AUTO INCREMENT
├── object_type  — 'product' | 'page'
├── object_id    — WooCommerce product ID ή WordPress post ID
├── chunk_index  — 0 για προϊόντα · 0..n για σελίδες (v1.2.6+)
├── chunk_text   — Το κείμενο αυτού του chunk (χρησιμοποιείται απευθείας στο RAG context)
├── content_hash — MD5 ολόκληρου του κειμένου σελίδας (αποθηκεύεται στο chunk 0)
├── embedding    — JSON float array (1 536 ή 768 διαστάσεις)
├── dims         — Αριθμός διαστάσεων
└── indexed_at   — Timestamp τελευταίας ευρετηρίασης
```

> **Page chunking (v1.2.6):** Μεγάλες σελίδες (π.χ. 1 500-λέξεων πολιτική επιστροφών) χωρίζονται αυτόματα σε overlapping chunks των 200 λέξεων (με 40 λέξεις overlap). Κάθε chunk παίρνει το δικό του embedding. Το retrieval βρίσκει το πιο σχετικό chunk ακριβώς εκεί που αναφέρεται η απάντηση, αντί να φέρνει ολόκληρη τη σελίδα.

---

## Changelog

### v1.4.1 — Attribute-based search + temperature fix

**Attribute filters** (`includes/api.php`)
- Προσθήκη 5 νέων tool parameters: `year`, `grape_variety`, `region`, `origin`, `sweetness`
- Χρησιμοποιούν `tax_query` στο `wc_get_products()` αντί για `s=` keyword — ψάχνουν απευθείας στα WC attributes (`pa_xronia`, `pa_poikilia`, `pa_perioxi`, `pa_proeleusi`, `pa_glykytita`)
- Τα enums διαβάζονται δυναμικά από `get_terms()` — ανανεώνονται αυτόματα
- Νέα helper `cacb_get_attribute_terms()` για επαναχρησιμοποιήσιμη ανάκτηση attribute terms

**Temperature fix** (`includes/api.php`)
- Temperature 0.7 → 0.2 για OpenAI και Claude
- Επίλυση inconsistency: το LLM επέλεγε διαφορετικά tool args για ίδια ερώτηση

---

### v1.4.0 — Function calling για WC προϊόντα, αφαίρεση Gemini

**Function Calling** (`includes/api.php`)
- Αντικατάσταση RAG-για-προϊόντα με 2-turn function calling flow
- Tool `search_products` με φίλτρα: `keyword`, `category`, `max_price`, `min_price`
- `cacb_execute_search_products()` — άμεση PHP κλήση `wc_get_products()`, χωρίς HTTP
- OpenAI format: `{"type": "function", "function": {...}}` · Claude format: `{"name": ..., "input_schema": ...}`
- RAG περιορίστηκε αποκλειστικά σε pages/FAQ — τα products skip με `continue`

**Αφαίρεση Gemini** (όλα τα αρχεία)
- Αφαιρέθηκε από `settings.php`, `api.php`, `embeddings.php`, `logs.php`, `uninstall.php`
- Αφαιρέθηκε `cacb_embed_gemini()`, Gemini CSS badge, Gemini στο provider filter

**SQL fix** (`includes/logs.php`)
- Αντικατάσταση interpolated `$wpdb->prepare()` pattern με δύο ξεχωριστά branched queries

---

### v1.3.0 — Product cards, code cleanup

**Product cards** (`includes/api.php`, `assets/chat.js`, `assets/chat.css`)
- Νέο REST endpoint `GET /cacb/v1/product/{id}` — επιστρέφει όνομα, τιμή, εικόνα, URL
- Το RAG context περιέχει πλέον `ID:` για κάθε προϊόν — το LLM εισάγει `[PRODUCT:ID]` markers στην απάντηση
- Το frontend ανιχνεύει τα markers, κάνει async fetch, και εμφανίζει product cards (εικόνα, τιμή, sale price με strikethrough, κουμπί "Προβολή Προϊόντος")

**Αφαίρεση streaming** (`includes/api.php`, `assets/chat.js`)
- Αφαιρέθηκε ο SSE/streaming handler — όλες οι απαντήσεις γίνονται μέσω REST API (`POST /cacb/v1/chat`)
- Απλοποίηση κώδικα: ~270 γραμμές streaming code αφαιρέθηκαν
- Logging γίνεται πλέον αποκλειστικά server-side (αφαιρέθηκε το `cacb_ajax_log_exchange`)

---

### v1.2.6 — Page chunking, richer RAG context, Markdown rendering

**Page chunking** (`includes/embeddings.php`)
- Μεγάλες σελίδες (π.χ. πολιτική απορρήτου 1 500 λέξεων) χωρίζονται πλέον σε overlapping chunks των 200 λέξεων (40 λέξεις overlap) — κάθε chunk αποκτά ξεχωριστό embedding
- Νέα `cacb_chunk_text()` utility function
- Το retrieval βρίσκει το ακριβές τμήμα σελίδας που απαντά στο ερώτημα αντί για ολόκληρη τη σελίδα
- Το `privacy-policy` αφαιρέθηκε από τη λίστα system slugs — πλέον ευρετηριάζεται κανονικά
- Page batch size μειώθηκε σε 2 (από 5) για αποφυγή PHP timeout λόγω πολλαπλών API calls ανά σελίδα

**DB schema migration** (`smart-ai-chatbot.php`, `includes/embeddings.php`)
- Νέες στήλες: `chunk_index` (smallint) και `chunk_text` (mediumtext)
- Νέο UNIQUE KEY: `(object_type, object_id, chunk_index)` αντί για `(object_type, object_id)`
- Αυτόματο migration για υπάρχουσες εγκαταστάσεις μέσω `cacb_maybe_migrate_chunks_schema()` στο `admin_init`
- `COUNT(DISTINCT object_id)` στο RAG status widget — εμφανίζει σωστό αριθμό σελίδων (όχι chunks)

**Richer product context** (`includes/embeddings.php`)
- `cacb_product_to_text()` παίρνει νέο `$desc_limit` parameter: **0 κατά το indexing** (ολόκληρη η περιγραφή → καλύτερο embedding) · **200 λέξεις στο RAG context** (αποφυγή φουσκώματος του prompt)
- Πριν: 100 λέξεις περιγραφή παντού — τώρα: full text για embedding, 200 λέξεις για context
- Context-aware RAG query: χρησιμοποιεί τα τελευταία 3 μηνύματα για σωστή ανάκτηση σε follow-up ερωτήσεις
- Deduplication: αν πολλά chunks ίδιας σελίδας έχουν υψηλό score, εμφανίζεται μόνο το καλύτερο

**Markdown rendering** (`assets/chat.js`, `assets/chat.css`)
- Οι απαντήσεις του bot αποδίδονται με Markdown: **bold**, *italic*, bullet lists (`-`, `*`, `•`)
- XSS-safe: escapeHtml εφαρμόζεται πριν οποιοδήποτε HTML markup

---

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
