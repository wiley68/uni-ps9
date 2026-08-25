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

Phase 5 calculator coverage:

| Test                                            | What it checks                                                            |
| ----------------------------------------------- | ------------------------------------------------------------------------- |
| `tests/Calculator/CalculatorDomainTest.php`     | PS8 critical scenarios (filters, months, coeff, first installment, promo) |
| `tests/Calculator/GoldenParityVectorsTest.php`  | Explicit numeric golden vectors                                           |
| `tests/Calculator/Ps8ParityHarnessTest.php`     | Frozen oracle + live PS8 subprocess cross-run                             |
| `tests/Calculator/WooReferenceParityTest.php`   | Optional Woo helper parity (SKIP if Woo bootstrap incomplete)             |
| `tests/Calculator/CurrencyDisplayLabelTest.php` | Display suffixes + module catalog registration                            |

Prior phases remain covered under `tests/Configuration`, `tests/Api`, `tests/Security`, `tests/Order`, `tests/SmartUcf`, `tests/Infrastructure`.

Other useful commands:

```bash
composer validate --no-check-publish
composer dump-autoload
git diff --check
```

## Manual STOP GATE 5

Domain/parity gate (no FO UI). See Phase 5 implementation report. Do not start Phase 6 until accepted.
