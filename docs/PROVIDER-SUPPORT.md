# Provider support and verification

## Capability matrix

| Provider | Stable form identity | Browser funnel | Server-confirmed success | Server validation fields | Server failure | Mail signal | Dynamic / popup | Multi-step lifecycle |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| Contact Form 7 | CF7 form ID | Yes | `wpcf7_mail_sent` / final `wpcf7_submit` status | `wpcf7_submit` invalid field keys | mail failed, spam, aborted | mail success/failure | Yes | Same form lifecycle |
| WPForms Lite / Pro | WPForms form ID | Yes | `wpforms_process_complete` | `wpforms_process_initial_errors`, defensive `wpforms_process_after` observation | No stable universal post-processing failure hook | Global `wp_mail` only | Yes | Same form lifecycle |
| Elementor Pro Forms | document/post ID + form widget ID | Yes | `elementor_pro/forms/new_record` after actions, with a successful handler outcome | `elementor_pro/forms/validation` handler error keys | action errors visible through handler errors, messages and `is_success` | `elementor_pro/forms/mail_sent`, confirmed only with an overall successful outcome | Yes | Same form lifecycle |
| Standard HTML | explicit ID/name or deterministic structural ID, placement-scoped | Yes | Not universally available | Browser constraint validation | Not universally available | Global `wp_mail` only | Yes | Same DOM form lifecycle |

## Autopilot CRO capability matrix

| Capability | Contact Form 7 | WPForms | Elementor Pro Forms | Standard HTML |
|---|---:|---:|---:|---:|
| CTA presentation | Yes | Yes | Yes | Yes |
| Field order | Runtime safety proof required | Runtime safety proof required | Runtime safety proof required | Runtime safety proof required |
| Progressive disclosure | Runtime safety proof required | Runtime safety proof required | Runtime safety proof required | Runtime safety proof required |
| Multi-step | Runtime safety proof required | Runtime safety proof required | Runtime safety proof required | Conditional/runtime safety proof required |
| Primary result | Provider-confirmed conversion | Provider-confirmed conversion | Provider-confirmed conversion | Observed submit rate |

## Field ROI attribution matrix

| Capability | Contact Form 7 | WPForms Lite / Pro | Elementor Pro Forms | Standard HTML |
|---|---:|---:|---:|---:|
| Opaque submission ID | Yes | Yes | Yes | No universal confirmation |
| Provider-confirmed linkage | Yes | Yes | Yes | No |
| Provider entry mapping | Public lifecycle has no entry ID | Safe non-zero entry ID when available | Not persisted in v1 | No |
| Stable native field identity | CF7 tag name | WPForms field ID | Elementor field `_id` | Best-effort only; ROI disabled |
| Structural schema snapshot | Yes | Yes | Yes | No |
| Controlled Field ROI | Safe Autopilot mutations | Safe Autopilot mutations | Safe Autopilot mutations | Not eligible |

The Field ROI support flag means that the provider adapter can join a
provider-confirmed success to an opaque Formhawk submission and stable structural
field definitions. It does not mean that every provider configuration is safe for
every experiment; conditional, required, security, payment, file and ambiguous
controls continue to fail closed.

All structural mutations use provider-native field identities where available and
fail closed for conditional, required, security, legal, payment, CAPTCHA, upload
or ambiguous fields. The signed attribution marker is removed before provider
entry/mail/CRM processing. Full architecture and test boundaries are documented
in [AUTOPILOT-CRO.md](AUTOPILOT-CRO.md).

Provider identifiers are namespaced by the Formhawk form key, so the same numeric provider ID cannot collide across Contact Form 7, WPForms and Elementor.

## Event semantics and deduplication

