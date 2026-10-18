<?php

namespace Tests\Feature\Pak;

use App\Models\ImportBatch;
use App\Models\Person;
use App\Models\PersonnelSnapshot;
use App\Models\ReportingPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class PakTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected Person $person;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->manager = User::factory()->create()->assignRole('Admin PAK');
        $this->person = Person::create(['person_key' => hash('sha256', 'test-person'), 'canonical_name' => 'Pegawai Sintetis', 'normalized_name' => 'PEGAWAI SINTETIS', 'nip' => '198001012005011001']);
    }

    protected function caseInput(): array
    {
        return ['person_id' => $this->person->id, 'purpose_type' => 'KP', 'period_start' => '2020-01-01', 'period_end' => '2026-10-01', 'employee_data_as_of' => '2026-10-01', 'pak_rule_set_id' => null];
    }

    protected function duk(string $status = 'published'): PersonnelSnapshot
    {
        $period = ReportingPeriod::create(['period_month' => '2026-09-01', 'status' => $status, 'revision' => 1]);
        $batch = ImportBatch::create(['reporting_period_id' => $period->id, 'uploaded_by' => $this->manager->id, 'source_type' => 'PERSONNEL_DUK', 'original_filename' => 'private.xlsx', 'sha256' => str_repeat('a', 64), 'path' => 'secret', 'status' => 'committed']);

        return PersonnelSnapshot::create(['reporting_period_id' => $period->id, 'person_id' => $this->person->id, 'import_batch_id' => $batch->id, 'name_at_period' => 'Nama DUK', 'nip_at_period' => $this->person->nip, 'employment_group' => 'ASN', 'employment_status' => 'PNS', 'rank_name' => 'Kandidat Pangkat', 'grade_code' => 'III/d', 'position_name' => 'Kandidat Jabatan', 'placement_current' => 'Tim saat ini', 'raw_payload' => ['secret' => 'hidden'], 'extra_data' => ['secret' => 'hidden']]);
    }
}
