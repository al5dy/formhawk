# Formhawk Autopilot CRO

## Product contract

Autopilot is a local aggregate decision loop with short-lived replay-protection state:

`analyze → rank opportunity → create hypothesis → generate a safe runtime variant → experiment → guard → decide → promote → monitor → continue`

The provider definition is always the source of truth. Formhawk does not write Contact Form 7 configuration, WPForms definitions, Elementor documents, submitted fields, CRM fields or email configuration. Disabling Formhawk removes the runtime layer and leaves the original form unchanged.

Modes have real execution semantics:

- **Observe** creates a suggestion but sends no experimental traffic.
- **Approve** creates a ready experiment and waits for an administrator to start it. This is the default.
- **Full Autopilot** starts, stops, promotes, monitors and rolls back only with protected server-confirmed evidence. Generic HTML cannot use browser-observed submits for autonomous decisions; its experiments remain available for manual start/promotion/rejection.

Only one meaningful mutation may be active for a form. A promoted mutation becomes the next baseline layer; the following experiment adds one mutation to it.

## Architecture

- `OpportunityDetector` reads existing 30-day aggregates and ranks deterministic opportunities by impact, confidence, risk, sample and estimated upside.
- `HypothesisEngine` converts the selected structural signal into a reproducible explanation.
- `VariantGenerator` and `MutationRegistry` build one control and one candidate through independent strategies.
- `ExperimentRepository` owns normalized storage, atomic aggregate upserts, baseline ancestry, transactional promote/rollback transitions, immutable decisions and per-form locks.
- `CROConfigController` performs page/form-instance assignment from a cache-neutral, no-store REST response; it registers the context and counts the server-selected arm before returning a mutation.
- `ContextStore` atomically admits bounded client lifecycle transitions and their aggregate increments. `DecisionEvidence` separates server-confirmed decision authority from client-observed advisory telemetry.
- `variant-engine.js` classifies the live provider DOM and applies or atomically restores mutations. Invalid, corrupt or unsupported configurations fail open to control.
- `RequestContext` verifies the signed, issued, unexpired structural marker and removes it from `$_POST` and `$_REQUEST` before provider processing.
- `AttributingEventRecorder` joins trusted provider outcomes to an arm after the normal analytics write succeeds.
- `StatisticalEngine`, `WinnerSelector` and `GuardrailEvaluator` make deterministic decisions; no LLM selects a winner.
- `AutopilotManager` is an hourly, idempotent state machine protected by a non-blocking MySQL advisory lock.
- `CacheCoordinator` requests WP Rocket/LiteSpeed purges and exposes `formhawk_cro_runtime_changed` for other caches/CDNs.

Frontend code and CSS are enqueued only when the current known placement has an active experiment or promoted baseline. A cached page remains shared: assignment is made by the lightweight same-origin runtime, never by visitor-specific server HTML.

## Storage and migration 5

Migration 5 creates:

- `formhawk_cro_forms`: mode, safety budget, current/previous runtime baseline and optional lead value;
- `formhawk_experiments`: hypothesis, evidence, status, policy and versioned algorithm metadata;
- `formhawk_variants`: two-arm configurations and traffic weights;
- `formhawk_experiment_daily`: desktop/mobile daily aggregate evidence only;
- `formhawk_optimization_history`: append-only decision records.

Indexes serve active form/status lookup, the bounded evaluation queue, two-arm daily aggregation and form history. Migration 5 is additive, idempotent and verified before the DB version advances. It deliberately does not copy historical analytics: traffic before assignment cannot honestly be attributed to control or variant.

## Mutation safety

| Mutation | Automatic behavior | Safety boundary |
|---|---|---|
| Field order | moves one independent optional SAFE field later | stable field key, common parent, no conditional graph, runtime restore anchors |
| Progressive disclosure | wraps selected optional SAFE units in accessible `<details>` | never required, legal, security, payment, upload or conditional fields |
| Multi-step | groups a long safe form, adds progress, Back/Next and focus/keyboard handling | every field appears once; ambiguous graphs fail closed; final button remains provider submit |
| Submit button | changes visible CTA copy only | bounded honest copy; original control retained |
| Label presentation | presentation class only | does not change label text or associations |
| Placeholder presentation | presentation class only | does not change field meaning or value |

