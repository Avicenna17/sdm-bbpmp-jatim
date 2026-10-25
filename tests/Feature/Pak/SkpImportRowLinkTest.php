<?php

namespace Tests\Feature\Pak;

use App\Models\PakSkpImportBatch;
use App\Models\PakSkpImportRow;
use App\Models\PerformanceEvaluation;
use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Pak\SkpSourceFixtures;
use Tests\TestCase;

class SkpImportRowLinkTest extends TestCase
{
    use SkpSourceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('Model link tests require isolated SQLite memory.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        RefreshDatabaseState::$migrated = false;
    }

    public static function paths(): array
    {
        return [['IMPORTED', false], ['IMPORTED', true], ['DUPLICATE', false], ['DUPLICATE', true]];
    }

    #[DataProvider('paths')]
    public function test_matching_link_uses_shared_read_only_inside_transaction(string $status, bool $transaction): void
    {
        $batch = PakSkpImportBatch::create($this->batchData());
        $evaluation = PerformanceEvaluation::create($this->evaluationData());
        $scopes = PerformanceEvaluation::getAllGlobalScopes();
        $locks = [];
        PerformanceEvaluation::addGlobalScope('observe-link-read', function ($builder) use (&$locks) {
            $builder->getQuery()->beforeQuery(function ($query) use (&$locks) {
                $locks[] = $query->lock;
            });
        });
        try {
            $save = fn () => PakSkpImportRow::create($this->resolvedRowData($batch, $evaluation, ['processing_status' => $status]));
            $row = $transaction ? DB::transaction($save) : $save();
        } finally {
            PerformanceEvaluation::setAllGlobalScopes($scopes);
        }
        // Builder contract only; SQLite does not prove current-read/snapshot semantics.
        $this->assertSame([$transaction ? false : null], $locks);
        $this->assertSame($evaluation->id, $row->performance_evaluation_id);
        $this->assertSame($evaluation->person_id, $row->person_id);
    }

    public static function invalidLinks(): array
    {
        return [['IMPORTED', 'missing'], ['IMPORTED', 'other-person'], ['DUPLICATE', 'missing'], ['DUPLICATE', 'other-person']];
    }

    #[DataProvider('invalidLinks')]
    public function test_shared_read_does_not_weaken_link_invariant(string $status, string $problem): void
    {
        $batch = PakSkpImportBatch::create($this->batchData());
        $evaluation = PerformanceEvaluation::create($this->evaluationData());
        $data = $this->resolvedRowData($batch, $evaluation, ['processing_status' => $status]);
        if ($problem === 'missing') {
            $data['performance_evaluation_id'] = $evaluation->id + 999;
        } else {
            $data['person_id'] = Person::factory()->create()->id;
        }
        try {
            DB::transaction(fn () => PakSkpImportRow::create($data));
            $this->fail('Expected evaluation/person validation');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('performance_evaluation_id', $error->errors());
        }
        $this->assertDatabaseCount('pak_skp_import_rows', 0);
    }
}
