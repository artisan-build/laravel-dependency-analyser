<?php

declare(strict_types=1);

namespace ArtisanBuild\LaravelDependencyAnalyser;

use Composer\Autoload\ClassLoader;

/**
 * Resolves Laravel's global class aliases (DB, Log, package facades) so `\DB::` counts as framework usage.
 */
final class FacadeAliases
{
    /**
     * Collect aliases from the framework defaults, config/app.php, and package discovery.
     *
     * @param  array<string, string>  $discovered
     * @return array<string, string> Alias mapped to the aliased class
     */
    public static function resolve(string $root, array $discovered): array
    {
        $aliases = [];

        // Laravel 10 apps merge these explicitly; from Laravel 11 they are always registered.
        $defaults = is_callable($method = ['Illuminate\\Support\\Facades\\Facade', 'defaultAliases']) ? $method() : null;
        $defaults = is_object($defaults) && method_exists($defaults, 'all') ? $defaults->all() : [];
        foreach (is_array($defaults) ? $defaults : [] as $alias => $class) {
            $aliases[(string) $alias] = ltrim((string) $class, '\\');
        }

        return array_merge($aliases, $discovered, self::fromAppConfig($root.'/config/app.php'));
    }

    /**
     * Build an unregistered class loader that maps each alias to the file of the class it aliases.
     * ShipMonk then attributes `\DB::table()` to the package that ships the facade.
     *
     * @param  array<string, string>  $aliases
     * @param  array<string, ClassLoader>  $loaders
     */
    public static function classLoader(array $aliases, array $loaders): ClassLoader
    {
        $classMap = [];
        foreach ($aliases as $alias => $class) {
            foreach ($loaders as $loader) {
                $file = $loader->findFile($class);
                if ($file !== false) {
                    $classMap[$alias] = $file;
                    break;
                }
            }
        }

        $aliasLoader = new ClassLoader;
        $aliasLoader->addClassMap($classMap);

        return $aliasLoader;
    }

    /**
     * Statically read `'Alias' => Some\Class::class` pairs from the aliases array, honouring `use` imports.
     *
     * @return array<string, string>
     */
    private static function fromAppConfig(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $code = (string) file_get_contents($path);
        $imports = [];
        preg_match_all('/^use\s+\\\\?([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/mi', $code, $uses, PREG_SET_ORDER);
        foreach ($uses as $use) {
            $alias = ($use[2] ?? '') !== '' ? $use[2] : basename(str_replace('\\', '/', $use[1]));
            $imports[$alias] = $use[1];
        }

        $start = strpos($code, "'aliases'");
        if ($start === false) {
            return [];
        }

        $aliases = [];
        preg_match_all(
            '/[\'"](\w+)[\'"]\s*=>\s*(?:\\\\?([\w\\\\]+)::class|[\'"]\\\\?([\w\\\\]+)[\'"])/',
            substr($code, $start),
            $pairs,
            PREG_SET_ORDER,
        );
        foreach ($pairs as $pair) {
            $class = ($pair[2] ?? '') !== '' ? $pair[2] : ($pair[3] ?? '');
            $first = explode('\\', $class)[0];
            $aliases[$pair[1]] = isset($imports[$first])
                ? $imports[$first].substr($class, strlen($first))
                : $class;
        }

        return $aliases;
    }
}
