# UniPayment — Architecture

This document describes high-level boundaries and the **current accepted implementation** for module **2.0.1** (pre-release / final audit remediation). Phase numbers below are historical delivery milestones; they are all implemented unless marked deferred.

---

## Source of truth vs adapter

```text
uni-ps8 = functional source of truth
uni-ps9 = PS9-native adapter/port
```

---

## Intended layering

```text
PrestaShop integration
        ↓
Application/domain
        ↓
Infrastructure
```

---

## Implemented capabilities (Phases 0–13 + final audit remediations)

| Area                        | State                                                                                           |
| --------------------------- | ----------------------------------------------------------------------------------------------- |
| Local configuration         | Repository/validator/UI                                                                         |
| Credential-change boundary  | `TokenRepository::invalidate()` **and** `ShopConfigurationCache::clear()`                       |
| Token storage               | `TokenRepository` (`enc:v1:` via `PhpEncryption` / `_NEW_COOKIE_KEY_`)                          |
| HTTP transport              | `CurlHttpTransport` (TLS verify on, timeouts 5s/15s)                                            |
| CP client                   | `ControlPanelClient` — API base from `config/environment.php` (`control_panel_url` + `/api/v1`) |
| Shop snapshot cache         | `ShopConfigurationCache` table `unipayment_shop_cache`, TTL **86400** seconds                   |
| Snapshot validation         | `ShopConfigurationSnapshotValidator` + `ShopConfigurationSnapshotValidationException`           |
| Pull / forced refresh       | `ShopConfigurationService::get(false\|true)` (explicit/non-render paths)                        |
| FO advertising cache read   | `ShopConfigurationService::getCachedOnly()` — **never** refreshes / calls CP (AUD-022)          |
| Flag helpers                | `ShopConfigurationFlags`                                                                        |
| BO bank-data refresh        | enabled — `get(true)` with PS8 error mapping                                                    |
| Inbound signed API          | `shopcache`, `orderbankstatus`, `smartucfdebuglog` + HMAC/nonce                                 |
| Replay store                | `unipayment_api_nonce` (900s retention)                                                         |
| Bank status persistence     | `unipayment_order_bank_status` (no FO / order-state side effects; AUD-009 dormant)              |
| SmartUCF debug journal      | `unipayment_smartucf_log` — **shop-scoped** lookup (`id_shop` + `order_id`, AUD-020)            |
| Financing calculator domain | Pure snapshot-driven Calculator                                                                 |
| Product page FO             | Hook + AJAX + vanilla JS (Hummingbird + Classic)                                                |
| Popup identity / dedupe     | `unipayment_popup_submission`, operation guard, Step 2 identity                                 |
| Cart page FO                | `displayShoppingCart` + cartcalculator/cartpopup                                                |
| Checkout PaymentOption      | `paymentOptions` + checkoutcalculate + preference/fingerprint handoff                           |
| Durable order submission    | Lock + attempt + PS order + snapshot + CP create (checkout **and** product/cart popups)         |
| Post-CP lifecycle           | Process 1 SmartUCF / Process 2 handoff + bank status                                            |
| Post-order communication    | Financing emails, order_conf, Thank You, BO diagnostics                                         |
| Homepage advertising        | Cache-only CP promo via `displayFooter` (index only)                                            |
| mTLS private-key passphrase | `secrets/smartucf-key.php` in module ZIP (AUD-021) — no server env requirement                  |

### Shop configuration cache flow

```text
get(false) / get(true)   ← BO refresh, product/cart calculators, explicit sync
    ↓
fresh local cache for current UNICID?
    ├─ yes → return cached snapshot
    └─ no / force → GET /shop → validate → replace → return

getCachedOnly()   ← FO homepage advertising only (AUD-022)
    ↓
fresh local cache?
    ├─ yes → return snapshot
    └─ no / stale / malformed → null (no refresh, no CP HTTP)
```

Invalid remote snapshot: **do not overwrite** a known-good cache; do not purge tokens.

Permanent auth/shop failures (401 / 400 / 403 / 404 / empty InvalidPayload): purge that UNICID cache entry + invalidate tokens.

Transient failures (timeout / connection / 5xx): keep cache; rethrow.

Cache scope key is **`unicid`** (UNIQUE), not PrestaShop `id_shop` — same as audited PS8.

`replaceSnapshot()` is used by inbound `shopcache` for full CP push replacement (no merge).

### Inbound CP → module flow

