# Database migrations

## Version 1 to 2

Version 1 stored aggregate form totals and field counters. Its `submissions`
counter can contain browser-observed generic submissions as well as
provider-confirmed Contact Form 7 successes. Version 2 deliberately leaves
that historical counter unchanged and adds zero-defaulted `submit_attempts`,
`validation_failures`, `mail_successes`, `field_type`, and
`last_mail_success_at` data. Formhawk does not fabricate certainty for old
rows.

## Version 2 to 3

Version 3 adds aggregate placement definitions and daily placement counters.
Existing form totals are retained, but are not copied into a guessed placement.
Placement analytics begin when a new event supplies an observed page path.

Each step uses idempotent WordPress `dbDelta()` operations, verifies its target
columns/tables, and updates `formhawk_db_version` only after that verification
succeeds. Re-running either step is safe after an interrupted migration.

## Version 3 to 4 (Formhawk 0.3.0)

The old `submissions` counter mixed generic browser observations and provider
confirmations. Old validation counts mixed sources and sometimes counted fields
or repeated browser/provider reports. The migration preserves these counters
unchanged; new writes leave them frozen. Existing `confirmed_successes` and
`submit_attempts` retain their original evidence; no missing attempts, successes,
placements or validation denominators are reconstructed from legacy totals.

`Version4` adds zero-defaulted `client_validation_failures`,
`provider_validation_failures`, `provider_validation_outcomes` to form/placement
daily tables and `client_validation_errors`, `provider_validation_errors` to
field daily aggregates. It creates InnoDB structural-registry and budget tables
with primary-key lookup/admission slots. No visitor-level data is introduced.

`DimensionBackfill` registers already known form definitions, paths, actual
placements and fields. It uses primary-key cursor scans of at most 500 rows per
stage/invocation, INSERT IGNORE and a fixed database checkpoint. It initializes
lifetime budgets from real structural counts without charging daily new-dimension
allowances. Existing inventories larger than configured caps stay usable; only
further additions are limited. Orphan aggregates are preserved without inventing
definitions. Version-1 form page paths do not become fabricated placement rows.

DDL is additive and verified before backfill. Analytics ingestion remains paused
while `formhawk_db_version` is below 4; underlying forms continue processing.
Subsequent WordPress requests resume the migration. Version 4 is committed only
after schema verification and completion of the structural checkpoint. Diagnostics
checks both schema and completion. A named lock serializes structural migration
without committing transactions belonging to form providers.

### Failure and recovery

Back up the database before deploying a schema upgrade. On missing DDL privileges,
storage errors or unavailable named locks, fix the database configuration and let
normal requests resume, or run `wp eval 'Formhawk\Infrastructure\Database::maybe_upgrade();'`
until the version reaches 4. Do not manually advance the version or reset the
checkpoint: replay is already idempotent. Read-only checks:

```sh
wp option get formhawk_db_version
wp eval 'var_export(Formhawk\Infrastructure\Database::schema_is_current());'
```

MySQL/MariaDB controls the duration/locking of ALTER TABLE; bounded PHP backfill
does not make DDL online on every supported database engine. Plan an upgrade
window for very large historical tables. New aggregate columns are additive, but
an older plugin would resume mixed-evidence writes: an intentional rollback should
restore the matching pre-upgrade database backup as well as plugin files. There
is no automatic destructive rollback or historical rewrite.

### Reproducible benchmark

```sh
FORMHAWK_BENCHMARK_DISPOSABLE=1 wp --path=/path/to/disposable-wordpress \
  eval-file wp-content/plugins/formhawk/tools/benchmark-migration.php
```

The benchmark creates randomly prefixed isolated tables, 100 synthetic forms,
36,500 rows in each of three aggregate tables (109,500 total), runs all bounded
passes, checks frozen counters and zero new evidence, and repeats the migration.
It removes only its own synthetic tables afterwards. Record wall time and memory
for the actual deployment database; local timings are not a hosting guarantee.

## Version 4 to 5 (Formhawk 0.4.0)

Version 5 adds the aggregate-only Autopilot CRO schema: per-form policy/baseline,
experiments, variants, daily arm/segment counters and immutable optimization
history. The migration is additive and idempotent; target tables and evidence
columns are verified before `formhawk_db_version` advances.

No version-4 form or field aggregate is copied into an experiment. Historical
traffic has no signed assignment and therefore cannot honestly become control
evidence. Existing analytics and evidence semantics remain unchanged. Runtime
baselines start empty, so upgrading alone never changes a provider form.

On DDL failure, normal forms and existing analytics remain available while the
upgrade retries. Autopilot stays unavailable until all five tables verify. An
intentional downgrade should restore the matching database backup; version 5
tables are not destructively removed during plugin deactivation.

## Version 5 to 6 (Formhawk 0.5.0)

Version 6 is an additive Field ROI migration. It creates stable field definitions,
structural form-version snapshots, opaque provider-confirmed submission linkage,
an append-only outcome journal, structural submission-field membership, daily
value aggregates, current ROI projections, immutable decision history, hashed API
key metadata and a PII-free structural audit log. It also adds
`optimization_objective` to the per-form Autopilot policy.

