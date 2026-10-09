# Installation and deployment

Guide for installing UniPayment on PrestaShop **9.x**.

Related: [`../README.md`](../README.md), [`ARCHITECTURE.md`](ARCHITECTURE.md), [`SECURITY-OPERATIONS.md`](SECURITY-OPERATIONS.md), [`RELEASE.md`](RELEASE.md)

---

## 1. Prerequisites

| Requirement               | Notes                                                  |
| ------------------------- | ------------------------------------------------------ |
| PrestaShop                | **9.0.0 – 9.99.99** (`ps_versions_compliancy`)         |
| PHP                       | **≥ 8.1 and &lt; 8.6** (`composer.json`)               |
| PHP curl / openssl        | Required; CP DNS pinning needs libcurl >=7.21.3 (IPv6 >=7.57.0), otherwise fails closed |
| Composer                  | Required for development checkout (`vendor/`)          |
| HTTPS                     | Module API controllers and SmartUCF use TLS            |
| Control Panel             | Shop registered with matching UNICID and shared secret |
| Writable module directory | Certificate sync may write under `{module}/keys/`      |

Themes: Hummingbird 2.0 (primary) and Classic 3.1.1.

**Merchant install assumes Back Office only** — no SSH, shell, PHP-FPM, Apache, or environment-variable configuration.

---

## 2. ZIP packaging (maintainer)

Run `composer package` (or `php bin/build-distribution.php`) from the module root. It creates `dist/CC_PrestaShop_9.x_UNI_v.<MODULE_VERSION>.zip` with production dependencies in an isolated staging tree and verifies the archive before success. See [RELEASE.md](RELEASE.md) for the manifest/parity checks and build prerequisites.

The package copies the current source `config/environment.php` unchanged. Its standard configuration is `control_panel_url = https://uni.avalonbg.com` (API = host + `/api/v1`). Prepare environment-specific CP hosts manually in this file before packaging; the builder performs no configuration substitution or rewriting. It also copies the local, Git-ignored `secrets/smartucf-key.php` unchanged into the ZIP as an explicit deployment-file exception. This file must exist and be readable before building; symlinks are rejected. Certificates/private keys under `keys/` remain excluded and are managed through the established certificate mechanism.

### Switching Control Panel

Change exactly `control_panel_url` in `config/environment.php` to the new public HTTPS CP root (no `/api/v1`, credentials, query, fragment or non-443 port), then build/deploy normally. No second endpoint setting, host allowlist, environment template, database reset or reinstall is required. The new CP still needs valid merchant registration/credentials under the existing contract.

Tokens, cache/LKG, SmartUCF credentials and certificate metadata from another or unproven origin are not reused. They refresh through the configured CP. Legacy durable order attempts/snapshots remain preserved and blocked pending explicit reconciliation; do not relabel them or create replacement orders. The first new reservation/save adds nullable provenance columns using the existing database connection; it needs ALTER permission. See [RECOVERY.md](RECOVERY.md).

### Git vs ZIP material

**Tracked (clone-ready structure):**

- `config/environment.php`, `config/index.php`, `config/services.yml`
- `secrets/.htaccess`, `secrets/index.php`
- `keys/.htaccess`, `keys/index.php`

**Ignored (fill before packaging / runtime):**

- `secrets/smartucf-key.php`
- `keys/*.pem`
- `keys/.incoming/`
- `keys/.ssl_state.json`
- `keys/.sync.lock`

There is **no** `UNIPAYMENT_MTLS_KEY_PASSPHRASE` (or other server env) requirement.

---

## 3. Install

1. Place module at `modules/unipayment` (upload prepared ZIP).
2. Run `composer install --no-dev --optimize-autoloader` only if `vendor/` is missing (dev checkouts).
3. BO → Modules → install **UniPayment**.
4. Configure UNICID, shared secret, enable module.
5. **Обнови данните от банката** (shop cache refresh).
6. Optionally enable advertising.

Install creates **8** module tables, custom order states (AWAITING / FAILED / REJECTED), and registers FO/BO hooks (including `displayFooter` for homepage advertising).

---

## 4. Smoke checklist

- [ ] Configure page opens
- [ ] Shop cache refresh succeeds
- [ ] Product calculator + product financing popup (Hummingbird + Classic)
- [ ] Cart calculator + cart financing popup (logged-in + guest)
- [ ] Checkout PaymentOption + Process 1 / Process 2
- [ ] Homepage advertising when enabled + fresh shop cache + CP `uni_container_status`
- [ ] Homepage still loads when advertising cache is missing (no advertising block)
- [ ] BO order financing block on financing orders only

---

## 5. Uninstall caveats

Uninstall runs `ModuleDataPurger` (AUD-006):

- Drops the 8 module tables
- Removes module Configuration keys (+ checkout-lock prefix leftovers)
- Invalidates tokens; best-effort CP logout; purges certificate runtime artifacts
- Deletes **unused** custom order states; **preserves** states still referenced by historical orders
- Does **not** delete native PrestaShop orders or remote CP orders

Confirm dialog warns that local UniPayment settings and data will be removed.

---

## 6. Development policy

- No upgrade scripts until first production release packaging cycle
- Current module version metadata: **2.0.3** (do not invent upgrade-\*.php without schema change)
- Do not commit secrets, Bearer tokens, private keys, or production `.env`
- Schema-changing development used uninstall/reinstall where appropriate (no production upgrade path invented yet)

---

## 7. Multishop

Configure UNICID/secret and refresh cache per shop context. Advertising and financing content follow the current shop’s UNICID cache row. SmartUCF journal reads are constrained by authenticated shop id.
