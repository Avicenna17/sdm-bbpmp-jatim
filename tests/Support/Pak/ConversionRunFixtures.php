<?php

namespace Tests\Support\Pak;

use App\Models\PakCase;
use App\Models\PakRuleSet;
use App\Models\Person;
use App\Models\User;

trait ConversionRunFixtures
{
    protected function runData(array $changes = []): array
    {
        $user = User::factory()->create();
        $person = Person::factory()->create();
        $rule = PakRuleSet::create(['code' => 'FIXTURE', 'version' => (string) $user->id, 'name' => 'Synthetic', 'created_by' => $user->id, 'updated_by' => $user->id]);
        $case = PakCase::create(['person_id' => $person->id, 'pak_rule_set_id' => $rule->id, 'purpose_type' => 'KP', 'period_start' => '2023-01-01', 'period_end' => '2026-12-31', 'employee_data_as_of' => '2026-12-31', 'created_by' => $user->id, 'updated_by' => $user->id]);

        return array_replace(['pak_case_id' => $case->id, 'person_id' => $person->id, 'pak_rule_set_id' => $rule->id, 'revision_number' => 1, 'status' => 'PREVIEW', 'active_case_id' => $case->id,
            'requested_start' => '2023-11-01', 'requested_end' => '2026-10-31', 'case_lock_version' => 2, 'dependency_fingerprint' => str_repeat('a', 64), 'snapshot_fingerprint' => str_repeat('b', 64),
            'snapshot_schema_version' => 1, 'calculation_contract_version' => 1, 'rounding_profile' => 'PREVIEW_ONLY', 'rounding_version' => 1,
            'dependency_manifest' => array_fill_keys(['case_context', 'coverage', 'source_candidates', 'selected_sources', 'position_assignments', 'history_dependencies', 'rule_set', 'versions'], []),
            'original_issues' => [], 'calculation_trace' => ['fixture' => true], 'total_numerator' => '75', 'total_denominator' => '1', 'created_by' => $user->id], $changes);
    }

    protected function diagnosticData(int $run): array
    {
        return ['pak_conversion_run_id' => $run, 'line_number' => 1, 'period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'month_count' => 1,
            'original_issues' => [['issue_code' => 'MISSING_POSITION_HISTORY']], 'calculation_trace' => []];
    }
}
