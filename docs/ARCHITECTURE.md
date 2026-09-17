# UniPayment — Architecture

This document describes high-level boundaries and the **current accepted implementation** for module **2.0.2** (scheme presentation / Cart representative / Checkout parity). Phase numbers below are historical delivery milestones; they are all implemented unless marked deferred.

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
bounded raw body (≤1 MiB) + signature headers
        ↓
ModuleRequestAuthenticator
  (HMAC on exact raw body → JSON → UNICID bind → atomic nonce claim)
        ↓
assert endpoint operation (shop-cache | order-bank-status | smartucf-debug-log)
        ↓
endpoint handler → canonical envelope {success,error,message,data}
```

See [`SECURITY-OPERATIONS.md`](SECURITY-OPERATIONS.md) for HMAC/nonce, lowercase nonce, durable CP status sync, and baselines
(`CP 0facb672…`, `PS8 c62c3be…`).

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
    │     → durable CP status sync target bank_sent_process2 (pending → PATCH via wired CP client → confirmed)
    │     → persist local bank_sent_process2 (always; independent of mail flag)
    │     → FinancingOrderMailDispatcher (if sendLeasingEmail)
    │           → DeferredOrderMailQueue::flush (native order_conf + leasing vars)
    │           → LeasingEmailNotifier (customer + admin; leasing_email_sent once)
    │     → native order confirmation redirect
    │     → replay retries pending CP PATCH only (no second CP create / handoff / successful email)
    └─ Process 1
          → retryPending CP sync if previously pending
          → SmartUcfSessionCoordinator::run/resume
          → claim smartucf_state on financing_snapshot
          → createSession (exactly-once via durable state; mTLS passphrase from secrets/smartucf-key.php)
          → on proven success: durable CP sync bank_sent_process1 + local bank_sent_process1
          → bank_sent_process1 | bank_send_failed_smartucf | processing | outcome_unknown
          → FinancingOrderMailDispatcher on terminal mail path
                → flush deferred order_conf + audience leasing mails
```

---

## Authoritative bank status and leasing information (business contract)

This section is the **AUTHORITATIVE** business contract for public bank status, customer/business-facing leasing information, and diagnostic visibility on PS9.

It governs upcoming manual business tests and subsequent runtime remediation. Implementation machine codes / lifecycle states are **not** substitutes for these public labels.

Strings below are identical across PS9, PS8, Woo, Control Panel, and other shop modules. Do **not** rename them.

### Two classes of status

| Class                                | Role                                                                          |
| ------------------------------------ | ----------------------------------------------------------------------------- |
| **Standard bank status**             | Public / business-facing bank status shown on officially agreed UI and emails |
| **Internal/service lifecycle state** | Technical progression, retry, transport, sync, diagnostic, or recovery state  |

Never present an internal/service lifecycle state as a standard bank status on customer, normal admin, email, or CP order-list surfaces.

### Four initial standard bank statuses

Until a later SmartUCF status arrives via CP, exactly **four** initial standard bank statuses are allowed:

#### A. `Неуспешно изпратен Банка - КП`

Use when:

- Shop order exists;
- CP order was **not** successfully created / is not visible in Control Panel;
- SmartUCF was **not** successfully created/sent.

This is the public bank status for **definitive failure before successful CP create**.

Same rule for **Process 1** and **Process 2**.

#### B. `Неуспешно изпратен Банка - SmartUCF`

Use when:

- Shop order exists;
- CP order exists and is visible in Control Panel;
- SmartUCF create/send **definitively** fails/rejects.

#### C. `Изпратен Банка - Процес 1`

Use when:

- Shop order exists;
- CP order exists;
- SmartUCF create/send succeeded;
- financing used **Process 1**.

#### D. `Изпратен Банка - Процес 2`

Use when:

- Shop order exists;
- CP order exists;
- financing used **Process 2**.

Process 2 does **not** require proof that the order was already created/sent to SmartUCF for this public status.

### Forbidden generic public status

```text
Неуспешно изпратен Банка
```

is **not** an allowed public bank status. Definitive CP failure must use `Неуспешно изпратен Банка - КП` for both Process 1 and Process 2.

### Later statuses from SmartUCF

After the initial bank status, Control Panel may request an updated status from SmartUCF (manual CP action or CP periodic/daily check).

