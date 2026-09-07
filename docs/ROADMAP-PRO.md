# Formhawk Pro & Cloud — Product and Engineering Roadmap

**Document status:** Living roadmap containing future commercial work only  
**Dependency:** stable public contracts from Formhawk Free/Core v2  
**Review date:** 2026-09-01  
**Target:** Formhawk Pro 1.0 plus Formhawk Cloud  
**Commercial rule:** customers pay for automation, external verification, cloud reliability, advanced operations, collaboration, and scale — not for artificial locks on useful local features

No Formhawk Pro implementation is present in the reviewed Formhawk 0.2.0 codebase. The Free plugin now has provider, identity, placement and aggregate-event boundaries, but this roadmap remains dependency-driven: Cloud work must use those public contracts rather than duplicate local analytics.

---

# 1. Product north star

Free answers:

> What is happening with my forms, and how trustworthy is the evidence?

Pro answers:

> Watch every important form for me, test the complete lead path from outside my site, alert the right person when it breaks, explain the evidence, and manage this across all my sites.

The flagship paid experience should be:

1. Formhawk tests the real page from an external browser;
2. verifies that the form is present and usable;
3. optionally performs a safe synthetic submission;
4. verifies the provider result;
5. verifies email or configured destination arrival;
6. opens one deduplicated incident when the chain breaks;
7. alerts through a channel independent of the failing WordPress mail stack;
8. reports recovery automatically.

For agencies:

> One calm dashboard proves whether every revenue-critical form across every client site is alive.

---

# 2. Commercial product principles

## 2.1 Sell an outcome, not more charts

The durable paid outcome is fewer silently lost leads and less manual QA.

Pro priorities:

- external proof;
- proactive alerts;
- incident workflow;
- full-path delivery checks;
- multi-site operations;
- client-ready reporting;
- advanced analysis with evidence;
- integrations and automation.

## 2.2 Do not paywall provider reach

Major provider adapters, local health, placements, friction, basic anomalies, local exports, and local diagnostics belong in Free.

Pro may add provider-specific synthetic recipes, external workers, cloud history, advanced cross-site intelligence, and managed destinations because those require ongoing infrastructure and service.

## 2.3 No false certainty

Pro must distinguish:

- browser-observed;
- provider-confirmed;
- WordPress/provider mail accepted;
- externally delivered;
- downstream destination verified;
- failed;
- inconclusive;
- monitor infrastructure error.

Never tell a customer “your form is broken” when Formhawk's worker or network is the failing component.

## 2.4 Privacy remains a product advantage

Cloud should receive the minimum aggregate/operational data required for an explicitly enabled feature. Synthetic test values are Formhawk-generated, never copied from real visitors.

## 2.5 Free failure or license expiry must not break the site

Free local analytics continues when:

- Pro is missing or deactivated;
- license is expired;
- entitlement API is unavailable;
- cloud is unavailable;
- API versions are temporarily incompatible.

No licensing or cloud call belongs on ordinary frontend visitor requests.

---

# 3. Prerequisites and go/no-go gates

Do not begin a paid beta until Free provides:

- canonical evidence/outcome schema;
- stable form definition and placement identities;
- provider capability registry;
- versioned migrations;
- public analytics/health read contracts;
- admin and diagnostics extension slots;
- tested CF7 plus at least one additional high-reach provider adapter;
- deterministic health/confidence outputs;
- privacy-safe synthetic exclusion contract;
- compatible version negotiation.

Go/no-go gate:

> Pro can be removed without changing Free data, Free behavior, or the customer's underlying forms.

---

# 4. Packaging and architecture

## 4.1 Three separate products

### `formhawk`

WordPress.org Free plugin:

- local event/identity/provider contracts;
- local aggregates and health;
- local admin;
- extension API.

### `formhawk-pro`

Commercial add-on:

