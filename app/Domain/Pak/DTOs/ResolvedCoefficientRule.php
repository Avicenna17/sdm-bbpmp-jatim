<?php

namespace App\Domain\Pak\DTOs;

final readonly class ResolvedCoefficientRule
{
    public function __construct(
        public int $ruleSetId,
        public int $childRuleId,
        public int $functionalLevelId,
        public string $annualCoefficient,
    ) {}
}
