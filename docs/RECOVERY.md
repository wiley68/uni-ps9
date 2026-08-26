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

## Multishop

All durable rows are scoped by `id_shop`. Inbound `orderbankstatus` authorizes via `order_reference + id_shop + financing_snapshot JOIN` (AUD-011).
