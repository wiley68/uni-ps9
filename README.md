# UniPayment

Native PrestaShop 9 module for **UniCredit financing** (credit calculator, checkout payment method, order lifecycle, Control Panel integration, and SmartUCF).

| Item                  | Value                                                             |
| --------------------- | ----------------------------------------------------------------- |
| Module technical name | `unipayment`                                                      |
| Current version       | `2.0.1`                                                           |
| Repository            | `wiley68/uni-ps9`                                                 |
| Repository root       | Module root (this directory)                                      |
| Current state         | **Phase 11 — post-CP lifecycle (SmartUCF Process 1 / Process 2)** |

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

Phase 11 provides:

- Phases 0–10 (durable PS order + snapshot + CP create);
- `PostControlPanelLifecycleService` after `cp_created`;
- SmartUCF Process 1 (session create/resume) → `bank_sent_process1`;
- Process 2 handoff (no SmartUCF) → `bank_sent_process2`;
- `bank_send_failed_smartucf` when CP succeeded but SmartUCF failed;
- deferred native `order_conf` flush via `Phase11DeferredMailDispatcher`.

Still **not** implemented:

- full financing customer/admin email presentation (Phase 12);
- final Thank You redesign;
- advertising FO;
- v2.0.2 scheme ordering.

## Documentation

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
- [`docs/SECURITY-OPERATIONS.md`](docs/SECURITY-OPERATIONS.md)
- [`docs/RECOVERY.md`](docs/RECOVERY.md)
- [`docs/TESTING.md`](docs/TESTING.md)
- [`AGENTS.md`](AGENTS.md)
