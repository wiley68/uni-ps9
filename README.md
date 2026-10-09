# UniPayment

Native PrestaShop 9 module for **UniCredit financing** (credit calculator, checkout payment method, order lifecycle, Control Panel integration, SmartUCF, and homepage advertising).

| Item                  | Value                                                                         |
| --------------------- | ----------------------------------------------------------------------------- |
| Module technical name | `unipayment`                                                                  |
| Current version       | `2.0.3`                                                                       |
| Repository            | `wiley68/uni-ps9`                                                             |
| Repository root       | Module root (this directory)                                                  |
| Current state         | **2.0.3** CP/SmartUCF failure Thank You + Satrudnik mail; 2.0.2 scheme parity |

## Purpose

PrestaShop 9-native adapter/port of the UniPayment product family:

- `uni-ps8` = functional source of truth
- `uni-ps9` = PS9-native implementation

## Supported platform

| Requirement  | Value                                        |
| ------------ | -------------------------------------------- |
| PrestaShop   | `>= 9.0.0` and `< 10.0.0` (`9.0.x`, `9.1.x`) |
| PHP          | `>= 8.1 < 8.6` (production baseline: 8.1)    |
| Front themes | Hummingbird 2.0 (primary) and Classic 3.1.1  |
| Module type  | Payment module (`payments_gateways`)         |

No PrestaShop 10 support claim. No production jQuery dependency.

## Features

- Product and cart credit calculators (vanilla JS)
- Product and cart financing popups with **durable** order completion (PS order → CP → SmartUCF / Process 2)
- Checkout `PaymentOption` with server-side validation and the same durable lifecycle
- Process 1: CP order + SmartUCF session + trusted bank redirect
- Process 2: native confirmation (EGN required; no SmartUCF)
- Exactly-once durable financing (lock / attempt / snapshot / CP)
- Financing customer/admin emails + native `order_conf` coordination
- BO financing diagnostics and UniCredit status grid column
- Homepage advertising float from **local fresh shop cache only** (no synchronous CP on FO render)
- Signed inbound CP API (`shopcache`, `orderbankstatus`, `smartucfdebuglog`)

**Public bank status / leasing information contract:** see [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) § _Authoritative bank status and leasing information_ (four initial standard bank statuses; internal lifecycle states are not public bank status).

## Control Panel dependency

Shop must be registered in UniPayment Control Panel with matching **UNICID** and shared secret.

Sole CP destination: `config/environment.php` (`control_panel_url`), validated as a public HTTPS root on port 443; outbound API base = normalized origin + `/api/v1`. Switching that one value invalidates foreign/legacy ephemeral state and blocks unproven durable replay. See [origin compatibility](docs/ARCHITECTURE.md#control-panel-destination-and-origin-provenance).

Shop schemes and promotional fields come from cached `GET /api/v1/shop` snapshots. Homepage advertising uses the shared PRESENTATION resolver: fresh data stays local, stale data triggers coordinated revalidation, and only a structurally valid snapshot no more than 6 hours stale may be used after a transient refresh failure.

## Configuration (BO)

1. Install the module (self-contained ZIP; no SSH / env setup)
2. Set UNICID + shared secret; enable module
3. Refresh bank data (shop cache)
4. Optionally enable advertising
5. Verify Process 1 / Process 2 via CP `uni_proces`

See [`docs/INSTALLATION.md`](docs/INSTALLATION.md).

## Security overview

- HMAC signed inbound requests + nonce replay store
- Encrypted tokens/secrets and Process 2 `sensitive_payload`
- SmartUCF diagnostic journal is **shop-scoped** (`id_shop` + order id)
- Audience-specific mail privacy (no customer EGN)
- Promo URLs restricted to `http`/`https`; text via `strip_tags`
- 180-day financing snapshot PII retention
- mTLS passphrase in `secrets/smartucf-key.php` (ZIP packaging; not Git)

See [`docs/SECURITY-OPERATIONS.md`](docs/SECURITY-OPERATIONS.md).

## Troubleshooting

- Cache / CP issues → [`docs/RECOVERY.md`](docs/RECOVERY.md)
- Manual smoke areas → [`docs/TESTING.md`](docs/TESTING.md)
- Release checklist (no tag yet) → [`docs/RELEASE.md`](docs/RELEASE.md)

## Deferred

- Production release tag / package (operator-driven)
- Bank-rejection → native PS order-state sync (AUD-009; dormant until proven CP status codes)

## Documentation

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
- [`docs/INSTALLATION.md`](docs/INSTALLATION.md)
- [`docs/SECURITY-OPERATIONS.md`](docs/SECURITY-OPERATIONS.md)
- [`docs/RECOVERY.md`](docs/RECOVERY.md)
- [`docs/TESTING.md`](docs/TESTING.md)
- [`docs/RELEASE.md`](docs/RELEASE.md)
- [`AGENTS.md`](AGENTS.md)
- [`CHANGELOG.md`](CHANGELOG.md)
