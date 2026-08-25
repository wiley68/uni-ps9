# AGENTS.md

# Инструкции за AI агент (Cursor / Codex)

## Език

- Отговаряй на **български**, освен ако потребителят изрично поиска друг език.
- Имена на класове, namespaces, methods, properties, variables, database columns и други code identifiers да бъдат на **английски**.
- Техническите коментари в кода да следват езика и стила на съседния код.

---

## Проект

Това repository съдържа native **PrestaShop 9.x module** за:

**UniCredit Credit Calculator / Покупки на кредит**

Repository:

```text
wiley68/uni-ps9
```

Module technical name:

```text
unipayment
```

Работната директория е директно в тестов PrestaShop installation:

```text
/var/www/presta9.avalonbg.com/modules/unipayment
```

Repository root е едновременно и **PrestaShop module root**.

Няма отделен local build/deploy workflow. Промените в repository-то променят директно модула в тестовия магазин.

---

# Постоянни правила

Тези правила важат за всяка задача, освен ако потребителят изрично не ги отмени.

1. **Само uni-ps9 е writable.** Работи и променяй файлове единствено в това repository.
2. **uni-ps8 е read-only source of functional truth.** Използвай `/var/www/presta8.avalonbg.com/modules/unipayment` (`wiley68/uni-ps8`) за business semantics, naming, namespace и repository conventions. Не го променяй.
3. **Control Panel е read-only**, освен ако задачата изрично не разреши CP промяна. CP: `/var/www/uni.avalonbg.com` (`wiley68/uni.avalonbg.com`).
4. **jet-ps9 е read-only PS9 selector/event reference.** Използвай `/var/www/presta9.avalonbg.com/modules/creditjet` (`wiley68/jet-ps9`) само за PrestaShop 9 module conventions. Не копирай неговата business logic или jQuery frontend architecture.
5. **Не копирай механично repository-та.** Пренасяй семантика, не структура заради самата структура.
6. **Запазвай PS8 business semantics.** uni-ps9 е PS9-native adapter/port, не rewrite на продукта.
7. **PHP production baseline е 8.1.** `composer.json` изисква `>=8.1 <8.6`. Не въвеждай синтаксис, който изисква PHP 8.2+.
8. **Поддържай PrestaShop 9.0.x и 9.1.x.** Compat bounds: `min 9.0.0`, `max 9.99.99`. Не използвай `_PS_VERSION_` като max и не претендирай за PrestaShop 10+.
9. **Поддържай Hummingbird и Classic.** Front-office поведението трябва да работи и в двата теми семейства, когато има front-office функционалност.
10. **Front-office UniPayment JS не трябва да зависи от jQuery.**
11. **Не мигрирай legacy controllers към Symfony без доказана нужда.** Предпочитай PrestaShop module front controllers, освен ако native PS9 механизъм го изисква.
12. **Няма Core overrides**, освен ако не са изрично одобрени.
13. **Реализирай само поисканата фаза.** Не започвай следваща фаза, дори текущата да изглежда завършена.
14. **Спри на всеки STOP gate.** Не продължавай автоматично.

---

# Задължителен проектен контекст

Преди значима задача прочети:

```text
docs/ARCHITECTURE.md
docs/TESTING.md
```

Ако съществува implementation plan за текущата фаза, прочети и него.

Не преминавай към следваща фаза без изрично указание от потребителя.

---

# Reference repositories

## uni-ps8 — functional source of truth

```text
wiley68/uni-ps8
/var/www/presta8.avalonbg.com/modules/unipayment
```

Използвай го за:

- module naming и namespace;
- business behavior;
- Control Panel communication;
- calculator / cart / checkout / order semantics;
- test organization;
- documentation style.

Не копирай PS8 implementation детайли, които са PS8-specific, ако PS9 има native еквивалент.

## jet-ps9 — PS9 selector/event reference

```text
wiley68/jet-ps9
/var/www/presta9.avalonbg.com/modules/creditjet
```

Използвай само за PrestaShop 9 module conventions (hooks, container, BO patterns), когато е полезно.

## Control Panel

```text
wiley68/uni.avalonbg.com
/var/www/uni.avalonbg.com
```

CP е source of truth за configuration и communication contract. Не променяй CP contract или business logic, освен при изрично одобрение.

---

# Архитектурни принципи

Изграждай **native PrestaShop 9 module**.

