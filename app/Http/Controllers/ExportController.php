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
        $reason = app(\App\Domain\Export\ExportEligibility::class)->reason($period, $source, $filters);
        abort_if($reason !== null, 422, $reason ?? 'Ekspor tidak tersedia.');
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

        foreach ((clone $query)->lazy(500) as $record) {
            foreach ($record->extra_data ?? [] as $key => $item) {
                $columns[$key] = 'Tambahan: '.$item['label'].' ['.substr($key, 6, 8).']';
            }
        }

        $sourceType = $source === 'personnel' ? 'PERSONNEL_DUK' : 'POSITION_REQUIREMENT';
        $batch = $period->batches()->where('source_type', $sourceType)->where('status', 'committed')->latest('committed_at')->latest('id')->first();
        $version = $batch?->template_version_id ? \App\Models\ImportTemplateVersion::find($batch->template_version_id) : null;
        $definition = $version?->definition ?? app(\App\Domain\Templates\TemplateService::class)->defaults($sourceType);
        $title = $definition['title'];
        $description = trim($definition['subtitle'] ?? '');
        if ($description === '' || preg_match('/^bulan\s*[-–]\s*tahun$/iu', $description)) {
            $description = $period->label;
        }
        $safePart = static function (string $value): string {
            $value = preg_replace('/[<>:"\\\\\/|?*\x00-\x1F\x7F]/u', '-', $value);
            return mb_substr(trim(preg_replace('/\s+/u', ' ', $value), " ."), 0, 120);
        };
        $filename = $safePart($title).'_'.$safePart($description).'.'.$format;

        return response()->streamDownload(function () use ($query, $columns, $period, $format, $title, $description) {
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
            }
            $write([$title], 1);
            $write([$description], 2);
            $write($headers, 3);
            $row = 4;
            $time = now()->toIso8601String();
            foreach ($query->lazy(500) as $record) {
                $values = [$period->period_month->format('Y-m'), $time];
                foreach ($columns as $field => $label) {
                    if (str_starts_with($field, 'extra:')) {
                        $values[] = $record->extra_data[$field]['value'] ?? null;
                    } elseif (str_starts_with($field, 'projection:')) {
                        [,$metric,$year] = explode(':', $field);
                        $values[] = $record->projections->first(fn ($p) => $p->metric_type === $metric && $p->projection_year == (int) $year)?->value;
                    } else {
                        $values[] = $record->$field === 'UNKNOWN' ? '-' : $record->$field;
                    }
                }
                $write($values, $row++);
            }
            if ($book) {
                $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
                $sheet->mergeCells('A1:'.$last.'1');
                $sheet->mergeCells('A2:'.$last.'2');
                $sheet->getStyle('A1:'.$last.'2')->getAlignment()->setHorizontal('center');
                $sheet->freezePane('A4');
                $sheet->getStyle('1:3')->getFont()->setBold(true);
                (new Xlsx($book))->save('php://output');
                $book->disconnectWorksheets();
            } else {
                fclose($stream);
            }
        }, $filename, ['Content-Type' => $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
