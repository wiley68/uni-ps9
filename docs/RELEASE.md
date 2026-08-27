# Release procedure

Release and packaging checklist for UniPayment PrestaShop **9**.

---

## 1. Current release state

| Item           | Value                                      |
| -------------- | ------------------------------------------ |
| Module version | **2.0.2** (`unipayment.php`, `config.xml`) |
| Project status | **Scheme presentation / checkout parity**  |
| Release notes  | [`../CHANGELOG.md`](../CHANGELOG.md)       |

`2.0.1` remains the final-audit remediation line. `2.0.2` is the scheme presentation, Cart representative, and Checkout parity release (Woo / PS8 / PS9 coordinated behavior).

Do **not** create or push a Git tag automatically from agent workflows — tagging is an explicit operator step.

---

## 2. Version policy

- Module version is **`2.0.2`** for this release
- Version metadata must stay consistent in `unipayment.php` and `config.xml`
- **No** historical upgrade scripts for development-only iterations
- After first production package, future schema changes use `upgrade/upgrade-x.y.z.php`

---

## 3. Production release verification

### Quality

- [x] Scheme parity version is **2.0.2**
- [x] Version in `unipayment.php` and `config.xml`
- [ ] `composer validate --no-check-publish`
- [ ] `composer test` green on PHP 8.1–8.5
- [ ] `git diff --check` clean
- [ ] Manual browser smoke (product/cart/checkout scheme ordering, Cart representative, Checkout priority/transitions)
- [ ] Confirm `secrets/smartucf-key.php` and PEMs are present in the **package** only (not committed)

### Packaging (future / operator)

- [ ] `composer install --no-dev --optimize-autoloader` in staging tree
- [ ] Fill `config/environment.php` + `secrets/smartucf-key.php` (+ PEMs) for the target environment
- [ ] Artifact excludes Git-ignored secrets/PEMs from the **source** tree unless intentionally packaged
- [ ] Search tree for accidental EGN/token/key leakage

### Deferred product work

- [ ] Activate bank-rejection → PS order-state sync only with proven CP status codes (AUD-009)

---

## 4. Rollback notes

Uninstall removes module-owned data only. Historical PS orders remain. Reinstall + cache refresh restores FO/BO without manual DB cleanup when following AUD-006 policy.

---

## 5. Tag creation (operator only)

1. Confirm this commit is the intended release HEAD
2. Confirm safe suite + manual smoke
3. Create annotated local tag only when explicitly approved: `git tag -a v2.0.2 -m "UniPayment 2.0.2"`
4. Push tag / attach `unipayment-2.0.2.zip` only when distribution is approved