Предпочитай:

- PrestaShop hooks;
- `PaymentModule`;
- `PaymentOption`;
- module front controllers;
- Symfony services, когато са подходящи;
- namespaced PHP classes в `src/`;
- отделни services;
- repositories за persistence;
- Smarty templates само за presentation;
- отделни JS/CSS assets без jQuery зависимост.

Избягвай:

- Core overrides;
- глобални utility функции без необходимост;
- business logic в templates;
- API calls от templates;
- SQL в templates/controllers;
- огромни monolithic classes;
- копиране на CreditJet/jQuery architecture;
- механично копиране на PS8 файлова структура „за по-късно“.

Правило:

```text
uni-ps8 = functional source of truth
uni-ps9 = PS9-native adapter/port
```

Логическо разделение, към което да се стремим в по-късните фази:

```text
PrestaShop integration
        ↓
Application/domain
        ↓
Infrastructure
```

---

# Secrets и чувствителни файлове

Никога не commit-вай:

- real secret keys;
- Bearer tokens;
- private SSL keys;
- certificates, ако не са изрично предназначени за repository;
- certificate passwords;
- production credentials;
- `.env`;
- local secret configuration.

Не показвай secrets в logs, errors, debug output, screenshots или generated documentation.

---

# Работа директно в тестова среда

Repository-то се намира директно в работещ PrestaShop test installation.

Не изпълнявай destructive операции без изрично разрешение.

## Забранено без изрично разрешение

Не изпълнявай:

```text
DROP TABLE
TRUNCATE
database reset
mass DELETE
PrestaShop reinstall
shop reset
destructive cleanup
```

Не изтривай orders, customers, products, categories или shop data, освен при изрично указание.

---

# Git правила

1. Работи само по необходимия scope.
2. Не прави несвързан refactoring.
3. Не създавай Git commit, освен ако потребителят изрично го поиска.
4. Не push-вай автоматично.
5. Не създавай branch автоматично.
6. Не reset-вай или rewrite-вай Git history.
7. Не използвай destructive Git commands без изрично разрешение.
8. Не променяй reference repository-тата.

Преди завършване на задача показвай кои файлове са:

```text
created
modified
deleted
```

Deleted files трябва да бъдат изрично обяснени.

---

# Работа по фази

Изпълнявай само текущо зададената фаза.

Ако е възложен Phase N, не започвай Phase N+1.

## След реализация

Предостави:

- created / modified / deleted files;
- какво е реализирано;
- какво е проверено;
- risks / differences;
- STOP GATE статус.

Спри на STOP GATE. Не продължавай автоматично.

---

# Кога задължително да спреш

Спри и поискай решение, ако откриеш необходимост от:

- промяна в Control Panel API contract;
- промяна в SmartUCF contract/payload;
- промяна на KOP business logic;
- промяна на established order lifecycle;
- Core override;
- нова external runtime dependency;
- destructive database operation;
- премахване на съществуващо reference behavior;
- security compromise;
- съхраняване на secret по небезопасен начин;
- неяснота, която може да промени business behavior.

---

# CLI тестове и Intelephense

Локалните test doubles (`Configuration`, `PhpEncryption`, fake transports) трябва да имат **explicit PHP 8.1 type hints** на параметри и return types.

Иначе Intelephense докладва шум като `Parameter $key has no type information available`.

Виж също: `.cursor/rules/php-test-stubs.mdc`.

---

# Текущо състояние

Phase 7 е **product popup identity / guarded submission** (Hummingbird + Classic).

Разрешено:

- всичко от Phase 0–6;
- `unipayment_popup_submission` + `PopupSubmissionRepository` / operation guard;
- `productpopup` actions: `calculate`, `issue_submission_token`, `validate_step2`, `apply` (identity only), `preselect`.

Не въвеждай cart hooks, PaymentOption, financing snapshot install, checkout lock, order attempts, SmartUCF outbound, emails, custom order states или advertising FO, докато съответната фаза не бъде изрично възложена.

Apply в Phase 7 приключва на `identity_accepted`. Не създава PrestaShop/CP поръчка.

---

# Основно правило

Не работи като автономен собственик на проекта.

Работи като engineering agent, който:

```text
анализира
→ предлага
→ реализира определения scope
→ проверява
→ обяснява
→ спира за review
```

Потребителят запазва контрола върху архитектурата, business решенията и преминаването между фазите.
