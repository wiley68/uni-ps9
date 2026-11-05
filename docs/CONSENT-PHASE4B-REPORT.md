# UNI-CONSENT-001 / Phase 4B — Implementation report

Дата: 2026-11-05. Implementation и isolated validation са завършени. STOP GATE за review.

## A. Baseline

- Repository: `wiley68/uni-ps9`, branch `main`.
- HEAD преди/след: `f85c477fc5a9b078211918a99b3d063cfc45ffab`.
- Начален `git status --short`: празен; без staged, unstaged или untracked файлове.
- Версия: `2.0.3`; PHP `>=8.1 <8.6`; PS min `9.0.0`, max `9.99.99`.
- PS8 reference: `deb3ca888f57352830436031b97c58b55d1e2a98`; CP reference: `c240ec75d3eb4616bdde75b5da4170e67432c001`.
- Прочетени AGENTS, ARCHITECTURE, TESTING и правилата за typed test doubles.

## B. Created / modified / deleted

Created: **9**. Modified: **43**. Deleted: **0**. Всички промени са unstaged в uni-ps9.

### Created

- `docs/CONSENT-PHASE4B-REPORT.md`
- `tests/Checkout/ConsentScopeTest.php`
- `tests/Configuration/ConsentCachePersistenceTest.php`
- `tests/Frontend/CheckoutSubmitBoundaryTest.js`
- `tests/Frontend/ConsentLifecycleTest.js`
- `tests/Order/ConsentCompletedReplayTest.php`
- `tests/Order/ConsentRetryTest.php`
- `tests/Product/ConsentSubmissionTest.php`
- `views/js/shop-consents.js`

### Modified

- `CHANGELOG.md`
- `controllers/front/cartpopup.php`
- `controllers/front/productpopup.php`
- `controllers/front/validatecheckout.php`
- `docs/ARCHITECTURE.md`
- `docs/TESTING.md`
- `src/Cart/CartPopupApplyService.php`
- `src/Cart/CartPopupCalculator.php`
- `src/Checkout/CheckoutPaymentCalculator.php`
- `src/Checkout/CheckoutPaymentPresenter.php`
- `src/Checkout/CheckoutPaymentValidator.php`
- `src/Checkout/ConsentResolver.php`
- `src/Checkout/ValidatedPaymentRequest.php`
- `src/Order/OrderAttemptRepository.php`
- `src/Order/OrderAttemptStoreInterface.php`
- `src/Order/OrderOrchestrator.php`
- `src/Order/PopupOrderReplayCurrencyGuard.php`
- `src/Order/PostOrderPopupFailureResponse.php`
- `src/Product/ProductPopupApplyService.php`
- `src/Product/ProductPopupCalculator.php`
- `tests/Checkout/Aud019CheckoutDoubleClickLockLoserTest.php`
- `tests/Frontend/Aud024PostOrderFinalFrontendContractTest.php`
- `tests/Frontend/CheckoutFrontendLifecycleContractTest.php`
- `tests/Infrastructure/DistributionPackageTest.php`
- `tests/Order/ApiV11Cloudflare403ClassificationTest.php`
- `tests/Order/Aud019PostOrderExceptionBoundaryTest.php`
- `tests/Order/CheckoutDefinitiveCpFailureBankStatusTest.php`
- `tests/Order/DefinitiveCpCreateFailureProductUxTest.php`
- `tests/Order/EmptyOrderLinesCpGuardTest.php`
- `tests/Order/OrderOrchestratorTest.php`
- `tests/Order/ProductP1SnapshotPersistenceRegressionTest.php`
- `tests/Remediation/Batch002ContractsTest.php`
- `tests/SmartUcf/Aud020SmartUcfDebugLogShopScopeTest.php`
- `tests/run.php`
- `unipayment.php`
- `views/css/checkout-payment.css`
- `views/css/product-calculator.css`
- `views/js/cart-calculator.js`
- `views/js/checkout-payment.js`
- `views/js/product-calculator.js`
- `views/templates/hook/cart_calculator.tpl`
- `views/templates/hook/checkout_payment.tpl`
- `views/templates/hook/product_calculator.tpl`

### Deleted

Няма изтрити файлове.

## C. Normalization / classification

