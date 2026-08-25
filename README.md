# UniPayment

Native PrestaShop 9 module for **UniCredit financing** (credit calculator, checkout payment method, order lifecycle, Control Panel integration, and SmartUCF).

| Item                  | Value                                   |
| --------------------- | --------------------------------------- |
| Module technical name | `unipayment`                            |
| Current version       | `2.0.1`                                 |
| Repository            | `wiley68/uni-ps9`                       |
| Repository root       | Module root (this directory)            |
| Current state         | **Phase 1 — local configuration layer** |

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

The development shop currently runs **PrestaShop 9.1 / PHP 8.4**. Production code must still parse and run on **PHP 8.1**.

## Current implementation status

Phase 1 provides:

- Module Manager discovery and install/enable/disable/uninstall/reinstall;
- Back Office local configuration page (`getContent()` + Smarty template);
- encrypted secret storage, UNICID validation, local flags and product-button settings;
- no Control Panel HTTP calls;
- no financing UI, payment option, calculators, FO hooks, module tables, custom order states, or JS/CSS behavior.

Do not use this checkout as a working UniCredit financing integration yet.

## Local configuration (Phase 1)

Merchant-facing settings (Back Office → Modules → UniPayment → Configure):

| Setting               | Purpose                                      |
| --------------------- | -------------------------------------------- |
| Enable module         | Master on/off (`UNIPAYMENT_ENABLED`)         |
| UNICID                | Shop identifier for future CP authentication |
| Shared secret         | CP/module shared secret; stored encrypted    |
| Advertising enabled   | Homepage promotional content gate (stored)   |
| Debug enabled         | Diagnostic flag (stored; no FO effect yet)   |
| Product button action | `add_to_cart` / `buy` (stored only)          |
| Button top spacing    | 0–200 px (stored only)                       |

Business financing rules remain owned by the Control Panel and are **not** duplicated locally.

CP-dependent actions on the configuration page (**Refresh bank data**, **Download journal**) are visible but **disabled** until later phases.

## Reference repositories

| Repository                    | Role                         | Writable |
| ----------------------------- | ---------------------------- | -------- |
| `wiley68/uni-ps9` (this repo) | PrestaShop 9 module          | yes      |
| `wiley68/uni-ps8`             | Functional source of truth   | no       |
| `wiley68/jet-ps9`             | PS9 selector/event reference | no       |
| `wiley68/uni.avalonbg.com`    | Control Panel                | no       |

## Installation

```bash
cd modules/unipayment
composer install
```

Then install the module from the PrestaShop Back Office (Modules → Module Manager).

## Documentation

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — intended boundaries vs implemented state
- [`docs/TESTING.md`](docs/TESTING.md) — automated checks and STOP GATE 1
