<?php

declare(strict_types=1);

namespace ArtisanBuild\LaravelDependencyAnalyser;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Finds evidence that a package with no class references is still used through Laravel's
 * configuration-selected drivers, migrations, or development tooling.
 */
final class LaravelIntegrations
{
    /** @var array<string, string>|null */
    private ?array $envExample = null;

    /** @var array<string, string> */
    private array $sources = [];

    public function __construct(private readonly string $root, private readonly PackageDiscovery $composer) {}

    /** Explain how Laravel itself loads this package at runtime, or return null when nothing proves it. */
    public function runtimeEvidence(string $package): ?string
    {
        return match ($package) {
            'doctrine/dbal' => $this->dbalEvidence(),
            'league/flysystem-aws-s3-v3' => $this->diskEvidence('s3'),
            'league/flysystem-ftp' => $this->diskEvidence('ftp'),
            'league/flysystem-sftp-v3' => $this->diskEvidence('sftp'),
            'predis/predis' => $this->configEvidence('config/database.php', 'client', 'REDIS_CLIENT', ['predis']),
            'pusher/pusher-php-server' => $this->configEvidence('config/broadcasting.php', 'default', 'BROADCAST_CONNECTION|BROADCAST_DRIVER', ['pusher', 'reverb']),
            'symfony/mailgun-mailer' => $this->mailerEvidence('mailgun'),
            'symfony/postmark-mailer' => $this->mailerEvidence('postmark'),
            'resend/resend-php' => $this->mailerEvidence('resend'),
            'geoip2/geoip2' => $this->configEvidence('config/geoip.php', 'service', 'GEOIP_SERVICE', ['maxmind_database', 'maxmind_api']),
            default => null,
        };
    }

    /** Explain where a development tool is invoked from scripts or CI, or return null. */
    public function toolingEvidence(string $package): ?string
    {
        if ($package === 'brianium/paratest') {
            $match = $this->findInTooling('/artisan\s+test\b[^"\n]*--parallel|\bparatest\b/');
            if ($match !== null) {
                return "{$match} runs tests in parallel";
            }
        }

        foreach ($this->composer->binaries($package) as $binary) {
            $match = $this->findInTooling('/vendor\/bin\/'.preg_quote($binary, '/').'\b/');
            if ($match !== null) {
                return "{$match} runs vendor/bin/{$binary}";
            }
        }

        return null;
    }

    /**
     * Describe signs that an auto-registered package was set up: published config files and
     * .env.example keys carrying the package's own prefix (HONE_*, not shared keys like APP_NAME).
     */
    public function setupEvidence(string $package): ?string
    {
        $installPath = $this->composer->installPath($package);
        if ($installPath === null || ! is_dir($installPath.'/config')) {
            return null;
        }

        $published = $keys = [];
        foreach (glob($installPath.'/config/*.php') ?: [] as $file) {
            $relative = 'config/'.basename($file);
            if (is_file($this->root.'/'.$relative)) {
                $published[] = $relative;
            }
            preg_match_all('/env\(\s*[\'"]([A-Z0-9_]+)[\'"]/', (string) file_get_contents($file), $env);
            $keys = array_merge($keys, $env[1]);
        }

        $prefixes = array_count_values(array_map(static fn (string $key): string => strtok($key, '_').'_', $keys));
        arsort($prefixes);
        $prefix = (string) array_key_first($prefixes);
        $configured = array_values(array_filter(
            array_unique($keys),
            fn (string $key): bool => str_starts_with($key, $prefix) && isset($this->envExample()[$key]),
        ));

        $evidence = array_filter([
            $published === [] ? null : implode(', ', $published).' published',
            $configured === [] ? null : '.env.example sets '.implode(', ', $configured),
        ]);

        return $evidence === [] ? null : implode('; ', $evidence);
    }

