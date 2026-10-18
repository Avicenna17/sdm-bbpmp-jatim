<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\Queries\EmployeeReferenceQuery;
use App\Domain\Pak\Services\FunctionalMasterService;
use App\Domain\Pak\Services\PakCaseService;
use App\Domain\Pak\Services\PakRuleSetService;
use App\Models\FunctionalLevel;
use App\Models\PakCase;
use App\Models\PakEvent;
use App\Models\PakRuleSet;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CorrectiveReviewTest extends PakTestCase
{
    private function rule(string $version): PakRuleSet
    {
        return app(PakRuleSetService::class)->save($this->manager, ['code' => 'REVIEW', 'name' => 'Metadata', 'version' => $version]);
    }

    public function test_rule_remains_locked_after_case_switches_to_another_version(): void
    {
        $r1 = $this->rule('1');
        $r2 = $this->rule('2');
        $stale = $r1->fresh();
        $this->assertNull($r1->first_used_at);
        $this->assertNull($r2->first_used_at);
        $service = app(PakCaseService::class);
        $input = array_replace($this->caseInput(), ['pak_rule_set_id' => $r1->id]);
        $service->preview($this->manager, $input);
        $this->assertNull($r1->fresh()->first_used_at);
        $case = $service->create($this->manager, $input);
        $lockedAt = $r1->fresh()->first_used_at;
        $this->assertNotNull($lockedAt);
        $input['pak_rule_set_id'] = $r2->id;
        $preview = $service->preview($this->manager, $input);
        $service->refresh($this->manager, $case, $input, 0, $preview->fingerprint(), 'Ganti versi');
        $this->assertFalse($r1->cases()->exists());
        $this->assertNotNull($r2->fresh()->first_used_at);
        $this->assertTrue($lockedAt->eq($r1->fresh()->first_used_at));
        $this->assertFalse(Gate::forUser($this->manager)->allows('update', $r1->fresh()));
        foreach ([
            fn () => app(PakRuleSetService::class)->save($this->manager, ['code' => 'REVIEW', 'name' => 'Revisi', 'version' => '1'], $r1->id),
            fn () => $r1->fresh()->forceFill(['first_used_at' => null])->save(),
            fn () => $r1->fresh()->forceFill(['first_used_at' => now()->addDay()])->save(),
            fn () => $stale->update(['name' => 'Stale mutation']),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Permanently locked');
            } catch (ValidationException) {
                $this->assertTrue($lockedAt->eq($r1->fresh()->first_used_at));
            }
        }
        $this->assertSame('Metadata', $r1->fresh()->name);
    }

    public function test_unused_rule_marker_rolls_back_with_failed_case_transaction(): void
    {
        $rule = $this->rule('1');
        PakEvent::creating(fn () => throw new \RuntimeException('synthetic audit failure'));
        try {
            app(PakCaseService::class)->create($this->manager, array_replace($this->caseInput(), ['pak_rule_set_id' => $rule->id]));
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('synthetic audit failure', $e->getMessage());
        } finally {
            PakEvent::flushEventListeners();
        }
        $this->assertNull($rule->fresh()->first_used_at);
        $this->assertSame(0, PakCase::count());
    }

    public function test_create_and_refresh_require_rule_view_even_for_manual_ids(): void
    {
        $rule = $this->rule('1');
        $actor = User::factory()->create()->givePermissionTo(['pak.access', 'pak.employee.view', 'pak.case.create', 'pak.case.update']);
        $service = app(PakCaseService::class);
        $case = $service->create($actor, $this->caseInput());
        $input = array_replace($this->caseInput(), ['pak_rule_set_id' => $rule->id]);
        $preview = $service->preview($this->manager, $input);
        foreach ([
            fn () => $service->create($actor, $input),
            fn () => $service->refresh($actor, $case, $input, 0, $preview->fingerprint(), 'ID manual'),
            fn () => $service->preview($actor, $input),
        ] as $action) {
            try {
                $action();
                $this->fail('Rule view required');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $this->assertNull($rule->fresh()->first_used_at);
        $this->assertNull($case->fresh()->pak_rule_set_id);
        $this->assertSame(0, $case->fresh()->lock_version);
        $this->assertSame(1, PakCase::count());
    }

    public static function inactiveParents(): array
    {
        return [['position'], ['level'], ['category']];
    }

    #[DataProvider('inactiveParents')]
    public function test_active_mapping_requires_every_parent_active(string $parent): void
    {
        $service = app(FunctionalMasterService::class);
        $position = $service->save($this->manager, 'position', ['code' => 'TEST', 'name' => 'JF test', 'is_active' => true]);
        $level = FunctionalLevel::firstOrFail();
        $parents = ['position' => $position, 'level' => $level, 'category' => $level->category];
        $parents[$parent]->update(['is_active' => false]);
        $data = ['functional_position_id' => $position->id, 'functional_level_id' => $level->id, 'is_active' => true];
        try {
            $service->save($this->manager, 'mapping', $data);
            $this->fail('Inactive parent');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('is_active', $e->errors());
        }
        $data['is_active'] = false;
        $mapping = $service->save($this->manager, 'mapping', $data);
        $this->assertFalse($mapping->is_active);
        $data['is_active'] = true;
        try {
            $service->save($this->manager, 'mapping', $data, $mapping->id);
            $this->fail('Cannot activate');
        } catch (ValidationException) {
            $this->assertFalse($mapping->fresh()->is_active);
        }
        $parents[$parent]->update(['is_active' => true]);
        $service->save($this->manager, 'mapping', $data, $mapping->id);
        $this->assertTrue($mapping->fresh()->is_active);
        $parents[$parent]->update(['is_active' => false]);
        $data['is_active'] = false;
        $service->save($this->manager, 'mapping', $data, $mapping->id);
        $this->assertFalse($mapping->fresh()->is_active);
        $this->assertDatabaseHas('functional_position_levels', ['id' => $mapping->id]);
    }

    public function test_published_reference_search_uses_qualified_name(): void
    {
        $this->duk();
        $query = app(EmployeeReferenceQuery::class);
        $this->assertSame(1, $query->search($this->manager, $this->person->id, '2026-10-01', 'Nama DUK')->total());
        $this->assertSame(0, $query->search($this->manager, $this->person->id, '2026-10-01', 'Tidak cocok')->total());
    }
}