- dependency/version checks;
- account/license connection;
- cloud client and durable outbox;
- monitor/rule configuration UI;
- local-to-cloud sync policy;
- advanced local modules where justified;
- cloud status and disconnect controls.

### Formhawk Cloud

Separate service/repository:

- API gateway and tenant authorization;
- scheduler and monitor queue;
- Playwright/Chromium worker fleet;
- result and evidence processor;
- delivery-verification service;
- incident engine;
- notification service;
- cloud analytics/reporting;
- agency dashboard;
- public API/webhooks;
- billing and entitlements.

Never ship cloud backend code inside the WordPress plugin.

## 4.2 Dependency direction

Pro consumes versioned public Free contracts. It must not:

- redefine Free classes;
- query undocumented Free tables directly;
- call private methods;
- copy provider logic;
- render inside Free screens without registered extension slots.

## 4.3 Compatibility negotiation

Define:

- minimum/maximum compatible Free versions;
- cloud API and payload schema versions;
- capability negotiation;
- deprecation window;
- backward-compatible server responses;
- admin guidance for missing/old/new Free;
- remote kill switch only for disabling unsafe cloud operations, never for downloading/executing code.

---

# 5. P0 — Pro foundation

## 5.1 License and account lifecycle

Support:

- connect/activate;
- revoke/disconnect;
- site transfer;
- expired and grace states;
- cached entitlements;
- offline tolerance;
- account mismatch recovery;
- staging/development environment rules;
- no destructive cleanup on temporary expiry.

Admin states:

- Not connected;
- Connected;
- Expiring;
- Expired;
- Grace period;
- Cloud unavailable;
- Incompatible version;
- Revoked.

License outages cannot produce frontend calls, fatals, or loss of Free analytics.

## 5.2 Site identity and credentials

On explicit connection, generate:

- random installation UUID;
- public site ID;
- private signing secret stored with appropriate WordPress protections;
- credential creation/rotation timestamps;
- environment label (`production`, `staging`, `development`).

This identifies a customer installation, never a visitor.

Support:

- secret rotation;
- site cloning detection/recovery;
- domain change workflow;
- replay-safe reconnect;
- clean disconnect.

## 5.3 Signed, versioned communication

WordPress → Cloud requests include:

- site ID;
- API/schema version;
- timestamp;
- unique request ID;
- body hash;
- HMAC signature.

Cloud rejects invalid signatures, expired timestamps, replayed request IDs, unknown sites, oversized payloads, and tenant mismatches.

Cloud → WordPress callbacks must use equivalent authentication and a strict command allowlist. Never accept arbitrary executable instructions.

## 5.4 Durable privacy-safe outbox

The Pro add-on needs a bounded local outbox for cloud sync and alert/monitor configuration changes.

Requirements:

- idempotency keys;
- exponential backoff with jitter;
- retry caps/dead-letter state;
- chunked sending;
- encrypted secrets, never whole payload logging;
- WP-Cron tolerance;
- queue size/age diagnostics;
- manual retry for administrators;
- no blocking visitor requests;
- safe behavior under duplicate cron execution.

## 5.5 Cloud data contract and minimization

Default sync may contain only enabled operational data such as:

- stable site/form/placement IDs;
- display name/path when explicitly enabled;
- provider and capability version;
- aggregate metrics;
- health/confidence state;
- failure category and timestamp;
- synthetic monitor/result metadata;
- plugin/API version.

Never sync:

- real visitor form values;
- visitor names/emails/phones/messages;
- raw IPs;
- persistent visitor/session IDs;
- arbitrary cookies;
- raw provider payloads;
- WordPress salts/secrets;
- email bodies/recipients/subjects from real visitors;
- full plugin/theme inventory unless a separate explicit feature requires and discloses it.

Provide a human-readable “Data sent to Formhawk Cloud” screen before connection and per optional module.

## 5.6 Disconnect, export, and deletion

Users must be able to:

