<?php

declare(strict_types=1);

namespace ArtisanBuild\LaravelDependencyAnalyser;

use ShipMonk\ComposerDependencyAnalyser\Result\AnalysisResult;
use ShipMonk\ComposerDependencyAnalyser\Result\SymbolUsage;
use TypeError;

/**
 * ShipMonk's result, reclassified with Laravel knowledge: discovery, config-selected drivers,
 * tooling, framework-guaranteed packages, and Blade views mapped back to their real paths.
 */
final class Report extends AnalysisResult
{
    /** @var array<string, list<string>> Auto-registered packages mapped to their provider/alias classes */
    public readonly array $needsReview;

    /** @var array<string, list<string>> Optional integrations of used packages mapped to "suggester: reason" */
    public readonly array $optionalIntegrations;

    /** @var array<string, string> Packages Laravel loads through configuration, mapped to the evidence */
    public readonly array $usedThroughLaravel;

    /** @var array<string, string> Packages used only by scripts or CI, mapped to the evidence */
    public readonly array $usedByTooling;

    /** @var list<string> Shadow dependencies that laravel/framework requires directly */
    public readonly array $frameworkProvided;

    /** @var array<string, array<string, list<SymbolUsage>>> */
    private array $safeUsages;

    /** @param array<string, string> $pathMap Scanned stand-in files mapped to the files they represent */
    public function __construct(
        AnalysisResult $result,
        PackageDiscovery $composer,
        LaravelIntegrations $integrations,
        private readonly array $pathMap = [],
    ) {
        try {
            $usages = $result->getUsages();
        } catch (TypeError) {
            // ShipMonk 1.8.4 leaves its usages property null when no usages exist.
            $usages = [];
        }
        $this->safeUsages = $this->remapNested($usages);

        $discovered = $composer->discovered();
        $unused = [];
        $prodOnlyInDev = $result->getProdDependencyOnlyInDevErrors();
        $needsReview = $optional = $usedThroughLaravel = $usedByTooling = [];

        foreach ($result->getUnusedDependencyErrors() as $package) {
            $runtime = $integrations->runtimeEvidence($package);
            $tooling = $runtime === null ? $integrations->toolingEvidence($package) : null;
            $suggesters = array_intersect_key($composer->suggesters($package), $this->safeUsages);

            if ($runtime !== null) {
                $usedThroughLaravel[$package] = $runtime;
            } elseif ($tooling !== null) {
                $usedByTooling[$package] = $tooling;
                if (! isset($composer->root['require-dev'][$package])) {
                    $prodOnlyInDev[] = $package;
                }
            } elseif (isset($discovered[$package])) {
                // Registration is not proof of usage, but absence of a PHP reference is not
                // enough to recommend removing a package that Laravel loads automatically.
                $needsReview[$package] = $discovered[$package];
            } elseif ($suggesters !== []) {
                $optional[$package] = array_map(
                    static fn (string $suggester, string $reason): string => trim("{$suggester}: {$reason}"),
                    array_keys($suggesters),
                    $suggesters,
                );
            } else {
                $unused[] = $package;
            }
        }

        $this->needsReview = $needsReview;
        $this->optionalIntegrations = $optional;
        $this->usedThroughLaravel = $usedThroughLaravel;
        $this->usedByTooling = $usedByTooling;

        // Laravel apps idiomatically use Carbon, Symfony components, PSR interfaces and the
        // extensions the framework itself requires; listing them as shadows buries real findings.
        $shadows = $result->getShadowDependencyErrors();
        $frameworkRequirements = isset($composer->root['require']['laravel/framework'])
            ? array_flip($composer->requirementsOf('laravel/framework'))
            : [];
        $this->frameworkProvided = array_keys(array_intersect_key($shadows, $frameworkRequirements));

        parent::__construct(
            $result->getScannedFilesCount(),
            $result->getElapsedTime(),
            $this->safeUsages,
            $this->remapFlat(array_diff_key($result->getUnknownClassErrors(), $integrations->declaredClasses())),
            $this->remapFlat($result->getUnknownFunctionErrors()),
            $this->remapNested(array_diff_key($shadows, $frameworkRequirements)),
            $this->remapNested($result->getDevDependencyInProductionErrors()),
            array_values(array_unique($prodOnlyInDev)),
            $unused,
            $result->getUnusedIgnores(),
        );
    }

    /** @return array<string, array<string, list<SymbolUsage>>> */
    public function getUsages(): array
    {
        return $this->safeUsages;
    }

    /** Whether anything still needs a human decision before the result can be called clean. */
    public function requiresReview(): bool
    {
        return $this->needsReview !== [] || $this->optionalIntegrations !== [];
    }

    /**
     * @param  array<string, array<string, list<SymbolUsage>>>  $errors
     * @return array<string, array<string, list<SymbolUsage>>>
     */
    private function remapNested(array $errors): array
    {
        return array_map(fn (array $symbols): array => $this->remapFlat($symbols), $errors);
    }

    /**
     * @param  array<string, list<SymbolUsage>>  $symbols
     * @return array<string, list<SymbolUsage>>
     */
    private function remapFlat(array $symbols): array
    {
        if ($this->pathMap === []) {
            return $symbols;
        }

        return array_map(fn (array $usages): array => array_map(
            fn (SymbolUsage $usage): SymbolUsage => isset($this->pathMap[$usage->getFilepath()])
                ? new SymbolUsage($this->pathMap[$usage->getFilepath()], $usage->getLineNumber(), $usage->getKind())
                : $usage,
            $usages,
        ), $symbols);
    }
}
