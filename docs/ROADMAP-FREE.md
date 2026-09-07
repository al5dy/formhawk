# Formhawk Free — Product & Engineering Roadmap

**Document status:** Living roadmap containing future work only  
**Code baseline reviewed:** Formhawk 0.2.0  
**Review date:** 2026-09-01  
**Target:** a trustworthy Formhawk Free 1.0 distributed through WordPress.org  
**Product boundary:** a complete, privacy-first, local form analytics and health product for one WordPress installation

This document intentionally does not repeat functionality already shipped in Formhawk 0.2.0. The current feature list belongs in `readme.txt`, `docs/PROVIDER-SUPPORT.md`, and release changelogs. If an item below is completed, remove it from this roadmap and document it in the release notes.

---

# 1. Product north star

Formhawk Free should become the local control center for every important WordPress form.

Within five seconds, a non-technical site owner should understand:

1. whether an important form is alive;
2. whether the result is observed, provider-confirmed, or unknown;
3. where conversion is being lost;
4. which field or placement needs attention;
5. what changed and what to do next.

The winning experience is:

> Install Formhawk, visit the dashboard, and immediately see a short, honest list of form problems and opportunities — without an account, cookies, visitor profiles, or captured form values.

Millions of downloads cannot be guaranteed by a feature list. The roadmap can, however, maximize the conditions that make broad adoption possible:

- zero-configuration first value;
- support for the form builders people already use;
- numbers users can trust;
- visible privacy advantages;
- actionable conclusions instead of decorative charts;
- excellent compatibility, performance, translations, documentation, and supportability;
- a Free product useful enough to recommend without qualification.

---

# 2. Strategic rules

## 2.1 Trust before visual polish

Metric semantics, identity, deduplication, migrations, and confidence must be correct before adding more dashboards or scores.

Never present:

- a browser submit event as a confirmed backend success;
- `wp_mail_succeeded` as inbox delivery;
- unavailable provider data as zero;
- a low-sample fluctuation as a strong anomaly;
- correlation as proven causation.

## 2.2 Privacy is the differentiator

Free must continue to avoid:

- form field values;
- visitor email, name, phone, or message;
- raw IP storage;
- persistent visitor/session identifiers;
- analytics cookies or browser persistence;
- raw visitor event history;
- undisclosed external analytics transmission.

New analysis should use bounded aggregate counters whenever possible.

## 2.3 Provider reach belongs in Free

Major form-builder support is distribution, not a paywall. Pro should monetize external infrastructure, automation, advanced operations, and agency scale.

The provider order is informed by current WordPress ecosystem reach. At the 2026-09-01 review snapshot, WordPress.org reported:

