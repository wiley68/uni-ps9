# UniPayment

Native PrestaShop 9 module for **UniCredit financing** (credit calculator, checkout payment method, order lifecycle, Control Panel integration, and SmartUCF).

| Item                  | Value                                           |
| --------------------- | ----------------------------------------------- |
| Module technical name | `unipayment`                                    |
| Current version       | `2.0.1`                                         |
| Repository            | `wiley68/uni-ps9`                               |
| Repository root       | Module root (this directory)                    |
| Current state         | **Phase 6 — product-page financing calculator** |

## Purpose

Provide a PrestaShop 9-native adapter/port of the UniPayment product family:

- `uni-ps8` remains the functional source of truth;
- `uni-ps9` is the PS9-native implementation.

## Platform

| Requirement  | Value                                        |
| ------------ | -------------------------------------------- |
| PrestaShop   | `>= 9.0.0` and `< 10.0.0` (`9.0.x`, `9.1.x`) |
| PHP          | `>= 8.1 < 8.6` (production baseline: 8.1)    |
| Front themes | Hummingbird and Classic                      |
| Module type  | Payment module (`payments_gateways`)         |

## Current implementation status

Phase 6 provides:

- Phases 0–5 (config, CP client, shop cache, inbound signed API, calculator domain);
- product-page context factory, presenters, hook, AJAX endpoints;
- vanilla JS/CSS for Hummingbird + Classic combination lifecycle.

Still **not** implemented:

- popup submission persistence / dedupe (Phase 7);
- cart calculator / PaymentOption / checkout;
- financing snapshots / checkout lock / order attempts;
- SmartUCF outbound / emails / advertising FO.

Product calculator presentation works; order/apply flows from the modal are deferred.

## Documentation

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
- [`docs/SECURITY-OPERATIONS.md`](docs/SECURITY-OPERATIONS.md)
- [`docs/TESTING.md`](docs/TESTING.md)
- [`AGENTS.md`](AGENTS.md)
