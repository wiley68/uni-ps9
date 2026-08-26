# UniPayment

Native PrestaShop 9 module for **UniCredit financing** (credit calculator, checkout payment method, order lifecycle, Control Panel integration, and SmartUCF).

| Item                  | Value                                                           |
| --------------------- | --------------------------------------------------------------- |
| Module technical name | `unipayment`                                                    |
| Current version       | `2.0.1`                                                         |
| Repository            | `wiley68/uni-ps9`                                               |
| Repository root       | Module root (this directory)                                    |
| Current state         | **Phase 12 — post-order mail, confirmation UX, BO diagnostics** |

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

Phase 12 provides:

- Phases 0–11 (durable order + CP + SmartUCF / Process 2);
- `FinancingOrderMailDispatcher` + `LeasingEmailNotifier` (customer/admin audiences);
- native `order_conf` flush coordinated with leasing mails;
- Thank You / `displayPaymentReturn` financing notices;
- BO financing block (`displayAdminOrderMainBottom`);
- privacy: Process 1 no EGN; Process 2 admin may receive EGN; customer never receives EGN.

Still **not** implemented (Phase 13+):

- advertising FO / landing promotional content;
- release packaging / tagging;
- v2.0.2 scheme aggregation/sorting.

## Documentation

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
- [`docs/SECURITY-OPERATIONS.md`](docs/SECURITY-OPERATIONS.md)
- [`docs/RECOVERY.md`](docs/RECOVERY.md)
- [`docs/TESTING.md`](docs/TESTING.md)
- [`AGENTS.md`](AGENTS.md)
