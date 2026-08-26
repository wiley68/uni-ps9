# UniPayment — Architecture

This document describes **intended** high-level boundaries and the **implemented Phase 11** state.

---

## Source of truth vs adapter

```text
uni-ps8 = functional source of truth
uni-ps9 = PS9-native adapter/port
```

---

## Intended layering (planned)

```text
PrestaShop integration
        ↓
Application/domain
        ↓
Infrastructure
```

---

## Implemented through Phase 8

| Area                        | State                                                                                 |
| --------------------------- | ------------------------------------------------------------------------------------- |
| Local configuration         | Phase 1 repository/validator/UI                                                       |
| Credential-change boundary  | `TokenRepository::invalidate()` **and** `ShopConfigurationCache::clear()`             |
| Token storage               | `TokenRepository` (`enc:v1:` via `PhpEncryption` / `_NEW_COOKIE_KEY_`)                |
| HTTP transport              | `CurlHttpTransport` (TLS verify on, timeouts 5s/15s)                                  |
| CP client                   | `ControlPanelClient` (auth + `getShop` + unused order/SSL helpers)                    |
| Shop snapshot cache         | `ShopConfigurationCache` table `unipayment_shop_cache`, TTL **86400** seconds         |
| Snapshot validation         | `ShopConfigurationSnapshotValidator` + `ShopConfigurationSnapshotValidationException` |
| Pull / forced refresh       | `ShopConfigurationService::get(false\|true)` via `ShopConfigurationProviderInterface` |
| Flag helpers                | `ShopConfigurationFlags`                                                              |
| BO bank-data refresh        | enabled — `get(true)` with PS8 error mapping                                          |
| Inbound signed API          | Phase 4 — `shopcache`, `orderbankstatus`, `smartucfdebuglog` + HMAC/nonce             |
| Replay store                | `unipayment_api_nonce` (900s retention)                                               |
| Bank status persistence     | `unipayment_order_bank_status` (no FO / order-state side effects yet)                 |
| SmartUCF debug journal      | `unipayment_smartucf_log` + diagnostic journal (BO download deferred)                 |
| Financing calculator domain | Phase 5 — pure snapshot-driven Calculator                                             |
| Product page FO             | Phase 6 — hook + AJAX + vanilla JS (Hummingbird + Classic)                            |
| Popup identity / dedupe     | Phase 7 — `unipayment_popup_submission`, operation guard, Step 2 identity             |
| Cart page FO                | Phase 8 — `displayShoppingCart` + cartcalculator/cartpopup + Phase 7 flow isolation   |
| Checkout PaymentOption      | Phase 9 — `paymentOptions` + checkoutcalculate + preference/fingerprint handoff       |
| Durable checkout submission | Phase 10 — lock + attempt + PS order + snapshot + CP create                           |
| Post-CP lifecycle           | Phase 11 — Process 1 SmartUCF / Process 2 handoff + bank status                       |

### Shop configuration cache flow

```text
get(false)
    ↓
fresh local cache for current UNICID?
    ├─ yes → return cached snapshot
    └─ no  → GET /shop
              ↓
           validate snapshot
              ↓
           full replace in unipayment_shop_cache
              ↓
           return

get(true) → always attempt CP pull (same validate/replace path)
```

Invalid remote snapshot: **do not overwrite** a known-good cache; do not purge tokens.

Permanent auth/shop failures (401 / 400 / 403 / 404 / empty InvalidPayload): purge that UNICID cache entry + invalidate tokens.

Transient failures (timeout / connection / 5xx): keep cache; rethrow.

Cache scope key is **`unicid`** (UNIQUE), not PrestaShop `id_shop` — same as audited PS8.

`replaceSnapshot()` is used by inbound `shopcache` for full CP push replacement (no merge).

### Inbound CP → module flow (Phase 4)

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

### Financing calculator domain (Phase 5)

Pure domain — validated shop snapshot + `ProductContext` → offers / calculation results.

