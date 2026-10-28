<?php

namespace App\Domain\Pak\Services;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
use App\Domain\Pak\Calculators\ConversionCalculator;
use App\Domain\Pak\DTOs\ConversionDependencyGraph;
use App\Domain\Pak\DTOs\ConversionInput;
use App\Domain\Pak\DTOs\ConversionPreview;
use App\Domain\Pak\DTOs\ConversionPreviewLine;
use App\Domain\Pak\DTOs\ConversionPreviewRequest;
use App\Domain\Pak\DTOs\ConversionRegimeEvidence;
use App\Domain\Pak\DTOs\MonthlyCoverageRequest;
use App\Domain\Pak\DTOs\SkpSelectedSource;
use App\Domain\Pak\Exceptions\ConversionRuleException;
use App\Domain\Pak\Rules\ConversionPersistenceValues;
use App\Models\PakCase;
use App\Models\PakRuleSet;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ConversionService
{
    public const PERMISSIONS = ['pak.conversion.calculate', 'pak.conversion.view', 'pak.case.view', 'pak.case.update', 'pak.skp.view', 'pak.employee.view', 'pak.rule-set.view'];

    public function preview(User $actor, ConversionPreviewRequest $request): ConversionPreview
    {
        $connection = DB::connection();
        $this->requireTopLevel($connection);
        foreach (self::PERMISSIONS as $permission) {
            Access::authorize($actor, $permission);
        }
        $this->requireTopLevel($connection);
        $this->requireSnapshotIsolation($connection);

        // All semantic reads use the default connection and its owned top-level snapshot.
        return $connection->transaction(fn () => $this->build($request));
    }

    private function requireTopLevel(Connection $connection): void
    {
        if ($connection->transactionLevel() !== 0) {
            throw new LogicException('Conversion preview requires ownership of a top-level transaction.');
        }
    }

    private function requireSnapshotIsolation(Connection $connection): void
    {
        $driver = $connection->getDriverName();
        if ($driver === 'sqlite') {
            return;
        }
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new LogicException('Unsupported conversion preview snapshot connection.');
        }
        // getPdo() is the WRITE PDO used by beginTransaction, never a read replica.
        $pdo = $connection->getPdo();
        try {
            $isolation = $pdo->query('SELECT @@session.transaction_isolation')->fetchColumn();
        } catch (\PDOException $e) {
            // MariaDB 10.5 exposes tx_isolation; fall back only for unknown variable.
            if (($e->errorInfo[1] ?? null) !== 1193) {
                throw $e;
            }
            $isolation = $pdo->query('SELECT @@session.tx_isolation')->fetchColumn();
        }
        if (! is_string($isolation) || strtoupper(str_replace('-', ' ', trim($isolation))) !== 'REPEATABLE READ') {
            throw new LogicException('Conversion preview requires a repeatable-read connection.');
        }
    }

    private function build(ConversionPreviewRequest $request): ConversionPreview
    {
        $case = PakCase::findOrFail($request->pak_case_id);
        $rule = $case->pak_rule_set_id === null ? null : PakRuleSet::find($case->pak_rule_set_id);
        if (! $rule) {
            throw new ConversionRuleException('RULE_SET_NOT_FOUND');
        }
        if ($rule->status !== 'DRAFT') {
            throw new ConversionRuleException('RULE_SET_NOT_USABLE');
        }
        if ($rule->first_used_at === null) {
            throw new ConversionRuleException('RULE_INTEGRITY_ERROR');
        }
        $from = $this->validityDate($rule->getRawOriginal('valid_from'));
        $until = $this->validityDate($rule->getRawOriginal('valid_until'));
        if ($from !== null && $until !== null && $from > $until) {
            throw new ConversionRuleException('RULE_INTEGRITY_ERROR');
        }
        $reader = app(ConversionPreviewDependencies::class);
        $caseDates = $reader->fields($case, ['period_start', 'period_end']);
        $coverage = app(MonthlyCoverageResolver::class)->resolve(MonthlyCoverageRequest::from(
            $caseDates['period_start'], $caseDates['period_end'], $request->requested_start, $request->requested_end));
        $notEvaluated = ['state' => 'NOT_EVALUATED'];
        $groups = [
            'case_context' => $reader->fields($case, ['id', 'person_id', 'pak_rule_set_id', 'purpose_type', 'status', 'period_start', 'period_end', 'employee_data_as_of', 'finalized_at'])
                + ['requested_start' => $request->requested_start, 'requested_end' => $request->requested_end],
            'coverage' => ['state' => 'EVALUATED', 'complete' => $coverage->is_complete, 'months' => $coverage->months, 'month_count' => $coverage->month_count,
                'partitions' => $coverage->year_partitions === null ? null : array_map(fn ($p) => ['year' => $p->year, 'start_month' => $p->start_month, 'end_month' => $p->end_month], $coverage->year_partitions)],
            'source_candidates' => $notEvaluated, 'selected_sources' => $notEvaluated, 'position_assignments' => $notEvaluated, 'history_dependencies' => $notEvaluated,
            'rule_set' => $reader->fields($rule, ['id', 'code', 'version', 'status', 'valid_from', 'valid_until', 'first_used_at', 'reference'])
                + ['validity' => 'NOT_EVALUATED', 'children' => $notEvaluated],
            'versions' => ['snapshot_schema_version' => ConversionPersistenceValues::SNAPSHOT_SCHEMA_VERSION,
                'calculation_contract_version' => ConversionPersistenceValues::CALCULATION_CONTRACT_VERSION,
                'rounding_profile' => ConversionRoundingPolicy::PROFILE, 'rounding_version' => ConversionRoundingPolicy::VERSION],
        ];
        $issues = $coverage->issues;
        $positions = null;
        $lines = [];
        $calculator = app(ConversionCalculator::class);
        $rounding = app(ConversionRoundingPolicy::class);
        if ($coverage->is_complete) {
            $reason = $from === null || $until === null ? 'UNKNOWN_BOUNDARY'
                : ($from > $request->requested_start || $until < $request->requested_end ? 'OUTSIDE_VALIDITY' : null);
            $groups['rule_set']['validity'] = $reason ?? 'VALID';
            if ($reason !== null) {
                $issues[] = ['issue_code' => 'RULE_VALIDITY_REQUIRES_REVIEW', 'reason' => $reason,
                    'requested_start' => $request->requested_start, 'requested_end' => $request->requested_end,
                    'valid_from' => $from, 'valid_until' => $until, 'rule_set_id' => $rule->id, 'rule_set_version' => $rule->version];
            } else {
                $evidence = $reader->read($case->person_id, $coverage);
                $groups['source_candidates'] = ['state' => 'EVALUATED', 'items' => $evidence['sources']];
                $groups['history_dependencies'] = ['state' => 'EVALUATED', 'items' => $evidence['histories'], 'levels' => $evidence['levels'], 'positions' => $evidence['positions']];
                $histories = array_column($evidence['histories'], null, 'id');
                $positions = app(PositionSegmentResolver::class)->resolve($case->person_id, $coverage->months);
                $issues = array_merge($issues, $positions->issues);
                $byMonth = [];
                foreach ($positions->assignments as $position) {
                    $byMonth[$position->month] = $position;
                }
                $sources = [];
                foreach ($coverage->year_partitions as $partition) {
                    $selection = app(SkpSourceSelectionResolver::class)->resolve($case->person_id, $partition, $positions->positionContext());
                    $issues = array_merge($issues, $selection->issues);
                    foreach ($selection->selections as $source) {
                        foreach ($source->applied_months as $month) {
                            $sources[$month] = $source;
                        }
                    }
                }
                $groups['selected_sources'] = ['state' => 'EVALUATED', 'items' => []];
                foreach ($sources as $month => $source) {
                    $groups['selected_sources']['items'][] = ['month' => $month, 'evaluation_id' => $source->evaluation_id,
                        'predicate_code' => $source->predicate_code, 'history_id' => $source->history_id, 'history_proof_method' => $source->history_proof_method,
                        'selection_reason' => $source->selection_reason];
                }
                $groups['position_assignments'] = ['state' => 'EVALUATED', 'items' => []];
                $children = [];
                foreach ($coverage->months as $month) {
                    $source = $sources[$month] ?? null;
                    $position = $byMonth[$month] ?? null;
                    $lineIssues = array_values(array_filter($issues, fn ($i) => ($i['month'] ?? null) === $month || in_array($month, $i['months'] ?? [], true)));
                    $regime = $position === null ? null : ConversionRegimeEvidence::from($case->person_id, $position, $histories[$position->history_id] ?? []);
                    if ($position !== null) {
                        $groups['position_assignments']['items'][] = ['month' => $month, 'history_id' => $position->history_id,
                            'coverage_history_ids' => $position->coverage_history_ids, 'functional_level_id' => $position->functional_level_id,
                            'selection_reason' => $position->selection_reason, 'regime_evidence' => get_object_vars($regime)];
                    }
                    $predicate = $coefficient = null;
                    if ($regime?->issue_code !== null) {
                        $lineIssues[] = ['issue_code' => $regime->issue_code, 'month' => $month, 'history_id' => $position->history_id];
                    } elseif ($position !== null && $source !== null && $lineIssues === []) {
                        if ($position->functional_level_id === null) {
                            $lineIssues[] = ['issue_code' => 'MISSING_FUNCTIONAL_LEVEL', 'month' => $month];
                        } else {
                            foreach (['predicate', 'coefficient'] as $kind) {
                                try {
                                    if ($kind === 'predicate') {
                                        $predicate = app(RuleResolver::class)->resolvePredicate($rule->id, $source->predicate_code);
                                    } else {
                                        $coefficient = app(RuleResolver::class)->resolveCoefficient($rule->id, $position->functional_level_id);
                                    }
                                } catch (ConversionRuleException $e) {
                                    if (! in_array($e->issue_code, ['PREDICATE_RULE_NOT_FOUND', 'COEFFICIENT_RULE_NOT_FOUND'], true)) {
                                        throw $e;
                                    }
                                    $lineIssues[] = ['issue_code' => $e->issue_code, 'month' => $month];
                                }
                            }
                            $children[] = ['month' => $month, 'predicate_code' => $source->predicate_code, 'functional_level_id' => $position->functional_level_id,
                                'predicate' => $predicate === null ? null : get_object_vars($predicate), 'coefficient' => $coefficient === null ? null : get_object_vars($coefficient)];
                        }
                    }
                    foreach ($lineIssues as $issue) {
                        if (! in_array($issue, $issues, true)) {
                            $issues[] = $issue;
                        }
                    }
                    $lines[] = new ConversionPreviewLine([$month], $source, $position, $regime, $predicate, $coefficient, null, $lineIssues, null, null);
                }
                $groups['rule_set']['children'] = $children === [] ? $notEvaluated : ['state' => 'EVALUATED', 'lookups' => $children];
                $lines = $this->calculateLines($lines);
            }
        }
        $total = $coverage->is_complete && $issues === [] && $lines !== [] ? $calculator->total(array_map(fn ($line) => $line->exact, $lines)) : null;
        $graph = new ConversionDependencyGraph($groups);

        return new ConversionPreview($request, $case->lock_version, $coverage, $positions, $lines, $total, $issues, $graph,
            ConversionManifestBuilder::hash($graph->groups), app(ConversionManifestBuilder::class)->snapshot($graph, $lines, $total, $issues),
            $total === null ? null : $rounding->decimal($total), $total === null ? null : $rounding->display($total));
    }

    /** Contiguous compatible monthly results coalesce within a source performance year. */
    private function calculateLines(array $monthly): array
    {
        $groups = [];
        $lastKey = null;
        foreach ($monthly as $line) {
            $key = ConversionManifestBuilder::hash(['year' => substr($line->months[0], 0, 4), 'source' => $line->source?->evaluation_id,
                'proof' => $line->source?->history_id, 'position' => $line->position?->history_id,
                'predicate' => $line->predicate?->childRuleId, 'coefficient' => $line->coefficient?->childRuleId]);
            if ($line->issues === [] && $lastKey === $key) {
                $groups[array_key_last($groups)]['months'][] = $line->months[0];
            } else {
                $groups[] = ['line' => $line, 'months' => $line->months];
            }
            $lastKey = $line->issues === [] ? $key : null;
        }
        $result = [];
        foreach ($groups as ['line' => $line, 'months' => $months]) {
            $exact = $line->issues === [] && $line->predicate !== null && $line->coefficient !== null
                ? app(ConversionCalculator::class)->calculate(ConversionInput::from(count($months), $line->predicate->predicateFactor, $line->coefficient->annualCoefficient)) : null;
            $source = $line->source;
            if ($source !== null) {
                $source = new SkpSelectedSource($source->evaluation_id, $source->person_id, $source->performance_year, $source->evaluation_type,
                    $source->predicate_code, $source->source_identity_fingerprint, $source->content_fingerprint, $source->coverage_start, $source->coverage_end,
                    $source->source_reference, $source->source_date, $months, $source->selection_reason, $source->history_id, $source->history_proof_method);
            }
            $result[] = new ConversionPreviewLine($months, $source, $line->position, $line->regime, $line->predicate, $line->coefficient, $exact, $line->issues,
                $exact === null ? null : app(ConversionRoundingPolicy::class)->decimal($exact), $exact === null ? null : app(ConversionRoundingPolicy::class)->display($exact));
        }

        return $result;
    }

    private function validityDate(mixed $date): ?string
    {
        if ($date === null) {
            return null;
        }
        // Laravel date casts store midnight datetime text on SQLite DATE columns.
        if (is_string($date) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} 00:00:00\z/', $date)) {
            $date = substr($date, 0, 10);
        }
        if (! is_string($date) || ! preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $date, $p) || ! checkdate((int) $p[2], (int) $p[3], (int) $p[1])) {
            throw new ConversionRuleException('RULE_INTEGRITY_ERROR');
        }

        return $date;
    }
}
