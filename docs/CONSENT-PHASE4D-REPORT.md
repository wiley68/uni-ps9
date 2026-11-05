# UNI-CONSENT-001 / Phase 4D — F1/F2/F3 remediation

Дата: 2026-11-05. Само Phase 4D. STOP GATE след remediation и isolated validation.

## A. Baseline и Git status

- Repository `wiley68/uni-ps9`, branch `main`, HEAD преди/след
  `f85c477fc5a9b078211918a99b3d063cfc45ffab`.
- Версията остава `2.0.3`; PHP `>=8.1 <8.6`; PS `9.0.0`–`9.99.99`.
- Начално състояние: 43 modified + 9 untracked файла от Phase 4B, без staged промени.
- Phase 4C findings са проверени по засегнатите пътища; F1/F2 са възпроизведени
  с изпълними изолирани тестове преди production корекциите.
- Крайно състояние: 43 modified + 11 untracked, без staged промени.
  SHA-256 manifest от началото на Phase 4D потвърждава: 44 от 52 начални файла са
  byte-identical; останалите 8 са точно описаните по-долу целеви изменения.
  Няма премахнати начални файлове; добавени са само новият F1 тест и този отчет.
- Няма commit, push, branch, tag, package или version bump.

## B. F1 — причина и корекция

`consentSelection()` връщаше metadata-only override при съществуваща PS order без
financing snapshot. Този non-null override изключваше използването на вече валидираната
server-side calculation, но нямаше KOP/months: scoped interest ставаше unknown.

Read-only preflight вече маркира изрично дали snapshot липсва. Само при доказана липса
`CheckoutPaymentValidator` добавя selection от собствената валидирана calculation,
като запазва order/attempt/reference metadata за order-aware отказите.
Наличен snapshot, включително празен/непълен, изрично е маркиран като наличен и никога
не получава fallback към POST B. Order identity се взема от attempt при налична order.
Маркерът е само in-memory; не се записва във financing snapshot или CP payload.

Няма промени във fingerprints, locks, reservation/recovery states, lifecycle, schema,
CP/SmartUCF contracts или исторически финансови стойности. Не се ремонтират стари snapshots.

## C. F1 — преди/след и regression

Команда: `php8.1 tests/Checkout/ConsentRecoverySelectionTest.php`.

- Преди: първият repro спира с order-aware `OrderOrchestrationException`, чиято причина е
  `CheckoutValidationException` за unknown interest, въпреки валиден STD coefficient и `[1,3]`.
- След: `OK consent recovery selection 42 assertions` на PHP 8.1 и 8.4.
- Покрити са липсващ snapshot + валидно/липсващо mandatory съгласие; persisted A срещу POST B;
  missing/invalid coefficient на A без fallback; празен/непълен snapshot; защитен marker и
  order identity; авторитетен A, открит едва при reserve; no history writes при отказ.
- Recovery запазва PS order 55, не извиква нов native create; CP create е един и completed
  replay не добавя втори. Отказите не достигат външна работа. Реален SmartUCF не се стартира.
- EUR-only, foreign attempt origin и foreign snapshot origin отказите остават authoritative.

## D. F2 — причина и ownership стратегия

Старият destroy оставяше blocking `form.submit` wrapper; новият mount върху същата форма
го запазваше като native method. Wrapper-ът на новия controller връщаше success без native send.

Module-local WeakMap пази истинския underlying method, текущия owner и sent flag за всяка
собствена форма. Новият mount оттегля стария owner и използва запазения method.
Старият controller се dispose-ва идемпотентно: native/document listeners се премахват,
timers се отменят, preview се abort-ва. Retained submit/event/release/destroy callbacks
не изпращат и не променят guard/busy state на новия owner. Disposed input callbacks не
стартират нови requests. Sent flag остава валиден и след same-form remount срещу duplicates.

Final synchronous validation и generation check остават за native event и direct submit.
Няма промяна на global form prototype, core код, други payment форми или jQuery dependency.

## E. F2 — преди/след и regression

Команда: `node tests/Frontend/CheckoutSubmitBoundaryTest.js`.

- Преди: първият repro пада с
  `F2 same-form remount invokes genuine native submit once`; native counter остава 0.
- След: `OK checkout final submit boundary 31 assertions`.
- Проверени: A → destroy A → B; A → B → destroy A; retained old callbacks; invalid/valid B;
  asynchronous generation change; native event; different form; duplicate и reentrancy;
  remount след вече изпратена форма; unrelated payment forms; no prototype/jQuery changes.
- Production DOM harness `ConsentLifecycleTest.js`: **114 assertions**, включително двата
  same-form реда, оттегляне на стария document handler и защита на busy state от стар release.

