# Testing

UniPayment CLI tests run against the module checkout in the PrestaShop test shop filesystem. The repository lives inside a **live dev installation**, so the default suite must remain non-destructive.

## Development environment

| Item                    | Current value      |
| ----------------------- | ------------------ |
| Shop                    | PrestaShop **9.1** |
| CLI / FPM PHP           | **8.4**            |
| Production PHP baseline | **8.1**            |

PHP 8.4 is acceptable for local development. Production code must still parse and run on PHP 8.1.

## Automated checks

Safe default:

```bash
composer test
```

Equivalent:

```bash
php tests/run.php
php tests/run.php safe
```

Current coverage:

| Test                                                      | What it checks                                                                                                                   |
| --------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| `tests/Infrastructure/ModuleSkeletonContractTest.php`     | Module identity, PS9-only compliancy, Composer namespace, no overrides / FO controllers / assets / tables / order states / hooks |
| `tests/Infrastructure/Php81CompatibilityContractTest.php` | Composer PHP constraint, `php -l` on production files, static rejection of a small set of PHP 8.2+ tokens                        |
| `tests/Configuration/ConfigurationRepositoryTest.php`     | Defaults, save/load, secret encryption/preservation, normalization, uninstall cleanup                                            |
| `tests/Configuration/ConfigurationValidatorTest.php`      | UNICID/secret/button action/spacing validation parity with PS8                                                                   |
| `tests/Configuration/AdminConfigurationContractTest.php`  | Real BO template, no Phase 0 placeholder, no secret leak, deferred CP actions, no Phase 2+ deps                                  |

Other useful commands:

```bash
composer validate
composer dump-autoload
git diff --check
```

## PHP 8.1 runtime requirement

The PHP 8.1 compatibility test is a **contract/static guard**, not a substitute for an actual PHP 8.1 execution lane.

- Syntax is checked with the **current interpreter** (`php -l`).
- A regex scan rejects known PHP 8.2+ constructs that can be detected safely.
- It does **not** execute the module on PHP 8.1.

## Manual STOP GATE 1

After Phase 1 code work, verify in the Back Office / storefront:

### Fresh configuration

1. Open Module Manager → UniPayment → Configure.
2. Real configuration page loads.
3. No fatal/error.
4. No Phase 0 placeholder remains.

### Validation

5. Save with missing UNICID → proper validation error.
6. Save invalid UNICID → proper validation error.
7. Save without secret on initial configuration → proper error.
8. Save valid UNICID + valid secret → success.
9. Reload configuration page.
10. Secret is NOT displayed back.
11. Existing-secret indication is correct.
12. Save again with blank secret → existing secret is preserved.
13. Invalid product button action is rejected.
14. Valid product button action is saved.
15. Invalid top spacing is rejected.
16. Boundary spacing values behave according to PS8 rules.

### Flags

17. Enable/disable local UniPayment configuration flag.
18. Advertising flag persists.
19. Debug flag persists.

### Lifecycle

20. Disable/enable module in Module Manager.
21. Configuration remains correct.
22. Uninstall.
23. Confirm module local configuration cleanup is correct.
24. Reinstall.
25. Confirm clean defaults.

### Regression

26. Homepage unchanged.
27. Product page unchanged.
28. Cart unchanged.
29. Checkout unchanged.
30. No UniPayment frontend JS/CSS loaded.
31. No `unipayment_*` financing tables created.
32. No custom UniPayment order states created.
33. Check PHP/PrestaShop logs.

Do not start Phase 2 until this gate is accepted.

## Later suites

Runtime and destructive suites from uni-ps8 are **not** present yet. Do not invent them ahead of the corresponding implementation phase.
