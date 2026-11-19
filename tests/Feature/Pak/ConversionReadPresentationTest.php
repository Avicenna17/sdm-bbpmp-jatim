<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\Queries\ConversionRunQuery;
use App\Filament\Pak\Resources\PakCaseResource;
use App\Filament\Pak\Resources\PakCaseResource\Pages\CaseConversion;
use App\Filament\Pak\Resources\PakCaseResource\Pages\ViewCase;
use App\Models\PakConversionPeriod;
use App\Models\PakConversionRun;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Pak\ConversionRunFixtures;

class ConversionReadPresentationTest extends PakTestCase
{
    use ConversionRunFixtures;

    private PakConversionRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $data = $this->runData();
        $data['dependency_manifest']['rule_set'] = ['id' => $data['pak_rule_set_id'], 'code' => 'HISTORICAL-RULE', 'version' => 'saved-version', 'private_path' => 'NEVER-EXPOSE'];
        $data['dependency_manifest']['source_candidates'] = ['secret' => 'NEVER-EXPOSE'];
        $this->run = PakConversionRun::create($data);
        PakConversionPeriod::create(array_replace($this->diagnosticData($this->run->id), [
            'source_snapshot' => ['evaluation_id' => 345, 'evaluation_type' => 'ANNUAL', 'performance_year' => 2026, 'source_reference' => '<script>historical-source</script>', 'source_date' => '2026-12-31', 'private_path' => 'NEVER-EXPOSE'],
            'position_snapshot' => ['history_id' => 456, 'position_name' => 'Saved position label', 'functional_level_name' => 'Saved level label', 'tmt_start' => '2023-01-01', 'nip' => 'NEVER-EXPOSE'],
            'rule_snapshot' => ['predicate' => ['childRuleId' => 567, 'predicateCode' => 'BAIK', 'predicateFactor' => '1.0000'], 'coefficient' => ['childRuleId' => 678, 'annualCoefficient' => '25.000000']],
            'original_issues' => [['issue_code' => 'MISSING_POSITION_HISTORY', 'month' => '2026-01', 'reason' => 'NEVER-EXPOSE', 'trace' => 'NEVER-EXPOSE']],
        ]));
        $this->actingAs($this->reader());
    }

    private function reader(array $permissions = ['pak.access', 'pak.case.view', 'pak.conversion.view']): User
    {
        return User::factory()->create()->givePermissionTo($permissions);
    }

    private function url(?int $runId = null): string
    {
        return PakCaseResource::getUrl('conversion', ['record' => $this->run->pak_case_id, 'run' => $runId]);
    }

    public function test_reader_without_calculate_can_render_direct_url_and_viewcase_navigation(): void
    {
        $this->get($this->url())->assertOk()->assertSee('75/1')->assertSee('Tampilan total: 75')->assertSee('PREVIEW');
        Livewire::test(ViewCase::class, ['record' => $this->run->pak_case_id])->assertActionVisible('conversion');
        $this->assertFalse(auth()->user()->can('pak.conversion.calculate'));
    }

    #[DataProvider('missingPermissions')]
    public function test_missing_read_permission_rejects_url_livewire_and_query(string $missing): void
    {
        $this->actingAs($this->reader(array_values(array_diff(['pak.access', 'pak.case.view', 'pak.conversion.view'], [$missing]))));
        $this->get($this->url())->assertForbidden();
        Livewire::test(CaseConversion::class, ['record' => $this->run->pak_case_id])->assertForbidden();
        $this->expectException(HttpException::class);
        app(ConversionRunQuery::class)->revisions(auth()->user(), $this->run->pak_case_id);
    }

    public static function missingPermissions(): array
    {
        return [['pak.access'], ['pak.case.view'], ['pak.conversion.view']];
    }

    public function test_inactive_and_revoked_access_fail_closed(): void
    {
        $component = Livewire::test(CaseConversion::class, ['record' => $this->run->pak_case_id]);
        auth()->user()->forceFill(['is_active' => false])->save();
        $component->call('$refresh')->assertForbidden();
        Livewire::test(CaseConversion::class, ['record' => $this->run->pak_case_id])->assertForbidden();
    }

    public function test_foreign_run_and_its_period_are_not_exposed(): void
    {
        $foreign = PakConversionRun::create($this->runData());
        PakConversionPeriod::create(array_replace($this->diagnosticData($foreign->id), ['source_snapshot' => ['source_reference' => 'FOREIGN-PERIOD']]));
        $this->get($this->url($foreign->id))->assertNotFound()->assertDontSee('FOREIGN-PERIOD');
        $this->expectException(ModelNotFoundException::class);
        Livewire::test(CaseConversion::class, ['record' => $this->run->pak_case_id])->set('runId', $foreign->id);
    }

    public function test_saved_provenance_is_whitelisted_escaped_and_not_current_relations(): void
    {
        $this->actingAs($this->reader(['pak.access', 'pak.case.view', 'pak.conversion.view', 'pak.skp.view', 'pak.employee.view', 'pak.rule-set.view']));
        DB::table('pak_rule_sets')->where('id', $this->run->pak_rule_set_id)->update(['code' => 'CURRENT-NOT-HISTORICAL']);
        $this->get($this->url())->assertOk()->assertSee('HISTORICAL-RULE')->assertSee('saved-version')
            ->assertSee('Saved position label')->assertSee('Saved level label')->assertSee('&lt;script&gt;historical-source&lt;/script&gt;', false)
            ->assertDontSee('<script>historical-source</script>', false)->assertSee('MISSING_POSITION_HISTORY')
            ->assertDontSee('CURRENT-NOT-HISTORICAL')->assertDontSee('NEVER-EXPOSE')->assertDontSee('dependency_manifest');
        $this->actingAs($this->reader());
        $this->get($this->url())->assertOk()->assertDontSee('HISTORICAL-RULE')->assertDontSee('Saved position label')->assertDontSee('historical-source');
    }

    public function test_null_total_is_explicitly_unavailable(): void
    {
        $run = PakConversionRun::create($this->runData(['status' => 'REQUIRES_REVIEW', 'total_numerator' => null, 'total_denominator' => null, 'original_issues' => [['issue_code' => 'SOURCE_NOT_FOUND']]]));
        Livewire::test(CaseConversion::class, ['record' => $run->pak_case_id])->assertSee('Total eksak: Tidak tersedia')->assertSee('Tampilan total: Tidak tersedia')->assertSee('SOURCE_NOT_FOUND');
    }

    public function test_empty_case_and_fractional_saved_results(): void
    {
        $data = $this->runData();
        Livewire::test(CaseConversion::class, ['record' => $data['pak_case_id']])->assertSee('Belum ada run konversi tersimpan.');
        $run = PakConversionRun::create(array_replace($data, ['total_numerator' => '25', 'total_denominator' => '6']));
        // Read-presentation fixture: the persisted rational is rendered, never recalculated from operands.
        DB::table('pak_conversion_periods')->where('pak_conversion_run_id', $this->run->id)->update(['result_numerator' => '25', 'result_denominator' => '6', 'predicate_factor' => '1.0000', 'annual_coefficient' => '25.000000']);
        Livewire::test(CaseConversion::class, ['record' => $run->pak_case_id])->assertSee('Total eksak: 25/6')->assertSee('Tampilan total: 4,167');
        Livewire::test(CaseConversion::class, ['record' => $this->run->pak_case_id])->assertSee('Hasil eksak: 25/6')->assertSee('Tampilan hasil: 4,167')->assertSee('25.000000')->assertSee('1.0000');
    }

    public function test_case_identity_cannot_be_changed_by_livewire(): void
    {
        $foreign = $this->runData();
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(CaseConversion::class, ['record' => $this->run->pak_case_id])->set('caseId', $foreign['pak_case_id']);
    }

    public function test_revoked_conversion_permission_hides_navigation_and_denies_existing_component(): void
    {
        $component = Livewire::test(CaseConversion::class, ['record' => $this->run->pak_case_id]);
        auth()->user()->revokePermissionTo('pak.conversion.view');
        Livewire::test(ViewCase::class, ['record' => $this->run->pak_case_id])->assertActionHidden('conversion');
        $component->call('$refresh')->assertForbidden();
    }

    public function test_revision_navigation_is_read_only_and_has_zero_database_writes(): void
    {
        DB::table('pak_conversion_runs')->where('id', $this->run->id)->update(['status' => 'SUPERSEDED', 'active_case_id' => null, 'superseded_at' => now(), 'superseded_by_user_id' => $this->manager->id]);
        $data = $this->run->getRawOriginal();
        unset($data['id'], $data['created_at'], $data['updated_at']);
        foreach (['dependency_manifest', 'original_issues', 'calculation_trace'] as $field) {
            $data[$field] = $this->run->$field;
        }
        $successor = PakConversionRun::create(array_replace($data, ['revision_number' => 2, 'predecessor_run_id' => $this->run->id, 'revision_reason' => 'Synthetic successor']));
        $tables = ['pak_cases', 'pak_conversion_runs', 'pak_conversion_periods', 'pak_events'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        DB::enableQueryLog();
        $component = Livewire::test(CaseConversion::class, ['record' => $this->run->pak_case_id])->assertSee('Synthetic successor')->assertSee('Revisi sebelumnya');
        $component->set('runId', $this->run->id)->assertSee('SUPERSEDED')->assertSee('Revisi berikutnya')->assertSee('hanya baca');
        $component->set('runId', $successor->id)->assertSee('Synthetic successor');
        $writes = array_filter(DB::getQueryLog(), fn ($query) => preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $query['query']));
        DB::disableQueryLog();
        $this->assertSame([], array_values($writes));
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson());
        }
        foreach (['preview', 'saveInitial', 'assess', 'assessReview', 'replace'] as $method) {
            $this->assertFalse(method_exists(CaseConversion::class, $method));
        }
    }
}
