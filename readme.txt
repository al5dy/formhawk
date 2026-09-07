=== Formhawk ===
Contributors: al5dy
Tags: form analytics, contact form 7, wpforms, elementor forms, form abandonment
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find where visitors abandon Contact Form 7, WPForms, Elementor Pro and standard HTML forms — privately, with no cookies or external analytics.

== Description ==

= Stop guessing what happens to your WordPress forms =

A form can look perfectly fine while quietly losing leads.

Visitors may:

* See the form but never start it.
* Start filling it out and abandon it.
* Get stuck on a specific field.
* Hit repeated validation errors.
* Submit the form while email sending fails.
* Stop converting as well as they did before.

**Formhawk shows you what happens between a form view and a successful submission.**

Track form conversions, discover where visitors abandon forms, find problematic fields, monitor Contact Form 7 failures, and see the health of every tracked form from your WordPress dashboard.

No external analytics account. No tracking cookies. No visitor profiles.

Just actionable form analytics stored inside WordPress.

= See where your forms are losing conversions =

Formhawk automatically tracks the full form funnel:

**Views → Starts → Submissions**

For every tracked form you can see:

* Form views.
* Form starts.
* Submissions.
* Abandonments.
* Conversion rate.
* Abandonment rate.
* Validation failures.
* Submission failures.
* Average completion time.
* Last confirmed success.
* Last detected failure.
* Conversion changes compared with the previous comparable period.

Instead of knowing only how many submissions you received, you can see **where potential conversions are being lost.**

= Find the fields that make visitors leave =

A form may have traffic and still convert poorly because one field creates unnecessary friction.

Formhawk tracks field-level interactions without reading the values visitors enter.

See:

* Which field was interacted with last before abandonment.
* Which fields generate validation errors.
* How often individual fields create friction.

This helps you identify fields that may be confusing, unnecessary, incorrectly configured, or hurting conversion.

= Know when a form stops working =

A broken form can cost leads without producing an obvious WordPress error.

Formhawk continuously evaluates tracked forms and assigns a clear health state:

* **Collecting** — not enough data yet.
* **Healthy** — the form is receiving normal successful activity.
* **Warning** — suspicious behavior has been detected.
* **Critical** — a newer failure exists after the last confirmed success.

Formhawk can also warn about situations such as:

* Visitors starting a form but no submissions being recorded.
* Forms being viewed but never started.
* Conversion dropping compared with the previous comparable period.
* Provider-confirmed outcomes and submit attempts that receive no matching success signal.
* WordPress mail failures.

You do not need to manually test every form on your website just to know whether it still appears to be working.

= First-class supported form providers =

Formhawk automatically distinguishes these providers before the standard HTML fallback:

* Contact Form 7.
* WPForms Lite and WPForms Pro.
* Elementor Pro Forms.
* Standard HTML forms.

Contact Form 7, WPForms and Elementor Pro Forms combine frontend behavior analytics with provider lifecycle hooks. A browser submit is an attempt; Formhawk records a confirmed successful submission only when the provider reaches its accepted/success lifecycle.

= Contact Form 7 analytics =

Formhawk combines frontend behavior analytics with Contact Form 7 server-side hooks to track:

* Confirmed successful submissions.
* Mail sending failures.
* Validation failures.
* Validation errors by field.
* Aborted submissions.
* Form abandonment.
* Field-level friction.
* Conversion rates.
* Form health.

This allows Formhawk to distinguish between a visitor merely attempting to submit a Contact Form 7 form and WordPress actually reporting a successful submission.

= WPForms Lite and Pro analytics =

Formhawk uses WPForms' successful processing lifecycle to confirm accepted submissions. This works when WPForms Lite supplies an entry ID of zero and does not require entry storage.

WPForms analytics include:

* Stable WPForms form and field IDs.
* AJAX and non-AJAX submit attempts.
* Provider-confirmed successful processing.
* Validation failures and affected fields.
* Compound field normalization.
* Multiple and dynamically inserted forms.

= Elementor Pro Forms analytics =

Elementor Pro Forms are identified by their document context and stable form widget ID. Formhawk observes Elementor's validation and post-action record lifecycle without modifying the form record, AJAX response or Actions After Submit. A post-action record is confirmed only when Elementor's handler still reports a successful outcome.

