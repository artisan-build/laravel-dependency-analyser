# Laravel Dependency Analyser

Laravel-aware Composer dependency analysis, powered by
[ShipMonk's composer-dependency-analyser](https://github.com/shipmonk-rnd/composer-dependency-analyser).
This is a small integration, not a fork of the analysis engine.

**Development preview.** Ready for application trials, not automatic dependency removal.
Requires PHP 8.2+ and Composer 2.2+. No Laravel runtime dependency or service provider is installed.

## Try the preview

This is an **untagged `dev-main` preview**, not a stable release or a Packagist publication.
Run these commands from a clean development branch in your Laravel application:

```sh
composer config repositories.laravel-dependency-analyser vcs https://github.com/artisan-build/laravel-dependency-analyser
composer require --dev artisan-build/laravel-dependency-analyser:dev-main
vendor/bin/laravel-dependency-analyser --ignore-shadow-deps
```

The first two commands change your application's `composer.json`, `composer.lock`, and installed
development dependencies. They do not require changing global `minimum-stability`.
Review those changes separately from your normal app work. The analyser itself never removes
dependencies or changes application files.

The first run focuses on unused dependencies. For the full set of checks, including undeclared
transitive dependencies, run `vendor/bin/laravel-dependency-analyser` without the flag.
Exit code **2 is expected** when only auto-registered packages need review; it is not a crash.
Findings are candidates for investigation, never an instruction to delete a package.

To try a later preview commit, run `composer update artisan-build/laravel-dependency-analyser`.
For useful feedback, include the installed preview commit from
`composer show artisan-build/laravel-dependency-analyser`, the package reported, its category,
and a minimal example of how your application uses it. Do not send secrets or your `.env` file.

For local package development instead, replace the VCS repository with a path repository pointing
at your checkout and require `artisan-build/laravel-dependency-analyser:@dev`.

## Laravel defaults

- Scan Composer's `autoload` and `autoload-dev` paths as usual.
- Also scan `app/`, `bootstrap/`, `config/`, `database/`, `public/`, `routes/`, and the extensionless `artisan` entry point, when present.
- Scan `tests/` and `database/factories/` as development code (factories only run in tests and seeders, which is why Laravel ships Faker in `require-dev`).
- Scan Blade views in `resources/views/`. The PHP inside echoes, `@php` blocks and directive arguments is extracted with its original line numbers, so findings point at the real view and line. Blade comments, `@{{ }}` and `@verbatim` are skipped.
- Resolve global class aliases (`\DB::`, `\Log::`, `Excel::`) to the class behind them: Laravel's default facade aliases, the `aliases` array in `config/app.php`, and discovered package aliases.
- Treat classes declared in application files that Composer can't autoload, such as helper classes inside Pest test files, as local code instead of unknown classes.
- Exclude generated `bootstrap/cache/` and `storage/` files. Stale manifests or compiled views must not keep an abandoned dependency looking used.
- Detect explicit provider and alias class references in PHP, including `bootstrap/providers.php` and `config/app.php`.
- Read installed packages' `extra.laravel.providers` and `extra.laravel.aliases` metadata, respecting both the application's and installed packages' `dont-discover` entries, including `*`.
- Honour custom Composer `vendor-dir` locations.

ShipMonk still checks unused dependencies, undeclared transitive (shadow) dependencies,
development dependencies used in production, and production dependencies used only in development.
Unused development dependencies are not checked by default, because CLI tools can be used only in CI.

## Interpreting the report

**Unused:** no static usage was found and the package isn't automatically registered by Laravel.
This is a removal candidate, not proof that removing it is safe.

**Auto-registered, needs review:** no static usage was found, but Laravel discovers a provider
or alias from the package. Registration alone does not prove that you still use its features.
These packages are reported separately on stderr, never silently marked as used or recommended for removal.
Packages with explicit PHP symbol references are handled by the normal analysis instead.
Each entry lists any setup evidence it finds: the package's config files published into `config/`, and
`.env.example` keys that carry the package's own prefix (e.g. `HONE_URL`, not shared keys like `APP_NAME`).
An entry with no evidence is the strongest removal candidate in this list.

**Optional integrations, needs review:** no static usage was found, but a package you do use lists it in
Composer `suggest` (e.g. laravel/framework suggests `predis/predis` for the predis Redis client). The reason
from `suggest` is printed. The driver may still be selected in a deployed `.env` the analyser can't see.

**Used without class references:** packages Laravel loads only through configuration or that only scripts run.
These are not findings; the evidence is printed so you can check it:

| Package | Evidence looked for |
| --- | --- |
| `doctrine/dbal` | A migration calls `->change()` or `renameColumn()` on Laravel < 11 |
| `league/flysystem-aws-s3-v3`, `-ftp`, `-sftp-v3` | A disk with that driver is the default disk, or its name is used via `disk('name')` / `'disk' => 'name'` |
| `predis/predis` | `database.redis.client` resolves to `predis` |
| `pusher/pusher-php-server` | The default broadcast connection is `pusher` or `reverb` |
| `symfony/mailgun-mailer`, `symfony/postmark-mailer`, `resend/resend-php` | The default mailer uses that transport |
| `geoip2/geoip2` | torann/geoip's `service` is a MaxMind service |
| `brianium/paratest` | `artisan test --parallel` or `paratest` in Composer scripts, CI workflows, a Makefile or package.json |
| Any package with a `bin` | `vendor/bin/<name>` in those same places |

A config value counts when it is a literal, when `.env.example` sets its env variable, or (with no
`.env.example` entry) when it is the `env()` default. A production dependency that only scripts use is
reported as a production dependency used only in dev paths.

**Shadow dependencies:** packages and extensions that `laravel/framework` itself requires directly (Carbon,
Symfony components, Monolog, PSR interfaces, `ext-mbstring` and so on) are listed in one summary line on
stderr instead of as findings, because Laravel apps use them as part of the framework's API. All other
shadow dependencies are still reported in full.

Exit codes (code 2 also covers optional integrations awaiting review):

| Code | Meaning |
| --- | --- |
| 0 | No findings or discovery reviews remain |
| 1 | Dependency/symbol/configuration-ignore findings (or upstream's `--dump-usages` mode) |
| 2 | Only auto-registered packages or optional integrations requiring review remain |
| 3 | Analysis could not run, e.g. missing installation or invalid configuration |

If both dependency findings and discovery reviews exist, exit code 1 takes precedence.
With JUnit output, review details remain on stderr and code 2 still signals an incomplete review;
the XML alone does not include discovery reviews. Always check the process exit code.

## Configuration and CI

All ShipMonk CLI options are available, including `--verbose`, `--dump-usages`,
`--format=junit`, `--composer-json`, and `--config`. See `--help` for the full list.
For an initial unused-dependency review without shadow-dependency findings, run
`vendor/bin/laravel-dependency-analyser --ignore-shadow-deps`.
Even a stock Laravel app directly references some transitive dependencies in its config
and entry points; full analysis deliberately keeps those findings visible.

Configuration is loaded from `composer-dependency-analyser.php` in the **working directory**,
or from the explicit `--config` path. This is unchanged from upstream, including when
`--composer-json` points to a different project. Laravel paths are relative to the target manifest.

A config file returns a ShipMonk `Configuration` object. Laravel defaults are added to it automatically:

```php
<?php

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())
    // An example: a package deliberately used via dynamic integration.
    ->ignoreErrorsOnPackage('your-vendor/your-package', [ErrorType::UNUSED_DEPENDENCY]);
```

Use narrow, explained ignores only after reviewing a finding. They also acknowledge
discovery reviews for the named package; unmatched ignores remain reported by upstream.
For known dynamically referenced classes, `addForceUsedSymbols([...])` is also available.

For CI, install the app normally, then run:

```sh
vendor/bin/laravel-dependency-analyser --format=junit > dependency-analysis.xml
```

## Boundaries of this first version

- It does **not** boot Laravel, execute routes/config/provider files, resolve container bindings,
  or connect to the application's services. Like ShipMonk, it does load Composer's autoloader:
  **Composer `autoload.files` entries and class autoloaders can execute PHP.** Run only on trusted code.
- Custom Blade directives registered by the app, Blade components, string-based class names,
  container keys such as `app('log')`, and dynamically registered integrations are not resolved.
  Config-selected drivers are recognised only for the packages listed above. Compiled views are never used.
- It does not infer usage from every installed provider: that would hide unused packages.
- Explicit provider registration is treated as a PHP reference, not proof that an application
  feature is exercised at runtime.
- The standard scan paths target Laravel applications. Custom layouts can add paths through
  ShipMonk configuration; disabling Composer path scanning does not disable Laravel's defaults.
- Review candidates and run your application's tests before removing anything. The tool never runs
  `composer remove` and never deletes `vendor/` contents.

The first application trials should focus on false positives from these dynamic integrations.
Please include the package name, the reported category, and a minimal example of how it is used.

## Development

```sh
composer install
composer check
```

`composer check` validates the manifest, checks Laravel Pint formatting, runs PHPStan at level 8,
and runs CLI integration tests using real Composer-installed fixture packages. Fixtures cover
all extra scan paths, discovery/exclusions, cache exclusion, dev/prod and shadow dependencies,
custom vendor paths, configuration, JUnit output, and Composer's generated binary proxy.

The integration currently targets ShipMonk's 1.8.x API (`~1.8.4`), since it consumes the engine
and result APIs as well as its configuration API. Test a newer minor before widening this constraint.
CI is configured for PHP 8.2–8.5. Orb development prerequisites are in `.agents/setup`.

The preview has also been smoke-tested on a fresh Laravel 12 application with the package
installed through Composer: no ordinary unused dependencies, Tinker requiring discovery review,
and four shadow-dependency findings from the stock config/entry point. Blade/container-heavy
application trials are still needed; this is not a claim of complete Laravel compatibility.

## License

MIT. ShipMonk's analyser is separately maintained and MIT-licensed.