- [Contact Form 7](https://wordpress.org/plugins/contact-form-7/): 10+ million active installs;
- [WPForms Lite](https://wordpress.org/plugins/wpforms-lite/): 5+ million;
- [Elementor](https://wordpress.org/plugins/elementor/): 10+ million overall installs, only a subset of which uses Elementor Pro Forms;
- [Fluent Forms](https://wordpress.org/plugins/fluentform/): 700,000+;
- [Forminator](https://wordpress.org/plugins/forminator/): 600,000+;
- [Ninja Forms](https://wordpress.org/plugins/ninja-forms/): 600,000+;
- [Formidable Forms](https://wordpress.org/plugins/formidable/): 300,000+.

These figures overlap and are prioritization signals, not an addressable-user total.

## 2.4 Actionability over information density

Every prominent card should answer at least one of:

- What happened?
- Why does it matter?
- How confident is Formhawk?
- What should the user inspect next?

## 2.5 No growth dark patterns

No forced activation redirect, undismissable review prompt, unrelated wp-admin advertising, fake urgency, or telemetry enabled by default.

---

# 3. Baseline gap assessment

The following gaps remain after the 0.2.0 provider-platform release and must guide sequencing.

| Area | Verified gap | Consequence | Release gate |
|---|---|---|---|
| Public ingestion | Body/batch/event bounds, client-event allowlists, same-origin checks, a site-bound public token and a coarse site-wide burst limit exist; distributed pollution detection remains limited | A determined distributed actor can still pollute public aggregate analytics | Hardening release |
| Dashboard scale | Overview pagination and chunked retention cleanup are bounded; very large-site query benchmarks are not yet automated | Regressions at agency-scale data volumes could be detected late | Core v2 |
| Release automation | Local Composer/npm quality gates, `.distignore` and deterministic ZIP verification exist, but CI remains to be added | Release integrity still depends on running the documented local gate | Release integrity |
| Provider compatibility lab | Real WPForms Lite HTTP lifecycles and Elementor Pro 3.35.1 API/markup compatibility are exercised; WPForms Pro and full browser Elementor Pro E2E remain unavailable | Pro-only changes still require a broader licensed compatibility matrix | Reach maintenance |
| Multisite | Activation, cron, tables, settings, and uninstall are single-site oriented | Network activation is not a proven supported path | Pre-1.0 |

This table is not a list of shipped features. It is the verified backlog rationale.

---

# 4. P0 — Release integrity and engineering foundation

No broad feature expansion should bypass this work.

## 4.1 Establish the development and release system

Remaining work:

- a canonical Git repository and documented branching/release process;
- a deterministic release ZIP builder;
- CI for minimum and current supported PHP/WordPress versions.

Release artifacts must exclude `.idea`, local settings, test output, caches, secrets, `node_modules`, `vendor` when not required at runtime, and development archives.

Acceptance criteria:

- a fresh checkout can install dependencies, build assets, test, and build a ZIP using documented commands;
- built assets match committed sources;
- the release ZIP passes Plugin Check with no release-blocking errors;
- the ZIP is inspected independently from the working directory;
- minimum PHP syntax compatibility is checked, not inferred from a PHP 8.x lint run.

## 4.2 Create the first honest automated test floor

Add before Core v2:

- unit tests for sanitizers, health rules, anomaly thresholds, and identity resolution;
- WordPress integration tests for activation, DB writes, REST ingestion, cron, settings, uninstall, capabilities, and nonces;
- JS tests for discovery, metadata extraction, privacy invariants, deduplication, batching, dynamic forms, and abandonment;
- reproducible CF7 integration fixtures;
- a browser smoke flow that verifies frontend event → REST → aggregate → dashboard.

Privacy regression tests must fail if tracking code starts using form control `.value`, `FormData`, cookies, `localStorage`, `sessionStorage`, or visitor identifiers for analytics.

## 4.3 Introduce explicit service boundaries

Split oversized responsibilities into replaceable services:

- bootstrap/container;
- provider registry;
- canonical event factory/validator;
- form identity resolver;
- placement resolver;
- aggregate writer;
- analytics queries;
- health/anomaly evaluators;
- admin controllers/views;
- migrations;
- diagnostics.

The admin layer must not own product calculations. Repositories must not decide health scores. Provider adapters must not render UI.

---

# 5. P0 — Analytics Core v2

This is the highest-priority product release.

## 5.1 Canonical evidence and outcome semantics

Replace mixed counters with explicit concepts.

| Canonical concept | Meaning | Valid evidence |
|---|---|---|
| `form_view` | Form entered the observable viewport | Browser-observed |
| `form_start` | First meaningful interaction | Browser-observed |
| `submit_attempt` | User/browser attempted submission | Browser or provider |
| `submit_success` | Provider reported successful processing | Trusted provider adapter |
| `submit_failure` | Provider reported processing failure | Trusted provider adapter |
| `validation_failure` | Browser/provider rejected one or more fields | Source-labelled |
| `spam_rejection` | Provider classified the attempt as spam | Trusted provider adapter |
| `mail_accepted` | WordPress/provider accepted a mail operation | Trusted server signal, not delivery |
| `mail_failure` | WordPress/provider reported immediate mail failure | Trusted server signal |
| `form_abandon` | Started form ended without a known terminal outcome | Browser-observed |

Every event must include:

- schema version;
- event type;
- source (`frontend`, `provider`, `wordpress`);
- evidence level (`observed`, `provider_confirmed`);
- provider ID;
- stable form definition identity;
- placement identity when known;
- bounded field metadata only when applicable;
- site-timezone analytics date;
- UTC event/health timestamp where an absolute time is stored;
- bounded duration/failure category when applicable.

`externally_verified` is reserved for Pro/Cloud and must never be fabricated locally.

## 5.2 Honest metric presentation

Use explicit labels:

- Attempts;
- Provider-confirmed successes;
- Provider failures;
- Mail accepted by WordPress/provider;
- Mail failures;
- External delivery: unavailable in Free.

Default conversion:

- confirmed start → success conversion when provider confirmation exists;
- start → attempt rate when only browser attempts exist;
- no mixed global rate across incompatible evidence without a labelled normalization rule.

Global `wp_mail_succeeded`/`wp_mail_failed` observations remain site-level mail-health facts unless a trusted provider adapter can safely attribute the operation to a specific form. Never guess that relationship from timing alone.

Unavailable metrics display `N/A` plus a short explanation, not `0`.

## 5.3 Separate definitions, placements, and fields

Target model:

- `formhawk_forms`: logical provider form definitions;
- `formhawk_form_locations`: URL/post/template placements;
- `formhawk_fields`: stable structural field definitions;
- `formhawk_form_daily`: definition-level daily aggregates;
- `formhawk_location_daily`: placement-level daily aggregates;
- `formhawk_field_daily`: bounded field aggregates.

Identity rules:

1. trusted provider ID;
2. explicit `data-formhawk-id` for generic forms;
3. stable native form ID/name only after validation;
4. bounded structural fingerprint when safe;
5. clearly flagged placement-scoped fallback when no durable identity exists.

Never silently claim that a DOM index is a stable logical form ID.

Acceptance criteria:

- one CF7 definition embedded at three URLs appears once with three placements;
- totals are not double-counted when location rows are aggregated;
- a moved form retains its logical history;
- duplicate/unstable generic IDs produce a visible diagnostics warning;
- placement cardinality is bounded against attacker-controlled paths.

## 5.4 Versioned migrations

Create a migration runner with one class per schema/data transition.

Requirements:

- idempotent;
- restart-safe;
- chunked for large datasets;
- explicit preconditions and postconditions;
- failure state visible in Diagnostics;
- DB version updated only after verified success;
- no historical data fabrication;
- recovery instructions and backups recommendation for high-risk migrations.

Historical 0.1.x conversion:

- CF7 legacy `submissions` may become provider-confirmed successes only where the old code semantics prove that mapping;
- generic HTML legacy `submissions` become observed attempts;
- ambiguous data remains labelled legacy/unknown;
- old form rows are conservatively split into definition/location records.

## 5.5 Provider capability registry

Each adapter declares:

- frontend discovery;
- stable form/field IDs;
- success/failure/validation/spam/mail signals;
- AJAX/non-AJAX support;
- multi-step support;
- dynamic rendering;
- placement attribution;
- tested provider version range.

The dashboard and scoring services consume capabilities rather than provider-name conditionals.

## 5.6 Time and date contract

Adopt:

- absolute timestamps stored in UTC;
- `stat_date` derived from the WordPress site timezone at ingestion;
- display through WordPress timezone APIs;
- tests for positive/negative offsets, DST boundaries, and timezone changes.

---

# 6. P0 — Public ingestion, tracker, and privacy hardening

## 6.1 Treat the browser token as public

The token is a routing/integrity aid, not a secret and not authentication.

Add layered controls:

- REST route argument schema;
- required JSON content type;
- strict request byte limit;
- batch count and per-event byte limits;
- strict provider/event/evidence allowlists;
- nested-object depth and field-count limits;
- path, form, placement, and field cardinality budgets;
- site-level burst control;
- optional short-lived privacy-reviewed source buckets without raw IP persistence;
- rejection reason counters with bounded labels;
- sustained-abuse diagnostics without noisy global notices;
- generic error responses that never expose SQL details.

Do not claim fraud-proof analytics.

## 6.2 Tracker correctness v2

Fix and test:

- explicit `data-formhawk-id` precedence;
- stable identity for reordered/dynamic generic forms;
- a shared observer strategy instead of one observer per form where practical;
- release of removed DOM nodes from strong references;
- provider-specific terminal states including validation, spam, abort, success, and failure;
- no abandonment after a known terminal provider outcome;
- no duplicate view/start/terminal event within one page lifecycle;
- dynamic popups, repeated mounts, back/forward cache, and long-lived SPA-style pages;
- missing `fetch`, `sendBeacon`, `IntersectionObserver`, and `MutationObserver` fallbacks;
- bounded in-memory queue with explicit loss/retry semantics;
- analytics failure never changing native form behavior.

## 6.3 Eligibility and pollution controls

Add conservative defaults and diagnostics for:

- WordPress search/login/password/comment forms;
- admin/preview/test traffic;
- checkout/account/system forms not intended as lead forms;
- duplicate generic identities;
- forms with no stable identity;
- bot/synthetic test traffic when safely identifiable.

Provide explicit include/exclude attributes and UI rules. Never inspect submitted values to decide eligibility.

## 6.4 Static metadata privacy audit

Field metadata extraction must read only structural text.

Add tests for:

- explicit labels;
- wrapping labels;
- placeholders;
- dynamic labels;
- contenteditable/custom controls;
- labels that accidentally include visitor-controlled DOM text.

If a label is unsafe or ambiguous, store a stable field key and omit the label.

---

# 7. P0/P1 — Provider platform and ecosystem coverage

No adapter is called supported until official hooks/source have been verified and its capability matrix and integration tests exist.

Contact Form 7 v2, WPForms Lite/Pro and Elementor Pro Forms shipped behind the provider registry in 0.2.0. Their current capability and verification matrix lives in `docs/PROVIDER-SUPPORT.md`.

## 7.1 Wave B — high-reach form builders

Implement and test:

- Fluent Forms;
- Forminator;
- Gravity Forms.

Gravity Forms should prove the multi-page abstraction. Forminator must not be omitted from the support plan given its current WordPress.org reach.

## 7.2 Wave C — ecosystem completeness

Implement and test:

- Ninja Forms;
- Formidable Forms;
- JetFormBuilder;
- Bricks Forms;
- Divi Forms;
- WS Form.

Later candidates, prioritized by verified demand and public API quality:

- Beaver Builder Forms;
- Everest Forms;
- Happyforms;
- native block/form solutions as they become stable.

## 7.5 Generic adapter SDK

Publish before 1.0:

- interfaces and lifecycle documentation;
- capability declaration examples;
- canonical event examples;
- provider diagnostics extension point;
- compatibility/deprecation policy;
- contract tests third-party adapters can run.

The stable API must not expose arbitrary raw-event or raw-SQL access.

---

# 8. P1 — Five-second dashboard and onboarding

## 8.1 Needs Attention first

The first screen should prioritize:

- Critical forms;
- newly detected failures;
- normal traffic with no confirmed success;
- severe conversion/validation/abandonment changes with confidence;
- stale or missing placements;
- broken tracking/provider integration;
- unresolved migration/cleanup problems.

Each item includes evidence, timeframe, confidence, and one next action.

## 8.2 Honest KPI layer

Show:

- definitions and placements monitored;
- attempts;
- provider-confirmed successes;
- confirmed conversion;
- failures;
- forms needing attention;
- evidence badges explaining metric quality.

Do not combine incompatible generic and provider-confirmed outcomes into a misleading total conversion.

## 8.3 Form inventory

Add searchable, sortable, paginated inventory with:

- logical form;
- provider and capability badge;
- placements;
- Active / Quiet / Possibly Removed / Archived state;
- last seen;
- last confirmed success;
- health and confidence;
- conversion and primary issue.

Distinguish no traffic, form removed, provider unavailable, and broken tracking.

## 8.4 Per-form workspace

Sections:

1. health summary and evidence;
2. funnel and trends;
3. placements;
4. field friction and validation;
5. outcome/failure timeline aggregated by day;
6. provider capabilities;
7. structural checkup;
8. diagnostics and data quality;
9. export/reset/archive actions.

## 8.5 First-value onboarding

Add a non-blocking checklist inside Formhawk:

- tracking runtime healthy;
- supported provider detected;
- first form/placement found;
- first view/start received;
- first provider-confirmed outcome received where supported;
- privacy summary reviewed.

Empty states must explain exactly what to do next. Do not auto-redirect on activation.

## 8.6 WordPress-native visibility

Add:

- focused Site Health tests;
- an optional wp-admin dashboard widget with critical/warning counts;
- a plugin action link to Diagnostics;
- accessible status text independent of color.

Only truly actionable critical failures may appear outside Formhawk screens.

---

# 9. P1 — Actionable local intelligence

## 9.1 Health Score with confidence

Add a deterministic 0–100 score while retaining plain-language state.

Inputs may include:

- recency/continuity of provider-confirmed success;
- provider failures and mail failures;
- conversion vs baseline;
- abandonment/validation rates;
- runtime/integration health;
- sample size and evidence quality.

Display:

- score;
- state;
- confidence (`Low`, `Medium`, `High`);
- positive and negative contributing reasons;
- unavailable inputs.

Low traffic lowers confidence rather than automatically lowering health.

## 9.2 Field Friction Score

For each stable field:

- reach/interactions;
- validation errors and rate;
- last-field abandonments and share;
- repeat-validation signal where aggregate-safe;
- hesitation time aggregate where implemented without session storage;
- friction score and confidence.

Never label a field problematic below documented minimum samples.

## 9.3 True funnel and field progression

Extend the funnel to:

- Viewed;
- Started;
- field/step reached;
- Attempted;
- Provider-confirmed;
- Failed / Abandoned.

Store aggregate reach/transition counters, not visitor journeys. Conditional and multi-step forms must show `N/A` where a linear path is not meaningful.

## 9.4 Trends and comparisons

Add lightweight accessible charts and table fallback for:

- volume;
- start/attempt/confirmed conversion;
- abandonment;
- validation;
- failures;
- completion and observed response time.

Support presets and bounded custom ranges. Compare current vs previous period and placements only when semantics/samples are compatible.

## 9.5 Deterministic anomaly detection v2

Detect locally:

- confirmed conversion drop;
- normal starts with no confirmed outcomes;
- validation spike;
- abandonment spike;
- completion-time degradation;
- disappearance of historically stable form views;
- new provider/mail failure after a healthy baseline.

Every anomaly states current value, baseline, sample size, threshold, first observed date, and confidence.

## 9.6 Form schema change detection

Track a privacy-safe structural signature built only from stable field metadata.

Use it to:

- show when fields/order/steps changed;
- reset or qualify incompatible field comparisons;
- add a chart annotation;
- compare before/after performance without claiming causation.

## 9.7 Form Checkup — a signature Free experience

Create one prioritized report combining:

- analytics health;
- provider outcome capability;
- tracking/data-quality health;
- structural UX checks;
- basic form accessibility checks;
- mail-layer facts;
- highest-confidence conversion opportunity.

Structural checks can include:

- missing/duplicate form or field IDs;
- controls without an accessible name;
- labels not associated with controls;
- missing autocomplete hints on common fields;
- inaccessible/ambiguous submit controls;
- excessive required fields or extreme field count as a heuristic;
- validation messaging that cannot be associated with fields where detectable.

This is not a full WCAG audit and must never be marketed as legal compliance.

## 9.8 Opportunity cards

Examples:

- “Phone creates 42% of high-confidence validation failures.”
- “The pricing-page placement converts 2.1× better than the footer placement.”
- “Confirmed conversion decreased after the form structure changed.”

Only deterministic evidence is used. If the sample is weak, Formhawk says so.

---

# 10. P1 — Privacy, tracking, and data controls

## 10.1 Tracking policy UI

Add controls for:

- exclude logged-in administrators by default;
- exclude selected roles;
- include/exclude forms, providers, posts, and bounded paths;
- server-only health mode with frontend behavior tracking disabled;
- preview/staging/test traffic exclusions;
- developer filters for programmatic control.

## 10.2 Consent interoperability

Provide a vendor-neutral API and documented examples for consent systems.

Support:

- start disabled until an integrator-provided consent signal;
- runtime enable/disable without reload where safe;
- documented DNT/GPC policy choices;
- server-side provider health continuing independently when configured.

Do not market Formhawk as automatically compliant with every privacy law.

## 10.3 Local exports

Stream CSV exports for:

- forms summary;
- placements;
- daily metrics;
- field metrics;
- health/anomaly explanations.

Exports require capability, nonce, bounded filters, formula-injection protection, and chunking. There is no visitor-level export because no visitor-level analytics should exist.

## 10.4 Reset, archive, and delete

Support:

- reset one placement;
- reset one form;
- reset all aggregates;
- archive/restore definitions;
- permanently delete selected analytics.

Every destructive action requires explicit confirmation, narrow scope, capability, nonce, and success/failure notice. Settings survive analytics reset.

## 10.5 Retention completeness

Add bounded cleanup for:

- daily aggregates;
- stale placements according to explicit policy;
- orphaned field/location rows;
- diagnostics counters;
- temporary caches and migration state.

Chunk cleanup and record last success/failure. Do not silently delete form definitions because traffic stopped.

---

# 11. P1 — Scale, performance, and resilience

## 11.1 Frontend budget

Targets:

- dependency-free core tracker;
- under 20 KB uncompressed unless a measured feature justifies more;
- no repeated full-DOM scans or polling;
- shared observers/listeners where practical;
- no layout thrashing;
- no synchronous/external analytics request;
- graceful failure on blocked REST or optional browser APIs.

CI should record uncompressed and compressed bundle size and fail on unexplained regression.

## 11.2 Database budget

Design and benchmark for:

- 100 forms;
- 1,000 placements;
- large field sets;
- at least one year of daily aggregates;
- high counter values;
- concurrent ingestion.

Add query-specific indexes, pagination, bounded custom ranges, chunked exports/cleanup/migrations, and query-count/latency assertions. Do not create a raw event table.

## 11.3 Failure-open guarantees

Test that failures in:

- REST;
- database writes;
- migration state;
- optional provider adapters;
- tracker APIs;
- admin analytics;

never block or alter the customer’s underlying form submission.

## 11.4 Multisite

Before claiming support, implement/test:

- normal and network activation;
- per-site tables/options/cron;
- site creation/deletion;
- safe site switching;
- network deactivation/uninstall scope;
- large-network batching.

If 1.0 remains per-site only, network activation must fail safely with clear documentation.

---

# 12. P1 — Diagnostics and supportability

Add a copyable, sanitized support report containing:

- plugin/schema version;
- WordPress/PHP version;
- table and migration status;
- scheduled cleanup and last result;
- REST reachability and recent bounded rejection counters;
- active provider adapters, capabilities, and tested compatibility;
- tracker asset/build version;
- form identity/data-quality warnings;
- multisite/runtime mode.

Never include secrets, salts, tokens, raw requests, form values, recipients, subjects, message bodies, or arbitrary provider payloads.

Add self-tests for:

- aggregate write/read;
- REST route health without polluting production analytics;
- provider hook availability;
- cron health;
- table/index shape;
- clock/timezone anomalies.

---

# 13. Adoption and WordPress.org excellence

Product quality creates recommendations; distribution work makes that quality discoverable.

## 13.1 Clear positioning

Directory copy, screenshots, and onboarding should consistently communicate:

> Know when WordPress forms stop working and where visitors give up — without storing what they type.

Do not compete as an entry database, CRM, session recorder, or generic web-analytics suite.

## 13.2 Public compatibility matrix

Publish for every provider:

- tested versions;
- frontend behavior support;
- confirmed success/failure/validation/mail support;
- multi-step/dynamic support;
- known limitations;
- last verification date.

## 13.3 International adoption

Add POT generation and translation QA. Prioritize community-ready strings and documentation for large WordPress language groups, including Spanish, German, French, Portuguese (Brazil), Italian, Dutch, Polish, Russian, Japanese, and Ukrainian.

No language-specific UI branches in source.

## 13.4 Ethical review request

If added, request a WordPress.org review only:

- after a real value milestone such as multiple confirmed outcomes or a resolved issue;
- inside Formhawk screens;
- with “Review”, “Later”, and “Never ask again”;
- with permanent dismissal;
- never after failure or immediately after activation.

## 13.5 Documentation and demo

Provide:

- five-minute start guide;
- metric/evidence glossary;
- provider guides;
- privacy and data map;
- common REST/cache/WAF troubleshooting;
- high-quality screenshots and short walkthrough;
- clearly labelled sample/demo dashboard that never mixes sample data with real analytics;
- developer adapter documentation.

## 13.6 No hidden telemetry dependency

Use WordPress.org active-install/review/support signals, opt-in research, and reproducible user tests. If product telemetry is ever proposed, it must be explicit opt-in, minimal, documented, deletable, disabled by default, and approved before implementation.

---

# 14. Suggested Free release sequence

## 0.1.2 — Release Integrity

- deterministic package and `.distignore`;
- remove hidden/development files from release artifact;
- source/build structure;
- initial PHP/JS/integration test floor;
- CI and compatibility matrix;
- verified Plugin Check gate;
- no product-semantic expansion.

Exit gate: the artifact, not only the working tree, is reproducible and release-safe.

## 0.2.0 — Analytics Core v2 and Reach I (shipped)

- canonical evidence/outcomes;
- definition/location/field model;
- versioned migration from 0.1.x;
- provider registry/capabilities;
- CF7 adapter v2;
- WPForms Lite/Pro and Elementor Pro Forms;
- UTC/site-date contract;
- REST/tracker hardening;
- paginated query foundation;
- honest dashboard labels.

Exit gate: generic and CF7 metrics can be compared without semantic ambiguity or historical fabrication.

## 0.3.0 — Hardening and compatibility lab

- paginated dashboard and chunked cleanup;
- public-endpoint burst diagnostics;
- licensed provider compatibility matrix;
- deterministic release ZIP builder and CI;
- onboarding and data-quality states.

Exit gate: minimum/current platform CI, licensed-provider compatibility runs where packages are available, and a verified release artifact.

## 0.4.0 — Reach II

- Fluent Forms;
- Forminator;
- Gravity Forms;
- multi-step aggregate foundation;
- provider compatibility lab.

## 0.5.0 — Reach III and SDK Beta

- Ninja Forms;
- Formidable Forms;
- selected builder-native integrations based on verified demand;
- third-party adapter SDK/contract tests beta.

## 0.6.0 — Intelligence

- confidence model;
- Health and Field Friction scores;
- anomaly detection v2;
- field progression;
- schema-change annotations;
- opportunity cards.

## 0.7.0 — Form Checkup and Dashboard 2.0

- Needs Attention home;
- searchable/paginated inventory;
- placement analytics;
- accessible trends and comparisons;
- structural UX/accessibility checkup;
- Site Health and optional dashboard widget.

## 0.8.0 — Controls and Data

- tracking/consent controls;
- streamed exports;
- reset/archive/delete;
- complete retention;
- support report;
- multisite implementation or explicit safe limitation.

## 0.9.0 — Public Quality Beta

- full provider/version matrix;
- minimum/current environment tests;
- accessibility and i18n audit;
- large-site benchmarks;
- migration-from-every-stable-version tests;
- real-host REST/cache/WAF matrix;
- support-driven false-positive tuning.

## 1.0.0 — Trusted Local Control Center

Launch only when:

- metrics and evidence are unambiguous;
- migrations are proven;
- high-reach providers are genuinely tested;
- dashboard prioritizes action;
- privacy invariants have automated regression protection;
- public extension contracts are documented;
- no release-blocking Plugin Check issues remain;
- critical browser and provider flows have reproducible E2E coverage;
- large-site and failure-open guarantees are demonstrated.

---

# 15. Free/Pro boundary

Free 1.0 should complete:

- provider-consistent local aggregate analytics;
- tested adapters for the major form ecosystem;
- confidence-aware health, friction, and anomaly detection;
- separate form-definition and placement inventory;
- Form Checkup;
- accessible local trends/comparisons;
- comprehensive privacy/tracking controls;
- streamed local exports and safe data-management actions;
- support-grade diagnostics and Site Health integration;
- stable provider/developer contracts.

Free should not include infrastructure-dependent services:

- remote scheduled browser submissions;
- externally verified inbox/CRM delivery;
- out-of-band alert delivery infrastructure;
- central multi-site/agency cloud dashboard;
- cross-site teams and client portals;
- white-label scheduled cloud reports;
- commercial cloud API/retention;
- enterprise SSO/SLA/data residency.

Local functionality must not be artificially disabled merely to create a Pro upsell.

---

# 16. Definition of Done

A roadmap item is complete only when applicable checks pass:

1. behavior and metric semantics are documented;
2. acceptance criteria exist;
3. privacy/security/threat impacts are reviewed;
4. provider capabilities and unavailable states are explicit;
5. backward compatibility and migration are proven;
6. PHP 7.4 and WordPress minimum compatibility are checked;
7. WPCS/static analysis/Plugin Check are reviewed;
8. PHP, WordPress integration, JS, and browser tests match risk;
9. form behavior still fails open;
10. performance and cardinality budgets are measured;
11. admin UI is accessible and translatable;
12. docs/readme/changelog/support matrix are updated;
13. built assets are reproducible;
14. release ZIP contains no development junk or secrets;
15. tests not run and known limitations are reported honestly.

---

# 17. Product success measures

Measure outcomes without making hidden telemetry a dependency:

- time from activation to first correctly identified form;
- time to first provider-confirmed outcome where supported;
- share of support cases resolved by Diagnostics report;
- false-positive rate for Critical/anomaly states;
- provider coverage of real user requests;
- migration failure rate;
- frontend request/script/CPU budget;
- dashboard comprehension in five-second user tests;
- WordPress.org active-install retention, rating quality, and support resolution;
- translations with active contributors;
- percentage of released versions passing the full compatibility matrix.

The primary quality metric is not the number of charts. It is the percentage of users who can correctly answer “Is this form working, how do we know, and what needs attention?”

---

# 18. Explicit non-goals

Do not add to Free without an intentional architecture/privacy change:

- captured partial submissions;
- visitor/session replay;
- CRM/entry storage;
- visitor fingerprinting;
- arbitrary query-parameter collection;
- raw event logs;
- promises of inbox delivery;
- AI-generated core metrics;
- automatic edits to third-party forms;
- a built-in form builder.

Formhawk should win by being the most trustworthy independent health and conversion layer across WordPress form builders, not by becoming another form builder.
