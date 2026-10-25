<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\Rules\ConversionRuleValues;
use App\Models\AkCoefficientRule;
use App\Models\FunctionalCategory;
use App\Models\FunctionalLevel;
use App\Models\PakRuleSet;
use App\Models\PerformancePredicateRule;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConversionRuleModelTest extends TestCase
{
    use RefreshDatabase;

    private function rule(string $version = '1'): PakRuleSet
    {
        $actor = User::factory()->create();

        return PakRuleSet::create(['code' => 'SYNTHETIC', 'name' => 'Fixture', 'version' => $version, 'status' => 'DRAFT', 'created_by' => $actor->id, 'updated_by' => $actor->id]);
    }

    private function level(string $code = 'LEVEL', bool $active = true): FunctionalLevel
    {
        $category = FunctionalCategory::firstOrCreate(['code' => 'SYNTHETIC'], ['name' => 'Synthetic']);

        return FunctionalLevel::create(['functional_category_id' => $category->id, 'code' => $code, 'name' => 'Synthetic', 'is_active' => $active]);
    }

    private function predicate(PakRuleSet $parent, array $changes = []): PerformancePredicateRule
    {
        return PerformancePredicateRule::create(array_replace(['pak_rule_set_id' => $parent->id, 'predicate_code' => 'Baik', 'predicate_name' => 'Baik', 'predicate_factor' => '1'], $changes));
    }

    private function coefficient(PakRuleSet $parent, array $changes = []): AkCoefficientRule
    {
        return AkCoefficientRule::create(array_replace(['pak_rule_set_id' => $parent->id, 'functional_level_id' => $this->level()->id, 'annual_coefficient' => '37.5'], $changes));
    }

    private function rejected(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Expected model validation.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey($field, $error->errors());
        }
    }

    public function test_predicate_canonical_code_cast_relationships_and_unused_edits(): void
    {
        $parent = $this->rule();
        $row = $this->predicate($parent, ['predicate_code' => "\u{00a0}BaIk\t", 'predicate_factor' => '1.5000']);
        $this->assertSame('BaIk', $row->fresh()->predicate_code);
        $this->assertSame('1.5000', $row->fresh()->predicate_factor);
        $this->assertTrue($row->ruleSet->is($parent));
        $this->assertTrue($parent->performancePredicateRules->sole()->is($row));
        $row->refresh()->update(['predicate_code' => 'BAIK', 'predicate_name' => 'Corrected label', 'predicate_factor' => '0.75']);
        $this->assertSame('BAIK', $row->fresh()->predicate_code);
        $this->assertSame('Corrected label', $row->fresh()->predicate_name);
        $this->assertSame('0.7500', $row->fresh()->predicate_factor);
        // Label-only updates also work with SQLite's numeric hydration.
        $row->refresh()->update(['predicate_name' => 'Another label']);
        $this->assertSame('0.7500', $row->fresh()->predicate_factor);
        $this->assertNull($parent->fresh()->first_used_at);
        $this->assertDatabaseCount('pak_events', 0);
    }

    public function test_coefficient_level_can_be_corrected_to_inactive_master_before_use(): void
    {
        $parent = $this->rule();
        $row = $this->coefficient($parent);
        $level = $this->level('CORRECTED', false);
        $row->refresh()->update(['functional_level_id' => $level->id, 'annual_coefficient' => '3.75']);
        $this->assertSame('3.750000', $row->fresh()->annual_coefficient);
        $this->assertTrue($row->fresh()->functionalLevel->is($level));
        $this->assertTrue($row->ruleSet->is($parent));
        $this->assertTrue($parent->akCoefficientRules->sole()->is($row));
        $row->refresh()->update(['functional_level_id' => $this->level('THIRD')->id]);
        $this->assertSame('3.750000', $row->fresh()->annual_coefficient);
    }

    public static function invalidNumbers(): array
    {
        $result = [];
        foreach (['predicate_factor', 'annual_coefficient'] as $field) {
            foreach ([null, 1, 1.5, true, [], '', '1e0', '1,5', ' 1', '1 ', "1\t", '1 0', '-1', '+1', '0', '0.0000', 'NaN', 'Infinity', '.5', '1.', '١'] as $index => $value) {
                $result[$field.'-'.$index] = [$field, $value];
            }
        }
        $result['factor-overflow'] = ['predicate_factor', '10000'];
        $result['factor-scale'] = ['predicate_factor', '1.00001'];
        $result['factor-extra-zero-scale'] = ['predicate_factor', '1.00000'];
        $result['coefficient-overflow'] = ['annual_coefficient', '1000000000'];
        $result['coefficient-scale'] = ['annual_coefficient', '1.0000001'];

        return $result;
    }

    #[DataProvider('invalidNumbers')]
    public function test_invalid_numeric_inputs_rejected_before_casting(string $field, mixed $value): void
    {
        $class = $field === 'predicate_factor' ? PerformancePredicateRule::class : AkCoefficientRule::class;
        $this->rejected(fn () => new $class([$field => $value]), $field);
    }

    public function test_exact_string_schema_boundaries_without_math(): void
    {
        foreach (['1' => '1.0000', '1.0' => '1.0000', '1.5000' => '1.5000', '0.75' => '0.7500', '0001.50' => '1.5000', '9999.9999' => '9999.9999', '0.0001' => '0.0001'] as $input => $expected) {
            $this->assertSame($expected, ConversionRuleValues::predicateFactor((string) $input));
        }
        foreach (['50' => '50.000000', '37.5' => '37.500000', '12.5' => '12.500000', '3.75' => '3.750000', '999999999.999999' => '999999999.999999', '0.000001' => '0.000001'] as $input => $expected) {
            $this->assertSame($expected, ConversionRuleValues::annualCoefficient((string) $input));
        }
        $this->assertSame('Ba  ik', ConversionRuleValues::predicateCode(' Ba  ik '));
        $this->assertSame('é', ConversionRuleValues::predicateCode(' é '));
    }

    public static function invalidCodes(): array
    {
        return [[null], [12], [''], [" \t\u{00a0}"], ["\xff"], [str_repeat('x', 51)]];
    }

    #[DataProvider('invalidCodes')]
    public function test_invalid_code_rejected(mixed $value): void
    {
        $this->rejected(fn () => $this->predicate($this->rule(), ['predicate_code' => $value]), 'predicate_code');
        $this->assertDatabaseCount('performance_predicate_rules', 0);
    }

    public function test_invalid_label_and_missing_level_are_rejected(): void
    {
        $parent = $this->rule();
        foreach ([null, '', "\u{00a0}", str_repeat('x', 101), "\xff"] as $value) {
            $this->rejected(fn () => $this->predicate($parent, ['predicate_name' => $value]), 'predicate_name');
        }
        $this->rejected(fn () => AkCoefficientRule::create(['pak_rule_set_id' => $parent->id, 'functional_level_id' => 999999, 'annual_coefficient' => '25']), 'functional_level_id');
    }

    public static function childTypes(): array
    {
        return [['predicate'], ['coefficient']];
    }

    #[DataProvider('childTypes')]
    public function test_parent_reassignment_and_deletion_always_rejected(string $type): void
    {
        $parent = $this->rule();
        $other = $this->rule('2');
        $row = $this->$type($parent);
        $this->rejected(fn () => $row->update(['pak_rule_set_id' => $other->id]), 'pak_rule_set_id');
        $this->assertEquals($parent->id, $row->fresh()->pak_rule_set_id);
        $this->rejected(fn () => $row->fresh()->delete(), 'record');
        $parent->forceFill(['first_used_at' => now()])->save();
        $this->rejected(fn () => $row->fresh()->delete(), 'record');
        $this->assertNotNull($row->fresh());
    }

    #[DataProvider('childTypes')]
    public function test_used_parent_rejects_creation_and_every_update_including_stale_relation(string $type): void
    {
        $parent = $this->rule();
        $row = $this->$type($parent);
        $row->load('ruleSet');
        $this->assertNull($row->ruleSet->first_used_at);
        $before = $row->fresh()->getAttributes();
        $parent->fresh()->forceFill(['first_used_at' => now()])->save();
        $other = $this->rule('2');
        $changes = $type === 'predicate'
            ? [['predicate_code' => 'CORRECTED'], ['predicate_name' => 'Corrected'], ['predicate_factor' => '1.5']]
            : [['functional_level_id' => $this->level('CORRECTED')->id], ['annual_coefficient' => '25']];
        $changes[] = ['pak_rule_set_id' => $other->id];
        $changes[] = ['updated_at' => now()->addDay()];
        foreach ($changes as $change) {
            $stale = clone $row;
            $this->rejected(fn () => $stale->fill($change)->save(), 'pak_rule_set_id');
            $this->assertSame($before, $row->fresh()->getAttributes());
        }
        $data = $before;
        unset($data['id'], $data['created_at'], $data['updated_at']);
        $field = $type === 'predicate' ? 'predicate_factor' : 'annual_coefficient';
        $data[$field] = '1';
        $class = $row::class;
        $this->rejected(fn () => $class::create($data), 'pak_rule_set_id');
    }

    #[DataProvider('childTypes')]
    public function test_unknown_or_missing_parent_rejected(string $type): void
    {
        $parent = $this->rule();
        $row = $this->$type($parent);
        $parent->update(['status' => 'UNSUPPORTED']);
        $this->rejected(fn () => $row->touch(), 'pak_rule_set_id');
        $data = $row->getAttributes();
        unset($data['id']);
        $data['pak_rule_set_id'] = 999999;
        $data[$type === 'predicate' ? 'predicate_factor' : 'annual_coefficient'] = '1';
        $class = $row::class;
        $this->rejected(fn () => $class::create($data), 'pak_rule_set_id');
    }

    #[DataProvider('childTypes')]
    public function test_duplicate_create_and_key_update_leave_persisted_children_unchanged(string $type): void
    {
        $parent = $this->rule();
        $first = $this->$type($parent);
        $key = $type === 'predicate' ? 'predicate_code' : 'functional_level_id';
        $valueField = $type === 'predicate' ? 'predicate_factor' : 'annual_coefficient';
        $class = $first::class;
        $data = $first->getAttributes();
        unset($data['id'], $data['created_at'], $data['updated_at']);
        $data[$valueField] = '1';
        try {
            $class::create($data);
            $this->fail('Expected unique violation');
        } catch (QueryException) {
            $this->assertSame(1, $class::count());
        }
        $data[$key] = $type === 'predicate' ? 'OTHER' : $this->level('OTHER')->id;
        $second = $class::create($data);
        $before = $second->fresh()->getAttributes();
        try {
            $second->update([$key => $first->$key, $valueField => '2']);
            $this->fail('Expected unique violation');
        } catch (QueryException) {
            $this->assertSame($before, $second->fresh()->getAttributes());
            $this->assertSame(2, $class::count());
        }
        $this->assertDatabaseCount('pak_events', 0);
    }
}
