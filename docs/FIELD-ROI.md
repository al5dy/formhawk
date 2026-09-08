# Formhawk Field ROI

Field ROI is the Business Value Intelligence layer introduced in Formhawk 0.5.0.
Its optimization target is business value per visitor, not the largest raw
submission count.

## Architecture

The provider adapters remain responsible only for translating their public
lifecycle into canonical events and stable structural identities. On a confirmed
CF7, WPForms or Elementor Pro success, `OutcomeAttribution` joins the form,
placement, experiment arm and configured field definitions to one random opaque
submission ID. The `Outcomes` module owns normalization, append-only state/value
history, authentication and retention. The `ROI` module owns cohorts, statistics,
confidence, recommendations, immutable results and admin presentation. CRM sources
implement `OutcomeSourceInterface`; the calculation engine has no CRM dependency.

The default `ModuleGateInterface` implementation exposes a
`formhawk_module_enabled` boundary. A commercial add-on may replace/filter this
gate without scattering license checks through analytics, providers or ROI math.

## Stored data

Database schema version 6 adds:

| Table | Purpose |
|---|---|
| `formhawk_field_definitions` | Stable provider-native field identity and current structural metadata. |
| `formhawk_form_versions` | Deduplicated structural schema fingerprints and JSON snapshots. |
| `formhawk_submissions` | Time-bounded opaque attribution, provider/form/placement and experiment arm. |
| `formhawk_outcomes` | Append-only canonical outcome/value transitions, keyed-HMAC external references and replay hashes. |
| `formhawk_submission_fields` | Structural field membership/requiredness; never a field value. |
| `formhawk_field_value_daily` | Daily business-outcome aggregates by field and safe cohort dimensions. |
| `formhawk_field_roi_results` | Latest deterministic projection per field/period/currency. |
| `formhawk_field_roi_history` | Immutable, model-versioned before/after decision snapshots. |
| `formhawk_outcome_api_keys` | Key ID, password hash, capability, created/revoked/last-used timestamps. |
| `formhawk_business_audit` | Structural events without lead identity or payload content. |

`formhawk_submissions.public_id` is `fh_` plus 128 cryptographically random bits,
encoded base64url. It is not a visitor, browser, account or cross-site identifier.
The frontend creates no fallback ID when secure randomness is unavailable.

### Submission lifecycle (0.5.1)

`_formhawk_submission` belongs to one logical provider submission. The tracker
creates it on attachment and preserves it while a request is in flight, including
duplicate submit events. CF7 `wpcf7mailsent`, `wpcf7mailfailed`, `wpcf7spam` and
`wpcf7aborted`, WPForms `wpformsAjaxSubmitSuccess`, and Elementor `submit_success`
complete the attempt and prepare a new cryptographically random marker for the
next independent submission. Duplicate terminal callbacks do not rotate it again.
CF7 `wpcf7invalid`, WPForms failed/error responses and Elementor `error` allow a
retry with the same marker. Provider resets retain the prepared marker; dynamic
replacement of an in-flight form retains that request's marker.

Completion suppresses abandonment until a new edit, validation attempt or submit.
The next submit records new browser/CRO attempt evidence. Views, starts, field
interaction/validation deduplication and the `_formhawk_cro` experiment assignment
remain scoped to the tracked instance/page lifecycle. Generic HTML remains
ineligible for Field ROI.

The unique `public_id` constraint remains the server retry boundary. Independent
IDs create separate rows and preserve their own provider entry IDs. Reuse with
different non-empty provider entry IDs returns `formhawk_submission_conflict`
(status 409) and records `submission_conflict` in the existing business audit
with only provider, structural form ID and `provider_entry_mismatch` reason.
Neither entry ID nor form content is added to the audit. The original submission
is preserved and the customer's provider submission is not blocked. When entry
IDs are absent, the server cannot distinguish a reused browser marker from a
legitimate retry.

Schema version remains 6. This fix prevents future merging; no historical rows
are fabricated. Recovering earlier undercounts requires separate provider/CRM
reconciliation. Clear caches serving old assets and reload already-open pages.

## Outcomes and value

Canonical states are `submitted`, `qualified`, `unqualified`, `won`, `lost`,
`spam`, `duplicate` and `unknown`. `value_adjustment` is an append-only monetary
event, not a state. UNKNOWN stays pending/censored and is never inferred as LOST.
The current state is the latest valid state transition by `occurred_at`, then
journal ID. Out-of-order delivery therefore does not overwrite a newer state.

Money is a signed integer in ISO 4217 minor units. USD 123.45 is `12345`; JPY has
zero decimal places and BHD has three. A submission cannot mix currencies. There
is no implicit FX service or cross-currency total. Initial WON value has one unique
terminal key; corrections and refunds are signed `value_adjustment` rows.
Monetary values on non-WON state transitions are rejected so qualification value
cannot be confused with booked revenue; configured outcome values are a separate
explicit fallback.

