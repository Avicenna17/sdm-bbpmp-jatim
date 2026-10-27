<?php

namespace App\Domain\Pak\Services;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
use App\Domain\Pak\DTOs\CreatePakCaseData;
use App\Domain\Pak\DTOs\EmployeeSnapshotData;
use App\Domain\Pak\Queries\EmployeeReferenceQuery;
use App\Models\PakCase;
use App\Models\PakRuleSet;
use App\Models\Person;
use App\Models\ReportingPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class PakCaseService
{
    public function preview(User $actor, array $input): EmployeeSnapshotData
    {
        Access::authorize($actor, 'pak.employee.view');
        abort_unless(Access::allows($actor, 'pak.case.create') || Access::allows($actor, 'pak.case.update'), 403);
        if (! empty($input['pak_rule_set_id'])) {
            Access::authorize($actor, 'pak.rule-set.view');
        }
        $data = CreatePakCaseData::from($input)->values;

        return app(EmployeeSnapshotService::class)->build($actor, Person::findOrFail($data['person_id']), $data['employee_data_as_of'], $input['duk_snapshot_id'] ?? null, $input['organization'] ?? null);
    }

    private function capture(User $actor, array $data, array $input): EmployeeSnapshotData
    {
        // Same order as DUK import: reporting period before person.
        if (! empty($input['duk_snapshot_id'])) {
            $ref = app(EmployeeReferenceQuery::class)->find($actor, (int) $input['duk_snapshot_id'], (int) $data['person_id'], $data['employee_data_as_of'])->toArray();
            ReportingPeriod::lockForUpdate()->findOrFail($ref['reporting_period_id']);
        }
        $person = Person::lockForUpdate()->findOrFail($data['person_id']);
        if (! empty($data['pak_rule_set_id'])) {
            $rule = PakRuleSet::lockForUpdate()->findOrFail($data['pak_rule_set_id']);
            if ($rule->status !== 'DRAFT') {
                throw ValidationException::withMessages(['pak_rule_set_id' => 'Foundation hanya mendukung metadata draft.']);
            }
            // Held under the rule row lock and the case transaction; rollback includes this marker.
            if ($rule->first_used_at === null) {
                $rule->forceFill(['first_used_at' => now()])->save();
            }
        }

        return app(EmployeeSnapshotService::class)->build($actor, $person, $data['employee_data_as_of'], $input['duk_snapshot_id'] ?? null, $input['organization'] ?? null, current: true);
    }

    public function create(User $actor, array $input): PakCase
    {
        Access::authorize($actor, 'pak.case.create');
        if (! empty($input['pak_rule_set_id'])) {
            Access::authorize($actor, 'pak.rule-set.view');
        }
        $data = CreatePakCaseData::from($input)->values;

        return DB::transaction(function () use ($actor, $data, $input) {
            $snapshot = $this->capture($actor, $data, $input);
            $case = PakCase::create($data + ['created_by' => $actor->id, 'updated_by' => $actor->id, 'source_type' => 'ADMIN_MANUAL', 'status' => 'DRAFT']);
            $case->employeeSnapshot()->create($snapshot->values + ['captured_by' => $actor->id, 'captured_at' => now(), 'schema_version' => 1]);
            app(PakAuditService::class)->record($actor, $case, 'PAK_CASE_CREATED', ['after' => $data], $case->id);

            return $case;
        });
    }

    public function refresh(User $actor, PakCase $case, array $input, int $expectedVersion, string $previewHash, string $reason): PakCase
    {
        Access::authorize($actor, 'pak.case.update');
        Validator::make(['reason' => $reason], ['reason' => 'required|string|max:2000'])->validate();
        if (! empty($input['pak_rule_set_id'])) {
            Access::authorize($actor, 'pak.rule-set.view');
        }
        $data = CreatePakCaseData::from($input)->values;

        return DB::transaction(function () use ($actor, $case, $data, $input, $expectedVersion, $previewHash, $reason) {
            $case = PakCase::lockForUpdate()->findOrFail($case->id);
            if ($case->status !== 'DRAFT' || $case->finalized_at || $case->lock_version !== $expectedVersion || (int) $data['person_id'] !== $case->person_id) {
                throw ValidationException::withMessages(['case' => 'Draft berubah/terkunci atau person berbeda. Muat ulang.']);
            }
            $snapshot = $this->capture($actor, $data, $input);
            if (! hash_equals($snapshot->fingerprint(), $previewHash)) {
                throw ValidationException::withMessages(['snapshot' => 'Sumber berubah sejak preview. Tinjau ulang.']);
            }
            $before = ['case' => $case->attributesToArray(), 'snapshot' => $case->employeeSnapshot->toArray()];
            $case->update($data + ['updated_by' => $actor->id, 'lock_version' => $expectedVersion + 1]);
            $case->employeeSnapshot->update($snapshot->values + ['captured_by' => $actor->id, 'captured_at' => now()]);
            app(PakAuditService::class)->record($actor, $case, 'PAK_SNAPSHOT_REFRESHED', ['before' => $before, 'after' => ['case' => $case->attributesToArray(), 'snapshot' => $case->employeeSnapshot->fresh()->toArray()], 'reason' => $reason], $case->id);

            return $case;
        });
    }
}
