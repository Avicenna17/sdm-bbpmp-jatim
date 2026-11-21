<?php

namespace App\Http\Controllers;

use App\Domain\Pak\Queries\PakEvidenceQuery;
use App\Domain\Pak\Services\PakEvidenceStorage;
use Throwable;

final class PakEvidenceDownloadController extends Controller
{
    public function __invoke(int $case, int $evidence, PakEvidenceQuery $query, PakEvidenceStorage $storage)
    {
        $record = $query->find(auth()->user(), $case, $evidence);
        try {
            $bytes = $storage->verifiedBytes($record->only(['disk', 'object_key', 'original_filename', 'mime_type', 'size_bytes', 'checksum_sha256']));
            $filename = $storage->filename($record->original_filename);
        } catch (Throwable) {
            abort(404, 'Dokumen tidak tersedia.');
        }

        return response()->streamDownload(static function () use ($bytes) {
            echo $bytes;
        }, $filename, ['Content-Type' => $record->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
