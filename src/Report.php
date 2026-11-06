<?php

declare(strict_types=1);

namespace ArtisanBuild\LaravelDependencyAnalyser;

use ShipMonk\ComposerDependencyAnalyser\Result\AnalysisResult;
use ShipMonk\ComposerDependencyAnalyser\Result\SymbolUsage;
use TypeError;

final class Report extends AnalysisResult
{
    /** @var array<string, list<string>> */
    public readonly array $needsReview;

    /** @var array<string, array<string, list<SymbolUsage>>> */
    private array $safeUsages;

    /** @param array<string, list<string>> $discovered */
    public function __construct(AnalysisResult $result, array $discovered)
    {
        try {
            $this->safeUsages = $result->getUsages();
        } catch (TypeError) {
            // ShipMonk 1.8.4 leaves its usages property null when no usages exist.
            $this->safeUsages = [];
        }

        // Registration is not proof of usage, but absence of a PHP reference is not
        // enough to recommend removing a package that Laravel loads automatically.
        $this->needsReview = array_intersect_key($discovered, array_flip($result->getUnusedDependencyErrors()));
        parent::__construct(
            $result->getScannedFilesCount(),
            $result->getElapsedTime(),
            $this->safeUsages,
            $result->getUnknownClassErrors(),
            $result->getUnknownFunctionErrors(),
            $result->getShadowDependencyErrors(),
            $result->getDevDependencyInProductionErrors(),
            $result->getProdDependencyOnlyInDevErrors(),
            array_values(array_diff($result->getUnusedDependencyErrors(), array_keys($this->needsReview))),
            $result->getUnusedIgnores(),
        );
    }

    /** @return array<string, array<string, list<SymbolUsage>>> */
    public function getUsages(): array
    {
        return $this->safeUsages;
    }
}
