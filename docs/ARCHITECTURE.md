# UniPayment — Architecture

This document describes **intended** high-level boundaries and the **implemented Phase 5** state.

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

## Implemented through Phase 5

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
| Flag helpers                | `ShopConfigurationFlags` — **not** wired to FO yet                                    |
| BO bank-data refresh        | enabled — `get(true)` with PS8 error mapping                                          |
| Inbound signed API          | Phase 4 — `shopcache`, `orderbankstatus`, `smartucfdebuglog` + HMAC/nonce             |
| Replay store                | `unipayment_api_nonce` (900s retention)                                               |
| Bank status persistence     | `unipayment_order_bank_status` (no FO / order-state side effects yet)                 |
| SmartUCF debug journal      | `unipayment_smartucf_log` + diagnostic journal (BO download deferred)                 |
| Front office                | no functional hooks, product/cart UI, payment option, JS, or CSS                      |
| Financing calculator domain | Phase 5 — pure snapshot-driven Calculator (no FO consumers)                           |

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

Pure domain only — validated shop snapshot + `ProductContext` → offers / calculation results.

```text
ProductContext + shop snapshot
        ↓
SchemaFilterMatcher / MonthResolver
        ↓
CoefficientResolver / FirstInstallmentResolver
        ↓
FinancialCalculator / OfferFactory
        ↓
PreferredOfferSelector / Calculator facade
```

No CP HTTP, no DB writes, no FO hooks. PS8 behavioral parity is the oracle (`tests/Calculator/*`).

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

| Area                                                  | Phase |
| ----------------------------------------------------- | ----- |
| ProductContextFactory / product hooks / product popup | 6+    |
| Cart calculator / cart popup                          | later |
| PaymentOption / checkout / financing snapshots        | later |
| SmartUCF outbound / emails / advertising FO           | later |
| Checkout lock / order attempt / popup / FO JS/CSS     | later |
| Custom order states / BO journal download             | later |
