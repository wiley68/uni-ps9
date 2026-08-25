# UniPayment

Native PrestaShop 9 module for **UniCredit financing** (credit calculator, checkout payment method, order lifecycle, Control Panel integration, and SmartUCF).

| Item                  | Value                                                     |
| --------------------- | --------------------------------------------------------- |
| Module technical name | `unipayment`                                              |
| Current version       | `2.0.1`                                                   |
| Repository            | `wiley68/uni-ps9`                                         |
| Repository root       | Module root (this directory)                              |
| Current state         | **Phase 8 — cart-page financing**                         |

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

Phase 8 provides:

- Phases 0–7 (config, CP client, shop cache, inbound API, calculator, product FO, popup identity);
- cart calculator via `displayShoppingCart` + `cartcalculator` / `cartpopup`;
- cart payable-total semantics (`Cart::getOrderTotal(true, Cart::BOTH)`);
- cart popup identity via shared `unipayment_popup_submission` with `flow=cart_popup`.

Still **not** implemented:

- PaymentOption / checkout financing method;
- financing snapshots / checkout lock / order attempts;
- SmartUCF outbound / emails / advertising FO;
- PrestaShop or Control Panel order creation from the popup.

## Documentation

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
- [`docs/SECURITY-OPERATIONS.md`](docs/SECURITY-OPERATIONS.md)
- [`docs/TESTING.md`](docs/TESTING.md)
- [`AGENTS.md`](AGENTS.md)
