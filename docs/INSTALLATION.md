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

Default CP host: `config/environment.php` → `control_panel_url` (API base = host + `/api/v1`).

Themes: Hummingbird 2.0 (primary) and Classic 3.1.1.

---

## 2. Install

1. Place module at `modules/unipayment` (or upload ZIP without secrets/`keys/`/`.env`).
2. Run `composer install --no-dev --optimize-autoloader` if `vendor/` is missing.
3. BO → Modules → install **UniPayment**.
4. Configure UNICID, shared secret, enable module.
5. **Обнови данните от банката** (shop cache refresh).
6. Optionally enable advertising.

Install creates **8** module tables, custom order states (AWAITING / FAILED / REJECTED), and registers FO/BO hooks (including `displayFooter` for homepage advertising).

---

## 3. Smoke checklist

- [ ] Configure page opens
- [ ] Shop cache refresh succeeds
- [ ] Product calculator (Hummingbird + Classic)
- [ ] Cart calculator
- [ ] Checkout PaymentOption + Process 1 / Process 2
- [ ] Homepage advertising when enabled + CP `uni_container_status`
- [ ] BO order financing block on financing orders only

---

## 4. Uninstall caveats

Uninstall runs `ModuleDataPurger` (AUD-006):

- Drops the 8 module tables
- Removes module Configuration keys (+ checkout-lock prefix leftovers)
- Invalidates tokens; best-effort CP logout; purges certificate runtime artifacts
- Deletes **unused** custom order states; **preserves** states still referenced by historical orders
- Does **not** delete native PrestaShop orders or remote CP orders

Confirm dialog warns that local UniPayment settings and data will be removed.

---

## 5. Development policy

- No upgrade scripts until first production release packaging cycle
- Current module version metadata: **2.0.1** (do not invent upgrade-\*.php for interim schema)
- Do not commit secrets, Bearer tokens, private keys, or production `.env`

---

## 6. Multishop

Configure UNICID/secret and refresh cache per shop context. Advertising and financing content follow the current shop’s UNICID cache row.
