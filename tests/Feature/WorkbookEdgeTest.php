<?php

namespace Tests\Feature;

use App\Domain\Import\WorkbookParser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class WorkbookEdgeTest extends TestCase
{
    private function parse(Spreadsheet $book, string $source, bool $xls = false): array
    {
        $path = tempnam(sys_get_temp_dir(), 'sdm');
        try {
            ($xls ? new Xls($book) : new Xlsx($book))->save($path);

            return app(WorkbookParser::class)->parse($path, $source);
        } finally {
            $book->disconnectWorksheets();
            unlink($path);
        }
    }

    public function test_merged_year_headers_are_read_from_xls(): void
    {
        $book = new Spreadsheet;
        $s = $book->getActiveSheet();
        $s->fromArray([['NAMA JABATAN', 'JUMLAH PEMANGKU', 'JUMLAH KEBUTUHAN', 'Pensiun', null, 'Proyeksi Kebutuhan'], [null, null, null, 2033, 2034, 2035], ['Analis', 1, 3, 2, 1, 4]]);
        foreach (['A1:A2', 'B1:B2', 'C1:C2', 'D1:E1'] as $range) {
            $s->mergeCells($range);
        }
        $result = $this->parse($book, WorkbookParser::POSITION, true);
        $this->assertCount(1, $result['rows']);
        $this->assertCount(3, $result['rows'][0]['projections']);
        $this->assertSame(2035, $result['rows'][0]['projections'][2]['projection_year']);
        $this->assertEmpty($result['issues']);
    }

    public function test_supplemental_sheet_never_expands_population(): void
    {
        $book = new Spreadsheet;
        $s = $book->getActiveSheet()->setTitle('DUK PEGAWAI');
        $s->fromArray([['NAMA', 'STATUS', 'TUGAS'], ['Pegawai Utama', 'PPNPN', '']]);
        $supplement = $book->createSheet()->setTitle('P3K - PPNPN');
        $supplement->fromArray([['NAMA', 'STATUS', 'TUGAS'], ['Pegawai Utama', 'PPNPN', 'Tugas Tambahan'], ['Tidak Ada', 'PPPK', 'Tugas Lain']]);
        $result = $this->parse($book, WorkbookParser::PERSONNEL);
        $this->assertCount(1, $result['rows']);
        $this->assertNull($result['rows'][0]['assignment_detail']);
        $this->assertEmpty($result['issues']);
    }

    public function test_invalid_nip_and_gender_and_numeric_class_are_errors(): void
    {
        $book = new Spreadsheet;
        $s = $book->getActiveSheet()->setTitle('DUK PEGAWAI');
        $s->fromArray([['NAMA', 'NIP', 'STATUS', 'JENIS KELAMIN', 'KELAS JABATAN'], ['Pegawai', '1.98001E17', 'PNS', 'Q', '7.5']]);
        $result = $this->parse($book, WorkbookParser::PERSONNEL);
        $codes = array_column($result['issues'], 'code');
        foreach (['INVALID_NIP', 'INVALID_GENDER', 'INVALID_NUMBER'] as $code) {
            $this->assertContains($code, $codes);
        }
    }

    public function test_ambiguous_fallback_name_is_blocking(): void
    {
        $book = new Spreadsheet;
        $s = $book->getActiveSheet()->setTitle('DUK PEGAWAI');
        $s->fromArray([['NAMA', 'STATUS', 'PENDIDIKAN'], ['Pegawai Sama', 'PPNPN', 'S1'], ['  PEGAWAI   SAMA ', 'PPNPN', 'S2']]);
        $result = $this->parse($book, WorkbookParser::PERSONNEL);
        $this->assertSame('DUPLICATE_IDENTITY', $result['issues'][0]['code']);
    }

    public function test_ppnpn_job_description_is_preserved_without_warning_but_bad_numeric_nip_stays_blocking(): void
    {
        $book = new Spreadsheet;
        $s = $book->getActiveSheet()->setTitle('DUK PEGAWAI');
        $s->fromArray([
            ['NAMA', 'NIP', 'STATUS'],
            ['Pegawai Non ASN', 'Tenaga Kebersihan', 'PPNPN'],
            ['NIP Rusak', '12345', 'PPNPN'],
            ['ASN Salah', 'Tenaga Kebersihan', 'PNS'],
        ]);
        $result = $this->parse($book, WorkbookParser::PERSONNEL);
        $this->assertNull($result['rows'][0]['nip_at_period']);
        $this->assertSame('PPNPN', $result['rows'][0]['employment_group']);
        $this->assertSame('Tenaga Kebersihan', $result['rows'][0]['raw_payload'][1]);
        $this->assertSame('Tenaga Kebersihan', $result['rows'][0]['nip_note']);
        $this->assertNull(collect($result['issues'])->firstWhere('code', 'NON_NIP_TEXT_PPNPN'));
        $this->assertCount(2, collect($result['issues'])->where('code', 'INVALID_NIP'));
    }

    public function test_full_name_format_matching_preserves_identity_and_does_not_guess_typographical_errors(): void
    {
        $book = new Spreadsheet;
        $s = $book->getActiveSheet()->setTitle('DUK PEGAWAI');
        $s->fromArray([['NAMA', 'STATUS', 'TUGAS'], ['Nama Contoh, S.Pd.', 'PPPK', ''], ['Nama Lain', 'PPPK', '']]);
        $book->createSheet()->setTitle('P3K - PPNPN')->fromArray([
            ['NAMA', 'STATUS', 'TUGAS SEBAGAI'],
            ['Nama Contoh , S. Pd', 'PPPK Paruh Waktu', 'Tugas tambahan'],
            ['Nama Laen', 'PPPK', 'Jangan diterapkan'],
        ]);
        $result = $this->parse($book, WorkbookParser::PERSONNEL);
        $this->assertNull($result['rows'][0]['assignment_detail']);
        $this->assertSame('Nama Contoh, S.Pd.', $result['rows'][0]['name_at_period']);
        $this->assertSame('PPPK', $result['rows'][0]['employment_status']);
        $this->assertNull($result['rows'][1]['assignment_detail']);
        $this->assertEmpty(collect($result['issues'])->where('source_sheet', 'P3K - PPNPN'));
    }

    public function test_ambiguous_normalized_names_do_not_receive_enrichment(): void
    {
        $book = new Spreadsheet;
        $s = $book->getActiveSheet()->setTitle('DUK PEGAWAI');
        $s->fromArray([['NAMA', 'STATUS', 'TUGAS'], ['Nama Sama, S.Pd.', 'PPNPN', ''], ['Nama Sama S. Pd', 'PPNPN', '']]);
        $book->createSheet()->setTitle('P3K - PPNPN')->fromArray([['NAMA', 'STATUS', 'TUGAS'], ['Nama Sama, S.Pd.', 'PPNPN', 'Tidak boleh diterapkan']]);
        $result = $this->parse($book, WorkbookParser::PERSONNEL);
        $this->assertNull($result['rows'][0]['assignment_detail']);
        $this->assertNull($result['rows'][1]['assignment_detail']);
        $this->assertEmpty($result['issues']);
    }

    public function test_merged_placement_headers_keep_initial_team_and_program_separate(): void
    {
        $book = new Spreadsheet;
        $s = $book->getActiveSheet()->setTitle('DUK PEGAWAI');
        $s->fromArray([
            ['NAMA', 'STATUS', 'PENEMPATAN', null, null],
            [null, null, 'SK Tim Kerja (Baru)', 'SK Tim Kerja (Awal)', null],
            ['Contoh', 'PPNPN', 'Tim Baru', 'Tim Awal', 'Detail Program'],
        ]);
        foreach (['A1:A2', 'B1:B2', 'C1:E1', 'D2:E2'] as $range) {
            $s->mergeCells($range);
        }
        $result = $this->parse($book, WorkbookParser::PERSONNEL);
        $this->assertSame('Tim Baru', $result['rows'][0]['placement_current']);
        $this->assertSame('Tim Awal', $result['rows'][0]['placement_initial']);
        $this->assertSame('Detail Program', $result['rows'][0]['assignment_detail']);
    }

    public function test_zero_projection_value_is_not_converted_to_null(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['NAMA JABATAN', 'JUMLAH PEMANGKU', 'JUMLAH KEBUTUHAN', 'Pensiun 2033'], ['Analis', 0, 1, 0]], null, 'A1', true);
        $result = $this->parse($book, WorkbookParser::POSITION);
        $this->assertSame(0, $result['rows'][0]['incumbent_count']);
        $this->assertSame(0, $result['rows'][0]['projections'][0]['value']);
    }
}