```text
POST /module/unipayment/{shopcache|orderbankstatus|smartucfdebuglog}
        ↓
raw body (php://input) + signature headers
        ↓
ModuleRequestAuthenticator
  (unicid match → HMAC on raw body → atomic nonce claim)
        ↓
endpoint handler
```

See [`SECURITY-OPERATIONS.md`](SECURITY-OPERATIONS.md) for HMAC/nonce details.

### Financing calculator domain

Pure domain — validated shop snapshot + `ProductContext` → offers / calculation results.

### Product page

```text
PrestaShop product
        ↓
ProductContextFactory (tax-incl unit × qty, categories, combination validation)
        ↓
ShopConfigurationService::get()
        ↓
Calculator + ProductCalculatorPresenter
        ↓
hook displayProductAdditionalInfo → product_calculator.tpl
        ↓
AJAX productcalculator (refresh) / productpopup (calculate + identity + durable order)
```

Theme lifecycle:

| Theme           | Hook                           | Events                                                                |
| --------------- | ------------------------------ | --------------------------------------------------------------------- | -------------------------------------------------- |
| Hummingbird 2.0 | `displayProductAdditionalInfo` | `prestashop.on('updatedProduct'                                       | 'updatedProductCombination')` + document listeners |
| Classic 3.1.1   | same                           | same + quantity input/change + MutationObserver on `.product-actions` |

Race protection: `AbortController` + `refreshSequence` (stale responses ignored).

### Product / cart popup identity + durable financing

Shared popup submission guard (token issue → claim → apply):

```text
calculate (authoritative context + Calculator)
    ↓
issue_submission_token  → unipayment_popup_submission (issued, TTL 1800s)
    ↓
apply
    → selection_hash match
    → atomic UPDATE issued → processing
    → validate customer + consents + address ownership
    → prepare cart/customer (guest factory / address resolver)
    → OrderOrchestrator (PS order + snapshot + CP create)
    → mark popup submission order_created
    → PostControlPanelLifecycleService (SmartUCF Process 1 or Process 2)
```

| Token                       | Role                                                                | Authoritative?                          |
| --------------------------- | ------------------------------------------------------------------- | --------------------------------------- |
| `popup_submission_token`    | Server `bin2hex(random_bytes(32))`, UNIQUE, bound to selection hash | Yes                                     |
| `preselect_operation_token` | Client 16-byte hex, cookie idempotency for Silent Buy (product)     | Correlation / cart-mutation dedupe only |
| CSRF `token`                | `Tools::getToken(false)`                                            | Yes, separate from submission identity  |

Guest identity: context guest / cookie (`PopupSubmissionBindingFactory::identityFromContext`). Logged-in: `$context->customer->isLogged()` only (AUD-001: never email lookup). Address ownership: `id_customer` match, skip deleted.

**Product popup** may create a fresh cart for financing. **Cart popup** finances the existing FO cart (no `preselect` re-add of lines).

### Cart financing

```text
native PrestaShop cart
    ↓
CartContextFactory (payable = Cart::getOrderTotal(true, Cart::BOTH))
    ↓
CartSchemeResolver (intersection of per-line schemes; each line priced at cart total)
    ↓
CartCalculatorPresenter → displayShoppingCart → cart_calculator.tpl
    ↓
AJAX cartcalculator (refresh) / cartpopup (calculate + durable apply — see above)
```

Amount semantics (PS8 / Woo cart oracle):

| Item            | Source                                                                             |
| --------------- | ---------------------------------------------------------------------------------- |
| Financed amount | `Cart::getOrderTotal(true, Cart::BOTH)` — tax incl. products + shipping − vouchers |
| Line `total_wt` | Stored on `CartLine` only; **not** used for eligibility/calculation                |
| Qty influence   | Via cart payable total (qty changes change `BOTH` total)                           |
| Filter identity | Metadata only; intersection key is `type\|kop\|months`                             |

Cart UI is **cart-wide** (one calculator for the whole cart), not per-line widgets.

**Guest Cart order materialization:** after customer/address mutation, `CartShippingStateSynchronizer` resets stale `delivery_option` / package caches before `validateOrder()`. One durable submission must resolve to **exactly one authoritative** PrestaShop order with `order_detail` lines. Empty twin orders must not bind financing (`AuthoritativeOrderResolver` + empty-lines guard). Replay of the same submission token must not create a second native order.

Theme lifecycle (cart):

| Theme           | Hook                  | Events                                        |
| --------------- | --------------------- | --------------------------------------------- |
| Hummingbird 2.0 | `displayShoppingCart` | `prestashop.on('updatedCart')` after AJAX     |
| Classic 3.1.1   | same                  | same (`updateCart` → refresh → `updatedCart`) |

