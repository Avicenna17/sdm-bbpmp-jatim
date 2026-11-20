<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\DTOs\ConversionAssessment;
use App\Domain\Pak\DTOs\ConversionAssessmentRequest;
use App\Domain\Pak\DTOs\ConversionPreview;
use App\Domain\Pak\DTOs\ConversionPreviewRequest;
use App\Domain\Pak\DTOs\ConversionReplaceRequest;
use App\Domain\Pak\DTOs\ConversionSaveRequest;
use App\Domain\Pak\Exceptions\ConversionRuleException;
use App\Domain\Pak\Queries\ConversionRunQuery;
use App\Domain\Pak\Services\ConversionAssessmentService;
use App\Domain\Pak\Services\ConversionReviewService;
use App\Domain\Pak\Services\ConversionService;
use App\Domain\Pak\Services\EmployeeHistoryService;
use App\Domain\Pak\Services\FunctionalMasterService;
use App\Filament\Pak\Resources\PakCaseResource;
use App\Filament\Pak\Resources\PakCaseResource\Pages\CaseConversion;
use App\Filament\Pak\Support\ConversionPresenter;
use App\Models\AkCoefficientRule;
use App\Models\EmployeePositionHistory;
use App\Models\FunctionalCategory;
use App\Models\FunctionalLevel;
use App\Models\FunctionalPosition;
use App\Models\FunctionalPositionLevel;
use App\Models\PakCase;
use App\Models\PakConversionRun;
use App\Models\PakEvent;
use App\Models\PakRuleSet;
use App\Models\PerformanceEvaluation;
use App\Models\PerformancePredicateRule;
use App\Models\Person;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ConversionReplacementComparisonUiTest extends TestCase
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
        $this->assertSame('sqlite', DB::getDriverName());
        // Preview owns its transaction: use a fresh disposable schema without a wrapping test transaction.
        $this->artisan('migrate:fresh', ['--force' => true]);
        RefreshDatabaseState::$migrated = false;
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actor = User::factory()->create(['is_active' => true]);
        Gate::before(fn ($user, $permission) => $permission !== $this->denied);
        $this->actingAs($this->actor);
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
        $this->case->refresh();
        foreach ([2023, 2024, 2025, 2026] as $year) {
            $this->evaluation($year);
        }
    }

    private function evaluation(int $year, string $type = 'ANNUAL'): PerformanceEvaluation
    {
        $hash = hash('sha256', $year.$type.PerformanceEvaluation::count());

        return PerformanceEvaluation::create(['person_id' => $this->case->person_id, 'created_by' => $this->actor->id, 'performance_year' => $year,
            'evaluation_type' => $type, 'source_kind' => 'OFFICIAL', 'source_reference' => 'Synthetic-'.$hash, 'source_record_identifier' => $hash, 'predicate_code' => 'BAIK', 'predicate_label_original' => 'Synthetic',
            'coverage_start' => $year.'-01-01', 'coverage_end' => $year.'-12-31', 'source_identity_fingerprint' => $hash, 'content_fingerprint' => $hash, 'current_source_identity_fingerprint' => $hash]);
    }

    private function save(string $start = '2023-11-01'): PakConversionRun
    {
        $preview = app(ConversionService::class)->preview($this->actor, new ConversionPreviewRequest($this->case->id, $start, '2026-10-31'));

        return app(ConversionService::class)->saveInitial($this->actor, ConversionSaveRequest::from([
            'pak_case_id' => $this->case->id, 'requested_start' => $start, 'requested_end' => '2026-10-31',
            'expected_case_lock_version' => $preview->expected_case_version,
            'expected_dependency_fingerprint' => $preview->dependency_fingerprint,
            'expected_snapshot_fingerprint' => $preview->snapshot_fingerprint,
        ]));
    }

    private function rename(string $name = 'Synthetic changed label'): void
    {
        $level = $this->level->fresh();
        app(FunctionalMasterService::class)->save($this->actor, 'level', [
            'code' => $level->code, 'name' => $name,
            'functional_category_id' => $level->functional_category_id, 'sort_order' => $level->sort_order,
            'is_active' => $level->is_active,
        ], $level->id);
    }

    private function page(?int $run = null)
    {
        $page = Livewire::test(CaseConversion::class, ['record' => $this->case->id]);

        return $run === null ? $page : $page->set('runId', $run);
    }

    private function assessment(PakConversionRun $run): ConversionAssessment
    {
        return app(ConversionAssessmentService::class)->assess($this->actor,
            ConversionAssessmentRequest::from(['pak_case_id' => $this->case->id, 'run_id' => $run->id]));
    }

    private function state(): array
    {
        $state = [];
        foreach (['people', 'pak_cases', 'pak_case_employee_snapshots', 'pak_conversion_runs', 'pak_conversion_periods',
            'pak_events', 'performance_evaluations', 'employee_position_histories', 'pak_rule_sets',
            'performance_predicate_rules', 'ak_coefficient_rules', 'functional_levels', 'functional_positions',
            'functional_categories', 'functional_position_levels'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $state;
    }

    private function clean(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->assertCount(0, app('db.transactions')->getPendingTransactions());
        $this->assertCount(0, app('db.transactions')->getCommittedTransactions());
    }

    private function competingReplacement(PakConversionRun $run): PakConversionRun
    {
        $preview = app(ConversionService::class)->preview($this->actor, new ConversionPreviewRequest($this->case->id,
            $run->requested_start->format('Y-m-d'), $run->requested_end->format('Y-m-d')));

        return app(ConversionService::class)->replace($this->actor, ConversionReplaceRequest::from([
            'pak_case_id' => $this->case->id, 'expected_predecessor_run_id' => $run->id,
            'requested_start' => $preview->request->requested_start, 'requested_end' => $preview->request->requested_end,
            'expected_case_lock_version' => $preview->expected_case_version,
            'expected_dependency_fingerprint' => $preview->dependency_fingerprint,
            'expected_snapshot_fingerprint' => $preview->snapshot_fingerprint, 'revision_reason' => 'Competing replacement',
        ]));
    }

    public function test_preview_is_explicit_zero_write_and_success_uses_exact_request_and_persisted_successor(): void
    {
        $run = $this->save();
        $this->rename();
        $before = $this->state();
        $service = Mockery::mock(new ConversionService);
        $service->shouldReceive('preview')->once()->andReturnUsing(function ($actor, $request) {
            $this->assertSame(['pak_case_id' => $this->case->id, 'requested_start' => '2023-11-01', 'requested_end' => '2026-10-31'], get_object_vars($request));

            return (new ConversionService)->preview($actor, $request);
        });
        app()->instance(ConversionService::class, $service);
        DB::enableQueryLog();
        $page = $this->page()->assertDontSee('Perbandingan revisi')->call('$refresh')->assertSet('replacementPreviewDisplay', [])
            ->set('requestedStart', '2024-01-01')->set('requestedEnd', '2024-12-31')->call('previewReplacement')->assertHasNoErrors()
            ->assertSet('replacementPredecessorRunId', $run->id)->assertSet('replacementRequestedStart', '2023-11-01')
            ->assertSet('replacementRequestedEnd', '2026-10-31')->assertSet('replacementPreviewDisplay.status', 'PREVIEW')
            ->assertSee('Total eksak revisi: 75');
        $writes = array_filter(DB::getQueryLog(), fn ($q) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $q['query']));
        DB::disableQueryLog();
        $this->assertSame([], array_values($writes));
        $this->assertSame($before, $this->state());
        $expected = ['pak_case_id' => $this->case->id, 'expected_predecessor_run_id' => $run->id,
            'requested_start' => '2023-11-01', 'requested_end' => '2026-10-31',
            'expected_case_lock_version' => $page->get('replacementExpectedCaseLockVersion'),
            'expected_dependency_fingerprint' => $page->get('replacementExpectedDependencyFingerprint'),
            'expected_snapshot_fingerprint' => $page->get('replacementExpectedSnapshotFingerprint'), 'revision_reason' => '<script>revision-note</script>'];
        $service->shouldReceive('replace')->once()->andReturnUsing(function ($actor, $request) use ($expected) {
            $this->assertSame($this->actor->id, $actor->id);
            $this->assertSame($expected, get_object_vars($request));

            return (new ConversionService)->replace($actor, $request);
        });
        $page->set('revisionReason', ' <script>revision-note</script> ')->assertSet('replacementExpectedSnapshotFingerprint', $expected['expected_snapshot_fingerprint'])
            ->call('replaceConversion', ['revision_number' => 99, 'total' => 999])->assertHasNoErrors()
            ->assertSet('replacementPreviewDisplay', [])->assertSet('replacementExpectedSnapshotFingerprint', null)->assertSet('revisionReason', '');
        $successor = PakConversionRun::where('predecessor_run_id', $run->id)->sole();
        $this->assertNotSame($run->id, $successor->id);
        $this->assertSame(2, $successor->revision_number);
        $this->assertEquals($this->case->id, $successor->pak_case_id);
        $this->assertEquals($this->case->id, $successor->active_case_id);
        $this->assertSame('PREVIEW', $successor->status->value);
        $this->assertSame('75', $successor->total_numerator);
        $this->assertSame(4, $successor->periods()->count());
        $this->assertEquals($this->actor->id, $successor->created_by);
        $this->assertSame($expected['expected_case_lock_version'] + 1, $this->case->fresh()->lock_version);
        $this->assertEquals($this->case->fresh()->lock_version, $successor->case_lock_version);
        $this->assertNull($successor->review_assessment);
        $this->assertNull($successor->superseded_at);
        $this->assertSame('SUPERSEDED', $run->fresh()->status->value);
        $allowed = array_flip(['status', 'active_case_id', 'superseded_at', 'superseded_by_user_id', 'updated_at']);
        $this->assertSame(array_diff_key($before['pak_conversion_runs'][0], $allowed), array_diff_key($run->fresh()->getRawOriginal(), $allowed));
        $this->assertSame($before['pak_conversion_periods'], DB::table('pak_conversion_periods')->where('pak_conversion_run_id', $run->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertSame(1, PakEvent::where('event_code', 'CONVERSION_RUN_SAVED')->count());
        $this->assertSame(1, PakEvent::where('event_code', 'CONVERSION_RUN_REPLACED')->count());
        $url = PakCaseResource::getUrl('conversion', ['record' => $this->case->id, 'run' => $successor->id]);
        $page->assertRedirect($url);
        $this->get($url)->assertOk()->assertSee('Perbandingan revisi')->assertSee('Run sebelumnya')->assertSee('Run pengganti')
            ->assertSee('&lt;script&gt;revision-note&lt;/script&gt;', false)->assertDontSee('<script>revision-note</script>', false);
        $this->clean();
    }

    #[DataProvider('actions')]
    public function test_backend_authorization_denials_and_inactive_actor(string $action, string $method): void
    {
        $this->save();
        $this->rename();
        $page = $this->page()->call('previewReplacement')->set('revisionReason', 'Reason');
        $proxy = Mockery::mock(new ConversionService);
        $proxy->shouldReceive($method)->once()->andThrow(new HttpException(403, 'PRIVATE'));
        app()->instance(ConversionService::class, $proxy);
        $page->call($action)->assertForbidden();
        app()->instance(ConversionService::class, new ConversionService);
        $page = $this->page()->call('previewReplacement');
        $this->actor->forceFill(['is_active' => false])->save();
        $page->call($action)->assertForbidden();
        $this->assertDatabaseCount('pak_conversion_runs', 1);
    }

    public static function actions(): array
    {
        return [['previewReplacement', 'preview'], ['replaceConversion', 'replace']];
    }

    #[DataProvider('permissions')]
    public function test_every_permission_is_rechecked_on_every_action(string $permission, string $action): void
    {
        $this->save();
        $this->rename();
        $page = $this->page()->call('previewReplacement');
        $before = $this->state();
        $this->denied = $permission;
        $page->call($action)->assertForbidden();
        $this->assertSame($before, $this->state());
    }

    public static function permissions(): array
    {
        $rows = [];
        foreach (['pak.access', ...ConversionService::PERMISSIONS] as $permission) {
            foreach (self::actions() as [$action]) {
                $rows[] = [$permission, $action];
            }
        }

        return $rows;
    }

    public function test_permission_loss_hides_and_clears_replacement_but_retains_read_access(): void
    {
        $this->save();
        $this->rename();
        $page = $this->page()->call('previewReplacement')->set('revisionReason', 'PRIVATE');
        $this->denied = 'pak.conversion.calculate';
        $page->call('$refresh')->assertSet('replacementPreviewDisplay', [])->assertSet('replacementPredecessorRunId', null)
            ->assertSet('revisionReason', '')->assertDontSee('Pratinjau revisi')->assertSee('Total eksak: 75/1');
        $this->page()->call('previewReplacement')->assertForbidden();
        $this->page()->call('replaceConversion')->assertForbidden();
    }

    #[DataProvider('lockedFields')]
    public function test_replacement_state_cannot_be_forged(string $field, mixed $value): void
    {
        $this->save();
        $this->rename();
        $page = $this->page()->call('previewReplacement');
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set($field, $value);
    }

    public static function lockedFields(): array
    {
        return [['replacementPredecessorRunId', 999], ['replacementRequestedStart', '2023-01-01'],
            ['replacementRequestedStart', '2024-01-01'], ['replacementRequestedEnd', '2026-12-31'],
            ['replacementRequestedEnd', '2025-12-31'], ['replacementExpectedCaseLockVersion', 99],
            ['replacementExpectedDependencyFingerprint', str_repeat('f', 64)], ['replacementExpectedSnapshotFingerprint', str_repeat('f', 64)],
            ['replacementPreviewDisplay', ['status' => 'APPROVED']]];
    }

    public function test_no_change_preview_and_other_checkpoint_tokens_cannot_authorize_replacement(): void
    {
        $this->save();
        $before = $this->state();
        $page = $this->page()->call('previewReplacement')->assertSee('Tidak ada perubahan perhitungan')
            ->assertSet('replacementPredecessorRunId', null)->set('revisionReason', 'Reason only')
            ->call('replaceConversion')->assertHasErrors('replacement');
        $page->set('requestedStart', '2023-11-01')->set('requestedEnd', '2026-10-31')->call('previewConversion')
            ->call('replaceConversion')->assertHasErrors('replacement');
        $this->assertSame($before, $this->state());
        $this->rename();
        $page->call('assessConversion')->assertSet('assessmentDisplay.comparison', 'CHANGED')->call('replaceConversion')->assertHasErrors('replacement');
        $this->assertDatabaseCount('pak_conversion_runs', 1);
        foreach (['successorId', 'status', 'revisionNumber', 'total', 'factor', 'coefficient', 'source', 'position', 'active_case_id'] as $property) {
            $this->assertFalse(property_exists($page->instance(), $property));
        }
        foreach (['replace', 'supersede', 'successor'] as $method) {
            $this->assertFalse(method_exists($page->instance(), $method));
        }
    }

    public function test_reviewable_successor_and_reviewable_predecessor_use_backend_saveability(): void
    {
        $run = $this->save();
        $payload = ['position_name' => 'Synthetic corrected position', 'position_type' => 'JF',
            'functional_position_id' => $this->history->functional_position_id, 'functional_level_id' => $this->level->id,
            'tmt_start' => '2023-01-01', 'sk_number' => null, 'sk_date' => '2022-12-20', 'source_ref' => 'Synthetic corrected evidence'];
        $history = app(EmployeeHistoryService::class)->record($this->actor, $this->case->person, $payload, true, $this->history->id, 'Synthetic correction');
        $this->page()->call('previewReplacement')->assertSet('replacementPreviewDisplay.status', 'REQUIRES_REVIEW')
            ->assertSee('Tidak tersedia')->set('revisionReason', 'Missing source')->call('replaceConversion')->assertHasNoErrors();
        $successor = PakConversionRun::where('predecessor_run_id', $run->id)->sole();
        $this->assertSame('REQUIRES_REVIEW', $successor->status->value);
        $this->assertNull($successor->total_numerator);
        $this->page($successor->id)->assertSee('Perbandingan revisi')->assertSee('Total eksak: Tidak tersedia')
            ->assertSee('REGIME_EVIDENCE_REQUIRES_REVIEW');
        $payload['sk_number'] = 'SYNTHETIC-RESTORED-SK';
        app(EmployeeHistoryService::class)->record($this->actor, $this->case->person, $payload, true, $history->id, 'Synthetic evidence restoration');
        $this->page($successor->id)->call('previewReplacement')->assertSet('replacementPredecessorRunId', $successor->id)
            ->assertSet('replacementPreviewDisplay.status', 'PREVIEW')->set('revisionReason', 'Restored source')->call('replaceConversion')->assertHasNoErrors();
        $this->assertDatabaseCount('pak_conversion_runs', 3);
    }

    public function test_non_saveable_preview_is_bounded_without_mutation_context(): void
    {
        $this->save();
        $this->rename();
        $preview = (new ConversionService)->preview($this->actor, new ConversionPreviewRequest($this->case->id, '2023-11-01', '2026-10-31'));
        $values = get_object_vars($preview);
        $values['issues'] = [['issue_code' => 'UNSUPPORTED', 'private' => 'PRIVATE-PATH']];
        $proxy = Mockery::mock(new ConversionService);
        $proxy->shouldReceive('preview')->once()->andReturn(new ConversionPreview(...$values));
        $proxy->shouldNotReceive('replace');
        app()->instance(ConversionService::class, $proxy);
        $this->page()->call('previewReplacement')->assertSee('Pratinjau revisi ini tidak dapat disimpan.')
            ->assertDontSee('PRIVATE-PATH')->assertSet('replacementPredecessorRunId', null)
            ->call('replaceConversion')->assertHasErrors('replacement');
    }

    public function test_latest_alias_after_successor_does_not_retarget_and_a_b_a_invalidates(): void
    {
        $run = $this->save();
        $this->rename();
        $page = $this->page()->assertSet('runId', null)->assertSet('displayedRunId', $run->id);
        $successor = $this->competingReplacement($run);
        $page->call('previewReplacement')->assertHasErrors('replacement')->assertSet('displayedRunId', $run->id)
            ->assertSet('replacementPredecessorRunId', null)->assertDontSee('Pratinjau revisi</span>', false);
        $this->rename('Another change');
        $page->set('runId', $successor->id)->call('previewReplacement')->set('revisionReason', 'Reason')
            ->set('runId', $run->id)->assertSet('replacementPreviewDisplay', [])->assertSet('revisionReason', '')
            ->set('runId', $successor->id)->call('replaceConversion')->assertHasErrors('replacement');
        $this->assertDatabaseCount('pak_conversion_runs', 2);
    }

    public function test_cross_case_run_fails_closed(): void
    {
        $run = $this->save();
        $foreign = $this->case->replicate();
        $foreign->save();
        $this->expectException(ModelNotFoundException::class);
        Livewire::test(CaseConversion::class, ['record' => $foreign->id])->set('runId', $run->id);
    }

    #[DataProvider('invalidators')]
    public function test_new_actions_invalidate_local_replacement_intent(string $action): void
    {
        $this->save();
        $this->rename();
        $page = $this->page()->call('assessConversion')->call('previewReplacement')->set('revisionReason', 'Old reason')
            ->set('requestedStart', '2023-11-01')->set('requestedEnd', '2026-10-31')->set('reviewReason', 'Review');
        $page->call($action)->assertHasNoErrors()->assertSet('replacementPredecessorRunId', null)
            ->assertSet('replacementPreviewDisplay', [])->assertSet('revisionReason', '');
    }

    public static function invalidators(): array
    {
        return [['previewConversion'], ['assessConversion'], ['reviewConversion']];
    }

    public function test_blank_reason_invalidates_but_reason_edits_do_not_invalidate(): void
    {
        $this->save();
        $this->rename();
        $page = $this->page()->call('previewReplacement');
        $token = $page->get('replacementExpectedSnapshotFingerprint');
        $before = $this->state();
        $page->set('revisionReason', str_repeat('Reason ', 300))->assertSet('replacementExpectedSnapshotFingerprint', $token)
            ->set('revisionReason', '  ')->call('replaceConversion')->assertHasErrors('replacement')->assertSee('Alasan revisi wajib diisi.')
            ->assertSet('replacementExpectedSnapshotFingerprint', null);
        $this->assertSame($before, $this->state());
    }

    #[DataProvider('drifts')]
    public function test_stale_requests_fail_atomically_without_preview_retry_or_retarget(string $drift): void
    {
        $run = $this->save();
        $this->rename();
        $page = $this->page()->call('previewReplacement')->set('revisionReason', 'Frozen reason');
        match ($drift) {
            'case' => DB::table('pak_cases')->where('id', $this->case->id)->increment('lock_version'),
            'dependency' => $this->rename('Further change'),
            'predecessor' => DB::table('pak_conversion_runs')->where('id', $run->id)->update(['status' => 'SUPERSEDED', 'active_case_id' => null, 'superseded_at' => now(), 'superseded_by_user_id' => $this->actor->id]),
            'competitor' => $this->competingReplacement($run),
        };
        $before = $this->state();
        $proxy = Mockery::mock(new ConversionService);
        $proxy->shouldNotReceive('preview');
        $proxy->shouldReceive('replace')->once()->andReturnUsing(fn ($actor, $request) => (new ConversionService)->replace($actor, $request));
        app()->instance(ConversionService::class, $proxy);
        $page->call('replaceConversion')->assertHasErrors('replacement')->assertNoRedirect()->assertSet('displayedRunId', $run->id)
            ->assertSet('replacementPredecessorRunId', null)->call('replaceConversion')->assertHasErrors('replacement');
        $this->assertSame($before, $this->state());
        $this->clean();
    }

    public static function drifts(): array
    {
        return [['case'], ['dependency'], ['predecessor'], ['competitor']];
    }

    public function test_external_review_preserves_frozen_replacement_and_replacement_makes_other_review_stale(): void
    {
        $run = $this->save();
        $this->rename();
        $replacePage = $this->page()->call('previewReplacement')->set('revisionReason', 'Replacement');
        $reviewPage = $this->page()->call('assessConversion')->set('reviewReason', 'External review');
        $reviewPage->call('reviewConversion')->assertHasNoErrors();
        $metadata = $run->fresh()->getRawOriginal('review_assessment');
        $staleReview = $this->page()->call('assessConversion')->set('reviewReason', 'Will become stale');
        $replacePage->call('replaceConversion')->assertHasNoErrors();
        $this->assertSame($metadata, $run->fresh()->getRawOriginal('review_assessment'));
        $before = $this->state();
        $staleReview->call('reviewConversion')->assertHasErrors('review')->assertNoRedirect();
        $this->assertSame($before, $this->state());
    }

    #[DataProvider('faults')]
    public function test_failure_after_write_is_atomic_and_never_retried(string $table): void
    {
        $this->save();
        $this->rename();
        $page = $this->page()->call('previewReplacement')->set('revisionReason', 'Failure');
        $before = $this->state();
        $hits = 0;
        DB::listen(function ($query) use ($table, &$hits) {
            $prefix = $table === 'pak_conversion_runs' ? 'update' : 'insert into';
            if (str_starts_with(strtolower($query->sql), $prefix.' "'.$table.'"')) {
                $hits++;
                throw new RuntimeException('PRIVATE SQL PATH TRACE');
            }
        });
        $page->call('replaceConversion')->assertHasErrors('replacement')->assertSee('Pembuatan revisi tidak dapat dikonfirmasi.')
            ->assertDontSee('PRIVATE')->assertSet('replacementPredecessorRunId', null)->assertNoRedirect();
        $this->assertSame(1, $hits);
        $this->assertSame($before, $this->state());
        $this->clean();
    }

    public static function faults(): array
    {
        return [['pak_conversion_runs'], ['pak_conversion_periods'], ['pak_events']];
    }

    public function test_uncertain_committed_result_is_not_return_existing_success(): void
    {
        $this->save();
        $this->rename();
        $page = $this->page()->call('previewReplacement')->set('revisionReason', 'Uncertain');
        $proxy = Mockery::mock(new ConversionService);
        $proxy->shouldReceive('replace')->once()->andReturnUsing(function ($actor, $request) {
            (new ConversionService)->replace($actor, $request);
            throw new RuntimeException('PRIVATE post-commit fault');
        });
        $proxy->shouldNotReceive('preview');
        app()->instance(ConversionService::class, $proxy);
        $page->call('replaceConversion')->assertHasErrors('replacement')->assertNoRedirect()->assertDontSee('PRIVATE')
            ->assertSet('replacementPredecessorRunId', null)->call('replaceConversion')->assertHasErrors('replacement')->assertNoRedirect();
        $this->assertDatabaseCount('pak_conversion_runs', 2);
        $this->assertSame(1, PakEvent::where('event_code', 'CONVERSION_RUN_REPLACED')->count());
    }

    public function test_comparison_is_persisted_adjacent_permission_aware_escaped_and_zero_write(): void
    {
        $run = $this->save();
        $this->rename();
        $successor = $this->competingReplacement($run);
        DB::table('functional_levels')->update(['name' => 'CURRENT-PRIVATE-MASTER']);
        DB::table('pak_conversion_periods')->where('pak_conversion_run_id', $run->id)->update([
            'source_snapshot' => json_encode(['source_reference' => '<script>OLD-SOURCE</script>', 'private_path' => 'PRIVATE-PATH']),
            'position_snapshot' => json_encode(['position_name' => 'OLD-POSITION']),
        ]);
        $before = $this->state();
        $conversion = Mockery::mock(new ConversionService);
        $conversion->shouldNotReceive('preview', 'replace', 'saveInitial');
        app()->instance(ConversionService::class, $conversion);
        $assessment = Mockery::mock(new ConversionAssessmentService);
        $assessment->shouldNotReceive('assess');
        app()->instance(ConversionAssessmentService::class, $assessment);
        $review = Mockery::mock(new ConversionReviewService);
        $review->shouldNotReceive('assessReview');
        app()->instance(ConversionReviewService::class, $review);
        DB::enableQueryLog();
        $page = $this->page($successor->id)->assertSee('Perbandingan revisi')->assertSee('Run sebelumnya')->assertSee('Run pengganti')
            ->assertSee('OLD-POSITION')->assertSee('&lt;script&gt;OLD-SOURCE&lt;/script&gt;', false)->assertDontSee('<script>OLD-SOURCE</script>', false)
            ->assertDontSee('PRIVATE-PATH')->assertDontSee('CURRENT-PRIVATE-MASTER')->assertDontSee($run->dependency_fingerprint)
            ->assertDontSee($successor->snapshot_fingerprint)->assertSee('Total eksak: 75/1')->assertSee('Tampilan total: 75')
            ->assertSee('Nomor baris antar revisi tidak menyatakan kesetaraan');
        $this->denied = 'pak.skp.view';
        $page->call('$refresh')->assertDontSee('OLD-SOURCE');
        $this->denied = 'pak.employee.view';
        $page->call('$refresh')->assertDontSee('OLD-POSITION');
        $this->denied = 'pak.rule-set.view';
        $page->call('$refresh')->assertDontSee('Kode aturan:');
        $page->set('runId', $run->id)->assertDontSee('Perbandingan revisi')->assertSee('SUPERSEDED');
        $writes = array_filter(DB::getQueryLog(), fn ($q) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $q['query']));
        DB::disableQueryLog();
        $this->assertSame([], array_values($writes));
        $this->assertSame($before, $this->state());
        foreach (['APPROVED', 'DISETUJUI', 'DITETAPKAN', 'FINALIZED', 'PENETAPAN AK'] as $word) {
            $page->assertDontSee($word);
        }
    }

    #[DataProvider('invalidPairs')]
    public function test_comparison_identity_fails_closed(string $invalid): void
    {
        $run = $this->save();
        $this->rename();
        $successor = $this->competingReplacement($run);
        $query = app(ConversionRunQuery::class);
        $previous = $query->find($this->actor, $this->case->id, $run->id);
        $selected = $query->find($this->actor, $this->case->id, $successor->id);
        match ($invalid) {
            'case' => $previous->pak_case_id = 999,
            'link' => $selected->predecessor_run_id = 999,
            'revision' => $selected->revision_number = 99,
        };
        $this->assertSame([], app(ConversionPresenter::class)->comparisonProjection($this->actor, $previous, $selected, fn ($id) => (string) $id));
    }

    public static function invalidPairs(): array
    {
        return [['case'], ['link'], ['revision']];
    }

    #[DataProvider('ranges')]
    public function test_backend_same_range_rejection_remains_safe_and_atomic(string $field, string $value): void
    {
        $this->save();
        $this->rename();
        $page = $this->page()->call('previewReplacement')->set('revisionReason', 'Synthetic reason');
        $before = $this->state();
        $proxy = Mockery::mock(new ConversionService);
        // Fault injection at the backend boundary; the browser itself cannot edit locked dates.
        $proxy->shouldReceive('replace')->once()->andReturnUsing(fn ($actor, $request) => (new ConversionService)->replace($actor,
            ConversionReplaceRequest::from(array_replace(get_object_vars($request), [$field => $value]))));
        app()->instance(ConversionService::class, $proxy);
        $page->call('replaceConversion')->assertHasErrors('replacement')->assertSee('rentang harus tetap sama')
            ->assertSet('replacementPredecessorRunId', null)->assertNoRedirect();
        $this->assertSame($before, $this->state());
    }

    public static function ranges(): array
    {
        return [['requested_start', '2023-01-01'], ['requested_start', '2024-01-01'],
            ['requested_end', '2026-12-31'], ['requested_end', '2025-12-31']];
    }

    #[DataProvider('errors')]
    public function test_exception_payloads_never_leak_or_retry(string $action, string $method, string $kind): void
    {
        $this->save();
        $this->rename();
        $page = $this->page()->call('previewReplacement')->set('revisionReason', 'Reason');
        $before = $this->state();
        $error = match ($kind) {
            'validation' => ValidationException::withMessages(['PRIVATE-SQL' => 'PRIVATE-PATH']),
            'domain' => new ConversionRuleException('PRIVATE-PATH'),
            default => new RuntimeException('PRIVATE-PATH'),
        };
        $proxy = Mockery::mock(new ConversionService);
        $proxy->shouldReceive($method)->once()->andThrow($error);
        app()->instance(ConversionService::class, $proxy);
        $page->call($action)->assertHasErrors('replacement')->assertDontSee('PRIVATE')
            ->assertSet('replacementPredecessorRunId', null)->assertSet('replacementPreviewDisplay', []);
        $this->assertSame($before, $this->state());
    }

    public static function errors(): array
    {
        $rows = [];
        foreach (self::actions() as [$action, $method]) {
            foreach (['validation', 'domain', 'technical'] as $kind) {
                $rows[] = [$action, $method, $kind];
            }
        }

        return $rows;
    }
}
