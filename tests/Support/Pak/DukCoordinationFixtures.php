<?php

namespace Tests\Support\Pak;

use App\Domain\Import\ImportService;
use App\Domain\Import\WorkbookParser;
use App\Models\ImportBatch;
use App\Models\Person;
use App\Models\ReportingPeriod;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

trait DukCoordinationFixtures
{
    private function preparedDuk(User $actor, array $rows, string $month = '2026-10-01'): ImportBatch
    {
        $period = ReportingPeriod::firstOrCreate(['period_month' => $month], ['status' => 'draft', 'revision' => 0]);
        $path = 'synthetic-'.bin2hex(random_bytes(5)).'.xlsx';
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('DUK PEGAWAI')->fromArray([
            ['NAMA', 'NIP/NIP3K', 'STATUS', 'JENIS KELAMIN', 'PENDIDIKAN', 'JABATAN', 'KELAS JABATAN'],
            ...array_map(fn ($row) => [$row['name_at_period'], $row['nip_at_period'], $row['nip_at_period'] ? 'PNS' : 'PPNPN', 'L', 'S1', 'Analis', '7'], $rows),
        ]);
        $file = Storage::disk('local')->path($path);
        (new Xlsx($book))->save($file);
        $book->disconnectWorksheets();
        $parsed = app(WorkbookParser::class)->parse($file, WorkbookParser::PERSONNEL);
        $this->assertEmpty(collect($parsed['issues'])->where('severity', 'ERROR')->all());
        $batch = ImportBatch::create(['reporting_period_id' => $period->id, 'uploaded_by' => $actor->id,
            'source_type' => 'PERSONNEL_DUK', 'original_filename' => 'synthetic.xlsx', 'disk' => 'local', 'path' => $path,
            'sha256' => hash_file('sha256', $file), 'status' => 'validated', 'error_rows' => 0,
            'base_revision' => $period->revision, 'summary' => ['source_policy' => 'duk-only-v1']]);
        Storage::disk('local')->put(app(ImportService::class)->stagingPath($batch), json_encode($parsed['rows'], JSON_THROW_ON_ERROR));

        return $batch;
    }

    private function dukPersonRow(string $key, ?string $nip, string $name): array
    {
        // Align synthetic existing identities with the real DUK parser's identity contract.
        $canonicalKey = hash('sha256', $nip ? 'nip:'.$nip : 'name:'.mb_strtoupper($name));
        Person::where('person_key', $key)->update(['person_key' => $canonicalKey]);

        return ['person_key' => $canonicalKey, 'nip_at_period' => $nip, 'name_at_period' => $name,
            'normalized_name' => mb_strtoupper($name), 'employment_group' => 'ASN', 'employment_status' => 'PNS'];
    }
}