### Checkout financing

```text
native checkout cart
    ↓
CartContextFactory::createForCheckout()
    ↓
CartSnapshot fingerprint (HMAC-signed cart_snapshot in PaymentOption form)
    ↓
CheckoutPaymentPresenter → hookPaymentOptions → checkout_payment.tpl
    ↓
AJAX checkoutcalculate (recalc + refresh preference)
    ↓
validatecheckout
    → CheckoutSubmitLock (45s TTL, id_shop + id_cart)
    → CheckoutPaymentValidator (revalidate fingerprint/selection/consents)
    → OrderOrchestrator (recovery-first)
        → order_attempt reserve (id_shop + id_cart + cart_fingerprint UNIQUE)
        → NativePrestaShopOrderGateway::validateOrder() once (AWAITING state)
        → financing_snapshot (INSERT IGNORE, id_attempt UNIQUE)
        → ControlPanelOrderPayloadBuilder → POST /api/v1/orders once
    → CheckoutPreferenceStore::clear() after successful validation path begins durable work
    → post-order template or order-confirmation / SmartUCF redirect
```

**Post-order durability (AUD-019):** once a native PrestaShop order exists for the durable attempt, later CP/SmartUCF/mail failures remain **order-aware**. The module must not invite a fresh financing attempt that can create a duplicate durable order for the same submission. Lock-loser / degraded UX stays post-order (not a blank checkout restart).

Durable `cp_created` continues with SmartUCF / Process 2. Human-facing communication:

```text
OrderOrchestrationResult (cp_created)
    ↓
PostControlPanelLifecycleService
    ├─ Process 2 (uni_proces=1)
    │     → persist bank_sent_process2 (always; independent of mail flag)
    │     → FinancingOrderMailDispatcher (if sendLeasingEmail)
    │           → DeferredOrderMailQueue::flush (native order_conf + leasing vars)
    │           → LeasingEmailNotifier (customer + admin; leasing_email_sent once)
    │     → native order confirmation redirect
    └─ Process 1
          → SmartUcfSessionCoordinator::run/resume
          → claim smartucf_state on financing_snapshot
          → createSession (exactly-once via durable state; mTLS passphrase from secrets/smartucf-key.php)
          → bank_sent_process1 | bank_send_failed_smartucf | processing | outcome_unknown
          → FinancingOrderMailDispatcher on terminal mail path
                → flush deferred order_conf + audience leasing mails
```

**Bank status meanings:**

| Status                      | Trigger                                                   |
| --------------------------- | --------------------------------------------------------- |
| `bank_send_failed_cp`       | PS order exists, CP create failed (no confirmed CP order) |
| `bank_sent_process1`        | CP created **and** SmartUCF Process 1 succeeded           |
| `bank_send_failed_smartucf` | CP created **and** SmartUCF Process 1 failed              |
| `bank_sent_process2`        | CP created **and** Process 2 handoff (no SmartUCF)        |

**SmartUCF snapshot states:** `not_started` → `submitting` → `created` \| `failed` \| `outcome_unknown`.

**Module-owned tables: 8** — `shop_cache`, `api_nonce`, `order_bank_status`, `smartucf_log`, `popup_submission`, `checkout_lock`, `order_attempt`, `financing_snapshot`.

**Mail audiences:**

| Flow      | Customer financing mail  | Admin financing mail     |
| --------- | ------------------------ | ------------------------ |
| Process 1 | No EGN; scheme/amount    | No EGN; operational data |
| Process 2 | No EGN; confirmation msg | May include EGN + phone2 |

Marker: `financing_snapshot.leasing_email_sent` — combined once-per-attempt.
`leasing_email_sent = 1` only after **all** required audience `Mail::Send` calls return true (false/throw leave marker unset for retry).
Accepted residual risk: retry after partial success may duplicate the already-delivered audience (no per-audience columns).

**Confirmation UX:**

| Outcome                       | Customer landing                                             |
| ----------------------------- | ------------------------------------------------------------ |
| Process 2 success             | Native order-confirmation + leasing table                    |
| Process 1 SmartUCF created    | Trusted SmartUCF redirect (then shop confirmation on return) |
| Process 1 SmartUCF failed     | Native confirmation + safe failure notice                    |
| CP failed / outcome unknown   | Native confirmation + safe degraded notice                   |
| SmartUCF processing / unknown | `checkout_validated.tpl` (order-aware; do not resubmit)      |

