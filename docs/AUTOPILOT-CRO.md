# Formhawk Autopilot CRO

## Product contract

Autopilot is an aggregate-only local loop:

`analyze → rank opportunity → create hypothesis → generate a safe runtime variant → experiment → guard → decide → promote → monitor → continue`

The provider definition is always the source of truth. Formhawk does not write Contact Form 7 configuration, WPForms definitions, Elementor documents, submitted fields, CRM fields or email configuration. Disabling Formhawk removes the runtime layer and leaves the original form unchanged.

Modes have real execution semantics:

- **Observe** creates a suggestion but sends no experimental traffic.
- **Approve** creates a ready experiment and waits for an administrator to start it. This is the default.
- **Full Autopilot** starts, stops, promotes, monitors and rolls back without a manual experiment builder.

Only one meaningful mutation may be active for a form. A promoted mutation becomes the next baseline layer; the following experiment adds one mutation to it.

## Architecture

- `OpportunityDetector` reads existing 30-day aggregates and ranks deterministic opportunities by impact, confidence, risk, sample and estimated upside.
- `HypothesisEngine` converts the selected structural signal into a reproducible explanation.
- `VariantGenerator` and `MutationRegistry` build one control and one candidate through independent strategies.
- `ExperimentRepository` owns normalized storage, atomic aggregate upserts, baseline ancestry, transactional promote/rollback transitions, immutable decisions and per-form locks.
- `CROConfigController` performs page-lifecycle assignment from a cache-neutral, no-store REST response.
- `variant-engine.js` classifies the live provider DOM and applies or atomically restores mutations. Invalid, corrupt or unsupported configurations fail open to control.
- `RequestContext` verifies the signed structural marker and removes it from `$_POST` and `$_REQUEST` before provider processing.
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

The server excludes field metadata resembling passwords, authentication, payment, OTP, CAPTCHA, CSRF/nonces, terms/privacy/GDPR/consent, signatures and files. The runtime independently classifies fields as SAFE, CAUTION, PROTECTED or FORBIDDEN. Required fields are PROTECTED. Any conditional marker or ambiguous wrapper makes structural mutation unsafe. Hidden provider fields are never moved. Runtime exceptions restore all anchors/classes/text, replace variant attribution with a signed control context and use control.

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

An assignment contains only experiment ID, variant ID, Formhawk form ID, provider, provider form ID, desktop/mobile segment and expiry. It is authenticated with HMAC derived from the WordPress auth salt. For CF7/WPForms/Elementor it is a short-lived technical submission marker, not authentication and not a visitor identifier, and is removed before provider code builds entry, mail or CRM collections. Generic HTML never receives a hidden marker because it has no confirmed server attribution and Formhawk must not alter an arbitrary submission payload.

Since 0.5.1, successful AJAX completion allows a later independent submission from the same form to record another `attempt` and `latency`. Duplicate submits before the response and duplicate terminal callbacks remain deduplicated. A successful terminal state suppresses pagehide abandonment until new editing/validation or submission. Views/starts and `_formhawk_cro` stay in the same page lifecycle and experiment arm; only the separate Field ROI `_formhawk_submission` marker rotates. Failed responses continue to allow retries and abandonment under the existing CRO semantics.

Assignment lasts only in memory for the current page lifecycle. Formhawk creates no cookie, local/session storage, IndexedDB record, IP dimension, fingerprint, session/visitor ID or raw event log. CRO storage contains structural configuration and aggregate counters only. It never contains submitted names, email, phone, message, uploads, recipients, subjects or bodies and uses no third-party CRO service.

The public CRO endpoints accept exact shallow schemas, bounded strings and request bodies. They use same-origin checks when Origin/Referer is present and atomic site-wide limits from `formhawk_cro_ingestion_limits`; no limiter key derives from visitor data. Rejected, throttled and storage counters are fixed daily aggregates visible in Diagnostics.

## Statistical methodology

Algorithm `beta-binomial-1.0` uses independent Jeffreys priors, `Beta(0.5, 0.5)`, for the two Bernoulli arms. It reports posterior means, 95% credible intervals, probability that variant is better, relative/absolute expected lift and expected loss. Small samples use deterministic numerical Beta integration; sufficiently large samples use the normal approximation to the posterior difference. Page-lifecycle randomization is cache-neutral and does not create a persistent identity; repeated visits can therefore enter different arms, an intentional privacy tradeoff documented below.

A winner requires every gate:

- minimum views per arm;
- minimum total conversions;
- minimum runtime;
- posterior probability threshold;
- maximum expected loss;
- minimum absolute effect.

Credible harm rejects a variant. Maximum runtime without sufficient evidence is inconclusive. Allocation remains fixed at control 50% / variant up to the configured 50%; adaptive allocation is intentionally not enabled because correctness is preferred over premature bandit behavior. Cumulative improvement compounds non-rolled-back baseline-relative lifts as `Π(1 + lift) - 1`; percentages are never added. Rolling back a winner excludes its lift from the displayed current cumulative impact.

Conservative, Balanced and Aggressive presets change real sample, runtime, posterior, loss and monitoring thresholds. Filters remain bounded by hard floors: at least 100 views per arm, 20 conversions and 3 days. Policy snapshots and `algorithm_version`/`policy_version` remain with each immutable decision.

## Guardrails and rollback

Guardrails are evaluated globally and, once each segment has enough sample, independently for desktop/mobile. A variant routes to control and is rejected on:

- credible confirmed-conversion harm;
- excessive provider/mail failures;
- provider-confirmed validation explosion;
- browser/client validation explosion using deduplicated failures divided by started lifecycles;
- CRO application/JS error rate;
- material submission-latency regression;
- corrupt/missing configuration or promotion storage failure.

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
- Generic HTML has observed-submit evidence only, so its result cannot be called confirmed conversion.
- First-class/explicitly identified forms can be visually prepared for at most 1.2 seconds. Anonymous structural generic IDs cannot always be prepared before discovery, so very late theme markup may briefly show control.
- Privacy-preserving assignment is scoped to a page lifecycle. Formhawk intentionally accepts possible repeat-visitor cross-arm exposure instead of introducing cookies or a persistent visitor identifier.
- Baselines containing progressive disclosure or multi-step layout are followed only by presentation-compatible hypotheses; unsafe structural stacking is not attempted.
- Cache plugins other than WP Rocket/LiteSpeed should subscribe to `formhawk_cro_runtime_changed` or purge on state changes.
- SPA navigation is supported once the CRO runtime is present. A transition that starts from a fully cached page on which the server knew of no active placement needs the SPA/theme integration to enqueue the runtime or refresh the document; Formhawk does not load CRO code site-wide merely to cover that case.
- Real licensed provider-browser verification depends on those packages being present; contract fixtures are not a substitute for an installed licensed package.
