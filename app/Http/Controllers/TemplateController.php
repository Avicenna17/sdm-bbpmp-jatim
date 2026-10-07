<?php

namespace App\Http\Controllers;

use App\Domain\Templates\TemplateService;
use App\Models\ImportTemplate;
use App\Models\ImportTemplateVersion;
use App\Models\ReportingPeriod;
use Illuminate\Support\Facades\Gate;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class TemplateController extends Controller
{
    public function __invoke(string $source)
    {
        abort_unless(Gate::allows('import.create') || Gate::allows('template.view'), 403);
        abort_unless(in_array($source, ['personnel', 'positions']), 404);
        $sourceType = $source === 'personnel' ? 'PERSONNEL_DUK' : 'POSITION_REQUIREMENT';
        $versionId = request()->validate(['version_id' => 'nullable|integer|exists:import_template_versions,id'])['version_id'] ?? null;
        $version = $versionId ? ImportTemplateVersion::findOrFail($versionId) : ImportTemplate::where('source', $sourceType)->first()?->activeVersion;
        if ($version) {
            abort_unless($version->template->source === $sourceType, 422);
            abort_unless($version->activated_at || Gate::allows('template.manage'), 403);

            return response()->streamDownload(function () use ($version) {
                $book = app(TemplateService::class)->workbook($version);
                (new Xlsx($book))->save('php://output');
                $book->disconnectWorksheets();
            }, $source === 'personnel' ? 'template_duk.xlsx' : 'template_peta_jabatan.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }
        $periodId = request()->validate(['period_id' => 'nullable|integer|exists:reporting_periods,id'])['period_id'] ?? null;
        $month = 'Bulan - tahun';
        $startYear = $source === 'personnel' && $periodId ? ReportingPeriod::findOrFail($periodId)->period_month->year : now()->year;
        $headers = $source === 'personnel' ? ['NO', 'NAMA', 'NIP/NIP3K', 'PANGKAT', 'GOL', 'JABATAN', 'KELAS JABATAN', 'PENEMPATAN', 'PENDIDIKAN', 'JENIS KELAMIN', 'STATUS'] : ['NO', 'UNIT ORGANISASI INDUK', 'SATUAN KERJA', 'NAMA JABATAN', 'JENIS JABATAN', 'KELAS JABATAN', 'USIA PENSIUN', 'JUMLAH PEMANGKU', 'JUMLAH KEBUTUHAN', 'JUMLAH KOSONG', 'STATUS', 'TOTAL PENSIUN 5 TAHUN'];
        if ($source === 'positions') {
            foreach (['Pensiun' => 7, 'Proyeksi Kebutuhan' => 5] as $prefix => $count) {
                for ($i = 0; $i < $count; $i++) {
                    $headers[] = $prefix.' '.($startYear + $i);
                }
            }
        }

        return response()->streamDownload(function () use ($source, $headers, $month) {
            $book = new Spreadsheet;
            $sheet = $book->getActiveSheet()->setTitle($source === 'personnel' ? 'DUK PEGAWAI' : 'PETA JABATAN');
            $sheet->fromArray($headers);
            $sheet->getStyle('A1:Z1')->getFont()->setBold(true);
            $sheet->freezePane('A2');
            if ($source === 'personnel') {
                $sheet->fromArray(['NO', 'NAMA', 'NIP/NIP3K', 'PANGKAT', 'GOL', 'JABATAN', 'KELAS JABATAN', 'PENEMPATAN', null, 'PENDIDIKAN', 'JENIS KELAMIN', 'STATUS'], null, 'A3');
                $sheet->fromArray(array_fill(0, 26, ''), null, 'A1');
                $sheet->mergeCells('A1:L1')->setCellValue('A1', 'DAFTAR PEGAWAI BALAI BESAR PENJAMINAN MUTU PENDIDIKAN PROVINSI JAWA TIMUR');
                $sheet->mergeCells('A2:L2')->setCellValue('A2', $month);
                $sheet->mergeCells('H3:I3');
                $sheet->setCellValue('H4', 'SK Tim Kerja (Baru)')->setCellValue('I4', 'SK Tim Kerja (Awal)');
                foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'J', 'K', 'L'] as $column) {
                    $sheet->mergeCells($column.'3:'.$column.'4');
                }
                $sheet->getStyle('A1:L4')->getFont()->setBold(true);
                $sheet->getStyle('A1:L4')->getAlignment()->setHorizontal('center')->setVertical('center')->setWrapText(true);
                $sheet->getStyle('A3:L4')->getFill()->setFillType('solid')->getStartColor()->setARGB('FFE2EFDA');
                foreach (['A' => 6, 'B' => 32, 'C' => 25, 'D' => 24, 'E' => 10, 'F' => 35, 'G' => 12, 'H' => 40, 'I' => 40, 'J' => 16, 'K' => 14, 'L' => 18] as $column => $width) {
                    $sheet->getColumnDimension($column)->setWidth($width);
                }
                foreach ([1 => 32, 2 => 24, 3 => 28, 4 => 32] as $row => $height) {
                    $sheet->getRowDimension($row)->setRowHeight($height);
                }
                $sheet->freezePane('D5');
                $sheet->getStyle('C5:C15000')->getNumberFormat()->setFormatCode('@');
                $sheet->getPageSetup()->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(1, 4);
            }
            if ($source === 'positions') {
                $last = Coordinate::stringFromColumnIndex(count($headers));
                $sheet->getStyle('A1:'.$last.'1')->getAlignment()->setWrapText(true)->setVertical('center');
                $sheet->getStyle('A1:'.$last.'1')->getFill()->setFillType('solid')->getStartColor()->setARGB('FFE2EFDA');
                $sheet->getRowDimension(1)->setRowHeight(48);
                foreach (range(1, count($headers)) as $column) {
                    $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setWidth($column === 1 ? 7 : (in_array($column, [2, 3, 4]) ? 40 : 18));
                }
                $sheet->freezePane('E2');
                $guide = $book->createSheet()->setTitle('Petunjuk');
                $guide->fromArray([
                    ['PETUNJUK PENGISIAN PETA JABATAN'],
                    ['Isi data pada lembar PETA JABATAN, mulai baris 2.'],
                    ['Kolom tahun adalah contoh berdasarkan tahun berjalan, bukan periode DUK.'],
                    ['Tahun boleh diganti; kolom tahun boleh ditambah atau dihapus sesuai data.'],
                    ['Gunakan judul Pensiun YYYY atau Proyeksi Kebutuhan YYYY, misalnya Pensiun 2033.'],
                    ['Satu jenis dan tahun hanya boleh muncul sekali. Isi 0 jika nilainya nol; kosongkan jika data tidak tersedia.'],
                    ['Total Pensiun 5 Tahun diisi sesuai dokumen sumber, bukan penjumlahan semua kolom pensiun.'],
                ]);
                $guide->getColumnDimension('A')->setWidth(105);
                $guide->getStyle('A1:A7')->getAlignment()->setWrapText(true);
                $guide->getStyle('A1')->getFont()->setBold(true);
                foreach (range(1, 7) as $row) {
                    $guide->getRowDimension($row)->setRowHeight(32);
                }
                $book->setActiveSheetIndex(0);
            }
            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, ($source === 'personnel' ? 'template_duk.xlsx' : 'template_peta_jabatan.xlsx'), ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
