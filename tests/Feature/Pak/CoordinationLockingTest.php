<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\Services\EmployeeHistoryService;
use App\Domain\Pak\Services\FunctionalMasterService;
use App\Domain\Pak\Services\PakCaseService;
use App\Domain\Pak\Services\SharedMasterLocks;
use App\Models\EmployeePositionHistory;
use App\Models\FunctionalCategory;
use App\Models\FunctionalLevel;
use App\Models\FunctionalPosition;
use App\Models\FunctionalPositionLevel;
use App\Models\PakConversionRun;
use App\Models\PakEvent;
use App\Models\Person;
use App\Support\Database\PersonLocks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CoordinationLockingTest extends PakTestCase
{
    private function observe(callable $action): array
    {
        $scopes = Model::getAllGlobalScopes();
        $locks = [];
        foreach ([Person::class, FunctionalPositionLevel::class, FunctionalPosition::class, FunctionalLevel::class, FunctionalCategory::class, EmployeePositionHistory::class] as $class) {
            $class::addGlobalScope('coordination-probe', function ($builder) use (&$locks, $class) {
                $builder->getQuery()->beforeQuery(function ($q) use (&$locks, $class) {
                    if ($q->lock !== null) {
                        $this->assertGreaterThan(0, DB::transactionLevel());
                        $locks[] = [$class, $q->lock, $q->getBindings()];
                    }
                });
            });
        }
        try {
            $action();
        } finally {
            Model::setAllGlobalScopes($scopes);
        }

        return $locks;
    }

    public function test_person_set_is_deduplicated_and_locked_in_primary_key_order(): void
    {
        $other = Person::factory()->create();
        $locks = $this->observe(function () use ($other) {
            $before = DB::transactionLevel();
            $people = PersonLocks::acquire([(string) $other->id, $this->person->id, $other->id]);
            $this->assertSame([$this->person->id, $other->id], $people->keys()->all());
            $this->assertSame($before, DB::transactionLevel());
        });
        $this->assertSame([$this->person->id, $other->id], array_map(fn ($lock) => (int) $lock[2][0], $locks));
    }

    public function test_invalid_person_ids_fail_before_any_lock(): void
    {
        foreach ([null, true, 1.5, 0, -1, '1 OR 1=1'] as $invalid) {
            $locks = $this->observe(function () use ($invalid) {
                try {
                    PersonLocks::acquire([$this->person->id, $invalid]);
                    $this->fail('Invalid ID accepted');
                } catch (\LogicException $e) {
                    $this->assertSame('Person coordination requires positive integer IDs.', $e->getMessage());
                }
            });
            $this->assertSame([], $locks);
        }
    }

    public function test_history_and_case_capture_lock_masters_before_histories_without_conversion_side_effects(): void
    {
        $service = app(FunctionalMasterService::class);
        $position = $service->save($this->manager, 'position', ['name' => 'Synthetic', 'code' => 'SYNTH', 'is_active' => true]);
        $level = FunctionalLevel::firstOrFail();
        $mapping = $service->save($this->manager, 'mapping', ['functional_position_id' => $position->id, 'functional_level_id' => $level->id, 'is_active' => true]);
        $locks = $this->observe(fn () => app(EmployeeHistoryService::class)->record($this->manager, $this->person, [
            'position_name' => 'Synthetic', 'position_type' => 'JF', 'functional_position_id' => $position->id,
            'functional_level_id' => $level->id, 'tmt_start' => '2020-01-01', 'source_ref' => 'Synthetic SK',
        ], true));
        $classes = array_values(array_unique(array_column($locks, 0)));
        $this->assertSame([Person::class, FunctionalPositionLevel::class, FunctionalPosition::class, FunctionalLevel::class, FunctionalCategory::class, EmployeePositionHistory::class], $classes);
        $this->assertSame(0, PakConversionRun::count());
        $this->assertSame(1, PakEvent::where('event_code', 'HISTORY_VERIFIED')->count());
        // Historical capture does not impose current master eligibility.
        $level->update(['is_active' => false]);
        $case = null;
        $locks = $this->observe(function () use (&$case) {
            $case = app(PakCaseService::class)->create($this->manager, $this->caseInput());
        });
        $this->assertSame([Person::class, FunctionalPosition::class, FunctionalLevel::class, FunctionalCategory::class, EmployeePositionHistory::class], array_values(array_unique(array_column($locks, 0))));
        $this->assertSame($level->name, $case->employeeSnapshot->functional_level_name);
        $this->assertSame(0, PakConversionRun::count());
        $this->assertSame(0, PakEvent::where('event_code', 'like', 'CONVERSION_RUN_%')->count());
    }

    public function test_shared_master_ids_are_sorted_and_only_relevant_rows_are_locked(): void
    {
        $levels = FunctionalLevel::orderBy('id')->limit(2)->get();
        $locks = $this->observe(fn () => app(SharedMasterLocks::class)->acquire(levelIds: $levels->reverse()->modelKeys()));
        $levelLocks = array_values(array_filter($locks, fn ($l) => $l[0] === FunctionalLevel::class));
        $this->assertSame($levels->modelKeys(), array_map(fn ($l) => (int) $l[2][0], $levelLocks));
        $this->assertNotContains(Person::class, array_column($locks, 0));
        $this->assertSame([FunctionalLevel::class, FunctionalCategory::class], array_values(array_unique(array_column($locks, 0))));
        foreach ($locks as $lock) {
            $this->assertFalse($lock[1]);
        }
    }
}
