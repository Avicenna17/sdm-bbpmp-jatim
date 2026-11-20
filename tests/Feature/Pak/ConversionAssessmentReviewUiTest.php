<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\DTOs\ConversionAssessment;
use App\Domain\Pak\DTOs\ConversionAssessmentRequest;
use App\Domain\Pak\DTOs\ConversionPreviewRequest;
use App\Domain\Pak\DTOs\ConversionReplaceRequest;
use App\Domain\Pak\DTOs\ConversionReviewRequest;
use App\Domain\Pak\DTOs\ConversionSaveRequest;
use App\Domain\Pak\Exceptions\ConversionRuleException;
use App\Domain\Pak\Services\ConversionAssessmentService;
use App\Domain\Pak\Services\ConversionReviewService;
use App\Domain\Pak\Services\ConversionService;
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

class ConversionAssessmentReviewUiTest extends TestCase
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

    public function test_assessment_is_explicit_zero_write_and_projection_contains_no_hashes(): void
    {
        $run = $this->save();
        $this->rename();
        $before = $this->state();
        $service = Mockery::mock(new ConversionAssessmentService);
        $service->shouldReceive('assess')->once()->andReturnUsing(fn ($actor, $request) => (new ConversionAssessmentService)->assess($actor, $request));
        app()->instance(ConversionAssessmentService::class, $service);
        DB::enableQueryLog();
        $page = $this->page()->assertSet('assessmentDisplay', [])->call('$refresh')->set('runId', $run->id)
            ->assertSet('assessmentDisplay', [])->call('assessConversion')->assertHasNoErrors()
            ->assertSet('assessmentDisplay.comparison', 'CHANGED')->assertSet('assessedRunId', $run->id)
            ->assertSee('history_dependencies');
        $writes = array_filter(DB::getQueryLog(), fn ($q) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $q['query']));
        DB::disableQueryLog();
        $this->assertSame([], array_values($writes));
        $this->assertSame($before, $this->state());
        $projection = json_encode($page->get('assessmentDisplay'));
        foreach (['fingerprint', 'manifest', 'snapshot', 'person_id'] as $key) {
            $this->assertStringNotContainsString($key, $projection);
        }
        $page->assertDontSee($page->get('reviewExpectedAssessmentFingerprint'));
        $this->clean();
    }

    public function test_review_persists_same_run_exact_frozen_metadata_and_redirects_without_calculation_mutation(): void
    {
        $run = $this->save();
        $this->rename();
        $before = $this->state();
        $frozen = $this->assessment($run);
        $reviewService = Mockery::mock(new ConversionReviewService);
        $reviewService->shouldReceive('assessReview')->once()->andReturnUsing(function ($actor, $request) use ($frozen, $run) {
            $this->assertSame($this->actor->id, $actor->id);
            $this->assertSame([
                'pak_case_id' => $this->case->id, 'run_id' => $run->id,
                'expected_run_status' => $frozen->observed_run_status,
                'expected_case_lock_version' => $frozen->observed_case_lock_version,
                'expected_assessment_sequence' => $frozen->assessment_sequence,
                'expected_assessment_fingerprint' => $frozen->assessment_fingerprint,
                'reason' => '<script>review-note</script>',
            ], get_object_vars($request));

            return (new ConversionReviewService)->assessReview($actor, $request);
        });
        app()->instance(ConversionReviewService::class, $reviewService);
        $page = $this->page()->call('assessConversion')->set('reviewReason', ' <script>review-note</script> ')
            ->assertSet('reviewExpectedAssessmentFingerprint', $frozen->assessment_fingerprint)
            ->call('reviewConversion')->assertHasNoErrors()->assertSet('assessmentDisplay', [])
            ->assertSet('reviewExpectedAssessmentFingerprint', null)->assertSet('reviewReason', '');
        $url = PakCaseResource::getUrl('conversion', ['record' => $this->case->id, 'run' => $run->id]);
        $page->assertRedirect($url);
        $run->refresh();
        $this->assertSame('REQUIRES_REVIEW', $run->status->value);
        $this->assertSame(1, $run->review_assessment['assessment_sequence']);
        $this->assertSame($frozen->assessment_fingerprint, $run->review_assessment['assessment_fingerprint']);
        $this->assertSame($this->actor->id, $run->review_assessed_by);
        $this->assertNotNull($run->review_assessed_at);
        $after = $this->state();
        foreach ($before as $table => $rows) {
            if (! in_array($table, ['pak_conversion_runs', 'pak_events'])) {
                $this->assertSame($rows, $after[$table], $table);
            }
        }
        $allowed = array_flip(['status', 'review_assessment', 'review_assessed_by', 'review_assessed_at', 'updated_at']);
        $this->assertSame(array_diff_key($before['pak_conversion_runs'][0], $allowed), array_diff_key($after['pak_conversion_runs'][0], $allowed));
        $this->assertCount(1, $after['pak_conversion_runs']);
        $this->assertSame($before['pak_events'], array_slice($after['pak_events'], 0, count($before['pak_events'])));
        $this->assertCount(count($before['pak_events']) + 1, $after['pak_events']);
        $this->assertSame(1, PakEvent::where('event_code', 'CONVERSION_RUN_REVIEW_ASSESSED')->count());
        $this->assertSame(0, PakEvent::where('event_code', 'CONVERSION_RUN_REPLACED')->count());
        $this->get($url)->assertOk()->assertSee('Total eksak: 75/1')->assertSee('Urutan asesmen: 1')
            ->assertSee('&lt;script&gt;review-note&lt;/script&gt;', false)->assertDontSee('<script>review-note</script>', false)
            ->assertDontSee($frozen->assessment_fingerprint);
        $this->clean();
    }

    #[DataProvider('eligibility')]
    public function test_backend_outcome_status_eligibility(string $status, string $outcome, bool $eligible): void
    {
        $run = $this->save($status === 'REQUIRES_REVIEW' ? '2023-11-15' : '2023-11-01');
        if ($outcome === 'CHANGED') {
            if ($status === 'REQUIRES_REVIEW') {
                // Partial-month diagnostics need not read master dependencies.
                DB::table('pak_cases')->where('id', $this->case->id)->update(['purpose_type' => 'KJ']);
            } else {
                $this->rename();
            }
        } elseif ($outcome === 'UNASSESSABLE') {
            DB::table('pak_rule_sets')->where('id', $this->rule->id)->update(['status' => 'RETIRED']);
        }
        $page = $this->page()->call('assessConversion')->assertHasNoErrors()->assertSee('Hasil asesmen: '.$outcome)
            ->assertSet('assessmentDisplay.comparison', $outcome)->assertSet('assessedRunId', $eligible ? $run->id : null);
        if ($outcome === 'UNASSESSABLE') {
            $page->assertSee('RULE_SET_NOT_USABLE');
        }
        if ($status === 'REQUIRES_REVIEW') {
            $page->assertSee('PARTIAL_MONTH_REQUIRES_REVIEW');
        }
        $before = $this->state();
        $page->set('reviewReason', 'Synthetic review')->call('reviewConversion');
        if ($eligible) {
            $page->assertHasNoErrors();
            $this->assertSame(1, $run->fresh()->review_assessment['assessment_sequence']);
        } else {
            $page->assertHasErrors('review');
            $this->assertSame($before, $this->state());
        }
    }

    public static function eligibility(): array
    {
        return [['PREVIEW', 'UNCHANGED', false], ['PREVIEW', 'CHANGED', true], ['PREVIEW', 'UNASSESSABLE', true],
            ['REQUIRES_REVIEW', 'UNCHANGED', true], ['REQUIRES_REVIEW', 'CHANGED', true], ['REQUIRES_REVIEW', 'UNASSESSABLE', true]];
    }

    #[DataProvider('denials')]
    public function test_each_permission_is_rechecked_after_mount(string $permission, string $action): void
    {
        $this->save();
        $this->rename();
        $page = $this->page()->call('assessConversion');
        $before = $this->state();
        $this->denied = $permission;
        $page->call($action)->assertForbidden();
        $this->assertSame($before, $this->state());
    }

    public static function denials(): array
    {
        $rows = [];
        foreach (['pak.access', ...ConversionService::PERMISSIONS] as $permission) {
            foreach (['assessConversion', 'reviewConversion'] as $action) {
                $rows[] = [$permission, $action];
            }
        }

        return $rows;
    }

    public function test_read_only_access_and_permission_loss_clear_protected_state(): void
    {
        $this->save();
        $this->rename();
        $page = $this->page()->call('assessConversion')->set('reviewReason', 'Private review reason');
        $this->denied = 'pak.skp.view';
        $page->call('$refresh')->assertSet('assessmentDisplay', [])->assertSet('assessedRunId', null)
            ->assertSet('reviewExpectedAssessmentFingerprint', null)->assertSet('reviewReason', '')
            ->assertDontSee('Periksa perubahan')->assertDontSee('Catat tinjauan')->assertSee('Total eksak: 75/1');
        $this->page()->call('assessConversion')->assertForbidden();
        $this->page()->call('reviewConversion')->assertForbidden();
    }

    #[DataProvider('actions')]
    public function test_inactive_actor_and_backend_http_denial(string $action, string $service, string $method): void
    {
        $this->save();
        $this->rename();
        $page = $this->page()->call('assessConversion')->set('reviewReason', 'Synthetic review');
        $proxy = Mockery::mock(new $service);
        $proxy->shouldReceive($method)->once()->andThrow(new HttpException(403, 'PRIVATE denial'));
        app()->instance($service, $proxy);
        $page->call($action)->assertForbidden();
        app()->instance($service, new $service);
        $page = $this->page()->call('assessConversion')->set('reviewReason', 'Synthetic review');
        $this->actor->forceFill(['is_active' => false])->save();
        $page->call($action)->assertForbidden();
        $this->assertSame(0, PakEvent::where('event_code', 'CONVERSION_RUN_REVIEW_ASSESSED')->count());
    }

    public static function actions(): array
    {
        return [['assessConversion', ConversionAssessmentService::class, 'assess'], ['reviewConversion', ConversionReviewService::class, 'assessReview']];
    }

    #[DataProvider('lockedFields')]
    public function test_server_context_cannot_be_replaced_by_browser(string $field, mixed $value): void
    {
        $this->save();
        $this->rename();
        $page = $this->page()->call('assessConversion');
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set($field, $value);
    }

    public static function lockedFields(): array
    {
        return [['displayedRunId', 999], ['assessedCaseId', 999], ['assessedRunId', 999], ['reviewExpectedRunStatus', 'SUPERSEDED'],
            ['reviewExpectedCaseLockVersion', 99], ['reviewExpectedAssessmentSequence', 99],
            ['reviewExpectedAssessmentFingerprint', str_repeat('a', 64)], ['assessmentDisplay', ['comparison' => 'UNCHANGED']]];
    }

    private function successor(PakConversionRun $run): PakConversionRun
    {
        // A competing backend writer is test setup only; no replacement UI is introduced.
        $this->rename('Synthetic successor label');
        $preview = app(ConversionService::class)->preview($this->actor, new ConversionPreviewRequest($this->case->id, '2023-11-01', '2026-10-31'));

        return app(ConversionService::class)->replace($this->actor, ConversionReplaceRequest::from([
            'pak_case_id' => $this->case->id, 'expected_predecessor_run_id' => $run->id,
            'requested_start' => '2023-11-01', 'requested_end' => '2026-10-31',
            'expected_case_lock_version' => $preview->expected_case_version,
            'expected_dependency_fingerprint' => $preview->dependency_fingerprint,
            'expected_snapshot_fingerprint' => $preview->snapshot_fingerprint, 'revision_reason' => 'Synthetic competitor',
        ]));
    }

    public function test_latest_alias_assesses_exact_displayed_historical_run_after_successor_appears(): void
    {
        $run = $this->save();
        $page = $this->page()->assertSet('runId', null)->assertSet('displayedRunId', $run->id);
        $successor = $this->successor($run);
        $before = $this->state();
        $page->call('assessConversion')->assertHasNoErrors()->assertSet('displayedRunId', $run->id)
            ->assertSet('assessmentDisplay.status', 'SUPERSEDED')->assertSet('assessmentDisplay.revision', 1)
            ->assertSet('assessmentDisplay.active', false)->assertSet('assessedRunId', null)
            ->assertDontSee('Catat tinjauan')->set('reviewReason', 'Cannot review history')->call('reviewConversion')->assertHasErrors('review');
        $this->assertSame($before, $this->state());
        $this->assertNull($successor->fresh()->review_assessment);
    }

    public function test_run_switch_a_b_a_and_new_preview_invalidate_without_automatic_assessment(): void
    {
        $old = $this->save();
        $current = $this->successor($old);
        $this->rename();
        $page = $this->page()->call('assessConversion')->set('reviewReason', 'Synthetic review');
        $proxy = Mockery::mock(new ConversionAssessmentService);
        $proxy->shouldNotReceive('assess');
        app()->instance(ConversionAssessmentService::class, $proxy);
        $page->set('runId', $old->id)->assertSet('assessmentDisplay', [])->assertSet('reviewReason', '')
            ->set('runId', $current->id)->assertSet('reviewExpectedAssessmentFingerprint', null)->call('reviewConversion')->assertHasErrors('review');
        app()->instance(ConversionAssessmentService::class, new ConversionAssessmentService);
        $page->call('assessConversion')->set('requestedStart', '2023-11-01')->set('requestedEnd', '2026-10-31')
            ->call('previewConversion')->assertSet('assessmentDisplay', [])->assertSet('assessedRunId', null);
    }

    public function test_successor_after_assessment_does_not_retarget_review(): void
    {
        $run = $this->save();
        $this->rename();
        $page = $this->page()->call('assessConversion')->set('reviewReason', 'Frozen old run');
        $successor = $this->successor($run);
        $before = $this->state();
        $page->call('reviewConversion')->assertHasErrors('review')->assertNoRedirect()
            ->assertSet('displayedRunId', $run->id)->assertSet('reviewExpectedAssessmentFingerprint', null);
        $this->assertSame($before, $this->state());
        $this->assertNull($successor->fresh()->review_assessment);
    }

    public function test_cross_case_selection_is_rejected_without_assessment(): void
    {
        $run = $this->save();
        $foreign = $this->case->replicate();
        $foreign->save();
        $proxy = Mockery::mock(new ConversionAssessmentService);
        $proxy->shouldNotReceive('assess');
        app()->instance(ConversionAssessmentService::class, $proxy);
        $this->expectException(ModelNotFoundException::class);
        Livewire::test(CaseConversion::class, ['record' => $foreign->id])->set('runId', $run->id);
    }

    public function test_reason_edits_preserve_token_but_blank_reason_invalidates_on_submit(): void
    {
        $this->save();
        $this->rename();
        $page = $this->page()->call('assessConversion');
        $token = $page->get('reviewExpectedAssessmentFingerprint');
        $before = $this->state();
        $page->set('reviewReason', 'Edited reason')->assertSet('reviewExpectedAssessmentFingerprint', $token)
            ->set('reviewReason', '  ')->call('reviewConversion')->assertHasErrors('review')->assertSee('Alasan tinjauan wajib diisi.')
            ->assertSet('reviewExpectedAssessmentFingerprint', null);
        $this->assertSame($before, $this->state());
    }

    #[DataProvider('staleStates')]
    public function test_stale_review_is_atomic_invalidates_tokens_and_never_reassesses_or_retries(string $change, string $message): void
    {
        $run = $this->save($change === 'sequence' ? '2023-11-15' : '2023-11-01');
        $this->rename();
        $page = $this->page()->call('assessConversion')->set('reviewReason', 'Synthetic review');
        if ($change === 'case') {
            DB::table('pak_cases')->where('id', $this->case->id)->increment('lock_version');
        } elseif ($change === 'status') {
            DB::table('pak_conversion_runs')->where('id', $run->id)->update(['status' => 'REQUIRES_REVIEW']);
        } elseif ($change === 'sequence') {
            $assessment = $this->assessment($run);
            app(ConversionReviewService::class)->assessReview($this->actor, ConversionReviewRequest::from([
                'pak_case_id' => $this->case->id, 'run_id' => $run->id, 'expected_run_status' => $assessment->observed_run_status,
                'expected_case_lock_version' => $assessment->observed_case_lock_version,
                'expected_assessment_sequence' => $assessment->assessment_sequence,
                'expected_assessment_fingerprint' => $assessment->assessment_fingerprint, 'reason' => 'Competing review',
            ]));
        } else {
            DB::table('functional_levels')->where('id', $this->level->id)->update(['name' => 'Another current label']);
        }
        $before = $this->state();
        $assessmentService = Mockery::mock(new ConversionAssessmentService);
        $assessmentService->shouldNotReceive('assess');
        app()->instance(ConversionAssessmentService::class, $assessmentService);
        $reviewService = Mockery::mock(new ConversionReviewService);
        $reviewService->shouldReceive('assessReview')->once()->andReturnUsing(fn ($actor, $request) => (new ConversionReviewService)->assessReview($actor, $request));
        app()->instance(ConversionReviewService::class, $reviewService);
        $page->call('reviewConversion')->assertHasErrors('review')->assertSee($message)->assertNoRedirect()
            ->assertSet('reviewExpectedAssessmentFingerprint', null)->call('reviewConversion')->assertHasErrors('review');
        $this->assertSame($before, $this->state());
        $this->clean();
    }

    public static function staleStates(): array
    {
        return [['case', 'Konteks case berubah'], ['status', 'Status run berubah'], ['sequence', 'Urutan asesmen berubah'], ['fingerprint', 'Keadaan yang dinilai telah berubah']];
    }

    public function test_mapping_plan_drift_uses_safe_review_error_and_leaves_no_partial_review(): void
    {
        DB::table('functional_position_levels')->delete();
        $this->save();
        $this->rename();
        $page = $this->page()->call('assessConversion')->set('reviewReason', 'Synthetic review');
        $watch = true;
        $before = null;
        DB::connection()->beforeStartingTransaction(function () use (&$watch, &$before) {
            if (! $watch) {
                return;
            }
            $watch = false;
            app(FunctionalMasterService::class)->save($this->actor, 'mapping', [
                'functional_position_id' => $this->history->functional_position_id, 'functional_level_id' => $this->level->id, 'is_active' => true,
            ]);
            $before = $this->state();
        });
        $page->call('reviewConversion')->assertHasErrors('review')->assertSee('Rencana dependensi berubah.')
            ->assertSet('reviewExpectedAssessmentFingerprint', null);
        $this->assertNotNull($before);
        $this->assertSame($before, $this->state());
    }

    #[DataProvider('failures')]
    public function test_exception_boundaries_never_fabricate_assessment_or_leak_details(string $action, string $service, string $method, string $kind): void
    {
        $this->save();
        $this->rename();
        $page = $this->page()->call('assessConversion')->set('reviewReason', 'Synthetic review');
        $before = $this->state();
        $error = match ($kind) {
            'validation' => ValidationException::withMessages(['PRIVATE SQL' => 'PRIVATE PATH TRACE']),
            'domain' => new ConversionRuleException('PRIVATE SQL PATH TRACE'),
            default => new RuntimeException('PRIVATE SQL PATH TRACE'),
        };
        $proxy = Mockery::mock(new $service);
        $proxy->shouldReceive($method)->once()->andThrow($error);
        app()->instance($service, $proxy);
        $page->call($action)->assertHasErrors($action === 'assessConversion' ? 'assessment' : 'review')
            ->assertSet('assessmentDisplay', [])->assertSet('reviewExpectedAssessmentFingerprint', null)
            ->assertDontSee('PRIVATE')->assertDontSee('Hasil asesmen: UNASSESSABLE');
        $this->assertSame($before, $this->state());
    }

    public static function failures(): array
    {
        $rows = [];
        foreach (self::actions() as $action) {
            foreach (['validation', 'domain', 'technical'] as $kind) {
                $rows[] = [...$action, $kind];
            }
        }

        return $rows;
    }

    public function test_review_failure_after_update_rolls_back_once(): void
    {
        $this->save();
        $this->rename();
        $page = $this->page()->call('assessConversion')->set('reviewReason', 'Synthetic review');
        $before = $this->state();
        $updates = 0;
        DB::listen(function ($query) use (&$updates) {
            if (str_starts_with(strtolower($query->sql), 'update "pak_conversion_runs"')) {
                $updates++;
                throw new RuntimeException('PRIVATE SQL PATH TRACE');
            }
        });
        $page->call('reviewConversion')->assertHasErrors('review')->assertDontSee('PRIVATE')
            ->assertSet('reviewExpectedAssessmentFingerprint', null);
        $this->assertSame(1, $updates);
        $this->assertSame($before, $this->state());
        $this->clean();
    }

    public function test_malformed_metadata_is_omitted_and_assessment_fails_safely(): void
    {
        $run = $this->save();
        DB::table('pak_conversion_runs')->where('id', $run->id)->update(['review_assessment' => json_encode([
            'schema_version' => 99, 'reason' => '<script>PRIVATE-METADATA</script>', 'assessment_fingerprint' => 'SECRET',
        ])]);
        $before = $this->state();
        $this->page()->assertDontSee('PRIVATE-METADATA')->assertDontSee('SECRET')->call('assessConversion')
            ->assertHasErrors('assessment')->assertSee('Metadata tinjauan tidak dapat digunakan.')
            ->assertSet('assessmentDisplay', [])->assertSet('assessedRunId', null);
        $this->assertSame($before, $this->state());
    }

    #[DataProvider('duplicateMetadataLists')]
    public function test_duplicate_persisted_review_lists_are_omitted_without_repair(string $field, string $value): void
    {
        $run = $this->save();
        $this->rename();
        $this->page()->call('assessConversion')->set('reviewReason', 'PRIVATE-METADATA')->call('reviewConversion')->assertHasNoErrors();
        $metadata = $run->fresh()->review_assessment;
        $metadata[$field] = [$value, $value];
        $raw = json_encode($metadata, JSON_THROW_ON_ERROR);
        DB::table('pak_conversion_runs')->where('id', $run->id)->update(['review_assessment' => $raw]);
        $before = $this->state();
        DB::enableQueryLog();
        $this->page()->assertDontSee('Alasan tinjauan:')->assertDontSee('Tinjauan dicatat pada:')
            ->assertDontSee('PRIVATE-METADATA')->assertDontSee($metadata['assessment_fingerprint'])
            ->call('assessConversion')->assertHasErrors('assessment')->assertSee('Metadata tinjauan tidak dapat digunakan.')
            ->assertSet('assessmentDisplay', [])->assertSet('assessedRunId', null);
        $writes = array_filter(DB::getQueryLog(), fn ($q) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $q['query']));
        DB::disableQueryLog();
        $this->assertSame([], array_values($writes));
        $this->assertSame($before, $this->state());
        $this->assertSame($raw, $run->fresh()->getRawOriginal('review_assessment'));
        $this->clean();
    }

    public static function duplicateMetadataLists(): array
    {
        return [['changed_groups', 'history_dependencies'], ['current_issue_codes', 'SOURCE_NOT_FOUND']];
    }

    public function test_repeated_explicit_review_increments_sequence_only_after_new_assessment(): void
    {
        $run = $this->save('2023-11-15');
        $page = $this->page()->call('assessConversion')->set('reviewReason', 'First review')->call('reviewConversion');
        $this->assertSame(1, $run->fresh()->review_assessment['assessment_sequence']);
        $page = $this->page()->call('reviewConversion')->assertHasErrors('review');
        $reason = str_repeat('Synthetic reason ', 200);
        $page->call('assessConversion')->assertSet('reviewExpectedAssessmentSequence', 1)
            ->set('reviewReason', $reason)->call('reviewConversion')->assertHasNoErrors();
        $this->assertSame(2, $run->fresh()->review_assessment['assessment_sequence']);
        $this->assertSame(trim($reason), $run->fresh()->review_assessment['reason']);
        $this->assertSame(2, PakEvent::where('event_code', 'CONVERSION_RUN_REVIEW_ASSESSED')->count());
        $this->assertDatabaseCount('pak_conversion_runs', 1);
    }

    public function test_case_boundary_keeps_assessment_read_only_without_review_context(): void
    {
        $this->save();
        $this->rename();
        DB::table('pak_cases')->where('id', $this->case->id)->update(['finalized_at' => now()]);
        $before = $this->state();
        $this->page()->call('assessConversion')->assertHasNoErrors()->assertSet('assessedRunId', null)
            ->assertDontSee('Catat tinjauan')->set('reviewReason', 'Not allowed')->call('reviewConversion')->assertHasErrors('review');
        $this->assertSame($before, $this->state());
    }

    public function test_projection_whitelists_groups_codes_and_no_mutation_operand_inputs_or_forbidden_actions_exist(): void
    {
        $run = $this->save();
        $values = get_object_vars($this->assessment($run));
        $values['changed_groups'] = ['history_dependencies', 'PRIVATE/PATH'];
        $values['current_issue_codes'] = ['SOURCE_NOT_FOUND', '<script>PRIVATE</script>'];
        $values['failure_code'] = 'PRIVATE/PATH';
        $display = app(ConversionPresenter::class)->assessmentProjection(new ConversionAssessment(...$values));
        $this->assertSame(['history_dependencies'], $display['changed_groups']);
        $this->assertSame(['SOURCE_NOT_FOUND'], $display['issue_codes']);
        $this->assertNull($display['failure_code']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($display));
        $page = $this->page();
        foreach (['assess', 'assessReview', 'replace'] as $method) {
            $this->assertFalse(method_exists($page->instance(), $method));
        }
        foreach (['total', 'status', 'revision', 'factor', 'coefficient', 'source', 'position', 'replacementTarget'] as $property) {
            $this->assertFalse(property_exists($page->instance(), $property));
        }
        foreach (['APPROVED', 'DISETUJUI', 'DITETAPKAN', 'FINALIZED', 'PENETAPAN AK'] as $word) {
            $page->assertDontSee($word);
        }
        $page->call('assessConversion', ['run_id' => 999, 'comparison' => 'CHANGED'])->assertSet('assessmentDisplay.comparison', 'UNCHANGED');
    }
}