Configured Qualified/Won values fill only eligible outcomes that have no actual
monetary value. An imported value, including an explicit zero, always wins for
that outcome; configured values never replace or add to its actual amount.

## Metrics and formulas

For a mature experiment arm `a`, “visitor” means one privacy-safe eligible form
view/page lifecycle, not a persistent cross-page unique person:

```text
Submission rate(a)            = confirmed submissions(a) / visitors(a)
Qualified leads per visitor(a)= qualified outcomes(a) / visitors(a)
Won leads per visitor(a)      = won outcomes(a) / visitors(a)
EVPV(a), or RPV(a)            = actual revenue minor units(a) / visitors(a)
Intervention relative impact  = (metric(variant) - metric(control)) / metric(control)
Current-config impact         = (metric(control) - metric(variant)) / metric(variant)
Current-config EVPV impact    = EVPV(control) - EVPV(variant)
Expected value contribution   = current-config EVPV impact × all evaluated views
Outcome coverage              = mature submissions with explicit outcome / mature submissions
```

Qualified and won metrics deliberately use visitors, not only submitted leads.
That retains the loss of volume caused by a field. Displayed revenue and EVPV use
the actual non-winsorized total.

Observational friction is:

```text
friction = (associated abandonment + provider validation failures
            + 0.35 × browser validation reports) / field interactions
```

Interaction/start rate is not presented as field reach. The latter, completion
and correction remain unavailable until a provider can emit those signals without
reading visitor values. The UI shows an em dash instead of inventing data.

The 0.35 browser weight is a deterministic conservative policy because native
browser validation is a friction report and has no confirmed failure denominator.
It affects prioritization, not revenue or causal lift.

Business Value Score is deterministic:

```text
business signal = clamp(revenue relative impact, -1, 1)
                  or qualified relative impact when revenue is unavailable
score = clamp(round(50 + 40 × business signal - 10 × friction), 0, 100)
```

The score is explanatory/ranking metadata. Autopilot winner selection uses the
underlying per-visitor metric and posterior probability, never the score.

When Autopilot optimizes qualified leads, won leads or business value, a lower
raw submission rate is not by itself a terminal harm guardrail: otherwise a
high-value qualifier could never win. Provider failures, validation regressions,
JavaScript errors and latency remain hard safety guardrails. Binary objectives
still use the configured minimum views, events and runtime; monetary winners must
also have enough revenue-bearing samples and a robust interval that excludes zero.

## Statistical methodology

Binary outcomes use the existing sequential Bayesian Beta-Binomial engine with a
Jeffreys prior (`alpha=0.5`, `beta=0.5`). Monetary inference uses a deterministic
seeded non-parametric bootstrap over the zero-inflated per-visitor distribution:
non-revenue visitors are zeros, 500 iterations are run, and a 95th-percentile
winsor limit is used only inside inference to reduce single-deal domination.
Each iteration draws at most 512 observations and applies finite-size variance
scaling to approximate the full n-out-of-n bootstrap without making large cohorts
quadratic in dashboard volume.
Reported totals and RPV remain actual totals. The result includes probability of
benefit, a robust interval, sample counts and the winsor diagnostic.

Confidence is capped by the smaller experiment arm, explicit mature outcome count
and outcome coverage. `insufficient`, `low`, `medium`, `high` and `very_high` are
shown with supporting counts. Strong experimental evidence additionally requires
at least 5,000 visitors per arm, 250 known outcomes, 90% outcome coverage and 99%
directional probability for benefit or harm. Assignment imbalance beyond four
standard errors (and at least ten percentage points), globally or within mature
day/device cells, invalidates confidence as possible leakage. This is a defensive
Simpson/experiment-leakage check; observational data never receives a causal label.

The evidence enum is `observational`, `quasi_experimental`, `experimental` and
`strong_experimental`. Version 0.5 calculates causal ROI only from controlled
Formhawk field-order or progressive-disclosure experiments whose final candidate
explicitly targets that field. The reported effect is the current field
presentation versus that exact tested alternative; it is not silently relabelled
as a field-removal effect. Schema history is retained for future adjusted natural-
experiment analysis, but a before/after change is not promoted to quasi evidence
until traffic, placement, device, source and seasonality denominators are available.
Required fields therefore show observational friction and “causal ROI unavailable”
unless a safe controlled experiment exists.

This conservative boundary protects against selection bias, stage/survivorship
bias and Simpson's paradox: the engine does not compare “people who filled an
optional field” with all visitors, and it makes no causal field-presence claim from
unstratified historical traffic. Experiment cohorts share the same eligible form
stage and retain placement/device/version dimensions in daily aggregates.

## Outcome delay and retention

