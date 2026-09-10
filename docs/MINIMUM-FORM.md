# Minimum Viable Form

Minimum Viable Form is the opt-in semantic optimization layer introduced in
Formhawk 0.6.0. Its target is not the shortest possible form. Its target is the
minimum friction that preserves or improves measurable value per visitor.

## Product contract

The engine executes one causal change per form:

`analyze → classify → select → experiment → evaluate → promote/reject → monitor → repeat`

It reuses the existing Field ROI projection, outcome attribution, CRO assignment
runtime and experiment aggregates. There is no second ROI model and no raw visitor
event log. A Field ROI verdict is an input to candidate selection, never proof that
an untested field removal won.

The default mode is Approve. Observe only prepares a proposal. Full Autopilot may
start, decide, promote and roll back without another click, but only inside the same
provider capability and safety boundaries. The feature is off after upgrade until
an administrator explicitly starts it.

## Objective hierarchy

The selected per-visitor primary metric is:

1. revenue per visitor when mature revenue coverage is sufficient;
2. won leads per visitor when sufficient;
3. qualified leads per visitor when sufficient;
4. provider-confirmed conversion otherwise.

Revenue per lead is not used as the monetary optimization target. When only the
fourth metric is available, the UI labels the run Conversion Optimization Mode and
does not claim business-value optimization.

## Candidate policy

- `MONEY_MAKER`, `QUALIFIER` and `FREE_VALUE` fields are retained.
- `UNKNOWN` fields collect more evidence.
- A high-confidence `CONVERSION_KILLER` can become a `REMOVE_FIELD` candidate only
  when its safety, provider capability and dependency graph all permit removal.
- A required `CONVERSION_KILLER` or `NEUTRAL` field can become `MAKE_OPTIONAL` only
  when a provider adapter explicitly verifies runtime server semantics.
- `MAKE_REQUIRED` has a separate high-risk strategy and is never selected by the
  built-in automatic policy in 0.6.0.

Candidate score combines friction, business impact, Field ROI confidence, sample
size and structural risk. `formhawk_minimum_form_candidate_score` can tune ranking;
it cannot bypass forbidden-field checks.

## Field safety and dependencies

Structural classification is value-blind:

- FORBIDDEN: password/authentication, payment/bank, OTP/2FA, CAPTCHA, nonce/CSRF,
  honeypot/security, file security, signature, legal/privacy/GDPR consent and
  reliably detected medical/sensitive fields.
- PROTECTED: provider-required fields, unknown or known conditional dependencies,
  email-template references, CRM/action mappings, calculations and multi-step
  dependencies.
- CAUTION: phone, budget, company, address, date, selects and qualification fields.
- SAFE: ordinary optional independent fields.

The graph records `depends_on`, `controls_visibility_of` and provider configuration
dependencies. An unknown graph is unsafe. A removed field is physically detached
from the variant form so it is absent from the provider payload; its DOM position
and accessibility state are retained in memory for idempotent restoration. The
original provider definition is never persisted with a mutation.

## Provider capabilities in 0.6.0

| Provider | Remove | Optional | Required | Confirmed success | Outcome attribution | Dependency graph | Minimum Form automation |
|---|---:|---:|---:|---:|---:|---:|---|
| Contact Form 7 | Yes, safe optional fields | No | No | Yes | Yes | Yes | Removal experiments |
| WPForms Lite/Pro | Yes, safe optional fields | No | No | Yes | Yes | Yes | Removal experiments |
| Elementor Pro Forms | Runtime strategy only | No | No | Yes | Yes | No | Observe/fail closed |
| Generic HTML | Runtime strategy only | No | No | No | No | No | Not autonomous |

“No” is intentional: Formhawk does not obtain apparent success by suppressing a
provider's required validation. Provider extensions may advertise optional/required
support through the public capability/schema filters only when they implement real,
reversible runtime provider semantics.

## Baselines and schema drift

`formhawk_minimum_form_baselines` is append-only. V1 snapshots the original provider
schema plus any pre-existing Autopilot runtime baseline. Every winner stores a new
version with parent ID, complete mutation stack, provider schema fingerprint and
dependency hash. The provider definition remains source of truth.

If either hash changes, traffic is routed to original, the current experiment is
paused and the run becomes `revalidation_required`. Old mutations are not replayed.
Explicit revalidation creates a new V1 and marks the old run superseded, preserving
its decisions for audit.

## Decision and integrity policy

Binary metrics use the existing Beta-Binomial model. Revenue uses deterministic,
winsorized robust bootstrap inference designed for zero-inflated, skewed and
heavy-tailed outcomes; actual totals remain available for reporting. A single
extreme deal cannot by itself become decisive.

A variant can be promoted only after minimum sample, runtime, outcome maturity,
coverage, posterior probability, expected loss and practical effect pass, with no
guardrail or integrity failure. Otherwise it remains collecting or ends
inconclusive. Binary conversions never exceed issued assignments. Each issued
context can consume one provider-success conversion; independent submissions and
their outcomes remain separate rows.

Provider failures, provider validation, confirmed conversion, JavaScript error and
outcome-quality guardrails remain active. A promoted baseline is monitored against
its pre-promotion cohort. Credible business or conversion regression atomically
restores its parent baseline. Manual Restore Previous and Disable are runtime pointer
switches; no provider configuration is edited or deleted.

All selection, evaluation, promotion and rollback work runs from bounded WP-Cron
jobs or an explicit administrator action. A non-blocking per-form MySQL lock prevents
competing workers. Operations are idempotent and restart-safe.

## Storage and privacy

Schema v8 adds:

- `formhawk_minimum_form_runs` for current opt-in state and baseline pointers;
- `formhawk_minimum_form_baselines` for immutable genomes/mutation stacks;
- `formhawk_minimum_form_decisions` for append-only evidence and algorithm versions;
- nullable run/baseline/field ancestry on CRO experiments;
- nullable baseline attribution on submissions;
- a one-shot provider-success bit on expiring hashed CRO contexts.

No field value, name, email address, phone number, message, IP address, fingerprint,
cookie, persistent browser identifier or third-party transmission is added. JSON is
used only for bounded structural genomes, normalized mutation configuration and
decision policy—not as a replacement for queryable run/decision entities.

## Developer API

Filters:

- `formhawk_minimum_form_candidate_score`
- `formhawk_minimum_form_field_safety`
- `formhawk_minimum_form_allowed_mutations`
- `formhawk_minimum_form_primary_metric`
- `formhawk_minimum_form_guardrails`
- `formhawk_minimum_form_min_sample`
- `formhawk_minimum_form_decision_policy`
- `formhawk_minimum_form_provider_schema`
- `formhawk_minimum_form_provider_capabilities`

Actions:

- `formhawk_minimum_form_run_started`
- `formhawk_minimum_form_candidate_selected`
- `formhawk_minimum_form_experiment_started`
- `formhawk_minimum_form_field_removed`
- `formhawk_minimum_form_field_retained`
- `formhawk_minimum_form_baseline_promoted`
- `formhawk_minimum_form_optimized`
- `formhawk_minimum_form_rollback`

Filters must return bounded structural data. FORBIDDEN classification is an absolute
floor. Extensions remain responsible for verifying provider versions, validation,
conditional logic, payload integrity and accessibility before advertising a semantic
capability.

## Operational recovery

Diagnostics expose engine/schema/job health, provider capabilities, current baseline,
active experiment, outcome coverage and integrity. On database, configuration,
schema or runtime mutation failure the original form remains usable. No extra runtime
asset is enqueued on pages without an active experiment or deployed baseline.
