<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\Queries\EmployeeReferenceQuery;
use App\Domain\Pak\Services\EmployeeHistoryService;
use App\Domain\Pak\Services\FunctionalMasterService;
use App\Domain\Pak\Services\PakCaseService;
use App\Domain\Pak\Services\PakRuleSetService;
use App\Domain\Pak\Services\UserPersonLinkService;
use App\Domain\Users\AccountStatusService;
use App\Models\FunctionalLevel;
use App\Models\PakEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class FoundationIntegrityTest extends PakTestCase
{
    public function test_reference_rejects_cross_person_wrong_period_future_and_deleted_ids(): void
    {
        $duk = $this->duk();
        $query = app(EmployeeReferenceQuery::class);
        foreach ([
            [$duk->id, $this->person->id + 1, '2026-10-01', null],
            [$duk->id, $this->person->id, '2026-10-01', $duk->reporting_period_id + 1],
            [$duk->id, $this->person->id, '2026-08-01', null],
        ] as $args) {
            try {
                $query->find($this->manager, ...$args);
                $this->fail('Reference should be rejected');
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
        $case = app(PakCaseService::class)->create($this->manager, $this->caseInput() + ['duk_snapshot_id' => $duk->id]);
        $duk->delete();
        $this->assertSame('Nama DUK', $case->employeeSnapshot->name);
        $this->expectException(ModelNotFoundException::class);
        $query->find($this->manager, $duk->id, $this->person->id, '2026-10-01');
    }

    public function test_all_purposes_and_overlapping_metadata_versions_are_allowed(): void
    {
        foreach (['ROUTINE', 'KP', 'KJ', 'KP_KJ'] as $purpose) {
            $case = app(PakCaseService::class)->create($this->manager, array_replace($this->caseInput(), ['purpose_type' => $purpose]));
            $this->assertSame($purpose, $case->purpose_type);
        }
        foreach (['1', '2'] as $version) {
            $rule = app(PakRuleSetService::class)->save($this->manager, ['code' => 'OVERLAP', 'name' => 'Metadata', 'version' => $version, 'valid_from' => '2026-01-01', 'valid_until' => '2026-01-01']);
            $this->assertSame('2026-01-01', $rule->valid_from->format('Y-m-d'));
        }
    }

    public function test_inclusive_history_boundary_and_jf_mapping(): void
    {
        $master = app(FunctionalMasterService::class);
        $jf = $master->save($this->manager, 'position', ['code' => 'SYNTHETIC', 'name' => 'JF Sintetis', 'is_active' => true]);
        $level = FunctionalLevel::firstOrFail();
        $mapping = $master->save($this->manager, 'mapping', ['functional_position_id' => $jf->id, 'functional_level_id' => $level->id, 'is_active' => true]);
        $data = ['position_name' => 'JF Sintetis', 'position_type' => 'JF', 'functional_position_id' => $jf->id, 'functional_level_id' => $level->id, 'tmt_start' => '2020-01-01', 'tmt_end' => '2021-01-01', 'source_ref' => 'SK sintetis'];
        $history = app(EmployeeHistoryService::class)->record($this->manager, $this->person, $data, true);
        $this->assertNotNull($history->verified_at);
        try {
            app(EmployeeHistoryService::class)->record($this->manager, $this->person, array_replace($data, ['tmt_start' => '2021-01-01', 'tmt_end' => null]), true);
            $this->fail('Inclusive boundary must overlap');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('tmt_start', $e->errors());
        }
        $master->save($this->manager, 'mapping', ['functional_position_id' => $jf->id, 'functional_level_id' => $level->id, 'is_active' => false], $mapping->id);
        $this->assertSame($jf->id, $history->fresh()->functional_position_id);
        $this->expectException(ValidationException::class);
        app(EmployeeHistoryService::class)->record($this->manager, $this->person, array_replace($data, ['tmt_start' => '2021-01-02', 'tmt_end' => null]), true);
    }

    public function test_mapping_is_unique_and_unlink_does_not_move_case(): void
    {
        $admin = User::factory()->create()->assignRole('Super Admin');
        $employee = User::factory()->create();
        $other = User::factory()->create();
        $links = app(UserPersonLinkService::class);
        $links->link($admin, $employee, $this->person->id, 'Identitas');
        try {
            $links->link($admin, $other, $this->person->id, 'Duplikat');
            $this->fail('Duplicate link');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $case = app(PakCaseService::class)->create($this->manager, $this->caseInput());
        $links->link($admin, $employee, null, 'Unlink');
        $this->assertNull($employee->fresh()->person_id);
        $this->assertSame($this->person->id, $case->fresh()->person_id);
    }

    public function test_self_and_last_admin_guard_and_token_rotation(): void
    {
        $admin = User::factory()->create()->assignRole('Super Admin');
        $operator = User::factory()->create()->givePermissionTo('user.manage');
        $service = app(AccountStatusService::class);
        foreach ([[$admin, $admin], [$operator, $admin]] as [$actor, $target]) {
            try {
                $service->change($actor, $target, false, 'Tidak boleh');
                $this->fail('Protected admin');
            } catch (ValidationException) {
                $this->assertTrue($target->fresh()->is_active);
            }
        }
        $token = $this->manager->remember_token;
        $service->change($admin, $this->manager, false, 'Nonaktif');
        $this->assertNotSame($token, $this->manager->fresh()->remember_token);
        $this->seed();
        $this->assertFalse($this->manager->fresh()->is_active);
    }

    public function test_audit_is_immutable_and_actor_fk_restricts_hard_delete(): void
    {
        $case = app(PakCaseService::class)->create($this->manager, $this->caseInput());
        $event = PakEvent::where('pak_case_id', $case->id)->firstOrFail();
        try {
            $event->update(['event_code' => 'TAMPER']);
            $this->fail('Immutable');
        } catch (ValidationException) {
            $this->assertSame('PAK_CASE_CREATED', $event->fresh()->event_code);
        }
        $this->expectException(QueryException::class);
        $this->manager->delete();
    }

    public function test_changed_source_after_preview_is_rejected_without_partial_update(): void
    {
        $service = app(PakCaseService::class);
        $case = $service->create($this->manager, $this->caseInput());
        $preview = $service->preview($this->manager, $this->caseInput());
        $this->person->update(['canonical_name' => 'Changed after preview']);
        try {
            $service->refresh($this->manager, $case, $this->caseInput(), 0, $preview->fingerprint(), 'Refresh');
            $this->fail('Stale source');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('snapshot', $e->errors());
        }
        $this->assertSame(0, $case->fresh()->lock_version);
        $this->assertSame('Pegawai Sintetis', $case->employeeSnapshot->fresh()->name);
    }
}
