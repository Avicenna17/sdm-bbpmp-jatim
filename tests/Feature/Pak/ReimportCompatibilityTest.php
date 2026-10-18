<?php

namespace Tests\Feature\Pak;

use App\Domain\Import\ImportService;
use App\Domain\Import\WorkbookParser;
use App\Domain\Pak\Services\PakCaseService;
use App\Domain\ReportingPeriod\PublishPeriodService;
use App\Models\Person;
use App\Models\ReportingPeriod;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReimportCompatibilityTest extends PakTestCase
{
    public function test_real_duk_reimport_keeps_person_case_and_saved_snapshot(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create()->assignRole('Super Admin');
        $period = ReportingPeriod::create(['period_month' => '2026-09-01']);
        $import = app(ImportService::class);
        $upload = function (string $name): UploadedFile {
            $book = new Spreadsheet;
            $book->getActiveSheet()->setTitle('DUK PEGAWAI')->fromArray([
                ['NAMA', 'NIP/NIP3K', 'STATUS', 'JENIS KELAMIN', 'PENDIDIKAN', 'JABATAN', 'KELAS JABATAN'],
                [$name, '198001012005011099', 'PNS', 'L', 'S1', 'Analis', '7'],
            ]);
            $path = tempnam(sys_get_temp_dir(), 'pak-test');
            (new Xlsx($book))->save($path);
            $contents = file_get_contents($path);
            unlink($path);
            $book->disconnectWorksheets();

            return UploadedFile::fake()->createWithContent('synthetic.xlsx', $contents);
        };
        $batch = $import->preview($admin, $period, WorkbookParser::PERSONNEL, $upload('Sumber Awal'));
        $import->commit($admin, $batch);
        app(PublishPeriodService::class)->publish($admin, $period->fresh());
        $person = Person::where('nip', '198001012005011099')->firstOrFail();
        $snapshot = $period->personnel()->firstOrFail();
        $case = app(PakCaseService::class)->create($this->manager, array_replace($this->caseInput(), ['person_id' => $person->id, 'duk_snapshot_id' => $snapshot->id]));
        // ImportService requires period.revise when replacing a published DUK.
        $batch = $import->preview($admin, $period->fresh(), WorkbookParser::PERSONNEL, $upload('Sumber Revisi'));
        $import->commit($admin, $batch);
        $this->assertSame($person->id, $period->personnel()->firstOrFail()->person_id);
        $this->assertSame($person->id, $case->fresh()->person_id);
        $this->assertSame('Sumber Awal', $case->employeeSnapshot->fresh()->name);
        $this->assertSame('Sumber Revisi', $period->personnel()->firstOrFail()->name_at_period);
    }
}
