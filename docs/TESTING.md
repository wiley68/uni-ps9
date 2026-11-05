# Testing

UniPayment CLI tests run against the module checkout in the PrestaShop test shop filesystem. The repository lives inside a **live dev installation**, so the default suite must remain non-destructive.

## Development environment

| Item                    | Current value      |
| ----------------------- | ------------------ |
| Shop                    | PrestaShop **9.1** |
| CLI / FPM PHP           | **8.4**            |
| Production PHP baseline | **8.1**            |
| Supported CLI matrix    | PHP **8.1–8.5**    |

## Control Panel destination / origin remediation gate

- `Api/ControlPanelDestinationTest`: four configurable origins with synthetic public DNS, invalid URL matrix, mixed unsafe A/AAAA, safe/unsafe/cyclic CNAME, pinning/proxy/TLS/timeouts and unsupported/failed cURL security options; rejection means zero HTTP.
- `Api/ControlPanelAuthorityTest`: every CP method under four deployment fixtures, no independent constructor/file authority or embedded deployment hostname; BO/cache/product/cart/checkout/create/replay/status/certificate/logout wiring.
- `Configuration/ControlPanelOriginSwitchTest`: real token/cipher/cache/service paths across A → B; new login precedes GET, no old Bearer, no foreign fresh/LKG/credential hydration, same-origin TTL/LKG preserved and legacy ephemeral replacement.
- `SmartUcf/CertificateOriginSwitchTest`: synthetic encrypted PEMs only in a private temporary directory; transient same-origin reuse, auth/protocol denial, foreign/legacy denial and required B bundle even with equal A hashes.
- `Order/OrderOrchestratorTest` and `Order/ControlPanelStatusSyncServiceTest`: foreign/legacy attempt/snapshot, frozen create, `cp_created`, pending/confirmed PATCH; zero extra HTTP/native orders and identical stored history on block.
- `Infrastructure/ControlPanelOriginMigrationTest`: additive nullable migration, existing/concurrent/failure paths and no provenance backfill or history mutation. The schema probe uses uncached `executeS()`; its regression models `getRow()` appending invalid `LIMIT 1` and asserts exact `SHOW COLUMNS` syntax.
- `Infrastructure/ControlPanelOriginSchemaRuntimeTest`: opt-in real MySQL/MariaDB integration with native PS Db/DbPDO and randomly named connection-local TEMPORARY tables. Both repositories run fresh, legacy-with-preserved-history and repeated install paths; existing columns also recheck through a new adapter/request. Run `UNIPAYMENT_ORIGIN_SCHEMA_RUNTIME=1 php8.4 tests/Infrastructure/ControlPanelOriginSchemaRuntimeTest.php [core-db-directory] [test-db-parameters-file]`. No shop bootstrap, permanent schema edits or destructive cleanup. Fresh/legacy/repeated paths were verified on MySQL 8.0.46 and isolated MariaDB 10.11.19 through local PS 9.1 and official PS 9.2 Db drivers on PHP 8.4.
- `Api/NativeInboundCompatibilityTest`: fifteen isolated cases with actual native PS controller inheritance, bypassing constructors/bootstrap. Default uses local PS core; pass an external official core-controller directory to repeat against another line, and an optional extracted module directory to test the production ZIP.

`DeploymentEnvironmentFixture` copies the actual loader and deployment file to `/tmp`, then simulates separate requests by resetting only that isolated loader's private process cache. It never edits the live environment file and is excluded from the ZIP. Fake DNS/transport never contact CP.

On PHP 8.4, native inheritance was checked against local PS 9.1.0 and the official 9.2.0 `Controller`/`FrontController`/`ModuleFrontController` sources fetched only to `/tmp`. This verifies inheritance and controlled inbound responses; it does not certify a complete PS 9.2 Apache/FPM/browser lifecycle.

The remediation validation passed the full safe suite on the available PHP 8.1, 8.2, 8.3 and 8.4 runtimes. The installed PHP 8.5 CLI has no curl, zip or SimpleXML extension binaries; its destination and packaging tests therefore fail at prerequisite checks. The missing-zip packaging prerequisite predates this remediation; the new transport-options test also requires curl. All other safe files pass there. This is an environment limitation, not evidence of completed PHP 8.5 network/package validation. Live financing/destructive database tests remain opt-in/skipped, as do database adapter tests when their native PS configuration fixture is unavailable.

## EUR-only regression gate

`tests/Calculator/EurCurrencyGuardsTest.php` covers ISO normalization and cart/context currency ID and ISO coherence. Snapshot validation tests confirm `uni_eur` is optional and opaque. `tests/Order/OrderOrchestratorTest.php` exercises new EUR CP create and rejects incomplete, malformed, non-EUR or mismatched frozen CP payloads without another HTTP call. `tests/Order/EurPopupReplayTest.php` exercises durable provenance for both product and cart popup replay. The SmartUCF boundary tests cover invalid snapshot replay and Process 2; the checkout lock-loser test rejects old non-EUR or missing durable evidence before exposing a stored redirect.

