<?php

namespace App\Domain\Pak\DTOs;

final readonly class ResolvedPredicateRule
{
    public function __construct(
        public int $ruleSetId,
        public int $childRuleId,
        public string $predicateCode,
        public string $predicateName,
        public string $predicateFactor,
    ) {}
}
