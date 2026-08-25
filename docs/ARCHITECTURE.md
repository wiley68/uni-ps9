# UniPayment — Architecture

This document describes **intended** high-level boundaries and the **implemented Phase 3** state.

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

## Implemented in Phase 3

| Area                       | Phase 3 state                                                                                     |
| -------------------------- | ------------------------------------------------------------------------------------------------- |
| Local configuration        | Phase 1 repository/validator/UI                                                                   |
| Credential-change boundary | `TokenRepository::invalidate()` **and** `ShopConfigurationCache::clear()`                         |
| Token storage              | `TokenRepository` (`enc:v1:` via `PhpEncryption` / `_NEW_COOKIE_KEY_`)                            |
| HTTP transport             | `CurlHttpTransport` (TLS verify on, timeouts 5s/15s)                                              |
| CP client                  | `ControlPanelClient` (auth + `getShop` + unused order/SSL helpers)                                |
| Shop snapshot cache        | `ShopConfigurationCache` table `unipayment_shop_cache`, TTL **86400** seconds                     |
| Snapshot validation        | `ShopConfigurationSnapshotValidator` + `ShopConfigurationSnapshotValidationException`             |
| Pull / forced refresh      | `ShopConfigurationService::get(false\|true)` via `ShopConfigurationProviderInterface`             |
| Flag helpers               | `ShopConfigurationFlags` (Process 1/2, test env, yes-flag, certificate) — **not** wired to FO yet |
| BO bank-data refresh       | enabled — `get(true)` with PS8 error mapping                                                      |
| Front office               | no functional hooks, controllers, JS, or CSS                                                      |
| Inbound CP callbacks       | **not** implemented (Phase 4)                                                                     |

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

`replaceSnapshot()` exists for Phase 4 CP push full-replacement without redesign.

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
| CP push / `shopcache` inbound + HMAC/nonce            | 4     |
| Calculator / product / cart                           | 5+    |
| PaymentOption / checkout / orders                     | later |
| SmartUCF / emails / advertising FO                    | later |
| Other module tables / custom order states / FO JS/CSS | later |
