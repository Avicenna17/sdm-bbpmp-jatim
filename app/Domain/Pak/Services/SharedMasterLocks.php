<?php

namespace App\Domain\Pak\Services;

use App\Models\FunctionalCategory;
use App\Models\FunctionalLevel;
use App\Models\FunctionalPosition;
use App\Models\FunctionalPositionLevel;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Relevant rows only; Person (when needed) must already be locked by the caller. */
final class SharedMasterLocks
{
    public function acquire(array $mappingIds = [], array $positionIds = [], array $levelIds = [], array $categoryIds = [], bool $exclusive = false): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Master coordination requires a transaction.');
        }
        $result = [];
        foreach ([FunctionalPositionLevel::class, FunctionalPosition::class, FunctionalLevel::class, FunctionalCategory::class] as $class) {
            $ids = match ($class) {
                FunctionalPositionLevel::class => $mappingIds,
                FunctionalPosition::class => $positionIds,
                FunctionalLevel::class => $levelIds,
                default => $categoryIds,
            };
            $ids = array_values(array_unique(array_filter($ids, fn ($id) => $id !== null)));
            sort($ids, SORT_NUMERIC);
            $result[$class] = [];
            foreach ($ids as $id) {
                $query = $class::query();

                $row = $exclusive
                    ? $query->lockForUpdate()->findOrFail($id)
                    : $query->sharedLock()->findOrFail($id);
                $result[$class][$id] = $row;
                if ($class === FunctionalPositionLevel::class) {
                    $positionIds[] = $row->functional_position_id;
                    $levelIds[] = $row->functional_level_id;
                } elseif ($class === FunctionalLevel::class) {
                    $categoryIds[] = $row->functional_category_id;
                }
            }
        }

        return $result;
    }
}
