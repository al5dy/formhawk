# Formhawk 0.3.0 hardening contract

## Acceptance criteria and boundaries

The public endpoint must bound work and new structure even when an attacker knows the token, omits origin headers and varies every form/path/field identifier. Existing dimensions remain usable after a new-dimension budget is exhausted. Independent database connections cannot oversubscribe a site budget. No limiter key represents a visitor, address, device, request fingerprint or session.

Generic browser submits never become backend successes. The overview must exclude generic starts and attempts from confirmed conversion. Browser validation and provider validation remain independent evidence; native constraint validation does not require a DOM submit event or create a submit attempt.

Free/Pro boundaries, form identity keys, existing options and aggregate history remain compatible. PHP 7.4 and WordPress 6.4 remain the minimums. There is no external analytics service or raw event table.

## Evidence model

| Metric | Evidence and semantics |
|---|---|
| `views`, `starts` | Browser lifecycle observations; once per tracked instance on the current page. Without IntersectionObserver a discovered form is used as the view fallback. |
| `submit_attempts` | Browser-observed DOM submit events. Native validation may stop submission before this event. No backend outcome is inferred. |
| `confirmed_successes` | Trusted CF7/WPForms/Elementor provider lifecycle only. Generic HTML displays N/A. Existing genuine confirmations are retained. |
| `failures` | Trusted provider failure signals, including CF7 mail failures. Not proof of every possible failure. WPForms has no universal failure hook. |
| `client_validation_failures` | Browser friction reports, once per current tracker lifecycle. Native invalid and WPForms jQuery validation share that lifecycle state. Cached old trackers can still send multiple observed reports. |
| `provider_validation_failures` | One rejection observed by the provider adapter per supported server lifecycle. No browser evidence is merged into it. |
| `provider_validation_outcomes` | Post-upgrade provider validation rejections plus accepted submissions. Numerator and denominator are updated together in one atomic statement per aggregate table. |
| Field validation | `client_validation_errors` and `provider_validation_errors` are distinct. Duplicate keys in a provider event count once. Browser field friction is emitted once per key/lifecycle. |
| Legacy counters | `submissions`, `validation_failures`, `validation_errors` remain frozen and visible as legacy evidence. They are never copied into new evidence counters. |

Confirmed conversion is `confirmed_successes / starts` for providers with confirmation support. Overview conversion uses only those providers in both numerator and denominator. Generic HTML exposes `submit_attempts / starts` separately. These are ratios of independently observed aggregates, not linked visitor funnels. A missing denominator, unavailable evidence or numerator larger than denominator produces N/A, without clamping a mismatch to 100%.

Provider validation rejection share is `provider_validation_failures / provider_validation_outcomes`. It describes only observed rejections and accepted submissions since the evidence upgrade. Spam, action failures, mail failures and unknown outcomes are excluded. It is not a rejection rate over all submissions. Browser friction has no failure percentage: `invalid` can occur without `submit`, and repeated invalid controls are not additional submission attempts.

Health prefers provider evidence. Its high-validation warning requires at least 10 known validation outcomes, at least 5 rejections and a share of 50% or greater. Generic attempts alone never make a form Healthy. Recent provider failures, traffic without attempts/confirmations and conversion changes retain their separate deterministic rules.

## REST protocol

`POST /formhawk/v1/events` deliberately has a public permission callback. The site token is public routing/integrity metadata, not authentication. The previous 0.1.1 token and events without `schema_version` remain accepted for cached pages. Versions 1 and 2 are recognized; new trackers send 2. Legacy browser `validation_failure` maps only to browser friction.

The request must be a JSON object with exactly `token` and `events`. Unknown properties, wrong JSON object/array shapes, numeric strings for integers, nested metadata and oversized structures are rejected. Schema validation runs inside the controller so rejections reach diagnostics. JSON decoding depth is capped at 8 before schema traversal. WordPress may reject malformed JSON before invoking the controller; such requests do not reach Formhawk counters.

