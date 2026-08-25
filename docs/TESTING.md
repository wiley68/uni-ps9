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

Also useful for Phase 6 JS helpers:

```bash
node tests/Product/ProductCalculatorJsTest.js
```

Phase 6 product coverage:

| Test                                                        | What it checks                                     |
| ----------------------------------------------------------- | -------------------------------------------------- |
| `tests/Product/ProductContextFactoryTest.php`               | Server-side price × qty / combination              |
| `tests/Product/ProductCalculatorPresenterTest.php`          | Offers / preferred / currency / visuals            |
| `tests/Product/ProductPopupCalculatorTest.php`              | Modal calculate + presenter consents               |
| `tests/Product/ProductCalculatorControllerContractTest.php` | Endpoint validation / safe errors / calculate-only |
| `tests/Product/ProductButtonVisualContractTest.php`         | Template/CSS/JS visual contracts                   |
| `tests/Frontend/ProductFrontendContractTest.php`            | Hooks, assets, no jQuery, lifecycle, envelopes     |
| `tests/Frontend/ProductStaleRequestRaceContractTest.php`    | AbortController + sequence stale-response guard    |

Phase 5 calculator coverage remains under `tests/Calculator/*`.

Prior phases remain covered under `tests/Configuration`, `tests/Api`, `tests/Security`, `tests/Order`, `tests/SmartUcf`, `tests/Infrastructure`.

Phase 7 identity/dedupe:

| Test                                                        | What it checks                                                       |
| ----------------------------------------------------------- | -------------------------------------------------------------------- |
| `tests/Product/PopupSubmissionRepositoryTest.php`           | Hash stability, issue/reuse, atomic claim, expiry, identity_accepted |
| `tests/Product/ProductPopupOperationGuardTest.php`          | Missing/expired/replay/shop/identity/selection/concurrency           |
| `tests/Product/PopupSubmissionBindingFactoryTest.php`       | Context guest/customer identity, no POST trust                       |
| `tests/Product/ProductPopupCustomerTest.php`                | Prefill + Step 2 validator (BG messages, Process 2 EGN)              |
| `tests/Product/ProductPopupApplyIdentityTest.php`           | Consents, EGN stripped from response                                 |
| `tests/Product/ProductPopupPreselectOperationGuardTest.php` | Client operation token idempotency                                   |
| `tests/Product/Aud010PopupAddressContractTest.php`          | Preferred address + ownership                                        |
| `tests/Security/Aud002aPopupSubmissionContractTest.php`     | Static AUD-002A / Phase 7 boundary                                   |
| `tests/Security/Aud001GuestIdentityContractTest.php`        | No email customer lookup                                             |

Other useful commands:

```bash
composer validate --no-check-publish
composer dump-autoload
git diff --check
```

## Manual STOP GATE 7

Product popup identity/dedupe (Hummingbird + Classic). Passed.

## Phase 8 cart coverage

| Test                                                         | What it checks                                              |
| ------------------------------------------------------------ | ----------------------------------------------------------- |
| `tests/Cart/CartSchemeResolverTest.php`                      | Intersection, filter metadata, cart-total price parity      |
| `tests/Cart/CartCalculatorPresenterTest.php`                 | Offers / currency / labels                                  |
| `tests/Cart/CartPopupCalculatorTest.php`                     | Modal calc uses cart total                                  |
| `tests/Cart/CartAmountSemanticsTest.php`                     | Qty vectors 100/300 + filter case                           |
| `tests/Cart/CartFlowIsolationTest.php`                       | product↔cart hash isolation, drift, cross-session           |
| `tests/Cart/CartPopupReplayGuardTest.php`                    | Atomic claim replay + cross-flow reject                     |
| `tests/Cart/CartPopupContractTest.php`                       | Template/JS/controller Phase 8 contracts                    |
| `tests/Cart/CartPopupControllerContractTest.php`             | Actions / no order / no preselect                           |
| `tests/Frontend/CartFrontendLifecycleContractTest.php`       | `updatedCart`, AbortController, no jQuery                   |

## Manual STOP GATE 8

Cart-page financing (Hummingbird + Classic). Passed.

## Phase 9 checkout coverage

| Test                                                           | What it checks                                         |
| -------------------------------------------------------------- | ------------------------------------------------------ |
| `tests/Checkout/CartSnapshotFingerprintTest.php`               | Same-total drift, carrier, voucher, determinism        |
| `tests/Checkout/CheckoutPaymentPresenterTest.php`              | Payment view / preference / currency / consents        |
| `tests/Checkout/CheckoutPaymentCalculatorTest.php`             | Eligible / promo / shipping / voucher / invalids       |
| `tests/Checkout/CheckoutPaymentValidatorTest.php`              | Fingerprint + scheme + consent server authority        |
| `tests/Checkout/CheckoutPreferenceStoreTest.php`               | Cookie-safe preference TTL / cart scope                |
| `tests/Checkout/PaymentOptionContractTest.php`                 | Hook / action / Phase 9 order boundary                 |
| `tests/Checkout/DeferredV202PromoAggregationContractTest.php`  | Documents deferred standard/promo v2.0.2               |
| `tests/Frontend/CheckoutFrontendLifecycleContractTest.php`     | Events, AbortController, no jQuery                     |

## Manual STOP GATE 9

Checkout PaymentOption + financing selection. See Phase 9 implementation report. Do not start Phase 10 until accepted.
