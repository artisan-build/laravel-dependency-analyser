<?php

declare(strict_types=1);

namespace ArtisanBuild\LaravelDependencyAnalyser;

use Composer\InstalledVersions;
use ShipMonk\ComposerDependencyAnalyser\Analyser;
use ShipMonk\ComposerDependencyAnalyser\Exception\AbortException;
use ShipMonk\ComposerDependencyAnalyser\Initializer;
use ShipMonk\ComposerDependencyAnalyser\Path;
use ShipMonk\ComposerDependencyAnalyser\Printer;
use ShipMonk\ComposerDependencyAnalyser\Stopwatch;
use Throwable;

final class Application
{
    /** @param list<string> $argv */
    public static function run(array $argv): int
    {
        $cwd = (string) getcwd();
        $noColor = getenv('NO_COLOR') !== false || ! stream_isatty(STDOUT);
        $stdout = new Printer(STDOUT, $noColor);
        $stderr = new Printer(STDERR, $noColor);
        $initializer = new Initializer($cwd, $stdout, $stderr);

        try {
            // Keep upstream's options, but identify this integration in help/version.
            if (in_array('--help', $argv, true)) {
                $stdout->printLine('Laravel Dependency Analyser');
                $stdout->printLine('Run vendor/bin/laravel-dependency-analyser in your Laravel project.');
                $stdout->printLine('Exit codes: 0 clean, 1 findings, 2 discovery review required, 3 analysis error.');
                $stdout->printLine('Configuration: composer-dependency-analyser.php (ShipMonk Configuration).');
            }
            if (in_array('--version', $argv, true)) {
                $stdout->printLine('Laravel Dependency Analyser '.InstalledVersions::getPrettyVersion('artisan-build/laravel-dependency-analyser'));
            }

            $options = $initializer->initCliOptions($cwd, $argv);
            $composerJson = $initializer->initComposerJson($options);
            $composerJsonPath = Path::resolve($cwd, $options->composerJson ?? 'composer.json');
            $root = dirname($composerJsonPath);
            $initializer->initComposerAutoloader($composerJson);
            $configuration = LaravelConfiguration::apply($initializer->initConfiguration($options, $composerJson), $root);
            $composer = new PackageDiscovery($composerJsonPath, $composerJson->composerVendorDir);

            $loaders = $initializer->initComposerClassLoaders();
            $aliases = FacadeAliases::resolve($root, $composer->discoveredAliases());
            // Appended last so real vendor directories still win when ShipMonk maps a file to its package.
            $loaders[$composerJson->composerVendorDir.'/composer/laravel-aliases'] = FacadeAliases::classLoader($aliases, $loaders);

            $views = sys_get_temp_dir().'/laravel-dependency-analyser-views-'.bin2hex(random_bytes(6));
            mkdir($views, 0700);
            // ShipMonk reports real paths, so the stand-in map must use them too (e.g. macOS /private/var).
            $views = (string) realpath($views);
            $pathMap = BladeViews::extract($root, $views);
            if ($pathMap !== []) {
                $configuration->addPathToScan($views, false);
            }

            try {
                $analyser = new Analyser(
                    new Stopwatch,
                    $composerJson->composerVendorDir,
                    $loaders,
                    $configuration,
                    $composerJson->dependencies,
                );
                $report = new Report($analyser->run(), $composer, new LaravelIntegrations($root, $composer), $pathMap);
            } finally {
                self::remove($views, array_keys($pathMap));
            }

            self::printLaravelFindings($stderr, $report, new LaravelIntegrations($root, $composer));

            $exit = $initializer->initFormatter($options)->format($report, $options, $configuration);

            return $exit !== 0 ? 1 : ($report->requiresReview() ? 2 : 0);
        } catch (AbortException) {
            return 0;
        } catch (Throwable $error) {
            $stderr->printLine($error->getMessage());

            return 3;
        }
    }

    private static function printLaravelFindings(Printer $stderr, Report $report, LaravelIntegrations $integrations): void
    {
        if ($report->needsReview !== []) {
            $stderr->printLine('Auto-registered packages requiring review (no static usage found):');
            foreach ($report->needsReview as $name => $symbols) {
                $hint = $integrations->setupEvidence($name);
                $stderr->printLine('  '.$name.': '.implode(', ', $symbols).($hint !== null ? " ({$hint})" : ''));
            }
            $stderr->printLine('Registration does not prove usage. Check package features before removing.');
            $stderr->printLine('');
        }

        if ($report->optionalIntegrations !== []) {
            $stderr->printLine('Optional integrations requiring review (suggested by a package you use, no usage found):');
            foreach ($report->optionalIntegrations as $name => $reasons) {
                $stderr->printLine('  '.$name.' <- '.implode('; ', $reasons));
            }
            $stderr->printLine('The driver may be selected in your deployed .env. Check before removing.');
            $stderr->printLine('');
        }

        $explained = $report->usedThroughLaravel + array_map(static fn (string $evidence): string => "tooling: {$evidence}", $report->usedByTooling);
        if ($explained !== []) {
            $stderr->printLine('Used without class references:');
            foreach ($explained as $name => $evidence) {
                $stderr->printLine('  '.$name.': '.$evidence);
            }
            $stderr->printLine('');
        }

        if ($report->frameworkProvided !== []) {
            $stderr->printLine(count($report->frameworkProvided).' shadow dependencies not listed because laravel/framework requires them directly:');
            $stderr->printLine('  '.implode(', ', $report->frameworkProvided));
            $stderr->printLine('');
        }
    }

    /** @param list<string> $files */
    private static function remove(string $directory, array $files): void
    {
        foreach ($files as $file) {
            @unlink($file);
        }

        $paths = is_dir($directory) ? new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) : [];
        foreach ($paths as $path) {
            @rmdir($path->getPathname());
        }
        @rmdir($directory);
    }
}
