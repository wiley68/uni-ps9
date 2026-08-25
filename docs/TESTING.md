# Testing

UniPayment CLI tests run against the module checkout in the PrestaShop test shop filesystem. The repository lives inside a **live dev installation**, so the default suite must remain non-destructive.

## Development environment

| Item                    | Current value      |
| ----------------------- | ------------------ |
| Shop                    | PrestaShop **9.1** |
| CLI / FPM PHP           | **8.4**            |
| Production PHP baseline | **8.1**            |

## Automated checks

Safe default:

```bash
composer test
```

Current coverage includes:

| Test                                                               | What it checks                                                                 |
| ------------------------------------------------------------------ | ------------------------------------------------------------------------------ |
| `tests/Infrastructure/*`                                           | Phase 0 skeleton / PHP 8.1 contract                                            |
| `tests/Configuration/Configuration*`                               | Phase 1 config repository/validator                                            |
| `tests/Configuration/AdminConfigurationContractTest.php`           | Phase 3 BO: refresh enabled, journal deferred, credential clears token+cache   |
| `tests/Configuration/ShopConfigurationCacheTest.php`               | pull/TTL semantics, invalid snapshot protection, unicid isolation, credentials |
| `tests/Configuration/ShopConfigurationSnapshotValidatorTest.php`   | semantic snapshot validation                                                   |
| `tests/Configuration/ShopConfigurationFlagsTest.php`               | Process 1/2 and helper flags                                                   |
| `tests/Configuration/ShopConfigurationCacheSchemaContractTest.php` | SQL/schema contract for `unipayment_shop_cache`                                |
| `tests/Security/TokenRepositoryTest.php`                           | token lifecycle + credential-change clears token and cache                     |
| `tests/Api/ControlPanelClientTest.php`                             | login/refresh/getShop/401-once/logout (fake transport)                         |

Other useful commands:

```bash
composer validate --no-check-publish
composer dump-autoload
git diff --check
```

Destructive/runtime DB install-uninstall schema tests are **not** in the default suite. Real table creation is verified via BO uninstall/reinstall (STOP GATE 3).

## Optional live CP smoke

Safe suite skips live CP by default (`SKIP` when env not set).

### Auth (Phase 2)

```bash
UNIPAYMENT_LIVE_CP_TEST=1 \
UNIPAYMENT_LIVE_UNICID='...' \
UNIPAYMENT_LIVE_SECRET='...' \
UNIPAYMENT_LIVE_SHOP_NAME='https://presta9.avalonbg.com' \
php tests/Api/LiveControlPanelSmokeTest.php
```

### Shop cache pull (Phase 3)

```bash
UNIPAYMENT_LIVE_CP_TEST=1 \
UNIPAYMENT_LIVE_UNICID='...' \
UNIPAYMENT_LIVE_SECRET='...' \
UNIPAYMENT_LIVE_SHOP_NAME='https://presta9.avalonbg.com' \
php tests/Configuration/LiveShopCacheSmokeTest.php
```

Uses in-memory Configuration + memory cache (does **not** write `unipayment_shop_cache`). Never prints secrets/tokens.

Sequence: clear → `get(true)` → cache hit on `get(false)` → `get(true)` replaces → logout.

Real DB persistence: BO „Обнови данните от банката“.

Optional: `UNIPAYMENT_LIVE_BASE_URL` (default `https://uni.avalonbg.com/api/v1`).

## Manual STOP GATE 3

See the Phase 3 implementation report checklist (install table, BO refresh, credential clear, multishop/unicid isolation, FO unchanged). Do not start Phase 4 until that gate is accepted.
