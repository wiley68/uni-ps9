# Testing

UniPayment CLI tests run against the module checkout in the PrestaShop test shop filesystem. The repository lives inside a **live dev installation**, so the default suite must remain non-destructive.

## Development environment

| Item                    | Current value      |
| ----------------------- | ------------------ |
| Shop                    | PrestaShop **9.1** |
| CLI / FPM PHP           | **8.4**            |
| Production PHP baseline | **8.1**            |
| Supported CLI matrix    | PHP **8.1–8.5**    |

## Automated checks

Safe default:

```bash
composer test
```

The release / regression gate runs the **full safe suite on PHP 8.1–8.5**.

Also useful for product JS helpers:

```bash
node tests/Product/ProductCalculatorJsTest.js
```

```bash
composer validate --no-check-publish
composer dump-autoload
git diff --check
```

Destructive Aud006 DB purge test **SKIPs** in the safe suite.

### Coverage map (by area)

| Area                                            | Location                                        |
| ----------------------------------------------- | ----------------------------------------------- |
| Calculator / parity                             | `tests/Calculator/*`                            |
| Configuration / shop cache / AUD-022 cache-only | `tests/Configuration/*`                         |
| Inbound API / CP client                         | `tests/Api/*`                                   |
| Security / tokens / AUD-021 secrets             | `tests/Security/*`                              |
| Product FO / popup                              | `tests/Product/*`, `tests/Frontend/Product*`    |
| Cart FO / popup / twin-order contracts          | `tests/Cart/*`, `tests/Frontend/Cart*`          |
| Checkout / lock / AUD-019                       | `tests/Checkout/*`, `tests/Frontend/Checkout*`  |
| Order / mail / bank status                      | `tests/Order/*`                                 |
| SmartUCF / AUD-020 journal scope                | `tests/SmartUcf/*`                              |
| Advertising / AUD-022 FO isolation              | `tests/Advertising/*`                           |
| Uninstall                                       | `tests/Uninstall/*`                             |
| Remediation / infrastructure                    | `tests/Remediation/*`, `tests/Infrastructure/*` |

Do not hard-code a permanent test file count here — it changes with each remediation. Prefer `composer test` output as the source of truth.

## Manual smoke (final regression)

| Area        | Checks                                                                                 |
| ----------- | -------------------------------------------------------------------------------------- |
| Product     | Calculator + financing popup (Hummingbird + Classic)                                   |
| Cart        | Calculator + financing popup; **guest cart** → exactly one authoritative PS order      |
| Checkout    | PaymentOption; Process 1 / Process 2; double-click stays post-order (AUD-019)          |
| Advertising | Fresh cache shows float; missing/stale cache → homepage OK, no advertising, no CP wait |
| Packaging   | ZIP with `config/environment.php` + `secrets/smartucf-key.php` only (no SSH/env)       |
| Privacy     | Process 1/2 mail audiences; no customer EGN                                            |

Historical phase STOP gates (7–13) are **completed** delivery milestones; they are not the current release gate.

## Deferred product behavior

- Coordinated **v2.0.2** scheme aggregation (`tests/Checkout/DeferredV202PromoAggregationContractTest.php` documents the deferral)
- Bank-rejection → native PS order-state sync remains dormant (AUD-009)
