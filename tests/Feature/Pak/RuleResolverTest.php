<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\DTOs\ResolvedCoefficientRule;
use App\Domain\Pak\DTOs\ResolvedPredicateRule;
use App\Domain\Pak\Exceptions\ConversionRuleException;
use App\Domain\Pak\Services\RuleResolver;
use App\Models\AkCoefficientRule;
use App\Models\FunctionalCategory;
use App\Models\FunctionalLevel;
use App\Models\PakEvent;
use App\Models\PakRuleSet;
use App\Models\PerformancePredicateRule;
use App\Models\User;
use Error;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RuleResolverTest extends TestCase
{
    use RefreshDatabase;

    private function rule(string $version = '1'): PakRuleSet
    {
        $actor = User::factory()->create();

        return PakRuleSet::create(['code' => 'SYNTHETIC', 'name' => 'Synthetic', 'version' => $version,
            'status' => 'DRAFT', 'created_by' => $actor->id, 'updated_by' => $actor->id]);
    }

    private function predicate(PakRuleSet $rule, string $code = 'BAIK', string $factor = '1.50'): PerformancePredicateRule
    {
        return $rule->performancePredicateRules()->create(['predicate_code' => $code, 'predicate_name' => 'Human label', 'predicate_factor' => $factor]);
    }

    private function coefficient(PakRuleSet $rule, string $value = '37.5'): AkCoefficientRule
    {
        $category = FunctionalCategory::firstOrCreate(['code' => 'SYNTHETIC'], ['name' => 'Synthetic']);
        $level = FunctionalLevel::create(['functional_category_id' => $category->id, 'code' => 'LEVEL-'.$rule->id,
            'name' => 'Synthetic', 'is_active' => false]);

        return $rule->akCoefficientRules()->create(['functional_level_id' => $level->id, 'annual_coefficient' => $value]);
    }

    private function rejected(callable $call, string $code): void
    {
        try {
            $call();
            $this->fail('Expected resolver rejection.');
        } catch (ConversionRuleException $error) {
            $this->assertSame($code, $error->issue_code);
            $this->assertSame($code, $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
    }

    public static function usage(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('usage')]
    public function test_explicit_unused_and_used_draft_resolve_without_any_mutation(bool $used): void
    {
        $rule = $this->rule();
        $predicate = $this->predicate($rule);
        $coefficient = $this->coefficient($rule);
        if ($used) {
            // Controlled lifecycle fixture; resolver must never mark or clear use.
            $rule->forceFill(['first_used_at' => now()])->save();
        }
        $before = [$rule->fresh()->getAttributes(), $predicate->fresh()->getAttributes(), $coefficient->fresh()->getAttributes()];
        $queries = [];
        DB::listen(function ($event) use (&$queries) {
            $queries[] = $event->sql;
        });
        $this->travel(1)->days();
        $resolver = app(RuleResolver::class);
        $p = $resolver->resolvePredicate($rule->id, 'BAIK');
        $c = $resolver->resolveCoefficient($rule->id, $coefficient->functional_level_id);
        $this->assertEquals(new ResolvedPredicateRule($rule->id, $predicate->id, 'BAIK', 'Human label', '1.5000'), $p);
        $this->assertEquals(new ResolvedCoefficientRule($rule->id, $coefficient->id, $coefficient->functional_level_id, '37.500000'), $c);
        $this->assertIsString($p->predicateFactor);
        $this->assertIsString($c->annualCoefficient);
        $this->assertFalse($coefficient->functionalLevel->is_active);
        foreach ($queries as $sql) {
            $this->assertStringStartsWith('select', strtolower($sql));
            $this->assertStringNotContainsString('for update', strtolower($sql));
        }
        $this->assertSame($before, [$rule->fresh()->getAttributes(), $predicate->fresh()->getAttributes(), $coefficient->fresh()->getAttributes()]);
        $this->assertSame(0, PakEvent::count());
        foreach ([[$p, 'predicateFactor'], [$c, 'annualCoefficient']] as [$dto, $field]) {
            try {
                $dto->$field = '9';
                $this->fail('DTO must be immutable.');
            } catch (Error $error) {
                $this->assertStringContainsString('readonly', $error->getMessage());
            }
        }
    }

    public function test_case_sensitive_codes_are_distinct_and_no_alias_or_label_fallback(): void
    {
        $rule = $this->rule();
        $resolver = app(RuleResolver::class);
        $this->predicate($rule, 'BAIK', '1');
        $this->rejected(fn () => $resolver->resolvePredicate($rule->id, 'Baik'), 'PREDICATE_RULE_NOT_FOUND');
        $this->predicate($rule, 'Baik', '0.75');
        $this->predicate($rule, 'baik', '0.50');
        foreach (['BAIK' => '1.0000', 'Baik' => '0.7500', 'baik' => '0.5000'] as $code => $factor) {
            $this->assertSame($factor, $resolver->resolvePredicate($rule->id, $code)->predicateFactor);
        }
        $this->predicate($rule, 'CUKUP');
        foreach (['BUTUH_PERBAIKAN', 'Human label', 'MISSING'] as $code) {
            $this->rejected(fn () => $resolver->resolvePredicate($rule->id, $code), 'PREDICATE_RULE_NOT_FOUND');
        }
    }

    public function test_no_other_rule_set_latest_or_date_fallback(): void
    {
        $one = $this->rule();
        $two = $this->rule('2');
        $this->predicate($one);
        $coefficient = $this->coefficient($one);
        $resolver = app(RuleResolver::class);
        $this->rejected(fn () => $resolver->resolvePredicate($two->id, 'BAIK'), 'PREDICATE_RULE_NOT_FOUND');
        $this->rejected(fn () => $resolver->resolveCoefficient($two->id, $coefficient->functional_level_id), 'COEFFICIENT_RULE_NOT_FOUND');
        $this->rejected(fn () => $resolver->resolveCoefficient($one->id, 999999), 'COEFFICIENT_RULE_NOT_FOUND');
        $this->predicate($two, 'BAIK', '0.25');
        $this->assertSame('1.5000', $resolver->resolvePredicate($one->id, 'BAIK')->predicateFactor);
        $this->assertSame('0.2500', $resolver->resolvePredicate($two->id, 'BAIK')->predicateFactor);
    }

    public function test_missing_and_corrupt_rule_set_status(): void
    {
        $resolver = app(RuleResolver::class);
        $this->rejected(fn () => $resolver->resolvePredicate(999999, 'BAIK'), 'RULE_SET_NOT_FOUND');
        $this->rejected(fn () => $resolver->resolveCoefficient(999999, 1), 'RULE_SET_NOT_FOUND');
        $rule = $this->rule();
        $this->predicate($rule);
        $coefficient = $this->coefficient($rule);
        // Corruption fixture only, rolled back by RefreshDatabase; no new status contract.
        DB::table('pak_rule_sets')->where('id', $rule->id)->update(['status' => 'CORRUPT']);
        $this->rejected(fn () => $resolver->resolvePredicate($rule->id, 'BAIK'), 'RULE_SET_NOT_USABLE');
        $this->rejected(fn () => $resolver->resolveCoefficient($rule->id, $coefficient->functional_level_id), 'RULE_SET_NOT_USABLE');
    }

    public static function invalidInputs(): array
    {
        return [['predicate', ' BAIK'], ['predicate', "BAIK\t"], ['predicate', ''], ['predicate', "\u{00a0}"],
            ['predicate', "\xFF"], ['predicate', 123], ['predicate', null], ['predicate', str_repeat('A', 51)],
            ['coefficient', 0], ['coefficient', -1], ['coefficient', '1'], ['coefficient', 1.0], ['coefficient', true], ['coefficient', null]];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_or_noncanonical_input_is_not_repaired(string $kind, mixed $input): void
    {
        $rule = $this->rule();
        $this->predicate($rule);
        $this->rejected(fn () => app(RuleResolver::class)->{'resolve'.ucfirst($kind)}($rule->id, $input), 'INVALID_RULE_INPUT');
    }

    public function test_noncanonical_rule_set_ids_are_rejected(): void
    {
        foreach ([null, '1', 1.0, 0, -1, true] as $id) {
            $this->rejected(fn () => app(RuleResolver::class)->resolvePredicate($id, 'BAIK'), 'INVALID_RULE_INPUT');
            $this->rejected(fn () => app(RuleResolver::class)->resolveCoefficient($id, 1), 'INVALID_RULE_INPUT');
        }
    }

    public static function corruptNumbers(): array
    {
        return [['predicate', 'not-a-number'], ['predicate', '0'], ['predicate', '-1'], ['predicate', '1.50001'], ['predicate', '10000'],
            ['coefficient', 'not-a-number'], ['coefficient', '0'], ['coefficient', '-1'], ['coefficient', '37.5000001'], ['coefficient', '1000000000']];
    }

    #[DataProvider('corruptNumbers')]
    public function test_raw_persisted_corruption_is_rejected_before_decimal_cast_can_round(string $kind, string $value): void
    {
        $rule = $this->rule();
        $row = $kind === 'predicate' ? $this->predicate($rule) : $this->coefficient($rule);
        $field = $kind === 'predicate' ? 'predicate_factor' : 'annual_coefficient';
        // SQLite permits corruption beyond DECIMAL declarations; no constraint is removed.
        DB::table($row->getTable())->where('id', $row->id)->update([$field => $value]);
        $this->rejected(fn () => app(RuleResolver::class)->{'resolve'.ucfirst($kind)}($rule->id,
            $kind === 'predicate' ? 'BAIK' : $row->functional_level_id), 'RULE_INTEGRITY_ERROR');
    }

    public static function boundaries(): array
    {
        return [['predicate', '0.0001', '0.0001'], ['predicate', '9999.9999', '9999.9999'],
            ['coefficient', '0.000001', '0.000001'], ['coefficient', '999999999.999999', '999999999.999999']];
    }

    #[DataProvider('boundaries')]
    public function test_schema_decimal_boundaries_remain_exact_strings(string $kind, string $value, string $expected): void
    {
        $rule = $this->rule();
        $row = $kind === 'predicate' ? $this->predicate($rule, factor: $value) : $this->coefficient($rule, $value);
        $dto = app(RuleResolver::class)->{'resolve'.ucfirst($kind)}($rule->id, $kind === 'predicate' ? 'BAIK' : $row->functional_level_id);
        $this->assertSame($expected, $kind === 'predicate' ? $dto->predicateFactor : $dto->annualCoefficient);
    }

    public function test_corrupt_label_is_rejected(): void
    {
        $rule = $this->rule();
        $row = $this->predicate($rule);
        DB::table($row->getTable())->where('id', $row->id)->update(['predicate_name' => ' ']);
        $this->rejected(fn () => app(RuleResolver::class)->resolvePredicate($rule->id, 'BAIK'), 'RULE_INTEGRITY_ERROR');
    }

    public static function kinds(): array
    {
        return [['predicate', PerformancePredicateRule::class], ['coefficient', AkCoefficientRule::class]];
    }

    #[DataProvider('kinds')]
    public function test_mismatched_retrieved_ownership_is_rejected(string $kind, string $model): void
    {
        $rule = $this->rule();
        $other = $this->rule('2');
        $row = $kind === 'predicate' ? $this->predicate($rule) : $this->coefficient($rule);
        $dispatcher = Model::getEventDispatcher();
        Model::setEventDispatcher(clone $dispatcher);
        // Simulate inconsistent hydration, without weakening FK/unique constraints.
        $model::retrieved(function (Model $record) use ($other) {
            $record->setRawAttributes(array_replace($record->getAttributes(), ['pak_rule_set_id' => $other->id]), true);
        });
        try {
            $this->rejected(fn () => app(RuleResolver::class)->{'resolve'.ucfirst($kind)}($rule->id,
                $kind === 'predicate' ? 'BAIK' : $row->functional_level_id), 'RULE_INTEGRITY_ERROR');
        } finally {
            Model::setEventDispatcher($dispatcher);
        }
    }

    public function test_corrupt_retrieved_predicate_code_is_not_repaired(): void
    {
        $rule = $this->rule();
        $this->predicate($rule);
        $dispatcher = Model::getEventDispatcher();
        Model::setEventDispatcher(clone $dispatcher);
        PerformancePredicateRule::retrieved(function (Model $record) {
            $record->setRawAttributes(array_replace($record->getAttributes(), ['predicate_code' => ' BAIK ']), true);
        });
        try {
            $this->rejected(fn () => app(RuleResolver::class)->resolvePredicate($rule->id, 'BAIK'), 'RULE_INTEGRITY_ERROR');
        } finally {
            Model::setEventDispatcher($dispatcher);
        }
    }

    #[DataProvider('kinds')]
    public function test_duplicate_result_guard_without_removing_unique_constraints(string $kind, string $model): void
    {
        $rule = $this->rule();
        $row = $kind === 'predicate' ? $this->predicate($rule) : $this->coefficient($rule);
        $scopes = $model::getAllGlobalScopes();
        // Test-only duplicate query results. The physical unique constraints stay intact.
        $model::addGlobalScope('duplicate-result-fixture', function (Builder $query) {
            $query->unionAll(clone $query->getQuery());
        });
        try {
            $this->rejected(fn () => app(RuleResolver::class)->{'resolve'.ucfirst($kind)}($rule->id,
                $kind === 'predicate' ? 'BAIK' : $row->functional_level_id), 'RULE_INTEGRITY_ERROR');
        } finally {
            $model::setAllGlobalScopes($scopes);
        }
    }
}
