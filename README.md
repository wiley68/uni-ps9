# UniPayment

Native PrestaShop 9 module for **UniCredit financing** (credit calculator, checkout payment method, order lifecycle, Control Panel integration, and SmartUCF).

| Item                  | Value                                             |
| --------------------- | ------------------------------------------------- |
| Module technical name | `unipayment`                                      |
| Current version       | `2.0.1`                                           |
| Repository            | `wiley68/uni-ps9`                                 |
| Repository root       | Module root (this directory)                      |
| Current state         | **Phase 4 — inbound signed CP → module API**      |

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

Phase 4 provides:

- Back Office local configuration (Phase 1);
- outbound Control Panel client + auth (Phase 2);
- persistent shop snapshot cache + BO bank refresh (Phase 3);
- inbound signed endpoints: `shopcache`, `orderbankstatus`, `smartucfdebuglog`;
- HMAC-SHA256 + timestamp ±300s + nonce replay store (900s);
- tables: `unipayment_shop_cache`, `unipayment_api_nonce`, `unipayment_order_bank_status`, `unipayment_smartucf_log`.

Still **not** implemented:

- calculator / product / cart / PaymentOption;
- financing snapshots / checkout lock / order attempts;
- SmartUCF outbound / leasing emails / Thank You;
- FO JS/CSS / custom order states / BO journal download.

Do not use this checkout as a working UniCredit financing integration yet.

## Inbound endpoints

| URL | Purpose |
| --- | ------- |
| `/module/unipayment/shopcache` | CP push full shop snapshot |
| `/module/unipayment/orderbankstatus` | Persist bank status (AUD-011) |
| `/module/unipayment/smartucfdebuglog` | CP read diagnostic journal entry |

Signing details: [`docs/SECURITY-OPERATIONS.md`](docs/SECURITY-OPERATIONS.md).

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

Then install (or reinstall) the module from the PrestaShop Back Office so Phase 3–4 tables are created.

## Documentation

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
- [`docs/SECURITY-OPERATIONS.md`](docs/SECURITY-OPERATIONS.md)
- [`docs/TESTING.md`](docs/TESTING.md)
- [`AGENTS.md`](AGENTS.md)