## F. F3 — независими assertions и negative tests

Слабият multi-argument `!isset(redirect_url, cp_error)` е премахнат.
Test-local `assertNoConsentReplayContinuation()` проверява всеки forbidden field отделно
и се изпълнява върху actual Product/Cart completed-replay отказите.
Три negative fixtures доказват отказ при redirect alone, CP error alone и both;
empty response преминава проверката. Всички минават на PHP 8.1/8.4.
F3 не променя production replay кода.

## G. Business parity и security

Пълният safe suite включва consent scope (73 assertions), cache persistence,
Product/Cart/Checkout, retry/completed replay/idempotency, AUD-019, EUR/origin,
CP failure и SmartUCF isolated regressions. Всички изпълнени safe тестове минават.
`git diff --quiet` потвърждава, че Calculator, CartSchemeResolver, CartSnapshot,
CheckoutSubmitLock, PopupSubmissionSelectionHash, FinancingSnapshotFactory/Repository,
CP payload builder, currency/replay guards, post-CP lifecycle, `src/SmartUcf`, composer
metadata/lock са непроменени спрямо HEAD.

Няма live bootstrap, live DB mutation, real native order, external CP/bank request или email.
Reference repositories не са променяни. Не са добавени secrets или runtime dependencies.

## H. PHP 8.1 / 8.4 safe suite

| Runtime | PASS | SKIP | PARTIAL | FAIL |
| --- | ---: | ---: | ---: | ---: |
| `php8.1 tests/run.php safe` | 146 | 6 | 1 | 0 |
| `php8.4 tests/run.php safe` | 146 | 6 | 1 | 0 |

153 test files общо. Шестте runtime файла са изключени **преди изпълнение**:

- `Api/LiveControlPanelSmokeTest.php`
- `Configuration/LiveShopCacheSmokeTest.php`
- `Infrastructure/ControlPanelOriginSchemaRuntimeTest.php`
- `Order/ControlPanelStatusSyncCasEmptyStringRuntimeTest.php`
- `SmartUcf/Aud002bLifecycleRepositoryTest.php`
- `Uninstall/Aud006ModuleDataPurgerDbTest.php`

`Infrastructure/DistributionPackageTest.php` е PARTIAL: static version/path assertions PASS;
ZIP build/dependency install са SKIP. Това не се отчита като пълна packaging валидация.

## I. JavaScript и други проверки

- Lifecycle harness: 114 assertions PASS.
- Final-submit harness: 31 assertions PASS.
- PHP syntax: всички 38 modified/new PHP файла PASS и на PHP 8.1, и на PHP 8.4.
- JS syntax: всички 6 modified/new JS файла PASS; последният checkout edit също е проверен.
- `composer validate --strict --no-check-publish --no-interaction`: PASS.
  Има само environment notice за unwritable Composer cache; validation завършва с exit 0.
- `git diff --check`: PASS. Index остава празен.

## J. Created / modified / deleted — само Phase 4D delta

Created:

- `tests/Checkout/ConsentRecoverySelectionTest.php`
- `docs/CONSENT-PHASE4D-REPORT.md`

Modified спрямо началното Phase 4D working tree:

- `src/Order/OrderOrchestrator.php`
- `src/Checkout/CheckoutPaymentValidator.php`
- `views/js/checkout-payment.js`
- `tests/Frontend/CheckoutSubmitBoundaryTest.js`
- `tests/Frontend/ConsentLifecycleTest.js`
- `tests/Order/ConsentCompletedReplayTest.php`
- `docs/ARCHITECTURE.md`
- `docs/TESTING.md`

Трите изменени consent/frontend теста вече бяха untracked от Phase 4B и остават такива.
Deleted: **няма**. Пълният наследен Phase 4B file inventory е в `CONSENT-PHASE4B-REPORT.md`.

## K. Remaining risks

Isolated CLI/fake DOM не доказват реален Apache/FPM/theme/core checkout lifecycle.
Остават manual checks на Classic и Hummingbird с delivery/payment HTML replacement,
same-form и different-form remount, slow orderability/context invalidation и double click.
След final отказ core може вече да е задал своя disabled-data flag: refresh guidance
остава в сила; UniPayment не заобикаля или нулира core guard-а.
Наличен malformed historical snapshot продължава да отказва; няма автоматичен repair.
Safe SKIP/PARTIAL не са evidence за live runtime, bank integration или ZIP acceptance.

## L. Recommendation и STOP GATE

**GO за отделна manual browser acceptance на F1/F2/F3** върху Classic и Hummingbird.
Това не е release approval или твърдение за завършена live acceptance.
Няма останал установен blocker по трите findings в изолираните проверки.
**STOP GATE: remediation и validation завършени; спиране за review.**
