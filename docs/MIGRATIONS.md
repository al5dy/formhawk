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
