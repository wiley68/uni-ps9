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

| Test                                     | What it checks                                                       |
| ---------------------------------------- | -------------------------------------------------------------------- |
| `tests/Infrastructure/*`                 | Phase 0 skeleton / PHP 8.1 contract                                  |
| `tests/Configuration/*`                  | Phase 1 config + Phase 2 admin contract                              |
| `tests/Security/TokenRepositoryTest.php` | token save/load/invalidate/malformed + credential-change             |
| `tests/Api/ControlPanelClientTest.php`   | login/refresh/getShop/401-once/logout/error classes (fake transport) |

Other useful commands:

```bash
composer validate --no-check-publish
composer dump-autoload
git diff --check
```

## Optional live CP smoke

Safe suite skips live CP by default.

```bash
UNIPAYMENT_LIVE_CP_TEST=1 \
UNIPAYMENT_LIVE_UNICID='...' \
UNIPAYMENT_LIVE_SECRET='...' \
UNIPAYMENT_LIVE_SHOP_NAME='https://presta9.avalonbg.com' \
php tests/Api/LiveControlPanelSmokeTest.php
```

Optional: `UNIPAYMENT_LIVE_BASE_URL` (default `https://uni.avalonbg.com/api/v1`).

Uses in-memory Configuration stubs (does not write the shop DB). Never prints secrets/tokens.

Sequence: login → GET /shop → refresh → GET /shop → logout → re-login → GET /shop → logout.

## Manual STOP GATE 2

### Configuration regression

1. Module Configure page loads.
2. Phase 1 settings remain intact.
3. Secret is not displayed.
4. Blank secret update preserves current secret.
5. Credential change invalidates current token state.

### Authentication

6. Login with valid credentials succeeds.
7. Local token state is created.
8. Access token is not exposed in UI/logs.
9. `GET /shop` succeeds.
10. Refresh succeeds.
11. `GET /shop` succeeds after refresh.
12. Logout succeeds/best-effort according to PS8 semantics.
13. Local token state is invalid after logout.
14. Login again succeeds.
15. `GET /shop` succeeds again.

### Negative paths

16. Invalid secret produces authentication failure.
17. Invalid UNICID produces authentication failure or local validation failure as appropriate.
18. Simulated/controlled 401 triggers only one recovery retry.
19. Terminal auth failure clears local token state.
20. Timeout is classified distinctly.
21. Connection failure is classified distinctly.
22. Malformed JSON is classified distinctly.

### Regression

23. No shop-cache table exists.
24. No inbound API endpoint was added.
25. No financing tables exist.
26. No custom order states exist.
27. Homepage unchanged.
28. Product unchanged.
29. Cart unchanged.
30. Checkout unchanged.
31. No UniPayment FO JS/CSS.
32. Check PHP/PrestaShop logs.

Do not start Phase 3 until this gate is accepted.