- pause sync;
- disconnect a site;
- revoke credentials;
- export cloud configuration/operational history where applicable;
- request cloud deletion;
- see deletion state and retention window;
- keep all Free local analytics.

Deletion and credential revocation must be idempotent and auditable.

## 5.7 Multi-tenant security floor

Before accepting payment:

- tenant ownership on every entity/query;
- authorization tests for cross-tenant access;
- TLS everywhere;
- managed secret storage;
- encryption at rest and in transit where appropriate;
- rate limiting/WAF;
- least-privilege service identities;
- immutable audit records for sensitive actions;
- dependency/container scanning;
- encrypted tested backups;
- restore drills;
- credential rotation;
- incident response and responsible disclosure process;
- data retention/deletion jobs;
- production access controls and logs.

---

# 6. P0 — Full-path synthetic monitoring

This is the flagship Pro capability.

## 6.1 Monitor levels

Offer explicit levels so customers can start safely:

### Presence check

- page reachable;
- expected form/placement present;
- provider runtime loaded;
- submit control available;
- no submission performed.

### Interaction check

- form can receive focus/input;
- required structure is present;
- client validation can run;
- no terminal submission unless enabled.

### Submission check

- fill approved synthetic values;
- submit through the real browser path;
- observe redirect/message/provider outcome;
- measure milestones and latency.

### Delivery check

- verify a unique synthetic message at Formhawk's external mailbox;
- report delivery latency;
- do not retain unnecessary body content.

### Destination check

- verify the synthetic correlation token reached an explicitly connected downstream system such as a webhook endpoint, supported CRM, or spreadsheet integration;
- verify only Formhawk-generated test records, never inspect unrelated customer leads.

## 6.2 Setup recorder and dry run

Create a wizard:

1. choose site/form/placement;
2. choose monitor level;
3. let a worker inspect the form structure;
4. map required fields to synthetic generators;
5. configure expected success signal/redirect;
6. configure synthetic cleanup/exclusion;
7. run a dry test;
8. show every action before saving;
9. start schedule only after explicit confirmation.

Do not silently modify the customer's form.

## 6.3 Provider recipes

Build versioned recipes for supported adapters:

- stable selectors/IDs;
- expected lifecycle;
- validation/spam/success/failure signals;
- multi-step navigation;
- AJAX/non-AJAX behavior;
- anti-spam considerations;
- cleanup/exclusion capabilities.

If a provider recipe is incompatible, the result is `Unsupported` or `Inconclusive`, not a customer failure.

## 6.4 Safe synthetic values

Use generated data only:

- recognizable name such as “Formhawk Test”;
- unique alias controlled by Formhawk for email verification;
- documented non-real phone/address placeholders appropriate to locale;
- correlation token;
- no production secrets.

Provide per-field preview and customer override. Never reuse real submissions as fixtures.

## 6.5 Synthetic analytics and business-system exclusion

Use a trusted, expiring, signed marker between Cloud and the Pro add-on so known synthetic traffic can be excluded from Free aggregates.

Requirements:

- not a public query parameter anyone can copy permanently;
- replay protection;
- explicit provider support matrix;
- CRM/entry cleanup guidance;
- optional automatic cleanup only when a public API exists and the user explicitly authorizes the exact scope;
- visible test history.

## 6.6 Scheduling and retries

Initial intervals:

- 60 minutes;
- 30 minutes;
- 15 minutes;
- 5 minutes for higher service levels after capacity proof.

Use confirmation retries to reduce false incidents, but show both initial and confirmation results. Never retry a real-looking submission so aggressively that it floods a CRM/mailbox.

## 6.7 Regions and network evidence

Start with one reliable region. Add EU, North America, and Asia only after regional capacity/SLOs are proven.

Multi-region results should distinguish:

- global site/form failure;
- regional DNS/CDN/WAF failure;
- worker-region outage;
- inconsistent/inconclusive result.

## 6.8 Failure taxonomy

Classify at least:

- DNS/TLS/connectivity;
- HTTP error/redirect loop;
- page timeout;
- form not found;
- form structure changed;
- required field mapping invalid;
- browser JavaScript error affecting the test;
- client validation rejected;
- provider rejected/failed/spam;
- success signal missing;
- unexpected redirect;
- WordPress mail failed;
- external mailbox not received;
- destination record not received;
- WAF/CAPTCHA blocked worker;
- monitor infrastructure failure;
- unsupported;
- inconclusive;
- unknown.

## 6.9 Evidence artifacts

Default evidence:

- timestamp and region;
- timing milestones;
- URL/status/redirect summary;
- provider lifecycle result;
- sanitized error category;
- selector/schema change summary.

Optional short-retention artifacts:

- screenshot on failure;
- sanitized console/network summary;
- trace for support.

Screenshots can contain public site content and synthetic values, so retention must be opt-in, short, disclosed, encrypted, and deletable. Never record real visitor sessions.

---

# 7. P0 — External email and destination verification

## 7.1 Email delivery verification

For approved synthetic tests:

1. issue a unique correlation alias/token;
2. perform the synthetic submission;
3. observe provider and WordPress mail outcome;
4. wait for the Formhawk-controlled verification inbox;
5. record received/not received and latency;
6. discard unnecessary content.

Store by default:

- correlation ID;
- monitor/run ID;
- accepted/test/received timestamps;
- delivery status and latency;
- minimal transport headers only if essential and disclosed.

Do not store full message bodies by default.

## 7.2 Destination connectors

After email verification is reliable, add opt-in proof for:

- generic signed webhook receipt;
- selected CRM test-record lookup;
- Google Sheets test-row lookup;
- automation platforms when they expose an appropriate public API.

Connector requirements:

- least-privilege OAuth/API scopes;
- encrypted credentials;
- connection test;
- correlation-token-only search;
- explicit cleanup policy;
- rate limits and retry budgets;
- audit log;
- no access to unrelated real lead contents.

## 7.3 Full-path result

Present one evidence chain:

```text
Page reachable → Form present → Browser submitted → Provider confirmed
→ WordPress/provider accepted mail → External mailbox received
→ Optional destination received
```

Each stage is Passed, Failed, Unavailable, Unsupported, or Inconclusive.

---

# 8. P0/P1 — Alerts and incident operations

## 8.1 Alert sources

Support:

- Free health changed to Critical;
- provider/mail failures;
- confirmed conversion/volume/validation/abandonment anomalies;
- form/placement disappeared;
- integration/tracker stopped reporting;
- synthetic failure;
- delivery/destination failure;
- site heartbeat stale;
- cloud sync/outbox unhealthy;
- incident recovery.

## 8.2 Rule builder

Rules include:

- organization/site/form/placement scope;
- detector/metric;
- threshold and evaluation window;
- minimum sample/confidence;
- severity;
- confirmation policy;
- destinations;
- quiet hours and timezone;
- cooldown;
- recovery notification;
- maintenance-window behavior.

Provide safe templates before exposing an expert builder.

## 8.3 Out-of-band destinations

Priority:

1. Formhawk Cloud email;
2. Slack;
3. Microsoft Teams;
4. Telegram;
5. Discord;
6. generic signed webhook;
7. PagerDuty/Opsgenie-style incident systems;
8. SMS/voice only after deliverability, abuse, and cost controls are proven.

Every destination needs connection test, disable switch, secret rotation, retries, delivery history, and failure visibility.

## 8.4 Deduplication and lifecycle

Incident states:

- Detected;
- Confirming;
- Open;
- Acknowledged;
- Ongoing;
- Recovered;
- Closed;
- Muted/Maintenance.

One underlying condition creates one incident with updates, not repeated alerts.

## 8.5 Escalation and routing

Later support:

- severity-based routing;
- business-hours schedules;
- escalation if unacknowledged;
- client/team ownership;
- fallback destination when primary delivery fails.

