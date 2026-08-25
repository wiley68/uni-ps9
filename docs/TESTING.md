# Testing

UniPayment CLI tests run against the module checkout in the PrestaShop test shop filesystem. The repository lives inside a **live dev installation**, so the default suite must remain non-destructive.

## Development environment

| Item                    | Current value      |
| ----------------------- | ------------------ |
| Shop                    | PrestaShop **9.1** |
| CLI / FPM PHP           | **8.4**            |
| Production PHP baseline | **8.1**            |

## Automated checks

Safe default:

```bash
composer test
```

Also useful for Phase 6 JS helpers:

```bash
node tests/Product/ProductCalculatorJsTest.js
```

Phase 6 product coverage:

| Test                                                        | What it checks                                     |
| ----------------------------------------------------------- | -------------------------------------------------- |
| `tests/Product/ProductContextFactoryTest.php`               | Server-side price × qty / combination              |
| `tests/Product/ProductCalculatorPresenterTest.php`          | Offers / preferred / currency / visuals            |
| `tests/Product/ProductPopupCalculatorTest.php`              | Modal calculate + presenter consents               |
| `tests/Product/ProductCalculatorControllerContractTest.php` | Endpoint validation / safe errors / calculate-only |
| `tests/Product/ProductButtonVisualContractTest.php`         | Template/CSS/JS visual contracts                   |
| `tests/Frontend/ProductFrontendContractTest.php`            | Hooks, assets, no jQuery, lifecycle, envelopes     |
| `tests/Frontend/ProductStaleRequestRaceContractTest.php`    | AbortController + sequence stale-response guard    |

Phase 5 calculator coverage remains under `tests/Calculator/*`.

Prior phases remain covered under `tests/Configuration`, `tests/Api`, `tests/Security`, `tests/Order`, `tests/SmartUcf`, `tests/Infrastructure`.

Other useful commands:

```bash
composer validate --no-check-publish
composer dump-autoload
git diff --check
```

## Manual STOP GATE 6

Product-page FO gate (Hummingbird + Classic). See Phase 6 implementation report. Do not start Phase 7 until accepted.