Elementor's `mail_sent` hook is treated as evidence about the Email action and becomes a separate mail-action success metric only after the overall action pipeline succeeds. It is not the universal definition of a successful form, because an Elementor form may use other actions or no Email action at all.

= Works with standard HTML forms too =

Formhawk also automatically discovers eligible standard HTML `<form>` elements.

This includes forms that are:

* Present when the page loads.
* Added dynamically with JavaScript.
* Loaded through AJAX.
* Opened inside popups or modals.

For generic HTML forms, Formhawk records the browser submission event as an observed submission attempt.

Because arbitrary custom form handlers cannot universally be verified from the browser, Formhawk does not claim a server-confirmed success where one cannot actually be verified.

= Zero complicated setup =

Install Formhawk and activate it.

That's it.

There is:

* No tracking snippet to install.
* No external Formhawk account to create.
* No analytics service to connect.
* No JavaScript configuration.
* No cookie banner integration required specifically for Formhawk analytics.
* No form-by-form setup for supported forms.

Formhawk automatically discovers supported frontend forms and starts collecting aggregate analytics when visitors interact with them.

Open **Formhawk** in your WordPress dashboard to see the results.

= Privacy-first form analytics =

Form analytics should not require building profiles of your visitors.

Formhawk is intentionally designed without visitor-level analytics.

**Formhawk does not intentionally store:**

* Form field values.
* Names entered into forms.
* Email addresses entered into forms.
* Phone numbers entered into forms.
* Message contents.
* IP addresses.
* Visitor IDs.
* Session IDs.
* User agents.
* Email recipients.
* Email subjects.
* Email message bodies.

Formhawk also creates:

* No analytics cookies.
* No `localStorage` tracking.
* No `sessionStorage` tracking.
* No persistent browser identifier.

Temporary per-form state exists only in JavaScript memory for the current page.

Analytics are stored locally in your WordPress database as aggregate statistics.

**No Formhawk analytics data is sent to an external Formhawk analytics service.**

= Your analytics stay in WordPress =

Formhawk stores daily aggregate analytics locally in WordPress.

You control how long analytics are retained:

* 30 days.
* 90 days.
* 180 days.
* 365 days.

Expired analytics are cleaned automatically.

You can also choose whether Formhawk data should be completely removed when the plugin is uninstalled.

= Monitor WordPress email health =

Forms often depend on WordPress email.

Formhawk monitors the WordPress `wp_mail_failed` and `wp_mail_succeeded` hooks without storing recipients or email contents.

You can:

* See WordPress mail success and failure health signals.
* See recent mail failures.
* Run a manual `wp_mail()` diagnostic test.

A successful `wp_mail()` event means WordPress/PHPMailer accepted the message for sending without an immediate error.

It does **not** guarantee that the message ultimately reached the recipient's inbox.

= Designed for useful data, not visitor surveillance =

Formhawk uses:

* Local daily aggregate storage.
* Batched frontend event delivery.
* A dependency-free frontend tracker.
* Same-origin REST event ingestion.
* A public, site-bound integrity token. It is abuse resistance, not a visitor secret.
* `IntersectionObserver` for actual form-view detection.
* `MutationObserver` for dynamically inserted forms.

The goal is simple:

**Show you whether your forms work and where they lose conversions — without collecting the contents of your visitors' submissions.**

= What Formhawk tracks =

**Form analytics**

* Views.
* Starts.
* Interactions.
* Submissions.
* Abandonments.
* Failures.
* Conversion rates.
* Abandonment rates.
* Average completion time.

**Field analytics**

* Last interacted field before abandonment.
* Validation errors by field.
* Field interaction signals.

**Form health**

* Collecting, Healthy, Warning and Critical states.
* Last confirmed success.
* Last failure.
* Conversion-drop detection.
* Started forms with no submissions.
* Viewed forms with no starts.

**Contact Form 7**

* Confirmed submission success.
* Mail failures.
* Validation failures.
* Aborted submissions.
* Field validation errors.

**WPForms Lite / Pro**

* Provider-confirmed successful processing.
* Validation failures and fields.
* Stable form and compound-field identity.
* Dynamic and multiple-form discovery.

