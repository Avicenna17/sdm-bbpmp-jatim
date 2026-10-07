<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PositionDataset extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function draftBatch()
    {
        return $this->belongsTo(ImportBatch::class, 'draft_batch_id');
    }

    public static function current(): self
    {
        return static::findOrFail(1);
    }

    public static function exportVersions(): Builder
    {
        return ImportBatch::query()->where('source_type', 'POSITION_REQUIREMENT')->where('status', 'committed')
            ->whereNotNull('published_at')->whereHas('positionSnapshots');
    }

    public static function exportSnapshots(?int $batchId = null): Builder
    {
        $batchId ??= static::current()->published_batch_id;

        return PositionRequirementSnapshot::query()->where('import_batch_id', $batchId ?? 0)
            ->whereIn('import_batch_id', static::exportVersions()->select('id'));
    }

    public static function snapshots(bool $published = true): Builder
    {
        $state = static::current();
        $batchId = $published ? $state->published_batch_id : $state->draft_batch_id;

        return PositionRequirementSnapshot::query()->where('import_batch_id', $batchId ?? 0);
    }
}
