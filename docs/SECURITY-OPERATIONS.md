# Security operations

Operational security reference for UniPayment PrestaShop 9 (Phase 4 inbound API + Phase 7 popup identity).

Related: [`ARCHITECTURE.md`](ARCHITECTURE.md), [`TESTING.md`](TESTING.md)

---

## 1. Secrets

| Secret                  | Role                                                                                                                       |
| ----------------------- | -------------------------------------------------------------------------------------------------------------------------- |
| **UNICID**              | Shop identity in Control Panel                                                                                             |
| **Shared secret**       | HMAC for CP → module signed requests; CP auth login                                                                        |
| **CP access token**     | Bearer for outbound CP calls (`TokenRepository`, encrypted)                                                                |
| **mTLS key passphrase** | SmartUCF client private-key decryption (`secrets/smartucf-key.php` in the module ZIP only; never in env, BO config, or DB) |

Never log secrets, tokens, Authorization headers, decrypted SECRET, or the mTLS private-key passphrase.

### mTLS private-key passphrase (AUD-021)

Self-contained ZIP deployment — **no** SSH / PHP-FPM / environment variables.

Edit before packaging (real value only in prepared ZIP; file is Git-ignored):

```php
<?php
// secrets/smartucf-key.php
return [
    'passphrase' => 'REPLACE_WITH_DEPLOYMENT_PASSPHRASE',
];
```

Missing/invalid file → certificate validation and SmartUCF mTLS fail closed. No environment-variable fallback.

Tracked under `secrets/`: `.htaccess`, `index.php` only.

### Control Panel base URL

Single authoritative host in `config/environment.php` (`control_panel_url`). Outbound API calls use `{control_panel_url}/api/v1`.

Maintainer switches development / test / production CP hosts by editing **only** that file before ZIP packaging.

---

## 2. CP → module signed request protocol

Implementation: `ModuleRequestSignatureProtocol`, `ModuleRequestSignatureVerifier`, `ModuleRequestAuthenticator`, `BoundedRawBodyReader`, `ApiNonceRepository`.

Authoritative CP baseline: `0facb6721c0b9199078ce4b6404ed89c00de680c`. Verified PS8 reference: `c62c3be399fd7adeb820d89a0d4e4cc78885e194`.

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

`raw_request_body` is the **exact** HTTP body bytes (bounded reader over `php://input`). Do not re-encode JSON before HMAC. Do not include method/route outside the body.

JSON payload must include `unicid` matching the configured shop UNICID and canonical `operation` matching the endpoint (covered by HMAC because it is inside the raw body).

### Operations and endpoint binding

| Operation            | PrestaShop front controller |
| -------------------- | --------------------------- |
| `shop-cache`         | `shopcache`                 |
| `order-bank-status`  | `orderbankstatus`           |
| `smartucf-debug-log` | `smartucfdebuglog`          |

A valid signed body for one operation must not execute on another endpoint (`unsupported_operation`).

### Signature

- Algorithm: **HMAC-SHA256**
- Key: decrypted shared secret
- Encoding: **lowercase hex** (`hash_hmac`)
- Compare with **`hash_equals()`**

### Timestamp

- Numeric (`ctype_digit`)
- Window: **±300 seconds** inclusive

### Nonce

- Format: **64 lowercase hexadecimal characters** (`^[0-9a-f]{64}$`) — 32 random bytes hex-encoded
- Uppercase hex is rejected
- Retention: **900 seconds**
- Stored as `sha256(nonce)` under unique `(unicid, nonce_hash)`
- Replay storage failure fails closed

### Body size

- Maximum inbound JSON body: **1 MiB** (`1048576` bytes)
- Read via `BoundedRawBodyReader` with hard cap `MAX+1` bytes (no unbounded `file_get_contents('php://input')`)
- Declared `Content-Length` > 1 MiB may fail early; the stream bound remains authoritative
- Oversized requests fail before HMAC / nonce claim / JSON decode (`payload_too_large`, HTTP 413)

### Ordering (audited)

```text
POST only
→ bounded raw body (413 if oversized)
→ module enabled / configured
→ validate timestamp + lowercase nonce + signature format
→ verify HMAC on exact raw body
→ JSON object decode
→ payload unicid matches store
→ atomically claim nonce
→ assert expected operation
→ endpoint handler
```

Invalid signature **must not** consume a nonce.

### Canonical response envelope

Every inbound module API JSON response uses all four fields:

```json
{
    "success": true,
    "error": null,
    "message": "...",
    "data": {}
}
```

Failures set `success` to `false` and `error` to a stable snake_case machine code. `data` is always a JSON object (never a list).

### Local bank status vs durable CP status sync

Local `bank_sent_process1` / `bank_sent_process2` mean **business handoff proven**.

Outbound CP `PATCH /orders/status` confirmation is tracked on `unipayment_financing_snapshot` (`cp_status_sync_*`):

| State             | Meaning                                                                                                  |
| ----------------- | -------------------------------------------------------------------------------------------------------- |
| `not_needed`      | No sync target admitted                                                                                  |
| `pending`         | Target persisted; PATCH not yet confirmed                                                                |
| `confirmed`       | Canonical CP success + echo validated                                                                    |
| `terminal_failed` | Positive allowlist only: `invalid_payload`, `semantic_conflict`, `unsupported_status`, `order_not_found` |

`bank_sent_process1` and `bank_sent_process2` are mutually incompatible terminal outcomes (not sequential stages). Conflicting admission does not PATCH.

### Auth failure

HTTP **401**, machine code `invalid_signature`, message: `Invalid or expired module request.` (generic; no oracle).

Disabled module: **403** `module_disabled`. Not configured: **401** `authentication_failed`.

### Contract test vector (synthetic)

