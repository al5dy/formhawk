=== Formhawk ===
Contributors: al5dy
Tags: form analytics, field roi, contact form 7, wpforms, lead attribution
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Privacy-first form analytics, Field ROI and business-value Autopilot — without cookies or field-value tracking.

== Description ==

= Understand where forms lose value =

Formhawk measures aggregate form views, starts, browser attempts, provider-confirmed successes, abandonment, validation friction and failures inside WordPress. Contact Form 7, WPForms and Elementor Pro Forms use their server lifecycle for confirmed outcomes; generic HTML submissions remain clearly labelled as browser-observed attempts.

Field ROI connects privacy-safe structural analytics to qualified, won, lost, spam and revenue outcomes. It evaluates conversion cost, qualified or won leads per visitor, revenue per visitor, evidence quality and confidence. A field can reduce raw conversion and still earn its place when it improves real business value.

= Minimum Viable Form =

Minimum Viable Form can experimentally determine which fields earn their place. After explicit opt-in, it selects one provider-verified safe field change at a time, splits traffic through the existing cache-compatible CRO runtime, measures provider-confirmed conversion and mature business outcomes, and promotes only evidence-backed winners.

Winning states become immutable baseline versions. Harmful changes are rejected, promoted baselines remain monitored, and a detected regression can automatically restore the previous validated baseline. Provider schema drift or invalid experiment data pauses optimization and fails open. The original provider form is never rewritten.

Contact Form 7 and WPForms field removal is limited to optional fields whose provider definitions prove they are independent. Security, payment, authentication, CAPTCHA, upload, signature and consent fields are forbidden. Unknown dependencies are never guessed. Elementor Pro and generic HTML remain observe-only for Minimum Form until their full dependency and confirmation requirements are available.

Without mature business outcomes, Formhawk explicitly uses Conversion Optimization Mode and does not claim business-value optimization.

= Privacy by design =

Formhawk does not intentionally store form values, names, email addresses, phone numbers, messages, IP addresses, persistent visitor or session IDs, user-agent history, email recipients, subjects or bodies. It creates no analytics cookies and does not use localStorage or sessionStorage. Analytics remain in the WordPress database and are not silently sent to Formhawk Cloud.

== Installation ==

1. Install Formhawk from Plugins > Add New, or upload the plugin directory.
2. Activate Formhawk.
3. Open Formhawk in the WordPress admin menu.
4. Visit a frontend page containing a supported form.
5. Return to Formhawk to review aggregate analytics and form health.

No tracking snippet or external analytics account is required.

== Frequently Asked Questions ==

= Which providers are supported? =

Formhawk detects Contact Form 7, WPForms Lite and Pro, Elementor Pro Forms, and eligible standard HTML forms. Provider-confirmed success is reported only when a supported server lifecycle supplies it.

= Does Formhawk store submitted values or visitor identities? =

No. The frontend tracker does not read or transmit visitor field values. Formhawk does not intentionally store names, email addresses, phone numbers, messages, IP addresses, persistent visitor IDs, session IDs or user-agent history.

= Does Formhawk use cookies or browser storage? =

No. Formhawk creates no analytics cookies and no localStorage, sessionStorage or IndexedDB visitor identifiers. Temporary attribution state exists only in JavaScript memory for the current page lifecycle.

= Does wp_mail_succeeded mean delivery to the inbox? =

No. It means WordPress accepted the mail operation without reporting an immediate failure. Formhawk does not describe that signal as external inbox delivery.

= How does Minimum Viable Form stay safe? =

The feature is off by default. It never permanently changes the provider form. It uses provider capability checks, field safety classification, dependency graphs, one semantic experiment per form, immutable baselines, schema fingerprints, experiment-integrity checks, guardrails and automatic rollback. If a mutation cannot be proven safe, the original form remains active.

= Can Minimum Viable Form optimize without revenue data? =

Yes, but it is labelled Conversion Optimization Mode. Revenue per visitor is preferred when mature revenue coverage is sufficient; otherwise Formhawk can use won leads, qualified leads or provider-confirmed conversion without claiming a business-value result it did not measure.

