# Testing

UniPayment CLI tests run against the module checkout in the PrestaShop test shop filesystem. The repository lives inside a **live dev installation**, so the default suite must remain non-destructive.

## Development environment

| Item                    | Current value      |
| ----------------------- | ------------------ |
| Shop                    | PrestaShop **9.1** |
| CLI / FPM PHP           | **8.4**            |
| Production PHP baseline | **8.1**            |

PHP 8.4 is acceptable for local development. Production code must still parse and run on PHP 8.1.

## Phase 0 automated checks

Safe default:

```bash
composer test
```

Equivalent:

```bash
php tests/run.php
php tests/run.php safe
```

Phase 0 coverage:

| Test                                                      | What it checks                                                                                                                   |
| --------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| `tests/Infrastructure/ModuleSkeletonContractTest.php`     | Module identity, PS9-only compliancy, Composer namespace, no overrides / FO controllers / assets / tables / order states / hooks |
| `tests/Infrastructure/Php81CompatibilityContractTest.php` | Composer PHP constraint, `php -l` on production files, static rejection of a small set of PHP 8.2+ tokens                        |

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

When a PHP 8.1 runtime becomes available, add an execution lane that installs dependencies and runs `composer test` under PHP 8.1. Until then, do not treat the static test as proof of 8.1 runtime behavior.

## Manual STOP GATE 0

After Phase 0 code work, verify in the Back Office / storefront:

1. Module Manager discovers UniPayment.
2. Install succeeds.
3. Configure opens without fatal error.
4. Placeholder configuration page contains no real settings.
5. Disable succeeds.
6. Enable succeeds.
7. Uninstall succeeds.
8. Reinstall succeeds.
9. Clear PrestaShop cache and confirm no Symfony container error.
10. Visit homepage.
11. Visit product page.
12. Visit cart.
13. Visit checkout.
14. Visit Back Office Orders.
15. Confirm no UniPayment visual/frontend behavior appears.
16. Confirm no UniPayment JS/CSS is loaded.
17. Check PrestaShop/PHP logs.
18. Confirm no `unipayment_*` tables were created.
19. Confirm no UniPayment custom order states were created.
20. Confirm module compatibility is PS9-only and does not imply PS10.

Do not start Phase 1 until this gate is accepted.

## Later suites

Runtime and destructive suites from uni-ps8 are **not** present in Phase 0. Do not invent them ahead of the corresponding implementation phase.
