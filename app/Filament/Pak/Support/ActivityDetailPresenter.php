<?php

namespace App\Filament\Pak\Support;

use App\Filament\Pak\Resources\PakEventResource;
use App\Models\PakEvent;
use Illuminate\Support\Str;

/** Read-only presentation of recorded values; never infers a missing historical value. */
final class ActivityDetailPresenter
{
    public function present(PakEvent $event): array
    {
        $payload = $this->projectPayload($event->event_code, $event->payload);
        $before = $payload['before'] ?? [];
        $after = $payload['after'] ?? [];
        $changes = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $hasBefore = array_key_exists($key, $before);
            $hasAfter = array_key_exists($key, $after);
            if ($hasBefore && $hasAfter && $before[$key] === $after[$key]) {
                continue;
            }
            $changes[] = [
                'label' => $key === 'is_active' && $event->subject_type === 'User' ? 'Status Akun' : $this->label($key),
                'before' => $hasBefore ? $this->value($key, $before[$key]) : 'Tidak tercatat',
                'after' => $hasAfter ? $this->value($key, $after[$key]) : 'Tidak tercatat',
            ];
        }
        $actor = $event->actor?->name ?? 'Pengguna tidak tercatat';
        $activity = match ($event->event_code) {
            'ACCOUNT_DEACTIVATED' => 'Penonaktifan Akun',
            'ACCOUNT_REACTIVATED' => 'Pengaktifan Akun',
            default => PakEventResource::activityLabel($event->event_code),
        };
        $object = $event->subject_type === 'User' ? 'Akun Pengguna #'.$event->subject_id : PakEventResource::objectLabel($event);
        $narrative = $actor.' mencatat aktivitas “'.$activity.'” pada '.$object.'.';
        if (in_array($event->event_code, ['ACCOUNT_DEACTIVATED', 'ACCOUNT_REACTIVATED'], true) && $event->subject_type === 'User') {
            $verb = $event->event_code === 'ACCOUNT_DEACTIVATED' ? 'penonaktifan' : 'pengaktifan';
            $narrative = $actor.' melakukan '.$verb.' akun pengguna #'.$event->subject_id.'.';
            if (array_key_exists('is_active', $before) && array_key_exists('is_active', $after)) {
                $narrative .= ' Status berubah dari '.$this->value('is_active', $before['is_active']).' menjadi '.$this->value('is_active', $after['is_active']).'.';
            }
        }

