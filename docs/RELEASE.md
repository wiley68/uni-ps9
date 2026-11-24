# Release procedure

Release and packaging checklist for UniPayment PrestaShop **9**.

---

## 1. Current release state

| Item           | Value                                      |
| -------------- | ------------------------------------------ |
| Module version | **2.0.5** (`unipayment.php`, `config.xml`, `config_bg.xml`) |
| Project status | **Local installer preparation (UNI-PS9-PROCESS2-FIELDS-001)** |
| Release notes  | [`../CHANGELOG.md`](../CHANGELOG.md)       |

`2.0.2` remains the scheme presentation / Cart / Checkout parity line. `2.0.3` adds definitive CP-failure classification/Thank You/emails and Satrudnik operational notification mail.

`2.0.4` (2026-11-19) removes the merchant advertising switch. CP `uni_container_status` controls visibility, with the existing homepage/module/UNICID/`uni_status` checks. Legacy local advertising values are ignored and preserved on settings saves; install defaults and uninstall cleanup remain unchanged. No migration or payment/financing change. CP push, TTL 86400 seconds, transient LKG up to 21600 seconds after expiry and manual refresh are unchanged. The shared presentation resolver may synchronously pull CP on a cache miss/stale snapshot; fresh coherent snapshots stay local.

`2.0.5` removes EGN and secondary phone from Process 2 Product, Cart and Checkout forms, submissions and validators. It retains the five supported customer fields and mandatory consents. New snapshots store SQL NULL in `sensitive_payload`. Historical ciphertext stays under existing retention and is omitted from current mail and Back Office output. Structured diagnostic records mask the four exact legacy keys. Process 1 and the CP API contract are unchanged. No migration is required.

The PS9-01 through PS9-09 browser acceptance (9/9 PASS) applies to 2.0.4. Manual PS9 installation and browser acceptance for 2.0.5 are pending. The 2.0.5 installer is for local, access-restricted operator use.

Do **not** create or push a Git tag automatically from agent workflows — tagging is an explicit operator step.

---

## 2. Version policy

- Module version is **`2.0.5`** for this release
- Version metadata must stay consistent in `unipayment.php`, `config.xml`, `config_bg.xml`, new financing snapshots and diagnostic exports; never rewrite historical snapshots or frozen payloads
- **No** historical upgrade scripts for development-only iterations
- After first production package, future schema changes use `upgrade/upgrade-x.y.z.php`

---

## 3. Production release verification

### Quality

- Synchronize the version in PHP, both XML metadata files, new financing snapshots and diagnostic exports.
- Verify the local ZIP against the working tree using the official verifier. The manifest records the actual source HEAD and dirty state.
- [ ] Manual browser acceptance on PS9 Classic and Hummingbird, including Product, Cart, Checkout, Back Office and Process 1 regression.

### Reproducible distribution command

From the module root, run:

```bash
composer package
# Equivalent: php bin/build-distribution.php
```

Requires CLI PHP 8.1–8.5 with zip/SimpleXML, Composer and Git, plus the local deployment file `secrets/smartucf-key.php`. The command uses current contents of tracked runtime files and non-ignored untracked runtime files selected by the existing runtime-path policy, and explicitly includes this Git-ignored deployment file. New runtime classes can therefore be reviewed and packaged without staging. Tests, documentation, temporary files and other paths outside that policy remain excluded. Never add `secrets/smartucf-key.php` to Git. The build never installs dependencies into the working module.

Output naming is exactly `dist/ps9_uni_<MODULE_VERSION>.zip`, preserving all three version components (for example, `ps9_uni_2.0.5.zip`). The version comes from the single literal `$this->version` assignment in `unipayment.php`; an absent, dynamic or ambiguous declaration fails the build. The ZIP contains the top-level `unipayment/` directory, and its filename version is checked against both packaged PHP and XML metadata.

The builder creates a private temporary staging tree under ignored `dist/`, copies runtime paths selected by the build policy, and runs:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-plugins --no-scripts
```

The source lockfile is used for that install. The shipped `composer.json` omits development autoload/dependencies and scripts; the build-only lockfile is omitted from the ZIP to avoid shipping a stale hash after metadata normalization. Production `vendor/` is generated fresh; source `vendor/` is never copied. No runtime dependency has been added.

`config/environment.php` is copied byte-for-byte from the current source. The standard configuration points to `https://uni.avalonbg.com`. Prepare any environment-specific endpoint manually in that file before running the command; packaging performs no environment substitution, template generation or configuration rewriting. The local `secrets/smartucf-key.php` is also copied byte-for-byte as the sole deployment-secret exception. A missing, unreadable or symlinked source file fails the build. The package otherwise contains only protection/index files under `keys/`, `secrets/` and `var/`. Other credentials, private keys, certificates, `.env` files, logs, caches, uploaded data, tests, docs, IDE files, `.git`, previous artifacts and `ps92-installed/` are excluded. Certificates remain managed separately through the established mechanism.

Before reporting success, the builder reopens the ZIP and checks required runtime files, safe paths, production autoload, PHP/XML/filename versions, a complete SHA-256 inventory and byte parity with the current runtime source. `package-manifest.json` records version, source Git SHA, dirty-tree status and normalized timestamp. It is a parity record, not a cryptographic signature.

Standalone verification against the current source:

```bash
php bin/verify-distribution.php dist/ps9_uni_2.0.5.zip
php tests/Infrastructure/DistributionPackageTest.php
```

The command prints ZIP path, file count, byte size and source Git SHA; any build/verification failure exits non-zero. Entries are sorted with normalized permissions and timestamps. By default the timestamp is the source HEAD commit time; `SOURCE_DATE_EPOCH` can override it. Identical source, toolchain and epoch produce identical ZIP bytes. The SHA identifies HEAD; `source_dirty=true` records a build containing uncommitted changes. For a release, build again after the intended source commit.

Do not commit generated ZIPs, `dist/` contents or `secrets/smartucf-key.php`. The archive includes the deployment passphrase and must be handled as a deployment artifact. The builder writes the existing Apache deny-all protection into `dist/.htaccess`; HTTP access requires Apache to honor it. This command creates a local artifact only; tagging and publishing remain explicit operator actions.

For UNI-PS9-ADS-003, first build and verify a provisional archive before Git publication. Run packaging with a restrictive umask (077), then regenerate the final archive from the exact published release commit. Verify the final manifest SHA equals that commit and `source_dirty=false`, the ZIP has mode 0600 and is Git-ignored. Keep `config/environment.php` unchanged and unstaged; the Git-ignored deployment secret must also remain unstaged. Record the final path, byte size, SHA-256, archive file count and verified manifest entry count without displaying deployment-file contents. Building and tests must not invoke live shop lifecycle hooks, modify shop DB/configuration or clear caches.

### Deferred product work

- [ ] Activate bank-rejection → PS order-state sync only with proven CP status codes (AUD-009)

---

## 4. Rollback notes

Uninstall removes module-owned data only. Historical PS orders remain. Reinstall + cache refresh restores FO/BO without manual DB cleanup when following AUD-006 policy.

---

## 5. Tag creation (operator only)

1. Confirm this commit is the intended release HEAD
2. Confirm safe suite + manual smoke
3. Create an annotated local tag only with explicit operator approval.
4. Push the tag only with explicit operator approval and verify that it resolves to the exact release commit. RELEASE-PREP-01 authorizes local installer preparation only.