EUR-PS9-004 coverage also checks positive and inconsistent CP state/ID combinations in the orchestrator, both popup replay paths, checkout lock-loser recovery, direct post-CP lifecycle and direct SmartUCF coordination. The lock-loser negative cases each restore a known-valid snapshot before changing one cause; invalid replay tests assert no extra CP/SmartUCF request, journal claim or durable rewrite.

Run `composer test`, `node tests/Product/ProductCalculatorJsTest.js`, PHP syntax checks for changed PHP files, JS syntax checks, and `git diff --check`. The safe suite uses test doubles and does not create live shop orders or call external financing endpoints.

## Automated checks

### UNI-CONSENT-001 / Phase 4B

`php tests/run.php safe` (also `composer test`) clears inherited `UNIPAYMENT_*` opt-ins,
sets the safe suite explicitly, and excludes the six known live/runtime/destructive files
before execution. It runs PHP tests and JS `*Test.js` files and reports PASS/SKIP/PARTIAL
separately. `runtime`, `destructive` and `all` are explicit CLI choices; their existing
individual opt-ins still apply. They are not authorized by a normal safe run.

The packaging test runs static version/path contracts in safe mode, then reports a partial
result. ZIP generation invokes Composer install and publishes artifacts, so that section
requires a separately authorized packaging task. Phase 4B generates no ZIP or dependencies.

Consent regressions:

- `Checkout/ConsentScopeTest.php`: normalization, strict IDs, epsilon/non-finite matrix,
  informational/mandatory rules, default/schema KOP, raw versus rounded GLP, and checkout.
- `Configuration/ConsentCachePersistenceTest.php`: real cache JSON/SQL serialization with
  a fake Db, array/JSON-string consents, all scopes and apostrophe round-trip.
- `Product/ConsentSubmissionTest.php`: Product/Cart rejection before fake customer mutation.
- `Order/ConsentRetryTest.php`: persisted A versus POST B, concurrent recovery, immutable
  historical calculations/payloads and order-aware refusal, using in-memory stores.
- `Order/ConsentCompletedReplayTest.php`: executes both actual private completed-replay
  controller paths with fake persistence/native currency and an unusable CP adapter; missing
  acceptance must return the existing order before any external continuation. EUR/origin
  denial remains authoritative.
- `Frontend/CheckoutSubmitBoundaryTest.js`: the chosen own-form method strategy was executed
  before checkout integration; native events, async confirmation, direct final submit,
  duplicate, generation change, disposal and unrelated forms.
- `Frontend/ConsentLifecycleTest.js`: production helper/controllers with fake DOM, timers and
  fetch. Includes H1 for both popups, stale calculation/error/token, early combination/cart
  invalidation, same-scheme acceptance retention, missing HTML IDs, disposed forms and the
  actual async orderability -> synthetic handlers -> direct form submit model. The model
  preserves core's disabled-data guard after rejected final submission: refresh/remount,
  rather than bypassing that guard, restores the flow with fresh acceptance.

Existing static lifecycle contracts now allow a mount-filtered observer and assert the final
submit boundary. The AUD-020 fake journal fixtures use recent timestamps so the three-month
retention policy cannot silently expire their isolation evidence. No journal production code changed.

Manual acceptance remains necessary on Classic and Hummingbird: Product combinations/quantity,
Cart quantity/voucher/empty-cart refresh, Checkout delivery/payment replacement, scope switches
and first-installment-only edits. Test slow orderability plus context changes and refresh guidance,
double clicks, Process 1/2 and exactly one authoritative Guest Cart order only in a separately
authorized runtime session. CLI doubles do not certify a live Apache/FPM/browser lifecycle.

### UNI-CONSENT-001 / Phase 4D remediation

- `Checkout/ConsentRecoverySelectionTest.php`: executable F1 reproduction and 42 assertions with
  typed in-memory order/CP adapters. Absent snapshot recovery, missing mandatory consent,
  persisted A versus POST B, missing/invalid A coefficient, malformed/empty present snapshots,
  authoritative reserve recovery, order-aware errors, no duplicate native/CP create or history
  rewrite, and unchanged EUR/CP-origin denial. No shop bootstrap/network/SmartUCF session.
- `Frontend/CheckoutSubmitBoundaryTest.js`: 31 assertions, including F2 before/after reproduction,
  both same-form ownership orders, retained callbacks, invalid/current context, asynchronous
  generation change, native events, duplicates/reentrancy and unrelated payment forms.
- `Frontend/ConsentLifecycleTest.js`: 114 assertions against production controllers; both
  same-form remount orders also retire the old live-root document handler and input callbacks.
