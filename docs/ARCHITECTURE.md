# UniPayment — Architecture

This document describes **intended** high-level boundaries and the **implemented Phase 1** state. It does not claim that later-phase components already exist.

---

## Source of truth vs adapter

```text
uni-ps8 = functional source of truth
uni-ps9 = PS9-native adapter/port
```

Preserve UniPayment business semantics from `wiley68/uni-ps8`. Implement them with native PrestaShop 9 mechanisms. Do not blindly copy the PS8 repository, and do not copy CreditJet (`wiley68/jet-ps9`) business logic or jQuery frontend architecture.

---

## Intended layering (planned)

```text
PrestaShop integration
        ↓
Application/domain
        ↓
Infrastructure
```

| Layer                      | Planned responsibility                                                                     |
| -------------------------- | ------------------------------------------------------------------------------------------ |
| **PrestaShop integration** | Module entry, hooks, front controllers, Smarty templates, payment option, BO configuration |
| **Application/domain**     | Financing calculation, cart scheme intersection, checkout validation, order orchestration  |
| **Infrastructure**         | Control Panel client, SmartUCF, persistence, secrets, signed inbound APIs                  |

Business rules should stay out of templates and hooks. PrestaShop types should stay out of domain services where practical.

---

## Implemented in Phase 1

| Area                       | Phase 1 state                                                                             |
| -------------------------- | ----------------------------------------------------------------------------------------- |
| Module entry               | `unipayment.php` extends `PaymentModule`, PS9-only compliancy, new translation system     |
| Autoload                   | Composer PSR-4 `PrestaShop\Module\Unipayment\` → `src/`                                   |
| Symfony services           | Minimal `config/services.yml` resource registration                                       |
| Local configuration        | `ConfigurationRepository`, `ConfigurationValidator`, encrypted secret via `PhpEncryption` |
| Credential-change boundary | `CredentialChangeSideEffectHandler` (no-op until Phase 2/3 token + shop cache)            |
| Configuration UI           | `getContent()` + `views/templates/admin/configuration.tpl`                                |
| CP-dependent BO actions    | Refresh / journal controls visible but disabled; no remote calls                          |
| Front office               | No functional hooks, controllers, JS, or CSS                                              |
| Persistence                | PrestaShop `Configuration` keys only; no module-owned tables                              |
| Orders                     | No custom order states                                                                    |
| Control Panel / SmartUCF   | Not connected                                                                             |

### Local configuration keys

```text
UNIPAYMENT_ENABLED
UNIPAYMENT_UNICID
UNIPAYMENT_SECRET          (enc:v1:… via PhpEncryption / _NEW_COOKIE_KEY_)
UNIPAYMENT_ADVERTISING_ENABLED
UNIPAYMENT_DEBUG_ENABLED
UNIPAYMENT_PRODUCT_BUTTON_ACTION
UNIPAYMENT_BUTTON_TOP_SPACING
UNIPAYMENT_SYNC_BANK_REJECTION_STATE   (stored; not exposed in UI — PS8 parity)
```

Multishop scoping matches PS8: `Configuration::updateValue` / `Configuration::get` without explicit shop overrides (PrestaShop context defaults).

### Credential change invalidation

When UNICID changes or a new secret is submitted, Phase 1 detects `$credentialsChanged` and calls `CredentialChangeSideEffectHandler::onCredentialsChanged()`.

That handler is intentionally empty until:

- Phase 2 — `TokenRepository::invalidate()`
- Phase 3 — `ShopConfigurationCache::clear()`

---

## Explicitly not implemented yet

- ControlPanelClient / authentication tokens / `/shop` pull;
- shop configuration cache and signed inbound API;
- calculator / product / cart / checkout / payment option;
- module database tables and uninstall purge beyond local Configuration keys;
- custom order states;
- mail bodies;
- functional JS/CSS;
- `routes.yml`, Symfony controllers/forms, Doctrine entities;
- `upgrade/` scripts;
- `keys/` certificate store.

Those belong to later phases and must not be added until requested.