- `form_submit` is a browser-observed submit attempt.
- Generic HTML increments only `submit_attempts`; confirmed successes and conversion display N/A.
- Contact Form 7, WPForms and Elementor increment `confirmed_successes` only from their trusted server lifecycle. `submissions` is frozen legacy evidence in 0.3.0.
- Global confirmed conversion includes supported providers in both numerator and denominator. Generic attempts and starts are excluded.
- Browser validation friction and provider-confirmed validation rejections have separate form and field counters. Provider rejection share uses paired post-upgrade rejections + accepted submissions, never browser submit attempts. Browser friction has no failure percentage.
- Provider adapters suppress repeated terminal hooks during the same server request.
- The tracker suppresses repeated submit events, view/start/interaction repeats, rediscovery of the same node, and recreated Elementor popup instances.
- Since 0.5.1, submit deduplication ends at a provider response: later independent submissions from the same AJAX form receive new attempt evidence and, after terminal completion, a new Field ROI marker. Views/starts and the experiment arm remain in the same page lifecycle. CF7 invalid, WPForms failed/error and Elementor error paths retain the marker for retry. See [Field ROI lifecycle](FIELD-ROI.md#submission-lifecycle-051).
- WPForms blocking errors without a field ID increment the form-level validation-failure aggregate once; Formhawk does not fabricate a field dimension or retain the error message.
- WPForms calls its initial-errors filter on successful submissions too. Empty errors and errors belonging to another form do not count as validation failures.
- Elementor Pro 3.35.1 fires `elementor_pro/forms/mail_sent` before a failed `wp_mail()` result becomes a handler error. Formhawk therefore defers the separate mail-action metric until the post-action handler confirms the overall outcome, and never uses the hook as universal form success.
- `wp_mail_succeeded` means WordPress accepted a mail operation; it does not prove inbox delivery.

## Privacy contract

Adapters receive provider hook arguments but only extract configured form IDs, configured titles, field error keys, configured field labels/types and technical page context. They never pass submitted values, validation messages, entry payloads, recipients, subjects, bodies or upload filenames to Formhawk storage.

Frontend tracking never reads input `.value`. The only hidden input attributes read are provider technical identifiers (`_wpcf7`, WPForms form ID, Elementor `post_id` and `form_id`). No cookie, local storage, session storage, IndexedDB, IP address, user agent or persistent visitor/session ID is used.

The public REST endpoint enforces origin checks when browser headers are available, a public routing token, strict JSON types/properties/nesting, body/event size caps, atomic request/event/cost budgets, CF7/WPForms form existence checks and site/per-form cardinality budgets. No limit uses an IP or visitor identifier. Defaults, configurable filters, evidence definitions and HTTP semantics are documented in [HARDENING.md](HARDENING.md).

Labels are captured on discovery and use only the label's own text nodes; nested controls, output and dynamic descendants are excluded. Personalized labels changed after discovery are not re-read. Explicit metadata and page paths must remain structural.

## 0.5.2 CRO integrity contract

- CF7, WPForms and Elementor retain their existing provider hooks and terminal/error semantics. `_formhawk_submission` still rotates only at the terminal boundaries documented for 0.5.1; `_formhawk_cro` retains the issued page/form arm across repeated attempts.
- A v2 signed context must also exist in short-lived server issuance storage before provider attribution is accepted. Trusted provider callbacks do not depend on browser view/attempt/latency delivery. Expired or old v1 contexts lose CRO attribution without blocking the underlying form or independent Field ROI linkage.
- Each DOM instance receives its own context, even when two forms share a provider definition. CRO browser events carry bounded attempt sequence numbers; server retry and one-shot admission are transactional.
- Generic HTML remains advisory and never gains Field ROI/provider-confirmed attribution. It can be observed and manually approved/promoted; Full Autopilot cannot decide from browser submit reports.
- Regression coverage includes HMAC/issuance/expiry, REST lifecycle replay, two independent concurrent database connections, provider attribution across repeated requests, migration/recovery, protected statistical denominators and fail-open frontend mutations. This release does not add a new claim of real-provider browser E2E coverage.

## 0.6.0 Minimum Form capability contract

Minimum Form requires both provider-confirmed success and a provider-definition
dependency graph before it can create an automatic semantic experiment. Contact Form
7 and WPForms advertise removal only for optional, independent fields after checking
required flags, conditionals and detectable mail/action/CRM/calculation mappings.
Unknown dependencies fail closed.

Elementor Pro supplies confirmed outcomes but not a complete public dependency graph,
so 0.6.0 does not autonomously simplify it. Generic HTML has neither universal server
confirmation nor a provider dependency definition and remains ineligible. The runtime
has separate reversible remove/optional/required strategies, but the built-in adapters
do not advertise optional/required semantics; Formhawk never suppresses provider
validation to manufacture success. See [Minimum Viable Form](MINIMUM-FORM.md).

One issued CRO context contributes at most one binary confirmed conversion even when a
provider repeats its callback. Later independent provider submissions retain separate
opaque submission IDs and outcome rows. This separates experiment conversion math from
submission/outcome volume without storing visitor values.

## 0.5.1 resubmission regression verification (2026-09-08)

- Reproduced the original defect before implementation: 13 JS regressions failed, including unchanged markers for all three providers and missing second CRO attempts. A separate database regression reproduced silent acceptance of conflicting provider entry IDs.
- 75 Vitest tests passed, including combined tracker/CRO provider callbacks, repeated successes, validation/error retries, delayed in-flight duplicate submits, provider reset/focus, dynamic replacement, simultaneous instances, secure-randomness failure and source/runtime privacy guards.
- 132 PHPUnit tests / 1,233 assertions passed on both WordPress 7.1 / PHP 8.2.12 and WordPress 6.4 / PHP 7.4.33 in separate disposable databases. Repository tests preserve one row for retries and two distinct rows/entry IDs for independent IDs; conflicts preserve the original row and add only structural audit evidence.
- An additional jsdom-to-PHP/WordPress database smoke passed for CF7, WPForms and Elementor: initial browser ID A, server row A, terminal callback, new browser ID B, server row B. Replaying either ID remained idempotent; views/starts stayed at one and no abandonment was emitted. Provider browser callbacks were simulated in this smoke; it is not a real-provider browser E2E test.
- Verified terminal/reset/error order against installed Contact Form 7 6.1.7, WPForms Lite 2.0.1.1 and Elementor Pro 3.35.1 source. No new claim of WPForms Pro or Elementor Pro browser E2E coverage is made.
- PHPCS/PHPCompatibility, ESLint, PHPStan, strict Composer validation, byte-identical rebuild and official Plugin Check on the unpacked release ZIP passed. Tracker: 11,872 bytes minified / 4,572 gzip; CRO bundle: 17,490 / 6,357 bytes. No new dependencies, polling, storage or external requests were added.

## Provider verification from the 0.2.0 development run

- Contact Form 7 6.1.7: installed and active.
- WPForms Lite 2.0.1.1: installed from WordPress.org; actual multiple-form markup, an AJAX validation failure, an AJAX success and a non-AJAX success were exercised through the real HTTP/server pipeline. Stored aggregates were checked for the synthetic submitted values.
- Elementor 4.2.4 (Free): installed from WordPress.org; graceful inactive-Pro behavior exercised.
- Elementor Pro 3.35.1 with Elementor 3.35.7: a licensed local package was available in a separate development site. Actual rendered markup was inspected, including forms with identical widget IDs in different documents. Real `Form_Record` and `Ajax_Handler` objects exercised validation, success, mail and action-failure normalization with privacy assertions. Formhawk was not installed into that separate site, so this was a real API compatibility test, not a full browser submission E2E.
- WPForms Pro: package was not present, so no Pro-only real E2E was run. The shared public WPForms lifecycle is covered by Lite and contract tests.
- Interactive browser/DevTools testing was not run because the in-app browser backend reported no available browser.

## Hardening verification on 2026-09-07

- 67 PHP tests / 880 assertions passed against WordPress 7.1 with PHP 8.2.12 and WordPress 6.4 with PHP 7.4.33, in disposable sites. This includes real SQL/REST/migration tests and eight-process admission tests, not a full provider compatibility matrix.
- 36 JS tests passed, including source-level forbidden API protection, dynamic forms/fields, native and jQuery validation, pending validation, observer/network failures and personalized-label privacy regressions.
- Chromium browser smoke exercised the built tracker → real WordPress REST → database. Native invalid produced one browser friction report with zero submit attempts; a valid synthetic HTML submit reached the underlying handler and produced one observed attempt, zero confirmations and N/A confirmed conversion. Submitted values were absent from field aggregates; no cookies or local/session storage entries were created.
- WPCS/PHPCompatibility, PHPStan, ESLint and reproducible JS/CSS builds passed. Tracker size: 10,892 bytes minified, 4,216 bytes gzip in this environment.
- Official Plugin Check passed without findings on the unpacked release ZIP. Packaging excludes hidden development configuration and browser/test artifacts; three narrowly scoped SQL-scanner false positives have inline explanations for prepared, allowlisted dynamic placeholder queries.
- The 109,500-row migration benchmark completed in approximately 8.43 seconds across bounded invocations; an idempotent repeat took approximately 0.007 seconds. Historical counters were preserved. These local timings are not hosting guarantees.
- This hardening run inspected installed CF7/WPForms public source and exercised adapter contract tests. Its original scope did not repeat provider submissions; the subsequent 0.4.0 Autopilot browser run below did repeat CF7 and WPForms Lite. WPForms Pro, full Elementor Pro browser E2E and multisite remain outside current coverage.

## Autopilot CRO browser verification on 2026-09-07

- The final automated matrix passed 96 PHPUnit tests / 1,015 assertions on both PHP 8.2 + WordPress 7.1 and the supported floor PHP 7.4.33 + WordPress 6.4. It also passed 55 Vitest tests, PHPCS/PHPCompatibility, PHPStan, ESLint, strict Composer validation and a byte-identical repeat asset build.
- Chromium exercised the built 0.4.0 runtime against Contact Form 7 6.1.7 and WPForms Lite 2.0.1.1 in a disposable WordPress site. Deterministic assignment was enabled only by `WP_DEBUG` plus the server-side `FORMHAWK_CRO_TEST_MODE` constant.
- CF7 control and variant sent the same five business fields through the real REST provider endpoint. Field-order and CTA variants changed presentation only; both provider successes were attributed to their signed arm. A real `validation_failed` response incremented provider validation and latency, then remained eligible for abandonment.
- WPForms control and two-step variant sent the same rendered business field IDs through the real AJAX endpoint and both reached provider-confirmed success. The multi-step layer retained the provider submit button and WPForms received unchanged business values/keys. Field-order, progressive-disclosure and CTA variants were also applied against its real rendered markup. Its jQuery success lifecycle produced the expected CRO latency sample.
- A test-only earliest provider observer confirmed `_formhawk_cro` was absent from `$_POST` and from parsed CF7/WPForms provider data before mail/entry processing. The observer retained keys/booleans only, never submitted values.
- Generic control/progressive variants retained identical business fields and receive no hidden experiment marker. Their browser submit updated observed attempts with zero confirmed conversions. Native invalid increased client validation while the attempt counter stayed unchanged.
- Elementor Free 3.35.7 plus licensed Elementor Pro 3.35.1 were then installed in the disposable site. Real control and accessible two-step variant submissions both returned HTTP 200 and reached Elementor's `new_record` lifecycle. All six fixed synthetic business values and keys matched inside the real `Form_Record`; the technical marker was absent from `$_POST` and the record, while the confirmed success reached the correct arm. Field-order, progressive-disclosure and CTA variants were also applied to actual rendered Pro markup. Empty required fields retained focus on step one, incremented one client-validation failure and did not increment submit attempts. A second instance of the actual rendered form inserted into a dialog was independently mutated once with one marker.
- Dynamic duplicate instances, mobile assignment/segment attribution, back/forward restoration, one marker per confirmed-provider form, keyboard step navigation, corrupt-config rollback, missing/late-JS watchdog, and a page without CRO assets were exercised. SPA path reassignment and transient-config retry are additionally covered in Vitest.
- SPA reassignment requires the CRO runtime to have been enqueued on the initial document. Transitions from a page with no known active placement require the SPA/theme integration to enqueue it or refresh; Formhawk deliberately does not impose the CRO bundle on every frontend page.
- The final active-page CRO payload measured 17,414 bytes JavaScript and 939 bytes CSS before gzip (6,365 bytes and 429 bytes gzip) in this environment; a page without an active experiment loaded neither asset. Measured layout shift was approximately 0.000068. These local figures are not hosting/theme guarantees.
- Elementor's rendered telephone pattern in this tested version is rejected by current Chromium's `v`-mode regular-expression parser in both control and variant. Formhawk catches the Constraint Validation API exception during step navigation; the provider itself still logs the same pattern error on final submission in both arms. Both arms nevertheless returned provider-confirmed success. This is recorded as provider-version evidence, not hidden as an Autopilot regression.

## Remaining licensed-provider expansion checklist

The base licensed Elementor Pro control/variant lifecycle is now exercised. A broader release matrix should still add:

1. Elementor's native Popup module lifecycle in addition to dynamic insertion of real Pro markup.
2. A configured CAPTCHA service with real test credentials; forbidden-field fail-closed behavior is already automated without transmitting a CAPTCHA payload.
3. Upload, signature and third-party conditional-field add-ons across their supported version matrix; these controls currently fail closed from structural mutation.
4. Elementor Email plus multiple non-email Actions After Submit against external sandbox services. The real local no-action success lifecycle and action/mail contract objects are covered, but no external CRM is contacted by this test suite.
5. WPForms Pro-only features. WPForms Lite exercises the shared public submission lifecycle, but the Pro package is not installed.