**BO diagnostics:** `displayAdminOrderMainBottom` → leasing rows + process label + CP id + safe SmartUCF fields. Absent snapshot → empty (non-financing orders).

**Homepage advertising:**

```text
UNIPAYMENT_ADVERTISING_ENABLED + module enabled + UNICID
→ HomepageAdvertisingGate (php_self=index + uni_status + uni_container_status)
→ ShopConfigurationService::getCachedOnly() (local fresh cache only; never refresh/CP on render)
→ HomepageAdvertisingPresenter (strip_tags + http/https URL filter)
→ displayFooter + homepage_advertising.tpl + scoped CSS/JS
```

| Cache state                         | FO advertising       |
| ----------------------------------- | -------------------- |
| Fresh valid snapshot                | May render           |
| Missing / stale / malformed         | No advertising block |
| Explicit BO / inbound cache refresh | Allowed (non-render) |

Empty/invalid promo → render nothing. Failures fail closed (no FO 500). No browser/AJAX CP fallback.

**Order-state sync (AUD-009):** inbound `orderbankstatus` does **not** map bank status to native PS order state (`ps_order_state_changed: false`). `BankStatusOrderStateMapper` / `SYNC_BANK_REJECTION_STATE` remain **dormant**; rejection whitelist empty until proven CP codes.

**Uninstall (AUD-006):** `ModuleDataPurger` drops 8 tables, config keys, tokens, cert runtime artifacts; preserves referenced custom order states; never deletes native PS orders.

**Deferred (not released yet):** release tag/package, coordinated **v2.0.2** scheme aggregation (`months ASC`; same months: standard before promo).

**Attempt state machine** (`OrderOrchestrator`):

| State                 | Meaning                                                        |
| --------------------- | -------------------------------------------------------------- |
| `reserved`            | Attempt row created (UNIQUE shop/cart/fingerprint)             |
| `ps_order_created`    | Native PS order attached                                       |
| `cp_submitting`       | CP POST in flight                                              |
| `cp_created`          | CP order id persisted — terminal success for durable CP create |
| `cp_failed_retryable` | CP 5xx — retry without new PS order                            |
| `cp_outcome_unknown`  | CP timeout/connection — retry; bank `bank_send_failed_cp`      |
| `terminal_failed`     | Non-retryable CP/validation failure after PS order             |

Checkout fingerprint canonical payload (non-PII):

```text
currency, total, lines[{product_id, product_attribute_id, quantity, line_total}],
checkout_state{id_cart, carrier_id, delivery_option, shipping_total, cart_rules[]}
```

Lines sorted by product/attribute; cart_rules sorted by `id_cart_rule`.

Deferred **v2.0.2** (Woo + PS8 + PS9 coordinated): standard popup/list should expose eligible promo schemes inside standard selection. Current code preserves audited v2.0.1 aggregation via `CartSchemeResolver` / `unifiedSchemes` — **do not change** without coordinated release.

### Authentication lifecycle

```text
ensureToken / authenticatedRequest
        ↓
valid Bearer token?
        ↓ no / expired / near-expiry
login or refresh
        ↓
authenticated call
        ↓ 401
invalidate → login → retry ONCE
        ↓ second 401
invalidate + AuthenticationException
```

Login payload to CP:

```text
POST /api/v1/auth/login
{ unicid, name: shopUrl, secret }
```

### Token Configuration keys

```text
UNIPAYMENT_CP_ACCESS_TOKEN   (encrypted)
UNIPAYMENT_CP_TOKEN_TYPE
UNIPAYMENT_CP_TOKEN_EXPIRES_AT
```

### Deployment packaging (ZIP-only)

No SSH / PHP-FPM / environment-variable setup is required for merchants.

| File                       | Role                                                         |
| -------------------------- | ------------------------------------------------------------ |
| `config/environment.php`   | Authoritative CP host (`control_panel_url`)                  |
| `secrets/smartucf-key.php` | SmartUCF mTLS private-key passphrase (Git-ignored; ZIP fill) |

Maintainer prepares development / test / production ZIPs by editing **only** those deployment files (plus PEMs under `keys/` when shipping certificates).

---

## Explicitly deferred (not in v2.0.1 release scope)

| Area                                        | Notes                                |
| ------------------------------------------- | ------------------------------------ |
| Production release tag / package            | After final regression gate          |
| Coordinated v2.0.2 scheme aggregation       | uni-woo + uni-ps8 + uni-ps9          |
| Bank-rejection → native PS order-state sync | Dormant until proven CP status codes |
