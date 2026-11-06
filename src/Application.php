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
            $discovered = PackageDiscovery::packages($composerJsonPath, $composerJson->composerVendorDir);

            $analyser = new Analyser(
                new Stopwatch,
                $composerJson->composerVendorDir,
                $initializer->initComposerClassLoaders(),
                $configuration,
                $composerJson->dependencies,
            );
            $report = new Report($analyser->run(), $discovered);

            if ($report->needsReview !== []) {
                $stderr->printLine('Auto-registered packages requiring review (no static usage found):');
                foreach ($report->needsReview as $name => $symbols) {
                    $stderr->printLine('  '.$name.': '.implode(', ', $symbols));
                }
                $stderr->printLine('Registration does not prove usage. Check package features before removing.');
            }

            $exit = $initializer->initFormatter($options)->format($report, $options, $configuration);

            return $exit !== 0 ? 1 : ($report->needsReview === [] ? 0 : 2);
        } catch (AbortException) {
            return 0;
        } catch (Throwable $error) {
            $stderr->printLine($error->getMessage());

            return 3;
        }
    }
}
