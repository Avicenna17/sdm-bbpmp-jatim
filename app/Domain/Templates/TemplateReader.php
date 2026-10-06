<?php

namespace App\Domain\Templates;

use App\Models\ImportTemplateVersion;
use Illuminate\Support\Facades\Gate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

class TemplateReader
{
    public ?ImportTemplateVersion $version = null;

    public array $columns = [];

    public array $mapping = [];

    public array $ignored = [];

    public int $headerRow = 0;

    private function norm(mixed $v): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/u', ' ', (string) $v)));
    }

    public function resolve(Spreadsheet $book, string $source, ?int $selectedId, array $overrides, array $ignored, bool $allowDraft = false): void
    {
        $this->version = null;
        $this->columns = [];
        $this->mapping = [];
        $this->ignored = $ignored;
        $marker = $book->getProperties()->getCustomPropertyValue('sdm_template_version');
        if ($marker !== null && (! ctype_digit((string) $marker) || (int) $marker < 1)) {
            throw new RuntimeException('Penanda versi template tidak valid. Unduh ulang template.');
        }
        if ($selectedId && $marker && (int) $marker !== $selectedId) {
            throw new RuntimeException('Versi yang dipilih berbeda dengan versi dalam file. Pilih versi yang sesuai.');
        }
        if ($selectedId === 0 && $marker) {
            throw new RuntimeException('File ini memiliki versi template. Pilih Kenali otomatis atau versi yang sesuai.');
        }
        $id = $selectedId ?: ($marker ? (int) $marker : null);
        if (! $id) {
            return;
        }
        $this->version = ImportTemplateVersion::with('template')->find($id);
        if (! $this->version || (! $this->version->activated_at && ! ($allowDraft && Gate::allows('template.manage'))) || $this->version->template->source !== $source) {
            throw new RuntimeException('Versi template tidak tersedia, belum diaktifkan, atau berbeda sumber. Pilih versi yang sesuai.');
        }
        $this->columns = $this->version->definition['columns'];
        $keys = array_column($this->columns, 'key');
        if (array_diff(array_keys($overrides), $keys)) {
            throw new RuntimeException('Pemetaan memuat kolom yang tidak tersedia pada versi template.');
        }
        foreach ($this->columns as &$c) {
            if (filled($overrides[$c['key']] ?? null)) {
                $c['label'] = trim($overrides[$c['key']]);
            }
        }
        unset($c);
        if (count(array_unique(array_map(fn ($c) => $this->norm($c['label']), $this->columns))) !== count($this->columns)) {
            throw new RuntimeException('Nama kolom pada pemetaan tidak boleh sama.');
        }
    }

    public function table(Worksheet $sheet, bool $discover = false): array
    {
        $data = $sheet->toArray(null, false, false, false);
        $expected = [];
        foreach ($this->columns as $c) {
            $expected[$this->norm($c['label'])] = $c['key'];
        }
        if ($discover) {
            foreach (app(\App\Domain\Import\WorkbookParser::class)->aliases($this->version->template->source) as $key => $aliases) {
                foreach ($aliases as $alias) {
                    $expected[$this->norm($alias)] ??= $key;
                }
            }
        }
        $required = app(TemplateService::class)->required($this->version->template->source);
        $best = [];
        $header = null;
        foreach (array_slice($data, 0, 30, true) as $r => $line) {
            $candidate = [];
            foreach ($line as $col => $value) {
                if (isset($expected[$this->norm($value)])) {
                    $key = $expected[$this->norm($value)];
                    if (isset($candidate[$key])) {
                        throw new RuntimeException('Judul kolom muncul dua kali: '.$value.'. Perbaiki file Excel.');
                    }
                    $candidate[$key] = $col;
                }
            }
            if (count($candidate) > count($best)) {
                $best = $candidate;
                $header = $r;
            }
            if (! array_diff($required, array_keys($candidate))) {
                break;
            }
        }
        if ($header === null || array_diff($required, array_keys($best))) {
            if ($discover) {
                $fields = app(TemplateService::class)->fields($this->version->template->source);
                $missing = array_map(fn ($key) => $fields[$key] ?? $key, array_values(array_diff($required, array_keys($best))));
                throw new RuntimeException('Pada lembar '.$sheet->getTitle().', judul kolom wajib belum ditemukan: '.implode(', ', $missing).'. Periksa penulisan header pada file Excel.');
            }
            throw new RuntimeException('Kolom wajib belum dikenali. Pilih versi template dan isi Nama kolom dalam file pada pemetaan import.');
        }
        $this->headerRow = $header + 1;
        $ignored = array_map(fn ($v) => $this->norm($v), $this->ignored);
        foreach ($data[$header] as $col => $label) {
            $label = $this->norm($label);
            if (! $label) {
                foreach (array_slice($data, $header + 1) as $line) {
                    if (filled($line[$col] ?? null)) {
                        throw new RuntimeException('Ada data pada kolom tanpa judul. Lengkapi judul sebelum import.');
                    }
                }

                continue;
            }
            if (! isset($expected[$label]) && ! in_array($label, $ignored, true)) {
                throw new RuntimeException('Kolom "'.$label.'" belum dikenali. Petakan ke kolom template, tambahkan melalui draft template baru, atau tulis pada daftar kolom yang sengaja diabaikan.');
            }
        }
        if ($discover) {
            $known = collect($this->columns)->keyBy('key');
            $defaults = collect(app(TemplateService::class)->defaults($this->version->template->source)['columns'])->keyBy('key');
            $this->columns = [];
            foreach ($best as $key => $index) {
                $column = $known[$key] ?? $defaults[$key] ?? null;
                if (! $column) {
                    throw new RuntimeException('Kegunaan kolom belum tersedia pada template: '.$data[$header][$index]);
                }
                $column['label'] = trim((string) $data[$header][$index]);
                $this->columns[] = $column;
            }
        }
        foreach ($this->columns as $c) {
            if (! isset($best[$c['key']])) {
                throw new RuntimeException('Kolom "'.$c['label'].'" tidak ditemukan. Sesuaikan pemetaan atau pilih versi template yang cocok.');
            }
            $this->mapping[] = ['label' => $c['label'], 'key' => $c['key'], 'column' => $best[$c['key']] + 1, 'target' => app(TemplateService::class)->fields($this->version->template->source)[$c['key']] ?? (str_starts_with($c['key'], 'extra:') ? 'Data tambahan: '.$c['label'] : $c['label'])];
        }

        if ($discover) {
            foreach ($sheet->getMergeCells() as $range) {
                [[$c1, $r1], [$c2, $r2]] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::rangeBoundaries($range);
                if ($r1 === $header + 1 && $r2 <= $header + 3 && $c1 === $c2 && in_array($c1 - 1, $best)) {
                    for ($r = $header + 1; $r < $r2; $r++) {
                        foreach ($best as $index) {
                            $data[$r][$index] = $data[$header][$index];
                        }
                    }
                    $header = $r2 - 1;
                    break;
                }
            }
        }
        return [$data, $best, $header];
    }

    public function additional(array &$row): array
    {
        $extra = [];
        $errors = [];
        foreach ($this->columns as $c) {
            $value = $row[$c['key']] ?? null;
            if ($c['required'] && ($value === null || $value === '')) {
                $errors[] = 'Kolom '.$c['label'].' wajib diisi.';
            }
            if (! str_starts_with($c['key'], 'extra:')) {
                continue;
            }
            if ($value !== null && $value !== '') {
                $valid = match ($c['type']) {
                    'number' => is_numeric($value) && is_finite((float) $value),
                    'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && ($date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value)) && $date->format('Y-m-d') === $value,
                    'choice' => in_array($value, array_map('trim', preg_split('/\r?\n/', $c['choices'] ?? '')), true),
                    default => mb_strlen($value) <= 2000,
                };
                if (! $valid) {
                    $errors[] = 'Isi kolom '.$c['label'].' tidak sesuai jenis data '.$c['type'].'.';
                }
            }
            $extra[$c['key']] = ['label' => $c['label'], 'value' => $value];
            unset($row[$c['key']]);
        }
        if ($extra) {
            $row['extra_data'] = $extra;
        }

        return $errors;
    }
}