Required event properties: `type`, `provider`, `provider_form_id`, `page_path`. Optional properties: `schema_version`, `title`, `duration_ms`, `field`, `fields`. Client event types are views, starts, submit attempts, abandonment, field interaction, browser validation friction and the legacy browser validation aliases. Clients cannot choose source/evidence or send success/failure/mail events.

IDs/field keys/labels are at most 191 characters, titles 255, field types 32 and paths 500; storage normalization retains the previous identifier rules. IDs/field keys must remain nonempty after normalization. Duration is an integer from 0 to 3,600,000 ms. Paths start with a single slash and exclude query strings/fragments. `field` is allowed only for field interaction, field validation or abandonment; `fields` is allowed only on validation reports. Field objects permit only `key`, `label`, `type`.

CF7 and WPForms IDs must be canonical positive decimal post IDs of the corresponding provider post type, excluding trash/auto-drafts. Checks use installed public post types and never load entries. Elementor widget traversal is deliberately avoided on public requests; its document/widget identity is subject to the same structural budgets. Neither existence checks nor origin headers prove that a visitor interaction occurred.

| Response | Meaning |
|---|---|
| 200 | Accepted batch, or partial acceptance with explicit `accepted`, `rejected`, `reasons` and `ok: false`. |
| 400 | Invalid JSON, schema, property, type or count. |
| 403 | Origin mismatch or invalid public routing token. |
| 413 / 415 | Payload too large / unsupported content type. |
| 422 | CF7/WPForms identity does not resolve to a real provider form. |
| 429 | Request/event/cost budget exhausted, or no events admitted by cardinality limits. |
| 503 | Database, structural lock or migration unavailable. |

429/503 HTTP responses include `Retry-After: 60`. It is a minimum retry suggestion, not a promise that a daily/lifetime cap will reset after a minute. Partial writes are possible if database operations fail independently; the tracker does not replay batches, including partially accepted ones. SQL errors and rejected request payloads are never returned or logged by the endpoint.

## Configurable budgets

`formhawk_ingestion_limits` filters an associative array. Invalid/nonpositive values fall back to defaults; values are converted to integers and capped at 1,000,000. Protocol-size limits may be tightened but cannot exceed the hard ceilings below. Choose deployment-wide stable settings; changing window duration reinterprets fixed-window slots and should be done between traffic windows.

| Key | Default |
|---|---:|
| `body_bytes` | 65536 |
| `event_bytes` | 16384 (canonical encoded event size) |
| `batch_size` | 20 |
| `fields_per_event` | 50 |
| `requests_per_minute` | 600 |
| `events_per_minute` | 3000 |
| `cost_per_minute` | 12000 |
| `forms_total` | 5000 |
| `placements_total` | 20000 |
| `paths_total` | 10000 |
| `fields_total` | 50000 |
| `forms_per_day` | 100 |
| `placements_per_day` | 500 |
| `paths_per_day` | 500 |
| `fields_per_day` | 1000 |
| `placements_per_form` | 100 |
| `fields_per_form` | 100 |
| `dimensions_per_form_per_day` | 100 (new placements + fields combined) |
| `dimension_window_seconds` | 86400 |

The `*_per_day` names refer to the configurable dimension window (one UTC day by default). Throughput windows remain fixed 60-second UTC windows. A boundary can admit a full allowance on each side; this is not a sliding-window algorithm. Cost is 4 units per event plus one per supplied field (including duplicate supplied keys before normalization). A 50-field report costs 54 units. These units bound relative ingestion work; they are not milliseconds or exact SQL counts.

Lifetime caps include existing migrated dimensions. Over-limit pre-existing inventories are grandfathered: they remain usable, but new dimensions of the exhausted kind are rejected. Budgets apply to server adapters as well as browser events for structural admission. Registry hashes describe form structure only. Global path hashes are shared across forms; generic HTML definitions retain their placement-scoped identity.

The legacy `formhawk_event_request_limit` filter remains supported and supplies the default `requests_per_minute` before the combined filter runs:

```php
add_filter( 'formhawk_ingestion_limits', static function ( $limits ) {
	$limits['events_per_minute'] = 6000;
	$limits['cost_per_minute'] = 24000;
	$limits['forms_per_day'] = 200;
	return $limits;
} );
```

