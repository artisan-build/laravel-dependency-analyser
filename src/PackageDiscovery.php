<?php

declare(strict_types=1);

namespace ArtisanBuild\LaravelDependencyAnalyser;

use RuntimeException;

/**
 * Reads Composer metadata (root manifest and installed.json) without executing the application.
 */
final class PackageDiscovery
{
    /** @var array<string, mixed> */
    public readonly array $root;

    /** @var array<string, array<string, mixed>> */
    public readonly array $installed;

    public function __construct(string $composerJsonPath, public readonly string $vendorDir)
    {
        $this->root = self::readJson($composerJsonPath);
        $installed = self::readJson($vendorDir.'/composer/installed.json');
        $packages = [];
        foreach ($installed['packages'] ?? $installed as $package) {
            $packages[$package['name']] = $package;
        }
        $this->installed = $packages;
    }

    /**
     * Read Composer metadata without executing the app or trusting stale bootstrap caches.
     *
     * @return array<string, list<string>> Package names mapped to their provider/alias classes
     */
    public static function packages(string $composerJsonPath, string $vendorDir): array
    {
        return (new self($composerJsonPath, $vendorDir))->discovered();
    }

    /** @return array<string, list<string>> Package names mapped to their provider/alias classes */
    public function discovered(): array
    {
        $discovered = [];
        foreach ($this->discoverable() as $name => $laravel) {
            $symbols = array_merge($laravel['providers'] ?? [], array_values($laravel['aliases'] ?? []));
            if ($symbols !== []) {
                $discovered[$name] = array_values(array_unique($symbols));
            }
        }

        ksort($discovered);

        return $discovered;
    }

    /** @return array<string, string> Alias names mapped to the classes Laravel's AliasLoader points them at */
    public function discoveredAliases(): array
    {
        $aliases = [];
        foreach ($this->discoverable() as $laravel) {
            foreach ($laravel['aliases'] ?? [] as $alias => $class) {
                $aliases[(string) $alias] = ltrim((string) $class, '\\');
            }
        }

        return $aliases;
    }

    /** @return array<string, string> Installed packages that suggest the given package, mapped to their reason */
    public function suggesters(string $package): array
    {
        $suggesters = [];
        foreach ($this->installed as $name => $metadata) {
            if (isset($metadata['suggest'][$package])) {
                $suggesters[$name] = (string) $metadata['suggest'][$package];
            }
        }

        return $suggesters;
    }

    /** @return list<string> */
    public function requirementsOf(string $package): array
    {
        return array_map('strval', array_keys($this->installed[$package]['require'] ?? []));
    }

    public function majorVersion(string $package): ?int
    {
        $version = ltrim((string) ($this->installed[$package]['version_normalized'] ?? ''), 'v');

        return preg_match('/^(\d+)\./', $version, $match) === 1 ? (int) $match[1] : null;
    }

    /** @return list<string> */
    public function binaries(string $package): array
    {
        return array_values(array_map('basename', $this->installed[$package]['bin'] ?? []));
    }

    public function installPath(string $package): ?string
    {
        $path = $this->installed[$package]['install-path'] ?? null;

        return is_string($path) ? $this->vendorDir.'/composer/'.$path : null;
    }

    /** @return array<string, array<string, mixed>> Laravel extra metadata of packages that discovery will load */
    private function discoverable(): array
    {
        $ignored = $this->root['extra']['laravel']['dont-discover'] ?? [];

        // Laravel also lets installed packages contribute to dont-discover.
        foreach ($this->installed as $package) {
            $ignored = array_merge($ignored, $package['extra']['laravel']['dont-discover'] ?? []);
        }

        if (in_array('*', $ignored, true)) {
            return [];
        }

        $discoverable = [];
        foreach ($this->installed as $name => $package) {
            if (! in_array($name, $ignored, true) && isset($package['extra']['laravel'])) {
                $discoverable[$name] = $package['extra']['laravel'];
            }
        }

        return $discoverable;
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