Formhawk must monitor its own notification delivery and never assume an alert was received because an API request was attempted.

## 8.6 Maintenance windows

During maintenance:

- monitoring continues;
- alerts can be suppressed or rerouted;
- evidence is tagged;
- data is not discarded;
- recovery is re-evaluated after the window.

---

# 9. P1 — Agency command center

## 9.1 Fleet view

Show across all connected sites:

- current health/incidents;
- monitor coverage;
- last provider-confirmed success;
- last successful synthetic/delivery check;
- heartbeat/sync state;
- provider/version compatibility;
- forms with no monitor;
- sites needing agent/update attention.

Support filters by client, site, environment, tag, provider, severity, owner, and region.

## 9.2 Client/site/environment model

Hierarchy:

- Organization;
- Client;
- Site;
- Environment;
- Form;
- Placement;
- Monitor.

Production and staging must be visibly different. Staging is excluded from client-facing reports by default.

## 9.3 Bulk operations and templates

Agency-scale workflows:

- monitor templates;
- alert-policy templates;
- bulk assign owner/tags;
- clone configuration between environments;
- bulk pause for maintenance;
- rollout status and partial-failure reporting;
- drift detection against organization policy.

## 9.4 Roles and client portal

Roles:

- Owner;
- Admin;
- Operator/Developer;
- Analyst;
- Read-only;
- Client viewer.

Use least privilege and tenant-scoped authorization. Client viewers see only assigned clients/sites and approved reports/incidents.

## 9.5 Heartbeat

Low-frequency heartbeat may contain only disclosed operational facts:

- site ID and environment;
- Free/Pro/API versions;
- provider capability summary;
- health/monitor summary;
- last local event/sync/cron time;
- outbox state.

Do not send an arbitrary plugin inventory. If change intelligence needs component metadata, make that a separate explicit opt-in module.

## 9.6 Agency onboarding

Add:

- secure bulk site enrollment;
- expiring enrollment links/tokens;
- ownership verification;
- duplicate/cloned site detection;
- guided “protect critical forms first” flow;
- coverage score based on monitored important forms, never vanity site count.

---

# 10. P1 — Advanced intelligence

## 10.1 Seasonal baselines

Use explainable models for:

- weekday/hour patterns;
- rolling 7/28/90-day baselines;
- seasonality when enough history exists;
- traffic disappearance;
- change-point detection;
- cross-placement divergence;
- provider failure-rate shifts;
- completion-latency degradation.

Display current value, baseline, expected range, magnitude, sample, confidence, and first detection.

## 10.2 Evidence-based root-cause view

Combine facts such as:

- form schema changed;
- WordPress/provider/theme version changed when explicit change tracking is enabled;
- synthetic form not found;
- provider failure began;
- mail acceptance succeeded but external delivery failed;
- only one placement/region is affected;
- traffic and conversion changed together.

Use “possibly related” until evidence proves causality.

## 10.3 Change intelligence

Optional annotations:

- manual deploy/form/campaign note;
- WordPress core update;
- selected plugin/theme update;
- provider version change;
- form schema change;
- monitor configuration change.

Only transmit component names/versions under an explicit disclosed opt-in. Never send the full inventory merely because one change is useful.

## 10.4 Privacy-safe segmentation

Optional aggregate dimensions:

- device class;
- referrer category;
- allowlisted UTM source/medium/campaign;
- country/region only if a privacy-approved aggregate implementation exists;
- explicit experiment/variant label.

Requirements:

- no fingerprint/persistent visitor ID;
- strict dictionaries/length/cardinality caps;
- minimum cohort sizes;
- retention controls;
- “Segmentation off” mode;
- no arbitrary query-parameter collection.

## 10.5 Funnel and placement comparisons

Compare:

- steps and conditional paths when provider support is reliable;
- placements;
- periods;
- bounded segments;
- explicit experiment variants.

No session replay or reconstructed individual journey.

## 10.6 Opportunity impact estimate