`ConsentResolver` запазва PS8 поведението: трите scopes, fallback към `all`, независимо
`mandatory`, informational редове без checkbox, array/JSON-string definitions, strict positive
integer candidate IDs, deduplication и филтриране до applicable IDs. Context извежда
`interest_state`, `scheme_identity`, `applicable_ids`, `required_ids`, `valid` от matching trusted
KOP/month entry. Изисква finite numeric GLP и положителен finite coefficient; epsilon е
`abs(GLP) <= 0.00001`. Unknown блокира всяко scoped съгласие, включително informational;
empty/all-only списък не добавя блокиране само заради unknown. Display/historical GLP не участва.

## D. Product / Cart / Checkout frontend

Общ vanilla helper управлява текущия root. Render-нати са всички definitions с ID/scope metadata.
Неприложимите checkbox-и се изчистват/disabled. Pending/error блокира изпращане. Смяната на
ефективна схема изчиства scoped acceptance; `all` в същата форма и first-only редакции се
запазват. Няма acceptance transfer към Checkout. Missing required ID в стар HTML изисква refresh.

Popup calculate/token responses имат sequence/connected/open guards. Изчисленията и вече
изпратената операция имат отделни identities. Отменят се debounce/refresh timers преди apply;
obsolete preview не може да изтрие изпратения резултат. Запазени са PS9 combination selectors,
abort/refresh sequences, token/claim lifecycle, `postOrderFinal` и `redirectPending`. Ранно
invalidate при `updateCart` и `updateProduct`; checkout delivery/address промени блокират преди
AJAX. Form mount/disposal observer е ограничен до добавяне/премахване на форми.

## E. PS9 final submit proof

Преди интеграцията бе изпълнен `CheckoutSubmitBoundaryTest.js` с избраната production функция:
собствената UniPayment форма получава instance `submit()` boundary и native submit listener.
Native listener не се представя като защита за direct call. Асинхронният core път стига до
instance метода, който проверява текущата readiness и confirmation generation преди реалния
native method call. Duplicate final send се блокира синхронно.

Няма jQuery dependency, global prototype modification, core edit или прихващане на други payment
модули. Detached/disposed references не могат да изпратят стара форма. Watchdog отключва нов
click, без да изтрива generation на стара async проверка. Превключване към друг payment блокира
закъснялото подаване на UniPayment формата.

Финалният isolated controller harness моделира и core synthetic disabled-data handler.
След отказ core може вече да е маркирал формата като disabled; следва се показаното указание
за refresh/remount с ново acceptance. Не се променя/заобикаля core guard. Изолираните проверки
не са live browser certification на Classic/Hummingbird.

## F. Server validation

Product/Cart services валидират преди customer/cart mutation. Checkout валидаторът получава
trusted effective selection. Orchestrator извършва read-only preflight и повторна проверка след
authoritative recovery и преди CP работа, включително snapshot, спечелил INSERT IGNORE.
`submittedConsentIds` са отделни от accepted/applicable IDs, така че POST B filtering не губи
candidate ID за persisted A. Client GLP/scope/visibility/required IDs не са доказателство.
`issue_submission_token` не изисква преждевременно Step 2 acceptance.

## G. Replay / concurrency / AUD-019

Completed Product и отделният PS9 Cart token replay се проверяват след durable EUR/origin proof,
преди CP/SmartUCF continuation. Запазват се order reference/ID и доказаният CP ID. Consent отказ
за съществуваща поръчка връща order-aware съобщение без restart, нов bank status или нова
външна работа. Не се извиква post-order binder за такъв отказ и не се презаписва history.
Concurrent recovery revalidates authoritative persisted selection. Lock-loser остава observer.

Attempt identity, fingerprints, selection hashes, locks/atomic claims, native order IDs, EUR/origin
guards, CP uncertainty handling, frozen payload и SmartUCF lifecycle са запазени. Новите accepted
records включват scope в съществуващия `consents_json`; старите snapshots не се обновяват.

## H. PS8 parity / PS9 adaptations

Resolver и shared helper следват финалната PS8 семантика. PS8 Cart няма PS9 token shortcut;
проверката е добавена към PS9 shortcut-а, без премахване на token handling. PS8 checkout jQuery
handler е заменен с module-local final form boundary. Запазени са по-силните PS9 post-order,
EUR и origin safeguards. Woo не е runtime dependency; съществуващите calculator parity tests
са част от safe regression.

## I. H1

Контролираните fake timers/DOM/fetch възпроизвеждат и за Product, и за Cart:
first input → pending debounce → refresh → immediate calculation → apply → forcibly invoked
obsolete callback → successful apply response. PASS: нула obsolete calculate заявки, точно един
apply, запазен order result и точно един success redirect. Проверени са и preview/context
промени по време на вече изпратен apply, без нова операция.

