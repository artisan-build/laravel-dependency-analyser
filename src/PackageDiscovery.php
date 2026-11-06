<?php

declare(strict_types=1);

namespace ArtisanBuild\LaravelDependencyAnalyser;

use RuntimeException;

final class PackageDiscovery
{
    /**
     * Read Composer metadata without executing the app or trusting stale bootstrap caches.
     *
     * @return array<string, list<string>> Package names mapped to their provider/alias classes
     */
    public static function packages(string $composerJsonPath, string $vendorDir): array
    {
        $root = self::readJson($composerJsonPath);
        $installed = self::readJson($vendorDir.'/composer/installed.json');
        $packages = $installed['packages'] ?? $installed;
        $ignored = $root['extra']['laravel']['dont-discover'] ?? [];

        // Laravel also lets installed packages contribute to dont-discover.
        foreach ($packages as $package) {
            $ignored = array_merge($ignored, $package['extra']['laravel']['dont-discover'] ?? []);
        }

        if (in_array('*', $ignored, true)) {
            return [];
        }

        $discovered = [];
        foreach ($packages as $package) {
            $name = $package['name'];
            if (in_array($name, $ignored, true)) {
                continue;
            }

            $laravel = $package['extra']['laravel'] ?? [];
            $symbols = array_merge($laravel['providers'] ?? [], array_values($laravel['aliases'] ?? []));
            if ($symbols !== []) {
                $discovered[$name] = array_values(array_unique($symbols));
            }
        }

        ksort($discovered);

        return $discovered;
    }

    /** @return array<mixed> */
    private static function readJson(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Cannot read $path. Run composer install before analysing.");
        }

        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new RuntimeException("Expected a JSON object or array in $path.");
        }

        return $data;
    }
}
