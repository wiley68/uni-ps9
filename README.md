# UniPayment

Native PrestaShop 9 module for **UniCredit financing** (credit calculator, checkout payment method, order lifecycle, Control Panel integration, and SmartUCF).

| Item                  | Value                        |
| --------------------- | ---------------------------- |
| Module technical name | `unipayment`                 |
| Current version       | `2.0.1`                      |
| Repository            | `wiley68/uni-ps9`            |
| Repository root       | Module root (this directory) |
| Current state         | **Phase 0 foundation only**  |

This repository currently contains a **minimal installable skeleton**. It has **no production financing functionality**.

## Purpose

Provide a PrestaShop 9-native adapter/port of the UniPayment product family:

- `uni-ps8` remains the functional source of truth;
- `uni-ps9` is the PS9-native implementation.

Phase 0 establishes module identity, Composer/autoload, Symfony service registration, documentation, and a contract test runner. Later phases will port business behavior.

## Platform

| Requirement  | Value                                        |
| ------------ | -------------------------------------------- |
| PrestaShop   | `>= 9.0.0` and `< 10.0.0` (`9.0.x`, `9.1.x`) |
| PHP          | `>= 8.1 < 8.6` (production baseline: 8.1)    |
| Front themes | Hummingbird and Classic                      |
| Module type  | Payment module (`payments_gateways`)         |

The development shop currently runs **PrestaShop 9.1 / PHP 8.4**. Production code must still parse and run on **PHP 8.1**.

## Current implementation status

Phase 0 provides:

- Module Manager discovery;
- install / enable / disable / uninstall / reinstall;
- a placeholder Configure page with no settings;
- empty `src/` namespace and `config/services.yml` registration;
- no financing UI, no payment option, no calculators, no hooks, no module tables, no custom order states, no JS/CSS behavior.

Do not use this checkout as a working UniCredit financing integration yet.

## Reference repositories

| Repository                    | Role                         | Writable |
| ----------------------------- | ---------------------------- | -------- |
| `wiley68/uni-ps9` (this repo) | PrestaShop 9 module          | yes      |
| `wiley68/uni-ps8`             | Functional source of truth   | no       |
| `wiley68/jet-ps9`             | PS9 selector/event reference | no       |
| `wiley68/uni.avalonbg.com`    | Control Panel                | no       |

Do not copy those repositories blindly.

## Installation

```bash
cd modules/unipayment
composer install
```

Then install the module from the PrestaShop Back Office (Modules → Module Manager).

## Documentation

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — intended boundaries vs Phase 0 state
- [`docs/TESTING.md`](docs/TESTING.md) — automated checks and STOP GATE 0
