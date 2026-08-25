# UniPayment

Native PrestaShop 9 module for **UniCredit financing** (credit calculator, checkout payment method, order lifecycle, Control Panel integration, and SmartUCF).

| Item                  | Value                                             |
| --------------------- | ------------------------------------------------- |
| Module technical name | `unipayment`                                      |
| Current version       | `2.0.1`                                           |
| Repository            | `wiley68/uni-ps9`                                 |
| Repository root       | Module root (this directory)                      |
| Current state         | **Phase 3 — persistent shop configuration cache** |

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

Phase 3 provides:

- Back Office local configuration (Phase 1);
- outbound Control Panel client + auth (Phase 2);
- persistent local shop snapshot cache (`unipayment_shop_cache`, 24h TTL);
- `GET /shop` pull on miss / forced refresh with snapshot validation;
- stale-good protection (invalid CP payload does not overwrite valid cache);
- full snapshot replacement (no partial merge);
- credential-change clears tokens **and** shop cache;
- BO „Обнови данните от банката“ via `ShopConfigurationService::get(true)`.

Still **not** implemented:

- CP → module inbound push (`shopcache`, HMAC, nonces) — Phase 4;
- financing UI, payment option, calculators, FO hooks, other module tables, order states, JS/CSS.

Do not use this checkout as a working UniCredit financing integration yet.

## Control Panel (Phase 3)

| Item                          | Status                                              |
| ----------------------------- | --------------------------------------------------- |
| Auth login / refresh / logout | implemented                                         |
| `GET /api/v1/shop`            | fetch + validate + persist local snapshot           |
| Local cache                   | `unipayment_shop_cache` keyed by UNICID, TTL 86400s |
| Bank-data refresh BO button   | enabled                                             |
| CP push / shop-cache inbound  | **not** implemented (Phase 4)                       |

Login payload (parity with PS8/CP): `unicid`, `name` (shop URL), `secret`.

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

Then install (or reinstall) the module from the PrestaShop Back Office so `unipayment_shop_cache` is created.

## Documentation

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
- [`docs/TESTING.md`](docs/TESTING.md)