No version-5 event is converted into a submission or business outcome: historical
provider events cannot be linked honestly after the fact. Collection begins only
after v6 verifies all tables and columns. `FORMHAWK_DB_VERSION` advances to `6`
only after that verification succeeds. Re-running `Version6::run()` is idempotent.

WON rows use a nullable unique terminal-value key in addition to the global
idempotency hash. This prevents two concurrent webhook requests with different
replay keys from counting the same initial won revenue twice; later corrections
must be append-only `value_adjustment` rows. Currency is fixed per submission and
no implicit FX conversion occurs.

Submission-level linkage receives a fixed expiry at attribution time (30, 60, 90
or 180 days). Bounded cleanup first preserves daily aggregate/result/history data,
then deletes outcome and structural child rows, and finally deletes the expired
submission. Deactivation never drops data. An intentional downgrade should restore
the matching database backup because older code does not understand v6 outcomes.
## Version 6 to 7 (Formhawk 0.5.2)

The v7 migration adds `formhawk_cro_contexts`: a short-lived issued-assignment
registry keyed by SHA-256 of a 128-bit random JTI. It stores structural experiment,
variant, form and segment IDs, UTC issuance/expiry, one-shot event flags and bounded
attempt/terminal counters. `expires_at_utc` indexes bounded TTL deletion. No raw
token, JTI, visitor identity or field value is stored. Context issuance and its
`assignments` aggregate commit together; each event transition and its aggregate
increment also commit together under an InnoDB row lock.

`formhawk_experiment_daily.assignments` starts at zero and preserves the meaning
of `views`. Experiments gain `integrity_version` (existing rows default to 1; new
experiments explicitly use 2) and a bounded `integrity_warning`. Historical v1
experiments remain reportable/manual-safe and cannot automatically promote,
reject or roll back from mixed historical denominators. No assignments are copied
from views. Start a new experiment to collect a complete v2 decision cohort.

DDL is additive and restart-safe. The database version advances only after table,
column, expiry-index and transactional-engine verification. Partial migration
disables CRO runtime while normal forms/core analytics remain available. Retry the
upgrade after resolving the storage error. Recovery preserves all historical
tables/columns; do not downgrade to vulnerable 0.5.1 code to restore automation.

Contexts expire with their signed token (300–86,400 seconds). Fifteen-minute WP-Cron
cleanup and bounded issuance-time cleanup delete expired rows. A per-site cap of
50,000 rows bounds growth even if cron is missed; issuance fails open to the
original form if storage/admission fails. Deactivation retains data; uninstall
removes the registry only when explicit cleanup is enabled.

Cleanup deletes at most 5,000 expired rows per run, with one-minute continuation
for a backlog; issuance opportunistically deletes at most 1,000. Optional headers
and rate budgets do not replace this storage boundary. WP-Cron needs traffic or a
system cron trigger: when cron is disabled, the capacity cap still bounds growth,
but physical expiry deletion cannot be promised while the site is not executing.

Both the context registry and CRO daily aggregates are verified as InnoDB. An old
nontransactional aggregate table is converted without changing its counters. DDL
is serialized by a fixed site lock; ALTER TABLE duration/locking depends on the
database server, so large sites should use a maintenance window and a backup.

Reproducible synthetic migration/capacity benchmark:

```sh
FORMHAWK_BENCHMARK_DISPOSABLE=1 wp --path=/path/to/disposable-wordpress \
  eval-file wp-content/plugins/formhawk/tools/benchmark-cro-integrity.php
```

It upgrades 50,000 actual pre-v7 CRO aggregate rows, verifies frozen history and
zero historical assignments, repeats the migration, fills the context registry
to its 50,000-row cap, verifies failed admission, expires the rows and checks
bounded cleanup/recovery. Only randomly prefixed synthetic tables are removed.

## Version 7 to 8 (Formhawk 0.6.0)

Version 8 is an additive, opt-in Minimum Viable Form migration. It creates
`formhawk_minimum_form_runs`, `formhawk_minimum_form_baselines` and
`formhawk_minimum_form_decisions`. Existing CRO experiments receive nullable run,
baseline and field ancestry columns; outcome submissions receive a nullable baseline
ID; expiring CRO contexts receive a zero-defaulted one-shot provider-success bit.
The migration also repairs an absent zero-defaulted `abandonments` CRO aggregate
column before Minimum Form can read guardrail totals. Historical abandonment
assignments are not reconstructed because that would fabricate evidence.

No historical assignment, conversion, submission, outcome, Field ROI result,
decision or baseline is fabricated or reinterpreted. Existing rows retain NULL
ancestry and existing Autopilot settings remain unchanged. No Minimum Form run is
created during upgrade.

DDL is serialized by a fixed per-site database lock. Tables, columns and query-serving
indexes are checked independently so a partial migration can resume without duplicate
index errors. The DB version advances to 8 only after every required table, column and
index exists. Runtime Minimum Form registration requires the verified v8 schema;
failure leaves the original provider form and core analytics available.

Deactivation preserves all data. Explicit uninstall cleanup removes the three new
tables and both Minimum Form/background hooks together with the existing configured
Formhawk cleanup scope.