**Elementor Pro Forms**

* Provider-confirmed accepted records after form actions.
* Validation failures and fields.
* Separate Email-action success signals.
* Popup, dynamic and multi-step form discovery.

**WordPress mail**

* `wp_mail()` success signals.
* `wp_mail()` failure signals.
* Manual mail diagnostic test.

**Privacy**

* No analytics cookies.
* No persistent browser storage.
* No visitor IDs.
* No IP address storage.
* No user-agent storage.
* No submitted field values.
* No external Formhawk analytics service.

= Developer controls =

Individual forms can be excluded with:

`data-formhawk-ignore`

Generic forms can receive readable identifiers using:

`data-formhawk-title`

and:

`data-formhawk-id`

Tracking can be disabled programmatically with the:

`formhawk_tracking_disabled`

filter.

Developers can integrate with recorded aggregate events using the:

`formhawk_event_recorded`

action.

== Installation ==

1. Install **Formhawk** from Plugins > Add New, or upload the plugin ZIP.
2. Activate Formhawk.
3. Open **Formhawk** in your WordPress admin menu.
4. Visit a frontend page containing Contact Form 7, WPForms, Elementor Pro Forms or a standard HTML form.
5. Formhawk automatically starts collecting aggregate analytics as visitors use your forms.
6. Return to **Formhawk** to review conversions, abandonment, field friction and form health.

**No tracking snippet, external account or additional analytics configuration is required.**

== Frequently Asked Questions ==

= What does Formhawk do? =

Formhawk tracks how visitors interact with WordPress forms and helps identify conversion problems and form failures.

It shows views, starts, submissions, abandonment, conversion rates, field-level friction and form health.

= Does Formhawk work with Contact Form 7? =

Yes.

Yes. Contact Form 7 includes server-confirmed success tracking, mail failures, validation failures and aborted submissions.

= Does Formhawk work with WPForms Lite and WPForms Pro? =

Yes. WPForms forms are detected automatically and successful processing is confirmed server-side. WPForms Lite does not need to create a stored entry for Formhawk to confirm success.

= Does Formhawk work with Elementor forms? =

Yes, when Elementor Pro Forms is active. Elementor Free alone does not include the Form widget, so the server integration remains safely inactive. Formhawk tracks Elementor popup and dynamically inserted forms from the frontend.

= Does Formhawk work with normal HTML forms? =

Yes.

Formhawk automatically detects eligible standard HTML `<form>` elements, including forms inserted dynamically after page load.

Generic HTML form submissions are recorded as observed browser submission attempts because arbitrary custom server-side handlers cannot universally be verified.

= Can Formhawk show where visitors abandon a form? =

Yes.

Formhawk tracks started forms that are left without completion and records the last interacted field before abandonment.

It does this without storing the value entered into that field.

= Can Formhawk show which fields have validation problems? =

Yes.

For Contact Form 7, WPForms and Elementor Pro Forms, Formhawk records aggregate validation failure counts by field when the provider exposes stable error keys.

= Does Formhawk store submitted form values? =

No.

The frontend tracker does not read or transmit visitor field values.

Only static metadata required for aggregate reporting, such as field names and labels, is used.

= Does Formhawk store names or email addresses entered by visitors? =

No.

Formhawk does not intentionally store names, email addresses, phone numbers, message contents or other submitted field values.

= Does Formhawk use cookies? =

No.

Formhawk creates no analytics cookies.

= Does Formhawk use localStorage or sessionStorage? =

No.

Formhawk does not create analytics entries in `localStorage` or `sessionStorage`.

= Does Formhawk store IP addresses or visitor IDs? =

No.

Formhawk does not intentionally store IP addresses, visitor IDs, session IDs or user agents for its analytics.

= Does Formhawk send analytics to an external service? =

No.

Current Formhawk analytics are stored locally in your WordPress database.

No external Formhawk analytics account is required.

= Do I need to add tracking code to my website? =

No.

Formhawk automatically discovers supported forms after activation.

= Does Formhawk detect dynamically loaded forms? =

Yes.

Formhawk can discover forms inserted after page load, including forms loaded with AJAX or displayed in popups.

= Can Formhawk detect Contact Form 7 mail failures? =

Yes.

