# Formhawk

Privacy-first form conversion analytics and health monitoring for WordPress.

Formhawk stores aggregate analytics in your WordPress database to help identify abandonment, field friction, validation failures and changes in form health.

## Requirements

- WordPress 6.4 or later.
- PHP 7.4 or later.
- For development: Composer 2, Node.js 22 or later, npm and `zip` for packaging.

## Features and semantics

- Form views, starts, submit attempts, abandonment and field interactions.
- Contact Form 7, WPForms and Elementor Pro Forms adapters with provider-confirmed outcomes.
- Standard HTML form discovery, including dynamically inserted forms.
- Local health reporting, placement analytics and configurable retention.
- Autopilot CRO: safe runtime experiments, confirmed-conversion decisions, automatic guardrails, promotion and rollback without editing provider forms.
- Field ROI Business Value Intelligence: privacy-safe outcome attribution, EVPV, qualified/won leads per visitor, robust revenue inference, evidence-aware recommendations and Field Value Map.
- WordPress mail operation diagnostics. An accepted mail operation does not prove inbox delivery.

Generic HTML submissions are observed browser attempts; provider-confirmed successes are separate evidence. See the [provider support matrix](docs/PROVIDER-SUPPORT.md) for capabilities, tested versions and remaining validation work.

Version 0.5.2 protects CRO against replayed browser telemetry with signed v2 contexts, short-lived server-side issuance state, atomic lifecycle admission and separate server-issued assignment counts. Client errors, validation and latency are advisory, not autonomous rejection/rollback authority. Generic HTML remains available in Observe/Approve mode with manual decisions; browser submit reports cannot promote a winner automatically. Schema v7 preserves historical data and keeps older experiments manual-safe. Back up before upgrading, clear caches and reload open pages: old v1 CRO tokens are intentionally no longer accepted.

The 0.5.1 repeated-AJAX fix remains: independent CF7, WPForms and Elementor submissions get separate Field ROI IDs, while retries retain their ID. CRO attempts/latencies may repeat up to 50 times per issued page/form context without repeating views/starts or changing the experiment arm. Previously merged submissions cannot be reconstructed from Formhawk aggregates.

## Privacy

Core analytics use daily aggregates, without submitted field values, analytics cookies, persistent visitor/session identifiers or external Formhawk analytics transmission. CRO replay protection stores only hashed random assignment identifiers, structural attribution, expiry and bounded lifecycle counters (not raw event payloads). Field ROI adds only a cryptographically random per-submission linkage, structural field metadata and time-bounded outcome/value records; no visitor form value or raw CRM payload is stored. See [CRO integrity](docs/AUTOPILOT-CRO.md), [Field ROI architecture](docs/FIELD-ROI.md) and [readme.txt](readme.txt).

## Development

Clone into a development WordPress installation under `wp-content/plugins/formhawk`:

```sh
git clone git@github.com:al5dy/formhawk.git formhawk
cd formhawk
composer install
npm ci
npm run build
```

Activate Formhawk in WordPress. Runtime assets are committed, and runtime PHP uses the plugin's own autoloader; Composer dependencies are development-only.

Edit JavaScript in `resources/js/` and styles in `resources/scss/`, then rebuild `assets/`. The current stylesheet uses CSS-compatible syntax and is processed by esbuild; Sass-specific syntax is not supported by the current build.

## Checks

```sh
composer validate --strict
composer phpcs
composer phpstan
npm run lint:js
npm test
npm run build
git diff --exit-code -- assets/
```

PHPUnit loads a real WordPress installation, including for tests under `tests/Unit`. Use a disposable development site/database with Formhawk active:

```sh
FORMHAWK_WP_ROOT=/absolute/path/to/disposable-wordpress composer test
```

The suite writes test data and temporarily changes options. Do not run it against production. Without `FORMHAWK_WP_ROOT`, the bootstrap resolves WordPress relative to the plugin directory.

GitHub Actions runs PHP coding/compatibility checks, static analysis, JavaScript tests and asset reproducibility checks. Database integration tests, real provider journeys and Plugin Check remain separate validation steps; passing CI does not certify a WordPress.org release.

## Packaging

```sh
npm ci
npm run build
npm run build:release
```

The installable archive is `dist/formhawk-0.5.2.zip`. Inspect the archive and run WordPress Plugin Check before a directory release. GitHub source archives are development snapshots; use the packaging command for an installable release artifact.

## Project documentation

- [Engineering standard](AGENTS.md)
- [Contributing](CONTRIBUTING.md)
- [Provider support](docs/PROVIDER-SUPPORT.md)
- [Database migrations](docs/MIGRATIONS.md)
- [Ingestion limits, evidence semantics and hardening](docs/HARDENING.md)
- [Autopilot CRO architecture, statistics and safety](docs/AUTOPILOT-CRO.md)
- [Field ROI architecture, Outcome API, formulas and privacy](docs/FIELD-ROI.md)
- [Free roadmap](docs/ROADMAP-FREE.md)
- [Pro roadmap](docs/ROADMAP-PRO.md)
- [Changelog](readme.txt#changelog)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE). You may redistribute and modify Formhawk under GNU GPL version 2 or, at your option, any later version.
