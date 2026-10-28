<?php

namespace App\Domain\Pak\Queries;

use App\Domain\Pak\DTOs\MonthlyCoverage;
use App\Models\AkCoefficientRule;
use App\Models\EmployeePositionHistory;
use App\Models\PakCase;
use App\Models\PakRuleSet;
use App\Models\PerformanceEvaluation;
use App\Models\PerformancePredicateRule;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Internal conversion reads, not a persistence or transaction API.
 * Resolver scopes only construct predicates; this boundary executes reads.
 * Models are internal inputs and must never escape into result DTOs.
 */
interface ConversionDependencyReader
{
    public function case(int $id): PakCase;

    public function ruleSet(int $id): ?PakRuleSet;

    /** @param Closure(Builder<PerformanceEvaluation>): Builder<PerformanceEvaluation> $scope
     * @return Collection<int, PerformanceEvaluation>
     */
    public function sourceCandidates(Closure $scope): Collection;

    /**
     * Every returned history must have functionalLevel loaded (including NULL).
     * The resolver accesses that relation without any lazy database query.
     *
     * @param  Closure(Builder<EmployeePositionHistory>): Builder<EmployeePositionHistory>  $scope
     * @return Collection<int, EmployeePositionHistory>
     */
    public function historyCandidates(Closure $scope): Collection;

    /** @return Collection<int, PerformancePredicateRule> Exact SQL lookup, at most two rows. */
    public function predicateRules(int $ruleSetId, string $predicateCode): Collection;

    /** @return Collection<int, AkCoefficientRule> Exact SQL lookup, at most two rows. */
    public function coefficientRules(int $ruleSetId, int $functionalLevelId): Collection;

    /** @param list<string> $fields @return array<string, int|string|bool|null> */
    public function fields(Model $row, array $fields): array;

    /** @return array{sources: list<array>, histories: list<array>, levels: list<array>, positions: list<array>} */
    public function read(int $personId, MonthlyCoverage $coverage): array;
}
