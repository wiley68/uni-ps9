# Changelog

Notable notes for the UniPayment PrestaShop **9** development line. This file does **not** claim a production release tag or package.

## Unreleased — 2.0.1 (pre-release / final audit remediation)

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

- Production tag/package (after final regression gate)
- v2.0.2 scheme aggregation/sorting parity
