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
- WordPress mail operation diagnostics. An accepted mail operation does not prove inbox delivery.

Generic HTML submissions are observed browser attempts; provider-confirmed successes are separate evidence. See the [provider support matrix](docs/PROVIDER-SUPPORT.md) for capabilities, tested versions and remaining validation work.

## Privacy

Free analytics use daily aggregates, without submitted field values, analytics cookies, persistent visitor/session identifiers or external Formhawk analytics transmission. Temporary frontend state lives in memory for the current page lifecycle. See [readme.txt](readme.txt) for the full product description and privacy details.

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

The installable archive is `dist/formhawk-0.2.0.zip`. Inspect the archive and run WordPress Plugin Check before a directory release. GitHub source archives are development snapshots; use the packaging command for an installable release artifact.

## Project documentation

- [Engineering standard](AGENTS.md)
- [Contributing](CONTRIBUTING.md)
- [Provider support](docs/PROVIDER-SUPPORT.md)
- [Database migrations](docs/MIGRATIONS.md)
- [Free roadmap](docs/ROADMAP-FREE.md)
- [Pro roadmap](docs/ROADMAP-PRO.md)
- [Changelog](readme.txt#changelog)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE). You may redistribute and modify Formhawk under GNU GPL version 2 or, at your option, any later version.
