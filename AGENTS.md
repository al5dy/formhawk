# AGENTS.md — Formhawk Engineering Standard

This file is normative for all AI coding agents and human contributors working on Formhawk, including Codex, ChatGPT, PhpStorm AI integrations, CLI agents, and code-review agents.

The goal is not to produce code that merely "works." The goal is to maintain a production-grade WordPress product that is secure, extensible, privacy-first, testable, backward-compatible, understandable, and suitable for WordPress.org review.

When this file conflicts with an ad-hoc implementation shortcut, this file wins unless the project owner explicitly overrides it.

---

# 1. Project identity

**Product:** Formhawk  
**Domain:** WordPress form conversion analytics and health monitoring  
**Free distribution:** WordPress.org  
**Commercial extension:** Separate Formhawk Pro add-on and/or Formhawk Cloud  
**Current baseline:** Formhawk 0.1.x  
**Core privacy position:** No visitor form values, no analytics cookies, no persistent visitor/session identifiers in Free.

---

# 2. Non-negotiable engineering principles

Every change must optimize for:

1. correctness;
2. security;
3. privacy;
4. semantic clarity;
5. backward compatibility;
6. performance;
7. testability;
8. extensibility;
9. accessibility;
10. maintainability.

Never optimize for "fewer files" or "quick patch" at the expense of architecture.

Do not introduce abstraction for its own sake. Introduce it when it creates a real boundary, test seam, or extension point.

---

# 3. Before changing code

An agent MUST:

1. inspect the existing implementation;
2. inspect affected tests;
3. identify current data semantics;
4. identify WordPress hooks/APIs involved;
5. identify backward-compatibility impact;
6. identify privacy impact;
7. identify database/migration impact;
8. identify Free/Pro boundary impact.

Do not rewrite a subsystem before understanding why it exists.

Do not remove working behavior merely because another style is preferred.

If an external plugin integration is involved, do not invent hook names from memory. Verify the provider's official public API, documentation, or installed source before implementing.

---

# 4. Source layout

Target development structure:

```text
formhawk/
├── formhawk.php
├── uninstall.php
├── readme.txt
│
├── src/                         # PHP application source
│   ├── Bootstrap/
│   ├── Contracts/
│   ├── Domain/
│   ├── Analytics/
│   ├── Integrations/
│   ├── Http/
│   ├── Admin/
│   ├── Infrastructure/
│   └── Support/
│
├── resources/                   # editable source assets
│   ├── js/
│   │   ├── tracker/
│   │   └── admin/
│   ├── scss/
│   │   ├── abstracts/
│   │   ├── components/
│   │   ├── pages/
│   │   └── admin.scss
│   └── images/
│
├── assets/                      # generated runtime assets
│   ├── js/
│   ├── css/
│   └── images/
│
├── languages/
│
├── tests/
│   ├── Unit/
│   ├── Integration/
│   ├── E2E/
│   ├── JS/
│   └── Fixtures/
│
├── tools/
├── composer.json
├── package.json
├── vite.config.js
├── phpcs.xml.dist
├── phpstan.neon.dist
├── phpunit.xml.dist
├── .editorconfig
├── .distignore
└── AGENTS.md
```

Rules:

- `src/` is PHP source, not SCSS.
- `resources/` contains editable JS/SCSS/source graphics.
- `assets/` contains files actually enqueued by WordPress.
- never manually edit generated assets when a source file/build process exists;
- change `resources/`, run build, commit the corresponding `assets/` output when the release workflow requires built assets in the plugin;
- if compiled/minified assets are distributed, public source and build instructions must remain available in accordance with WordPress.org requirements.

---

# 5. PHP architecture

## 5.1 Namespaces

All application classes use the `Formhawk\` namespace.

Do not introduce globally named classes unless WordPress compatibility absolutely requires it.

Global constants must be prefixed:

- `FORMHAWK_VERSION`;
- `FORMHAWK_DB_VERSION`;
- `FORMHAWK_FILE`;
- `FORMHAWK_DIR`;
- `FORMHAWK_URL`.

Global functions, if unavoidable, must use the `formhawk_` prefix.

## 5.2 Dependency direction

Preferred direction:

```text
Admin / Http / Integrations
          ↓
Application / Analytics
          ↓
Domain / Contracts
          ↓
