<?php

namespace App\Domain\Pak\DTOs;

use App\Domain\Pak\Services\ConversionManifestBuilder;

/** Immutable scalar-only evidence, not client authority or a persistence command. */
final readonly class ConversionDependencyGraph
{
    public array $groups;

    public function __construct(array $groups)
    {
        $required = ['case_context', 'coverage', 'source_candidates', 'selected_sources', 'position_assignments', 'history_dependencies', 'rule_set', 'versions'];
        $keys = array_keys($groups);
        sort($keys);
        sort($required);
        if ($keys !== $required) {
            throw new \InvalidArgumentException('Conversion dependency groups do not match snapshot contract.');
        }
        $this->groups = ConversionManifestBuilder::canonical($groups);
    }
}