        return [
            'time' => $event->occurred_at->copy()->timezone(config('app.timezone'))->locale('id')->translatedFormat('d F Y, H:i:s').' '.PakEventResource::timezoneLabel(),
            'actor' => $actor, 'activity' => $activity, 'object' => $object,
            'changes' => $changes, 'reason' => $this->value('reason', $payload['reason'] ?? null),
            'narrative' => $narrative,
        ];
    }

    /** Unknown keys are ignored; malformed allowed values invalidate the whole detail. */
    private function projectPayload(string $code, mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }
        $schemas = $this->schemas();
        if (! isset($schemas[$code])) {
            return $this->legacyPayload($code, $payload);
        }
        $result = [];
        foreach ($schemas[$code] as $section => $schema) {
            if (is_array($schema)) {
                if (! isset($payload[$section]) || ! is_array($payload[$section])) {
                    return [];
                }
                $values = $this->fields($payload[$section], $schema);
                if ($values === null) {
                    return [];
                }
                $result[$section] = $values;
            } else {
                if (! array_key_exists($section, $payload) || ! $this->valid($payload[$section], $schema)) {
                    return [];
                }
                if ($section === 'reason') {
                    $result['reason'] = $payload[$section];
                } else {
                    $result['after'][$section] = $payload[$section];
                }
            }
        }

        return $result;
    }

    private function schemas(): array
    {
        $run = ['case_id' => 'id', 'run_id' => 'id', 'revision_number' => 'id', 'status' => 'run'];
        $predicate = ['pak_rule_set_id' => 'id', 'predicate_code' => 'code', 'predicate_name' => 'text', 'predicate_factor' => 'decimal'];
        $coefficient = ['pak_rule_set_id' => 'id', 'functional_level_id' => 'id', 'annual_coefficient' => 'decimal'];
        $review = $run + ['assessment_sequence' => 'count'];

        return [
            'PAK_CASE_EVIDENCE_UPLOADED' => ['after' => ['case_id' => 'id', 'evidence_id' => 'id', 'mime_type' => 'evidence_mime', 'size_bytes' => 'evidence_size']],
            'SKP_IMPORT_COMMITTED' => [
                'before' => ['id' => 'id', 'status' => '=READY'],
                'after' => ['id' => 'id', 'status' => '=COMMITTED', 'summary' => [
                    'total' => 'count', 'outcomes' => array_fill_keys(['PENDING', 'READY', 'IMPORTED', 'DUPLICATE', 'CONFLICT', 'IDENTITY_AMBIGUOUS', 'INVALID', 'FAILED'], 'count'),
                ]],
            ],
            'PERFORMANCE_EVALUATION_CORRECTED' => [
                'before' => ['id' => 'id', 'status' => '=CURRENT'],
                'after' => ['id' => 'id', 'status' => '=CURRENT', 'source_row_id' => 'id'],
                'reason' => 'text', 'supersedes_id' => 'id',
            ],
            'PREDICATE_RULE_CREATED' => ['after' => $predicate],
            'PREDICATE_RULE_UPDATED' => ['before' => $predicate, 'after' => $predicate],
            'COEFFICIENT_RULE_CREATED' => ['after' => $coefficient],
            'COEFFICIENT_RULE_UPDATED' => ['before' => $coefficient, 'after' => $coefficient],
            'RULE_SET_COPIED' => ['after' => [
                'source_rule_set_id' => 'id', 'target_rule_set_id' => 'id', 'code' => 'text', 'version' => 'text',
                'status' => '=DRAFT', 'predicate_count' => 'count', 'coefficient_count' => 'count',
            ]],
            'CONVERSION_RUN_SAVED' => ['after' => $run + ['issue_codes' => 'codes']],
            'CONVERSION_RUN_REVIEW_ASSESSED' => [
                'before' => $review + ['comparison?' => 'comparison'],
                'after' => array_replace($review, ['status' => '=REQUIRES_REVIEW']) + [
                    'comparison' => 'comparison', 'changed_groups' => 'groups', 'current_issue_codes' => 'codes', 'failure_code?' => 'code',
                ],
                'reason' => 'text',
            ],
            'CONVERSION_RUN_REPLACED' => [
                'before' => ['run_id' => 'id', 'revision_number' => 'id', 'status' => 'run'],
                'after' => $run + ['issue_codes' => 'codes'], 'reason' => 'text', 'supersedes_id' => 'id',
            ],
        ];
    }

    private function fields(array $data, array $schema, string $prefix = ''): ?array
    {
        $result = [];
        foreach ($schema as $field => $type) {
            $optional = str_ends_with($field, '?');
            $field = rtrim($field, '?');
            if (! array_key_exists($field, $data)) {
                if ($optional) {
                    continue;
                }

                return null;
            }
            $value = $data[$field];
            $path = $prefix.$field;
            if (is_array($type)) {
                if (! is_array($value) || ($nested = $this->fields($value, $type, $path.'.')) === null) {
                    return null;
                }
                $result += $nested;
            } else {
                if (! $this->valid($value, $type)) {
                    return null;
                }
                $result[$path] = is_array($value) ? ($value === [] ? 'Tidak ada' : implode(', ', $value)) : $value;
            }
        }

        return $result;
    }

    private function valid(mixed $value, string $type): bool
    {
        if (str_starts_with($type, '=')) {
            return $value === substr($type, 1);
        }
        if (in_array($type, ['codes', 'groups'], true)) {
            if (! is_array($value) || ! array_is_list($value) || count($value) > 100) {
                return false;
            }
            foreach ($value as $item) {
                if ($type === 'groups'
                    ? ! in_array($item, ['case_context', 'coverage', 'source_candidates', 'selected_sources', 'position_assignments', 'history_dependencies', 'rule_set', 'versions'], true)
                    : ! $this->valid($item, 'code')) {
                    return false;
                }
            }

            return count(array_unique($value)) === count($value);
        }

        return match ($type) {
            'evidence_mime' => in_array($value, ['application/pdf', 'image/jpeg', 'image/png'], true),
            'evidence_size' => is_int($value) && $value > 0 && $value <= 10485760,
            'id' => is_int($value) && $value > 0,
            'count' => is_int($value) && $value >= 0,
            'text' => is_string($value) && trim($value) !== '',
            'code' => is_string($value) && preg_match('/\A[A-Z][A-Z0-9_]{0,99}\z/D', $value) === 1,
            'decimal' => is_string($value) && preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/D', $value) === 1,
            'run' => in_array($value, ['PREVIEW', 'REQUIRES_REVIEW'], true),
            'comparison' => in_array($value, ['UNCHANGED', 'CHANGED', 'UNASSESSABLE'], true),
            default => false,
        };
    }

    private function legacyPayload(string $code, array $payload): array
    {
        $fields = match ($code) {
            'ACCOUNT_DEACTIVATED', 'ACCOUNT_REACTIVATED' => ['is_active'],
            'USER_PERSON_LINKED' => ['person_id'],
            'PROFILE_VERIFIED' => ['person_id', 'institution_name', 'work_unit_name'],
            'HISTORY_VERIFIED', 'HISTORY_CORRECTED' => ['person_id', 'rank_name', 'grade_code', 'position_name', 'position_type', 'functional_position_id', 'functional_level_id', 'tmt', 'valid_from', 'valid_until', 'sk_number', 'sk_date'],
            'MASTER_SAVED' => ['code', 'name', 'is_active', 'functional_category_id', 'functional_position_id', 'functional_level_id'],
            'RULE_METADATA_SAVED' => ['code', 'name', 'version', 'status', 'valid_from', 'valid_until'],
            'PAK_CASE_CREATED', 'PAK_SNAPSHOT_REFRESHED' => ['person_id', 'pak_rule_set_id', 'purpose_type', 'status', 'period_start', 'period_end', 'employee_data_as_of', 'lock_version'],
            default => [],
        };
        if ($fields === []) {
            return [];
        }
        $result = [];
        foreach (['before', 'after'] as $side) {
            $data = $payload[$side] ?? [];
            if (! is_array($data)) {
                return [];
            }
            $prefix = '';
            if ($code === 'PAK_SNAPSHOT_REFRESHED') {
                $data = $data['case'] ?? [];
                if (! is_array($data)) {
                    return [];
                }
                $prefix = 'case.';
            }
            foreach ($fields as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }
                $value = $data[$field];
                if (! is_null($value) && ! is_string($value) && ! is_int($value) && ! is_bool($value)) {
                    return [];
                }
                $result[$side][$prefix.$field] = $value;
            }
        }
        if (isset($payload['reason']) && ! is_string($payload['reason'])) {
            return [];
        }
        $result['reason'] = $payload['reason'] ?? null;

        return $result;
    }

    private function label(string $path): string
    {
        $labels = [
            'is_active' => 'Status Keaktifan', 'person_id' => 'ID Pegawai', 'name' => 'Nama', 'email' => 'Email',
            'code' => 'Kode', 'version' => 'Versi', 'status' => 'Status', 'rank_name' => 'Pangkat',
            'grade_code' => 'Golongan Ruang', 'position_name' => 'Nama Jabatan', 'position_type' => 'Jenis Jabatan',
            'tmt' => 'TMT', 'valid_from' => 'Berlaku Mulai', 'valid_until' => 'Berlaku Sampai',
            'sk_number' => 'Nomor SK', 'sk_date' => 'Tanggal SK', 'source_reference' => 'Referensi Sumber',
            'institution_name' => 'Instansi', 'work_unit_name' => 'Unit Kerja', 'purpose_type' => 'Tujuan Pemeriksaan',
            'period_start' => 'Awal Periode', 'period_end' => 'Akhir Periode', 'employee_data_as_of' => 'Tanggal Acuan Data Pegawai',
            'functional_position_id' => 'ID Jabatan Fungsional', 'functional_level_id' => 'ID Jenjang JF',
            'functional_category_id' => 'ID Kategori JF', 'pak_rule_set_id' => 'ID Aturan PAK',
            'case' => 'Draf PAK', 'snapshot' => 'Salinan Data Pegawai', 'value' => 'Data',
        ];

        return implode(' / ', array_map(fn ($key) => $labels[$key] ?? Str::headline($key), explode('.', $path)));
    }

    private function value(string $path, mixed $value): string
    {
        if (Str::afterLast($path, '.') === 'is_active') {
            if (in_array($value, [true, 1, '1'], true)) {
                return 'Aktif';
            }
            if (in_array($value, [false, 0, '0'], true)) {
                return 'Tidak Aktif';
            }
        }
        if ($value === null || $value === '') {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? 'Ya' : 'Tidak';
        }
        if (is_array($value)) {
            return 'Tidak tersedia';
        }

        return (string) $value;
    }
}
