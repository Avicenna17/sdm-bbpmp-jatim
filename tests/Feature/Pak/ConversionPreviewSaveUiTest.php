<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\DTOs\ConversionPreview;
use App\Domain\Pak\DTOs\ConversionPreviewRequest;
use App\Domain\Pak\Services\ConversionService;
use App\Filament\Pak\Resources\PakCaseResource;
use App\Filament\Pak\Resources\PakCaseResource\Pages\CaseConversion;
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
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ConversionPreviewSaveUiTest extends TestCase
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

    private function page()
    {
        return Livewire::test(CaseConversion::class, ['record' => $this->case->id])
            ->set('requestedStart', '2023-11-01')->set('requestedEnd', '2026-10-31');
    }

    private function emptySave(): void
    {
        $this->assertDatabaseCount('pak_conversion_runs', 0);
        $this->assertDatabaseCount('pak_conversion_periods', 0);
        $this->assertSame(0, PakEvent::where('event_code', 'CONVERSION_RUN_SAVED')->count());
        $this->assertSame($this->case->lock_version, $this->case->fresh()->lock_version);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function test_preview_zero_writes_projection_and_successful_exact_run_redirect(): void
    {
        $before = $this->rule->fresh()->getRawOriginal();
        DB::enableQueryLog();
        $page = $this->page()->call('previewConversion')->assertHasNoErrors()->assertSet('previewDisplay.status', 'PREVIEW')
            ->assertSee('Total eksak pratinjau: 75')->assertSee('Tampilan hasil pratinjau: 4,167');
        $writes = array_filter(DB::getQueryLog(), fn ($q) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $q['query']));
        DB::disableQueryLog();
        $this->assertSame([], array_values($writes));
        $this->assertSame($before, $this->rule->fresh()->getRawOriginal());
        $this->emptySave();
        $projection = json_encode($page->get('previewDisplay'));
        foreach (['fingerprint', 'dependencies', 'manifest', 'snapshot', 'person_id'] as $key) {
            $this->assertStringNotContainsString($key, $projection);
        }
        $page->call('saveInitialConversion')->assertHasNoErrors()->assertSet('previewDisplay', [])->assertSet('expectedSnapshotFingerprint', null);
        $run = PakConversionRun::sole();
        $url = PakCaseResource::getUrl('conversion', ['record' => $this->case->id, 'run' => $run->id]);
        $page->assertRedirect($url);
        $this->assertSame('75', $run->total_numerator);
        $this->assertSame(1, $run->revision_number);
        $this->assertSame(4, $run->periods()->count());
        $this->assertSame(1, PakEvent::where('event_code', 'CONVERSION_RUN_SAVED')->count());
        $this->get($url)->assertOk()->assertSee('Total eksak: 75/1');
    }

    #[DataProvider('denials')]
    public function test_each_permission_rechecked_on_both_actions(string $permission, string $action): void
    {
        $page = $this->page()->call('previewConversion');
        $this->denied = $permission;
        $page->call($action)->assertForbidden();
        $this->emptySave();
    }

    public static function denials(): array
    {
        $rows = [];
        foreach (['pak.access', ...ConversionService::PERMISSIONS] as $permission) {
            foreach (['previewConversion', 'saveInitialConversion'] as $action) {
                $rows[] = [$permission, $action];
            }
        }

        return $rows;
    }

    public function test_read_only_user_keeps_saved_read_access_but_cannot_call_actions(): void
    {
        $this->denied = 'pak.conversion.calculate';
        $this->page()->assertSee('Belum ada run')->assertDontSee('Simpan konversi awal')->call('previewConversion')->assertForbidden();
        $this->page()->call('saveInitialConversion')->assertForbidden();
        $this->emptySave();
    }

    public function test_inactive_actor_and_revoked_permission_cannot_reuse_projection(): void
    {
        $page = $this->page()->call('previewConversion');
        $this->denied = 'pak.skp.view';
        $page->call('$refresh')->assertSet('previewDisplay', [])->assertSet('expectedCaseLockVersion', null);
        $this->denied = null;
        $page = $this->page()->call('previewConversion');
        $this->actor->forceFill(['is_active' => false])->save();
        $page->call('saveInitialConversion')->assertForbidden();
        Livewire::test(CaseConversion::class, ['record' => $this->case->id])->assertForbidden();
        $this->emptySave();
    }

    #[DataProvider('invalidRanges')]
    public function test_invalid_range_is_safe_and_never_saveable(string $start, string $end, string $field): void
    {
        $this->page()->set('requestedStart', $start)->set('requestedEnd', $end)->call('previewConversion')
            ->assertHasErrors($field)->assertSet('expectedCaseLockVersion', null);
        $this->emptySave();
    }

    public static function invalidRanges(): array
    {
        return [['2023-02-30', '2026-10-31', 'requested_start'], ['2026-11-01', '2026-10-31', 'requested_end'], ['2022-01-01', '2026-10-31', 'requested_range']];
    }

    #[DataProvider('rangeChanges')]
    public function test_range_change_and_reversion_require_new_preview(string $field, string $changed, string $original): void
    {
        $page = $this->page()->call('previewConversion')->set($field, $changed)->assertSet('previewDisplay', [])->assertSet('expectedDependencyFingerprint', null);
        $page->set($field, $original)->call('saveInitialConversion')->assertHasErrors('conversion');
        $this->emptySave();
    }

    public static function rangeChanges(): array
    {
        return [['requestedStart', '2023-12-01', '2023-11-01'], ['requestedEnd', '2026-09-30', '2026-10-31']];
    }

    public function test_partial_month_reviewable_save_preserves_dates_and_unavailable_total(): void
    {
        $page = $this->page()->set('requestedStart', '2023-11-15')->call('previewConversion')
            ->assertSet('previewDisplay.status', 'REQUIRES_REVIEW')->assertSee('PARTIAL_MONTH_REQUIRES_REVIEW')->assertSee('Tampilan total pratinjau: Tidak tersedia');
        $page->call('saveInitialConversion')->assertHasNoErrors();
        $run = PakConversionRun::sole();
        $this->assertNull($run->total_numerator);
        $this->assertSame('2023-11-15', $run->requested_start->format('Y-m-d'));
        $this->assertSame('REQUIRES_REVIEW', $run->status->value);
    }

    public function test_missing_source_has_unavailable_line_and_escaped_provenance(): void
    {
        DB::table('performance_evaluations')->where('performance_year', 2026)->delete();
        DB::table('performance_evaluations')->update(['source_reference' => '<script>source-label</script>']);
        $this->page()->call('previewConversion')->assertSet('previewDisplay.status', 'REQUIRES_REVIEW')
            ->assertSee('SOURCE_NOT_FOUND')->assertSee('Hasil eksak pratinjau: Tidak tersedia')
            ->assertSee('&lt;script&gt;source-label&lt;/script&gt;', false)->assertDontSee('<script>source-label</script>', false);
    }

    #[DataProvider('lockedFields')]
    public function test_locked_context_and_preconditions_reject_browser_tampering(string $field, mixed $value): void
    {
        $page = $this->page()->call('previewConversion');
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set($field, $value);
    }

    public static function lockedFields(): array
    {
        return [['caseId', 999], ['expectedCaseLockVersion', 99], ['expectedDependencyFingerprint', str_repeat('a', 64)], ['expectedSnapshotFingerprint', str_repeat('b', 64)], ['frozenRequestedStart', '2024-01-01'], ['previewDisplay', ['status' => 'APPROVED']]];
    }

    public function test_authoritative_operands_are_not_writable_state_and_action_payload_is_not_request(): void
    {
        $page = $this->page();
        foreach (['factor', 'coefficient', 'monthCount', 'source', 'position', 'functionalLevel', 'predicate', 'ak', 'total', 'status', 'revision', 'manifest'] as $property) {
            $this->assertFalse(property_exists($page->instance(), $property));
        }
        $page->call('previewConversion', ['total' => 999, 'status' => 'APPROVED'])->assertSet('previewDisplay.status', 'PREVIEW')
            ->call('saveInitialConversion', ['total' => 999, 'revision' => 8]);
        $this->assertSame('75', PakConversionRun::sole()->total_numerator);
        $this->assertSame(1, PakConversionRun::sole()->revision_number);
    }

    #[DataProvider('staleFields')]
    public function test_stale_precondition_invalidates_without_automatic_preview_or_resubmit(string $field, string $expectedError): void
    {
        if ($field !== 'case') {
            $result = (new ConversionService)->preview($this->actor, new ConversionPreviewRequest($this->case->id, '2023-11-01', '2026-10-31'));
            $values = get_object_vars($result);
            $values[$field === 'expectedDependencyFingerprint' ? 'dependency_fingerprint' : 'snapshot_fingerprint'] = str_repeat('f', 64);
            // Inject a stale server-generated token before Livewire dehydrates; backend remains real.
            $proxy = Mockery::mock(new ConversionService);
            $proxy->shouldReceive('preview')->once()->andReturn(new ConversionPreview(...$values));
            app()->instance(ConversionService::class, $proxy);
        }
        $page = $this->page()->call('previewConversion');
        if ($field === 'case') {
            DB::table('pak_cases')->where('id', $this->case->id)->increment('lock_version');
            $this->case->refresh();
        }
        $page->call('saveInitialConversion')->assertHasErrors($expectedError)->assertSet('previewDisplay', [])->assertSet('expectedCaseLockVersion', null);
        $page->call('saveInitialConversion')->assertHasErrors('conversion');
        $this->emptySave();
    }

    public static function staleFields(): array
    {
        return [['case', 'case'], ['expectedDependencyFingerprint', 'snapshot'], ['expectedSnapshotFingerprint', 'snapshot']];
    }

    public function test_dependency_drift_and_competing_save_fail_closed(): void
    {
        $page = $this->page()->call('previewConversion');
        DB::table('functional_levels')->where('id', $this->level->id)->update(['name' => 'Changed dependency']);
        $page->call('saveInitialConversion')->assertHasErrors('snapshot');
        $this->emptySave();
        $page = $this->page()->call('previewConversion');
        $other = $this->page()->call('previewConversion')->call('saveInitialConversion');
        $page->call('saveInitialConversion')->assertHasErrors('case')->assertNoRedirect();
        $this->assertDatabaseCount('pak_conversion_runs', 1);
        $this->assertSame(1, PakEvent::where('event_code', 'CONVERSION_RUN_SAVED')->count());
        $this->page()->call('previewConversion')->call('saveInitialConversion')->assertHasErrors('case');
        $this->assertDatabaseCount('pak_conversion_runs', 1);
    }

    public function test_non_saveable_preview_remains_visible_without_tokens(): void
    {
        $result = (new ConversionService)->preview($this->actor, new ConversionPreviewRequest($this->case->id, '2023-11-01', '2026-10-31'));
        $values = get_object_vars($result);
        $values['issues'] = [['issue_code' => 'UNSUPPORTED_TEST_ISSUE', 'reason' => 'PRIVATE-TRACE']];
        $proxy = Mockery::mock(new ConversionService);
        $proxy->shouldReceive('preview')->once()->andReturn(new ConversionPreview(...$values));
        $proxy->shouldNotReceive('saveInitial');
        app()->instance(ConversionService::class, $proxy);
        $this->page()->call('previewConversion')->assertSee('UNSUPPORTED_TEST_ISSUE')->assertDontSee('PRIVATE-TRACE')
            ->assertSee('Pratinjau ini tidak dapat disimpan.')->assertSet('expectedCaseLockVersion', null)
            ->call('saveInitialConversion')->assertHasErrors('conversion');
        $this->emptySave();
    }

    public function test_technical_preview_failure_is_safe_and_single_invocation(): void
    {
        $proxy = Mockery::mock(new ConversionService);
        $proxy->shouldReceive('preview')->once()->andThrow(new RuntimeException('PRIVATE SQL PATH TOKEN'));
        app()->instance(ConversionService::class, $proxy);
        $this->page()->call('previewConversion')->assertHasErrors('conversion')->assertDontSee('PRIVATE SQL PATH TOKEN')
            ->assertSet('previewDisplay', [])->assertSet('expectedSnapshotFingerprint', null);
        $this->emptySave();
    }

    public function test_failure_after_run_insert_rolls_back_and_does_not_retry(): void
    {
        $page = $this->page()->call('previewConversion');
        $inserts = 0;
        DB::listen(function ($query) use (&$inserts) {
            if (str_starts_with(strtolower($query->sql), 'insert into "pak_conversion_runs"')) {
                $inserts++;
                throw new RuntimeException('PRIVATE SQL PATH TOKEN');
            }
        });
        $page->call('saveInitialConversion')->assertHasErrors('conversion')->assertDontSee('PRIVATE SQL PATH TOKEN')
            ->assertSet('expectedCaseLockVersion', null)->assertSet('previewDisplay', []);
        $this->assertSame(1, $inserts);
        $this->emptySave();
    }

    #[DataProvider('backendAuthorizationActions')]
    public function test_backend_http_denial_after_ui_authorization_remains_forbidden(string $action, string $method): void
    {
        $page = $this->page();
        if ($action === 'saveInitialConversion') {
            $page->call('previewConversion')->assertHasNoErrors();
        }
        $denial = new HttpException(403, 'Backend authorization revoked');
        $proxy = Mockery::mock(new ConversionService);
        // Reaching this invocation proves the UI check succeeded. The backend independently denies access.
        $proxy->shouldReceive($method)->once()->andThrow($denial);
        app()->instance(ConversionService::class, $proxy);
        $page->call($action)->assertForbidden();
        $this->emptySave();
    }

    public static function backendAuthorizationActions(): array
    {
        return [['previewConversion', 'preview'], ['saveInitialConversion', 'saveInitial']];
    }
}
