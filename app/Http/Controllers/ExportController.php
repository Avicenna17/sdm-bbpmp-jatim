<?php

namespace App\Http\Controllers;

use App\Domain\Dashboard\DashboardQuery;
use App\Models\PositionProjectionValue;
use App\Models\ReportingPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExportController extends Controller
{
    public function __invoke(Request $request, string $source, string $format, DashboardQuery $dashboard)
    {
        Gate::authorize($source === 'personnel' ? 'export.personnel' : 'export.position_requirement');
        $filters = $request->validate(['period_id' => 'required|integer|exists:reporting_periods,id', 'employment_group' => 'nullable|in:ASN,PPNPN,UNKNOWN'] + array_fill_keys(array_merge(array_keys(DashboardQuery::FILTERS), ['position_type', 'requirement_status']), 'nullable|string|max:255'));
        $period = ReportingPeriod::findOrFail($filters['period_id']);
        if ($source === 'personnel') {
            $query = $dashboard->personnel($period, $filters);
            $columns = ['name_at_period' => 'Nama', 'nip_at_period' => 'NIP/NIP3K', 'employment_group' => 'Grup', 'employment_status' => 'Status', 'gender' => 'Jenis Kelamin', 'education_level' => 'Pendidikan', 'rank_name' => 'Pangkat', 'grade_code' => 'Golongan', 'position_name' => 'Jabatan', 'position_class' => 'Kelas Jabatan', 'placement_current' => 'SK Tim Kerja (Baru)', 'placement_initial' => 'SK Tim Kerja (Awal)', 'assignment_detail' => 'Detail Penempatan Awal', 'nip_note' => 'Keterangan NIP'];
        } else {
            $query = $period->positions()->with('projections');
            foreach (['position_type', 'position_class', 'requirement_status'] as $field) {
                if (isset($filters[$field]) && $filters[$field] !== '') {
                    $query->where($field, $filters[$field]);
                }
            }
            $columns = ['source_sequence' => 'No', 'retirement_age' => 'Usia Pensiun', 'retirement_5y_total' => 'Total Pensiun 5 Tahun', 'parent_org' => 'Unit Organisasi Induk', 'work_unit' => 'Satuan Kerja', 'position_name' => 'Nama Jabatan', 'position_type' => 'Jenis Jabatan', 'position_class' => 'Kelas Jabatan', 'incumbent_count' => 'Pemangku', 'requirement_count' => 'Kebutuhan', 'vacancy_count' => 'Kosong', 'requirement_status' => 'Status'];
            $years = PositionProjectionValue::whereHas('snapshot', fn ($q) => $q->where('reporting_period_id', $period->id))->select('metric_type', 'projection_year')->distinct()->orderBy('metric_type')->orderBy('projection_year')->get();
            foreach ($years as $year) {
                $columns['projection:'.$year->metric_type.':'.$year->projection_year] = ($year->metric_type === 'RETIREMENT' ? 'Pensiun ' : 'Proyeksi Kebutuhan ').$year->projection_year;
            }
        }

        return response()->streamDownload(function () use ($query, $columns, $period, $format) {
            $headers = ['Periode', 'Diekspor pada', ...array_values($columns)];
            $book = $format === 'xlsx' ? new Spreadsheet : null;
            $sheet = $book?->getActiveSheet()->setTitle($period->period_month->format('Y-m'));
            $stream = $format === 'csv' ? fopen('php://output', 'w') : null;
            $write = function (array $values, int $row) use ($sheet, $stream) {
                if ($stream) {
                    fputcsv($stream, array_map(fn ($v) => preg_match('/^[=+@\-\t\r\n]/', (string) $v) ? "'".$v : $v, $values));
                } else {
                    foreach ($values as $c => $value) {
                        $sheet->setCellValueExplicit([$c + 1, $row], (string) $value, DataType::TYPE_STRING);
                    }
                }
            };
            if ($stream) {
                fwrite($stream, "\xEF\xBB\xBF");
            } $write($headers, 1);
            $row = 2;
            $time = now()->toIso8601String();
            foreach ($query->lazy(500) as $record) {
                $values = [$period->period_month->format('Y-m'), $time];
                foreach ($columns as $field => $label) {
                    if (str_starts_with($field, 'projection:')) {
                        [,$metric,$year] = explode(':', $field);
                        $values[] = $record->projections->first(fn ($p) => $p->metric_type === $metric && $p->projection_year == (int) $year)?->value;
                    } else {
                        $values[] = $record->$field === 'UNKNOWN' ? '-' : $record->$field;
                    }
                }
                $write($values, $row++);
            }
            if ($book) {
                $sheet->freezePane('A2');
                $sheet->getStyle('1:1')->getFont()->setBold(true);
                (new Xlsx($book))->save('php://output');
                $book->disconnectWorksheets();
            } else {
                fclose($stream);
            }
        }, $source.'-'.$period->period_month->format('Y-m').'.'.$format, ['Content-Type' => $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
