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

        if (is_dir($root.'/tests')) {
            $configuration->addPathToScan($root.'/tests', true);
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
