<?php

namespace App\Domain\Pak\Services;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
use App\Domain\Pak\DTOs\EmployeeSnapshotData;
use App\Domain\Pak\DTOs\HistoricalOrganizationContext;
use App\Domain\Pak\Queries\EmployeeHistoryQuery;
use App\Domain\Pak\Queries\EmployeeReferenceQuery;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Arr;

final class EmployeeSnapshotService
{
    public function build(User $actor, Person $person, string $asOf, ?int $dukId = null, ?array $organization = null): EmployeeSnapshotData
    {
        Access::authorize($actor, 'pak.employee.view');
        $person->refresh();
        $duk = $dukId ? app(EmployeeReferenceQuery::class)->find($actor, $dukId, $person->id, $asOf)->toArray() : null;
        $profile = $person->employeeProfile()->whereNotNull('verified_at')->first();
        $rank = app(EmployeeHistoryQuery::class)->at($person->id, $asOf);
        $position = app(EmployeeHistoryQuery::class)->at($person->id, $asOf, true);
        $org = HistoricalOrganizationContext::from($organization, $asOf)->values;
        $fields = ['karpeg_number', 'birth_place', 'birth_date', 'gender'];
        $data = ['name' => $duk['name_at_period'] ?? $person->canonical_name, 'nip' => $duk ? $duk['nip_at_period'] : $person->nip];
        foreach ($fields as $field) {
            $data[$field] = $profile?->$field instanceof \DateTimeInterface ? $profile->$field->format('Y-m-d') : $profile?->$field;
        }
        $data += [
            'rank_name' => $rank?->rank_name, 'grade_code' => $rank?->grade_code, 'rank_tmt' => $rank?->tmt_start?->format('Y-m-d'),
            'position_name' => $position?->position_name, 'position_type' => $position?->position_type, 'position_tmt' => $position?->tmt_start?->format('Y-m-d'),
            'functional_position_id' => $position?->functional_position_id, 'functional_level_id' => $position?->functional_level_id,
            'functional_position_code' => $position?->functionalPosition?->code, 'functional_position_name' => $position?->functionalPosition?->name,
            'functional_level_code' => $position?->functionalLevel?->code, 'functional_level_name' => $position?->functionalLevel?->name,
            'functional_category_code' => $position?->functionalLevel?->category?->code, 'functional_category_name' => $position?->functionalLevel?->category?->name,
            'institution_name' => $org['institution_name'] ?? null, 'work_unit' => $org['work_unit'] ?? null,
        ];
        $missing = array_keys(array_filter(Arr::only($data, ['nip', 'rank_name', 'grade_code', 'rank_tmt', 'position_name', 'position_tmt', 'institution_name', 'work_unit']), fn ($v) => blank($v)));
        $data['snapshot_payload'] = [
            'as_of' => $asOf, 'identity_source' => $duk ? 'PUBLISHED_DUK_REFERENCE' : 'CANONICAL_CURRENT_NOT_HISTORICALLY_VERIFIED',
            'duk_reference' => $duk, 'duk_candidate_verified' => false, 'profile_id' => $profile?->id,
            'rank_history_id' => $rank?->id, 'position_history_id' => $position?->id,
            'organization' => $org, 'organization_verified_by' => $org ? $actor->id : null, 'missing_fields' => $missing,
        ];

        return new EmployeeSnapshotData($data);
    }
}