### Product page (Phase 6)

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
AJAX productcalculator (refresh) / productpopup (calculate + identity/dedupe)
```

Theme lifecycle:

| Theme           | Hook                           | Events                                                                |
| --------------- | ------------------------------ | --------------------------------------------------------------------- | -------------------------------------------------- |
| Hummingbird 2.0 | `displayProductAdditionalInfo` | `prestashop.on('updatedProduct'                                       | 'updatedProductCombination')` + document listeners |
| Classic 3.1.1   | same                           | same + quantity input/change + MutationObserver on `.product-actions` |

Race protection: `AbortController` + `refreshSequence` (stale responses ignored).

### Product popup identity (Phase 7)

```text
calculate (authoritative ProductContext + Calculator)
    ↓
issue_submission_token  → unipayment_popup_submission (issued, TTL 1800s)
    ↓
apply
    → selection_hash match (product/combination/qty/scheme/guest/customer/shop)
    → atomic UPDATE issued → processing
    → validate customer + consents + address ownership
    → identity_accepted (no PS/CP order yet)
```

| Token                       | Role                                                                | Authoritative?                          |
| --------------------------- | ------------------------------------------------------------------- | --------------------------------------- |
| `popup_submission_token`    | Server `bin2hex(random_bytes(32))`, UNIQUE, bound to selection hash | Yes                                     |
| `preselect_operation_token` | Client 16-byte hex, cookie idempotency for Silent Buy               | Correlation / cart-mutation dedupe only |
| CSRF `token`                | `Tools::getToken(false)`                                            | Yes, separate from submission identity  |

Guest identity: `$context->cookie->id_guest`. Logged-in: `$context->customer->isLogged()` only (AUD-001: never email lookup). Address ownership: `id_customer` match, skip deleted. Apply does **not** create guests, addresses, carts, or orders.

### Cart financing (Phase 8)

```text
native PrestaShop cart
    ↓
CartContextFactory (payable = Cart::getOrderTotal(true, Cart::BOTH))
    ↓
CartSchemeResolver (intersection of per-line schemes; each line priced at cart total)
    ↓
CartCalculatorPresenter → displayShoppingCart → cart_calculator.tpl
    ↓
AJAX cartcalculator (refresh) / cartpopup (calculate + Phase 7 identity/dedupe)
```

Amount semantics (PS8 / Woo cart oracle):

| Item            | Source                                                                             |
| --------------- | ---------------------------------------------------------------------------------- |
| Financed amount | `Cart::getOrderTotal(true, Cart::BOTH)` — tax incl. products + shipping − vouchers |
| Line `total_wt` | Stored on `CartLine` only; **not** used for eligibility/calculation                |
| Qty influence   | Via cart payable total (qty changes change `BOTH` total)                           |
| Filter identity | Metadata only; intersection key is `type\|kop\|months`                             |

Cart UI is **cart-wide** (one calculator for the whole cart), not per-line widgets.

Cart popup reuses Phase 7 `PopupSubmissionRepository` / guard / identity services with `flow=cart_popup` and binding `{id_cart, cart_total, scheme…}`. Apply stops at `identity_accepted`. No `preselect` (does not re-add cart lines). No PaymentOption / orders / SmartUCF.

Theme lifecycle (cart):

| Theme           | Hook                  | Events                                        |
| --------------- | --------------------- | --------------------------------------------- |
| Hummingbird 2.0 | `displayShoppingCart` | `prestashop.on('updatedCart')` after AJAX     |
| Classic 3.1.1   | same                  | same (`updateCart` → refresh → `updatedCart`) |

### Checkout financing (Phase 9–10)

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
validatecheckout (Phase 10)
    → CheckoutSubmitLock (45s TTL, id_shop + id_cart)
    → CheckoutPaymentValidator (revalidate fingerprint/selection/consents)
    → OrderOrchestrator (recovery-first)
        → order_attempt reserve (id_shop + id_cart + cart_fingerprint UNIQUE)
        → NativePrestaShopOrderGateway::validateOrder() once (AWAITING state)
        → financing_snapshot (INSERT IGNORE, id_attempt UNIQUE)
        → ControlPanelOrderPayloadBuilder → POST /api/v1/orders once
    → CheckoutPreferenceStore::clear() after successful validation path begins durable work
    → Phase10CheckoutOutcome / post-order template or order-confirmation redirect
```

