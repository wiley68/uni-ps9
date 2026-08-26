# Release procedure

Release and packaging checklist for UniPayment PrestaShop **9**.

This document does **not** authorize creating a production tag or package yet.

---

## 1. Current state

| Item           | Value                                                                                |
| -------------- | ------------------------------------------------------------------------------------ |
| Module version | **2.0.1** (`unipayment.php`)                                                         |
| Project status | Final audit remediations accepted — **final regression gate** required before tag    |
| Suite          | Full safe suite on PHP **8.1–8.5** (`composer test`; see [`TESTING.md`](TESTING.md)) |

Do **not** tag or package until the final regression gate passes.

---

## 2. Version policy

- Keep version metadata consistent in `unipayment.php` / `config.xml`
- **No** historical upgrade scripts for development-only iterations
- After first production package, future schema changes use `upgrade/upgrade-x.y.z.php`

---

## 3. Pre-release verification (operator)

### Quality

- [ ] `composer validate --no-check-publish`
- [ ] `composer test` green on PHP 8.1–8.5
- [ ] `git diff --check` clean
- [ ] Manual final regression (product/cart/checkout, Process 1/2, guest cart, advertising cache-only, ZIP packaging files)
- [ ] Confirm `secrets/smartucf-key.php` and PEMs are present in the **package** only (not committed)

### Packaging (future)

- [ ] `composer install --no-dev --optimize-autoloader` in staging tree
- [ ] Fill `config/environment.php` + `secrets/smartucf-key.php` (+ PEMs) for the target environment
- [ ] Artifact excludes Git-ignored secrets/PEMs from the **source** tree unless intentionally packaged
- [ ] Search tree for accidental EGN/token/key leakage

### Deferred product work

- [ ] Coordinated **v2.0.2** scheme aggregation across uni-woo / uni-ps8 / uni-ps9
- [ ] Activate bank-rejection → PS order-state sync only with proven CP status codes (AUD-009)

---

## 4. Rollback notes

Uninstall removes module-owned data only. Historical PS orders remain. Reinstall + cache refresh restores FO/BO without manual DB cleanup when following AUD-006 policy.