    /**
     * Classes declared in application files that Composer cannot autoload, such as helper
     * classes inside Pest test files. They are local code, not unknown classes.
     *
     * @return array<string, true>
     */
    public function declaredClasses(): array
    {
        $declared = [];
        foreach (['app', 'bootstrap', 'config', 'database', 'routes', 'tests'] as $path) {
            foreach ($this->phpFiles($path) as $code) {
                if (preg_match_all('/^\s*(?:(?:abstract|final|readonly)\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $code, $classes) === 0) {
                    continue;
                }
                $namespace = preg_match('/^\s*namespace\s+([\w\\\\]+)\s*;/m', $code, $match) === 1 ? $match[1].'\\' : '';
                foreach ($classes[1] as $class) {
                    $declared[$namespace.$class] = true;
                }
            }
        }

        return $declared;
    }

    private function dbalEvidence(): ?string
    {
        $framework = $this->composer->majorVersion('laravel/framework');
        if ($framework === null || $framework >= 11) {
            return null;
        }

        foreach ($this->phpFiles('database/migrations') as $relative => $code) {
            if (preg_match('/->\s*(change|renameColumn)\s*\(/', $code, $match) === 1) {
                return "{$relative} calls {$match[1]}(), which needs doctrine/dbal before Laravel 11";
            }
        }

        return null;
    }

    private function diskEvidence(string $driver): ?string
    {
        $config = $this->source('config/filesystems.php');
        preg_match_all('/[\'"](\w+)[\'"]\s*=>\s*\[\s*[\'"]driver[\'"]\s*=>\s*[\'"]'.$driver.'[\'"]/', $config, $disks);
        if ($disks[1] === []) {
            return null;
        }

        $default = $this->configEvidence('config/filesystems.php', 'default', 'FILESYSTEM_DISK|FILESYSTEM_DRIVER', $disks[1]);
        if ($default !== null) {
            return $default;
        }

        $names = implode('|', array_map(static fn (string $disk): string => preg_quote($disk, '/'), $disks[1]));
        foreach (['app', 'routes', 'config', 'database', 'resources/views'] as $path) {
            foreach ($this->phpFiles($path) as $relative => $code) {
                if ($relative === 'config/filesystems.php') {
                    continue;
                }
                if (preg_match('/(?:disk\s*\(\s*|[\'"]disk[\'"]\s*=>\s*)[\'"]('.$names.')[\'"]/', $code, $match) === 1) {
                    return "{$relative} uses the '{$match[1]}' disk ({$driver} driver)";
                }
            }
        }

        return null;
    }

    private function mailerEvidence(string $transport): ?string
    {
        return $this->configEvidence('config/mail.php', 'default', 'MAIL_MAILER|MAIL_DRIVER', [$transport]);
    }

    /**
     * Check whether a config key's literal value, env() default, or .env.example value selects a driver.
     *
     * @param  list<string>  $values
     */
    private function configEvidence(string $file, string $key, string $envNames, array $values): ?string
    {
        $config = $this->source($file);
        $pattern = '/[\'"]'.preg_quote($key, '/').'[\'"]\s*=>\s*(?:env\(\s*[\'"]('.$envNames.')[\'"]\s*(?:,\s*[\'"]([\w-]+)[\'"])?\s*\)|[\'"]([\w-]+)[\'"])/';
        if (preg_match($pattern, $config, $match) !== 1) {
            return null;
        }

        $literal = $match[3] ?? '';
        if ($literal !== '') {
            return in_array($literal, $values, true) ? "{$file} sets '{$key}' to '{$literal}'" : null;
        }

        $env = $match[1];
        $example = $this->envExample()[$env] ?? null;
        if ($example !== null && in_array($example, $values, true)) {
            return ".env.example sets {$env}={$example}";
        }

        $default = $match[2] ?? '';
        if ($example === null && in_array($default, $values, true)) {
            return "{$file} defaults {$env} to '{$default}'";
        }

        return null;
    }

    private function findInTooling(string $pattern): ?string
    {
        $scripts = $this->composer->root['scripts'] ?? [];
        foreach ($scripts as $name => $commands) {
            foreach ((array) $commands as $command) {
                if (is_string($command) && preg_match($pattern, $command) === 1) {
                    return "composer script \"{$name}\"";
                }
            }
        }

        $files = array_merge(
            glob($this->root.'/.github/workflows/*.{yml,yaml}', GLOB_BRACE) ?: [],
            array_filter([$this->root.'/Makefile', $this->root.'/package.json'], 'is_file'),
        );
        foreach ($files as $file) {
            if (preg_match($pattern, (string) file_get_contents($file)) === 1) {
                return substr($file, strlen($this->root) + 1);
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private function envExample(): array
    {
        if ($this->envExample === null) {
            $this->envExample = [];
            $path = $this->root.'/.env.example';
            preg_match_all('/^\s*([A-Z0-9_]+)\s*=\s*"?([^"\s#]*)"?/m', is_file($path) ? (string) file_get_contents($path) : '', $pairs, PREG_SET_ORDER);
            foreach ($pairs as $pair) {
                $this->envExample[$pair[1]] = $pair[2];
            }
        }

        return $this->envExample;
    }

    private function source(string $relative): string
    {
        $path = $this->root.'/'.$relative;

        return $this->sources[$relative] ??= is_file($path) ? (string) file_get_contents($path) : '';
    }

    /** @return iterable<string, string> */
    private function phpFiles(string $relative): iterable
    {
        if (! is_dir($this->root.'/'.$relative)) {
            return;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root.'/'.$relative, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield substr($file->getPathname(), strlen($this->root) + 1) => (string) file_get_contents($file->getPathname());
            }
        }
    }
}