| Field              | Value                                                                                                               |
| ------------------ | ------------------------------------------------------------------------------------------------------------------- |
| Secret             | `test_shared_secret_123`                                                                                            |
| Timestamp          | `1787380000`                                                                                                        |
| Nonce              | `0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef`                                                  |
| Raw body           | `{"operation":"order-bank-status","unicid":"TEST-UNICID","order_id":"ABC123","status":"approved","status_id":"10"}` |
| Expected signature | `012e8545e84e43b45ae05a828bb487932454313ace1729d846cc3bd05a41c6a0`                                                  |

---

## 3. Inbound endpoints (Phase 4)

| Path                                  | Role                                                  |
| ------------------------------------- | ----------------------------------------------------- |
| `/module/unipayment/shopcache`        | CP pushes full shop snapshot → `replaceSnapshot`      |
| `/module/unipayment/orderbankstatus`  | Persist bank status for financing order (AUD-011)     |
| `/module/unipayment/smartucfdebuglog` | CP **reads** latest SmartUCF diagnostic journal entry |

All: **POST** only, JSON body, signed headers, bound `operation`.

### shopcache

- Body: `operation`, `unicid`, non-empty JSON object `data`
- Full replacement only (no merge)
- Invalid snapshot → HTTP 422 `shop_snapshot_invalid`, **keep** previous valid cache
- Snapshot `unicid` must match authenticated shop when present

### orderbankstatus

- Body: `operation`, `unicid`, string `order_id` (max **13**, never coerced from integer), `status_id`, `status` (no wire `status_label`)
- Lookup: unique `orders.reference` + `id_shop` + INNER JOIN `unipayment_financing_snapshot` via `executeS` (0 → 404, 2+ → 409 `order_ambiguous`)
- No customer-facing order-state changes (`ps_order_state_changed: false`) — AUD-009
- `BankStatusOrderStateMapper` is **not** wired into the callback; rejection whitelist empty until proven CP codes

### smartucfdebuglog

- Body: `operation`, `unicid`, string `order_id` (max 13)
- Authorize unique financing order + Process 1 / SmartUCF ownership before any journal disclosure
- Lookup is **shop-scoped** and bound to authorized `ps_order_id` (AUD-020)
- Absent / wrong-shop / non-financing / non-P1 / missing log → opaque **404** `order_not_found`
- Writes go through `record()` only when `UNIPAYMENT_DEBUG_ENABLED`

---

## 4. Replay table

```text
{prefix}unipayment_api_nonce
```

Unique: `(unicid, nonce_hash)`. Probabilistic purge of expired rows on claim. Storage failure fails closed.

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

Do not log: raw `popup_submission_token`, EGN, session cookie, secrets, Bearer tokens.

Phase 10 financing snapshot stores EGN/phone2 only in encrypted `sensitive_payload` (`SensitiveDataCipher`); generic `customer_json` excludes EGN. Retention redaction via `FinancingSnapshotRetentionService` (6 months AUD-014 default).

Phase 11 SmartUCF: do not log EGN, full SmartUCF customer payload, certificate/key material, or Bearer tokens. Safe: order reference, attempt id, SmartUCF state/error class.

Phase 13 homepage advertising: FO render uses `getCachedOnly()` only (AUD-022). CP text fields are plain text after `strip_tags` (not trusted HTML). Promo/image/CTA URLs must pass `FILTER_VALIDATE_URL` with scheme `http` or `https` only.

### Phase 12 financing email audiences

| Flow      | Customer email                        | Admin email (`PS_SHOP_EMAIL`)             |
| --------- | ------------------------------------- | ----------------------------------------- |
| Process 1 | No EGN                                | No EGN                                    |
| Process 2 | No EGN (confirmation message allowed) | **Full EGN** + second phone (operational) |

- Implementation: `LeasingOrderEmailPresenter` + `LeasingEmailNotifier` via `FinancingOrderMailDispatcher`
- Marker: `leasing_email_sent` (once per attempt; combined customer+admin)
- Set to `1` **only** when every required audience send succeeds (`Mail::Send === true`)
- `Mail::Send` false or throw → marker unchanged; `LeasingEmailDeliveryException` → lifecycle `withEmailSent(false)`
- Retry after failure may duplicate a previously successful audience (accepted residual risk; no schema change)
- Mail logs: order reference + audience class + exception class only — never body/EGN/SMTP credentials
- Thank-you page uses **customer** audience rows (no EGN); BO may show Process 2 EGN via admin rows (audited)
- BO may also show CP id + safe SmartUCF diagnostics; never raw request/response or secrets

### Data retention (module-owned)

| Store                      | Retention / cleanup                                                           |
| -------------------------- | ----------------------------------------------------------------------------- |
| `financing_snapshot`       | 180 days; opportunistic redact of PII + `sensitive_payload` (batch 200 / 24h) |
| `sensitive_payload`        | Removed with snapshot redaction; never stored plaintext elsewhere             |
| `order_bank_status`        | Kept for order diagnostics; not purged by snapshot retention                  |
| `smartucf_log`             | Diagnostic journal; no aggressive auto-purge beyond uninstall                 |
| `api_nonce`                | ~900s replay window; probabilistic expiry purge                               |
| `shop_cache`               | TTL 86400s freshness; replaced on refresh/push; deleted on uninstall          |
| `popup_submission`         | Opportunistic delete of expired issued/failed/identity_accepted               |
| `checkout_lock` / attempts | Lifecycle rows; dropped on uninstall                                          |

Expired / replayed / wrong-shop / wrong-identity operations return safe customer-facing JSON (no SQL, no token hash, no stack traces).

Cleanup: opportunistic `DELETE` of expired `issued` / `failed` / `identity_accepted` rows (1/20 of issue attempts). `processing` and `order_created` are never purged here.
