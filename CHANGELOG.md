# Changelog

Notable notes for the UniPayment PrestaShop **9** development line. This file does **not** claim a production release tag/package from Phase 13.

## Unreleased — Phase 13 (development)

- Homepage advertising float from cached CP promotional fields (`uni_container_*`, `uni_picturem`, `uni_backurl`)
- `displayFooter` + module-scoped CSS/JS (Hummingbird + Classic)
- Uninstall via `ModuleDataPurger` (AUD-006): 8 tables, tokens, certificates, safe order-state purge
- Removed superseded `Phase11DeferredMailDispatcher`
- Documentation: INSTALLATION, RELEASE, README/ARCHITECTURE refresh for Phases 0–13

## Prior development milestones

- Phase 12 — financing emails, native `order_conf`, Thank You UX, BO diagnostics; mail completion marker invariant
- Phase 11 — SmartUCF Process 1 / Process 2 handoff
- Phase 10 — durable checkout exactly-once
- Phases 0–9 — configuration, CP cache, inbound API, calculator, product/cart/checkout FO

## Deferred

- Final audit/remediation
- Production tag/package
- v2.0.2 scheme aggregation/sorting parity