The server excludes field metadata resembling passwords, authentication, payment, OTP, CAPTCHA, CSRF/nonces, terms/privacy/GDPR/consent, signatures and files. The runtime independently classifies fields as SAFE, CAUTION, PROTECTED or FORBIDDEN. Required fields are PROTECTED. Any conditional marker or ambiguous wrapper makes structural mutation unsafe. Hidden provider fields are never moved. Runtime exceptions restore all anchors/classes/text, remove experimental attribution and use control. There is no alternate usable control token: issuing both arms would let a caller select which arm to report. The failed assigned arm keeps its server-issued exposure and can receive one advisory JS error.

The dependency recognizer intentionally declines mutations when it cannot prove independence. Safety has priority over experiment volume.

## Provider CRO capability matrix

| Capability | CF7 | WPForms | Elementor Pro Forms | Generic HTML |
|---|---:|---:|---:|---:|
| CTA presentation | Yes | Yes | Yes | Yes |
| Safe field order | Runtime-verified | Runtime-verified | Runtime-verified | Runtime-verified |
| Progressive disclosure | Runtime-verified | Runtime-verified | Runtime-verified | Runtime-verified |
| Multi-step | Runtime-verified | Runtime-verified | Runtime-verified | Conditional/runtime-verified |
| Provider-confirmed success | Yes | Yes | Yes | No |
| Primary metric | confirmed conversion | confirmed conversion | confirmed conversion | observed submit rate |

Generic browser submit is never relabelled as confirmed conversion. Provider adapters retain the evidence semantics in `PROVIDER-SUPPORT.md`.

## Attribution and privacy

ContextSigner v2 contains experiment ID, variant ID, Formhawk form ID, provider, provider form ID, desktop/mobile segment, `iat`, `exp` and `jti`. The JTI is 16 bytes from `random_bytes()`, encoded as 22 URL-safe characters. HMAC is derived from the WordPress auth salt and site URL; signatures, exact claim shape, canonical JTI encoding and a 300–86,400 second lifetime are verified. V1 tokens are rejected, including on cached/open pages. For CF7/WPForms/Elementor the token is a short-lived technical submission marker, not visitor authentication or a visitor identifier, and is removed before provider code builds entry, mail or CRM collections. Generic HTML never receives a hidden marker because it has no confirmed server attribution and Formhawk must not alter an arbitrary submission payload.

Since 0.5.1, successful AJAX completion allows a later independent submission from the same form to record another `attempt` and `latency`. Duplicate submits before the response and duplicate terminal callbacks remain deduplicated. A successful terminal state suppresses pagehide abandonment until new editing/validation or submission. Views/starts and `_formhawk_cro` stay in the same page lifecycle and experiment arm; only the separate Field ROI `_formhawk_submission` marker rotates. Failed responses continue to allow retries and abandonment under the existing CRO semantics.

The browser keeps assignment only in memory for the current page/form lifecycle. The server stores its SHA-256 JTI hash, structural attribution, UTC expiry and bounded counters in `formhawk_cro_contexts`, never a raw token/JTI or event payload. Formhawk creates no cookie, local/session storage, IndexedDB record, IP dimension, fingerprint or persistent session/visitor ID. It never stores submitted names, email, phone, message, uploads, recipients, subjects or bodies and uses no third-party CRO service.

The public CRO endpoints accept exact shallow schemas, bounded strings and request bodies. Explicit cross-site Origin, fallback Referer or `Sec-Fetch-Site: cross-site` is rejected; missing optional headers remain acceptable for privacy-compatible browsers. Headers are defense in depth, not authentication: non-browser clients can forge them. Site-wide limits remain 600 config / 1,200 event requests per minute by default, filtered through `formhawk_cro_ingestion_limits`.

### Atomic client lifecycle (0.5.2)

- `view`, `start`, `client_validation`, `js_error` and `abandon` are at most once per issued context. Validation and abandonment require a start; abandonment is rejected during an in-flight or successfully completed attempt.
- `attempt` carries a consecutive integer `attempt` (1–50). The previous attempt must have terminated. Duplicates and gaps never increment counters.
- `latency` carries that attempt number, integer `latency_ms` (0–300,000) and boolean `successful`. It is accepted once for the current attempt, records a terminal state and permits a subsequent independent submission.
- Generic-only `observed_submit` is accepted once for its current attempt. Both observed submits and latency samples are bounded by attempts; neither is confirmed conversion evidence.
- Editing after success emits one bounded `resume` for the completed attempt, with no aggregate increment. This permits genuine later abandonment without repeating start/view. Focus/reset/pagehide alone do not resume a successful lifecycle.
- Frontend requests are serialized per context in a bounded memory queue. Analytics delivery never intercepts or blocks provider submission. Multiple DOM instances, even with the same provider form ID, receive distinct contexts through the response's `form_index` mapping.

