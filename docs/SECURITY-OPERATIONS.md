# Security operations

Operational security reference for UniPayment PrestaShop 9 (Phase 4 inbound API + Phase 7 popup identity).

Related: [`ARCHITECTURE.md`](ARCHITECTURE.md), [`TESTING.md`](TESTING.md)

---

## 1. Secrets

| Secret              | Role                                                        |
| ------------------- | ----------------------------------------------------------- |
| **UNICID**          | Shop identity in Control Panel                              |
| **Shared secret**   | HMAC for CP → module signed requests; CP auth login         |
| **CP access token** | Bearer for outbound CP calls (`TokenRepository`, encrypted) |

Never log secrets, tokens, Authorization headers, or decrypted SECRET.

---

## 2. CP → module signed request protocol

Implementation: `ModuleRequestSignatureProtocol`, `ModuleRequestSignatureVerifier`, `ModuleRequestAuthenticator`, `ApiNonceRepository`.

### Headers (required)

```text
X-UniPayment-Timestamp
X-UniPayment-Nonce
X-UniPayment-Signature
```

### Canonical string

```text
{timestamp}\n{nonce}\n{raw_request_body}
```

`raw_request_body` is the **exact** HTTP body bytes (`php://input`). Do not re-encode JSON before HMAC.

JSON payload must include `unicid` matching the configured shop UNICID.

### Signature

- Algorithm: **HMAC-SHA256**
- Key: decrypted shared secret
- Encoding: **lowercase hex** (`hash_hmac`)
- Compare with **`hash_equals()`**

### Timestamp

- Numeric (`ctype_digit`)
- Window: **±300 seconds**

### Nonce

- Format: **64 hex characters** (`[0-9a-fA-F]{64}`) — 32 random bytes hex-encoded
- Retention: **900 seconds**
- Stored as `sha256(nonce)` under unique `(unicid, nonce_hash)`

### Ordering (audited)

```text
module enabled / configured
→ payload unicid matches store
→ validate timestamp + nonce format
→ verify HMAC on raw body
→ atomically claim nonce
→ endpoint handler
```

Invalid signature **must not** consume a nonce.

### Auth failure

HTTP **401**, message: `Невалидна или изтекла заявка към модула.` (generic; no oracle for guessing).

Disabled module: **403**. Not configured: **401**.

---

## 3. Inbound endpoints (Phase 4)

| Path                                  | Role                                                  |
| ------------------------------------- | ----------------------------------------------------- |
| `/module/unipayment/shopcache`        | CP pushes full shop snapshot → `replaceSnapshot`      |
| `/module/unipayment/orderbankstatus`  | Persist bank status for financing order (AUD-011)     |
| `/module/unipayment/smartucfdebuglog` | CP **reads** latest SmartUCF diagnostic journal entry |

All: **POST** only, JSON body, signed headers.

### shopcache

- Full replacement only (no merge)
- Invalid snapshot → HTTP 422, **keep** previous valid cache
- Snapshot `unicid` must match authenticated shop when present

### orderbankstatus

- Lookup: `orders.reference` + `id_shop` + INNER JOIN `unipayment_financing_snapshot` (AUD-011)
- Phase 4 does **not** install `unipayment_financing_snapshot`. Before the JOIN, the repository checks table existence with `SHOW TABLES LIKE`; if absent → `null` → HTTP **404** (no SQL error / 500). When a later phase creates the table, the audited JOIN activates automatically.
- No customer-facing order-state changes in Phase 4 (`ps_order_state_changed: false`)

### smartucfdebuglog

- Read path via `SmartUcfDiagnosticJournal`
- Writes go through `record()` only when `UNIPAYMENT_DEBUG_ENABLED` (outbound SmartUCF later)
- Responses sanitize secrets / PII keys

---

## 4. Replay table

```text
{prefix}unipayment_api_nonce
```

Unique: `(unicid, nonce_hash)`. Probabilistic purge of expired rows on claim.

---

## 5. Control Panel platform mapping

CP `config/uni.php` maps **PrestaShop 9.x** to the same module paths as PrestaShop 8.x:

```text
module/unipayment/shopcache
module/unipayment/smartucfdebuglog
module/unipayment/orderbankstatus
```

Verify shop platform enum is `PrestaShop 9.x` before live CP push tests.

---

## 6. Local signed request helper

```bash
php bin/signed-module-request.php --print-headers \
  --secret-env=UNIPAYMENT_LIVE_SECRET \
  --body='{"unicid":"..."}'

php bin/signed-module-request.php --post \
  --url=https://presta9.avalonbg.com/module/unipayment/shopcache \
  --secret-env=UNIPAYMENT_LIVE_SECRET \
  --body-file=/tmp/shopcache-body.json
```

Does not bypass authentication. Never prints the secret.

---

## 7. Popup submission identity (Phase 7)

Distinct from Phase 4 HMAC nonce replay (CP → module). Protects **customer popup operations**.

| Item                               | Value                                                                                                 |
| ---------------------------------- | ----------------------------------------------------------------------------------------------------- |
| Table                              | `{prefix}unipayment_popup_submission`                                                                 |
| Token                              | `bin2hex(random_bytes(32))` — 64 hex chars, UNIQUE                                                    |
| Issued TTL                         | **1800** seconds                                                                                      |
| Claim                              | Atomic `UPDATE … WHERE state=issued AND expires_at > now`                                             |
| Binding                            | SHA-256 JSON of shop, product, combination, qty, scheme, first installment, `id_guest`, `id_customer` |
| Logged-in identity                 | `Customer::isLogged()` from Context — never POST `id_customer` / email                                |
| Guest identity                     | `$cookie->id_guest` — not transferable to another session                                             |
| CSRF                               | PrestaShop `Tools::getToken(false)` remains required in addition to the submission token              |
| Client `preselect_operation_token` | Non-authoritative correlation ID for Silent Buy cookie idempotency                                    |

Do not log: raw `popup_submission_token`, EGN, session cookie, secrets.

Expired / replayed / wrong-shop / wrong-identity operations return safe customer-facing JSON (no SQL, no token hash, no stack traces).

Cleanup: opportunistic `DELETE` of expired `issued` / `failed` / `identity_accepted` rows (1/20 of issue attempts). `processing` and `order_created` are never purged here.
