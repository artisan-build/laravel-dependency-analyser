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
- Scan `tests/` as development code.
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

Exit codes:

| Code | Meaning |
| --- | --- |
| 0 | No findings or discovery reviews remain |
| 1 | Dependency/symbol/configuration-ignore findings (or upstream's `--dump-usages` mode) |
| 2 | Only auto-registered packages requiring review remain |
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
- Blade directives, Blade components, global facade aliases, string-based class names,
  container keys such as `app('log')`, config-selected drivers, and dynamically registered
  integrations are not resolved. Blade templates and compiled views are not used as usage evidence.
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
