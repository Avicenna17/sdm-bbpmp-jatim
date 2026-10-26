<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\Enums\ConversionRunStatus;
use App\Domain\Pak\Rules\ConversionPersistenceValues;
use App\Models\AkCoefficientRule;
use App\Models\EmployeePositionHistory;
use App\Models\FunctionalCategory;
use App\Models\FunctionalLevel;
use App\Models\PakCase;
use App\Models\PakConversionPeriod;
use App\Models\PakConversionRun;
use App\Models\PerformanceEvaluation;
use App\Models\PerformancePredicateRule;
use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Pak\ConversionRunFixtures;
use Tests\TestCase;

class ConversionRunModelTest extends TestCase
{
    use ConversionRunFixtures, RefreshDatabase;

    public function test_partial_request_preserves_dates_without_count_periods_or_total(): void
    {
        $run = PakConversionRun::create($this->runData(['status' => 'REQUIRES_REVIEW', 'requested_start' => '2026-01-15', 'requested_end' => '2026-03-15', 'total_numerator' => null, 'total_denominator' => null, 'original_issues' => [['issue_code' => 'PARTIAL_MONTH_REQUIRES_REVIEW']]]))->fresh();
        $this->assertSame('2026-01-15', $run->requested_start->format('Y-m-d'));
        $this->assertSame('2026-03-15', $run->requested_end->format('Y-m-d'));
        $this->assertNull($run->total_numerator);
        $this->assertNull($run->total_denominator);
        $this->assertCount(0, $run->periods);
        $this->assertSame('PARTIAL_MONTH_REQUIRES_REVIEW', $run->original_issues[0]['issue_code']);
        $this->expectException(ValidationException::class);
        PakConversionPeriod::create($this->diagnosticData($run->id));
    }

    public function test_diagnostic_period_nullable_snapshots_and_relationships(): void
    {
        $run = PakConversionRun::create($this->runData(['status' => 'REQUIRES_REVIEW', 'total_numerator' => null, 'total_denominator' => null, 'original_issues' => [['issue_code' => 'MISSING_POSITION_HISTORY']]]));
        $line = PakConversionPeriod::create($this->diagnosticData($run->id))->fresh();
        foreach (['source_snapshot', 'position_snapshot', 'rule_snapshot', 'result_numerator', 'result_denominator', 'performance_evaluation_id', 'source_position_history_id', 'employee_position_history_id', 'predicate_factor', 'annual_coefficient'] as $field) {
            $this->assertNull($line->$field);
        }
        $this->assertTrue($line->run->is($run));
        $this->assertTrue($run->periods->sole()->is($line));
        $this->assertTrue($run->pakCase->conversionRuns->sole()->is($run));
        $this->assertSame($run->person_id, $run->person->id);
        $this->assertSame($run->pak_rule_set_id, $run->ruleSet->id);
        $this->assertSame($run->created_by, $run->creator->id);
    }

    public function test_large_exact_integer_strings_and_json_survive_roundtrip(): void
    {
        $large = str_repeat('9', 100);
        $data = $this->runData(['total_numerator' => $large, 'total_denominator' => '2']);
        $data['dependency_manifest']['source_candidates'] = ['2026' => ['annual_present' => false, 'candidate_ids' => []]];
        $data['dependency_manifest']['position_assignments'] = ['2026-01' => ['history_id' => 123, 'proof' => 'SYNTHETIC']];
        $data['dependency_manifest']['versions'] = ['calculation_contract_version' => ConversionPersistenceValues::CALCULATION_CONTRACT_VERSION];
        $run = PakConversionRun::create($data)->fresh();
        $this->assertSame($large, $run->total_numerator);
        $this->assertSame('2', $run->total_denominator);
        $this->assertSame($data['dependency_manifest'], $run->dependency_manifest);
        $this->assertSame($data['calculation_trace'], $run->calculation_trace);
        $this->assertSame(ConversionRunStatus::PREVIEW, $run->status);
    }

    #[DataProvider('badRationals')]
    public function test_invalid_exact_rational_rejected(mixed $n, mixed $d): void
    {
        $this->expectException(ValidationException::class);
        ConversionPersistenceValues::rational($n, $d, 'result');
    }

    public static function badRationals(): array
    {
        return [[null, '1'], ['1', null], ['1', '0'], ['1', '-2'], ['-1', '2'], ['1.0', '2'], ['1e3', '2'], [1, '2'], [1.0, '2'], ['01', '2'], ['2', '4'], ['0', '2']];
    }

