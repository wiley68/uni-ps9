# Installation and deployment

Guide for installing UniPayment on PrestaShop **9.x**.

Related: [`../README.md`](../README.md), [`ARCHITECTURE.md`](ARCHITECTURE.md), [`SECURITY-OPERATIONS.md`](SECURITY-OPERATIONS.md), [`RELEASE.md`](RELEASE.md)

---

## 1. Prerequisites

| Requirement               | Notes                                                  |
| ------------------------- | ------------------------------------------------------ |
| PrestaShop                | **9.0.0 – 9.99.99** (`ps_versions_compliancy`)         |
| PHP                       | **≥ 8.1 and &lt; 8.6** (`composer.json`)               |
| PHP curl / openssl        | Required for CP HTTP and SmartUCF certificates         |
| Composer                  | Required for development checkout (`vendor/`)          |
| HTTPS                     | Module API controllers and SmartUCF use TLS            |
| Control Panel             | Shop registered with matching UNICID and shared secret |
| Writable module directory | Certificate sync may write under `{module}/keys/`      |

Themes: Hummingbird 2.0 (primary) and Classic 3.1.1.

**Merchant install assumes Back Office only** — no SSH, shell, PHP-FPM, Apache, or environment-variable configuration.

---

## 2. ZIP packaging (maintainer)

Prepare development / test / production packages by editing **only**:

| File                               | Purpose                                               |
| ---------------------------------- | ----------------------------------------------------- |
| `config/environment.php`           | `control_panel_url` (CP host; API = host + `/api/v1`) |
| `secrets/smartucf-key.php`         | SmartUCF mTLS private-key passphrase                  |
| `keys/*.pem` (when shipping certs) | SmartUCF client certificate + private key             |

Then ZIP the module and send it for BO install.

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
- Current module version metadata: **2.0.1** (do not invent upgrade-\*.php for interim schema)
- Do not commit secrets, Bearer tokens, private keys, or production `.env`
- Schema-changing development used uninstall/reinstall where appropriate (no production upgrade path invented yet)

---

## 7. Multishop

Configure UNICID/secret and refresh cache per shop context. Advertising and financing content follow the current shop’s UNICID cache row. SmartUCF journal reads are constrained by authenticated shop id.
