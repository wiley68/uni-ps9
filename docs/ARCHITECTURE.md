# UniPayment — Architecture

This document describes **intended** high-level boundaries and the **implemented Phase 2** state.

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

## Implemented in Phase 2

| Area                       | Phase 2 state                                                                                                        |
| -------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| Local configuration        | Phase 1 repository/validator/UI                                                                                      |
| Credential-change boundary | `CredentialChangeSideEffectHandler` → `TokenRepository::invalidate()`                                                |
| Token storage              | `TokenRepository` (`enc:v1:` via `PhpEncryption` / `_NEW_COOKIE_KEY_`)                                               |
| HTTP transport             | `CurlHttpTransport` (TLS verify on, timeouts 5s/15s)                                                                 |
| CP client                  | `ControlPanelClient` (`login`, `refreshToken`, `logout`, `getShop`, `createOrder`, `updateOrderStatus`, SSL helpers) |
| `GET /shop`                | authenticated fetch; **not persisted**                                                                               |
| BO refresh / journal       | still disabled (Phase 3 / later)                                                                                     |
| Front office               | no functional hooks, controllers, JS, or CSS                                                                         |
| Inbound CP callbacks       | not implemented                                                                                                      |

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

Shop identity `name` is `Tools::getShopDomainSsl(true) . __PS_BASE_URI__` (trimmed), same as PS8. Platform type is **not** sent in the login body (CP matches shop by unicid + name).

### Token Configuration keys

```text
UNIPAYMENT_CP_ACCESS_TOKEN   (encrypted)
UNIPAYMENT_CP_TOKEN_TYPE
UNIPAYMENT_CP_TOKEN_EXPIRES_AT
```

Multishop scoping matches PS8: default PrestaShop `Configuration` context (no explicit shop override).

---

## Explicitly not implemented yet

| Area                                                     | Phase |
| -------------------------------------------------------- | ----- |
| `ShopConfigurationCache` / shop snapshot persistence     | 3     |
| Signed inbound API (`shopcache`, bank status, debug log) | 4     |
| Calculator / product / cart                              | 5+    |
| PaymentOption / checkout / orders                        | later |
| SmartUCF / emails / advertising FO                       | later |
| Module tables / custom order states / FO JS/CSS          | later |
