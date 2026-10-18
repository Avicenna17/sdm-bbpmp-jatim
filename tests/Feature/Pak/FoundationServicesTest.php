<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\Queries\EmployeeReferenceQuery;
use App\Domain\Pak\Queries\PakCaseQuery;
use App\Domain\Pak\Services\EmployeeHistoryService;
use App\Domain\Pak\Services\EmployeeProfileService;
use App\Domain\Pak\Services\PakCaseService;
use App\Domain\Pak\Services\PakRuleSetService;
use App\Domain\Pak\Services\UserPersonLinkService;
use App\Domain\Users\AccountStatusService;
use App\Models\EmployeeRankHistory;
use App\Models\PakCase;
use App\Models\PakEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class FoundationServicesTest extends PakTestCase
{
    public function test_draft_partial_snapshot_does_not_promote_duk_candidates_or_current_organization(): void
    {
        $duk = $this->duk();
        app(EmployeeProfileService::class)->save($this->manager, $this->person, ['institution_name' => 'Current only', 'work_unit_name' => 'Not historic', 'source_reference' => 'Profil terkini']);
        $case = app(PakCaseService::class)->create($this->manager, $this->caseInput() + ['duk_snapshot_id' => $duk->id]);
        $snap = $case->employeeSnapshot;
        $this->assertSame('Nama DUK', $snap->name);
        foreach (['rank_name', 'rank_tmt', 'position_name', 'work_unit', 'institution_name'] as $field) {
            $this->assertNull($snap->$field);
        }
        $this->assertSame('KP', $case->purpose_type);
        $this->assertSame('DRAFT', $case->fresh()->status);
        $this->assertNull($case->pak_rule_set_id);
        $duk->delete();
        $this->person->update(['canonical_name' => 'Changed']);
        $this->assertSame('Nama DUK', $snap->fresh()->name);
        $this->assertNotEmpty($snap->snapshot_payload['missing_fields']);
    }

    public function test_reference_whitelist_and_no_sdm_permission(): void
    {
        $duk = $this->duk();
        $data = app(EmployeeReferenceQuery::class)->find($this->manager, $duk->id, $this->person->id, '2026-10-01')->toArray();
        $this->assertFalse($this->manager->can('period.view'));
        foreach (['raw_payload', 'extra_data', 'nip_note', 'path', 'uploaded_by', 'employment_status'] as $field) {
            $this->assertArrayNotHasKey($field, $data);
        }
        $this->assertSame('published', $duk->period->status);
        $this->assertSame(1, app(EmployeeReferenceQuery::class)->search($this->manager, $this->person->id, '2026-10-01')->total());
    }

    public function test_unpublished_reference_is_rejected_even_by_id(): void
    {
        $duk = $this->duk('ready');
        $this->expectException(ModelNotFoundException::class);
        app(EmployeeReferenceQuery::class)->find($this->manager, $duk->id, $this->person->id, '2026-10-01');
    }

    public function test_history_requires_real_tmt(): void
    {
        $this->expectException(ValidationException::class);
        app(EmployeeHistoryService::class)->record($this->manager, $this->person, ['rank_name' => 'Penata', 'grade_code' => 'III/c', 'source_ref' => 'SK']);
    }

    public function test_history_correction_preserves_old_version_and_prevents_overlap(): void
    {
        $service = app(EmployeeHistoryService::class);
        $data = ['rank_name' => 'Penata', 'grade_code' => 'III/c', 'source_ref' => 'SK-1', 'tmt_start' => '2020-01-01'];
        $old = $service->record($this->manager, $this->person, $data);
        $new = $service->record($this->manager, $this->person, array_replace($data, ['rank_name' => 'Koreksi']), false, $old->id, 'Koreksi dokumen');
        $this->assertNotNull($old->fresh()->superseded_at);
        $this->assertSame($old->id, $new->supersedes_id);
        $this->assertSame(2, EmployeeRankHistory::count());
        $this->expectException(ValidationException::class);
        $service->record($this->manager, $this->person, $data);
    }

    public function test_rule_nullable_dates_overlap_and_used_version_lock(): void
    {
        $service = app(PakRuleSetService::class);
        $rule = $service->save($this->manager, ['code' => 'REF', 'name' => 'Metadata', 'version' => '1', 'valid_from' => null, 'valid_until' => null]);
        $this->assertNull($rule->valid_from);
        $service->save($this->manager, ['code' => 'REF', 'name' => 'Metadata', 'version' => '2', 'valid_from' => null, 'valid_until' => '2030-01-01']);
        app(PakCaseService::class)->create($this->manager, array_replace($this->caseInput(), ['pak_rule_set_id' => $rule->id]));
        $this->expectException(ValidationException::class);
        $service->save($this->manager, ['code' => 'REF', 'name' => 'Changed', 'version' => '1'], $rule->id);
    }

    public function test_rule_rejects_reversed_dates(): void
    {
        $this->expectException(ValidationException::class);
        app(PakRuleSetService::class)->save($this->manager, ['code' => 'REF', 'name' => 'Meta', 'version' => '1', 'valid_from' => '2026-02-01', 'valid_until' => '2026-01-01']);
    }

    public function test_refresh_is_explicit_and_stale_write_is_rejected(): void
    {
        $service = app(PakCaseService::class);
        $input = $this->caseInput();
        $case = $service->create($this->manager, $input);
        $this->person->update(['canonical_name' => 'New name']);
        $this->assertSame('Pegawai Sintetis', $case->employeeSnapshot->name);
        $preview = $service->preview($this->manager, $input);
        $service->refresh($this->manager, $case, $input, 0, $preview->fingerprint(), 'Tinjauan ulang');
        $this->assertSame('New name', $case->employeeSnapshot->fresh()->name);
        $this->expectException(ValidationException::class);
        $service->refresh($this->manager, $case, $input, 0, $preview->fingerprint(), 'Stale');
    }

    public function test_case_and_snapshot_roll_back_when_audit_fails(): void
    {
        PakEvent::creating(fn () => throw new \RuntimeException('synthetic failure'));
        try {
            app(PakCaseService::class)->create($this->manager, $this->caseInput());
            $this->fail('Expected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('synthetic failure', $e->getMessage());
        } finally {
            PakEvent::flushEventListeners();
        }
        $this->assertSame(0, PakCase::count());
        $this->assertDatabaseCount('pak_case_employee_snapshots', 0);
    }

    public function test_mapping_ownership_and_account_retention(): void
    {
        $admin = User::factory()->create()->assignRole('Super Admin');
        $employee = User::factory()->create();
        app(UserPersonLinkService::class)->link($admin, $employee, $this->person->id, 'Verifikasi identitas');
        $case = app(PakCaseService::class)->create($this->manager, $this->caseInput());
        $this->assertSame([$case->id], app(PakCaseQuery::class)->forEmployee($employee)->pluck('id')->all());
        $this->assertSame(0, app(PakCaseQuery::class)->forEmployee($this->manager)->count());
        app(AccountStatusService::class)->change($admin, $employee, false, 'Nonaktif');
        $this->assertSame(0, app(PakCaseQuery::class)->forEmployee($employee)->count());
        $this->assertDatabaseHas('users', ['id' => $employee->id, 'person_id' => $this->person->id]);
        app(AccountStatusService::class)->change($admin, $employee, true, 'Aktif lagi');
        $this->assertTrue($employee->fresh()->is_active);
    }

    public function test_organization_needs_dated_evidence(): void
    {
        $input = $this->caseInput() + ['organization' => ['institution_name' => 'Instansi', 'work_unit' => 'Unit historis', 'source_reference' => 'SK-UNIT', 'source_date' => '2026-01-01', 'valid_from' => '2026-01-01', 'valid_until' => '2026-12-31']];
        $case = app(PakCaseService::class)->create($this->manager, $input);
        $this->assertSame('Unit historis', $case->employeeSnapshot->work_unit);
        $input['organization']['valid_until'] = null;
        $this->expectException(ValidationException::class);
        app(PakCaseService::class)->create($this->manager, $input);
    }

    public function test_purpose_does_not_accept_transfer(): void
    {
        $this->expectException(ValidationException::class);
        app(PakCaseService::class)->create($this->manager, array_replace($this->caseInput(), ['purpose_type' => 'TRANSFER']));
    }
}
