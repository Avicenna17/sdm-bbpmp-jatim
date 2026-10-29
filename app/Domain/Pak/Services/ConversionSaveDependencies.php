<?php

namespace App\Domain\Pak\Services;

use App\Models\AkCoefficientRule;
use App\Models\EmployeePositionHistory;
use App\Models\FunctionalLevel;
use App\Models\FunctionalPosition;
use App\Models\FunctionalPositionLevel;
use App\Models\PakCase;
use App\Models\PakRuleSet;
use App\Models\PerformanceEvaluation;
use App\Models\PerformancePredicateRule;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** One transaction-local reader. Discovery never supplies calculation values. */
final class ConversionSaveDependencies extends ConversionPreviewDependencies
{
    private array $masters;

    private array $evaluationIds;

    private array $historyIds;

    private function __construct(private PakCase $lockedCase, private PakRuleSet $lockedRule) {}

    /** Scalar IDs only, outside the owned transaction. No models survive discovery. */
    public static function discover(int $caseId): array
    {
        $personId = PakCase::whereKey($caseId)->value('person_id');
        $personId = $personId === null ? null : (int) $personId;
        $histories = EmployeePositionHistory::where('person_id', $personId)->get(['functional_position_id', 'functional_level_id']);
        $positions = $histories->pluck('functional_position_id')->filter()->unique()->sort()->values()->all();
        $levels = $histories->pluck('functional_level_id')->filter()->unique()->sort()->values()->all();
        $pairs = [];
        foreach ($histories as $history) {
            if ($history->functional_position_id !== null && $history->functional_level_id !== null) {
                $pairs[$history->functional_position_id.':'.$history->functional_level_id] = [(int) $history->functional_position_id, (int) $history->functional_level_id];
            }
        }

        return ['person_id' => $personId, 'positions' => $positions, 'levels' => $levels,
            'pairs' => $pairs, 'mappings' => self::mappingIdsFor($pairs)];
    }

    /** Case, Person and RuleSet are already coordinated by the owning service. */
    public static function coordinate(PakCase $case, PakRuleSet $rule, array $plan): self
    {
        if (DB::transactionLevel() !== 1) {
            throw new LogicException('Conversion save dependencies require the owned transaction.');
        }
        if ($plan['person_id'] !== $case->person_id) {
            self::changed();
        }
        $reader = new self($case, $rule);
        // Child rows belong to the already-held RuleSet resource. All child locks precede masters.
        PerformancePredicateRule::where('pak_rule_set_id', $rule->id)->orderBy('id')->sharedLock()->get();
        AkCoefficientRule::where('pak_rule_set_id', $rule->id)->orderBy('id')->sharedLock()->get();
        $reader->masters = app(SharedMasterLocks::class)->acquire(mappingIds: $plan['mappings'], positionIds: $plan['positions'], levelIds: $plan['levels']);
        if ($case->conversionRuns()->sharedLock()->first() !== null) {
            throw ValidationException::withMessages(['case' => 'Initial save requires a case with no conversion-run history.']);
        }
        // Person coordination prevents compliant writers from adding/changing dependencies.
        // Individual PK locks make acquisition order independent of the query optimizer.
        $reader->evaluationIds = PerformanceEvaluation::where('person_id', $case->person_id)->orderBy('id')->pluck('id')->all();
        foreach ($reader->evaluationIds as $id) {
            PerformanceEvaluation::sharedLock()->findOrFail($id);
        }
        $reader->historyIds = EmployeePositionHistory::where('person_id', $case->person_id)->orderBy('id')->pluck('id')->all();
        $requiredPairs = [];
        foreach ($reader->historyIds as $id) {
            $history = EmployeePositionHistory::sharedLock()->findOrFail($id);
            foreach (['functional_position_id' => FunctionalPosition::class, 'functional_level_id' => FunctionalLevel::class] as $field => $class) {
                if ($history->$field !== null && ! isset($reader->masters[$class][$history->$field])) {
                    self::changed();
                }
            }
            if ($history->functional_position_id !== null && $history->functional_level_id !== null) {
                $key = $history->functional_position_id.':'.$history->functional_level_id;
                if (! isset($plan['pairs'][$key])) {
                    self::changed();
                }
                $requiredPairs[$key] = $plan['pairs'][$key];
            }
        }

        // Validate identities only, without acquiring any late mapping lock. The read view
        // starts after parent/master coordination; no discovery attribute is calculation input.
        // An unchanged pair with no mapping remains allowed: this is not a new validity rule.
        $lockedMappingIds = [];
        foreach ($reader->masters[FunctionalPositionLevel::class] as $mapping) {
            if (isset($requiredPairs[$mapping->functional_position_id.':'.$mapping->functional_level_id])) {
                $lockedMappingIds[] = $mapping->id;
            }
        }
        sort($lockedMappingIds, SORT_NUMERIC);
        if (self::mappingIdsFor($requiredPairs) !== $lockedMappingIds) {
            self::changed();
        }

        return $reader;
    }

    public function case(int $id): PakCase
    {
        if ($id !== $this->lockedCase->id) {
            self::changed();
        }

        return $this->lockedCase;
    }

    public function ruleSet(int $id): ?PakRuleSet
    {
        if ($id !== $this->lockedRule->id) {
            self::changed();
        }

        return $this->lockedRule;
    }

    public function sourceCandidates(Closure $scope): Collection
    {
        // Reentrant current reads of rows already locked above; never expand the lock set.
        return $scope(PerformanceEvaluation::whereIn('id', $this->evaluationIds))->sharedLock()->get();
    }

    public function historyCandidates(Closure $scope): Collection
    {
        $rows = $scope(EmployeePositionHistory::whereIn('id', $this->historyIds))->sharedLock()->get();
        foreach ($rows as $row) {
            $row->setRelation('functionalLevel', $this->masters[FunctionalLevel::class][$row->functional_level_id] ?? null);
        }

        return $rows;
    }

    public function predicateRules(int $ruleSetId, string $predicateCode): Collection
    {
        $this->ruleSet($ruleSetId);

        return PerformancePredicateRule::where('pak_rule_set_id', $ruleSetId)->where('predicate_code', $predicateCode)->limit(2)->sharedLock()->get();
    }

    public function coefficientRules(int $ruleSetId, int $functionalLevelId): Collection
    {
        $this->ruleSet($ruleSetId);

        return AkCoefficientRule::where('pak_rule_set_id', $ruleSetId)->where('functional_level_id', $functionalLevelId)->limit(2)->sharedLock()->get();
    }

    protected function masterRows(string $class, array $ids): Collection
    {
        $rows = new Collection;
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            if (! isset($this->masters[$class][$id])) {
                self::changed();
            }
            $rows->push($this->masters[$class][$id]);
        }

        return $rows;
    }

    /** @param array<string, array{int, int}> $pairs @return list<int> */
    private static function mappingIdsFor(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }

        return FunctionalPositionLevel::where(function ($query) use ($pairs) {
            foreach ($pairs as [$positionId, $levelId]) {
                $query->orWhere(fn ($q) => $q->where('functional_position_id', $positionId)->where('functional_level_id', $levelId));
            }
        })->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private static function changed(): never
    {
        throw ValidationException::withMessages(['snapshot' => 'Dependency lock plan changed. Obtain a new preview.']);
    }
}
