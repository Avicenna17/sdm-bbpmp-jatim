<?php

namespace App\Domain\Pak\Services;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
use App\Domain\Pak\DTOs\EmployeeSnapshotData;
use App\Domain\Pak\DTOs\HistoricalOrganizationContext;
use App\Domain\Pak\Queries\EmployeeHistoryQuery;
use App\Domain\Pak\Queries\EmployeeReferenceQuery;
use App\Models\FunctionalCategory;
use App\Models\FunctionalLevel;
use App\Models\FunctionalPosition;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EmployeeSnapshotService
{
    public function build(User $actor, Person $person, string $asOf, ?int $dukId = null, ?array $organization = null, bool $current = false): EmployeeSnapshotData
    {
        Access::authorize($actor, 'pak.employee.view');
        if ($current && DB::transactionLevel() === 0) {
            throw new \LogicException('Authoritative capture requires coordinated transaction.');
        }
        $person = $current ? Person::sharedLock()->findOrFail($person->id) : $person->fresh();
        $duk = $dukId ? app(EmployeeReferenceQuery::class)->find($actor, $dukId, $person->id, $asOf, current: $current)->toArray() : null;
        $profile = $person->employeeProfile()->whereNotNull('verified_at')->when($current, fn ($q) => $q->sharedLock())->first();
        // Discovery is not authoritative: master rows must precede locking history reads.
        $position = app(EmployeeHistoryQuery::class)->at($person->id, $asOf, true);
        if ($current) {
            $masters = app(SharedMasterLocks::class)->acquire(positionIds: [$position?->functional_position_id], levelIds: [$position?->functional_level_id]);
            $position = app(EmployeeHistoryQuery::class)->at($person->id, $asOf, true, true);
            foreach (['functional_position_id' => FunctionalPosition::class, 'functional_level_id' => FunctionalLevel::class] as $field => $class) {
                if ($position?->$field !== null && ! isset($masters[$class][$position->$field])) {
                    throw ValidationException::withMessages(['snapshot' => 'Sumber berubah sejak discovery. Tinjau ulang.']);
                }
            }
            if ($position) {
                $level = $masters[FunctionalLevel::class][$position->functional_level_id] ?? null;
                $level?->setRelation('category', $masters[FunctionalCategory::class][$level->functional_category_id] ?? null);
                $position->setRelation('functionalPosition', $masters[FunctionalPosition::class][$position->functional_position_id] ?? null);
                $position->setRelation('functionalLevel', $level);
            }
        }
        $rank = app(EmployeeHistoryQuery::class)->at($person->id, $asOf, false, $current);
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
