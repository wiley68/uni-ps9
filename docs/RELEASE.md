# Release procedure

Release and packaging checklist for UniPayment PrestaShop **9**. Phase 13 prepares readiness; it does **not** create a tag or production package.

---

## 1. Current state

| Item           | Value                                                       |
| -------------- | ----------------------------------------------------------- |
| Module version | **2.0.1** (`unipayment.php`)                                |
| Project status | **Phase 13 complete — STOP GATE 13 / awaiting final audit** |
| Suite          | See `composer test` in [`TESTING.md`](TESTING.md)           |

Do **not** tag or package until the final audit/remediation cycle passes.

---

## 2. Version policy

- Keep version metadata consistent in `unipayment.php` / `config.xml`
- **No** historical upgrade scripts for development-only iterations
- After first production package, future schema changes use `upgrade/upgrade-x.y.z.php`

---

## 3. Pre-release verification (operator)

### Quality

- [ ] `composer validate --no-check-publish`
- [ ] `composer test` green
- [ ] `git diff --check` clean
- [ ] Manual STOP GATE 13 (advertising + uninstall + transactional regression)
- [ ] Final audit / remediation cycle (separate phase)

### Packaging (future)

- [ ] `composer install --no-dev --optimize-autoloader` in staging tree
- [ ] Artifact excludes secrets, `keys/`, tests, IDE files, `.env`
- [ ] Search tree for accidental EGN/token/key leakage

### Deferred product work

- [ ] Coordinated **v2.0.2** scheme aggregation across uni-woo / uni-ps8 / uni-ps9
- [ ] Activate bank-rejection → PS order-state sync only with proven CP status codes (AUD-009)

---

## 4. Rollback notes

Uninstall removes module-owned data only. Historical PS orders remain. Reinstall + cache refresh restores FO/BO without manual DB cleanup when following AUD-006 policy.