Authoritative rule:

```text
Persist and display the status exactly as returned by SmartUCF.
```

Do **not**:

- rename it;
- normalize it into a predefined list;
- invent a mapping of all possible SmartUCF values.

The same raw-display rule applies to PS9 and Control Panel.

### Internal/service lifecycle states

Internal examples (non-exhaustive): pending, created, submitting, retryable, timeout, outcome unknown, definitive*failed, sent_unknown, sync pending/failed, transport failure, and PS9 equivalents such as attempt states, `smartucf_state`, `cp_status_sync*\*`, machine status ids (`bank_sent_process1`, …).

These may appear **only** on explicitly diagnostic surfaces, for example:

- SmartUCF debug information in Control Panel;
- debug information retrieved from the PS9 shop (`smartucfdebuglog`);
- specialized developer/support diagnostic panels;
- module/application logs;
- other explicitly designated service locations.

They must **not** appear as bank status on:

- customer UI;
- standard PrestaShop admin order UI bank-status columns/panels;
- standard emails;
- Control Panel order list / order table;
- normal customer/business-facing screens.

### Allowed locations for standard bank status

A field labeled as bank status may show only:

- one of the four initial standard bank statuses; **or**
- a later raw SmartUCF status.

Allowed PS9 / product surfaces:

1. PrestaShop admin order list UniCredit bank-status column (when present).
2. PrestaShop admin order view UniCredit / leasing panel.
3. Customer order/confirmation UI when such display is explicitly provided.
4. Thank You / order confirmation when bank status is part of the agreed customer content.
5. Standard financing / order emails when they include bank status.
6. Control Panel order list / order table.
7. Other pre-agreed, explicitly designated bank-status surfaces.

### Failure status semantics

| Situation                                                                                           | Public standard bank status                                                              |
| --------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| Shop order exists; CP order definitively does **not** exist; SmartUCF not successfully created/sent | `Неуспешно изпратен Банка - КП`                                                          |
| Shop order exists; CP order exists; SmartUCF definitively rejects/fails                             | `Неуспешно изпратен Банка - SmartUCF`                                                    |
| Ambiguous technical outcome (timeout / interrupted transport / cannot prove remote create)          | Keep **internal** lifecycle/recovery state; do **not** invent a fifth public bank status |

Process consistency:

```text
Process 1 + definitive CP failure → Неуспешно изпратен Банка - КП
Process 2 + definitive CP failure → Неуспешно изпратен Банка - КП
```

### Terminal order UX rule

When a Shop order exists **and** a terminal public bank status exists, the business flow is a **terminal business result**.

Customer UX must use the existing Thank You / order confirmation page when that is the agreed PS9 flow.

Terminal business failures such as:

```text
Неуспешно изпратен Банка - КП
Неуспешно изпратен Банка - SmartUCF
```

must not be treated as a generic technical popup/request failure after the Shop order already exists. Customer-facing failure content belongs on the agreed confirmation surface, without internal diagnostics.

### Standard email rule

When a Shop order exists and the flow ends with a terminal public bank status, standard order/financing emails must use the **same canonical bank status** (one of the four initial labels, or a later raw SmartUCF status when that is what was persisted).

Do not invent separate technical email statuses. Internal lifecycle/debug information must not appear in standard emails.

### Customer/business-facing leasing information

Standardized leasing information may appear on agreed surfaces (admin UniCredit panel, Thank You when provided, standard emails, designated reports). Adaptations are allowed only for pre-agreed business cases (for example Process 2 second phone / EGN only where privacy rules permit).

**Base field set** (structure is authoritative; values are examples):

```text
Статус към банката    Изтекло време за регистрация
КП поръчка (ID)       329
КП shop order_id      920
Срок (месеци)         12
КОП                    POS COM 50
Първоначална вноска   0.00
Сума на заема         1000.00
Месечна вноска        97.49
Обща дължима сума     1169.88
ГЛП / ГПР             30.00% / 34.50%
```

If a field has no value at a given lifecycle moment, follow the existing agreed presentation behavior (omit or leave empty). Do not invent a new placeholder convention in documentation alone.

