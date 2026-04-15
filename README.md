# Capitano AI Chatbot — WordPress Plugin

AI-powered chatbot για WordPress/WooCommerce με υποστήριξη **OpenAI (GPT)**, **Anthropic (Claude)** και **Google (Gemini)**.
Production-ready με streaming απαντήσεις, AES-256-GCM encryption, rate limiting, και πλήρη admin controls.

---

## Εγκατάσταση

1. Ανέβασε τον φάκελο `capitano-chatbot` στο `/wp-content/plugins/`
2. Ενεργοποίησε το plugin από **WP Admin → Plugins**
3. Πήγαινε στο **Settings → AI Chatbot** και:
   - Επίλεξε τον AI Provider (OpenAI / Claude / Gemini)
   - Συμπλήρωσε το αντίστοιχο API Key
   - Ρύθμισε το System Prompt και την εμφάνιση

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

---

## Αρχιτεκτονική

```
capitano-chatbot/
├── capitano-chatbot.php   ← Bootstrap, activation, constants
├── uninstall.php          ← Καθαρισμός βάσης κατά τη διαγραφή
├── includes/
│   ├── settings.php       ← Admin page, WP options, AES-256-GCM encryption
│   ├── api.php            ← REST endpoint + streaming (SSE) για OpenAI/Claude/Gemini
│   └── frontend.php       ← Asset enqueue + chat HTML output
└── assets/
    ├── chat.js            ← UI logic, SSE streaming, localStorage history
    └── chat.css           ← Styles, animations
```

### Streaming (SSE)

Οι απαντήσεις των AI στέλνονται σε πραγματικό χρόνο μέσω Server-Sent Events:
- **Server**: `admin-ajax.php` → cURL με `CURLOPT_WRITEFUNCTION` → forward chunks στον browser
- **Client**: `fetch` + `ReadableStream` + `TextDecoder` → προοδευτική εμφάνιση κειμένου

---

## Limits (Settings → AI Chatbot → Limits & Ασφάλεια)

| Ρύθμιση | Εύρος | Default | Περιγραφή |
|---|---|---|---|
| Rate limit | 1–200 | 20 | Μέγιστα μηνύματα ανά IP ανά ώρα |
| Max tokens | 100–2000 | 500 | Μέγιστο μέγεθος απάντησης (έλεγχος κόστους) |
| History limit | 2–50 | 10 | Πόσα τελευταία μηνύματα να θυμάται |

---

## WooCommerce Integration

Αν το WooCommerce είναι ενεργό, μπορείς να ενεργοποιήσεις την αυτόματη ενσωμάτωση προϊόντων στο system prompt:

- Τα προϊόντα φορτώνονται live από το κατάστημα (όνομα, τιμή, SKU, διαθεσιμότητα, κατηγορία)
- Αποθηκεύονται σε cache 1 ώρα — ανανεώνονται αυτόματα όταν αλλάξει προϊόν
- Φιλτράρισμα ανά κατηγορία (slugs χωρισμένα με κόμμα)

---

## Uninstall

Διαγραφή από **WP Admin → Plugins → Delete**:
- Αφαιρεί όλα τα options (συμπεριλαμβανομένων των κρυπτογραφημένων API keys) από `wp_options`
- Αφαιρεί όλα τα rate limit transients
- Αφαιρεί το WooCommerce product cache
- Δεν αφήνει τίποτα πίσω στη βάση