Every submission gets `mature_after_utc` and `attribution_expires_at_utc` at
ingestion. Recent cohorts are reported as MATURING and excluded from final business
inference. Only explicit outcomes close a state; absence of a webhook never becomes
LOST. Attribution windows are 30, 60, 90 or 180 days. Cleanup is bounded and leaves
daily aggregates and immutable result history after submission linkage expires.
Daily projection work follows the append-only outcome ID cursor, handles at most
seven changed site dates per run, and rebuilds each date transactionally; it never
rescans the full attribution window because an administrator opened the dashboard.

### Reproducible performance fixture

`FORMHAWK_BENCHMARK_DISPOSABLE=1 wp eval-file tools/benchmark-field-roi.php`
creates random isolated InnoDB tables and removes them in `finally`. On the local
MariaDB/PHP 8.2 development environment, the final worst-case fixture (dates
deliberately interleaved through the primary-key range) measured:

| Linkage rows | Incremental fixture insert | One daily shard | Dashboard aggregate |
|---:|---:|---:|---:|
| 10,000 | 1.029 s | 0.046 s | 0.000457 s |
| 100,000 | 6.855 s | 0.233 s | 0.000976 s |
| 1,000,000 | 133.267 s | 47.656 s | 0.000571 s |

Fixture insertion is reported separately and is not a dashboard operation. The
1M shard result is a cold, fragmented-index bound, not a hosting guarantee. It is
why dashboard reads use compact projections and heavy rebuilds remain locked,
cursor-driven background work.

## Outcome REST API

Endpoint:

```text
POST /wp-json/formhawk/v1/outcomes
```

Authentication is either a WordPress Application Password belonging to a user with
`manage_options`, or a dedicated Bearer key created under **Formhawk → Field ROI →
Outcome API**. A dedicated secret is returned in one response only; only its
WordPress password hash is stored. Keys have the `record_outcomes` capability and
can be revoked.

Example:

```sh
curl --request POST 'https://example.com/wp-json/formhawk/v1/outcomes' \
  --header 'Authorization: Bearer fhk_KEY_ID.SECRET' \
  --header 'Content-Type: application/json' \
  --header 'Idempotency-Key: hubspot-event-48721' \
  --data '{
    "submission_id": "fh_7Kx29abcdefghijklmnopq",
    "status": "won",
    "value_minor": 12345,
    "currency": "USD",
    "occurred_at": "2026-09-07T10:00:00Z",
    "external_reference": "deal-8821"
  }'
```

The JSON object is strict; arbitrary metadata is rejected. Body size is 8 KiB.
Date/time must be ISO 8601 and cannot be materially future-dated. Requests are
rate-limited through atomic site budgets. Idempotency is global across key rotation;
an identical retry returns HTTP 200 with `duplicate: true`.

Local PHP:

```php
$result = formhawk_record_outcome(
    $submission_id,
    'qualified',
    null,
    '',
    array(
        'idempotency_key' => 'crm-event-123',
        'occurred_at'     => '2026-09-07T10:00:00Z',
    )
);
```

## Extension hooks

Filters:

- `formhawk_outcome_statuses`
- `formhawk_field_roi_min_sample`
- `formhawk_field_roi_policy`
- `formhawk_field_roi_attribution_window`
- `formhawk_field_roi_value_metric`
- `formhawk_field_roi_recommendation`
- `formhawk_module_enabled`

Actions:

- `formhawk_submission_attributed`
- `formhawk_outcome_recorded`
- `formhawk_field_roi_updated`
- `formhawk_field_roi_opportunity_detected`

Arguments contain opaque IDs, structural IDs, aggregate metrics or canonical
outcomes. Formhawk never adds submitted values to these hooks.

## Known boundaries in 0.5.1

- Generic HTML forms have no universal provider-confirmed success and are not
  eligible for outcome attribution or Field ROI claims.
- Optional-field “supplied versus skipped” cohorts are not inferred because the
  privacy contract forbids reading values. Provider-native presence/requiredness
  experiments are not shipped in 0.5.1; Formhawk will not simulate them by only
  changing browser validation while the server schema remains unchanged.
- Structural schema snapshots are collected at confirmed submission. They are not
  yet sufficient for adjusted quasi-experimental EVPV and are not presented as such.
- UTM/campaign capture and new-versus-returning segmentation are not enabled; no
  arbitrary query string or persistent identity is stored.
- Automated provider form-definition promotion remains limited to existing safe,
  reversible runtime Autopilot mutations. Provider definitions are never rewritten.
- Post-promotion monitoring in 0.5.1 retains technical/provider guardrails, but a
  delayed business-outcome regression does not yet trigger an automatic rollback.
  The immutable decision history preserves the evidence for review.
- No FX conversion is performed. Each currency is evaluated separately.
