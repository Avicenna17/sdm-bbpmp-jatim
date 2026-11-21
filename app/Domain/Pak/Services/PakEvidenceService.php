<?php

namespace App\Domain\Pak\Services;

use App\Domain\Pak\Authorization\PakEvidenceAuthorization as Access;
use App\Models\PakCase;
use App\Models\PakCaseEvidence;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class PakEvidenceService
{
    public function __construct(private PakEvidenceStorage $storage) {}

    public function upload(User $actor, int $caseId, UploadedFile $file): PakCaseEvidence
    {
        Access::authorize($actor, true);
        Access::requireDraft(PakCase::query()->findOrFail($caseId));
        $connection = DB::connection();
        // Own the commit outcome: an outer transaction cannot defer evidence acceptance.
        if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
            throw new RuntimeException('Upload memerlukan transaksi mandiri.');
        }
        $metadata = $this->storage->store($file);
        $committing = false;
        try {
            $connection->beginTransaction();
            $case = PakCase::query()->lockForUpdate()->findOrFail($caseId);
            Access::authorize($actor, true);
            Access::requireDraft($case);
            $evidence = PakCaseEvidence::create($metadata + ['pak_case_id' => $case->id, 'uploaded_by' => $actor->id]);
            app(PakAuditService::class)->record($actor, $case, 'PAK_CASE_EVIDENCE_UPLOADED', ['after' => [
                'case_id' => $case->id, 'evidence_id' => $evidence->id, 'mime_type' => $evidence->mime_type, 'size_bytes' => $evidence->size_bytes,
            ]], $case->id);
            $committing = true;
            $connection->commit();

            return $evidence;
        } catch (Throwable $error) {
            $rolledBack = false;
            try {
                if ($connection->transactionLevel() > 0 && $connection->getPdo()->inTransaction()) {
                    $connection->rollBack();
                    $rolledBack = $connection->transactionLevel() === 0 && ! $connection->getPdo()->inTransaction();
                }
            } catch (Throwable) {
                throw new RuntimeException('Upload tidak dapat dikonfirmasi; periksa dokumen tersimpan sebelum mencoba lagi.');
            }
            if ($committing) {
                // Confirmed rollback uses normal compensation; otherwise reconcile on the primary.
                try {
                    if ($rolledBack || $this->storage->isReferenced($metadata) === false) {
                        $this->storage->cleanupUnreferenced($metadata);
                    }
                } catch (Throwable) {
                    // Preserve the new object for operational reconciliation.
                }
                throw new RuntimeException('Upload tidak dapat dikonfirmasi; periksa dokumen tersimpan sebelum mencoba lagi.');
            }
            if ($rolledBack || ($connection->transactionLevel() === 0 && ! $connection->getPdo()->inTransaction())) {
                try {
                    $this->storage->cleanupUnreferenced($metadata);
                } catch (Throwable $cleanupError) {
                    if ($error instanceof HttpExceptionInterface) {
                        throw $error;
                    }
                    throw $cleanupError;
                }
            }
            throw $error;
        }
    }
}
