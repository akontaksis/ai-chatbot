# Smart AI Chatbot — WordPress Plugin

**Version 1.4.6**

AI-powered chatbot για WordPress/WooCommerce με υποστήριξη **OpenAI (GPT)** και **Anthropic (Claude)**. Production-ready με **Function Calling** για ακριβή αναζήτηση προϊόντων, **RAG (Retrieval-Augmented Generation)** για σελίδες/FAQ, **product cards** με add-to-cart, **AES-256-GCM encryption** για API keys, **rate limiting**, και πλήρη admin controls.

---

## Περιεχόμενα

- [Γρήγορη εκκίνηση](#γρήγορη-εκκίνηση)
- [AI Providers](#ai-providers)
- [Αρχιτεκτονική](#αρχιτεκτονική)
- [WooCommerce Integration — Function Calling](#woocommerce-integration--function-calling)
- [RAG — Knowledge Base](#rag--knowledge-base-semantic-search)
- [Ασφάλεια](#ασφάλεια)
- [Αποθήκευση & Διαγραφή Δεδομένων](#αποθήκευση--διαγραφή-δεδομένων)
- [Limits & Configuration](#limits--configuration)
- [REST API Reference](#rest-api-reference)
- [Changelog](#changelog)
- [Roadmap](#roadmap)

---

## Γρήγορη εκκίνηση

1. Ανέβασε τον φάκελο `smart-ai-chatbot` στο `/wp-content/plugins/`
2. Ενεργοποίησε το plugin από **WP Admin → Plugins**
3. Ρύθμισε το από **Settings → AI Chatbot**:
   - **AI Providers**: provider, API key, model, limits
   - **Ρυθμίσεις**: System Prompt, WooCommerce, Logging, εμφάνιση
   - **Knowledge Base** (προαιρετικό): ενεργοποίηση RAG, index σελίδων

---

## AI Providers

### OpenAI (GPT)
API key από [platform.openai.com](https://platform.openai.com).

| Model | Περιγραφή | Function Calling |
|---|---|---|
| `gpt-4o` | Κορυφαία ποιότητα, άριστα ελληνικά | ✓ Parallel tool calls |
| `gpt-4o-mini` | Γρήγορο & φθηνό — **προτεινόμενο** | ✓ Parallel tool calls |
| `gpt-5-mini` | Νέα γενιά, reasoning model | ✓ Parallel tool calls |
| `gpt-5-nano` | Νέα γενιά, οικονομικό | ✓ Parallel tool calls |

```php
// wp-config.php (προαιρετικό — έχει προτεραιότητα έναντι DB)
define( 'CACB_OPENAI_API_KEY', 'sk-...' );
```

### Anthropic (Claude)
API key από [console.anthropic.com](https://console.anthropic.com).

| Model | Περιγραφή | Function Calling |
|---|---|---|
| `claude-sonnet-4-6` | Ισορροπία ταχύτητας/ποιότητας — **προτεινόμενο** | ✓ 1 tool per turn |
| `claude-opus-4-7` | Κορυφαία ποιότητα, reasoning | ✓ 1 tool per turn |
| `claude-haiku-4-5-20251001` | Γρήγορο & φθηνό | ✓ 1 tool per turn |

```php
define( 'CACB_CLAUDE_API_KEY', 'sk-ant-...' );
```

> **Σημείωση:** Το Claude δεν παρέχει Embeddings API. Αν χρησιμοποιείς Claude με RAG, χρειάζεσαι ένα επιπλέον OpenAI key (αποθηκεύεται στο `cacb_rag_openai_key`) αποκλειστικά για το generation των embeddings.

---

## Αρχιτεκτονική

```
smart-ai-chatbot/
├── smart-ai-chatbot.php     ← Bootstrap, activation hooks, DB migrations, constants
├── uninstall.php            ← Καθαρισμός βάσης κατά τη διαγραφή του plugin
├── includes/
│   ├── settings.php         ← Admin UI, WP options, AES-256-GCM encryption
│   ├── embeddings.php       ← RAG engine: chunking, embeddings, cosine similarity
│   ├── api.php              ← REST API, AI providers, function calling, rate limiting
│   ├── logs.php             ← Conversation logging, admin viewer, retention
│   └── frontend.php         ← Asset enqueue, chat HTML injection
└── assets/
    ├── chat.js              ← Chat UI, product cards, localStorage history, markdown
    ├── chat.css             ← Chat styles, animations
    └── admin.js             ← Admin panel logic: key test, RAG index progress
```

### Data Flow

```
┌──────────────┐     ┌──────────────────┐     ┌─────────────────┐
│  User (JS)   │ ──► │ POST /cacb/v1/   │ ──► │  Provider       │
│  chat.js     │     │      chat        │     │  OpenAI/Claude  │
└──────────────┘     └──────────────────┘     └────────┬────────┘
       ▲                      │                        │
       │                      ▼                        │ (if tool_call)
       │              ┌───────────────┐                ▼
       │              │ RAG context   │        ┌─────────────────┐
       │              │ (cosine sim)  │        │ search_products │
       │              └───────────────┘        │ wc_get_products │
       │                      │                └────────┬────────┘
       │                      ▼                         │
       │              ┌───────────────┐                 │
       └──────────────┤ REST response │◄────────────────┘
                      └───────────────┘  (2nd provider call with tool result)
```

### REST Endpoints

| Endpoint | Method | Auth | Σκοπός |
|---|---|---|---|
| `/cacb/v1/chat` | POST | Nonce | Αποστολή μηνύματος → AI response |
| `/cacb/v1/product/{id}` | GET | Public | Δεδομένα product card (όνομα, τιμή, εικόνα) |
| `wp-admin/admin-ajax.php?action=cacb_add_to_cart` | POST | Nonce | Add to cart από chat |
| `wp-admin/admin-ajax.php?action=cacb_refresh_nonce` | POST | Public | Refresh nonce μετά από 12-24h |

---

## WooCommerce Integration — Function Calling

Αν το WooCommerce είναι ενεργό, το plugin εκθέτει στο LLM ένα **tool** με όνομα `search_products`. Το LLM αποφασίζει πότε και με ποια φίλτρα να το καλέσει. Η αναζήτηση τρέχει **server-side** μέσω `wc_get_products()` — **τίποτα δεν εκτίθεται στο LLM από τον κατάλογο**, εκτός από τα αποτελέσματα του κάθε query.

### 2-turn Flow

1. **1ο API call** — αποστολή μηνύματος + tool definition
2. LLM αποφασίζει να καλέσει `search_products` με συγκεκριμένα φίλτρα
3. PHP εκτελεί `wc_get_products()` με τα φίλτρα
4. **2ο API call** — τα αποτελέσματα επιστρέφονται στο LLM για τη φυσική γλωσσική απάντηση
5. LLM απαντά με `[PRODUCT:ID]` markers → JavaScript εμφανίζει product cards

### Tool Parameters

| Parameter | Τύπος | Πηγή | Παράδειγμα |
|---|---|---|---|
| `keyword` | string | WP full-text search (`s=`) | "Gerovassiliou" |
| `category` | enum | WC product categories | "krasia" |
| `min_price` | number | meta query σε `_price` | 30 (για "πάνω από 30€") |
| `max_price` | number | meta query σε `_price` | 15 (για "κάτω από 15€") |
| `sort_by_price` | enum | `asc` / `desc` | "asc" (για "φθηνότερο") |
| `on_sale` | boolean | meta query σε `_sale_price > 0` | true (για "προσφορές") |
| `year` | enum | WC attribute `pa_xronia` | "2019" |
| `grape_variety` | enum | WC attribute `pa_poikilia` | "Ασύρτικο" |
| `region` | enum | WC attribute `pa_perioxi` | "Σαντορίνη" |
| `origin` | enum | WC attribute `pa_proeleusi` | "Γαλλία" |
| `sweetness` | enum | WC attribute `pa_glykytita` | "Ξηρό" |

> **Dynamic enums:** Οι τιμές για `category`, `year`, `grape_variety`, `region`, `origin`, `sweetness` διαβάζονται **δυναμικά** από τα WooCommerce attributes/categories του site. Κάθε νέο attribute term εμφανίζεται αυτόματα στο tool schema χωρίς code change.

### Provider Differences

| | OpenAI | Claude |
|---|---|---|
| Tool schema | `{type: "function", function: {...}}` | `{name, description, input_schema}` |
| Tool trigger | `finish_reason === "tool_calls"` | `stop_reason === "tool_use"` |
| Tool result | `role: "tool"` + `tool_call_id` | `role: "user"` + `type: "tool_result"` |
| Parallel tools | ✓ Ναι, multiple tool_calls σε ένα turn | ✗ Μόνο 1 tool per turn |
| System prompt | Μέσα στα `messages[]` | Ξεχωριστό πεδίο `system:` |

### Reliability Fixes (v1.4.6)

Το `wc_get_products()` με `min_price`, `max_price`, `orderby => 'price'`, και `on_sale => true` στηρίζεται στον πίνακα `wc_product_meta_lookup` του WooCommerce, που σε ορισμένες εγκαταστάσεις δεν είναι πλήρως συγχρονισμένος. Αυτό προκαλούσε **false negatives** (κενά αποτελέσματα ενώ υπήρχαν προϊόντα). Η λύση:

- **Price filtering:** `meta_query` απευθείας στο `_price` meta
- **Price sorting:** `orderby => 'meta_value_num'` με `meta_key => '_price'`
- **On-sale filter:** `meta_query` στο `_sale_price > 0`

Αυτή η προσέγγιση είναι ανεξάρτητη από το lookup table και δουλεύει παντού.

---

## RAG — Knowledge Base (Semantic Search)

Το RAG χρησιμοποιείται **αποκλειστικά για σελίδες/FAQ** (πολιτική επιστροφών, αποστολή, επικοινωνία, about). Τα **WooCommerce προϊόντα εξυπηρετούνται αποκλειστικά μέσω function calling** — δεν αποθηκεύονται στον RAG index.

### Indexing Pipeline

1. Εξαγωγή κειμένου από κάθε σελίδα (υποστήριξη Elementor `_elementor_data`)
2. Chunking σε **200-word chunks με 40-word overlap** (preserves context across boundaries)
3. Γένεση embedding μέσω OpenAI `text-embedding-3-small` (1 536 διαστάσεις)
4. Αποθήκευση στο `wp_cacb_embeddings` με content hash για change detection

### Retrieval Pipeline

1. Embedding της τελευταίας 3 μηνυμάτων (context-aware για follow-ups)
2. Load όλων των stored embeddings και υπολογισμός **cosine similarity** σε PHP
3. Filter: score ≥ `0.18` threshold (αποφυγή noise)
4. Deduplication: μόνο το καλύτερο chunk ανά σελίδα
5. Top-K (default 5) chunks εισάγονται στο system prompt

### Database Schema

```sql
CREATE TABLE wp_cacb_embeddings (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    object_type  VARCHAR(20)      NOT NULL DEFAULT 'page',
    object_id    BIGINT UNSIGNED  NOT NULL,
    chunk_index  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    chunk_text   MEDIUMTEXT,
    content_hash CHAR(32)         NOT NULL,
    embedding    LONGTEXT         NOT NULL,   -- JSON float[]
    dims         SMALLINT UNSIGNED NOT NULL DEFAULT 1536,
    indexed_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_object (object_type, object_id, chunk_index),
    KEY        idx_type   (object_type)
);
```

### Settings

| Option | Default | Περιγραφή |
|---|---|---|
| `cacb_rag_enabled` | `0` | Ενεργοποίηση RAG retrieval |
| `cacb_rag_top_k` | `5` | Max chunks ανά ερώτηση |
| `cacb_rag_index_pages` | `0` | Auto-reindex σελίδων κατά το save (async) |
| `cacb_rag_openai_key` | `''` | OpenAI key για embeddings όταν ο chat provider είναι Claude |

### Scaling Limits

Η τρέχουσα υλοποίηση φορτώνει όλα τα embeddings στη PHP memory για τον similarity υπολογισμό. Πρακτικά όρια:

| Indexed Pages | Performance |
|---|---|
| < 200 | Instant — δεν χρειάζεται βελτιστοποίηση |
| 200 – 2 000 | Αξιόπιστο, ~50–150ms retrieval |
| 2 000+ | Συνιστάται vector DB (Pinecone/Qdrant) ή caching |

Αρχιτεκτονικά, το plugin είναι έτοιμο να δεχθεί vector DB abstraction — οι μόνες αλλαγές αφορούν τα `cacb_index_page()` και `cacb_rag_retrieve()`.

---

## Ασφάλεια

| Μηχανισμός | Υλοποίηση |
|---|---|
| **API key encryption** | AES-256-GCM (authenticated encryption) με 96-bit nonce + 128-bit tag |
| **Encryption key derivation** | SHA-256(`AUTH_KEY` + `SECURE_AUTH_KEY`) από `wp-config.php` |
| **Legacy compatibility** | Παλιά AES-256-CBC keys (prefix `cacb_enc:`) αποκρυπτογραφούνται και ανανεώνονται σε GCM στην επόμενη αποθήκευση |
| **wp-config.php override** | Constants (`CACB_OPENAI_API_KEY`, `CACB_CLAUDE_API_KEY`) για keys εκτός βάσης |
| **CSRF protection** | WP nonce verification σε κάθε REST/AJAX call |
| **Rate limiting** | Per-IP (SHA-256 hashed) transients, ρυθμιζόμενο 1–200/hour |
| **Input sanitization** | `sanitize_text_field()`, `sanitize_textarea_field()`, role whitelist (`user`/`assistant` only) |
| **Output escaping** | `wp_kses()` με allowlist (`<br>`, `<strong>`, `<em>`, `<a>`) |
| **Capability enforcement** | `current_user_can('manage_options')` για όλες τις admin ενέργειες |
| **Enum whitelisting** | `provider`, `model`, `bubble_position` κ.λπ. validated πριν την αποθήκευση |
| **Message length cap** | 4 000 χαρακτήρες server-side — αποτρέπει API credit drain |
| **Privacy-first IP** | Στα logs αποθηκεύεται μόνο `hash('sha256', $ip)` — ποτέ raw IP |
| **Cloudflare-aware IP** | `HTTP_CF_CONNECTING_IP` → `HTTP_X_FORWARDED_FOR` → `HTTP_X_REAL_IP` → `REMOTE_ADDR` |
| **SSL verification** | `CURLOPT_SSL_VERIFYPEER` ενεργό σε όλα τα outbound calls |

---

## Αποθήκευση & Διαγραφή Δεδομένων

### Πλήρης Χάρτης

| Δεδομένο | Πού | Μορφή | Διάρκεια | Διαγραφή |
|---|---|---|---|---|
| OpenAI API Key | `wp_options` | AES-256-GCM | Μόνιμη | Admin UI ή uninstall |
| Claude API Key | `wp_options` | AES-256-GCM | Μόνιμη | Admin UI ή uninstall |
| IP (rate limit) | `wp_options` transient | SHA-256 hash | 1 ώρα | Αυτόματα ή uninstall |
| IP (logs) | `wp_cacb_logs` | SHA-256 hash | Configurable | Admin UI ή uninstall |
| Ιστορικό (browser) | `localStorage` | JSON plaintext | Αόριστη | Κουμπί 🗑 στο chat |
| Μηνύματα (logs) | `wp_cacb_logs` | Plaintext | Configurable (30d default) | Admin UI ή uninstall |
| Vector Embeddings | `wp_cacb_embeddings` | JSON float array | Μόνιμη | Admin UI ή uninstall |
| Ρυθμίσεις plugin | `wp_options` | Plaintext | Μόνιμη | Uninstall |

### API Keys

- **Αποθήκευση:** `wp_options` (κλειδιά: `cacb_api_key`, `cacb_claude_api_key`, `cacb_rag_openai_key`)
- **Μορφή:** `cacb_enc2:` + Base64(nonce[12] + tag[16] + ciphertext)
- **Admin view:** Password field — η τιμή **δεν αποστέλλεται ποτέ** πίσω στον browser
- **Uninstall:** Αυτόματη διαγραφή μέσω `uninstall.php`

> Αν το `openssl` extension δεν είναι διαθέσιμο ή τα WordPress secret keys δεν έχουν οριστεί, το API key **δεν αποθηκεύεται** και εμφανίζεται error στον admin.

### Ιστορικό Συνομιλίας (2 επίπεδα)

**Επίπεδο 1 — Browser (localStorage)**
- Κλειδί: `cacb_chatHistory`
- Περιεχόμενο: JSON array με `{role, content}` για context restoration μετά από refresh
- Στέλνεται σε κάθε request για context, αλλά **δεν ξαναποθηκεύεται** στον server από εκεί

**Επίπεδο 2 — Server (wp_cacb_logs)**
- Καταγράφεται μόνο το τελευταίο ζεύγος user/bot ανά request (όχι ολόκληρο το ιστορικό)
- Retention: 30 ημέρες default, ρυθμιζόμενο
- Auto-prune σε ~10% των writes για αποφυγή overhead

---

## Limits & Configuration

### AI Providers → Limits & Ασφάλεια

| Option | Εύρος | Default | Περιγραφή |
|---|---|---|---|
| `cacb_rate_limit` | 1–200 | 20 | Max μηνύματα ανά IP/hour |
| `cacb_max_tokens` | 100–2000 | 500 | Max μέγεθος απάντησης AI |
| `cacb_history_limit` | 2–50 | 10 | Recent messages για context |
| `cacb_wc_limit` | 1–20 | 8 | Max αποτελέσματα `search_products` |
| `CACB_MAX_MSG_CHARS` | — | 4000 | Max χαρακτήρες ανά μήνυμα user |

### System Prompt — Παράδειγμα για κάβα κρασιών

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

Απάντα πάντα στα Ελληνικά με φιλικό τόνο.
Αν δεν ξέρεις κάτι, πες ότι θα επικοινωνήσει μαζί τους η ομάδα.
```

---

## REST API Reference

### `POST /cacb/v1/chat`

**Headers:**
```
Content-Type: application/json
```

**Body:**
```json
{
  "nonce": "abc123...",
  "messages": [
    { "role": "user", "content": "κόκκινα κρασιά κάτω από 15€" }
  ]
}
```

**Response (200):**
```json
{
  "reply": "Βρήκα δύο προτάσεις: το [PRODUCT:42] και το [PRODUCT:87]..."
}
```

**Errors:**
| Code | Reason |
|---|---|
| 403 | Invalid nonce |
| 429 | Rate limit exceeded |
| 502 | Upstream AI provider error |

### `GET /cacb/v1/product/{id}`

**Response (200):**
```json
{
  "name": "Κτήμα Γεροβασιλείου",
  "price": "14.50",
  "regular_price": "16.00",
  "sale_price": "14.50",
  "image": "https://.../image.jpg",
  "url": "https://.../product/..."
}
```

---

## Changelog

### v1.4.6 — Product search reliability & new filters

**Tool schema improvements** (`includes/api.php`)
- Προσθήκη `sort_by_price` param (enum: `asc`/`desc`) για ερωτήσεις τύπου "φθηνότερο/ακριβότερο"
- Προσθήκη `on_sale` boolean param για προϊόντα σε προσφορά
- Βελτιωμένη περιγραφή `min_price` με παράδειγμα ("πάνω από 30€")
- Οδηγία στο tool description για price range (min+max μαζί) και category usage όταν ο χρήστης αναφέρει τύπο προϊόντος

**Reliability fixes — bypass `wc_product_meta_lookup`** (`includes/api.php`)
- Price filtering: `meta_query` σε `_price` αντί για `wc_get_products()` native `min_price`/`max_price` args
- Price sorting: `orderby => 'meta_value_num'` + `meta_key => '_price'` αντί για `orderby => 'price'`
- On-sale filtering: `meta_query` σε `_sale_price > 0` αντί για `on_sale => true`

Αυτές οι αλλαγές διορθώνουν false negatives σε εγκαταστάσεις όπου ο `wc_product_meta_lookup` πίνακας δεν είναι πλήρως συγχρονισμένος.

**UX fix — log timestamps** (`includes/logs.php`)
- Διόρθωση timezone conversion: `strtotime($created_at . ' UTC')` πριν την κλήση στο `wp_date()`
- Πριν: λάθος ώρα σε εγκαταστάσεις όπου ο PHP timezone ≠ WordPress timezone

---

### v1.4.5 — Admin logs improvements

Μικροδιορθώσεις στο admin log viewer και styling του chat window.

---

### v1.4.2 — Professional audit: error handling, dead code cleanup

**Error handling hardening** (`includes/api.php`)
- HTTP status + array validation στα 2nd calls (OpenAI & Claude) μετά από tool execution
- `is_array()` type-check σε όλες τις `json_decode()` καταναλώσεις
- Type validation στο Claude `tool_use.input` πριν το χρησιμοποιήσει
- WooCommerce availability guard στο `cacb_get_tool_definitions()`

**Dead code cleanup**
- Αφαίρεση orphan option `cacb_wc_categories`
- Αφαίρεση dead transient `cacb_wc_products_cache` και `cacb_maybe_clear_cache()` handler
- Αφαίρεση product indexing στο RAG engine — τα products εξυπηρετούνται αποκλειστικά μέσω function calling
- Καθαρισμός undefined `$btnP` references στο `assets/admin.js`

---

### v1.4.1 — Attribute-based search + temperature fix

- Προσθήκη 5 tool params: `year`, `grape_variety`, `region`, `origin`, `sweetness` (μέσω `tax_query`)
- Temperature 0.7 → 0.2 (consistency fix)
- Dynamic enums από `get_terms()`

---

### v1.4.0 — Function calling για WC προϊόντα, αφαίρεση Gemini

- Αντικατάσταση RAG-για-προϊόντα με 2-turn function calling flow
- Tool `search_products` με base filters (`keyword`, `category`, `min_price`, `max_price`)
- Αφαίρεση Gemini από όλα τα αρχεία
- SQL fix στο `includes/logs.php`

---

### v1.3.0 — Product cards, code cleanup

- Νέο endpoint `GET /cacb/v1/product/{id}` για product card data
- `[PRODUCT:ID]` markers στο LLM response → async product card rendering στον browser
- Αφαίρεση SSE streaming (~270 lines)

---

### v1.2.6 — Page chunking, Markdown rendering

- 200-word overlapping chunks για μεγάλες σελίδες
- Context-aware RAG query (last 3 messages)
- Per-page deduplication στο retrieval
- Markdown rendering (bold, italic, bullet lists) — XSS-safe

---

### v1.2.5 — AI Providers tab & security hardening

- Νέο "AI Providers" admin tab
- CSRF protection σε cache clear action
- Server-side message length cap (4 000 chars)
- Rewrite `cacb_sanitize_option` με exact match και enum whitelisting

---

### v1.2.0 — Initial RAG

- Vector embeddings με OpenAI `text-embedding-3-small`
- Νέος `wp_cacb_embeddings` πίνακας
- Batch indexing UI με progress bar
- Content hash deduplication

---

### v1.1.0 — Initial release

- OpenAI GPT, Anthropic Claude support
- AES-256-GCM encryption
- Rate limiting, history limit, logging
- Privacy notice, bubble position, color customization

---

## Roadmap

Ιδέες για μελλοντικές εκδόσεις (δεν έχουν υλοποιηθεί):

**UX**
- Streaming responses (SSE) για word-by-word rendering
- Proactive chat triggers (π.χ. user views product 30s)
- Full Markdown (code blocks, tables, links)

**Tools**
- `get_order_status` — "Πού είναι η παραγγελία μου;"
- `check_availability` — real-time stock
- `get_related_products` — cross-sell

**Architecture**
- Provider abstraction interface (cleaner plug-in για νέους providers)
- Vector DB abstraction (Pinecone/Qdrant) για 10K+ indexed content
- Semantic caching (cache παρόμοιων ερωτήσεων)
- Fallback chain (OpenAI → Claude → cached)
- Hybrid search (BM25 + vector combined)

**Integrations**
- WhatsApp / Viber / Messenger
- Email auto-reply
- Analytics dashboard (most-asked, conversion rate)

---

## Uninstall

**WP Admin → Plugins → Delete** διαγράφει πλήρως:

- Όλα τα options (συμπεριλαμβανομένων κρυπτογραφημένων API keys) από `wp_options`
- Όλα τα rate limit transients
- Legacy options από παλαιότερες εκδόσεις (`cacb_gemini_*`, `cacb_wc_categories`)
- Tables `wp_cacb_logs` και `wp_cacb_embeddings`

Το plugin **δεν αφήνει τίποτα πίσω** στη βάση.

---

## License

GPL v2 or later — συμβατό με τη WordPress GPL.