= How do I send a qualified, won or revenue outcome? =

Open Formhawk > Field ROI > Outcome API and create a dedicated key. Send the opaque submission ID, canonical status, integer value in minor currency units, ISO currency and an idempotency key to `/wp-json/formhawk/v1/outcomes`. WordPress Application Password authentication and the local `formhawk_record_outcome()` API are also supported.

= How do I exclude or name a generic form? =

Add `data-formhawk-ignore` to exclude a form. Use `data-formhawk-title` and optionally `data-formhawk-id` to give a generic form stable, readable identity.

= How long is analytics retained? =

The administrator can choose 30, 90, 180 or 365 days. Cleanup is bounded and scheduled. Uninstall cleanup happens only when explicitly configured.

== Screenshots ==

1. Review every tracked form with conversion metrics and evidence-aware health.
2. Inspect a form funnel, placement analytics and field friction.
3. Compare field friction and business value in Field ROI.
4. Follow Minimum Form progress, the visual form map and current experiment.
5. Review immutable baseline history and evidence-backed field decisions.
6. Inspect provider, schema, outcome and experiment-integrity diagnostics.

== Changelog ==

= 0.6.0 =

* Added the opt-in Minimum Viable Form Engine with sequential, evidence-backed semantic field experiments.
* Added reversible REMOVE_FIELD, MAKE_OPTIONAL and MAKE_REQUIRED mutation strategies with provider capability gates.
* Added structural field safety classification and fail-closed provider dependency graphs.
* Added immutable versioned form baselines, mutation genomes, schema-drift revalidation and original-form fallback.
* Added business-objective selection for revenue, qualified leads, won leads and confirmed conversion.
* Added robust revenue-per-visitor decisions with outcome maturity, coverage, expected-loss and practical-significance gates.
* Added post-promotion business regression monitoring, automatic rollback and form-level optimization locks.
* Added Minimum Form history, visual form map, before/after preview, safety diagnostics and accessible admin controls.
* Hardened CRO integrity so one assignment records at most one binary conversion while independent provider submissions remain separately attributable.
* Added schema v8 without changing existing analytics, outcomes, Field ROI results, Autopilot settings or provider form definitions.

= 0.5.2 =

* Replaced replayable CRO contexts with signed v2 contexts, random assignment IDs, issuance timestamps and short-lived hashed server lifecycle storage.
* Atomically deduplicated browser events and separated server-issued assignments from browser views.
* Restricted autonomous decisions to trusted provider evidence and added schema v7.

= 0.5.1 =

* Fixed repeated AJAX submissions being merged into one Field ROI submission.
* Preserved retry idempotency while rotating submission markers after terminal provider responses.

= 0.5.0 =

* Added Field ROI Business Value Intelligence, privacy-safe outcome attribution and robust revenue inference.
* Added qualified, won, lost, spam and revenue outcomes with immutable evidence history.
* Added a strict authenticated Outcome REST API and schema v6.

= 0.4.0 =

* Added Formhawk Autopilot CRO, reversible presentation mutations and Bayesian binary decisions.
* Added business-value objectives, guardrails, automatic promotion and rollback, and schema v5.

= 0.3.0 =

* Added hardened aggregate ingestion, provider registries, WPForms and Elementor Pro integrations.
* Separated observed attempts from provider-confirmed submissions and added placement analytics.

= 0.1.1 =

* Initial public release of Formhawk.

== Upgrade Notice ==

= 0.6.0 =

Adds the opt-in Minimum Viable Form engine and schema v8. Existing forms and Autopilot experiments are not changed automatically. Back up before upgrading and clear page/CDN caches after deploying rebuilt assets.

= 0.5.2 =

Security update for CRO replay protection and trusted-only automatic decisions. Historical data is preserved.

= 0.5.1 =

Critical fix for repeated AJAX submissions. Clear page/CDN caches and reload open pages. No database migration.
