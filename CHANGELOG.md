# Changelog

Notable notes for the UniPayment PrestaShop **9** development line.

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