## J. Test safety / резултати

Runner-ът бе прегледан преди изпълнение. Safe mode изчиства наследени `UNIPAYMENT_*` opt-ins,
избира safe изрично и изключва шест runtime/live/destructive файла преди изпълнение. Отчита
PASS/SKIP/PARTIAL отделно и включва JS harness-ите. Не са стартирани реални runtime тестове.

| Проверка | Резултат |
|---|---|
| Пълна safe suite, PHP 8.1 | 145 PASS / 6 SKIP / 1 PARTIAL / 0 FAIL |
| Пълна safe suite, PHP 8.4 | 145 PASS / 6 SKIP / 1 PARTIAL / 0 FAIL |
| Safe suite с наследени live/schema/destructive flags | Същият успешен резултат; runtime файловете са изключени |
| Consent scope PHP | 73 assertions PASS |
| Production frontend lifecycle | 96 assertions PASS |
| Own-form final submit boundary | 15 assertions PASS |
| Product/Cart mutation boundary, retry и completed replay | PASS с fake persistence/network/native boundaries |

Шестте SKIP са LiveControlPanelSmoke, LiveShopCacheSmoke, ControlPanelOriginSchemaRuntime,
ControlPanelStatusSyncCasEmptyStringRuntime, Aud002bLifecycleRepository и Aud006ModuleDataPurgerDb.
PARTIAL е DistributionPackageTest: статичните version/path contracts са изпълнени; build частта,
която извиква Composer install и публикува ZIP, е пропусната. Не е създаван release package.

При първия run беше открит pre-existing AUD-020 test fixture проблем: августовските записи вече
изтичат по тримесечния retention policy. Само fixture timestamps са направени recent и doubles
са типизирани; production journal не е променян. Старите source-pattern frontend contracts са
адаптирани към final submit boundary и ограничен mount observer; behavioral proof е в JS.

## K. Syntax / static

PHP 8.1 `-l`: 37 променени/нови PHP файла PASS. Node `--check`: 6 JS файла PASS.
`composer validate --no-check-publish`: PASS. Съществуващият PHP 8.1 compatibility scanner и
frontend no-jQuery contracts преминават в safe suite. Не са install/update-вани dependencies.
PHP 8.2/8.3/8.5 full matrix не е изпълнявана за тази фаза.

## L. Git / invariants

`git diff --check`: PASS. HEAD/branch/версията са непроменени; index е празен. Created/modified
inventory е в B. Няма commit/push/tag, DB writes, native orders, имейли или bank requests.
Няма промени по reference repositories. За financial calculator/helpers, fingerprint/hash/lock,
financing snapshot factory/repository, CP/SmartUCF payload/lifecycle и EUR proof файловете е
проверена липсата на diff спрямо baseline.

## M. Remaining risks / manual acceptance

Manual browser и live financing acceptance не са извършвани. Следващата матрица е предложение
за отделно разрешена сесия, при Classic и Hummingbird, guest и logged-in според случая:

| Област | Проверки |
|---|---|
| Product | Combinations A→B→A, quantity, zero/nonzero switches, popup close/reopen, stale responses |
| Cart | Quantity/voucher/shipping/empty-cart refresh, Step 2 invalidation, post-order final preservation |
| Consent | Трите scopes × mandatory/informational; first-only retention; stale HTML с нов mandatory |
| Checkout | Delivery/address/payment changes, form replacement, slow orderability + context switch, refresh guidance |
| Submission | Native/direct path, double click, detached forms, друг payment method по време на async проверка |
| Durable flow | Product/Cart completed replay, persisted A/POST B, recovery, една Guest Cart order с lines |
| Process 1/2 | EUR-only/origin rejection, CP/SmartUCF outcomes, без дублиране на order/send/mail |

Локално са налични Classic 3.1.1 и Hummingbird 2.0.0 (последният декларира PS от 9.1.0).
Не се претендира за runtime certification на всяка PS 9.0.x/9.1.x комбинация.
Cache push/TTL е непроменен: ново CP съгласие е authoritative след достигане на trusted snapshot.

## N. STOP GATE

Не се наложиха нови schema/lifecycle/API/SmartUCF/KOP решения, Core override или runtime dependency.
Implementation е готова за review. STOP: без commit, push, version bump или следваща фаза.