Estimate missed confirmed submissions from a documented baseline with uncertainty bounds.

Optional business value uses only a customer-configured average lead value. Clearly label:

- estimate, not booked revenue;
- baseline period;
- confidence interval;
- assumptions.

## 10.7 AI incident copilot — later and opt-in

AI may summarize already computed facts and suggest investigation steps.

It must:

- cite internal evidence cards;
- never calculate source-of-truth metrics;
- never invent causality;
- never receive real visitor form values;
- disclose the external provider/data boundary;
- support disabling/deleting generated summaries.

## 10.8 Anonymous benchmarks — research only

Do not implement until scale and privacy review justify it.

If pursued:

- explicit opt-in;
- coarse provider/form-type cohorts;
- minimum cohort thresholds;
- no domains, paths, titles, field labels, or visitor data;
- protection against differencing/re-identification;
- deletion/withdrawal;
- published methodology.

---

# 11. P1 — Reports and client communication

## 11.1 Weekly operations report

Include:

- monitored coverage;
- confirmed conversions and trend;
- open/recovered incidents;
- synthetic uptime;
- delivery/destination verification rate;
- highest-confidence issue/opportunity;
- forms lacking external protection.

## 11.2 Monthly client report

Add:

- 30/90-day trend;
- provider and placement summary;
- incident resolution timeline;
- change annotations;
- bounded segmentation where enabled;
- estimated opportunity impact with assumptions;
- maintenance exclusions.

## 11.3 Delivery and white label

Support:

- cloud link;
- accessible HTML email;
- downloadable PDF;
- scheduled delivery;
- agency logo/name/colors/footer;
- approved client-specific sections;
- delivery history/failure status.

Reports must state evidence level and avoid claiming inbox delivery or revenue without proof.

---

# 12. P1 — Webhooks, API, and work integrations

## 12.1 Outgoing webhooks

Events:

- incident detected/opened/acknowledged/recovered/closed;
- form health changed;
- synthetic/delivery/destination failed or recovered;
- site offline/reconnected;
- report ready;
- monitor configuration changed.

Requirements:

- signed versioned payload;
- secret rotation;
- retries with backoff;
- idempotency/event ID;
- delivery log;
- endpoint disable after sustained failure with notification;
- manual resend;
- test event.

## 12.2 Cloud API

Expose tenant-authorized, paginated resources for:

- sites/forms/placements;
- health and aggregates;
- monitors/runs;
- incidents;
- reports;
- alert policies and destinations;
- audit history where allowed.

Use scoped tokens/OAuth, quotas, audit logs, versioning, and revocation. No raw visitor data exists to expose.

## 12.3 Work integrations

Later:

- Jira/Linear/GitHub issue creation;
- deployment annotations from GitHub/GitLab/Bitbucket/webhooks;
- Slack/Teams incident actions;
- status-page component updates;
- Zapier/Make-style generic automation through signed webhooks.

External mutations require explicit configuration, previews/tests, least-privilege scopes, and audit history.

---

# 13. P1/P2 — Cloud reliability and observability

Define SLOs for:

- API availability;
- scheduler delay;
- monitor execution latency;
- result processing;
- alert dispatch;
- verification-inbox processing;
- dashboard freshness.

Build:

- per-service health and saturation metrics;
- distributed trace/request correlation without visitor data;
- queue lag/dead-letter monitoring;
- regional worker health;
- notification-provider health;
- synthetic tests of Formhawk's own monitoring path;
- public status page;
- incident runbooks and postmortem process;
- capacity/load/chaos testing;
- customer-visible “monitoring delayed” state.

Customer incidents must be suppressed or marked Inconclusive when Formhawk infrastructure cannot provide reliable evidence.

---

# 14. P2 — Enterprise and compliance controls

After product-market fit:

