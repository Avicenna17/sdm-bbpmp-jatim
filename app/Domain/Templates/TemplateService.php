<?php

namespace App\Domain\Templates;

use App\Domain\Import\WorkbookParser;
use App\Models\ImportTemplate;
use App\Models\ImportTemplateVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class TemplateService
{
    public const SOURCES = ['PERSONNEL_DUK' => 'DUK Pegawai', 'POSITION_REQUIREMENT' => 'Peta Jabatan / Kebutuhan'];

    public function fields(string $source): array
    {
        return $source === 'PERSONNEL_DUK'
            ? ['source_sequence' => 'NO', 'name_at_period' => 'NAMA', 'nip_at_period' => 'NIP/NIP3K', 'rank_name' => 'PANGKAT', 'grade_code' => 'GOL', 'position_name' => 'JABATAN', 'position_class' => 'KELAS JABATAN', 'placement_current' => 'SK Tim Kerja (Baru)', 'placement_initial' => 'SK Tim Kerja (Awal)', 'education_raw' => 'PENDIDIKAN', 'gender' => 'JENIS KELAMIN', 'employment_status' => 'STATUS', 'assignment_detail' => 'DETAIL PENEMPATAN']
            : ['source_sequence' => 'NO', 'parent_org' => 'UNIT ORGANISASI INDUK', 'work_unit' => 'SATUAN KERJA', 'position_name' => 'NAMA JABATAN', 'position_type' => 'JENIS JABATAN', 'position_class' => 'KELAS JABATAN', 'retirement_age' => 'USIA PENSIUN', 'incumbent_count' => 'JUMLAH PEMANGKU', 'requirement_count' => 'JUMLAH KEBUTUHAN', 'vacancy_count' => 'JUMLAH KOSONG', 'requirement_status' => 'STATUS', 'retirement_5y_total' => 'TOTAL PENSIUN 5 TAHUN'];
    }

    public function required(string $source): array
    {
        return $source === 'PERSONNEL_DUK' ? ['name_at_period', 'employment_status'] : ['position_name', 'incumbent_count', 'requirement_count'];
    }

    public function withRequiredValues(string $source, array $definition): array
    {
        foreach ($definition['columns'] as &$column) {
            if (in_array($column['key'], $this->required($source))) {
                $column['required'] = true;
            }
        }

        return $definition;
    }

    public function defaults(string $source): array
    {
        $columns = [];
        foreach ($this->fields($source) as $key => $label) {
            if ($key === 'assignment_detail') {
                continue;
            }
            $columns[] = ['key' => $key, 'label' => $label, 'type' => 'text', 'required' => in_array($key, $this->required($source)), 'width' => 24, 'color' => '#E2EFDA', 'bold' => true, 'align' => 'center', 'choices' => ''];
        }
        if ($source === 'POSITION_REQUIREMENT') {
            foreach (['RETIREMENT' => 7, 'REQUIREMENT' => 5] as $metric => $count) {
                for ($i = 0; $i < $count; $i++) {
                    $columns[] = ['key' => 'projection:'.$metric.':'.(now()->year + $i), 'label' => ($metric === 'RETIREMENT' ? 'Pensiun ' : 'Proyeksi Kebutuhan ').(now()->year + $i), 'type' => 'number', 'required' => false, 'width' => 18, 'color' => '#E2EFDA', 'bold' => true, 'align' => 'center', 'choices' => ''];
                }
            }
        }

        return ['title' => $source === 'PERSONNEL_DUK' ? 'DAFTAR PEGAWAI BALAI BESAR PENJAMINAN MUTU PENDIDIKAN PROVINSI JAWA TIMUR' : 'PETA JABATAN DAN KEBUTUHAN', 'subtitle' => 'Bulan - tahun', 'columns' => $columns];
    }

    public function ensureTemplates(): void
    {
        foreach (self::SOURCES as $source => $name) {
            $template = ImportTemplate::firstOrCreate(['source' => $source], ['name' => $name]);
            DB::transaction(function () use ($template, $source) {
                $template = ImportTemplate::lockForUpdate()->findOrFail($template->id);
                if ($template->active_version_id) {
                    return;
                }
                // Reserve version 1 for the initial format without overwriting existing drafts.
                foreach ($template->versions()->orderByDesc('number')->get() as $existing) {
                    $existing->update(['number' => $existing->number + 1]);
                }
                $definition = $this->defaults($source);
                $definition['initial_template'] = true;
                $version = $template->versions()->create(['number' => 1, 'definition' => $definition, 'activated_at' => now()]);
                $template->update(['active_version_id' => $version->id]);
            });
        }
    }

    public function validate(string $source, array $definition): array
    {
        Validator::make($definition, [
            'title' => 'required|string|max:255', 'subtitle' => 'nullable|string|max:255', 'columns' => 'required|array|min:2|max:200',
            'columns.*.key' => 'required|string|max:100', 'columns.*.label' => 'required|string|max:100',
            'columns.*.type' => 'required|in:text,number,date,choice', 'columns.*.required' => 'required|boolean',
            'columns.*.width' => 'required|numeric|min:8|max:80', 'columns.*.color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'columns.*.bold' => 'required|boolean', 'columns.*.align' => 'required|in:left,center,right', 'columns.*.choices' => 'nullable|string|max:2000',
        ])->validate();
        $keys = [];
        $labels = [];
        foreach ($definition['columns'] as $c) {
            $key = $c['key'];
            $label = WorkbookParser::norm($c['label']);
            if (isset($keys[$key]) || isset($labels[$label])) {
                throw ValidationException::withMessages(['template' => 'Kegunaan atau nama kolom muncul dua kali. Gunakan satu kolom untuk setiap kegunaan.']);
            }
            $keys[$key] = true;
            $labels[$label] = true;
            $extra = preg_match('/^extra:[a-zA-Z0-9-]{8,64}$/', $key);
            $projection = $source === 'POSITION_REQUIREMENT' && preg_match('/^projection:(RETIREMENT|REQUIREMENT):(19|20|21)\d{2}$/', $key);
            if (! isset($this->fields($source)[$key]) && ! $extra && ! $projection) {
                throw ValidationException::withMessages(['template' => 'Kegunaan kolom tidak valid. Pilih dari daftar yang tersedia.']);
            }
            if ($extra && $c['type'] === 'choice' && ! trim($c['choices'] ?? '')) {
                throw ValidationException::withMessages(['template' => 'Isi daftar pilihan untuk kolom '.$c['label'].'.']);
            }
            foreach ($this->fields($source) as $standard => $standardLabel) {
                if ($standard !== $key && mb_strtoupper($standardLabel) === $label) {
                    throw ValidationException::withMessages(['template' => 'Nama '.$c['label'].' sudah digunakan untuk data standar lain.']);
                }
            }
        }
        if (array_diff($this->required($source), array_keys($keys))) {
            throw ValidationException::withMessages(['template' => 'Kolom wajib belum lengkap. Kembalikan kolom yang ditandai wajib sebelum mengaktifkan.']);
        }

        return $definition;
    }

    public function save(ImportTemplate $template, array $definition, ?int $draftId, int $userId): ImportTemplateVersion
    {
        Gate::authorize('template.manage');
        unset($definition['initial_template']);
        $definition = $this->withRequiredValues($template->source, $definition);
        $this->validate($template->source, $definition);

        return DB::transaction(function () use ($template, $definition, $draftId, $userId) {
            $template = ImportTemplate::lockForUpdate()->findOrFail($template->id);
            if ($draftId) {
                $draft = $template->versions()->whereNull('activated_at')->findOrFail($draftId);
                $draft->update(['definition' => $definition]);

                return $draft;
            }

            return $template->versions()->create(['number' => ($template->versions()->max('number') ?? 0) + 1, 'definition' => $definition, 'created_by' => $userId]);
        });
    }

    public function activate(ImportTemplateVersion $version): void
    {
        Gate::authorize('template.manage');
        DB::transaction(function () use ($version) {
            $template = ImportTemplate::lockForUpdate()->findOrFail($version->import_template_id);
            $version = $template->versions()->findOrFail($version->id);
            $this->validate($template->source, $version->definition);
            if (! $version->activated_at) {
                $version->update(['activated_at' => now()]);
            }
            $template->update(['active_version_id' => $version->id]);
        });
    }

    public function workbook(ImportTemplateVersion $version): Spreadsheet
    {
        $definition = $this->validate($version->template->source, $version->definition);
        $book = new Spreadsheet;
        $book->getProperties()->setCustomProperty('sdm_template_version', (string) $version->id);
        $sheet = $book->getActiveSheet()->setTitle($version->template->source === 'PERSONNEL_DUK' ? 'DUK PEGAWAI' : 'PETA JABATAN');
        $last = Coordinate::stringFromColumnIndex(count($definition['columns']));
        foreach ([1 => $definition['title'], 2 => $definition['subtitle'] ?? ''] as $r => $text) {
            $sheet->mergeCells('A'.$r.':'.$last.$r)->setCellValueExplicit('A'.$r, $text, DataType::TYPE_STRING);
        }
        foreach ($definition['columns'] as $i => $c) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValueExplicit($col.'3', $c['label'], DataType::TYPE_STRING);
            $style = $sheet->getStyle($col.'3');
            $style->getFill()->setFillType('solid')->getStartColor()->setRGB(substr($c['color'], 1));
            $style->getFont()->setBold((bool) $c['bold']);
            $style->getAlignment()->setHorizontal($c['align'])->setWrapText(true)->setVertical('center');
            $sheet->getColumnDimension($col)->setWidth((float) $c['width']);
            $sheet->getStyle($col.'4:'.$col.'15000')->getNumberFormat()->setFormatCode('@');
        }
        $sheet->getRowDimension(3)->setRowHeight(48);
        $sheet->freezePane('A4');
        $sheet->getStyle('A1:'.$last.'2')->getAlignment()->setHorizontal('center')->setWrapText(true);
        $sheet->getStyle('A1:'.$last.'2')->getFont()->setBold(true);
        $guide = $book->createSheet()->setTitle('Petunjuk');
        $guide->fromArray([['Template versi '.$version->number], ['Isi data mulai baris 4. Pertahankan judul kolom dan nama lembar.'], ['NIP harus berupa teks. Kolom tanggal tambahan: YYYY-MM-DD.'], ['Kolom tambahan disimpan sebagai dokumentasi, tidak mengubah perhitungan dashboard.'], ['Jika judul kolom diubah di Excel, pilih versi template dan sesuaikan pemetaan pada halaman import.']]);
        $guide->getColumnDimension('A')->setWidth(110);
        $book->setActiveSheetIndex(0);

        return $book;
    }
}