    #[DataProvider('immutableWrites')]
    public function test_saved_records_cannot_be_rewritten_or_deleted(string $target, string $operation): void
    {
        $run = PakConversionRun::create($this->runData());
        $row = $target === 'run' ? $run : PakConversionPeriod::create($this->diagnosticData($run->id));
        $this->expectException(ValidationException::class);
        if ($operation === 'delete') {
            $row->delete();
        } else {
            $row->update($target === 'run' ? ['snapshot_fingerprint' => str_repeat('c', 64)] : ['calculation_trace' => ['changed' => true]]);
        }
    }

    public static function immutableWrites(): array
    {
        return [['run', 'update'], ['run', 'delete'], ['period', 'update'], ['period', 'delete']];
    }

    #[DataProvider('badRuns')]
    public function test_invalid_projection_status_range_and_pairs(array $changes): void
    {
        $data = $this->runData($changes);
        $this->expectException(ValidationException::class);
        PakConversionRun::create($data);
    }

    public static function badRuns(): array
    {
        return [[['active_case_id' => null]], [['status' => 'FINAL']], [['requested_end' => '2022-01-01']], [['total_denominator' => null]], [['dependency_fingerprint' => 'bad']], [['total_numerator' => null, 'total_denominator' => null]], [['revision_number' => 2]], [['dependency_manifest' => []]], [['requested_start' => '2026-02-29']]];
    }

    #[DataProvider('badPeriods')]
    public function test_period_structural_guards(array $changes): void
    {
        $run = PakConversionRun::create($this->runData());
        $this->expectException(ValidationException::class);
        PakConversionPeriod::create(array_replace($this->diagnosticData($run->id), $changes));
    }

    public static function badPeriods(): array
    {
        return [[['month_count' => 0]], [['month_count' => 2]], [['period_start' => '2026-01-15']], [['period_end' => '2026-01-30']], [['period_end' => '2025-12-31']], [['line_number' => 0]], [['result_numerator' => '1']], [['result_numerator' => '1', 'result_denominator' => '1']], [['original_issues' => []]], [['predicate_factor' => 1.0]], [['annual_coefficient' => '1e2']]];
    }

    public function test_overlapping_periods_are_rejected(): void
    {
        $run = PakConversionRun::create($this->runData());
        PakConversionPeriod::create($this->diagnosticData($run->id));
        $this->expectException(ValidationException::class);
        PakConversionPeriod::create(array_replace($this->diagnosticData($run->id), ['line_number' => 2]));
    }

    public function test_cross_person_case_is_rejected(): void
    {
        $data = $this->runData();
        $data['person_id'] = Person::factory()->create()->id;
        $this->expectException(ValidationException::class);
        PakConversionRun::create($data);
    }

    public function test_predecessor_sequence_and_relationship_support_without_replacement_service(): void
    {
        $data = $this->runData(['status' => 'SUPERSEDED', 'active_case_id' => null]);
        $previous = PakConversionRun::create($data);
        $next = PakConversionRun::create(array_replace($data, ['status' => 'PREVIEW', 'active_case_id' => $previous->pak_case_id, 'revision_number' => 2, 'predecessor_run_id' => $previous->id, 'revision_reason' => 'Synthetic structural fixture']));
        $this->assertTrue($next->predecessor->is($previous));
        $this->assertTrue($previous->successor->is($next));
        $this->expectException(ValidationException::class);
        PakConversionRun::create(array_replace($data, ['revision_number' => 4, 'predecessor_run_id' => $next->id, 'revision_reason' => 'Invalid sequence']));
    }

    public function test_manifest_rejects_automatic_model_serialization(): void
    {
        $data = $this->runData();
        $data['dependency_manifest']['case_context'] = PakCase::findOrFail($data['pak_case_id']);
        $this->expectException(ValidationException::class);
        PakConversionRun::create($data);
    }

