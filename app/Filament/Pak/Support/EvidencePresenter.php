<?php

namespace App\Filament\Pak\Support;

use App\Domain\Pak\Services\PakEvidenceStorage;
use App\Models\PakCaseEvidence;

final class EvidencePresenter
{
    public function present(PakCaseEvidence $evidence): array
    {
        try {
            app(PakEvidenceStorage::class)->validateMetadata($evidence->only([
                'disk', 'object_key', 'original_filename', 'mime_type', 'size_bytes', 'checksum_sha256',
            ]));
        } catch (\Throwable) {
            return ['filename' => 'Dokumen tidak tersedia', 'mime' => '—', 'size' => '—', 'uploader' => '—', 'time' => '—', 'url' => null];
        }

        return [
            'filename' => $evidence->original_filename, 'mime' => $evidence->mime_type,
            'size' => number_format($evidence->size_bytes / 1024, 1, ',', '.').' KiB',
            'uploader' => $evidence->uploader?->name ?? 'Tidak tersedia',
            'time' => $evidence->created_at?->timezone(config('app.timezone'))->format('d/m/Y H:i'),
            'url' => route('pak.evidence.download', ['case' => $evidence->pak_case_id, 'evidence' => $evidence->id]),
        ];
    }
}
