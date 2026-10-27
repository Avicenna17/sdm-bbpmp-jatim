<?php

namespace App\Support\Database;

use App\Models\Person;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Caller owns the transaction and acquires all Persons before downstream locks. */
final class PersonLocks
{
    public static function acquire(array $ids): Collection
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Person coordination requires a transaction.');
        }
        $ids = array_map(static function ($id): int {
            if ((! is_int($id) && ! is_string($id)) || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new LogicException('Person coordination requires positive integer IDs.');
            }

            return (int) $id;
        }, $ids);
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        $people = new Collection;
        // Individual primary-key reads make acquisition order independent of the query plan.
        foreach ($ids as $id) {
            $people->put($id, Person::query()->lockForUpdate()->findOrFail($id));
        }

        return $people;
    }
}
