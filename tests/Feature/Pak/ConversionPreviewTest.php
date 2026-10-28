<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\DTOs\ConversionPreviewRequest;
use App\Domain\Pak\DTOs\ConversionRegimeEvidence;
use App\Domain\Pak\Exceptions\ConversionRuleException;
use App\Domain\Pak\Queries\ConversionDependencyReader;
use App\Domain\Pak\Services\ConversionManifestBuilder;
use App\Domain\Pak\Services\ConversionOrchestrator;
use App\Domain\Pak\Services\ConversionPreviewDependencies;
use App\Domain\Pak\Services\ConversionService;
use App\Domain\Pak\Services\PositionSegmentResolver;
use App\Models\AkCoefficientRule;
use App\Models\EmployeePositionHistory;
use App\Models\FunctionalCategory;
use App\Models\FunctionalLevel;
use App\Models\FunctionalPosition;
use App\Models\FunctionalPositionLevel;
use App\Models\PakCase;
use App\Models\PakRuleSet;
use App\Models\PerformanceEvaluation;
use App\Models\PerformancePredicateRule;
use App\Models\Person;
use App\Models\User;
use Brick\Math\BigRational;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ConversionPreviewTest extends TestCase
{
    private User $actor;

    private PakCase $case;

    private PakRuleSet $rule;

    private EmployeePositionHistory $history;

    private FunctionalLevel $level;

    private ?string $denied = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'sqlite') {
            $this->assertSame('pak_02a1_test', getenv('PAK_DISPOSABLE_DB'));
            $this->assertSame('127.0.0.1', DB::connection()->getConfig('host'));
            $this->assertSame('33318', (string) DB::connection()->getConfig('port'));
            $this->assertSame('pak_02a1_test', DB::connection()->getDatabaseName());
        }
        // Preview owns its transaction: use a fresh disposable schema without a wrapping test transaction.
        $this->artisan('migrate:fresh', ['--force' => true]);
        RefreshDatabaseState::$migrated = false;
        $this->actor = User::factory()->create(['is_active' => true]);
        Gate::before(fn ($user, $permission) => $permission !== $this->denied);
        // Entirely synthetic integration data. This is NOT Nurmaulida documentary evidence.
        $person = Person::factory()->create(['canonical_name' => 'Synthetic Preview Subject']);
        $category = FunctionalCategory::create(['code' => 'PREVIEW', 'name' => 'Synthetic']);
        $this->level = FunctionalLevel::create(['functional_category_id' => $category->id, 'code' => 'PREVIEW', 'name' => 'Synthetic']);
        $position = FunctionalPosition::create(['code' => 'PREVIEW', 'name' => 'Synthetic']);
        FunctionalPositionLevel::create(['functional_position_id' => $position->id, 'functional_level_id' => $this->level->id]);
        $this->rule = PakRuleSet::create(['code' => 'PREVIEW', 'version' => '1', 'name' => 'Synthetic', 'valid_from' => '2023-01-01', 'valid_until' => '2026-12-31', 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]);
        PerformancePredicateRule::create(['pak_rule_set_id' => $this->rule->id, 'predicate_code' => 'BAIK', 'predicate_name' => 'Synthetic', 'predicate_factor' => '1']);
        AkCoefficientRule::create(['pak_rule_set_id' => $this->rule->id, 'functional_level_id' => $this->level->id, 'annual_coefficient' => '25']);
        // Isolated fixture stands in for accepted case first-use lifecycle, never production preview behavior.
        DB::table('pak_rule_sets')->where('id', $this->rule->id)->update(['first_used_at' => '2026-01-01 00:00:00']);
        $this->case = PakCase::create(['person_id' => $person->id, 'pak_rule_set_id' => $this->rule->id, 'purpose_type' => 'KP', 'period_start' => '2023-01-01', 'period_end' => '2026-12-31', 'employee_data_as_of' => '2026-01-01', 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]);
        $this->history = EmployeePositionHistory::create(['person_id' => $person->id, 'position_name' => 'Synthetic position', 'position_type' => 'JF', 'functional_position_id' => $position->id, 'functional_level_id' => $this->level->id,
            'tmt_start' => '2023-01-01', 'sk_number' => 'SYNTHETIC-SK-001', 'sk_date' => '2022-12-15', 'source' => 'FIXTURE', 'source_ref' => 'Synthetic documentary reference', 'verified_by' => $this->actor->id, 'verified_at' => now(), 'created_by' => $this->actor->id]);
        foreach ([2023, 2024, 2025, 2026] as $year) {
            $this->evaluation($year);
        }
    }

    public static function extractionCases(): array
    {
        return array_map(fn ($case) => [$case], ['calculated', 'partial', 'validity', 'legacy', 'unproven', 'gap', 'overlap', 'missing_source', 'explicit_proof', 'missing_predicate', 'missing_coefficient']);
    }

    #[DataProvider('extractionCases')]
    public function test_pre_extraction_manifest_fingerprints_and_complete_result_parity(string $scenario): void
    {
        // Fixed synthetic documentary timestamp: capture the committed 851fb45 output, not wall time.
        DB::table('employee_position_histories')->update(['verified_at' => '2026-01-01 00:00:00']);
        $start = '2023-11-01';
        if ($scenario === 'partial') {
            $start = '2023-11-15';
        } elseif ($scenario === 'validity') {
            DB::table('pak_rule_sets')->update(['valid_until' => null]);
        } elseif ($scenario === 'legacy') {
            DB::table('employee_position_histories')->update(['tmt_start' => '2022-01-01']);
        } elseif ($scenario === 'unproven') {
            DB::table('employee_position_histories')->update(['sk_number' => null]);
        } elseif ($scenario === 'gap') {
            DB::table('employee_position_histories')->update(['tmt_end' => '2024-12-31']);
        } elseif ($scenario === 'overlap') {
            $this->history->fresh()->replicate()->save();
        } elseif ($scenario === 'missing_source') {
            DB::table('performance_evaluations')->delete();
        } elseif ($scenario === 'explicit_proof') {
            DB::table('performance_evaluations')->update(['employee_position_history_id' => $this->history->id]);
        } elseif ($scenario === 'missing_predicate') {
            DB::table('performance_predicate_rules')->delete();
        } elseif ($scenario === 'missing_coefficient') {
            DB::table('ak_coefficient_rules')->delete();
        }
        $result = $this->preview($start);
        $actual = [$result->dependency_fingerprint, $result->snapshot_fingerprint,
            hash('sha256', json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))];
        // Captured before production extraction from 851fb45 using the fixture above.
        // Third digest covers every public DTO field, including manifest bytes, issue/list order and presentation.
        $baseline = [
            'calculated' => ['73a0e710d9606b8ed16cf627811c8f5ccbaf50632335cb13b1d7efdc475a1ee7', 'f4451bf266e4948ed5ab873adb6e30c3f29a3860394e34d756c5e737d0b77c81', '763c354b54c927cd5ca4ea4dd268b0980e4c8467cc08155f559c2a61fb360ece'],
            'partial' => ['6beeec584438453ae40aae891c3fa7ea9f26f79ac0c195ff760a75b4a323d7a4', '311bafb92cd297e1a98f60df2cda2d88c3a54db00dc5696e38d0e5ec164d0d46', '079d9324a3b98f199e2074827e6231f1c5cd7e3035631da5d3290d83e4439b5a'],
            'validity' => ['411c214f2c86fd472a888ccbbd8195d13983c07fb97a237f9a4053f1316d9d96', 'c35629bc3ebfe966c783a6c75e099c0e3d7ec6ce2ba683378c2f5b2d374eda71', '44a075ad599fc50889a05389a132d44d9620a38b87fdd145002cb7536277490f'],
            'legacy' => ['1906b0fb9d7ba1e5661f68cb77127f683384580f4331604e760e4e9cf16e3e3f', 'ce80c23cf708b240e2d15f579cb183c3d56380db198f5a0076f5853feca7a6c8', '7c9c2e4853c2cd273f4a75ec3a579acb207f01913f28361dadc08fea288042f8'],
            'unproven' => ['107c7213f2469f174cb1e4ee4b57213bf0d3a0f625163a20b9e9d3507825123e', '25c58a13462ea314db50b9a78d6d48481d30b63423b2ff5720459783294b5594', '8f2b2fd911814ed1f087fc56d46b7ade099509bf3f507ab1069efa3de7bacb78'],
            'gap' => ['71a7418f86b3dde0695b5a5a8ed3ab2d1d2808d6431ad99fe26132ddc61edd62', '4235a2323694febeaf2de6175592ac3a77732ddf07642c4247b26b25aa486f3b', 'f098bacaa79d22c13e5113e80f513b8ea4b593eb1b0093cfb9de8cea13ad1923'],
            'overlap' => ['330357f04920c0c4b48c74376387b5153266e0f6d4982c6fd4d820fc7c8d250a', '939c5700b34ae5ae5daff6f68e00d7e5c7ebf1864c7df40f943724af36e611e8', '20c3ad81ed407d742d99451a54a92880d0615b9646d460dfbca6c3288e776614'],
            'missing_source' => ['20015064867b4fa82cb1a4337cdf5a70ce17241a4f1ccdd820900514a069521a', '60a18bb1cd0e6605d8a7712a0f04dce1239df7e857959cfac8da2e5c4147f16e', '5242d39d1139fc48eaa8e6c73c2e97f65bfb10ca4e82380fd7ebbdd9e418f90d'],
            'explicit_proof' => ['3ead837ec1f99a9b8d7c2f044750864d31a8cad21bf93b1fc370d7d7f15de00d', 'cffa23017b0d122c8af7fcdc3000822e5a64a714105dfc46474af2a5344b138e', '7b83e610ecf7f3689a36284e5732977347e5b11469e181d874386b832e43bb62'],
            'missing_predicate' => ['34267114c6227aef9fd0bacdcd091c363a2516b37e0e498ba16c71fa02fc6b72', '082838a309819c3d0af0cbbb60c253e6c87a3980349f96e9abce3c443be1e76d', 'e1b2041b445a2e62642903aec7c4dffa751c47025c4cbf77b88a484d7cd22019'],
            'missing_coefficient' => ['0076e1bf4130450de506a2708002f8d2e1cd6df4db505391bb08a5d44d260823', '8475068131ff4798cc2e0483e27ef64ed2610f1cc3f0edf9a4d5e2b6638f0b53', '33e2a7100913433a0c272aa9d21df1bd1436dec9d59a2265ef10aeda2dc92537'],
        ];
        $this->assertSame($baseline[$scenario], $actual);
    }

    public static function readerProofCases(): array
    {
        return [['single'], ['explicit'], ['reconstructed']];
    }

    #[DataProvider('readerProofCases')]
    public function test_orchestration_uses_only_explicit_reader_including_history_proof_relations(string $proof): void
    {
        if ($proof === 'explicit') {
            DB::table('performance_evaluations')->update(['employee_position_history_id' => $this->history->id]);
        } elseif ($proof === 'reconstructed') {
            $this->evaluation(2026);
            DB::table('performance_evaluations')->update(['source_position_tmt' => '2023-01-01']);
        }
        $snapshot = app(ConversionPreviewDependencies::class);
        $reader = \Mockery::mock(ConversionDependencyReader::class);
        $recorded = [];
        foreach (['case', 'ruleSet', 'read', 'fields', 'sourceCandidates', 'historyCandidates', 'predicateRules', 'coefficientRules'] as $method) {
            $reader->shouldReceive($method)->andReturnUsing(function (...$args) use ($method, $snapshot, &$recorded) {
                $result = $snapshot->$method(...$args);
                $recorded[] = [$method, $result];

                return $result;
            });
        }
        $request = new ConversionPreviewRequest($this->case->id, '2023-11-01', '2026-10-31');
        $orchestrator = app(ConversionOrchestrator::class);
        $expected = $this->preview();
        $recordedResult = DB::transaction(fn () => $orchestrator->build($request, $reader));
        $this->assertEquals($expected, $recordedResult);
        $detachedReader = \Mockery::mock(ConversionDependencyReader::class);
        foreach ($recorded as [$method, $result]) {
            $detachedReader->shouldReceive($method)->once()->ordered()->andReturn($result);
        }
        $queries = [];
        DB::listen(function ($event) use (&$queries) {
            $queries[] = $event->sql;
        });
        // Test-only recorded inputs: no database reads, relation lazy loads or owned transaction.
        $actual = $orchestrator->build($request, $detachedReader);
        $this->assertSame([], $queries);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertEquals($expected, $actual);
        $this->assertSame($expected->dependencies->groups, $actual->dependencies->groups);
        $this->assertSame($expected->dependency_fingerprint, $actual->dependency_fingerprint);
        $this->assertSame($expected->snapshot_fingerprint, $actual->snapshot_fingerprint);
        $this->assertNoModels($actual);
    }

    private function evaluation(int $year, string $type = 'ANNUAL'): PerformanceEvaluation
    {
        $hash = hash('sha256', $year.$type.PerformanceEvaluation::count());

        return PerformanceEvaluation::create(['person_id' => $this->case->person_id, 'created_by' => $this->actor->id, 'performance_year' => $year,
            'evaluation_type' => $type, 'source_kind' => 'OFFICIAL', 'source_reference' => 'Synthetic-'.$hash, 'source_record_identifier' => $hash, 'predicate_code' => 'BAIK', 'predicate_label_original' => 'Synthetic',
            'coverage_start' => $year.'-01-01', 'coverage_end' => $year.'-12-31', 'source_identity_fingerprint' => $hash, 'content_fingerprint' => $hash, 'current_source_identity_fingerprint' => $hash]);
    }

    private function preview(string $start = '2023-11-01', string $end = '2026-10-31')
    {
        return app(ConversionService::class)->preview($this->actor, new ConversionPreviewRequest($this->case->id, $start, $end));
    }

    private function codes($result): array
    {
        return array_values(array_unique(array_column($result->issues, 'issue_code')));
    }

    public function test_synthetic_documentary_complete_preview_preserves_exact_shape_without_writes_or_locks(): void
    {
        $before = [];
        foreach (['pak_cases', 'pak_rule_sets', 'performance_evaluations', 'employee_position_histories', 'pak_events', 'pak_conversion_runs', 'pak_conversion_periods'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $queries = [];
        DB::listen(function ($event) use (&$queries) {
            $queries[] = $event->sql;
        });
        $result = $this->preview();
        $this->assertSame([], $result->issues);
        $this->assertSame(['25/6', '25', '25', '125/6'], array_map(fn ($line) => (string) $line->exact, $result->lines));
        $this->assertSame(['4,167', '25', '25', '20,833'], array_column($result->lines, 'human'));
        $this->assertSame('75', (string) $result->exact_total);
        $this->assertSame('1', (string) $result->exact_total->getDenominator());
        $this->assertSame('75.000', $result->machine_total);
        $this->assertSame('75', $result->human_total);
        $this->assertSame($this->case->fresh()->lock_version, $result->expected_case_version);
        $this->assertCount(8, $result->dependencies->groups);
        $this->assertSame('CURRENT', $result->lines[0]->regime->regime); // sk_date in 2022 is not regime TMT.
        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/\b(insert|update|delete|for update|lock in share mode)\b/i', $sql);
        }
        foreach ($before as $table => $json) {
            $this->assertSame($json, DB::table($table)->orderBy('id')->get()->toJson());
        }
        $this->assertSame(0, DB::transactionLevel()); // Preview releases its owned transaction.
        $this->assertScalarGraph($result->dependencies->groups);
        $this->assertNoModels($result);
    }

    private function assertScalarGraph(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $v) {
                $this->assertScalarGraph($v);
            }
        } else {
            $this->assertTrue($value === null || is_scalar($value));
        }
    }

    private function assertNoModels(mixed $value): void
    {
        $this->assertNotInstanceOf(Model::class, $value);
        if (is_array($value) || (is_object($value) && ! $value instanceof BigRational)) {
            foreach (is_array($value) ? $value : get_object_vars($value) as $v) {
                $this->assertNoModels($v);
            }
        }
    }

    public static function permissions(): array
    {
        return array_map(fn ($p) => [$p], array_merge(ConversionService::PERMISSIONS, ['pak.access']));
    }

    #[DataProvider('permissions')]
    public function test_each_permission_is_required(string $permission): void
    {
        $this->denied = $permission;
        $this->expectException(HttpException::class);
        $this->preview();
    }

    public function test_inactive_actor_is_rejected(): void
    {
        $this->actor->forceFill(['is_active' => false])->save();
        $this->expectException(HttpException::class);
        $this->preview();
    }

    public static function ranges(): array
    {
        return [['2026-02-30', '2026-03-31'], ['bad', '2026-03-31'], ['2026-03-01', '2026-02-28'], ['2022-12-01', '2023-01-31'], ['2026-12-01', '2027-01-31']];
    }

    #[DataProvider('ranges')]
    public function test_invalid_range_rejected(string $start, string $end): void
    {
        $this->expectException(ValidationException::class);
        $this->preview($start, $end);
    }

    public function test_partial_request_has_no_fabricated_lines_or_count(): void
    {
        $result = $this->preview('2026-01-15', '2026-03-31');
        $this->assertSame(['PARTIAL_MONTH_REQUIRES_REVIEW'], $this->codes($result));
        $this->assertNull($result->exact_total);
        $this->assertNull($result->coverage->months);
        $this->assertSame([], $result->lines);
        $this->assertSame('NOT_EVALUATED', $result->dependencies->groups['source_candidates']['state']);
    }

    public static function validity(): array
    {
        return [[null, '2026-12-31', 'UNKNOWN_BOUNDARY'], ['2023-01-01', null, 'UNKNOWN_BOUNDARY'], [null, null, 'UNKNOWN_BOUNDARY'], ['2024-01-01', '2026-12-31', 'OUTSIDE_VALIDITY'], ['2023-01-01', '2026-10-15', 'OUTSIDE_VALIDITY']];
    }

    #[DataProvider('validity')]
    public function test_validity_short_circuits_child_queries(?string $from, ?string $until, string $reason): void
    {
        DB::table('pak_rule_sets')->where('id', $this->rule->id)->update(['valid_from' => $from, 'valid_until' => $until]);
        $queries = [];
        DB::listen(function ($event) use (&$queries) {
            $queries[] = $event->sql;
        });
        $result = $this->preview();
        $this->assertSame(['RULE_VALIDITY_REQUIRES_REVIEW'], $this->codes($result));
        $this->assertSame($reason, $result->issues[0]['reason']);
        $this->assertNull($result->exact_total);
        $this->assertSame('NOT_EVALUATED', $result->dependencies->groups['rule_set']['children']['state']);
        $this->assertStringNotContainsString('performance_predicate_rules', implode(' ', $queries));
        $this->assertStringNotContainsString('ak_coefficient_rules', implode(' ', $queries));
    }

    public static function documentary(): array
    {
        return [['sk_number', null], ['sk_number', '  '], ['source_ref', '  ']];
    }

    #[DataProvider('documentary')]
    public function test_missing_documentary_proof_never_defaults_current(string $field, mixed $value): void
    {
        DB::table('employee_position_histories')->where('id', $this->history->id)->update([$field => $value]);
        $queries = [];
        DB::listen(function ($event) use (&$queries) {
            $queries[] = $event->sql;
        });
        $result = $this->preview();
        $this->assertStringNotContainsString('performance_predicate_rules', implode(' ', $queries));
        $this->assertStringNotContainsString('ak_coefficient_rules', implode(' ', $queries));
        $this->assertSame(['REGIME_EVIDENCE_REQUIRES_REVIEW'], $this->codes($result));
        $this->assertNull($result->exact_total);
        $this->assertNull($result->lines[0]->regime->regime);
        $this->assertSame('NOT_EVALUATED', $result->dependencies->groups['rule_set']['children']['state']);
    }

    public static function eligibility(): array
    {
        return [['verified_by', null], ['verified_at', null], ['superseded_at', '2026-01-01 00:00:00'], ['person_id', -1], ['tmt_start', null], ['sk_number', null], ['source_ref', '']];
    }

    #[DataProvider('eligibility')]
    public function test_evidence_value_requires_all_documentary_conditions(string $field, mixed $value): void
    {
        $assignment = app(PositionSegmentResolver::class)->resolve($this->case->person_id, ['2026-01'])->assignments[0];
        $raw = $this->history->fresh()->getAttributes();
        $raw['tmt_start'] = $assignment->tmt_start;
        $this->assertSame('CURRENT', ConversionRegimeEvidence::from($this->case->person_id, $assignment, $raw)->regime);
        $raw[$field] = $value;
        $evidence = ConversionRegimeEvidence::from($this->case->person_id, $assignment, $raw);
        $this->assertSame('REGIME_EVIDENCE_REQUIRES_REVIEW', $evidence->issue_code);
        $this->assertNull($evidence->regime);
    }

    public function test_legacy_skips_children_and_does_not_consume_old_values(): void
    {
        DB::table('employee_position_histories')->where('id', $this->history->id)->update(['tmt_start' => '2022-12-31']);
        DB::table('performance_predicate_rules')->delete();
        DB::table('ak_coefficient_rules')->delete();
        $queries = [];
        DB::listen(function ($event) use (&$queries) {
            $queries[] = $event->sql;
        });
        $result = $this->preview();
        $this->assertStringNotContainsString('performance_predicate_rules', implode(' ', $queries));
        $this->assertStringNotContainsString('ak_coefficient_rules', implode(' ', $queries));
        $this->assertSame(['LEGACY_REGIME_REQUIRES_REVIEW'], $this->codes($result));
        $this->assertNull($result->exact_total);
        $this->assertSame('NOT_EVALUATED', $result->dependencies->groups['rule_set']['children']['state']);
        foreach ($result->lines as $line) {
            $this->assertNull($line->exact);
        }
    }

    public static function missing(): array
    {
        return [['performance_predicate_rules', 'PREDICATE_RULE_NOT_FOUND'], ['ak_coefficient_rules', 'COEFFICIENT_RULE_NOT_FOUND']];
    }

    #[DataProvider('missing')]
    public function test_missing_current_rule_is_diagnostic(string $table, string $code): void
    {
        DB::table($table)->delete();
        $result = $this->preview();
        $this->assertSame([$code], $this->codes($result));
        $this->assertNull($result->exact_total);
    }

    public function test_missing_level_is_not_a_position_or_evidence_issue(): void
    {
        DB::table('employee_position_histories')->where('id', $this->history->id)->update(['functional_level_id' => null]);
        $this->assertSame(['MISSING_FUNCTIONAL_LEVEL'], $this->codes($this->preview()));
    }

    public function test_annual_priority_and_quarterly_fallback(): void
    {
        $quarter = $this->evaluation(2026, 'Q3');
        $this->assertSame('ANNUAL', $this->preview('2026-01-01', '2026-10-31')->lines[0]->source->evaluation_type->value);
        DB::table('performance_evaluations')->where('performance_year', 2026)->where('evaluation_type', 'ANNUAL')->update(['status' => 'SUPERSEDED', 'current_source_identity_fingerprint' => null]);
        $this->assertSame($quarter->id, $this->preview('2026-01-01', '2026-10-31')->lines[0]->source->evaluation_id);
    }

    public function test_multiple_quarters_remain_ambiguous(): void
    {
        DB::table('performance_evaluations')->where('performance_year', 2026)->delete();
        $this->evaluation(2026, 'Q3');
        $this->evaluation(2026, 'Q3');
        $this->assertSame(['AMBIGUOUS_SOURCE_SELECTION'], $this->codes($this->preview('2026-01-01', '2026-10-31')));
    }

    public static function positions(): array
    {
        return [[false, 'MISSING_POSITION_HISTORY'], [true, 'AMBIGUOUS_POSITION_HISTORY']];
    }

    #[DataProvider('positions')]
    public function test_position_failures_keep_od04_codes(bool $overlap, string $code): void
    {
        if ($overlap) {
            $copy = $this->history->replicate();
            $copy->save();
        } else {
            DB::table('employee_position_histories')->where('id', $this->history->id)->update(['tmt_end' => '2025-12-31']);
        }
        $result = $this->preview('2026-01-01', '2026-10-31');
        $this->assertSame([$code], $this->codes($result));
        $this->assertNull($result->exact_total);
    }

    public static function mixed(): array
    {
        return [[true, 'LEGACY_REGIME_REQUIRES_REVIEW'], [false, 'REGIME_EVIDENCE_REQUIRES_REVIEW']];
    }

    #[DataProvider('mixed')]
    public function test_mixed_request_never_exposes_a_subtotal_as_total(bool $legacy, string $code): void
    {
        DB::table('employee_position_histories')->where('id', $this->history->id)->update(['tmt_start' => $legacy ? '2022-01-01' : '2023-01-01', 'tmt_end' => '2024-12-31', 'sk_number' => $legacy ? 'SYNTHETIC-OLD' : null]);
        $new = $this->history->replicate();
        $new->tmt_start = '2025-01-01';
        $new->save();
        $result = $this->preview();
        $this->assertSame([$code], $this->codes($result));
        $this->assertNull($result->exact_total);
        $this->assertTrue(collect($result->lines)->contains(fn ($line) => $line->exact !== null));
    }

    public function test_fingerprints_are_deterministic_semantic_and_distinct(): void
    {
        $a = $this->preview();
        $b = $this->preview();
        $this->assertSame($a->dependencies->groups, $b->dependencies->groups);
        $this->assertSame($a->dependency_fingerprint, $b->dependency_fingerprint);
        $this->assertSame($a->snapshot_fingerprint, $b->snapshot_fingerprint);
        $this->assertNotSame($a->dependency_fingerprint, $a->snapshot_fingerprint);
        DB::table('pak_cases')->where('id', $this->case->id)->increment('lock_version');
        $this->assertSame($a->dependency_fingerprint, $this->preview()->dependency_fingerprint);
        foreach ([['employee_position_histories', $this->history->id, 'sk_number', 'SYNTHETIC-CHANGED'], ['employee_position_histories', $this->history->id, 'source_ref', 'Synthetic changed reference'], ['performance_evaluations', PerformanceEvaluation::first()->id, 'content_fingerprint', str_repeat('f', 64)], ['pak_rule_sets', $this->rule->id, 'valid_until', '2027-12-31']] as [$table, $id, $field, $value]) {
            $old = $this->preview()->dependency_fingerprint;
            DB::table($table)->where('id', $id)->update([$field => $value]);
            $this->assertNotSame($old, $this->preview()->dependency_fingerprint);
        }
        $this->assertSame(ConversionManifestBuilder::hash(['b' => 2, 'a' => 1]), ConversionManifestBuilder::hash(['a' => 1, 'b' => 2]));
    }

    public function test_evaluated_empty_sources_are_not_not_evaluated(): void
    {
        DB::table('performance_evaluations')->delete();
        $result = $this->preview();
        $this->assertSame('EVALUATED', $result->dependencies->groups['source_candidates']['state']);
        $this->assertSame([], $result->dependencies->groups['source_candidates']['items']);
        $this->assertSame(['SOURCE_NOT_FOUND'], $this->codes($result));
    }

    public function test_missing_first_use_is_integrity_error_without_repair(): void
    {
        DB::table('pak_rule_sets')->where('id', $this->rule->id)->update(['first_used_at' => null]);
        try {
            $this->preview();
            $this->fail();
        } catch (ConversionRuleException $e) {
            $this->assertSame('RULE_INTEGRITY_ERROR', $e->issue_code);
        }
        $this->assertNull($this->rule->fresh()->first_used_at);
    }

    public function test_serializer_rejects_mutable_models(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConversionManifestBuilder::canonical(['case' => $this->case]);
    }

    public function test_explicit_case_rule_only_and_missing_rule_rejection(): void
    {
        PakRuleSet::create(['code' => 'OTHER', 'version' => '99', 'name' => 'Synthetic alternative', 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]);
        $result = $this->preview();
        $this->assertSame($this->rule->id, $result->dependencies->groups['rule_set']['id']);
        foreach ($result->lines as $line) {
            $this->assertSame($line->months, $line->source->applied_months);
        }
        DB::table('pak_cases')->where('id', $this->case->id)->update(['pak_rule_set_id' => null]);
        $this->expectExceptionMessage('RULE_SET_NOT_FOUND');
        $this->preview();
    }

    public function test_source_explicit_history_proof_is_preserved_and_bad_proof_is_review(): void
    {
        DB::table('performance_evaluations')->update(['employee_position_history_id' => $this->history->id]);
        $result = $this->preview();
        $this->assertSame('EXPLICIT_HISTORY_ID', $result->lines[0]->source->history_proof_method);
        DB::table('performance_evaluations')->update(['source_position_tmt' => '2024-01-01']);
        $this->assertSame(['SOURCE_POSITION_LINK_REQUIRES_REVIEW'], $this->codes($this->preview()));
    }

    public function test_source_tmt_and_sk_date_do_not_replace_missing_documentary_proof(): void
    {
        DB::table('performance_evaluations')->update(['source_position_tmt' => '2023-01-01']);
        DB::table('employee_position_histories')->where('id', $this->history->id)->update(['sk_number' => null, 'sk_date' => '2023-01-01']);
        $this->assertSame(['REGIME_EVIDENCE_REQUIRES_REVIEW'], $this->codes($this->preview()));
    }

    public function test_inclusive_validity_and_no_actor_or_execution_time_in_fingerprint(): void
    {
        DB::table('pak_rule_sets')->where('id', $this->rule->id)->update(['valid_from' => '2023-11-01', 'valid_until' => '2026-10-31']);
        $first = $this->preview();
        $this->assertSame([], $first->issues);
        $this->actor = User::factory()->create();
        $this->travel(1)->hours();
        $this->assertSame($first->dependency_fingerprint, $this->preview()->dependency_fingerprint);
        $this->assertSame($first->snapshot_fingerprint, $this->preview()->snapshot_fingerprint);
    }

    public function test_inactive_historical_level_remains_resolvable(): void
    {
        DB::table('functional_levels')->where('id', $this->level->id)->update(['is_active' => false]);
        $this->assertSame('75', (string) $this->preview()->exact_total);
    }

    public function test_preview_dtos_are_immutable_and_serializer_preserves_list_order(): void
    {
        $result = $this->preview();
        $this->assertNotSame(ConversionManifestBuilder::hash(['months' => ['2026-01', '2026-02']]), ConversionManifestBuilder::hash(['months' => ['2026-02', '2026-01']]));
        $this->expectException(\Error::class);
        $result->dependencies->groups['coverage'] = [];
    }

    public function test_outer_transaction_is_rejected_before_any_reads(): void
    {
        DB::beginTransaction();
        $queries = [];
        DB::listen(function ($event) use (&$queries) {
            $queries[] = $event->sql;
        });
        try {
            $this->preview();
            $this->fail('Nested preview must be rejected.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('top-level', $e->getMessage());
            $this->assertSame([], $queries);
            $this->assertSame(1, DB::transactionLevel());
        } finally {
            DB::rollBack();
        }
    }

    public static function isolationCases(): array
    {
        return [['mysql', 'REPEATABLE-READ', false, true], ['mysql', 'REPEATABLE READ', false, true],
            ['mariadb', 'REPEATABLE-READ', true, true], ['mariadb', 'READ-COMMITTED', true, false],
            ['mysql', 'SERIALIZABLE', false, false]];
    }

    #[DataProvider('isolationCases')]
    public function test_isolation_uses_write_pdo_without_mutating_settings(string $driver, string $value, bool $fallback, bool $accepted): void
    {
        $connection = \Mockery::mock(Connection::class);
        $pdo = \Mockery::mock(\PDO::class);
        $statement = \Mockery::mock(\PDOStatement::class);
        $connection->shouldReceive('getDriverName')->once()->andReturn($driver);
        $connection->shouldReceive('getPdo')->once()->andReturn($pdo);
        $connection->shouldNotReceive('getReadPdo');
        $pdo->shouldNotReceive('exec');
        if ($fallback) {
            $error = new \PDOException('Unknown system variable');
            $error->errorInfo = ['HY000', 1193, 'Unknown system variable'];
            $pdo->shouldReceive('query')->once()->with('SELECT @@session.transaction_isolation')->andThrow($error);
            $pdo->shouldReceive('query')->once()->with('SELECT @@session.tx_isolation')->andReturn($statement);
        } else {
            $pdo->shouldReceive('query')->once()->with('SELECT @@session.transaction_isolation')->andReturn($statement);
        }
        $statement->shouldReceive('fetchColumn')->once()->andReturn($value);
        if (! $accepted) {
            $this->expectException(\LogicException::class);
        }
        $method = new \ReflectionMethod(ConversionService::class, 'requireSnapshotIsolation');
        $method->invoke(app(ConversionService::class), $connection);
        if ($accepted) {
            $this->assertTrue(true);
        }
    }

    public function test_foreign_explicit_proof_never_exposes_documentary_target(): void
    {
        $foreign = $this->history->replicate();
        $foreign->person_id = Person::factory()->create()->id;
        $foreign->sk_number = 'FOREIGN-PRIVATE-SK';
        $foreign->source_ref = 'FOREIGN-PRIVATE-REFERENCE';
        $foreign->position_name = 'FOREIGN-PRIVATE-POSITION';
        $foreign->save();
        DB::table('performance_evaluations')->update(['employee_position_history_id' => $foreign->id]);
        $result = $this->preview();
        $this->assertSame(['SOURCE_POSITION_LINK_REQUIRES_REVIEW'], $this->codes($result));
        $json = json_encode($result->dependencies->groups, JSON_THROW_ON_ERROR);
        foreach (['FOREIGN-PRIVATE-SK', 'FOREIGN-PRIVATE-REFERENCE', 'FOREIGN-PRIVATE-POSITION'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
        $this->assertSame([$this->history->id], array_column($result->dependencies->groups['history_dependencies']['items'], 'id'));
        $this->assertSame($foreign->id, $result->dependencies->groups['source_candidates']['items'][0]['employee_position_history_id']);
    }

    public function test_rule_reference_is_immutable_semantic_evidence(): void
    {
        $initial = $this->preview();
        $this->assertNull($initial->dependencies->groups['rule_set']['reference']);
        DB::table('pak_rule_sets')->where('id', $this->rule->id)->update(['reference' => 'Synthetic regulatory reference']);
        $next = $this->preview();
        $this->assertSame('Synthetic regulatory reference', $next->dependencies->groups['rule_set']['reference']);
        $this->assertNotSame($initial->dependency_fingerprint, $next->dependency_fingerprint);
        $this->assertNotSame($initial->snapshot_fingerprint, $next->snapshot_fingerprint);
        $this->assertNull($initial->dependencies->groups['rule_set']['reference']);
        $this->assertSame($next->dependency_fingerprint, $this->preview()->dependency_fingerprint);
        $this->assertNoModels($next);
    }

    public function test_equivalent_reinserted_sources_have_identical_manifest_and_hashes(): void
    {
        $first = $this->preview();
        $rows = DB::table('performance_evaluations')->orderByDesc('id')->get()->map(fn ($row) => (array) $row)->all();
        DB::table('performance_evaluations')->delete();
        foreach ($rows as $row) {
            DB::table('performance_evaluations')->insert($row);
        }
        $second = $this->preview();
        $this->assertSame($first->dependencies->groups, $second->dependencies->groups);
        $this->assertSame($first->dependency_fingerprint, $second->dependency_fingerprint);
        $this->assertSame($first->snapshot_fingerprint, $second->snapshot_fingerprint);
    }

    public function test_candidate_eligibility_matches_source_contract(): void
    {
        DB::table('performance_evaluations')->where('performance_year', 2026)->delete();
        $q1 = $this->evaluation(2026, 'Q1');
        $q2 = $this->evaluation(2026, 'Q2');
        $this->evaluation(2026, 'Q3');
        $this->evaluation(2026, 'Q4');
        $excluded = $this->evaluation(2026);
        DB::table('performance_evaluations')->where('id', $excluded->id)->update(['source_kind' => 'PROVISIONAL']);
        $custom = $this->evaluation(2026);
        DB::table('performance_evaluations')->where('id', $custom->id)->update(['evaluation_type' => 'CUSTOM_PERIOD']);
        $old = $this->evaluation(2026);
        DB::table('performance_evaluations')->where('id', $old->id)->update(['status' => 'SUPERSEDED', 'current_source_identity_fingerprint' => null]);
        $march = $this->preview('2026-01-01', '2026-03-31');
        $this->assertSame([$q1->id], array_column($march->dependencies->groups['source_candidates']['items'], 'id'));
        $this->assertSame($q1->id, $march->lines[0]->source->evaluation_id);
        $april = $this->preview('2026-01-01', '2026-04-30');
        $this->assertSame([$q1->id, $q2->id], array_column($april->dependencies->groups['source_candidates']['items'], 'id'));
        $this->assertSame($q2->id, $april->lines[0]->source->evaluation_id);
    }

    public function test_isolation_does_not_fallback_for_other_sql_errors(): void
    {
        $connection = \Mockery::mock(Connection::class);
        $pdo = \Mockery::mock(\PDO::class);
        $error = new \PDOException('Controlled non-1193 error');
        $error->errorInfo = ['HY000', 2006, 'Controlled error'];
        $connection->shouldReceive('getDriverName')->once()->andReturn('mariadb');
        $connection->shouldReceive('getPdo')->once()->andReturn($pdo);
        $pdo->shouldReceive('query')->once()->with('SELECT @@session.transaction_isolation')->andThrow($error);
        $pdo->shouldNotReceive('query')->with('SELECT @@session.tx_isolation');
        $this->expectExceptionObject($error);
        (new \ReflectionMethod(ConversionService::class, 'requireSnapshotIsolation'))->invoke(app(ConversionService::class), $connection);
    }

    public static function liveDrivers(): array
    {
        return [['mysql'], ['mariadb']];
    }

    #[DataProvider('liveDrivers')]
    public function test_live_disposable_preview_snapshot_and_cleanup(string $driver): void
    {
        if (getenv('PAK_PREVIEW_LIVE') !== '1' || DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('Opt in PAK_PREVIEW_LIVE=1 with disposable MariaDB 127.0.0.1:33318/pak_02a1_test.');
        }
        $name = DB::getDefaultConnection();
        config(["database.connections.$name.driver" => $driver]);
        DB::purge($name);
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $server = $pdo->query('SELECT VERSION() AS version, DATABASE() AS db, CONNECTION_ID() AS id')->fetch(\PDO::FETCH_ASSOC);
        $this->assertStringStartsWith('10.5.11-MariaDB', $server['version']);
        $this->assertSame('pak_02a1_test', $server['db']);
        $this->assertSame($driver, $connection->getDriverName());
        $this->assertSame(0, $connection->transactionLevel());
        try {
            $pdo->query('SELECT @@session.transaction_isolation');
            $this->fail('MariaDB 10.5.11 should exercise the actual unknown-variable fallback.');
        } catch (\PDOException $e) {
            $this->assertSame(1193, $e->errorInfo[1]);
        }
        $originalIsolation = $pdo->query('SELECT @@session.tx_isolation')->fetchColumn();
        $this->assertSame('REPEATABLE-READ', $originalIsolation);
        $tables = ['pak_conversion_runs', 'pak_conversion_periods', 'pak_events', 'pak_cases', 'pak_rule_sets',
            'performance_evaluations', 'employee_position_histories', 'functional_positions', 'functional_levels', 'functional_position_levels'];
        $state = function () use ($tables): array {
            $state = [];
            foreach ($tables as $table) {
                $state[$table] = DB::table($table)->orderBy('id')->get()->toJson();
            }

            return $state;
        };
        $before = $state();
        $result = $this->preview(); // Real production fallback must succeed on this same PDO.
        $this->assertSame(['25/6', '25', '25', '125/6'], array_map(fn ($line) => (string) $line->exact, $result->lines));
        $this->assertSame(['75', '1'], [(string) $result->exact_total->getNumerator(), (string) $result->exact_total->getDenominator()]);
        $this->assertCount(8, $result->dependencies->groups);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result->dependency_fingerprint);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result->snapshot_fingerprint);
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertSame($before, $state());

        $other = new \PDO('mysql:host=127.0.0.1;port=33318;dbname=pak_02a1_test;charset=utf8mb4', 'root', '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $otherId = $other->query('SELECT CONNECTION_ID()')->fetchColumn();
        $this->assertNotSame((string) $server['id'], (string) $otherId);
        $originalReference = $result->dependencies->groups['rule_set']['reference'];
        $armed = true;
        $changed = false;
        DB::listen(function ($event) use (&$armed, &$changed, $other) {
            // QueryExecuted runs after SELECT consumed the case: the consistent snapshot exists.
            if ($armed && str_contains($event->sql, 'from `pak_cases`')) {
                $armed = false;
                $this->assertSame(1, DB::transactionLevel());
                $other->beginTransaction();
                $statement = $other->prepare('UPDATE pak_rule_sets SET reference = ? WHERE id = ?');
                $statement->execute(['Synthetic concurrent reference', $this->rule->id]);
                $other->commit();
                $changed = true;
            }
        });
        try {
            $during = $this->preview();
            $this->assertTrue($changed);
            $this->assertSame($originalReference, $during->dependencies->groups['rule_set']['reference']);
            $this->assertSame($result->dependencies->groups, $during->dependencies->groups);
            $this->assertSame($result->dependency_fingerprint, $during->dependency_fingerprint);
            $this->assertSame($result->snapshot_fingerprint, $during->snapshot_fingerprint);
            $later = $this->preview();
            $this->assertSame('Synthetic concurrent reference', $later->dependencies->groups['rule_set']['reference']);
            $this->assertNotSame($during->dependency_fingerprint, $later->dependency_fingerprint);
            $this->assertNotSame($during->snapshot_fingerprint, $later->snapshot_fingerprint);
        } finally {
            $armed = false;
            if ($other->inTransaction()) {
                $other->rollBack();
            }
            $statement = $other->prepare('UPDATE pak_rule_sets SET reference = ? WHERE id = ?');
            $statement->execute([$originalReference, $this->rule->id]);
        }
        $this->assertSame($before, $state());
        $queries = [];
        DB::listen(function ($event) use (&$queries) {
            $queries[] = $event->sql;
        });
        $connection->beginTransaction();
        try {
            $this->preview();
            $this->fail('Outer transaction must be rejected.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('top-level', $e->getMessage());
            $this->assertSame([], $queries);
            $this->assertSame(1, $connection->transactionLevel());
            $this->assertTrue($pdo->inTransaction());
        } finally {
            $connection->rollBack();
        }
        try {
            // Test-only SESSION change; production preview must only read it.
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $queries = [];
            try {
                $this->preview();
                $this->fail('READ COMMITTED must be rejected.');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('repeatable-read', $e->getMessage());
            }
            $this->assertSame(0, $connection->transactionLevel());
            $this->assertStringNotContainsString('pak_cases', implode(' ', $queries));
            $this->assertSame('READ-COMMITTED', $pdo->query('SELECT @@session.tx_isolation')->fetchColumn());
        } finally {
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        $fault = true;
        DB::listen(function ($event) use (&$fault) {
            if ($fault && str_contains($event->sql, 'from `pak_rule_sets`')) {
                $fault = false;
                $this->assertSame(1, DB::transactionLevel());
                throw new \RuntimeException('Controlled preview read fault');
            }
        });
        try {
            $this->preview();
            $this->fail('Controlled fault must escape.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Controlled preview read fault', $e->getMessage());
        }
        $this->assertFalse($fault);
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($pdo->inTransaction());
        $this->assertSame('75', (string) $this->preview()->exact_total);
        $this->assertSame($before, $state());
        $this->assertSame($originalIsolation, $pdo->query('SELECT @@session.tx_isolation')->fetchColumn());
        fwrite(STDOUT, "\nLIVE PREVIEW ".json_encode($server + ['driver' => $driver, 'host' => '127.0.0.1', 'port' => 33318,
            'competitor_id' => $otherId, 'fallback_vendor_code' => 1193, 'isolation' => $originalIsolation, 'transaction_level_after' => $connection->transactionLevel()], JSON_THROW_ON_ERROR)."\n");
    }
}
