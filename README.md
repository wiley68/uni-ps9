# UniPayment

Native PrestaShop 9 module for **UniCredit financing** (credit calculator, checkout payment method, order lifecycle, Control Panel integration, and SmartUCF).

| Item                  | Value                                                                        |
| --------------------- | ---------------------------------------------------------------------------- |
| Module technical name | `unipayment`                                                                 |
| Current version       | `2.0.1`                                                                      |
| Repository            | `wiley68/uni-ps9`                                                            |
| Repository root       | Module root (this directory)                                                 |
| Current state         | **Phase 10 — durable checkout submission (PS order + snapshot + CP create)** |

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

Phase 10 provides:

- Phases 0–9 (config, CP, calculator, product/cart FO, checkout PaymentOption);
- atomic checkout lock + durable `order_attempt` + `financing_snapshot`;
- exactly-once native PrestaShop order + CP `POST /api/v1/orders`;
- `bank_send_failed_cp` on CP failure after PS order exists;
- temporary post-order UX (`checkout_validated.tpl` / order-confirmation redirect).

Still **not** implemented:

- SmartUCF Process 1 / 2;
- `PostControlPanelLifecycleService` / final bank statuses;
- financing email lifecycle;
- final Thank You redesign;
- advertising FO.

## Documentation

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
- [`docs/SECURITY-OPERATIONS.md`](docs/SECURITY-OPERATIONS.md)
- [`docs/RECOVERY.md`](docs/RECOVERY.md)
- [`docs/TESTING.md`](docs/TESTING.md)
- [`AGENTS.md`](AGENTS.md)