Infrastructure adapters
```

Domain logic should be as independent of rendering as practical.

Do not make Analytics classes depend on Admin classes.

Do not make provider integrations directly render UI.

Do not make repositories calculate product/business scores.

## 5.3 No giant god classes

A class should have one coherent responsibility.

Examples:

Good:
- `HealthEvaluator`;
- `FormRepository`;
- `EventIngestor`;
- `ContactForm7Adapter`;
- `RetentionService`.

Bad:
- `FormhawkManager` containing SQL, HTML rendering, hooks, REST, mail, migrations, and settings.

When a class grows because it handles multiple reasons to change, split it.

## 5.4 Service construction

Keep bootstrap explicit.

Avoid hidden service-locator calls throughout the codebase.

A small plugin bootstrap/container may construct dependencies.

Dependencies should be constructor-injected when they affect behavior and should be replaceable in tests.

## 5.5 Static methods

Use static methods for:

- pure utility functions;
- immutable calculations;
- well-defined infrastructure helpers.

Do not use static state as a substitute for dependency injection.

---

# 6. WordPress coding standard

WordPress Coding Standards are the primary PHP style standard.

Do not mechanically apply PSR-12 where it conflicts with WordPress coding conventions.

Use:

- tabs/spacing per WPCS;
- WordPress naming conventions where applicable;
- proper translator comments;
- WordPress APIs before custom equivalents when appropriate.

Every release must be checked with PHPCS configured for WordPress.

PHPCS suppression is allowed only when:

1. the warning is understood;
2. the code is safe;
3. there is a precise inline explanation;
4. the suppression targets the smallest possible scope.

Never add broad file-level ignores merely to obtain a green check.

---

# 7. Supported platform policy

The plugin header is the contract.

If header says:

- `Requires at least: 6.4`;
- `Requires PHP: 7.4`;

production code must remain compatible with those minimums until the owner intentionally changes the requirement.

Agents MUST NOT:

- use PHP syntax newer than the minimum;
- call a WordPress API unavailable in the minimum without a compatibility guard;
- raise minimum requirements silently.

When raising a minimum:

1. document why;
2. update plugin header;
3. update readme;
4. update CI matrix;
5. update changelog;
6. consider existing user impact.

---

# 8. Event architecture

Formhawk must use a canonical internal event model.

Provider-specific hooks are adapters, not analytics semantics.

Canonical concepts should include:

- form view;
- form start;
- field interaction;
- validation error;
- submit attempt;
- submit success;
- submit failure;
- mail failure;
- abandonment.

Every event must have explicit semantics.

Never use one counter to mean two different things for two providers.

If a provider cannot confirm success, represent the outcome as unknown rather than pretending an attempt is a success.

---

# 9. Provider adapter architecture

Each major form builder integration should implement a stable adapter contract.

Conceptual responsibilities:

- provider ID;
- provider display name;
- provider availability;
- capability declaration;
- hook registration;
- stable form identity;
- stable field identity;
- provider event → canonical Formhawk event translation;
- diagnostics.

Suggested capabilities:

- `frontend_tracking`;
- `server_success`;
- `server_failure`;
- `server_validation`;
- `mail_failure`;
- `multi_step`;
- `stable_field_ids`;
- `dynamic_rendering`.

Core analytics MUST NOT contain long conditionals like:

```php
if ( 'cf7' === $provider ) { ... }
elseif ( 'wpforms' === $provider ) { ... }
elseif ( 'elementor' === $provider ) { ... }
```

That logic belongs in adapters/registry.

---

# 10. External integration rule

For Contact Form 7, WPForms, Elementor, Fluent Forms, Gravity Forms, Ninja Forms, Formidable Forms, WooCommerce, or any third-party product:

1. verify current official hooks/API;
2. inspect provider source when necessary;
3. do not depend on private/internal symbols if a public API exists;
4. declare tested provider versions;
5. handle provider absence safely;
6. handle provider upgrades safely;
7. add integration tests;
8. never read field values unless a product requirement explicitly changes the privacy model.

Do not claim support from a single happy-path manual test.

---

# 11. Form identity model

Formhawk must distinguish:

## Form Definition

The logical form:

- provider;
- provider form ID;
- title.

## Form Placement

Where the form appears:

- page/path;
- post/template context where known.

Do not overwrite a logical form's only page path when the same form appears on multiple pages.

Analytics should support:

- global form totals;
- placement-specific breakdown.

Generic forms should use explicit stable IDs when possible.

---

# 12. Time semantics

Use a deliberate time strategy.

Recommended:

- absolute event timestamps: UTC;
- analytics `stat_date`: derived from WordPress site timezone at ingestion;
- display: WordPress site timezone;
- never mix server OS timezone assumptions with WordPress timezone.

DST and timezone changes must not create fatal errors.

Use WordPress date/time APIs or timezone-aware DateTime objects.

---

# 13. Database design

## 13.1 Custom tables are appropriate

Formhawk is analytics software.

Do not store high-volume metrics in:

- post meta;
- user meta;
- autoloaded options.

Use custom tables.

## 13.2 Aggregate-first

Default model is aggregate statistics.

Do not create a raw visitor event log unless the product owner explicitly changes the architecture and privacy policy.

## 13.3 SQL safety

All variable SQL values must be prepared.

Use:

- `%d`;
- `%f`;
- `%s`;
- `%i` for identifiers where supported.

Dynamic column names must come from strict internal allowlists.

Never concatenate request data into SQL.

## 13.4 Atomic counters

Counters must use atomic SQL updates/upserts.

Do not:

1. SELECT counter;
2. increment in PHP;
3. UPDATE;

when concurrent requests can race.

## 13.5 Indexes

Every query added to a high-traffic screen must be reviewed against indexes.

Do not add indexes blindly.

Explain the query pattern each index serves.

## 13.6 No unbounded queries

Admin tables must paginate when data can grow.

Exports must stream/chunk when datasets can grow.

Migrations must chunk large operations.

---

# 14. Database migrations

Database changes require explicit migrations.

Requirements:

- versioned;
- idempotent;
- restart-safe;
- safe for partial failure;
- no data fabrication;
- tested from previous stable schema;
- update DB version only after successful migration.

Do not silently reinterpret historical data.

Example:

If an old `submissions` column contains generic attempts and CF7 successes, migration logic must preserve that uncertainty rather than pretending all values are confirmed successes.

---

# 15. Privacy invariants

Current Free invariants:

Formhawk must not intentionally store or transmit:

- visitor form field values;
- visitor email;
- visitor phone;
- visitor name;
- visitor message;
- visitor IP address;
- persistent visitor ID;
- persistent session ID;
- user-agent history;
- email recipients;
- email subjects;
- email bodies.

Frontend tracking must not call `.value` for analytics purposes.

Field metadata may include:

- stable field key;
- field label;
- static placeholder when used as fallback.

Agents must review whether a label could accidentally include dynamic visitor content.

---

# 16. No cookies / browser persistence in Free

Free analytics must not create:

- analytics cookies;
- `localStorage`;
- `sessionStorage`;
- IndexedDB visitor identifiers.

Temporary state may live in JavaScript memory for the current page lifecycle.

If a future feature requires persistence, it needs explicit product/privacy approval and documentation.

---

# 17. Cloud boundary

Free must not silently transmit Formhawk analytics to Formhawk Cloud.

Pro/cloud connection must be explicit.

External services must be disclosed.

When Pro sends data:

- minimize;
- aggregate;
- authenticate;
- document;
- allow disconnect;
- never send form values.

---

# 18. Security — input

Treat all input as untrusted:

- `$_GET`;
- `$_POST`;
- `$_REQUEST`;
- `$_SERVER`;
- REST JSON;
- provider hook payloads;
- database values originally derived from external input.

Rules:

1. `wp_unslash()` where appropriate;
2. sanitize;
3. validate allowed domain;
4. reject invalid values.

Never process an entire `$_POST` or `$_REQUEST` array when only two fields are required.

---

# 19. Security — output

Escape at the final output context:

- `esc_html()`;
- `esc_attr()`;
- `esc_url()`;
- `wp_kses_post()` only for intentional safe HTML.

Do not escape early and then reuse escaped values for storage/business logic.

Stored data is not trusted merely because Formhawk stored it.

---

# 20. Security — admin actions

Every state-changing admin action requires:

- appropriate capability check;
- nonce verification;
- sanitized input;
- validated input;
- explicit success/failure feedback.

Examples:

- settings save;
- reset analytics;
- delete/archive form;
- export if it exposes protected data;
- cloud connection;
- webhook creation.

Do not rely on "the menu is only visible to admins" as authorization.

---

# 21. Security — REST

Every REST route requires an intentional permission model.

Private routes:

- capability/user auth;
- nonce when cookie-authenticated.

Public tracking routes:

- strict schema;
- strict size limits;
- allowed event types;
- sanitized metadata;
- abuse resistance;
- no privileged action;
- no secret data returned.

Never expose internal SQL errors to clients.

---

# 22. Public tracking endpoint threat model

The browser token is public.

Do not call it a secret.

Assume a malicious actor can retrieve it from a page and attempt analytics pollution.

Mitigate with:

- same-origin checks when available;
- payload constraints;
- event allowlist;
- rate limits;
- high-cardinality limits;
- rejection counters;
- anomaly monitoring.

Do not store raw IP addresses merely to implement rate limiting without explicit privacy review.

---

# 23. JavaScript rules

Frontend tracker must be:

- dependency-free unless a WordPress-provided dependency is clearly justified;
- small;
- asynchronous;
- resilient;
- non-blocking;
- compatible with dynamic DOM content.

Never:

- read field values;
- serialize `FormData`;
- intercept submission in a way that changes provider behavior;
- call `preventDefault()` for analytics;
- poll every N milliseconds for forms;
- throw uncaught errors that affect the site.

Analytics failure must never break the customer's form.

Wrap optional APIs:

- `IntersectionObserver`;
- `MutationObserver`;
- `sendBeacon`;
- `fetch`;

with graceful fallbacks.

---

# 24. Frontend event deduplication

Within a page lifecycle:

- form view once;
- start once;
- field interaction according to documented semantics;
- abandonment once;
- terminal state once.

Provider success/failure events must not cause duplicate business conversions.

Any retry mechanism must consider idempotency.

---

# 25. SCSS/CSS rules

Editable styles belong in `resources/scss`.

Generated CSS belongs in `assets/css`.

Recommended SCSS layers:

- abstracts: variables/mixins/functions;
- base;
- components;
- pages/utilities.

Do not create deeply nested selectors.

Prefer WordPress admin design conventions where appropriate.

Avoid `!important` unless required to defend a deliberate component boundary and documented.

No frontend CSS should be loaded if the plugin has no frontend UI requirement.

---

# 26. JavaScript source/build

Editable code belongs in `resources/js`.

Generated production code belongs in `assets/js`.

Use a reproducible build.

Build scripts must:

- have deterministic inputs;
- not fetch executable code at plugin runtime;
- produce human-reviewable/source-mapped output as appropriate;
- preserve public source availability.

Do not depend on a CDN for runtime plugin JavaScript libraries when WordPress provides the required library or the feature can remain dependency-free.

---

# 27. Admin UX

Primary dashboard goal:

A user understands form health in five seconds.

Prioritize:

- actionable problems;
- clear metrics;
- clear semantics;
- meaningful empty states.

Avoid:

- 20 decorative charts;
- unexplained scores;
- vanity metrics;
- aggressive upsells.

Status must never rely on color alone.

---

# 28. Accessibility

All admin UI must be keyboard usable.

Requirements:

- semantic HTML;
- heading hierarchy;
- labels for inputs;
- visible focus;
- buttons are buttons, links are links;
- status has text;
- accessible tables;
- chart data accessible as text/table;
- adequate contrast.

Accessibility warnings are not "cosmetic."

---

# 29. Internationalization

All user-visible source strings must be translatable.

Requirements:

- text domain `formhawk`;
- proper escaping translation helpers;
- translator comments immediately above strings with placeholders;
- plural-aware `_n()` where needed;
- no sentence fragments assembled in a way that translators cannot reorder;
- no hard-coded Russian/English switch in PHP.

English source; WordPress localization handles languages.

---

# 30. Performance budgets

Formhawk should be safe on business sites.

Frontend:

- no external analytics request;
- no synchronous request;
- no unnecessary dependencies;
- no field-value capture;
- no repeated full-DOM rescans.

Admin:

- no N+1 queries;
- paginate large lists;
- aggregate in SQL;
- cache only when correctness permits.

Database:

- no raw event table by default;
- indexed aggregate queries;
- bounded retention.

Every new feature affecting all frontend page loads must justify its cost.

---

# 31. Cron/background work

Use WP-Cron for local maintenance that is appropriate for WordPress.

Cron handlers must be:

- idempotent;
- safe if invoked twice;
- bounded;
- tolerant of missed schedules.

Do not assume WP-Cron fires exactly on time.

Pro cloud monitoring must not rely on customer WP-Cron for external uptime guarantees.

---

# 32. Mail semantics

Never claim:

`wp_mail_succeeded === delivered to inbox`.

Use precise wording:

- "WordPress accepted the message for sending";
- "mail operation failed";
- "external delivery verified" only when Pro external verification actually received it.

CF7 `mail_sent` is provider-confirmed success, not universal downstream mailbox proof.

---

# 33. Health and anomaly logic

Scores/alerts must be deterministic unless explicitly labeled otherwise.

Every score should have:

- inputs;
- thresholds;
- minimum sample size;
- confidence;
- explanation.

Do not label low-traffic randomness as a strong anomaly.

Tests must cover boundary conditions.

---

# 34. Free vs Pro boundary

Free remains fully useful.

Free should include:

- major provider adapters;
- local analytics;
- local health;
- local field friction;
- local basic anomaly detection;
- privacy controls;
- local exports.

Pro can include:

- remote synthetic monitoring;
- real external email delivery verification;
- advanced alert destinations;
- cloud dashboard;
- agency fleet monitoring;
- advanced segmentation;
- advanced funnels;
- scheduled/white-label reports;
- cloud API/webhooks;
- commercial infrastructure.

Do not insert disabled Pro implementations into the free WordPress.org code merely to lock them.

---

# 35. Pro add-on dependency rules

`formhawk-pro` must:

- check Free availability;
- check compatible Free version;
- show admin guidance rather than fatal error;
- never redefine Free classes;
- use public Formhawk extension contracts;
- preserve Free functionality after license expiry.

Pro activation/deactivation must not damage Free analytics tables.

---

# 36. Licensing behavior

License checks must not:

- run on frontend visitor requests unnecessarily;
- block Free analytics;
- produce fatal errors when license server is down;
- destroy settings on temporary expiry.

Cache entitlement state.

Use reasonable offline/grace behavior.

---

# 37. Testing standards

A code change is incomplete without tests appropriate to its risk.

## Unit

Use for:

- sanitizers;
- scores;
- anomaly calculations;
- value objects;
- migration transforms.

## WordPress integration

Use for:

- DB;
- REST;
- admin actions;
- nonces;
- capabilities;
- cron;
- uninstall;
- provider hooks.

## JavaScript

Use for:

- discovery;
- state machine;
- field metadata;
- batching;
- abandonment;
- dynamic forms.

## E2E

Use for critical user journeys:

- install;
- detect form;
- interact;
- validate;
- submit;
- confirm success;
- mail failure;
- dashboard update.

---

# 38. Test honesty

An agent must never say:

- "fully tested";
- "E2E passed";
- "works on WordPress";

unless that level of test was actually executed.

Report exactly what ran.

Example:

Good:

> PHP lint, JS unit tests, and WordPress integration tests passed. Browser E2E was not run.

Bad:

> Everything works perfectly.

---

# 39. Static analysis

Recommended release pipeline:

- PHPCS / WordPress Coding Standards;
- Plugin Check;
- PHP static analysis;
- PHP compatibility checks;
- JS lint/test;
- build verification.

Warnings should be reviewed, not automatically ignored.

---

# 40. Plugin Check

Official Plugin Check is a release gate for the WordPress.org package.

If it reports an issue:

1. understand the rule;
2. fix the actual problem when real;
3. use narrowly scoped suppression only for a genuine false positive;
4. add explanatory comment;
5. rerun.

Never mass-suppress Plugin Check/WPCS warnings.

---

# 41. WordPress.org review rules

Before every directory release:

- plugin remains GPL-compatible;
- no prohibited trialware;
- no undisclosed tracking;
- no executable code downloaded from third parties;
- no admin hijacking;
- no trademark misuse;
- readme is accurate;
- stable version is complete;
- current WordPress version has been tested.

WordPress.org SVN is a release repository, not the development repository.

Do not push noisy development commits to SVN.

---

# 42. Git vs SVN

Git is the primary development history.

SVN is the WordPress.org release transport.

Workflow:

1. develop/test in Git;
2. prepare release artifact;
3. validate artifact;
4. copy release files to SVN `trunk`;
5. create matching SVN tag;
6. commit release with clear message.

Never develop directly inside SVN as the canonical workflow.

---

# 43. Versioning

Use semantic versioning pragmatically.

- patch: bug/security/internal compatible fix;
- minor: backward-compatible features;
- major: significant public/API/data contract break.

Every release:

- increment plugin header version;
- update `FORMHAWK_VERSION`;
- update `Stable tag`;
- update changelog;
- update upgrade notice when useful.

All version locations must match.

---

# 44. Database versioning is separate

`FORMHAWK_DB_VERSION` changes only when schema/migration state changes.

Plugin version may change without DB version change.

Never use plugin version as an implicit migration state.

---

# 45. Public extension API

Before 1.0, hooks may be marked experimental.

At 1.0:

- document stable hooks;
- document arguments;
- maintain backward compatibility where reasonable;
- deprecate before removal.

Custom hook names must use `formhawk_` prefix.

---

# 46. Deprecation

Do not abruptly remove public APIs.

Provide:

- deprecated wrapper/hook;
- developer notice where appropriate;
- replacement path;
- changelog entry.

Remove only in an intentional major release unless security requires otherwise.

---

# 47. Logging

Free should not create noisy logs by default.

Debug logging:

- opt-in;
- sanitized;
- no form values;
- no recipients;
- no secrets;
- no license keys.

Never `error_log()` arbitrary provider payloads in production.

---

# 48. Diagnostics

Diagnostics should report facts, not guesses.

Good:

- REST endpoint reachable;
- tables exist;
- cron scheduled;
- CF7 adapter active;
- last `wp_mail_failed`.

Bad:

- "email delivery works" based only on `wp_mail_succeeded`.

---

# 49. Error handling

Analytics errors must fail open with respect to the customer's form.

If Formhawk fails:

- visitor should still be able to submit the underlying form;
- no JavaScript exception should block submission;
- no provider hook should throw fatal due to missing optional data.

Prefer guarded integration code.

---

# 50. No silent destructive behavior

Never automatically:

- delete analytics on deactivate;
- reset settings;
- drop tables;
- delete cloud site data;
- remove customer configuration.

Uninstall cleanup must respect explicit configuration and WordPress uninstall context.

---

# 51. Data reset actions

Destructive actions require:

- explicit user intent;
- nonce;
- capability;
- confirmation;
- narrow scope;
- success/failure feedback.

---

# 52. Code review checklist for agents

Before finalizing a change, review:

### Architecture
- right layer?
- duplicated provider logic?
- stable API impact?

### Security
- capability?
- nonce?
- sanitize?
- validate?
- escape?
- prepared SQL?

### Privacy
- new data captured?
- field values?
- identifiers?
- external transmission?

### Performance
- frontend cost?
- query count?
- index?
- unbounded loop?

### Compatibility
- minimum PHP?
- minimum WordPress?
- provider version?

### UX
- empty state?
- error state?
- accessibility?
- translatable?

### Tests
- unit?
- integration?
- E2E?
- migration?

---

# 53. Required workflow for a new form-provider integration

1. research provider public lifecycle;
2. write capability matrix;
3. define identity;
4. define field identity;
5. define success semantics;
6. define failure semantics;
7. define validation semantics;
8. define AJAX behavior;
9. define dynamic rendering behavior;
10. implement adapter;
11. map to canonical events;
12. add diagnostics;
13. add unit tests;
14. add WordPress integration tests;
15. add manual/E2E scenarios;
16. update support matrix;
17. update readme/changelog.

Do not modify analytics core to "special case" the provider if an adapter can solve it.

---

# 54. Required workflow for a DB schema change

1. write target schema;
2. document old semantics;
3. document new semantics;
4. define migration;
5. define rollback/recovery strategy;
6. write migration test from actual prior schema;
7. benchmark migration with large synthetic dataset;
8. make migration idempotent;
9. update DB version;
10. update diagnostics;
11. update uninstall;
12. update documentation.

---

# 55. Required workflow for a frontend-tracker change

1. define event/state transition;
2. prove no field values are read;
3. test static form;
4. test dynamic form;
5. test multiple forms;
6. test repeated focus/input/change;
7. test validation;
8. test navigation/pagehide;
9. test provider success/failure lifecycle;
10. test missing observer APIs;
11. test REST failure;
12. measure bundle size.

---

# 56. Required workflow for an admin feature

1. define capability;
2. define nonce;
3. sanitize input;
4. validate allowed values;
5. use Settings API/admin-post patterns where appropriate;
6. escape output;
7. add accessible labels;
8. add success/error notice;
9. test insufficient permissions;
10. test invalid nonce;
11. test malformed input.

---

# 57. Release artifact policy

The release ZIP should contain only files needed by users plus source/build material required for transparency.

Never include:

- `.git`;
- IDE caches;
- `node_modules`;
- test coverage output;
- local `.env`;
- secrets;
- temporary archives;
- OS metadata;
- developer database dumps.

Tests may remain in the public repository even if excluded from production ZIP.

If compiled assets are distributed without source inside the ZIP, the public source/build location must be clearly maintained. Prefer including manageable source/build files.

---

# 58. Build reproducibility

A fresh checkout must be able to produce the release assets using documented commands.

Recommended commands:

```bash
composer install
npm ci
npm run build
composer test
npm test
```

Exact commands may evolve, but they must be documented and deterministic.

Do not rely on manually edited generated files.

---

# 59. Secrets

Never commit:

- API keys;
- SMTP passwords;
- cloud HMAC secrets;
- signing private keys;
- license server secrets;
- database passwords.

Use environment configuration for development services.

Redact secrets from diagnostics and tests.

---

# 60. Naming

Use names that describe business meaning.

Good:

- `$confirmed_successes`;
- `$form_location_id`;
- `$failure_code`.

Bad:

- `$data2`;
- `$tmp`;
- `$thing`.

Short variable names are acceptable only for tiny conventional scopes.

---

# 61. Comments

Comments explain **why**, not obvious syntax.

Good:

> Atomic upsert prevents lost increments under concurrent form events.

Bad:

> Increment counter.

Translator comments are mandatory where placeholders require context.

---

# 62. Documentation

When behavior changes, update:

- code docs where useful;
- readme;
- changelog;
- roadmap if scope changed;
- developer hooks documentation;
- migration notes.

Do not let WordPress.org readme advertise functionality that does not exist.

---

# 63. Product semantics are API

Names such as:

- Submission;
- Confirmed;
- Delivered;
- Healthy;

have business meaning.

Changing their definition is effectively a product/API change.

Agents must treat metric semantics with the same care as PHP public methods.

---

# 64. Never fabricate certainty

Formhawk should distinguish:

- observed;
- provider-confirmed;
- externally verified;
- unknown.

Examples:

Generic browser submit:
- observed attempt.

CF7 server success:
- provider-confirmed success.

`wp_mail_succeeded`:
- WordPress send accepted.

Formhawk Pro verification mailbox:
- externally verified delivery.

Never collapse these into one vague "success."

---

# 65. Privacy-safe product design

When proposing a feature, first ask:

> Can this be solved with aggregates instead of visitor-level storage?

Prefer aggregates.

If product value genuinely requires a new identifier or data category, stop and obtain explicit architectural approval before coding.

---

# 66. AI feature rule

Do not add AI merely because it is fashionable.

AI is acceptable for:

- summarizing deterministic analytics;
- explaining detected changes;
- suggesting investigation steps.

AI must not:

- invent metrics;
- calculate core numbers unreliably;
- receive visitor form values;
- execute generated code on the site;
- silently send data to third parties.

---

# 67. Dependency policy

Prefer:

1. WordPress APIs;
2. WordPress-bundled libraries;
3. small well-maintained dependencies only when justified.

Before adding dependency:

- license compatible?
- maintained?
- security history?
- bundle size?
- PHP compatibility?
- WordPress already provides equivalent?

Do not vendor a duplicate copy of a library already provided by WordPress where prohibited.

---

# 68. Backward compatibility

Existing analytics/settings should survive updates.

Before changing:

- option names;
- table names;
- public hooks;
- provider identifiers;
- metric semantics;

define migration/compatibility behavior.

Do not require users to reinstall the plugin to fix schema.

---

# 69. Admin permissions

Default sensitive management capability should be deliberate, typically `manage_options` unless a narrower custom capability is introduced.

If agencies need delegated access later, introduce explicit Formhawk capabilities rather than weakening all checks.

---

# 70. Multisite

Never assume single-site globals are sufficient.

When touching activation, uninstall, cron, or tables, consider:

- network activation;
- site switching;
- per-site prefix;
- deletion scope.

If a feature is not multisite-supported yet, fail safely and document the limitation.

---

# 71. Large-site mindset

Code should be reasonable for:

- hundreds of forms/placements;
- a year of daily aggregates;
- large field sets;
- high frontend traffic.

Avoid design choices that are fine only for a test site.

---

# 72. Acceptance criteria are mandatory

For non-trivial tasks, an agent should define acceptance criteria before or while implementing.

Example:

> A CF7 form embedded on two URLs appears as one form with two placements, and each placement reports independent conversion without double-counting the form total.

This is better than "add placement analytics."

---

# 73. Bug-fix rule

For a real bug:

1. reproduce;
2. add failing regression test where practical;
3. fix root cause;
4. prove regression test passes;
5. inspect adjacent paths;
6. document behavior change.

Do not only patch the visible symptom.

---

# 74. Performance regression rule

If a change affects all frontend pages:

- measure script size;
- measure requests;
- inspect DOM work;
- avoid new external calls.

If a change affects dashboard queries:

- inspect SQL;
- inspect indexes;
- test realistic volume.

---

# 75. Agent response/reporting standard

After coding, report concisely:

- what changed;
- key architectural decisions;
- files changed;
- migrations;
- tests executed;
- tests not executed;
- known limitations;
- artifact path/version.

Do not hide failures.

Do not claim work that did not run.

---

# 76. Codex-specific operating rule

Codex or another autonomous coding agent should:

- read this `AGENTS.md` first;
- inspect repository status;
- avoid overwriting unrelated user changes;
- make scoped commits/patches;
- run relevant tests;
- leave repository buildable;
- never publish to WordPress.org SVN without explicit instruction;
- never rotate production credentials;
- never change licensing/product boundaries without explicit instruction.

---

# 77. PhpStorm/AI assistant rule

When working interactively in PhpStorm:

- follow project formatting/WPCS configuration;
- do not accept an IDE quick-fix that weakens escaping/sanitization;
- do not rename public hooks/classes without compatibility review;
- use inspections as input, not unquestioned truth;
- run the same repository checks as CLI agents before release.

---

# 78. ChatGPT coding rule

When ChatGPT is asked to implement Formhawk changes:

- use actual repository/files as source of truth;
- do not infer unseen code;
- verify external hooks when current accuracy matters;
- generate complete patches/files, not pseudo-code, when implementation is requested;
- preserve project conventions;
- test produced artifacts where tools permit;
- explicitly disclose limits of testing.

---

# 79. Definition of Done — repository change

A production code change is DONE only if applicable items pass:

- [ ] architecture boundary respected;
- [ ] data semantics documented;
- [ ] privacy reviewed;
- [ ] security reviewed;
- [ ] minimum PHP compatible;
- [ ] minimum WordPress compatible;
- [ ] WPCS/PHPCS passed or justified;
- [ ] Plugin Check reviewed;
- [ ] PHP tests passed;
- [ ] JS tests passed;
- [ ] build passed;
- [ ] migration tests passed;
- [ ] E2E passed for critical flow, or explicitly not run;
- [ ] i18n complete;
- [ ] accessibility considered;
- [ ] readme/changelog updated;
- [ ] version updated when releasing;
- [ ] release ZIP inspected.

---

# 80. Definition of Done — WordPress.org release

Before SVN commit:

- [ ] Git release is finalized;
- [ ] plugin header version correct;
- [ ] `Stable tag` matches;
- [ ] `Tested up to` reflects a version actually tested;
- [ ] Plugin Check has no unresolved release-blocking errors;
- [ ] current WordPress version manually/automatically tested;
- [ ] minimum supported environment smoke-tested;
- [ ] assets built;
- [ ] ZIP structure valid;
- [ ] no secrets/dev junk;
- [ ] changelog complete;
- [ ] SVN trunk contains release;
- [ ] SVN tag matches release version;
- [ ] WordPress.org `/assets` are separate from plugin runtime `trunk/assets`.

---

# 81. Final standard

A Formhawk change is considered high quality when another senior engineer can:

- understand the architecture without reverse-engineering hidden assumptions;
- test it independently;
- extend it without modifying unrelated core logic;
- trust its metric semantics;
- trust that it does not collect hidden visitor data;
- upgrade from the previous release without data loss;
- run it on a busy WordPress site without unreasonable overhead.

Build Formhawk as a long-lived product, not as a collection of patches.