- enforced MFA;
- SAML/OIDC SSO;
- SCIM;
- granular custom roles;
- immutable audit export;
- IP/network access policies for cloud accounts where appropriate;
- organization retention policies;
- regional data residency;
- DPA/subprocessor transparency;
- customer-managed destination credentials lifecycle;
- SLA and enterprise support;
- legal hold only if the product/data model later requires it.

Do not overbuild enterprise controls before synthetic monitoring, alerts, and agency workflows are reliable.

---

# 15. WordPress admin UX

Pro extends Free with registered areas:

- Monitoring;
- Incidents/Alerts;
- Reports;
- Cloud/Fleet link;
- Account/Connection.

UX requirements:

- local Free screens remain uncluttered and useful;
- cloud status is clear but not noisy;
- setup shows exact external actions/data;
- destructive disconnect/delete actions are explicit;
- expired license retains settings and explains what stopped;
- no unrelated global wp-admin nags;
- all controls accessible and translatable;
- external links identify that they open Formhawk Cloud.

---

# 16. Cloud data model

Core entities:

- Organization;
- Membership/Role;
- Client;
- Site;
- SiteCredential;
- Environment;
- Form;
- Placement;
- Monitor;
- MonitorConfigurationVersion;
- MonitorRun;
- RunEvidence;
- DeliveryCheck;
- DestinationConnector/Check;
- MetricAggregate;
- Baseline/Anomaly;
- AlertPolicy;
- Incident;
- IncidentEvent;
- NotificationDestination/Delivery;
- MaintenanceWindow;
- Annotation;
- Report;
- WebhookEndpoint/Delivery;
- Entitlement;
- AuditEvent.

Every tenant-owned entity must have explicit ownership, lifecycle, retention, and deletion behavior. High-volume run/evidence tables require partitioning/retention plans before scale.

---

# 17. Packaging and entitlements

Avoid scattering plan names through code. Use stable entitlements, for example:

- `monitor.presence`;
- `monitor.submission`;
- `monitor.delivery`;
- `monitor.destination`;
- `monitor.interval.5m`;
- `alerts.destination.slack`;
- `agency.fleet`;
- `reports.white_label`;
- `api.access`;
- `security.sso`.

Possible packaging to validate with real customers:

## Personal

- one site;
- limited critical-form monitors;
- cloud email alerts;
- short run history.

## Business

- several sites;
- faster submission/delivery checks;
- collaboration destinations;
- longer history and advanced intelligence.

## Agency

- many sites/clients;
- fleet dashboard;
- templates/bulk operations;
- team/client roles;
- scheduled white-label reports.

## Enterprise

- custom scale/SLO;
- SSO/audit/data region;
- API limits/support/retention controls.

Pricing and limits must be tested through customer discovery and infrastructure cost data, not hard-coded assumptions in this roadmap.

---

# 18. Suggested Pro release sequence

## Pro 0.1 — Foundation

- separate add-on;
- Free dependency/version checks;
- license/account lifecycle;
- site identity/credential rotation;
- signed API client;
- durable outbox;
- cloud opt-in/data disclosure;
- disconnect/export/delete foundation;
- tenant/auth security floor.

Exit gate: cloud/license failure cannot affect Free or public forms.

## Pro 0.2 — Out-of-band Alerts Beta

- cloud email plus one collaboration destination;
- safe rule templates from Free health signals;
- incident deduplication/recovery;
- destination tests/history;
- quiet hours and maintenance windows;
- heartbeat/sync health.

Exit gate: one incident produces one reliable alert and one recovery notification.

## Pro 0.3 — Synthetic Presence & Submission MVP

- one region;
- setup recorder/dry run;
- presence and submission levels;
- provider recipes for CF7 plus one additional high-reach provider;
- signed synthetic exclusion;
- failure taxonomy and Inconclusive;
- run history and bounded evidence.

Exit gate: controlled failures are correctly distinguished from worker/infrastructure failures.

## Pro 0.4 — External Delivery Verification