Formhawk uses Contact Form 7 server-side hooks to record mail failures associated with Contact Form 7 submissions.

= Can Formhawk monitor wp_mail()? =

Yes.

Formhawk monitors WordPress `wp_mail_failed` and `wp_mail_succeeded` events without storing the email recipient, subject or message body.

It also includes a manual `wp_mail()` diagnostic test.

= Does wp_mail_succeeded mean the email reached the inbox? =

No.

It means WordPress/PHPMailer completed the send operation without reporting an immediate error.

Final delivery can still fail later at another point in the email delivery chain.

= Can Formhawk confirm every form submission? =

No plugin can universally verify every arbitrary custom server-side form handler from the browser.

Contact Form 7, WPForms and Elementor Pro outcomes are confirmed through their server-side lifecycle hooks.

Generic HTML forms are therefore recorded as observed browser submission attempts rather than falsely reported as confirmed successes.

= How do I exclude a form from Formhawk? =

Add `data-formhawk-ignore` to the `<form>` element:

`<form data-formhawk-ignore>`

= How do I give a generic form a readable name? =

Use `data-formhawk-title` and optionally `data-formhawk-id`:

`<form data-formhawk-title="Request a Quote" data-formhawk-id="request-quote">`

= Can developers disable tracking programmatically? =

Yes.

Return `true` from the `formhawk_tracking_disabled` filter.

= Is there a developer hook after an event is recorded? =

Yes.

The `formhawk_event_recorded` action fires after an aggregate event is recorded.

= How long does Formhawk keep analytics? =

You can choose a retention period of:

* 30 days.
* 90 days.
* 180 days.
* 365 days.

Expired daily aggregate analytics are cleaned automatically.

= What happens to the data when Formhawk is uninstalled? =

Analytics can be preserved by default.

Enable the uninstall cleanup setting if you want Formhawk data removed when the plugin is deleted.

== Screenshots ==

1. See every tracked form at a glance with conversion metrics and clear health status.
2. Analyze views, starts, submissions, abandonment, conversion rates and health for an individual form.
3. Find fields associated with abandonment and validation friction.
4. Monitor WordPress email health and run a manual mail diagnostic test.
5. Control analytics retention and whether data is removed when Formhawk is uninstalled.

== Changelog ==

= 0.2.0 =

* Added first-class WPForms Lite and Pro discovery, stable identity, server-confirmed success and validation analytics.
* Added first-class Elementor Pro Forms discovery, stable document/widget identity, validation, post-action outcome checks and separate Email-action signals.
* Added provider registry and capability contracts for future form integrations.
* Separated browser submit attempts from provider-confirmed submissions to prevent double counting.
* Added placement-specific aggregate analytics without visitor identifiers or raw event storage.
* Improved Contact Form 7 terminal-event deduplication and validation aggregation.
* Added dynamic/popup lifecycle deduplication, compound field normalization and privacy regression coverage.
* Added bounded dashboard pagination and restart-safe chunked retention cleanup for large sites.
* Added reproducible JS/CSS build, PHPUnit, Vitest, ESLint, PHPStan and WPCS tooling.

= 0.1.1 =

* Hardened SQL preparation for aggregate counter upserts.
* Updated WordPress compatibility metadata for WordPress 7.1.
* Resolved Plugin Check release-blocking issues.

= 0.1.0 =

* Initial public MVP release.
* Added Contact Form 7 and standard HTML form discovery.
* Added views, starts, interactions, submissions and abandonment analytics.
* Added field-level abandonment and validation reporting.
* Added conversion, abandonment and timing metrics.
* Added server-side Contact Form 7 success, failure and validation monitoring.
* Added WordPress mail health monitoring and diagnostics.
* Added health states and conversion-drop detection.
* Added local aggregate storage, retention cleanup and privacy controls.
* Added dynamic form discovery and developer extension hooks.

== Upgrade Notice ==

= 0.2.0 =

Adds first-class WPForms and Elementor Pro Forms analytics. The aggregate-only database schema upgrades automatically and preserves existing Contact Form 7 and standard HTML analytics.

= 0.1.1 =

Plugin Check hardening and WordPress 7.1 compatibility metadata.

= 0.1.0 =

Initial public release of Formhawk.
