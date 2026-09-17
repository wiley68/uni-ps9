# Recovery — durable financing

Recovery is **state-first**: inspect `order_attempt` + `financing_snapshot` before any new side effect.

Applies to checkout (`validatecheckout`) and product/cart popup apply paths that share `OrderOrchestrator`.

## Invariants

| Resource         | Exactly-once key                                                                     |
| ---------------- | ------------------------------------------------------------------------------------ |
| PrestaShop order | Authoritative same-cart order with lines (gateway + `AuthoritativeOrderResolver`)    |
| Attempt          | UNIQUE `(id_shop, id_cart, cart_fingerprint)`                                        |
| Snapshot         | UNIQUE `id_attempt` and `id_order`                                                   |
| CP order         | Persist `control_panel_order_id`; CP dedupes by `(shop_id, order_id)` / PS reference |

**Guest Cart:** shipping/package state is synchronized after guest/address mutation so `validateOrder()` materializes one authoritative order (not an empty twin). Financing never binds to an order with zero `order_detail` rows.

## Crash windows

| Window                                         | Recovery                                                                                                                                                                                                                                                    |
| ---------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Before PS order                                | Release checkout lock / abandon popup processing; customer may retry safely when no `id_order` exists                                                                                                                                                       |
| After `validateOrder()`, before attempt update | Lock owner retries: `reserved` + `id_order NULL` is recoverable; gateway recovers existing same-cart authoritative order (no second `validateOrder`); `attachOrderIfReserved` persists identity atomically. Live concurrency stays on `CheckoutSubmitLock`. |
| After snapshot, during CP POST                 | Ambiguous outcome → `cp_outcome_unknown` (not `bank_send_failed_cp`); **no blind second POST /orders**; frozen payload preserved for proven reconciliation                                                                                                  |
| After CP success, before attempt update        | Retry finds CP id on success response or CP lookup by reference                                                                                                                                                                                             |

## Post-order rule (AUD-019)

Once `id_order` exists on the attempt, **never** start a fresh financing attempt for the same `(shop, cart, fingerprint)`. CP/SmartUCF/mail failure keeps the PS order; retry resumes attempt/snapshot/CP/SmartUCF only. Customer UX stays order-aware (not a blank new financing invite).

## Lock stale recovery

`checkout_lock` TTL **45s**. Expired row may be taken over only after checking durable attempt state — expiry alone does not imply no PS order was created.

## CP ambiguous timeout

Connection/timeout/malformed success/echo mismatch/auth/rate-limit/unknown 4xx → `cp_outcome_unknown` (or `cp_failed_retryable` for 5xx), **without** definitive local `bank_send_failed_cp`. Automatic replay must **not** issue a blind second POST `/orders` after an ambiguous create outcome. Frozen `cp_payload` / attempt identity remain authoritative for later proven reconciliation (operator/CP). Definitive CP machine-code rejection may still map to `bank_send_failed_cp`.

## Post-CP lifecycle

After durable `cp_created`:

| Path                          | Recovery                                                                                                         |
| ----------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| Process 2                     | Persist local `bank_sent_process2` + durable CP sync; mail is separate side effect                               |
| Process 1 success             | Snapshot `smartucf_state=created`; local `bank_sent_process1` + durable CP sync; trusted redirect                |
| Process 1 retryable failure   | `smartucf_failed` + retryable=1; same attempt/CP; resume claim                                                   |
| Process 1 terminal failure    | Public bank status `Неуспешно изпратен Банка - SmartUCF` (`bank_send_failed_smartucf`); no new PS/CP order       |
| Process 1 ambiguous transport | Internal `outcome_unknown`; do **not** mark `bank_sent_process1`; do **not** invent a fifth public bank status   |
| Ambiguous CP create           | Internal `cp_outcome_unknown` — **not** public `Неуспешно изпратен Банка - КП`; **no blind second POST /orders** |
| Definitive CP create failure  | Public bank status `Неуспешно изпратен Банка - КП` for **Process 1 and Process 2** (no generic failure label)    |
| P1 ↔ P2 CP sync targets       | Mutually incompatible; conflict → no PATCH                                                                       |
| Replay after created          | Coordinator returns durable session; no second `createSession`                                                   |
| CP missing / pre-CP failure   | Post-CP lifecycle must not run                                                                                   |

Public labels, email/Thank You rules, and leasing-field visibility: see [`ARCHITECTURE.md`](ARCHITECTURE.md) § _Authoritative bank status and leasing information_.

Callback race: inbound `orderbankstatus` uses financing snapshot JOIN; local SmartUCF success writes `bank_sent_process1` first — do not regress success to SmartUCF failure on replay. Later SmartUCF statuses from CP are stored/shown **exactly as returned** (no invented mapping).

## Mail / confirmation recovery

| Issue                                       | Guidance                                                                                |
| ------------------------------------------- | --------------------------------------------------------------------------------------- |
| Mail failure after bank success             | Bank status unchanged; log audience/exception class; customer stays order-aware         |
| Required audience `Mail::Send` false/throw  | `leasing_email_sent` stays 0; replay retries leasing mail                               |
| Partial customer OK / admin fail            | Marker stays 0; retry may resend customer (accepted vs permanent lost mail)             |
| SMTP accepted, marker persistence fails     | Exception; marker unset; replay may duplicate if SMTP already delivered                 |
| Native `order_conf` flush then leasing fail | Queue emptied once — no second `order_conf`; leasing retries independently              |
| Confirmation refresh / callback             | Must not re-send financing mails when marker=1; duplicate `order_conf` guarded by queue |
| Manual Retry CP / SmartUCF buttons          | Not provided (not audited safe UI)                                                      |

AUD-009: do not map bank rejection callbacks to native PS order state. `SYNC_BANK_REJECTION_STATE` is dormant (empty rejection whitelist).

## Multishop

All durable rows are scoped by `id_shop`. Inbound `orderbankstatus` authorizes via `order_reference + id_shop + financing_snapshot JOIN` (AUD-011). SmartUCF journal reads use `id_shop + order_id` (AUD-020). BO financing block resolves by `id_order` → snapshot (unique per order).