The tracker protocol retains its normal batch sizes when limits are tightened. Set restrictive protocol limits only with a matching custom emitter. Do not derive filter values from visitor addresses, request fingerprints or visitor state.

## Storage, concurrency and operational limits

`AtomicBudgetStore` uses a conditional SQL UPDATE as the admission decision. Stable primary-key slots are reused across time windows and do not depend on transient/object-cache atomicity. Old delayed requests cannot move a slot backwards. `BudgetStoreInterface` provides a test seam.

`CardinalityGuard` looks up a bounded primary-key list. Only unknown structure requires a site-scoped MySQL named lock, with zero wait. All applicable limits are checked before reservation, so rejected attempts cannot deliberately drain lifetime budgets. The registry stores SHA-256 structural keys and fixed kind labels. The number of per-form budget slots is bounded by admitted forms. Standard MySQL/MariaDB connection-scoped named locks are required; unavailable locks produce a storage diagnostic and drop analytics without blocking a form submission. Database proxies must keep lock and write operations on the same connection.

Partial database failures can conservatively consume reserved capacity without recording all related dimensions or aggregates. Do not automatically refund/replay after uncertain writes. Fix the storage issue, inspect structural inventory and adjust the documented limits if necessary. Retention removes dated analytics, not the lifetime structural registry; long-lived slots prevent an attacker from recycling capacity by forcing cleanup. An intentional future inventory-reset workflow must coordinate both stores.

Diagnostics contain six fixed counters for the current UTC day: rejected requests/events, throughput-throttled requests/events, cardinality-rejected events, and storage-rejected events. Event counts cover parseable bounded batches (at most the configured batch size); oversized/unparseable requests have no inferred event count. Counters saturate at one billion. No IP, raw payload, fingerprint, user ID or arbitrary rejection label is stored. These controls bound pollution; a public endpoint cannot prove genuine traffic, and an attacker can consume shared site capacity.

## Privacy and browser behavior

There are no form values, FormData serialization, analytics cookies, localStorage/sessionStorage, IndexedDB identifiers, IPs, recipients, subjects, bodies or persistent visitor/session IDs in analytics. The only hidden attributes read by the tracker are the documented provider technical IDs.

Field metadata is captured on discovery, before later interaction can personalize labels. Label extraction uses only the label's own text nodes, excluding nested controls, output and dynamic markup; ambiguous labels fall back to static keys. Contenteditable/custom nodes are not treated as form controls. Sites must keep form IDs, field names, paths and explicit metadata attributes structural; no generic sanitizer can prove that arbitrary site-supplied metadata is free of personal content.

Temporary deduplication lives only in page memory. Browser/provider validation signals are not matched by time. A zero-delay task coalesces fields for transport; lifecycle/field sets determine deduplication. Pending validation is flushed before terminal state/pagehide. Fetch/beacon failures do not change submission behavior. Missing/throwing observer constructors degrade to discovery fallback/manual refresh. Dynamic fields require discovery or explicit `Formhawk.refresh()` when MutationObserver is unavailable.

## Verification commands

Use disposable WordPress databases. PHPUnit includes real REST/SQL/migration tests; concurrency workers use eight independent processes. See `docs/MIGRATIONS.md` for restart/recovery and benchmark commands.

```sh
composer phpcs
composer phpstan
FORMHAWK_WP_ROOT=/path/to/disposable-wordpress composer test
npm run lint:js
npm test
npm run build
npm run build:release
```

For real native validation, serve `tests/Fixtures/browser-smoke.php` through a disposable WordPress site's document root, with `FORMHAWK_WP_ROOT` set in the PHP server environment. Open the fixture, click Send while empty, repeat, then fill synthetic data and submit. Expect one browser friction report, zero attempts until native validation passes, one observed attempt after submission and no generic confirmation. Verify REST bodies and aggregates, then open the dashboard. The fixture handler does not inspect the posted fields.

Provider package/E2E coverage and remaining limitations are recorded separately in `docs/PROVIDER-SUPPORT.md`. Multisite network activation remains outside the proven support matrix; new tables/locks use the active site's prefix. No release is published by running these checks.
