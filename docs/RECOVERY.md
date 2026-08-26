# Recovery — Phase 10 durable checkout

Recovery is **state-first**: inspect `order_attempt` + `financing_snapshot` before any new side effect.

## Invariants

| Resource         | Exactly-once key                                                                     |
| ---------------- | ------------------------------------------------------------------------------------ |
| PrestaShop order | `Order::getIdByCartId()` + attempt `id_order`                                        |
| Attempt          | UNIQUE `(id_shop, id_cart, cart_fingerprint)`                                        |
| Snapshot         | UNIQUE `id_attempt` and `id_order`                                                   |
| CP order         | Persist `control_panel_order_id`; CP dedupes by `(shop_id, order_id)` / PS reference |

## Crash windows

| Window                                         | Recovery                                                                                                                                                                                                                                  |
| ---------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Before PS order                                | Release checkout lock; customer may retry checkout safely                                                                                                                                                                                 |
| After `validateOrder()`, before attempt update | Lock owner retries: `reserved` + `id_order NULL` is recoverable; gateway uses `Order::getIdByCartId()` (no second `validateOrder`); `attachOrderIfReserved` persists identity atomically. Live concurrency stays on `CheckoutSubmitLock`. |
| After snapshot, during CP POST                 | Retry resumes CP create with same payload; timeout → `cp_outcome_unknown` + `bank_send_failed_cp`                                                                                                                                         |
| After CP success, before attempt update        | Retry finds CP id on success response or CP lookup by reference (Phase 11 may extend)                                                                                                                                                     |

## Post-order rule

Once `id_order` exists on the attempt, **never** start a fresh financing attempt for the same `(shop, cart, fingerprint)`. CP failure keeps the PS order; retry resumes attempt/snapshot/CP only.

## Lock stale recovery

`checkout_lock` TTL **45s**. Expired row may be taken over only after checking durable attempt state — expiry alone does not imply no PS order was created.

## CP ambiguous timeout

Connection/timeout → `cp_outcome_unknown`, `bank_send_failed_cp`, retryable post-order outcome. Safe retry reuses stored `cp_payload` and relies on CP idempotency by shop/order reference.

## Phase 11 post-CP lifecycle

After durable `cp_created`:

| Path                          | Recovery                                                                  |
| ----------------------------- | ------------------------------------------------------------------------- |
| Process 2                     | Persist `bank_sent_process2` (always); mail is separate side effect       |
| Process 1 success             | Snapshot `smartucf_state=created`; `bank_sent_process1`; trusted redirect |
| Process 1 retryable failure   | `smartucf_failed` + retryable=1; same attempt/CP; resume claim            |
| Process 1 terminal failure    | `bank_send_failed_smartucf`; no new PS/CP order                           |
| Process 1 ambiguous transport | `outcome_unknown`; do **not** mark `bank_sent_process1`                   |
| Replay after created          | Coordinator returns durable session; no second `createSession`            |
| CP missing / Phase 10 failure | Phase 11 must not run                                                     |

Callback race: inbound `orderbankstatus` uses financing snapshot JOIN; local SmartUCF success writes `bank_sent_process1` first — do not regress success to SmartUCF failure on replay.

## Phase 12 mail / confirmation recovery

| Issue                                          | Guidance                                                                    |
| ---------------------------------------------- | --------------------------------------------------------------------------- |
| Mail failure after bank success                | Bank status unchanged; log exception class only; customer stays order-aware |
| SMTP accepted, `leasing_email_sent` write fail | Residual duplicate-mail risk on replay (audited combined marker)            |
| Confirmation refresh / callback                | Must not re-send financing mails or duplicate `order_conf`                  |
| Manual Retry CP / SmartUCF buttons             | Not provided (not audited safe UI)                                          |

AUD-009: do not map bank rejection callbacks to native PS order state. `SYNC_BANK_REJECTION_STATE` is dormant (empty rejection whitelist).

## Multishop

All durable rows are scoped by `id_shop`. Inbound `orderbankstatus` authorizes via `order_reference + id_shop + financing_snapshot JOIN` (AUD-011). BO financing block resolves by `id_order` → snapshot (unique per order).
