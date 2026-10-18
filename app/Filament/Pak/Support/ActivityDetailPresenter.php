<?php

namespace App\Filament\Pak\Support;

use App\Filament\Pak\Resources\PakEventResource;
use App\Models\PakEvent;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/** Read-only presentation of recorded values; never infers a missing historical value. */
final class ActivityDetailPresenter
{
    public function present(PakEvent $event): array
    {
        $payload = $event->payload ?? [];
        $before = $this->flatten($payload['before'] ?? []);
        $after = $this->flatten($payload['after'] ?? []);
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

    private function flatten(mixed $data): array
    {
        return is_array($data) ? Arr::dot($data) : (is_null($data) ? [] : ['value' => $data]);
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
            return $value === [] ? 'Tidak ada data' : 'Lihat data teknis';
        }

        return (string) $value;
    }
}
