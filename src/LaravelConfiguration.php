<?php

declare(strict_types=1);

namespace ArtisanBuild\LaravelDependencyAnalyser;

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;

final class LaravelConfiguration
{
    public static function apply(Configuration $configuration, string $root): Configuration
    {
        foreach (['app', 'bootstrap', 'config', 'database', 'public', 'routes', 'artisan'] as $path) {
            if (file_exists($root.'/'.$path)) {
                $configuration->addPathToScan($root.'/'.$path, false);
            }
        }

        // Factories only run in tests and seeders, which is why Laravel ships Faker as a dev dependency.
        foreach (['tests', 'database/factories'] as $path) {
            if (is_dir($root.'/'.$path)) {
                $configuration->addPathToScan($root.'/'.$path, true);
            }
        }

        // Generated manifests and compiled views are not evidence of application usage.
        foreach (['bootstrap/cache', 'storage'] as $path) {
            if (is_dir($root.'/'.$path)) {
                $configuration->addPathToExclude($root.'/'.$path);
            }
        }

        return $configuration;
    }
}
