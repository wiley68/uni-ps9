# UniPayment — Architecture

This document describes **intended** high-level boundaries and the **implemented Phase 0** state. It does not claim that later-phase components already exist.

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

This layering is a target for later phases. It is **not** implemented in Phase 0.

---

## Implemented in Phase 0

Phase 0 is foundation only:

| Area                     | Phase 0 state                                                                         |
| ------------------------ | ------------------------------------------------------------------------------------- |
| Module entry             | `unipayment.php` extends `PaymentModule`, PS9-only compliancy, new translation system |
| Autoload                 | Composer PSR-4 `PrestaShop\Module\Unipayment\` → `src/`                               |
| Symfony services         | Minimal `config/services.yml` resource registration; no application services          |
| `src/`                   | Protective `index.php` only; no domain/infrastructure classes                         |
| Configuration UI         | Placeholder `getContent()` message; no form, no persisted settings                    |
| Front office             | No functional hooks, controllers, JS, or CSS                                          |
| Persistence              | No module-owned tables                                                                |
| Orders                   | No custom order states                                                                |
| Control Panel / SmartUCF | Not connected                                                                         |

---

## Explicitly not implemented yet

Do not treat these as present:

- calculator / product / cart / checkout / payment option;
- Control Panel credentials, token storage, replay protection;
- module database tables and uninstall purge;
- custom order states;
- mail bodies;
- functional JS/CSS;
- `routes.yml`, Symfony controllers/forms, Doctrine entities;
- `upgrade/` scripts;
- `keys/` certificate store.

Those belong to later phases and must not be added until requested.
