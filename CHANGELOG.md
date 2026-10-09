# Changelog

Notable notes for the UniPayment PrestaShop **9** development line.

## Unreleased — 2026-10-09

- Installation fix: CP-origin column probes use uncached `Db::executeS()` and exact `SHOW COLUMNS ... LIKE 'cp_origin'`, avoiding the `LIMIT 1` automatically appended by `getRow()`. Both attempt/snapshot migrations remain additive, nullable and idempotent; tests cover native schema installation and preserved legacy rows.

- Control Panel destination remains solely `config/environment.php`: HTTPS/public DNS/root/443 validation, bounded A/AAAA/CNAME checks, cURL IP pinning, no proxy or redirects, unchanged TLS/timeouts; independent client API-base and environment-file overrides removed.
- Encrypted CP tokens and CP-derived SmartUCF credentials, shop cache/LKG and local certificate metadata now carry normalized CP-origin provenance. Foreign/legacy ephemeral state is unusable and refreshed lazily; certificate fail-open requires the same origin and transient failure.
- Durable attempt/snapshot rows gain nullable `cp_origin` through additive lazy migration. Foreign/legacy create, successful replay and pending status PATCH are blocked with history and exactly-once identity preserved; no automatic cross-CP migration.
- Packaging includes non-ignored new runtime classes without staging; unchanged environment/passphrase bytes and runtime-secret exclusions remain verified. No version bump, external runtime dependency, CP/SmartUCF payload change or inbound HMAC change.

## Unreleased — 2026-10-08

- Inbound header extraction skips non-string SERVER keys/values before string operations; all three signed API endpoints retain controlled unsigned POST/GET responses.
- Unexpected inbound failures log exception class, source filename and line without exception messages, traces or request material.
- Reproducible deployment ZIP build with fresh production autoload, exact module-version naming and SHA-256 manifest/source parity; includes the local Git-ignored `secrets/smartucf-key.php` unchanged, excludes other credentials/runtime data and protects `dist/` with Apache deny-all rules.
- No CP/SmartUCF contract, database schema or order lifecycle change.

## 2.0.3 — 2026-09-18

- Definitive CP create failure: persist `bank_send_failed_cp` / `Неуспешно изпратен Банка - КП` for Process 1 and Process 2; finalize standard emails once; Thank You redirect (not ambiguous popup-only UX).
- Explicit HTTP endpoint rejection (`403` / `404` / `405` / `410`) classified as definitive CP create failure (covers Cloudflare/edge rejection of wrong API path such as `/api/v11`); true transport ambiguity (`timeout`, `5xx`, connection errors) remains `CP_OUTCOME_UNKNOWN`.
- Satrudnik operational notification mail on `bank_send_failed_cp` and `bank_send_failed_smartucf` via shared leasing mail once-guard (`leasing_email_sent`); recipient from cached `satrudnik_email` only (no fallback).
- No database schema change and no upgrade script.

## 2.0.2 — 2026-08-27

- Canonical financing scheme ordering for equal month counts: standard → non-zero promo → 0%.
- Product, Cart, and Checkout presentation ordering parity.
- Correct Cart promotional standard-button representative; `zero_promo` cannot represent the standard Cart button while remaining available in popup/unified membership and the dedicated 0% flow.
- Cart automatic-first-installment preview parity (`button monthly == popup monthly`).
- Cross-line conflicting `uni_parva` safety: ambiguous common schemes are not line-order-dependent calculable/submittable offers.
- Deterministic non-conflicting cross-line metadata normalization (lowest `filterId` when `uni_parva` agrees).
- Checkout automatic priority: valid explicit → longest 0% → longest non-zero promo → CP preferred standard → deterministic fallback.
- PS9 `CheckoutSchemeIdentity` and `preference_unresolved` preserved.
- Checkout first-installment transitions: locked → editable = 0; editable → locked = automatic amount; locked A → locked B = B amount.
- UniCredit red Checkout scheme selector styling.
- No database schema change and no upgrade script.

## 2.0.1 — final audit remediation

### Final audit remediations

- AUD-019 — post-order durability / lock-loser stays order-aware
- AUD-020 — SmartUCF diagnostic journal shop-scoped (`id_shop` + order id)
- Cart guest identity gate consistency (`PopupSubmissionBindingFactory::identityFromContext`)
- Cart guest twin-order prevention (`CartShippingStateSynchronizer` + authoritative order resolver + empty-lines CP guard)
- AUD-021 — mTLS passphrase from `secrets/smartucf-key.php`; CP host from `config/environment.php` (ZIP-only; no env)
- AUD-022 — homepage advertising uses `getCachedOnly()` (no FO CP refresh)
- AUD-023 — documentation aligned to current accepted behavior

### Phase 13 capabilities (already in line)

- Homepage advertising float from cached CP promotional fields (`uni_container_*`, `uni_picturem`, `uni_backurl`)
- `displayFooter` + module-scoped CSS/JS (Hummingbird + Classic)
- Uninstall via `ModuleDataPurger` (AUD-006): 8 tables, tokens, certificates, safe order-state purge

## Prior development milestones

- Phase 12 — financing emails, native `order_conf`, Thank You UX, BO diagnostics; mail completion marker invariant
- Phase 11 — SmartUCF Process 1 / Process 2 handoff
- Phase 10 — durable checkout exactly-once; product/cart popups share durable order completion
- Phases 0–9 — configuration, CP cache, inbound API, calculator, product/cart/checkout FO

## Deferred

- Production tag/package (operator-driven; not created by this release commit)
- Bank-rejection → native PS order-state sync (AUD-009; dormant until proven CP status codes)