**Forbidden** in the standard leasing block (except explicit diagnostic surfaces): CP create result, SmartUCF result/lifecycle/session, automatic resend, recommended action, last error category, subsystem, error time, correlation, CP synchronization state, lifecycle/retry state, HTTP/transport classification, timeout/network details, internal error class, internal CP/SmartUCF stage, or other architecture/retry/recovery details.

### Privacy / business-model rule

```text
Customer-facing and normal business-facing UI must contain only information
needed for the order, the financing, and the agreed bank status.
```

Do not expose Shop → CP → SmartUCF internals, retry/recovery/timeout/outcome-unknown mechanisms, internal state machines, correlation/error classification, transport implementation, or architectural details on those surfaces.

### Internal machine keys (implementation detail only)

PS9 may persist machine ids such as `bank_send_failed_cp`, `bank_send_failed_smartucf`, `bank_sent_process1`, `bank_sent_process2` separately from public labels. Those ids are **not** public bank-status copy. Public UI and emails must show the Bulgarian standard labels (or later raw SmartUCF text).

**SmartUCF snapshot states** (internal): `not_started` → `submitting` → `created` \| `failed` \| `outcome_unknown`.

---

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

| Outcome                       | Customer landing                                                                                                                                                           |
| ----------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Process 2 success             | Native order-confirmation + leasing table                                                                                                                                  |
| Process 1 SmartUCF created    | Trusted SmartUCF redirect (then shop confirmation on return)                                                                                                               |
| Process 1 SmartUCF failed     | Native confirmation + safe failure notice (terminal bank status on confirmation surface)                                                                                   |
| CP failed / outcome unknown   | Native confirmation + safe degraded notice (definitive CP failure uses public CP-failure bank status; ambiguity stays order-aware without inventing a fifth public status) |
| SmartUCF processing / unknown | `checkout_validated.tpl` (order-aware; do not resubmit)                                                                                                                    |

**BO UniCredit / leasing panel:** `displayAdminOrderMainBottom` → customer/business-facing leasing rows + public bank status only. Absent snapshot → empty (non-financing orders). Operational CP/SmartUCF diagnostics belong in the SmartUCF journal / designated debug surfaces — not in the standard leasing table.

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

**Released in 2.0.2:** coordinated scheme presentation ordering (`months ASC`; same months: standard → non-zero promo → 0%), Cart representative/`uni_parva` safety, and Checkout default priority / first-installment transitions.

**Deferred (not tagged yet):** production release tag/package remains an explicit operator step.

**Attempt state machine** (`OrderOrchestrator`):

| State                 | Meaning                                                                                                                     |
| --------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| `reserved`            | Attempt row created (UNIQUE shop/cart/fingerprint)                                                                          |
| `ps_order_created`    | Native PS order attached                                                                                                    |
| `cp_submitting`       | CP POST in flight                                                                                                           |
| `cp_created`          | CP order id persisted — terminal success for durable CP create                                                              |
| `cp_failed_retryable` | CP 5xx — retry without new PS order                                                                                         |
| `cp_outcome_unknown`  | CP transport/response ambiguity — **no blind second POST /orders**; frozen payload preserved; **not** `bank_send_failed_cp` |
| `terminal_failed`     | Non-retryable CP/validation failure after PS order                                                                          |

Checkout fingerprint canonical payload (non-PII):

```text
currency, total, lines[{product_id, product_attribute_id, quantity, line_total}],
checkout_state{id_cart, carrier_id, delivery_option, shipping_total, cart_rules[]}
```

Lines sorted by product/attribute; cart_rules sorted by `id_cart_rule`.

**v2.0.2 presentation ordering** is implemented via `SchemePresentationCategory` and `CartSchemeResolver::unifiedSchemes` / Product popup list sort. Intersection identity remains `type|KOP|months`; `filterId` remains metadata.

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

Canonical CP success responses nest application fields under `response.data` (tokens, shop, create/PATCH echoes). Legacy top-level `access_token` is rejected. Malformed 2xx envelopes are protocol failures. POST `/orders` create payload never includes `status` / `status_id` / EGN / phone2 — CP owns initial `cp_sent`.

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

## Explicitly deferred (not in v2.0.2 release scope)

| Area                                        | Notes                                |
| ------------------------------------------- | ------------------------------------ |
| Production release tag / package            | Operator-driven; not part of commit  |
| Bank-rejection → native PS order-state sync | Dormant until proven CP status codes |
