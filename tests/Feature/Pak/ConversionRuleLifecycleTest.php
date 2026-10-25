<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\Services\ConversionRuleService;
use App\Domain\Pak\Services\PakCaseService;
use App\Domain\Pak\Services\PakRuleSetService;
use App\Models\FunctionalCategory;
use App\Models\FunctionalLevel;
use App\Models\PakEvent;
use App\Models\PakRuleSet;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ConversionRuleLifecycleTest extends PakTestCase
{
    private function rule(string $version = '1'): PakRuleSet
    {
        return app(PakRuleSetService::class)->save($this->manager, ['code' => 'SYNTHETIC', 'name' => 'Fixture', 'version' => $version]);
    }

    private function data(string $kind, string $key = 'A'): array
    {
        if ($kind === 'predicate') {
            return ['predicate_code' => $key, 'predicate_name' => 'Synthetic', 'predicate_factor' => '1.50'];
        }
        $category = FunctionalCategory::firstOrCreate(['code' => 'SYNTHETIC'], ['name' => 'Synthetic']);
        $level = FunctionalLevel::firstOrCreate(['functional_category_id' => $category->id, 'code' => $key], ['name' => 'Synthetic', 'is_active' => false]);

        return ['functional_level_id' => $level->id, 'annual_coefficient' => '37.5'];
    }

    private function save(string $kind, PakRuleSet $rule, array $data, ?int $id = null, ?User $actor = null): mixed
    {
        return app(ConversionRuleService::class)->{'save'.ucfirst($kind)}($actor ?? $this->manager, $rule->id, $data, $id);
    }

    private function rejects(callable $call, string $code): void
    {
        try {
            $call();
            $this->fail('Expected validation rejection.');
        } catch (ValidationException $error) {
            $this->assertContains($code, array_merge(...array_values($error->errors())));
        }
    }

    public static function kinds(): array
    {
        return [['predicate'], ['coefficient']];
    }

    #[DataProvider('kinds')]
    public function test_create_and_update_keys_values_audit_and_actor_are_atomic(string $kind): void
    {
        $rule = $this->rule();
        $actor = User::factory()->create()->givePermissionTo(['pak.access', 'pak.rule-set.manage']);
        $child = $this->save($kind, $rule, $this->data($kind), actor: $actor);
        $fields = ['pak_rule_set_id', ...array_keys($this->data($kind))];
        $created = PakEvent::where('event_code', strtoupper($kind).'_RULE_CREATED')->sole();
        $this->assertSame(class_basename($child), $created->subject_type);
        $this->assertEquals($child->id, $created->subject_id);
        $this->assertSame(['before' => null, 'after' => $child->only($fields)], $created->payload);
        $this->assertEquals($actor->id, $rule->fresh()->updated_by);
        $before = $child->only($fields);
        $data = $this->data($kind, 'b');
        if ($kind === 'predicate') {
            $data['predicate_name'] = 'Corrected';
            $data['predicate_factor'] = '0.75';
        } else {
            $data['annual_coefficient'] = '3.75';
        }
        $updated = $this->save($kind, $rule, $data, $child->id);
        $audit = PakEvent::where('event_code', strtoupper($kind).'_RULE_UPDATED')->sole();
        $this->assertSame(['before' => $before, 'after' => $updated->only($fields)], $audit->payload);
        $this->assertEquals($this->manager->id, $rule->fresh()->updated_by);
        $this->assertEquals($child->id, $updated->id);
        $this->assertNull($rule->fresh()->first_used_at);
    }

    #[DataProvider('kinds')]
    public function test_ownership_missing_ids_and_payload_whitelist(string $kind): void
    {
        $rule = $this->rule();
        $other = $this->rule('2');
        $data = $this->data($kind);
        $child = $this->save($kind, $rule, $data);
        $this->rejects(fn () => $this->save($kind, $other, $data, $child->id), 'RULE_CHILD_PARENT_MISMATCH');
        $this->rejects(fn () => $this->save($kind, $rule, $data, 999999), 'RULE_CHILD_NOT_FOUND');
        $missing = new PakRuleSet;
        $missing->id = 999999;
        $this->rejects(fn () => $this->save($kind, $missing, $data), 'RULE_SET_NOT_FOUND');
        $this->rejects(fn () => $this->save($kind, $rule, $data + ['pak_rule_set_id' => $other->id], $child->id), 'INVALID_RULE_INPUT');
        $this->assertEquals($rule->id, $child->fresh()->pak_rule_set_id);
    }

    #[DataProvider('kinds')]
    public function test_case_first_use_blocks_create_and_stale_update(string $kind): void
    {
        $rule = $this->rule();
        $child = $this->save($kind, $rule, $this->data($kind));
        app(PakCaseService::class)->create($this->manager, array_replace($this->caseInput(), ['pak_rule_set_id' => $rule->id]));
        $before = $child->fresh()->getAttributes();
        $this->rejects(fn () => $this->save($kind, $rule, $this->data($kind, 'B')), 'RULE_SET_NOT_EDITABLE');
        $this->rejects(fn () => $this->save($kind, $rule, $this->data($kind, 'B'), $child->id), 'RULE_SET_NOT_EDITABLE');
        $this->assertSame($before, $child->fresh()->getAttributes());
        $this->assertNotNull($rule->fresh()->first_used_at);
    }

    #[DataProvider('kinds')]
    public function test_collision_and_audit_failure_restore_child_parent_and_audit(string $kind): void
    {
        $rule = $this->rule();
        $one = $this->save($kind, $rule, $this->data($kind));
        $two = $this->save($kind, $rule, $this->data($kind, 'B'));
        $actor = User::factory()->create()->assignRole('Admin PAK');
        $parentBefore = $rule->fresh()->getAttributes();
        $childBefore = $two->fresh()->getAttributes();
        $count = PakEvent::count();
        $this->rejects(fn () => $this->save($kind, $rule, $this->data($kind), actor: $actor), 'RULE_CHILD_CONFLICT');
        $this->rejects(fn () => $this->save($kind, $rule, $this->data($kind), $two->id, $actor), 'RULE_CHILD_CONFLICT');
        $this->assertSame($childBefore, $two->fresh()->getAttributes());
        $this->assertSame($parentBefore, $rule->fresh()->getAttributes());
        $this->assertSame($count, PakEvent::count());
        PakEvent::creating(fn () => throw new RuntimeException('Synthetic audit failure'));
        foreach ([null, $two->id] as $id) {
            try {
                $this->save($kind, $rule, $this->data($kind, 'C'), $id, $actor);
                $this->fail('Expected audit failure.');
            } catch (RuntimeException $error) {
                $this->assertSame('Synthetic audit failure', $error->getMessage());
            }
            $this->assertSame($childBefore, $two->fresh()->getAttributes());
            $this->assertSame($parentBefore, $rule->fresh()->getAttributes());
            $this->assertSame(2, $one->newQuery()->count());
            $this->assertSame($count, PakEvent::count());
        }
    }

    #[DataProvider('kinds')]
    public function test_invalid_numeric_input_is_validation_not_persistence_failure(string $kind): void
    {
        $rule = $this->rule();
        $data = $this->data($kind);
        $data[$kind === 'predicate' ? 'predicate_factor' : 'annual_coefficient'] = 1.5;
        try {
            $this->save($kind, $rule, $data);
            $this->fail('Expected invalid decimal input.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey($kind === 'predicate' ? 'predicate_factor' : 'annual_coefficient', $error->errors());
        }
        $this->assertDatabaseCount($kind === 'predicate' ? 'performance_predicate_rules' : 'ak_coefficient_rules', 0);
    }

    public function test_authorization_precedes_mutation(): void
    {
        $rule = $this->rule();
        foreach ([[], ['pak.access'], ['pak.rule-set.manage'], ['pak.access', 'pak.rule-set.manage']] as $permissions) {
            $actor = User::factory()->create()->givePermissionTo($permissions);
            if (count($permissions) === 2) {
                $actor->forceFill(['is_active' => false])->save();
            }
            try {
                $this->save('predicate', $rule, $this->data('predicate'), actor: $actor);
                $this->fail('Expected authorization denial.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        $this->assertDatabaseCount('performance_predicate_rules', 0);
    }
}
