# Release procedure

Release and packaging checklist for UniPayment PrestaShop **9**.

---

## 1. Current release state

| Item           | Value                                      |
| -------------- | ------------------------------------------ |
| Module version | **2.0.3** (`unipayment.php`, `config.xml`) |
| Project status | **Failure UX + Satrudnik mail**            |
| Release notes  | [`../CHANGELOG.md`](../CHANGELOG.md)       |

`2.0.2` remains the scheme presentation / Cart / Checkout parity line. `2.0.3` adds definitive CP-failure classification/Thank You/emails and Satrudnik operational notification mail.

Do **not** create or push a Git tag automatically from agent workflows — tagging is an explicit operator step.

---

## 2. Version policy

- Module version is **`2.0.3`** for this release
- Version metadata must stay consistent in `unipayment.php` and `config.xml`
- **No** historical upgrade scripts for development-only iterations
- After first production package, future schema changes use `upgrade/upgrade-x.y.z.php`

---

## 3. Production release verification

### Quality

- [x] Module version is **2.0.3**
- [x] Version in `unipayment.php` and `config.xml`
- [ ] `composer validate --no-check-publish`
- [ ] `composer test` green on PHP 8.1–8.5
- [ ] `git diff --check` clean
- [ ] Manual browser smoke (product/cart/checkout; CP failure Thank You; SmartUCF failure; Satrudnik mail when configured)
- [ ] Confirm the ZIP contains the intended `secrets/smartucf-key.php` deployment file and no other credentials, PEMs, logs or runtime data

### Reproducible distribution command

From the module root, run:

```bash
composer package
# Equivalent: php bin/build-distribution.php
```

Requires CLI PHP 8.1–8.5 with zip/SimpleXML, Composer and Git, plus the local deployment file `secrets/smartucf-key.php`. The command uses current contents of Git-indexed runtime files, including uncommitted changes to those files, and explicitly includes this Git-ignored deployment file. Add newly created non-secret runtime files to the Git index before building; other untracked files are excluded. Never add `secrets/smartucf-key.php` to Git. The build never installs dependencies into the working module.

Output naming is exactly `dist/CC_PrestaShop_9.x_UNI_v.<MODULE_VERSION>.zip`. The version comes from the single literal `$this->version` assignment in `unipayment.php`; an absent, dynamic or ambiguous declaration fails the build. The ZIP contains the top-level `unipayment/` directory, and its filename version is checked against both packaged PHP and XML metadata.

The builder creates a private temporary staging tree under ignored `dist/`, copies an allowlist of indexed runtime paths, and runs:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-plugins --no-scripts
```

The source lockfile is used for that install. The shipped `composer.json` omits development autoload/dependencies and scripts; the build-only lockfile is omitted from the ZIP to avoid shipping a stale hash after metadata normalization. Production `vendor/` is generated fresh; source `vendor/` is never copied. No runtime dependency has been added.

`config/environment.php` is copied byte-for-byte from the current source. The standard configuration points to `https://uni.avalonbg.com`. Prepare any environment-specific endpoint manually in that file before running the command; packaging performs no environment substitution, template generation or configuration rewriting. The local `secrets/smartucf-key.php` is also copied byte-for-byte as the sole deployment-secret exception. A missing, unreadable or symlinked source file fails the build. The package otherwise contains only protection/index files under `keys/`, `secrets/` and `var/`. Other credentials, private keys, certificates, `.env` files, logs, caches, uploaded data, tests, docs, IDE files, `.git`, previous artifacts and `ps92-installed/` are excluded. Certificates remain managed separately through the established mechanism.

Before reporting success, the builder reopens the ZIP and checks required runtime files, safe paths, production autoload, PHP/XML/filename versions, a complete SHA-256 inventory and byte parity with the current runtime source. `package-manifest.json` records version, source Git SHA, dirty-tree status and normalized timestamp. It is a parity record, not a cryptographic signature.

Standalone verification against the current source:

```bash
php bin/verify-distribution.php dist/CC_PrestaShop_9.x_UNI_v.2.0.3.zip
php tests/Infrastructure/DistributionPackageTest.php
```

The command prints ZIP path, file count, byte size and source Git SHA; any build/verification failure exits non-zero. Entries are sorted with normalized permissions and timestamps. By default the timestamp is the source HEAD commit time; `SOURCE_DATE_EPOCH` can override it. Identical source, toolchain and epoch produce identical ZIP bytes. The SHA identifies HEAD; `source_dirty=true` records a build containing uncommitted changes. For a release, build again after the intended source commit.

Do not commit generated ZIPs, `dist/` contents or `secrets/smartucf-key.php`. The archive includes the deployment passphrase and must be handled as a deployment artifact. The builder writes the existing Apache deny-all protection into `dist/.htaccess`; HTTP access requires Apache to honor it. This command creates a local artifact only; tagging and publishing remain explicit operator actions.

### Deferred product work

- [ ] Activate bank-rejection → PS order-state sync only with proven CP status codes (AUD-009)

---

## 4. Rollback notes

Uninstall removes module-owned data only. Historical PS orders remain. Reinstall + cache refresh restores FO/BO without manual DB cleanup when following AUD-006 policy.

---

## 5. Tag creation (operator only)

1. Confirm this commit is the intended release HEAD
2. Confirm safe suite + manual smoke
3. Create annotated local tag only when explicitly approved: `git tag -a v2.0.3 -m "UniPayment 2.0.3"`
4. Push tag / attach `CC_PrestaShop_9.x_UNI_v.2.0.3.zip` only when distribution is approved
