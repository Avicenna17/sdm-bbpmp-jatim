<?php

namespace App\Domain\ReportingPeriod;

use App\Models\ImportBatch;
use App\Models\PositionDataset;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PublishPositionService
{
    public function publish(User $user, ?int $expectedBatchId): void
    {
        Gate::forUser($user)->authorize('period.publish');
        DB::transaction(function () use ($user, $expectedBatchId) {
            $state = PositionDataset::lockForUpdate()->findOrFail(1);
            if (! $expectedBatchId || $state->draft_batch_id !== $expectedBatchId) {
                throw ValidationException::withMessages(['positions' => 'Versi Peta Jabatan berubah atau belum tersedia. Muat ulang dan periksa versi terbaru.']);
            }
            if ($state->published_batch_id === $expectedBatchId) {
                return;
            }
            if (! PositionDataset::snapshots(false)->exists()) {
                throw ValidationException::withMessages(['positions' => 'Simpan Peta Jabatan sebelum publikasi.']);
            }
            ImportBatch::whereKey($expectedBatchId)->update(['published_at' => now()]);
            $state->update(['published_batch_id' => $expectedBatchId, 'published_at' => now(), 'published_by' => $user->id]);
        });
    }
}
