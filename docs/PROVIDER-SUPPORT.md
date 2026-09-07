# Provider support and verification

## Capability matrix

| Provider | Stable form identity | Browser funnel | Server-confirmed success | Server validation fields | Server failure | Mail signal | Dynamic / popup | Multi-step lifecycle |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| Contact Form 7 | CF7 form ID | Yes | `wpcf7_mail_sent` / final `wpcf7_submit` status | `wpcf7_submit` invalid field keys | mail failed, spam, aborted | mail success/failure | Yes | Same form lifecycle |
| WPForms Lite / Pro | WPForms form ID | Yes | `wpforms_process_complete` | `wpforms_process_initial_errors`, defensive `wpforms_process_after` observation | No stable universal post-processing failure hook | Global `wp_mail` only | Yes | Same form lifecycle |
| Elementor Pro Forms | document/post ID + form widget ID | Yes | `elementor_pro/forms/new_record` after actions, with a successful handler outcome | `elementor_pro/forms/validation` handler error keys | action errors visible through handler errors, messages and `is_success` | `elementor_pro/forms/mail_sent`, confirmed only with an overall successful outcome | Yes | Same form lifecycle |
| Standard HTML | explicit ID/name or deterministic structural ID, placement-scoped | Yes | Not universally available | Browser constraint validation | Not universally available | Global `wp_mail` only | Yes | Same DOM form lifecycle |

Provider identifiers are namespaced by the Formhawk form key, so the same numeric provider ID cannot collide across Contact Form 7, WPForms and Elementor.

## Event semantics and deduplication

- `form_submit` is a browser-observed submit attempt.
- Generic HTML increments only `submit_attempts`; confirmed successes and conversion display N/A.
- Contact Form 7, WPForms and Elementor increment `confirmed_successes` only from their trusted server lifecycle. `submissions` is frozen legacy evidence in 0.3.0.
- Global confirmed conversion includes supported providers in both numerator and denominator. Generic attempts and starts are excluded.
- Browser validation friction and provider-confirmed validation rejections have separate form and field counters. Provider rejection share uses paired post-upgrade rejections + accepted submissions, never browser submit attempts. Browser friction has no failure percentage.
- Provider adapters suppress repeated terminal hooks during the same server request.
- The tracker suppresses repeated submit events, view/start/interaction repeats, rediscovery of the same node, and recreated Elementor popup instances.
- WPForms blocking errors without a field ID increment the form-level validation-failure aggregate once; Formhawk does not fabricate a field dimension or retain the error message.
- WPForms calls its initial-errors filter on successful submissions too. Empty errors and errors belonging to another form do not count as validation failures.
- Elementor Pro 3.35.1 fires `elementor_pro/forms/mail_sent` before a failed `wp_mail()` result becomes a handler error. Formhawk therefore defers the separate mail-action metric until the post-action handler confirms the overall outcome, and never uses the hook as universal form success.
- `wp_mail_succeeded` means WordPress accepted a mail operation; it does not prove inbox delivery.

## Privacy contract

Adapters receive provider hook arguments but only extract configured form IDs, configured titles, field error keys, configured field labels/types and technical page context. They never pass submitted values, validation messages, entry payloads, recipients, subjects, bodies or upload filenames to Formhawk storage.

Frontend tracking never reads input `.value`. The only hidden input attributes read are provider technical identifiers (`_wpcf7`, WPForms form ID, Elementor `post_id` and `form_id`). No cookie, local storage, session storage, IndexedDB, IP address, user agent or persistent visitor/session ID is used.

The public REST endpoint enforces origin checks when browser headers are available, a public routing token, strict JSON types/properties/nesting, body/event size caps, atomic request/event/cost budgets, CF7/WPForms form existence checks and site/per-form cardinality budgets. No limit uses an IP or visitor identifier. Defaults, configurable filters, evidence definitions and HTTP semantics are documented in [HARDENING.md](HARDENING.md).

Labels are captured on discovery and use only the label's own text nodes; nested controls, output and dynamic descendants are excluded. Personalized labels changed after discovery are not re-read. Explicit metadata and page paths must remain structural.

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
- This run inspected installed CF7/WPForms public source and exercised adapter contract tests. It did not repeat live CF7/WPForms/Elementor form-submission E2E; WPForms Pro, full Elementor Pro browser E2E and multisite remain outside this run's coverage.

## Remaining full-browser Elementor Pro checklist

When a browser-enabled environment and a disposable licensed Elementor Pro test site are available:

1. Create a form with text, email, checkbox, upload and multi-step fields.
2. Add Email and a non-email Action After Submit.
3. Verify a view, start and unique field interactions.
4. Submit invalid data and verify one validation failure with field keys.
5. Submit valid data and verify one submit attempt plus one confirmed submission/success.
6. Verify Email action success is separate and does not duplicate the submission.
7. Remove the Email action and verify the form can still confirm successfully.
8. Open the form in an Elementor Popup twice and verify no duplicate view for the same popup lifecycle.
9. Insert multiple widgets, including identical names, and verify their document/widget identities remain distinct.
10. Inspect the REST request body and database aggregates for absence of submitted values and upload filenames.
