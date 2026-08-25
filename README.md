# UniPayment

Native PrestaShop 9 module for **UniCredit financing** (credit calculator, checkout payment method, order lifecycle, Control Panel integration, and SmartUCF).

| Item                  | Value                                                     |
| --------------------- | --------------------------------------------------------- |
| Module technical name | `unipayment`                                              |
| Current version       | `2.0.1`                                                   |
| Repository            | `wiley68/uni-ps9`                                         |
| Repository root       | Module root (this directory)                              |
| Current state         | **Phase 9 — checkout PaymentOption / financing selection** |

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

Phase 9 provides:

- Phases 0–8 (config, CP, calculator, product/cart FO, popup identity);
- `hookPaymentOptions` + checkout financing UI;
- authoritative `checkoutcalculate` + cart fingerprint / preference handoff;
- `validatecheckout` Phase 9 boundary (validates, does not create orders).

Still **not** implemented:

- financing snapshots / checkout lock / order attempts;
- PrestaShop `validateOrder` / CP order / SmartUCF / emails;
- Thank You / bank status workflow / advertising FO.

## Documentation

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
- [`docs/SECURITY-OPERATIONS.md`](docs/SECURITY-OPERATIONS.md)
- [`docs/TESTING.md`](docs/TESTING.md)
- [`AGENTS.md`](AGENTS.md)