    public function test_foreign_rule_and_source_ownership_are_rejected(): void
    {
        $run = PakConversionRun::create($this->runData());
        $other = PakConversionRun::create($this->runData());
        $predicate = PerformancePredicateRule::create(['pak_rule_set_id' => $other->pak_rule_set_id, 'predicate_code' => 'BAIK', 'predicate_name' => 'Baik', 'predicate_factor' => '1']);
        $history = EmployeePositionHistory::create(['person_id' => $other->person_id, 'position_name' => 'Synthetic', 'position_type' => 'JF', 'tmt_start' => '2023-01-01', 'source' => 'FIXTURE', 'source_ref' => 'Synthetic', 'verified_by' => $other->created_by, 'created_by' => $other->created_by, 'verified_at' => now()]);
        foreach (['performance_predicate_rule_id' => $predicate->id, 'source_position_history_id' => $history->id, 'employee_position_history_id' => $history->id] as $field => $id) {
            try {
                PakConversionPeriod::create(array_replace($this->diagnosticData($run->id), [$field => $id]));
                $this->fail('Cross-owner dependency accepted.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }
        $this->assertDatabaseCount('pak_conversion_periods', 0);
    }

    private function calculatedData(bool $explicitHistory = false): array
    {
        $run = PakConversionRun::create($this->runData());
        $category = FunctionalCategory::create(['code' => 'CONSISTENCY', 'name' => 'Synthetic']);
        $level = FunctionalLevel::create(['functional_category_id' => $category->id, 'code' => 'CONSISTENCY', 'name' => 'Synthetic', 'is_active' => false]);
        $predicate = PerformancePredicateRule::create(['pak_rule_set_id' => $run->pak_rule_set_id, 'predicate_code' => 'BAIK', 'predicate_name' => 'Baik', 'predicate_factor' => '1']);
        $coefficient = AkCoefficientRule::create(['pak_rule_set_id' => $run->pak_rule_set_id, 'functional_level_id' => $level->id, 'annual_coefficient' => '25']);
        $historyData = ['person_id' => $run->person_id, 'position_name' => 'Synthetic', 'position_type' => 'JF', 'functional_level_id' => $level->id, 'tmt_start' => '2023-01-01', 'source' => 'FIXTURE', 'source_ref' => 'Synthetic', 'verified_by' => $run->created_by, 'created_by' => $run->created_by, 'verified_at' => now()];
        $proof = EmployeePositionHistory::create($historyData);
        $position = EmployeePositionHistory::create(array_replace($historyData, ['tmt_start' => '2023-11-01']));
        // Q3 formal coverage intentionally differs from the applied January–October months.
        $evaluation = PerformanceEvaluation::create(['person_id' => $run->person_id, 'created_by' => $run->created_by, 'performance_year' => 2026, 'evaluation_type' => 'Q3', 'source_kind' => 'OFFICIAL', 'source_reference' => 'Synthetic', 'predicate_code' => 'BAIK', 'predicate_label_original' => 'Baik', 'coverage_start' => '2026-07-01', 'coverage_end' => '2026-09-30', 'source_identity_fingerprint' => str_repeat('c', 64), 'content_fingerprint' => str_repeat('d', 64), 'current_source_identity_fingerprint' => str_repeat('c', 64), 'employee_position_history_id' => $explicitHistory ? $proof->id : null]);

        return ['pak_conversion_run_id' => $run->id, 'line_number' => 1, 'period_start' => '2026-01-01', 'period_end' => '2026-10-31', 'month_count' => 10, 'performance_evaluation_id' => $evaluation->id, 'source_position_history_id' => $proof->id, 'employee_position_history_id' => $position->id, 'functional_level_id' => $level->id, 'performance_predicate_rule_id' => $predicate->id, 'ak_coefficient_rule_id' => $coefficient->id, 'predicate_factor' => '01.000', 'annual_coefficient' => '025.00', 'result_numerator' => '125', 'result_denominator' => '6', 'source_snapshot' => ['reference' => 'Synthetic'], 'position_snapshot' => ['history_id' => $position->id], 'rule_snapshot' => ['factor' => '1', 'coefficient' => '25'], 'original_issues' => [], 'calculation_trace' => []];
    }

    #[DataProvider('proofModes')]
    public function test_normalized_operands_and_distinct_source_proof_are_accepted(bool $explicit, bool $supplyProof): void
    {
        $data = $this->calculatedData($explicit);
        if (! $supplyProof) {
            $data['source_position_history_id'] = null;
        }
        $line = PakConversionPeriod::create($data)->fresh();
        $this->assertSame('1.0000', $line->predicate_factor);
        $this->assertSame('25.000000', $line->annual_coefficient);
        $this->assertFalse($line->functionalLevel->is_active);
        $this->assertNotSame($line->source_position_history_id, $line->employee_position_history_id);
        $this->assertSame($data['source_position_history_id'], $line->source_position_history_id);
    }

    public static function proofModes(): array
    {
        return [[true, true], [false, true], [false, false]];
    }

    #[DataProvider('dependencyMismatches')]
    public function test_contradictory_calculated_dependencies_are_rejected(string $scenario, string $field): void
    {
        $data = $this->calculatedData(true);
        if ($scenario === 'year') {
            $data = array_replace($data, ['period_start' => '2025-01-01', 'period_end' => '2025-10-31']);
        } elseif ($scenario === 'cross_year') {
            $data = array_replace($data, ['period_start' => '2025-12-01', 'month_count' => 11]);
        } elseif ($scenario === 'predicate_code') {
            $predicate = PerformancePredicateRule::findOrFail($data['performance_predicate_rule_id']);
            $predicate->update(['predicate_code' => 'baik']);
        } elseif ($scenario === 'factor') {
            $data['predicate_factor'] = '0.75';
        } elseif ($scenario === 'coefficient') {
            $data['annual_coefficient'] = '12.5';
        } elseif ($scenario === 'source_proof') {
            $data['source_position_history_id'] = $data['employee_position_history_id'];
        } else {
            $level = FunctionalLevel::findOrFail($data['functional_level_id']);
            $other = FunctionalLevel::create(['functional_category_id' => $level->functional_category_id, 'code' => 'OTHER', 'name' => 'Other']);
            $coefficient = AkCoefficientRule::findOrFail($data['ak_coefficient_rule_id']);
            $coefficient->update(['functional_level_id' => $other->id]);
            if ($scenario === 'position_level') {
                $data['functional_level_id'] = $other->id;
            }
        }
        try {
            PakConversionPeriod::create($data);
            $this->fail('Contradictory dependency accepted.');
        } catch (ValidationException $e) {
            $this->assertSame(['INVALID_CONVERSION_PERSISTENCE'], $e->errors()[$field]);
        }
        $this->assertDatabaseCount('pak_conversion_periods', 0);
    }

    public static function dependencyMismatches(): array
    {
        return [['year', 'performance_evaluation_id'], ['cross_year', 'performance_evaluation_id'], ['predicate_code', 'performance_predicate_rule_id'], ['factor', 'predicate_factor'], ['coefficient', 'annual_coefficient'], ['position_level', 'functional_level_id'], ['coefficient_level', 'functional_level_id'], ['source_proof', 'source_position_history_id']];
    }

    public function test_canonical_zero_and_supported_versions_persist(): void
    {
        $run = PakConversionRun::create($this->runData(['total_numerator' => '0', 'total_denominator' => '1', 'snapshot_schema_version' => ConversionPersistenceValues::SNAPSHOT_SCHEMA_VERSION, 'calculation_contract_version' => ConversionPersistenceValues::CALCULATION_CONTRACT_VERSION]))->fresh();
        $this->assertSame('0', $run->total_numerator);
        $this->assertSame('1', $run->total_denominator);
        $this->assertSame(1, (int) $run->snapshot_schema_version);
        $this->assertSame(1, (int) $run->calculation_contract_version);
    }

    #[DataProvider('unsupportedVersions')]
    public function test_unsupported_versions_rejected(string $field): void
    {
        $this->expectException(ValidationException::class);
        PakConversionRun::create($this->runData([$field => 2]));
    }

    public static function unsupportedVersions(): array
    {
        return [['snapshot_schema_version'], ['calculation_contract_version']];
    }

    #[DataProvider('invalidJson')]
    public function test_json_encoding_failure_uses_persistence_validation(array $payload): void
    {
        try {
            ConversionPersistenceValues::json($payload, 'calculation_trace');
            $this->fail('Invalid JSON accepted.');
        } catch (ValidationException $e) {
            $this->assertSame(['calculation_trace' => ['INVALID_CONVERSION_PERSISTENCE']], $e->errors());
        }
    }

    public static function invalidJson(): array
    {
        return [[['value' => "\xB1"]], [['value' => INF]], [['value' => NAN]]];
    }

    public function test_golden_exact_lines_distinct_history_proof_and_immutable_snapshots(): void
    {
        $run = PakConversionRun::create($this->runData());
        $category = FunctionalCategory::create(['code' => 'FIXTURE', 'name' => 'Synthetic']);
        $level = FunctionalLevel::create(['functional_category_id' => $category->id, 'code' => 'FIXTURE', 'name' => 'Synthetic']);
        $predicate = PerformancePredicateRule::create(['pak_rule_set_id' => $run->pak_rule_set_id, 'predicate_code' => 'BAIK', 'predicate_name' => 'Baik', 'predicate_factor' => '1']);
        $coefficient = AkCoefficientRule::create(['pak_rule_set_id' => $run->pak_rule_set_id, 'functional_level_id' => $level->id, 'annual_coefficient' => '25']);
        $historyData = ['person_id' => $run->person_id, 'position_name' => 'Synthetic', 'position_type' => 'JF', 'functional_level_id' => $level->id, 'tmt_start' => '2023-01-01', 'source' => 'FIXTURE', 'source_ref' => 'Synthetic', 'verified_by' => $run->created_by, 'created_by' => $run->created_by, 'verified_at' => now()];
        $proof = EmployeePositionHistory::create($historyData);
        $position = EmployeePositionHistory::create(array_replace($historyData, ['tmt_start' => '2023-11-01']));
        $fixtures = [['2023-11-01', '2023-12-31', 2, '25', '6'], ['2024-01-01', '2024-12-31', 12, '25', '1'], ['2025-01-01', '2025-12-31', 12, '25', '1'], ['2026-01-01', '2026-10-31', 10, '125', '6']];
        foreach ($fixtures as $index => [$start, $end, $months, $n, $d]) {
            $year = (int) substr($start, 0, 4);
            $fingerprint = hash('sha256', 'synthetic-'.$year);
            $evaluation = PerformanceEvaluation::create(['person_id' => $run->person_id, 'created_by' => $run->created_by, 'performance_year' => $year, 'evaluation_type' => 'ANNUAL', 'source_kind' => 'OFFICIAL', 'source_reference' => 'Synthetic-'.$year, 'predicate_code' => 'BAIK', 'predicate_label_original' => 'Baik', 'coverage_start' => $year.'-01-01', 'coverage_end' => $year.'-12-31', 'source_identity_fingerprint' => $fingerprint, 'content_fingerprint' => str_repeat('b', 64), 'current_source_identity_fingerprint' => $fingerprint]);
            // Structural numeric fixture only: source selection/suitability is not orchestrated here.
            $line = PakConversionPeriod::create(['pak_conversion_run_id' => $run->id, 'line_number' => $index + 1, 'period_start' => $start, 'period_end' => $end, 'month_count' => $months, 'performance_evaluation_id' => $evaluation->id, 'source_position_history_id' => $proof->id, 'employee_position_history_id' => $position->id, 'functional_level_id' => $level->id, 'performance_predicate_rule_id' => $predicate->id, 'ak_coefficient_rule_id' => $coefficient->id, 'predicate_factor' => '1', 'annual_coefficient' => '25', 'result_numerator' => $n, 'result_denominator' => $d, 'source_snapshot' => ['reference' => 'Synthetic'], 'position_snapshot' => ['tmt_start' => '2023-11-01'], 'rule_snapshot' => ['factor' => '1', 'coefficient' => '25'], 'original_issues' => [], 'calculation_trace' => ['fixture' => true]])->fresh();
            $this->assertSame($n, $line->result_numerator);
            $this->assertSame($d, $line->result_denominator);
            $this->assertSame('1.0000', $line->predicate_factor);
            $this->assertSame('25.000000', $line->annual_coefficient);
            $this->assertTrue($line->sourcePositionHistory->is($proof));
            $this->assertTrue($line->positionHistory->is($position));
            $this->assertTrue($line->evaluation->is($evaluation));
            $this->assertSame($year, (int) $line->evaluation->performance_year);
            $this->assertTrue($line->predicateRule->is($predicate));
            $this->assertTrue($line->coefficientRule->is($coefficient));
            $this->assertTrue($line->functionalLevel->is($level));
        }
        $before = $run->periods()->get()->map->getAttributes()->all();
        $position->update(['superseded_at' => now()]);
        $evaluation->update(['status' => 'SUPERSEDED', 'current_source_identity_fingerprint' => null, 'superseded_by_user_id' => $run->created_by, 'superseded_at' => now()]);
        $this->assertSame($before, $run->periods()->get()->map->getAttributes()->all());
        $this->assertSame('75', $run->fresh()->total_numerator);
        $this->assertSame('1', $run->fresh()->total_denominator);
    }
}