Issuance plus `assignments += 1` commits in one transaction. Event admission uses `SELECT ... FOR UPDATE` on the issued row; its flags and daily aggregate update commit or roll back together. The registry and aggregate table must both be InnoDB. Unknown, expired, duplicate and invalid lifecycle events cannot increment aggregates. A valid signature alone is insufficient.

The registry is capped at 50,000 rows per site under a fixed site issuance lock. Contexts expire with the token; issuance deletes up to 1,000 expired rows, and 15-minute WP-Cron cleanup deletes up to 5,000 per batch, scheduling a one-minute continuation for a backlog. Hosts disabling WP-Cron must provide system cron; the hard capacity cap still prevents unlimited growth if maintenance stops. Failed admission leaves the original form intact. No random JTI becomes a permanent budget-store key.

Diagnostics are fixed-key daily counters: `replayed_context_event`, `invalid_context_lifecycle`, `unknown_context`, `expired_context`, `duplicate_view`, `duplicate_start`, `duplicate_js_error`, `event_without_attempt`, `cro_integrity_warning`, plus existing request/storage counters. No diagnostic includes tokens, JTI, IP, field values or arbitrary request strings.

Migration 7 adds `assignments` without changing `views`, and marks existing experiments `integrity_version=1`. They remain reportable and manually controllable, but cannot make automatic terminal decisions from mixed legacy evidence. Newly created version-2 experiments use protected assignment cohorts; historical assignments are never inferred from views. See [migration and recovery](MIGRATIONS.md#version-6-to-7-formhawk-052).

## Statistical methodology

Algorithm `beta-binomial-1.0` uses independent Jeffreys priors, `Beta(0.5, 0.5)`, for the two Bernoulli arms. It reports posterior means, 95% credible intervals, probability that variant is better, relative/absolute expected lift and expected loss. Small samples use deterministic numerical Beta integration; sufficiently large samples use the normal approximation to the posterior difference. Page-lifecycle randomization is cache-neutral and does not create a persistent identity; repeated visits can therefore enter different arms, an intentional privacy tradeoff documented below.

A winner requires every gate:

- minimum server-issued assignments per arm (policy keys retain `minimum_views_per_variant` for compatibility; stored browser `views` keep their reporting meaning);
- minimum total conversions;
- minimum runtime;
- posterior probability threshold;
- maximum expected loss;
- minimum absolute effect.

Credible harm rejects a variant. Maximum runtime without sufficient evidence is inconclusive. Allocation remains fixed at control 50% / variant up to the configured 50%; adaptive allocation is intentionally not enabled because correctness is preferred over premature bandit behavior. Cumulative improvement compounds non-rolled-back baseline-relative lifts as `Π(1 + lift) - 1`; percentages are never added. Rolling back a winner excludes its lift from the displayed current cumulative impact.

Conservative, Balanced and Aggressive presets change real sample, runtime, posterior, loss and monitoring thresholds. Filters remain bounded by hard floors: at least 100 assignments per arm, 20 confirmed conversions and 3 days. Conversion statistics, provider guardrails, segment checks, promotion monitoring and autonomous Field ROI business-value cohorts use server-issued assignment denominators. Browser views remain observational reporting. Policy snapshots and `algorithm_version`/`policy_version` remain with each immutable decision.

## Guardrails and rollback

Guardrails are evaluated globally and, once each segment has enough sample, independently for desktop/mobile. A variant routes to control and is rejected on:

- credible confirmed-conversion harm;
- excessive provider/mail failures;
- provider-confirmed validation explosion;
- corrupt/missing configuration or promotion storage failure.

Browser/client validation, CRO application/JS errors and browser latency produce a bounded review warning with `CLIENT_OBSERVED` evidence. They do not route traffic, pause/reject experiments or roll back baselines on their own. `SERVER_CONFIRMED` provider signals retain automatic safety authority. Client warnings do not mask an independently triggered trusted provider guardrail.

After promotion, the winner receives 100% of new assignments while post-promotion conversion is compared with the experiment control baseline. Credible relative regression restores the exact previous baseline from immutable decision ancestry, records rollback and purges compatible caches. Promote and rollback each update baseline, experiment status and traffic in one database transaction; a partial write cannot deploy a rejected mutation. Two cron workers cannot decide one form concurrently because evaluation uses a per-form database advisory lock. Traffic/storage failures fail open to the original form or last validated baseline.

## Deterministic score and value

Form Optimization Score v1 combines normalized evidence: conversion 40%, completion 25%, validation 20% and field friction 15%. Confirmed providers use confirmed conversion/provider validation; Generic uses observed submit/browser friction and stays labelled observed. The 15% conversion normalization ceiling is policy, not fabricated evidence; confidence derives only from sample size.

Estimated additional conversions derive from current confirmed conversions and compounded non-rolled-back lift. Generic HTML instead shows estimated additional **observed submits** and never converts them to lead value. Estimated value appears only for a confirmed provider after an administrator supplies average lead value and equals `additional confirmed conversions × lead value`; it is not claimed revenue.

## Extension API

Filters are experimental before 1.0 and receive only structural/aggregate data:

| Filter | Arguments | Return contract |
|---|---|---|
| `formhawk_cro_opportunities` | ranked opportunities, form metadata | ranked structural opportunities |
| `formhawk_cro_mutation_types` | strategies keyed by type | `MutationInterface` strategies |
| `formhawk_cro_variant` | candidate mutation, form, opportunity | one bounded structural mutation |
| `formhawk_cro_assignment` | selected arm, eligible two arms | one eligible arm or `null` |
| `formhawk_cro_guardrails` | policy, control aggregates, variant aggregates | complete guardrail policy |
| `formhawk_cro_statistical_policy` | policy, preset name | complete policy; hard floors are re-applied afterwards |
| `formhawk_cro_provider_capabilities` | capability matrix | provider capability matrix |
| `formhawk_cro_ingestion_limits` | endpoint limits | positive bounded limits |
| `formhawk_cro_disabled` | disabled boolean | boolean frontend kill switch |

Actions and arguments:

- `formhawk_cro_experiment_created( $experiment_id, $form_id )`
- `formhawk_cro_experiment_started( $experiment_id )`
- `formhawk_cro_variant_rejected( $experiment_id, $variant_id, $reason )`
- `formhawk_cro_winner_selected( $experiment_id, $variant_id, $analysis )`
- `formhawk_cro_winner_promoted( $experiment_id, $variant_id )`
- `formhawk_cro_rollback( $experiment_id, $variant_id )`
- `formhawk_cro_runtime_changed()`

Extension code must preserve the no-PII contract and return values within the documented shapes. Runtime mutation validation remains fail-closed even for filtered candidates.

Test forcing exists only when both `WP_DEBUG` and server-side `FORMHAWK_CRO_TEST_MODE` are true. Production has no public force-assignment bypass.

## Known conservative limitations

- Dependency recognition is fail-closed and does not reverse-engineer arbitrary custom JavaScript conditions. Such forms retain control.
- Generic HTML has advisory observed-submit evidence only; autonomous promotion/rejection/rollback is disabled. Explicit administrator actions remain available.
- Registration and exposure accounting prevent selective browser-view reporting, not all automated traffic. A public configuration endpoint cannot prove a human visitor; site budgets, server randomization and trusted outcomes remain necessary. No visitor fingerprint is added to claim bot-proof traffic.
- First-class/explicitly identified forms can be visually prepared for at most 1.2 seconds. Anonymous structural generic IDs cannot always be prepared before discovery, so very late theme markup may briefly show control.
- Privacy-preserving assignment is scoped to a page lifecycle. Formhawk intentionally accepts possible repeat-visitor cross-arm exposure instead of introducing cookies or a persistent visitor identifier.
- Baselines containing progressive disclosure or multi-step layout are followed only by presentation-compatible hypotheses; unsafe structural stacking is not attempted.
- Cache plugins other than WP Rocket/LiteSpeed should subscribe to `formhawk_cro_runtime_changed` or purge on state changes.
- SPA navigation is supported once the CRO runtime is present. A transition that starts from a fully cached page on which the server knew of no active placement needs the SPA/theme integration to enqueue the runtime or refresh the document; Formhawk does not load CRO code site-wide merely to cover that case.
- Real licensed provider-browser verification depends on those packages being present; contract fixtures are not a substitute for an installed licensed package.
