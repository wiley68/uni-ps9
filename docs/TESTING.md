# Testing

UniPayment CLI tests run against the module checkout in the PrestaShop test shop filesystem. The repository lives inside a **live dev installation**, so the default suite must remain non-destructive.

## Development environment

| Item                    | Current value      |
| ----------------------- | ------------------ |
| Shop                    | PrestaShop **9.1** |
| CLI / FPM PHP           | **8.4**            |
| Production PHP baseline | **8.1**            |
| Supported CLI matrix    | PHP **8.1–8.5**    |

## EUR-only regression gate

`tests/Calculator/EurCurrencyGuardsTest.php` covers ISO normalization and cart/context currency ID and ISO coherence. Snapshot validation tests confirm `uni_eur` is optional and opaque. `tests/Order/OrderOrchestratorTest.php` exercises new EUR CP create and rejects incomplete, malformed, non-EUR or mismatched frozen CP payloads without another HTTP call. `tests/Order/EurPopupReplayTest.php` exercises durable provenance for both product and cart popup replay. The SmartUCF boundary tests cover invalid snapshot replay and Process 2; the checkout lock-loser test rejects old non-EUR or missing durable evidence before exposing a stored redirect.

EUR-PS9-004 coverage also checks positive and inconsistent CP state/ID combinations in the orchestrator, both popup replay paths, checkout lock-loser recovery, direct post-CP lifecycle and direct SmartUCF coordination. The lock-loser negative cases each restore a known-valid snapshot before changing one cause; invalid replay tests assert no extra CP/SmartUCF request, journal claim or durable rewrite.

Run `composer test`, `node tests/Product/ProductCalculatorJsTest.js`, PHP syntax checks for changed PHP files, JS syntax checks, and `git diff --check`. The safe suite uses test doubles and does not create live shop orders or call external financing endpoints.

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
| Configuration / shared cache lifecycle / LKG     | `tests/Configuration/*`                         |
| Inbound API / CP client                         | `tests/Api/*`                                   |
| Security / tokens / AUD-021 secrets             | `tests/Security/*`                              |
| Product FO / popup                              | `tests/Product/*`, `tests/Frontend/Product*`    |
| Cart FO / popup / twin-order contracts          | `tests/Cart/*`, `tests/Frontend/Cart*`          |
| Checkout / lock / AUD-019                       | `tests/Checkout/*`, `tests/Frontend/Checkout*`  |
| Order / mail / bank status                      | `tests/Order/*`                                 |
| SmartUCF / AUD-020 journal scope                | `tests/SmartUcf/*`                              |
| Advertising / shared presentation resolver      | `tests/Advertising/*`                           |
| Uninstall                                       | `tests/Uninstall/*`                             |
| Remediation / infrastructure                    | `tests/Remediation/*`, `tests/Infrastructure/*` |

Do not hard-code a permanent test file count here — it changes with each remediation. Prefer `composer test` output as the source of truth.

## Manual smoke (final regression)

| Area        | Checks                                                                                                                           |
| ----------- | -------------------------------------------------------------------------------------------------------------------------------- |
| Product     | Calculator + financing popup (Hummingbird + Classic)                                                                             |
| Cart        | Calculator + financing popup; **guest cart** → exactly one authoritative PS order                                                |
| Checkout    | PaymentOption; Process 1 / Process 2; double-click stays post-order (AUD-019)                                                    |
| Advertising | Fresh cache is local; stale refreshes; transient <=6h may show valid LKG; Class B/C and too-old hide safely                       |
| Packaging   | ZIP with `config/environment.php` + `secrets/smartucf-key.php` only (no SSH/env)                                                 |
| Privacy     | Process 1/2 mail audiences; no customer EGN; public bank status only on emails/Thank You (see ARCHITECTURE bank-status contract) |

Historical phase STOP gates (7–13) are **completed** delivery milestones; they are not the current release gate.

## Authoritative manual-test contract

For bank status, leasing panel fields, Thank You / email content, and diagnostic visibility, use:

[`ARCHITECTURE.md`](ARCHITECTURE.md) § _Authoritative bank status and leasing information_

Do not treat internal lifecycle/machine ids as public bank-status copy during manual verification.

## Deferred product behavior

- Bank-rejection → native PS order-state sync remains dormant (AUD-009)
- Non-blocking test-quality follow-ups (DOM JS transition tests, forged-selection validator coverage, SKIP accounting in the runner)
