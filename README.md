# UniPayment

Native PrestaShop 9 module for **UniCredit financing** (credit calculator, checkout payment method, order lifecycle, Control Panel integration, SmartUCF, and homepage advertising).

| Item                  | Value                                                          |
| --------------------- | -------------------------------------------------------------- |
| Module technical name | `unipayment`                                                   |
| Current version       | `2.0.1` (development line; no release tag/package in Phase 13) |
| Repository            | `wiley68/uni-ps9`                                              |
| Repository root       | Module root (this directory)                                   |
| Current state         | **Phase 13 — advertising FO + install/uninstall hygiene**      |

## Purpose

PrestaShop 9-native adapter/port of the UniPayment product family:

- `uni-ps8` = functional source of truth
- `uni-ps9` = PS9-native implementation

## Supported platform

| Requirement  | Value                                        |
| ------------ | -------------------------------------------- |
| PrestaShop   | `>= 9.0.0` and `< 10.0.0` (`9.0.x`, `9.1.x`) |
| PHP          | `>= 8.1 < 8.6` (production baseline: 8.1)    |
| Front themes | Hummingbird 2.0 and Classic 3.1.1            |
| Module type  | Payment module (`payments_gateways`)         |

No PrestaShop 10 support claim. No production jQuery dependency.

## Features

- Product and cart credit calculators (vanilla JS)
- Checkout `PaymentOption` with server-side validation
- Process 1: CP order + SmartUCF session + trusted bank redirect
- Process 2: native confirmation (EGN required; no SmartUCF)
- Exactly-once durable checkout (lock / attempt / snapshot / CP)
- Financing customer/admin emails + native `order_conf` coordination
- BO financing diagnostics and UniCredit status grid column
- Homepage advertising float (cached CP promotional content)
- Signed inbound CP API (`shopcache`, `orderbankstatus`, `smartucfdebuglog`)

## Control Panel dependency

Shop must be registered in UniPayment Control Panel with matching **UNICID** and shared secret.

Default CP host: `config/environment.php` (`control_panel_url`); API base = host + `/api/v1`.

Promotional content and schemes come from cached `GET /api/v1/shop` snapshots (Phase 3). FO pages do **not** call CP synchronously for advertising.

## Configuration (BO)

1. Install the module
2. Set UNICID + shared secret; enable module
3. Refresh bank data (shop cache)
4. Optionally enable advertising
5. Verify Process 1 / Process 2 via CP `uni_proces`

See [`docs/INSTALLATION.md`](docs/INSTALLATION.md).

## Security overview

- HMAC signed inbound requests + nonce replay store
- Encrypted tokens/secrets and Process 2 `sensitive_payload`
- Audience-specific mail privacy (no customer EGN)
- Promo URLs restricted to `http`/`https`; text via `strip_tags`
- 180-day financing snapshot PII retention

See [`docs/SECURITY-OPERATIONS.md`](docs/SECURITY-OPERATIONS.md).

## Troubleshooting

- Cache / CP issues → [`docs/RECOVERY.md`](docs/RECOVERY.md)
- Manual smoke areas → [`docs/TESTING.md`](docs/TESTING.md)
- Release checklist (no tag yet) → [`docs/RELEASE.md`](docs/RELEASE.md)

## Deferred (not Phase 13)

- Final audit/remediation cycle
- Release tag / production package
- Coordinated **v2.0.2** scheme aggregation (`months ASC`; same months: standard before promo)

## Documentation

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
- [`docs/INSTALLATION.md`](docs/INSTALLATION.md)
- [`docs/SECURITY-OPERATIONS.md`](docs/SECURITY-OPERATIONS.md)
- [`docs/RECOVERY.md`](docs/RECOVERY.md)
- [`docs/TESTING.md`](docs/TESTING.md)
- [`docs/RELEASE.md`](docs/RELEASE.md)
- [`AGENTS.md`](AGENTS.md)
- [`CHANGELOG.md`](CHANGELOG.md)
