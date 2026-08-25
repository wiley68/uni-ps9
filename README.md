# UniPayment

Native PrestaShop 9 module for **UniCredit financing** (credit calculator, checkout payment method, order lifecycle, Control Panel integration, and SmartUCF).

| Item                  | Value                                               |
| --------------------- | --------------------------------------------------- |
| Module technical name | `unipayment`                                        |
| Current version       | `2.0.1`                                             |
| Repository            | `wiley68/uni-ps9`                                   |
| Repository root       | Module root (this directory)                        |
| Current state         | **Phase 2 — Control Panel client + authentication** |

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

Phase 2 provides:

- Back Office local configuration (Phase 1);
- outbound Control Panel client (`login`, `refreshToken`, `logout`, `getShop`, plus unused `createOrder` / `updateOrderStatus` / SSL methods on the same client);
- encrypted local access-token storage;
- controlled 401 recovery (invalidate → re-login → one retry);
- credential-change token invalidation.

Still **not** implemented:

- shop configuration cache / persistence of `GET /shop`;
- inbound signed CP callbacks;
- financing UI, payment option, calculators, FO hooks, module tables, order states, JS/CSS.

Do not use this checkout as a working UniCredit financing integration yet.

## Control Panel (Phase 2)

| Item                        | Status                                |
| --------------------------- | ------------------------------------- |
| `POST /api/v1/auth/login`   | implemented                           |
| `POST /api/v1/auth/refresh` | implemented                           |
| `POST /api/v1/auth/logout`  | implemented                           |
| `GET /api/v1/shop`          | fetch only — **not cached**           |
| Token storage               | encrypted in PrestaShop Configuration |
| Bank-data refresh BO button | still disabled until Phase 3          |

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

Then install the module from the PrestaShop Back Office (Modules → Module Manager).

## Documentation

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
- [`docs/TESTING.md`](docs/TESTING.md)
