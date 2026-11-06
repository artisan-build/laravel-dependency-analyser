<?php

declare(strict_types=1);

namespace ArtisanBuild\LaravelDependencyAnalyser;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Extracts the PHP embedded in Blade templates into line-preserving PHP files ShipMonk can scan.
 */
final class BladeViews
{
    /**
     * Write an extracted copy of every Blade view under the target directory.
     *
     * @return array<string, string> Extracted file path mapped to the original view path
     */
    public static function extract(string $root, string $target): array
    {
        $views = $root.'/resources/views';
        if (! is_dir($views)) {
            return [];
        }

        $map = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($views, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $original = $file->getPathname();
            $extracted = $target.substr($original, strlen($root));
            if (! is_dir(dirname($extracted))) {
                mkdir(dirname($extracted), 0777, true);
            }
            file_put_contents($extracted, self::toPhp((string) file_get_contents($original)));
            $map[$extracted] = $original;
        }

        return $map;
    }

    /**
     * Keep only echoes, directive arguments, and raw PHP blocks, each on its original line.
     */
    public static function toPhp(string $blade): string
    {
        $blade = preg_replace_callback(
            '/\{\{--.*?--\}\}|@verbatim\b.*?@endverbatim\b/s',
            static fn (array $match): string => str_repeat("\n", substr_count($match[0], "\n")),
            $blade,
        ) ?? $blade;

        $php = '<?php ';
        $line = 1;
        $offset = 0;
        $pattern = '/@?\{\{\{|@?\{\{|\{!!|<\?(?:php|=)|@php\b(?!\s*\()|(?<![\w@])@\w+\s*\(/';

        while (preg_match($pattern, $blade, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            [$token, $start] = $match[0];
            $contentStart = $start + strlen($token);

            if ($token[0] === '@' && str_starts_with($token, '@{{')) {
                $offset = $contentStart;

                continue;
            }

            [$content, $offset] = match (true) {
                $token === '{{{' => self::until($blade, $contentStart, '}}}'),
                $token === '{{' => self::until($blade, $contentStart, '}}'),
                $token === '{!!' => self::until($blade, $contentStart, '!!}'),
                $token === '@php' => self::until($blade, $contentStart, '@endphp'),
                str_starts_with($token, '<?') => self::until($blade, $contentStart, '?>'),
                default => self::balanced($blade, $contentStart),
            };

            $contentLine = 1 + substr_count($blade, "\n", 0, $contentStart);
            $php .= str_repeat("\n", max(0, $contentLine - $line)).$content.";\n";
            $line = $contentLine + substr_count($content, "\n") + 1;
        }

        return $php;
    }

    /** @return array{string, int} */
    private static function until(string $blade, int $start, string $end): array
    {
        $stop = strpos($blade, $end, $start);
        if ($stop === false) {
            return [substr($blade, $start), strlen($blade)];
        }

        return [substr($blade, $start, $stop - $start), $stop + strlen($end)];
    }

    /**
     * Read directive arguments up to the parenthesis that closes the one before $start.
     *
     * @return array{string, int}
     */
    private static function balanced(string $blade, int $start): array
    {
        $depth = 1;
        $quote = null;
        $length = strlen($blade);
        for ($i = $start; $i < $length; $i++) {
            $char = $blade[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')' && --$depth === 0) {
                return [substr($blade, $start, $i - $start), $i + 1];
            }
        }

        return [substr($blade, $start), $length];
    }
}
