<?php

declare(strict_types=1);

namespace ArtisanBuild\LaravelDependencyAnalyser\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ApplicationTest extends TestCase
{
    private string $root;

    /** @var array<string, mixed> */
    private array $composer;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/laravel-dependency-analyser-'.bin2hex(random_bytes(6));
        mkdir($this->root);
        $this->write('app/.gitkeep', '');
        $this->composer = [
            'name' => 'test/laravel-app',
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
            'repositories' => [['type' => 'path', 'url' => 'packages/*', 'options' => ['symlink' => false]]],
            'config' => ['allow-plugins' => false],
        ];
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    /** @return iterable<string, array{string}> */
    public static function sourcePaths(): iterable
    {
        foreach (['app/Example.php', 'config/app.php', 'routes/web.php', 'bootstrap/providers.php',
            'database/migrations/create.php', 'public/index.php', 'artisan'] as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('sourcePaths')]
    public function test_laravel_paths_count_as_usage_without_executing_them(string $path): void
    {
        $this->package('referenced');
        $this->write($path, '<?php throw new \\RuntimeException("Application must not boot"); Referenced\\Feature::class;');
        $this->install();

        [$exit, $stdout, $stderr] = $this->analyse();

        self::assertSame(0, $exit, $stdout.$stderr);
        self::assertStringNotContainsString('unused dependenc', $stdout);
    }

    public function test_discovery_is_review_not_usage_and_honours_dont_discover(): void
    {
        $this->package('registered', extra: ['laravel' => ['providers' => ['Registered\\Feature']]]);
        $this->package('alias', extra: ['laravel' => ['aliases' => ['Alias' => 'Alias\\Feature']]]);
        $this->package('disabled', extra: ['laravel' => ['providers' => ['Disabled\\Feature']]]);
        $this->package('unused');
        $this->composer['extra'] = ['laravel' => ['dont-discover' => ['test/disabled']]];
        $this->install();

        [$exit, $stdout, $stderr] = $this->analyse();

        self::assertSame(1, $exit, $stdout.$stderr);
        self::assertStringContainsString('test/unused', $stdout);
        self::assertStringContainsString('test/disabled', $stdout);
        self::assertStringNotContainsString('test/registered', $stdout);
        self::assertStringNotContainsString('test/alias', $stdout);
        self::assertStringContainsString('test/registered: Registered\\Feature', $stderr);
        self::assertStringContainsString('test/alias: Alias\\Feature', $stderr);
        self::assertStringNotContainsString('test/disabled', $stderr);
    }

    public function test_review_only_is_not_a_clean_ci_result(): void
    {
        $this->package('registered', extra: ['laravel' => ['providers' => ['Registered\\Feature']]]);
        $this->install();

        [$exit, , $stderr] = $this->analyse();

        self::assertSame(2, $exit);
        self::assertStringContainsString('Registration does not prove usage', $stderr);
    }

    public function test_explicit_provider_and_alias_class_references_are_usage(): void
    {
        $this->package('registered', extra: ['laravel' => ['providers' => ['Registered\\Feature']]]);
        $this->package('alias', extra: ['laravel' => ['aliases' => ['Alias' => 'Alias\\Feature']]]);
        $this->write('bootstrap/providers.php', '<?php return [Registered\\Feature::class];');
        $this->write('config/app.php', '<?php return ["aliases" => ["Alias" => Alias\\Feature::class]];');
        $this->install();

        [$exit, $stdout, $stderr] = $this->analyse();

        self::assertSame(0, $exit, $stdout.$stderr);
        self::assertStringNotContainsString('requiring review', $stderr);
    }

    public function test_wildcard_and_package_contributed_discovery_exclusions(): void
    {
        $this->package('disabled', extra: ['laravel' => ['providers' => ['Disabled\\Feature']]]);
        $this->package('disabler', extra: ['laravel' => ['dont-discover' => ['*']]]);
        $this->install();

        [$exit, $stdout, $stderr] = $this->analyse();

        self::assertSame(1, $exit);
        self::assertStringContainsString('test/disabled', $stdout);
        self::assertStringNotContainsString('requiring review', $stderr);
    }

    public function test_generated_cache_is_not_usage_and_custom_vendor_dir_works(): void
    {
        $this->package('cached');
        $this->composer['config']['vendor-dir'] = 'dependencies';
        $this->write('bootstrap/cache/packages.php', '<?php return [Cached\\Feature::class];');
        $this->write('storage/framework/views/view.php', '<?php Cached\\Feature::class;');
        $this->install();

        [$exit, $stdout, $stderr] = $this->analyse();

        self::assertSame(1, $exit, $stdout.$stderr);
        self::assertStringContainsString('test/cached', $stdout);
    }

    public function test_tests_are_dev_paths_and_prod_dev_checks_are_preserved(): void
    {
        $this->package('testonly');
        $this->package('devtool', dev: true);
        $this->write('tests/ExampleTest.php', '<?php Testonly\\Feature::class;');
        $this->write('routes/web.php', '<?php Devtool\\Feature::class;');
        $this->install();

        [$exit, $stdout, $stderr] = $this->analyse();

        self::assertSame(1, $exit, $stdout.$stderr);
        self::assertStringContainsString('dev dependency in production code', $stdout);
        self::assertStringContainsString('test/devtool', $stdout);
        self::assertStringContainsString('prod dependency used only in dev paths', $stdout);
        self::assertStringContainsString('test/testonly', $stdout);
    }

    public function test_transitive_usage_is_reported_as_shadow_dependency(): void
    {
        $this->package('direct', requires: ['test/transitive' => '*']);
        $this->package('transitive');
        unset($this->composer['require']['test/transitive']);
        $this->write('routes/web.php', '<?php Direct\\Feature::class; Transitive\\Feature::class;');
        $this->install();

        [$exit, $stdout, $stderr] = $this->analyse();

        self::assertSame(1, $exit, $stdout.$stderr);
        self::assertStringContainsString('shadow dependency', $stdout);
        self::assertStringContainsString('test/transitive', $stdout);
    }

    public function test_user_configuration_is_preserved_and_missing_install_is_an_error(): void
    {
        $this->package('ignored', extra: ['laravel' => ['providers' => ['Ignored\\Feature']]]);
        $this->write('composer-dependency-analyser.php', <<<'PHP'
<?php
use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;
return (new Configuration())->ignoreErrorsOnPackage('test/ignored', [ErrorType::UNUSED_DEPENDENCY]);
PHP);
        $this->install();
        [$exit, $stdout, $stderr] = $this->analyse();
        self::assertSame(0, $exit, $stdout.$stderr);
        self::assertStringNotContainsString('requiring review', $stderr);

        unlink($this->root.'/vendor/composer/installed.json');
        [$exit, , $stderr] = $this->analyse();
        self::assertSame(3, $exit);
        self::assertStringContainsString('Run composer install', $stderr);
    }

    public function test_external_project_path_and_junit_output(): void
    {
        $this->package('registered', extra: ['laravel' => ['providers' => ['Registered\\Feature']]]);
        $this->install();
        rename($this->root.'/composer.json', $this->root.'/custom-composer.json');
        [$exit, $stdout, $stderr] = $this->command(
            [PHP_BINARY, dirname(__DIR__).'/bin/laravel-dependency-analyser', '--composer-json', $this->root.'/custom-composer.json', '--format=junit'],
            dirname($this->root),
        );
        self::assertSame(2, $exit, $stdout.$stderr);
        self::assertStringStartsWith('<?xml', $stdout);
        self::assertStringContainsString('test/registered', $stderr);
        self::assertNotFalse(simplexml_load_string($stdout));
    }

    public function test_composer_installed_binary_and_empty_usage_dump(): void
    {
        $this->package('unused');
        $this->composer['repositories'][] = [
            'type' => 'path',
            'url' => dirname(__DIR__),
            'options' => ['symlink' => false, 'versions' => ['artisan-build/laravel-dependency-analyser' => 'dev-main']],
        ];
        $this->composer['require-dev']['artisan-build/laravel-dependency-analyser'] = 'dev-main';
        $this->install();

        [$exit, $stdout, $stderr] = $this->command([PHP_BINARY, $this->root.'/vendor/bin/laravel-dependency-analyser']);
        self::assertSame(1, $exit, $stdout.$stderr);
        self::assertStringContainsString('test/unused', $stdout);

        [$exit, $stdout, $stderr] = $this->command([PHP_BINARY, $this->root.'/vendor/bin/laravel-dependency-analyser', '--dump-usages=test/unused']);
        self::assertSame(1, $exit, $stdout.$stderr);
        self::assertStringContainsString('0 usages', $stdout);

        [$exit, $stdout, $stderr] = $this->command([PHP_BINARY, $this->root.'/vendor/bin/laravel-dependency-analyser', '--version']);
        self::assertSame(0, $exit, $stdout.$stderr);
        self::assertStringContainsString('Laravel Dependency Analyser dev-main', $stdout);
    }

    public function test_global_aliases_resolve_to_the_package_behind_them(): void
    {
        $this->package('discovered', extra: ['laravel' => ['aliases' => ['Discovered' => 'Discovered\\Feature']]]);
        $this->package('configured');
        $this->write('config/app.php', <<<'PHP'
<?php
use Configured\Feature as ConfiguredFeature;
return ['aliases' => ['Configured' => ConfiguredFeature::class]];
PHP);
        $this->write('app/Example.php', '<?php namespace App; class Example { function run() { \\Discovered::go(); \\Configured::go(); } }');
        $this->composer['autoload']['psr-4']['App\\'] = 'app/';
        $this->install();

        [$exit, $stdout, $stderr] = $this->analyse();

        self::assertSame(0, $exit, $stdout.$stderr);
        self::assertStringNotContainsString('unknown class', $stdout);
        self::assertStringNotContainsString('requiring review', $stderr);
    }

    public function test_blade_views_are_usage_and_findings_point_at_the_view(): void
    {
        $this->package('viewonly');
        $this->write('resources/views/page.blade.php', <<<'BLADE'
{{-- \Missing\Commented::class --}}
<p>{{ \Viewonly\Feature::class }}</p>
@{{ \Missing\Escaped::class }}
@if (count($items) > 0)
    {!! \Missing\Thing::make() !!}
@endif
BLADE);
        $this->install();

        [$exit, $stdout, $stderr] = $this->analyse();

        self::assertSame(1, $exit, $stdout.$stderr);
        self::assertStringNotContainsString('unused dependenc', $stdout);
        self::assertStringContainsString('Missing\\Thing', $stdout);
        self::assertStringContainsString('resources/views/page.blade.php:5', $stdout);
        self::assertStringNotContainsString('Missing\\Commented', $stdout);
        self::assertStringNotContainsString('Missing\\Escaped', $stdout);
    }

    public function test_factories_are_dev_code(): void
    {
        $this->package('faker', dev: true);
        $this->write('database/factories/UserFactory.php', '<?php Faker\\Feature::class;');
        $this->install();

        [$exit, $stdout, $stderr] = $this->analyse();

        self::assertSame(0, $exit, $stdout.$stderr);
        self::assertStringNotContainsString('dev dependency in production', $stdout);
    }

    public function test_classes_declared_in_test_files_are_not_unknown(): void
    {
        $this->package('used');
        $this->write('routes/web.php', '<?php Used\\Feature::class;');
        $this->write('tests/ExampleTest.php', "<?php\nabstract class HelperTestCase {}\nuses(HelperTestCase::class);");
        $this->install();

        [$exit, $stdout, $stderr] = $this->analyse();

        self::assertSame(0, $exit, $stdout.$stderr);
        self::assertStringNotContainsString('HelperTestCase', $stdout);
    }

    public function test_optional_integrations_of_used_packages_need_review_with_the_reason(): void
    {
        $this->package('host', metadata: ['suggest' => ['test/driver' => 'Required to use the driver.']]);
        $this->package('driver');
        $this->write('routes/web.php', '<?php Host\\Feature::class;');
        $this->install();

        [$exit, $stdout, $stderr] = $this->analyse();

        self::assertSame(2, $exit, $stdout.$stderr);
        self::assertStringNotContainsString('test/driver', $stdout);
        self::assertStringContainsString('test/driver <- test/host: Required to use the driver.', $stderr);
    }

    public function test_config_selected_drivers_and_tooling_count_as_usage(): void
    {
        $this->package('s3', fullName: 'league/flysystem-aws-s3-v3');
        $this->package('runner', fullName: 'brianium/paratest');
        $this->package('cli', metadata: ['bin' => ['bin/cli-tool']]);
        $this->write('packages/cli/bin/cli-tool', '#!/usr/bin/env php');
        $this->write('config/filesystems.php', "<?php return ['disks' => ['uploads' => ['driver' => 's3']]];");
        $this->write('app/Upload.php', "<?php return ['disk' => 'uploads'];");
        $this->composer['scripts'] = ['test-parallel' => '@php artisan test --parallel', 'tool' => 'vendor/bin/cli-tool'];
        $this->install();

        [$exit, $stdout, $stderr] = $this->analyse();

        self::assertSame(1, $exit, $stdout.$stderr);
        self::assertStringNotContainsString('unused dependenc', $stdout);
        self::assertStringContainsString("league/flysystem-aws-s3-v3: app/Upload.php uses the 'uploads' disk (s3 driver)", $stderr);
        self::assertStringContainsString('brianium/paratest: tooling: composer script "test-parallel"', $stderr);
        self::assertStringContainsString('used only in dev paths', $stdout);
        self::assertStringContainsString('test/cli', $stdout);
    }

    public function test_framework_requirements_are_not_reported_as_shadow_dependencies(): void
    {
        $this->package('framework', requires: ['test/carbon' => '*'], fullName: 'laravel/framework', metadata: ['version' => '99.0.0']);
        $this->package('carbon');
        $this->package('direct', requires: ['test/transitive' => '*']);
        $this->package('transitive');
        unset($this->composer['require']['test/carbon'], $this->composer['require']['test/transitive']);
        $this->write('routes/web.php', '<?php Framework\\Feature::class; Carbon\\Feature::class; Direct\\Feature::class; Transitive\\Feature::class;');
        $this->install();

        [$exit, $stdout, $stderr] = $this->analyse();

        self::assertSame(1, $exit, $stdout.$stderr);
        self::assertStringContainsString('test/transitive', $stdout);
        self::assertStringNotContainsString('test/carbon', $stdout);
        self::assertStringContainsString('laravel/framework requires them directly', $stderr);
    }

    public function test_review_shows_published_config_and_env_setup(): void
    {
        $this->package('registered', extra: ['laravel' => ['providers' => ['Registered\\Feature']]]);
        $this->write('packages/registered/config/registered.php', "<?php return ['token' => env('REGISTERED_TOKEN'), 'name' => env('APP_NAME')];");
        $this->write('config/registered.php', '<?php return [];');
        $this->write('.env.example', "APP_NAME=Laravel\nREGISTERED_TOKEN=\n");
        $this->install();

        [$exit, , $stderr] = $this->analyse();

        self::assertSame(2, $exit, $stderr);
        self::assertStringContainsString('(config/registered.php published; .env.example sets REGISTERED_TOKEN)', $stderr);
    }

    /** @param array<string, mixed> $extra
     * @param  array<string, string>  $requires
     * @param  array<string, mixed>  $metadata
     */
    private function package(string $name, array $extra = [], bool $dev = false, array $requires = [], ?string $fullName = null, array $metadata = []): void
    {
        $namespace = ucfirst($name);
        $fullName ??= 'test/'.$name;
        $this->write('packages/'.$name.'/composer.json', json_encode($metadata + [
            'name' => $fullName,
            'version' => '1.0.0',
            'autoload' => ['psr-4' => [$namespace.'\\' => 'src/']],
            'extra' => (object) $extra,
            'require' => (object) $requires,
        ], JSON_THROW_ON_ERROR));
        $this->write('packages/'.$name.'/src/Feature.php', '<?php namespace '.$namespace.'; class Feature {}');
        $this->composer[$dev ? 'require-dev' : 'require'][$fullName] = '*';
    }

    private function write(string $path, string $content): void
    {
        $absolute = $this->root.'/'.$path;
        if (! is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0777, true);
        }
        file_put_contents($absolute, $content);
    }

    private function install(): void
    {
        $this->write('composer.json', json_encode($this->composer, JSON_THROW_ON_ERROR));
        [$exit, $stdout, $stderr] = $this->command(['composer', 'update', '--no-interaction', '--no-plugins', '--no-scripts']);
        self::assertSame(0, $exit, $stdout.$stderr);
    }

    /** @return array{int, string, string} */
    private function analyse(): array
    {
        return $this->command([PHP_BINARY, dirname(__DIR__).'/bin/laravel-dependency-analyser']);
    }

    /** @param list<string> $command
     * @return array{int, string, string}
     */
    private function command(array $command, ?string $cwd = null): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd ?? $this->root);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
