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
- Generic HTML increments observed submissions from that browser event because no universal server confirmation exists.
- Contact Form 7, WPForms and Elementor increment `submissions` and `confirmed_successes` only from their trusted server lifecycle.
- Provider adapters suppress repeated terminal hooks during the same server request.
- The tracker suppresses repeated submit events, view/start/interaction repeats, rediscovery of the same node, and recreated Elementor popup instances.
- WPForms blocking errors without a field ID increment the form-level validation-failure aggregate once; Formhawk does not fabricate a field dimension or retain the error message.
- Elementor Pro 3.35.1 fires `elementor_pro/forms/mail_sent` before a failed `wp_mail()` result becomes a handler error. Formhawk therefore defers the separate mail-action metric until the post-action handler confirms the overall outcome, and never uses the hook as universal form success.
- `wp_mail_succeeded` means WordPress accepted a mail operation; it does not prove inbox delivery.

## Privacy contract

Adapters receive provider hook arguments but only extract configured form IDs, configured titles, field error keys, configured field labels/types and technical page context. They never pass submitted values, validation messages, entry payloads, recipients, subjects, bodies or upload filenames to Formhawk storage.

Frontend tracking never reads input `.value`. The only hidden input attributes read are provider technical identifiers (`_wpcf7`, WPForms form ID, Elementor `post_id` and `form_id`). No cookie, local storage, session storage, IndexedDB, IP address, user agent or persistent visitor/session ID is used.

The public REST endpoint enforces origin checks when browser headers are available, a site-bound public token, strict event and batch allowlists, body/event size caps, and a coarse site-wide burst limit. The burst counter contains no IP address or visitor identifier and can be adjusted with `formhawk_event_request_limit` for very high-traffic sites.

## Tested versions in this development run

- Contact Form 7 6.1.7: installed and active.
- WPForms Lite 2.0.1.1: installed from WordPress.org; actual multiple-form markup, an AJAX validation failure, an AJAX success and a non-AJAX success were exercised through the real HTTP/server pipeline. Stored aggregates were checked for the synthetic submitted values.
- Elementor 4.2.4 (Free): installed from WordPress.org; graceful inactive-Pro behavior exercised.
- Elementor Pro 3.35.1 with Elementor 3.35.7: a licensed local package was available in a separate development site. Actual rendered markup was inspected, including forms with identical widget IDs in different documents. Real `Form_Record` and `Ajax_Handler` objects exercised validation, success, mail and action-failure normalization with privacy assertions. Formhawk was not installed into that separate site, so this was a real API compatibility test, not a full browser submission E2E.
- WPForms Pro: package was not present, so no Pro-only real E2E was run. The shared public WPForms lifecycle is covered by Lite and contract tests.
- Interactive browser/DevTools testing was not run because the in-app browser backend reported no available browser.

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