**Phase 10 boundary ends at `cp_created`.** Phase 11 continues:

```text
OrderOrchestrationResult (cp_created)
    ↓
PostControlPanelLifecycleService
    ├─ Process 2 (uni_proces=1)
    │     → bank_sent_process2
    │     → Phase11DeferredMailDispatcher (flush order_conf)
    │     → native order confirmation redirect
    └─ Process 1
          → SmartUcfSessionCoordinator::run/resume
          → claim smartucf_state on financing_snapshot
          → createSession (exactly-once via durable state)
          → bank_sent_process1 | bank_send_failed_smartucf | processing | outcome_unknown
          → flush deferred order_conf on terminal mail path
```

**Bank status meanings:**

| Status                      | Trigger                                            |
| --------------------------- | -------------------------------------------------- |
| `bank_send_failed_cp`       | Phase 10: PS order exists, CP create failed        |
| `bank_sent_process1`        | CP created **and** SmartUCF Process 1 succeeded    |
| `bank_send_failed_smartucf` | CP created **and** SmartUCF Process 1 failed       |
| `bank_sent_process2`        | CP created **and** Process 2 handoff (no SmartUCF) |

**SmartUCF snapshot states:** `not_started` → `submitting` → `created` \| `failed` \| `outcome_unknown`.

**UniPayment tables remain 8** (Phase 10 schema already includes `smartucf_*` columns).

**Post-order UX:** Process 2 / SmartUCF failure → order confirmation; SmartUCF created → trusted bank redirect; processing/unknown → `checkout_validated.tpl`.

**Attempt state machine** (`OrderOrchestrator`):

| State                 | Meaning                                                   |
| --------------------- | --------------------------------------------------------- |
| `reserved`            | Attempt row created (UNIQUE shop/cart/fingerprint)        |
| `ps_order_created`    | Native PS order attached                                  |
| `cp_submitting`       | CP POST in flight                                         |
| `cp_created`          | CP order id persisted — terminal success for Phase 10     |
| `cp_failed_retryable` | CP 5xx — retry without new PS order                       |
| `cp_outcome_unknown`  | CP timeout/connection — retry; bank `bank_send_failed_cp` |
| `terminal_failed`     | Non-retryable CP/validation failure after PS order        |

**UniPayment tables after Phase 10 (8 total):** `shop_cache`, `api_nonce`, `order_bank_status`, `smartucf_log`, `popup_submission`, `checkout_lock`, `order_attempt`, `financing_snapshot`.

**Post-order UX (temporary until Phase 12):** Process 2 + CP success → native order confirmation; Process 1 + CP success → `checkout_validated.tpl`; CP failure / unknown after PS order → order confirmation or validated warning (no return to editable checkout submit).

Checkout fingerprint canonical payload (non-PII):

```text
currency, total, lines[{product_id, product_attribute_id, quantity, line_total}],
checkout_state{id_cart, carrier_id, delivery_option, shipping_total, cart_rules[]}
```

Lines sorted by product/attribute; cart_rules sorted by `id_cart_rule`.

Deferred **v2.0.2** (Woo + PS8 + PS9 coordinated): standard popup/list should expose eligible promo schemes inside standard selection. Phase 9 preserves audited v2.0.1 aggregation via `CartSchemeResolver` / `unifiedSchemes` — **do not change** here.

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

---

## Explicitly not implemented yet

| Area                                    | Phase |
| --------------------------------------- | ----- |
| Full financing customer/admin email UX  | 12+   |
| Final Thank You / confirmation redesign | 12+   |
| Admin/order UI polish                   | 12+   |
| Advertising FO                          | later |
