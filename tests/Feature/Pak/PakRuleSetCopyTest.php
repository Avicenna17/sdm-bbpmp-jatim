<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\Services\ConversionRuleService;
use App\Domain\Pak\Services\PakCaseService;
use App\Domain\Pak\Services\PakRuleSetService;
use App\Models\AkCoefficientRule;
use App\Models\FunctionalCategory;
use App\Models\FunctionalLevel;
use App\Models\PakEvent;
use App\Models\PakRuleSet;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PakRuleSetCopyTest extends PakTestCase
{
    private function source(): PakRuleSet
    {
        $source = app(PakRuleSetService::class)->save($this->manager, ['code' => 'SYNTHETIC', 'name' => 'Synthetic', 'version' => 'original', 'valid_from' => '2026-01-01', 'valid_until' => '2026-12-31', 'reference' => 'Synthetic reference']);
        $category = FunctionalCategory::create(['code' => 'SYNTHETIC', 'name' => 'Synthetic']);
        foreach (['BAIK', 'baik'] as $index => $code) {
            app(ConversionRuleService::class)->savePredicate($this->manager, $source->id, ['predicate_code' => $code, 'predicate_name' => 'Synthetic', 'predicate_factor' => '1.50']);
            $level = FunctionalLevel::create(['functional_category_id' => $category->id, 'code' => 'LEVEL-'.$index, 'name' => 'Synthetic', 'is_active' => false]);
            app(ConversionRuleService::class)->saveCoefficient($this->manager, $source->id, ['functional_level_id' => $level->id, 'annual_coefficient' => '37.5']);
        }

        return $source;
    }

    public static function usage(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('usage')]
    public function test_copy_is_explicit_independent_unused_and_has_one_safe_audit(bool $used): void
    {
        $source = $this->source();
        if ($used) {
            app(PakCaseService::class)->create($this->manager, array_replace($this->caseInput(), ['pak_rule_set_id' => $source->id]));
        }
        $before = $source->fresh()->getAttributes();
        $count = PakEvent::count();
        $actor = User::factory()->create()->assignRole('Admin PAK');
        $target = app(PakRuleSetService::class)->copyVersion($actor, $source->id, 'caller-chosen-v7');
        $this->assertSame('caller-chosen-v7', $target->version);
        $this->assertSame('DRAFT', $target->status);
        $this->assertNull($target->fresh()->first_used_at);
        $this->assertEquals($actor->id, $target->created_by);
        $this->assertEquals($actor->id, $target->updated_by);
        $this->assertEquals($source->only(['code', 'name', 'valid_from', 'valid_until', 'reference']), $target->only(['code', 'name', 'valid_from', 'valid_until', 'reference']));
        foreach (['performancePredicateRules' => ['predicate_code', 'predicate_name', 'predicate_factor'], 'akCoefficientRules' => ['functional_level_id', 'annual_coefficient']] as $relation => $fields) {
            $original = $source->$relation()->orderBy('id')->get();
            $copied = $target->$relation()->orderBy('id')->get();
            $this->assertCount(2, $copied);
            foreach ($original as $i => $child) {
                $this->assertNotEquals($child->id, $copied[$i]->id);
                $this->assertSame($child->only($fields), $copied[$i]->only($fields));
                $this->assertEquals($target->id, $copied[$i]->pak_rule_set_id);
            }
        }
        $audit = PakEvent::where('event_code', 'RULE_SET_COPIED')->sole();
        $this->assertSame('PakRuleSet', $audit->subject_type);
        $this->assertEquals($target->id, $audit->subject_id);
        $this->assertSame(['after' => ['source_rule_set_id' => $source->id, 'target_rule_set_id' => $target->id, 'code' => 'SYNTHETIC', 'version' => 'caller-chosen-v7', 'status' => 'DRAFT', 'predicate_count' => 2, 'coefficient_count' => 2]], $audit->payload);
        $this->assertSame($count + 1, PakEvent::count());
        $child = $target->performancePredicateRules()->first();
        app(ConversionRuleService::class)->savePredicate($actor, $target->id, ['predicate_code' => 'EDITED', 'predicate_name' => 'Target only', 'predicate_factor' => '0.75'], $child->id);
        $this->assertSame(['BAIK', 'baik'], $source->performancePredicateRules()->orderBy('id')->pluck('predicate_code')->all());
        $this->assertSame($before, $source->fresh()->getAttributes());
        if (! $used) {
            $original = $source->akCoefficientRules()->first();
            app(ConversionRuleService::class)->saveCoefficient($actor, $source->id, ['functional_level_id' => $original->functional_level_id, 'annual_coefficient' => '5'], $original->id);
            $this->assertSame('37.500000', $target->akCoefficientRules()->first()->annual_coefficient);
        }
    }

    public function test_explicit_version_validation_missing_source_and_conflict(): void
    {
        $source = $this->source();
        foreach ([['', $source->id, null], ['original', $source->id, 'RULE_VERSION_CONFLICT'], ['new', 999999, 'RULE_SET_NOT_FOUND']] as [$version, $id, $code]) {
            $count = PakEvent::count();
            try {
                app(PakRuleSetService::class)->copyVersion($this->manager, $id, $version);
                $this->fail('Expected rejection.');
            } catch (ValidationException $error) {
                if ($code !== null) {
                    $this->assertContains($code, array_merge(...array_values($error->errors())));
                } else {
                    $this->assertArrayHasKey('version', $error->errors());
                }
            }
            $this->assertDatabaseCount('pak_rule_sets', 1);
            $this->assertSame($count, PakEvent::count());
        }
    }

    public static function failures(): array
    {
        return [['child'], ['audit']];
    }

    #[DataProvider('failures')]
    public function test_child_or_audit_failure_rolls_back_entire_copy(string $failure): void
    {
        $source = $this->source();
        $before = $source->fresh()->getAttributes();
        $count = PakEvent::count();
        if ($failure === 'child') {
            AkCoefficientRule::creating(fn () => throw new RuntimeException('Synthetic failure'));
        } else {
            PakEvent::creating(fn () => throw new RuntimeException('Synthetic failure'));
        }
        try {
            app(PakRuleSetService::class)->copyVersion($this->manager, $source->id, 'new');
            $this->fail('Expected injected failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic failure', $error->getMessage());
        }
        $this->assertDatabaseCount('pak_rule_sets', 1);
        $this->assertDatabaseCount('performance_predicate_rules', 2);
        $this->assertDatabaseCount('ak_coefficient_rules', 2);
        $this->assertSame($before, $source->fresh()->getAttributes());
        $this->assertSame($count, PakEvent::count());
    }

    public function test_copy_requires_view_as_well_as_manage_access_and_active_actor(): void
    {
        $source = $this->source();
        foreach ([['pak.access', 'pak.rule-set.manage'], ['pak.access', 'pak.rule-set.view'], ['pak.rule-set.view', 'pak.rule-set.manage'], ['pak.access', 'pak.rule-set.view', 'pak.rule-set.manage']] as $permissions) {
            $actor = User::factory()->create()->givePermissionTo($permissions);
            if (count($permissions) === 3) {
                $actor->forceFill(['is_active' => false])->save();
            }
            try {
                app(PakRuleSetService::class)->copyVersion($actor, $source->id, 'new');
                $this->fail('Expected authorization denial.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        $this->assertDatabaseCount('pak_rule_sets', 1);
    }
}
