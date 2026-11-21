<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\Queries\ConversionReviewQuery;
use App\Domain\Pak\Services\ConversionAssessmentService;
use App\Domain\Pak\Services\ConversionReviewService;
use App\Domain\Pak\Services\ConversionService;
use App\Filament\Pak\Pages\Dashboard;
use App\Filament\Pak\Resources\PakCaseResource\Pages\ListCases;
use App\Models\PakCase;
use App\Models\PakConversionPeriod;
use App\Models\PakConversionRun;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Pak\ConversionRunFixtures;

class DashboardReviewPresentationTest extends PakTestCase
{
    use ConversionRunFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->reader());
    }

    private function reader(array $permissions = ['pak.access', 'pak.case.view', 'pak.conversion.view']): User
    {
        return User::factory()->create()->givePermissionTo($permissions);
    }

    public function test_empty_authorized_metric_has_zero_and_filtered_destination(): void
    {
        Livewire::test(Dashboard::class)->assertViewHas('metrics', function ($metrics) {
            $this->assertSame(0, $metrics[2]['value']);
            $this->assertStringContainsString('needs_review', $metrics[2]['url']);
            $this->assertSame('—', $metrics[3]['value']);

            return true;
        });
    }

    public function test_current_review_count_and_filter_are_identical_without_fanout_or_writes(): void
    {
        $first = PakConversionRun::create($this->runData(['status' => 'REQUIRES_REVIEW']));
        $history = PakConversionRun::create($this->runData(['status' => 'REQUIRES_REVIEW']));
        DB::table('pak_conversion_runs')->where('id', $history->id)->update(['active_case_id' => null]);
        $history->refresh();
        $data = $history->getAttributes();
        unset($data['id'], $data['created_at'], $data['updated_at']);
        $data = array_replace($data, ['revision_number' => 2, 'predecessor_run_id' => $history->id, 'revision_reason' => 'Synthetic revision', 'active_case_id' => $history->pak_case_id]);
        // Raw insertion intentionally represents historical/malformed states for a read query.
        $successorId = DB::table('pak_conversion_runs')->insertGetId($data);
        $preview = PakConversionRun::create($this->runData());
        $superseded = PakConversionRun::create($this->runData(['status' => 'SUPERSEDED', 'active_case_id' => null, 'superseded_at' => now(), 'superseded_by_user_id' => $this->manager->id]));
        $nonTip = PakConversionRun::create($this->runData(['status' => 'REQUIRES_REVIEW']));
        $later = $nonTip->getAttributes();
        unset($later['id']);
        DB::table('pak_conversion_runs')->insert(array_replace($later, ['revision_number' => 2, 'active_case_id' => null]));
        PakConversionPeriod::create($this->diagnosticData($first->id));
        PakConversionPeriod::create(array_replace($this->diagnosticData($first->id), ['line_number' => 2, 'period_start' => '2026-02-01', 'period_end' => '2026-02-28']));
        DB::table('pak_conversion_runs')->where('id', $first->id)->update(['review_assessment' => json_encode(['untrusted' => ['repeated', 'repeated']])]);
        $emptyCase = $this->runData()['pak_case_id'];
        foreach ([ConversionService::class, ConversionAssessmentService::class, ConversionReviewService::class] as $service) {
            $this->app->bind($service, fn () => throw new \LogicException('Read presentation must not resolve conversion services.'));
        }
        $before = DB::table('pak_conversion_runs')->orderBy('id')->get()->toJson();
        DB::enableQueryLog();
        Livewire::test(Dashboard::class)->assertViewHas('metrics', fn ($m) => $m[2]['value'] === 2);
        $counts = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'pak_conversion_runs') && str_contains(strtolower($q['query']), 'count('));
        $this->assertCount(1, $counts);
        $page = Livewire::test(ListCases::class)->filterTable('needs_review');
        $page->assertCanSeeTableRecords(PakCase::whereIn('id', [$first->pak_case_id, $history->pak_case_id])->get())
            ->assertCanNotSeeTableRecords(PakCase::whereIn('id', [$preview->pak_case_id, $superseded->pak_case_id, $nonTip->pak_case_id, $emptyCase])->get());
        $this->assertSame(2, $page->instance()->getFilteredTableQuery()->count());
        $this->assertSame([$first->id, $successorId], app(ConversionReviewQuery::class)->forManager(auth()->user())->orderBy('id')->pluck('id')->all());
        $this->assertSame($before, DB::table('pak_conversion_runs')->orderBy('id')->get()->toJson());
        $this->assertCount(0, collect(DB::getQueryLog())->filter(fn ($q) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $q['query'])));
        DB::disableQueryLog();
    }

    public static function missingPermissions(): array
    {
        return [['pak.case.view'], ['pak.conversion.view']];
    }

    #[DataProvider('missingPermissions')]
    public function test_denied_metric_never_queries_runs_and_query_itself_rejects(string $missing): void
    {
        $this->actingAs($this->reader(array_values(array_diff(['pak.access', 'pak.case.view', 'pak.conversion.view'], [$missing]))));
        DB::enableQueryLog();
        Livewire::test(Dashboard::class)->assertViewHas('metrics', fn ($m) => $m[2]['value'] === '—' && $m[2]['url'] === null);
        $this->assertCount(0, collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'pak_conversion_runs')));
        DB::disableQueryLog();
        $this->expectException(HttpException::class);
        app(ConversionReviewQuery::class)->forManager(auth()->user());
    }

    public function test_revoked_permission_after_render_clears_value_and_performs_no_run_query(): void
    {
        $page = Livewire::test(Dashboard::class);
        auth()->user()->revokePermissionTo('pak.conversion.view');
        DB::enableQueryLog();
        $page->call('$refresh')->assertViewHas('metrics', fn ($m) => $m[2]['value'] === '—' && $m[2]['url'] === null);
        $this->assertCount(0, collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'pak_conversion_runs')));
        DB::disableQueryLog();
    }

    public function test_forged_filter_is_forbidden_without_conversion_permission(): void
    {
        $this->actingAs($this->reader(['pak.access', 'pak.case.view']));
        Livewire::test(ListCases::class)->filterTable('needs_review')->assertForbidden();
    }

    public function test_inactive_actor_fails_closed_after_mount(): void
    {
        $page = Livewire::test(Dashboard::class);
        auth()->user()->forceFill(['is_active' => false])->save();
        $page->call('$refresh')->assertForbidden();
    }
}
