<?php

namespace App\Domain\Import;

use App\Domain\Templates\TemplateReader;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

class WorkbookParser
{
    public const PERSONNEL = 'PERSONNEL_DUK';

    public const POSITION = 'POSITION_REQUIREMENT';

    private TemplateReader $templateReader;

    private array $issues = [];

    private string $sheetName = '';

    private array $columns = [];

    private array $summary = [];

    public static function clean(mixed $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $value));
    }

    public static function norm(mixed $value): string
    {
        return mb_strtoupper(self::clean($value));
    }

    private function issue(int $row, string $severity, string $code, string $message): void
    {
        $field = match ($code) {
            'INVALID_NIP', 'NON_NIP_TEXT_PPNPN', 'MISSING_NIP', 'DUPLICATE_NIP' => 'nip_at_period',
            'MISSING_STATUS', 'UNKNOWN_STATUS' => 'employment_status',
            'UNMATCHED_SUPPLEMENTAL_PERSON', 'AMBIGUOUS_SUPPLEMENTAL_PERSON', 'NAME_FORMAT_NORMALIZED', 'DUPLICATE_IDENTITY', 'MISSING_NAME' => 'name_at_period',
            default => null,
        };
        $cell = $row > 0 && $field && isset($this->columns[$field])
            ? Coordinate::stringFromColumnIndex($this->columns[$field] + 1).$row : null;
        $this->issues[] = ['source_sheet' => $this->sheetName ?: null, 'source_cell' => $cell, 'source_row' => $row, 'field_name' => $field, 'severity' => $severity, 'code' => $code, 'message' => $message];
    }

    public function parse(string $path, string $source, ?int $versionId = null, array $mapping = [], array $ignored = [], bool $allowDraft = false): array
    {
        $this->issues = [];
        $this->sheetName = '';
        $this->columns = [];
        $this->summary = ['supplemental_matched' => 0, 'supplemental_unmatched' => 0];
        if (! in_array($source, [self::PERSONNEL, self::POSITION], true)) {
            throw new RuntimeException('Sumber import tidak dikenal.');
        }
        $reader = IOFactory::createReaderForFile($path);
        if (! in_array($reader::class, [Xlsx::class, Xls::class], true)) {
            throw new RuntimeException('File harus berupa workbook XLS/XLSX asli.');
        }
        // XLS readers omit merged-cell metadata in data-only mode.
        $reader->setReadDataOnly(false);
        $book = $reader->load($path);
        try {
            $this->templateReader = new TemplateReader;
            $this->templateReader->resolve($book, $source, $versionId, $mapping, $ignored, $allowDraft);
            if ($source === self::PERSONNEL) {
                $sheet = $book->getSheetByName('DUK PEGAWAI');
                if (! $sheet) {
                    throw new RuntimeException('Sheet DUK PEGAWAI tidak ditemukan.');
                }
                $rows = $this->readSheet($sheet, $source);
                // DUK PEGAWAI is the sole authoritative source, including placement.
            } else {
                $rows = null;
                $sheets = $this->templateReader->version ? [$book->getSheetByName('PETA JABATAN')] : $book->getWorksheetIterator();
                foreach ($sheets as $sheet) {
                    if (! $sheet) {
                        throw new RuntimeException('Lembar PETA JABATAN tidak ditemukan. Gunakan nama lembar sesuai template.');
                    }
                    try {
                        $rows = $this->readSheet($sheet, $source);
                        break;
                    } catch (RuntimeException $e) {
                        if (! str_starts_with($e->getMessage(), 'Header')) {
                            throw $e;
                        }
                    }
                }
                if ($rows === null) {
                    throw new RuntimeException('Header Peta Jabatan tidak ditemukan.');
                }
            }
            if (! $rows) {
                $this->issue(0, 'ERROR', 'EMPTY_DATASET', 'Tidak ada baris data. Snapshot kosong tidak dapat di-commit.');
            }

            if ($this->templateReader->version) {
                $this->summary['template_version_id'] = $this->templateReader->version->id;
                $this->summary['template_mapping'] = $this->templateReader->mapping;
                $this->summary['ignored_columns'] = $ignored;
                $this->summary['mapping_overrides'] = $mapping;
            }

            return ['rows' => $rows, 'issues' => $this->issues, 'summary' => $this->summary];
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function aliases(string $source): array
    {
        if ($source === self::PERSONNEL) {
            return [
                'name_at_period' => ['NAMA', 'NAMA PEGAWAI'], 'nip_at_period' => ['NIP', 'NIP/NIP3K', 'NIP/NIP 3K', 'NIP / NIP3K', 'NIP/NIPPPK', 'NIP3K'],
                'source_sequence' => ['NO', 'NO.', 'NOMOR'], 'rank_name' => ['PANGKAT'], 'grade_code' => ['GOL', 'GOL.', 'GOLONGAN', 'GOLONGAN RUANG'],
                'position_name' => ['JABATAN', 'NAMA JABATAN'], 'position_class' => ['KELAS JABATAN'],
                'placement_current' => ['PENEMPATAN', 'SK TIM KERJA BARU', 'SK TIM KERJA (BARU)', 'PENEMPATAN / SK TIM KERJA BARU', 'TIM KERJA'],
                'placement_initial' => ['SK TIM KERJA AWAL', 'SK TIM KERJA (AWAL)'], 'assignment_detail' => ['TUGAS', 'TUGAS SEBAGAI', 'DETAIL PENEMPATAN', 'PROGRAM'],
                'education_raw' => ['PENDIDIKAN', 'PENDIDIKAN TERAKHIR'], 'gender' => ['JENIS KELAMIN', 'JK', 'L/P'], 'employment_status' => ['STATUS', 'STATUS PEGAWAI'],
            ];
        }

        return [
            'source_sequence' => ['NO', 'NO.', 'NOMOR'], 'parent_org' => ['UNIT ORGANISASI INDUK'], 'work_unit' => ['SATUAN KERJA'], 'position_name' => ['NAMA JABATAN', 'JABATAN'],
            'position_type' => ['JENIS JABATAN'], 'position_class' => ['KELAS JABATAN'], 'retirement_age' => ['USIA PENSIUN'],
            'incumbent_count' => ['JUMLAH PEMANGKU'], 'requirement_count' => ['JUMLAH KEBUTUHAN'], 'vacancy_count' => ['JUMLAH KOSONG'],
            'requirement_status' => ['STATUS', 'STATUS KEBUTUHAN'], 'retirement_5y_total' => ['TOTAL PENSIUN 5 TAHUN'],
        ];
    }

    private function table(Worksheet $sheet, string $source): array
    {
        if ($sheet->getHighestDataRow() > 15000 || Coordinate::columnIndexFromString($sheet->getHighestDataColumn()) > 200) {
            throw new RuntimeException('Workbook melebihi batas 15.000 baris / 200 kolom.');
        }
        if ($this->templateReader->version) {
            return $this->templateReader->table($sheet);
        }
        $data = $sheet->toArray(null, false, false, false);
        // Propagate only merged header cells, never merged data rows.
        foreach ($sheet->getMergeCells() as $range) {
            [[$c1,$r1],[$c2,$r2]] = Coordinate::rangeBoundaries($range);
            if ($r1 > 30) {
                continue;
            }
            $v = $data[$r1 - 1][$c1 - 1] ?? null;
            foreach (range($r1, min($r2, 30)) as $r) {
                foreach (range($c1, $c2) as $c) {
                    $data[$r - 1][$c - 1] = $v;
                }
            }
        }
        $aliases = $this->aliases($source);
        $map = [];
        $end = -1;
        for ($r = 0; $r < min(count($data), 30); $r++) {
            $candidate = [];
            foreach ($data[$r] as $c => $value) {
                foreach ($aliases as $field => $names) {
                    if (in_array(self::norm($value), $names, true)) {
                        $candidate[$field] ??= $c;
                    }
                }
            }
            $required = $source === self::PERSONNEL ? ['name_at_period', 'employment_status'] : ['position_name', 'incumbent_count', 'requirement_count'];
            if (count($candidate) >= 3) {
                $map = array_replace($map, $candidate);
                $end = $r;
            }
            if (! array_diff($required, array_keys($map))) {
                // Collect subordinate headers (years, placement) beneath a multi-row header.
                for ($extra = 1; $extra <= 2 && isset($data[$r + $extra]); $extra++) {
                    $line = $data[$r + $extra];
                    // Check before scanning: a data value such as "Nama" or "Status"
                    // must not move a column mapping established by the header.
                    $identityField = $source === self::PERSONNEL ? 'name_at_period' : 'position_name';
                    $identity = $line[$map[$identityField]] ?? null;
                    if ($identity && ! in_array(self::norm($identity), $aliases[$identityField], true) && ! ctype_digit(self::clean($identity))) {
                        break;
                    }
                    $recognized = false;
                    $childCandidate = [];
                    foreach ($line as $c => $v) {
                        $h = self::norm($v);
                        foreach ($aliases as $field => $names) {
                            if (in_array($h, $names, true)) {
                                $childCandidate[$field] ??= $c;
                                $recognized = true;
                            }
                        }
                        if (preg_match('/^(19|20|21)\d{2}$/', $h)) {
                            $parent = self::norm($data[$r][$c] ?? '');
                            if (str_contains($parent, 'PENSIUN') || str_contains($parent, 'PROYEKSI KEBUTUHAN')) {
                                $data[$r][$c] = $parent.' '.$h;
                                $recognized = true;
                            }
                        }
                    }
                    $map = array_replace($map, $childCandidate);
                    // A data row containing a name is never a header continuation.
                    $identity = $line[$map[$source === self::PERSONNEL ? 'name_at_period' : 'position_name']] ?? null;
                    if ($identity && ! in_array(self::norm($identity), $aliases[$source === self::PERSONNEL ? 'name_at_period' : 'position_name'], true) && ! ctype_digit(self::clean($identity))) {
                        break;
                    }
                    if ($recognized) {
                        $end = $r + $extra;
                    } else {
                        break;
                    }
                }
                if ($source === self::PERSONNEL && isset($map['placement_initial']) && ! isset($map['assignment_detail'])) {
                    // The BBPMP DUK layout groups initial team and program detail
                    // under a two-column "SK Tim Kerja (Awal)" merged heading.
                    foreach ($sheet->getMergeCells() as $range) {
                        [[$c1, $r1], [$c2, $r2]] = Coordinate::rangeBoundaries($range);
                        if ($r1 <= $end + 1 && $r2 <= $end + 1 && $c2 === $c1 + 1
                            && $map['placement_initial'] === $c1 - 1
                            && in_array(self::norm($data[$r1 - 1][$c1 - 1] ?? ''), $aliases['placement_initial'], true)) {
                            $map['assignment_detail'] = $c2 - 1;
                        }
                    }
                }
                if ($source === self::POSITION) {
                    foreach ($data[$r] as $c => $v) {
                        if (preg_match('/^(PENSIUN|PROYEKSI KEBUTUHAN)\s+((?:19|20|21)\d{2})$/', self::norm($v), $m)) {
                            $key = 'projection:'.($m[1] === 'PENSIUN' ? 'RETIREMENT' : 'REQUIREMENT').':'.$m[2];
                            if (isset($map[$key])) {
                                throw new RuntimeException('Kolom '.$v.' muncul lebih dari sekali. Gunakan satu kolom untuk setiap jenis dan tahun.');
                            }
                            $map[$key] = $c;
                        }
                    }
                }

                return [$data, $map, $end];
            }
        }
        throw new RuntimeException('Header wajib tidak ditemukan pada sheet '.$sheet->getTitle().'. Gunakan template pada menu import.');
    }

    private function readSheet(Worksheet $sheet, string $source): array
    {
        $this->sheetName = $sheet->getTitle();
        $this->columns = [];
        [$data,$map,$end] = $this->table($sheet, $source);
        $this->columns = $map;
        $rows = [];
        $seen = [];
        foreach (array_slice($data, $end + 1, null, true) as $index => $line) {
            if (! array_filter($line, fn ($v) => self::clean($v) !== '')) {
                continue;
            }
            $row = ['source_row_no' => $index + 1, 'raw_payload' => $line];
            foreach ($map as $field => $col) {
                $value = self::clean($line[$col] ?? '');
                $row[$field] = $value === '' ? null : $value;
            }
            foreach ($this->templateReader->additional($row) as $message) {
                $this->issue($index + 1, 'ERROR', 'INVALID_TEMPLATE_VALUE', $message);
            }
            $identity = $row[$source === self::PERSONNEL ? 'name_at_period' : 'position_name'] ?? null;
            if (in_array(self::norm($identity), ['JUMLAH', 'TOTAL', 'JUMLAH TOTAL'], true)) {
                continue;
            }
            if (! $identity) {
                $this->issue($index + 1, 'ERROR', 'MISSING_NAME', 'Nama pegawai / jabatan wajib diisi.');
            }
            if ($source === self::PERSONNEL) {
                $row = $this->personnel($row);
            } else {
                $row = $this->position($row);
            }
            $key = $row[$source === self::PERSONNEL ? 'person_key' : 'position_key'];
            if (isset($seen[$key])) {
                $this->issue($index + 1, 'ERROR', $source === self::PERSONNEL && $row['nip_at_period'] ? 'DUPLICATE_NIP' : 'DUPLICATE_IDENTITY', 'Identitas duplikat dengan baris '.$seen[$key].'.');
            }
            foreach ($row as $field => $value) {
                if (is_string($value) && mb_strlen($value) > 255) {
                    $this->issue($index + 1, 'ERROR', 'VALUE_TOO_LONG', "Kolom {$field} melebihi 255 karakter.");
                }
            }
            $seen[$key] = $index + 1;
            $rows[] = $row;
        }

        return $rows;
    }

    private function integer(array $row, string $field, bool $signed = false, int $max = 2147483647): ?int
    {
        $value = $row[$field] ?? null;
        if ($value === null || $value === '' || $value === '-') {
            return null;
        }
        if (! preg_match($signed ? '/^-?\d+$/' : '/^\d+$/', (string) $value) || (float) $value > $max || (float) $value < -2147483648) {
            $this->issue($row['source_row_no'], 'ERROR', 'INVALID_NUMBER', "Kolom {$field} harus berupa bilangan bulat yang valid.");

            return null;
        }

        return (int) $value;
    }

    private function personnel(array $row): array
    {
        $row['nip_note'] = null;
        $nip = preg_replace('/\s+/', '', (string) ($row['nip_at_period'] ?? ''));
        if (in_array($nip, ['', '-'], true)) {
            $nip = null;
        } elseif (! preg_match('/^\d{18}$/', $nip)) {
            $isPpnPnDescription = in_array(self::norm($row['employment_status'] ?? ''), ['PPNPN', 'PNPN'], true)
                && preg_match('/\p{L}/u', $nip) && ! preg_match('/\p{N}/u', $nip);
            if ($isPpnPnDescription) {
                $row['nip_note'] = self::clean($row['nip_at_period']);
            } else {
                $this->issue($row['source_row_no'], 'ERROR', 'INVALID_NIP', 'NIP/NIP3K yang diisi harus 18 digit utuh. Periksa nomor pada sumber resmi dan simpan sebagai teks; mengganti format sel saja tidak memulihkan digit yang sudah berubah. Nomor tidak diperbaiki atau ditebak otomatis.');
            }
            $nip = null;
        }
        $row['nip_at_period'] = $nip;
        $row['normalized_name'] = self::norm($row['name_at_period'] ?? '');
        $row['person_key'] = hash('sha256', $nip ? 'nip:'.$nip : 'name:'.$row['normalized_name']);
        $status = str_replace(['-', '_'], ' ', self::norm($row['employment_status'] ?? ''));
        $row['employment_status'] = match ($status) {
            'PNS' => 'PNS','PPPK','P3K' => 'PPPK','PPPK PARUH WAKTU','P3K PARUH WAKTU' => 'PPPK_PARUH_WAKTU','PPNPN','PNPN' => 'PPNPN',default => 'UNKNOWN'
        };
        $row['employment_group'] = match ($row['employment_status']) {
            'PNS','PPPK','PPPK_PARUH_WAKTU' => 'ASN','PPNPN' => 'PPNPN',default => 'UNKNOWN'
        };
        if ($row['employment_group'] === 'UNKNOWN') {
            $this->issue($row['source_row_no'], 'WARNING', $status ? 'UNKNOWN_STATUS' : 'MISSING_STATUS', 'Status '.($status ? 'tidak dikenal' : 'kosong').'. Pegawai tetap diimport sebagai UNKNOWN (belum terklasifikasi), tanpa dimasukkan ke ASN/PPNPN. Isi status yang benar pada file sumber; warning ini tidak menghalangi commit.');
        }
        if (! $nip && $row['employment_group'] === 'ASN') {
            $this->issue($row['source_row_no'], 'WARNING', 'MISSING_NIP', 'ASN tanpa NIP memakai identitas nama; periksa file sumber.');
        }
        $gender = self::norm($row['gender'] ?? '');
        $row['gender'] = match ($gender) {
            'L','LAKI-LAKI','LAKI LAKI','PRIA' => 'L','P','PEREMPUAN','WANITA' => 'P','','-' => null,default => 'INVALID'
        };
        if ($row['gender'] === 'INVALID') {
            $this->issue($row['source_row_no'], 'ERROR', 'INVALID_GENDER', 'Jenis kelamin tidak dikenal.');
            $row['gender'] = null;
        }
        $education = self::norm($row['education_raw'] ?? '');
        $row['education_level'] = match (str_replace([' ', '-'], '', $education)) {
            'DIV','D4' => 'D4','DI','D1' => 'D1','DII','D2' => 'D2','DIII','D3' => 'D3','S1' => 'S1','S2' => 'S2','S3' => 'S3','SLTP','SMP' => 'SMP',default => $education ?: null
        };
        $row['position_class'] = $this->integer($row, 'position_class', false, 255);
        $row['source_sequence'] = $this->integer($row, 'source_sequence');

        return $row;
    }

    private function position(array $row): array
    {
        $row['projections'] = [];
        foreach (array_keys($row) as $field) {
            if (str_starts_with($field, 'projection:')) {
                [, $metric,$year] = explode(':', $field);
                $row['projections'][] = ['metric_type' => $metric, 'projection_year' => (int) $year, 'value' => $this->integer($row, $field)];
                unset($row[$field]);
            }
        }
        foreach (['source_sequence', 'position_class', 'retirement_age', 'incumbent_count', 'requirement_count', 'vacancy_count', 'retirement_5y_total'] as $field) {
            $row[$field] = $this->integer($row, $field, $field === 'vacancy_count', $field === 'position_class' ? 255 : ($field === 'retirement_age' ? 65535 : 2147483647));
        }
        $row['position_key'] = hash('sha256', implode('|', array_map(fn ($f) => self::norm($row[$f] ?? ''), ['parent_org', 'work_unit', 'position_name', 'position_class'])));

        return $row;
    }
}