- verification inbox;
- correlation and latency;
- delivery incidents;
- minimal data/retention controls;
- full-path evidence chain through external mailbox.

## Pro 0.5 — Destination Verification & Integrations

- generic webhook receipt;
- one carefully selected downstream connector;
- least-privilege credentials;
- correlation-only lookup and cleanup controls;
- signed outgoing webhooks.

## Pro 0.6 — Agency Command Center

- organizations/clients/environments;
- fleet view;
- bulk enrollment/templates;
- roles/client viewer;
- site coverage and heartbeat;
- multi-site incidents.

## Pro 0.7 — Intelligence

- seasonal baselines;
- evidence/root-cause view;
- change intelligence;
- aggregate segmentation;
- opportunity estimates;
- multi-step/placement comparisons.

## Pro 0.8 — Reports and API

- weekly/monthly reports;
- PDF/cloud/email delivery;
- white label;
- public scoped API;
- work integrations and maintenance automation.

## Pro 0.9 — Scale, Security, and Multi-region Beta

- load/chaos/restore tests;
- tenant isolation audit;
- notification and worker failure drills;
- multi-region beta;
- deletion/export and billing edge cases;
- SLO/status page/runbooks;
- external security review.

## Pro 1.0 — Full-path Form Operations

Launch only when:

- stable Free/Core contracts exist;
- external checks are measurably reliable;
- delivery verification is precise;
- alerts are deduplicated and independently deliverable;
- incidents distinguish customer vs Formhawk failures;
- agency workflows work at realistic fleet size;
- privacy/data/deletion contracts are published;
- billing/entitlements tolerate outages;
- tenant isolation and critical E2E flows are independently verified.

---

# 19. Definition of Done for Pro

A Pro/Cloud feature is complete only when:

1. Free works unchanged without Pro;
2. dependency/API versions are negotiated;
3. entitlement outage/expiry is safe;
4. external data and permissions are disclosed before opt-in;
5. form values/visitor identifiers are excluded by design and tests;
6. tenant ownership/authorization is enforced and tested;
7. requests/jobs/retries are idempotent;
8. secrets are encrypted, rotatable, and redacted;
9. alert deduplication/recovery and delivery failure exist;
10. customer failure, Formhawk failure, and Inconclusive are distinct;
11. retention/export/deletion behavior is implemented;
12. rate/cost/cardinality limits exist;
13. accessibility/i18n are complete;
14. audit/support evidence is sufficient without sensitive payloads;
15. load/failure/rollback behavior is tested;
16. monitoring of Formhawk's own service exists;
17. documentation, subprocessors, and known limitations are current;
18. tests actually executed and not executed are reported honestly.

---

# 20. Product and business success gates

Before optimizing expansion, measure:

- setup completion and time to first successful external test;
- percentage of monitors producing reliable (not Inconclusive) outcomes;
- synthetic false-positive/false-negative rate in controlled tests;
- alert delivery and acknowledgement latency;
- incident deduplication quality;
- verified prevented/shortened form outages reported by customers;
- monitor coverage of customer-designated critical forms;
- agency time saved per site/month;
- churn reasons by reliability, complexity, and value;
- cloud cost per monitor run and per protected site;
- support volume per provider recipe/version;
- data deletion/export completion time;
- SLO attainment.

The key activation metric is not “license connected.” It is:

> At least one critical form completed a successful external full-path test and has a working independent alert destination.

---

# 21. Explicitly defer

Do not prioritize before the flagship loop is reliable:

- generic AI chat;
- visitor/session replay;
- lead/CRM entry storage;
- automatic rewriting of customer forms;
- a Formhawk form builder;
- opaque universal “AI health” scores;
- sub-minute monitoring;
- dozens of shallow connectors;
- invasive device/fingerprint analytics;
- anonymous benchmarks without scale/privacy proof;
- enterprise checklists without customer demand.

Formhawk Pro should become indispensable because it provides credible external proof and calm incident operations, not because it contains the largest menu.