- `Order/ConsentCompletedReplayTest.php`: independent forbidden-field assertions exercised by
  actual Product/Cart replay refusals and three negative fixtures (redirect alone, CP error alone,
  both). Production replay behavior is unchanged by F3.

Phase 4D safe results on PHP 8.1 and 8.4: **146 PASS / 6 SKIP / 1 PARTIAL / 0 FAIL** on each.
The six runtime exclusions and partial static-only packaging check described above remain in force.
See `CONSENT-PHASE4D-REPORT.md` for reproduction evidence and the manual acceptance STOP gate.

Safe default:

```bash
composer test
```

The release / regression gate runs the **full safe suite on PHP 8.1–8.5**.

Also useful for product JS helpers:

```bash
node tests/Product/ProductCalculatorJsTest.js
```

```bash
composer validate --no-check-publish
composer dump-autoload
git diff --check
```

Destructive Aud006 DB purge test **SKIPs** in the safe suite.

### Coverage map (by area)

| Area                                            | Location                                        |
| ----------------------------------------------- | ----------------------------------------------- |
| Calculator / parity                             | `tests/Calculator/*`                            |
| Configuration / shared cache lifecycle / LKG     | `tests/Configuration/*`                         |
| Inbound API / CP client                         | `tests/Api/*`                                   |
| Header type safety / private error diagnostics   | `tests/Api/InboundHeaderHardeningTest.php`        |
| Production ZIP / runtime parity / repeatability   | `tests/Infrastructure/DistributionPackageTest.php` |
| Security / tokens / AUD-021 secrets             | `tests/Security/*`                              |
| Product FO / popup                              | `tests/Product/*`, `tests/Frontend/Product*`    |
| Cart FO / popup / twin-order contracts          | `tests/Cart/*`, `tests/Frontend/Cart*`          |
| Checkout / lock / AUD-019                       | `tests/Checkout/*`, `tests/Frontend/Checkout*`  |
| Order / mail / bank status                      | `tests/Order/*`                                 |
| SmartUCF / AUD-020 journal scope                | `tests/SmartUcf/*`                              |
| Advertising / shared presentation resolver      | `tests/Advertising/*`                           |
| Uninstall                                       | `tests/Uninstall/*`                             |
| Remediation / infrastructure                    | `tests/Remediation/*`, `tests/Infrastructure/*` |

The PHP baseline scanner prunes vendor, tests and dist before recursion, so protected build artifacts cannot cause unrelated source-lint failures.

Do not hard-code a permanent test file count here — it changes with each remediation. Prefer `composer test` output as the source of truth.

## Manual smoke (final regression)

| Area        | Checks                                                                                                                           |
| ----------- | -------------------------------------------------------------------------------------------------------------------------------- |
| Product     | Calculator + financing popup (Hummingbird + Classic)                                                                             |
| Cart        | Calculator + financing popup; **guest cart** → exactly one authoritative PS order                                                |
| Checkout    | PaymentOption; Process 1 / Process 2; double-click stays post-order (AUD-019)                                                    |
| Advertising | Fresh cache is local; stale refreshes; transient <=6h may show valid LKG; Class B/C and too-old hide safely                       |
| Packaging   | Byte parity for source `config/environment.php` and Git-ignored `secrets/smartucf-key.php`; fresh production autoload; manifest/source parity; no other credentials/PEMs/runtime data in ZIP |
| Privacy     | Process 1/2 mail audiences; no customer EGN; public bank status only on emails/Thank You (see ARCHITECTURE bank-status contract) |

Historical phase STOP gates (7–13) are **completed** delivery milestones; they are not the current release gate.

`InboundHeaderHardeningTest` runs all three inbound controllers in isolated CLI processes with typed PrestaShop doubles: unsigned POST, malformed SERVER keys/values, GET, header fallback and unexpected-error logging. `DistributionPackageTest` requires the local `secrets/smartucf-key.php` deployment file without printing or executing it. It builds twice, checks byte repeatability/source immutability and unchanged environment/passphrase files, rejects missing/symlinked deployment sources, executes nine cases from the extracted production ZIP and rejects ten corrupted/development archives. Negative cases include missing passphrase files and rewritten environment/passphrase files with recomputed manifests. These tests do not bootstrap the live shop or contact CP. Run the native HTTP smoke on both PS 9.1.x and 9.2.x under PHP 8.4 before deployment; CLI doubles do not certify the remote Apache/FPM lifecycle.

## Authoritative manual-test contract

For bank status, leasing panel fields, Thank You / email content, and diagnostic visibility, use:

[`ARCHITECTURE.md`](ARCHITECTURE.md) § _Authoritative bank status and leasing information_

Do not treat internal lifecycle/machine ids as public bank-status copy during manual verification.

## Deferred product behavior

- Bank-rejection → native PS order-state sync remains dormant (AUD-009)
- Additional browser/runtime acceptance beyond isolated DOM/submit and validator coverage
