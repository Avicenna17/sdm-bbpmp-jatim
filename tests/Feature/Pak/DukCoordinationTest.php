<?php

namespace Tests\Feature\Pak;

use App\Domain\Import\ImportService;
use App\Domain\Import\WorkbookParser;
use App\Models\ImportBatch;
use App\Models\Person;
use App\Models\PersonnelSnapshot;
use App\Models\User;
use App\Support\Database\PersonLocks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Support\DatabaseWithoutWrappingTransaction;
use Tests\Support\Pak\DukCoordinationFixtures;

class DukCoordinationTest extends PakTestCase
{
    use DatabaseWithoutWrappingTransaction, DukCoordinationFixtures;

    private function actor(): User
    {
        return User::factory()->create()->assignRole('Super Admin');
    }

    public function test_person_lock_helper_requires_transaction_and_forgets_rolled_back_acquisition(): void
    {
        try {
            PersonLocks::acquire([$this->person->id]);
            $this->fail('Caller transaction required');
        } catch (\LogicException $e) {
            $this->assertSame('Person coordination requires a transaction.', $e->getMessage());
        }
        $scopes = Model::getAllGlobalScopes();
        $reads = 0;
        Person::addGlobalScope('retry-read', function ($builder) use (&$reads) {
            $builder->getQuery()->beforeQuery(function ($q) use (&$reads) {
                if ($q->lock === true) {
                    $reads++;
                }
            });
        });
        try {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                DB::beginTransaction();
                PersonLocks::acquire([$this->person->id]);
                DB::rollBack();
            }
        } finally {
            Model::setAllGlobalScopes($scopes);
        }
        $this->assertSame(2, $reads);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_commit_parses_the_verified_private_copy_even_if_source_path_changes(): void
    {
        Storage::fake('local');
        $actor = $this->actor();
        $batch = $this->preparedDuk($actor, [$this->dukPersonRow($this->person->person_key, $this->person->nip, 'Verified source')]);
        $parser = \Mockery::mock(WorkbookParser::class);
        $privatePath = null;
        $parser->shouldReceive('parse')->once()->andReturnUsing(function ($path, ...$args) use ($batch, &$privatePath) {
            $this->assertSame(0, DB::transactionLevel());
            $privatePath = $path;
            $this->assertNotSame(Storage::disk('local')->path($batch->path), $path);
            $this->assertSame($batch->sha256, hash_file('sha256', $path));
            Storage::disk('local')->put($batch->path, 'concurrently replaced source');

            return (new WorkbookParser)->parse($path, ...$args);
        });
        (new ImportService($parser))->commit($actor, $batch);
        $this->assertFileDoesNotExist($privatePath);
        $this->assertSame('Verified source', PersonnelSnapshot::sole()->name_at_period);
        $this->assertSame('committed', $batch->fresh()->status);
    }

    public function test_staging_payload_not_derived_from_verified_source_is_rejected(): void
    {
        Storage::fake('local');
        $actor = $this->actor();
        $batch = $this->preparedDuk($actor, [$this->dukPersonRow($this->person->person_key, $this->person->nip, 'Verified source')]);
        $service = app(ImportService::class);
        $rows = $service->rows($batch);
        $rows[0]['name_at_period'] = 'Tampered staging';
        Storage::disk('local')->put($service->stagingPath($batch), json_encode($rows, JSON_THROW_ON_ERROR));
        try {
            $service->commit($actor, $batch);
            $this->fail('Unbound staging accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('tidak sesuai file sumber', $e->errors()['import'][0]);
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(0, PersonnelSnapshot::count());
        $this->assertSame('validated', $batch->fresh()->status);
    }

    public function test_outer_transaction_is_rejected_before_any_io_or_mutation(): void
    {
        Storage::shouldReceive('disk')->never();
        $count = Person::count();
        DB::transaction(function () {
            try {
                app(ImportService::class)->commit($this->manager, new ImportBatch);
                $this->fail('Outer transaction must be rejected');
            } catch (ValidationException $e) {
                $this->assertSame(['COMMIT_REQUIRES_TOP_LEVEL_TRANSACTION'], $e->errors()['import']);
            }
        });
        $this->assertSame($count, Person::count());
        $this->assertSame(0, ImportBatch::count());
    }

    public function test_io_precedes_locks_and_mixed_people_preserve_workbook_order(): void
    {
        Storage::fake('local');
        $actor = $this->actor();
        $second = Person::factory()->create();
        $rows = [$this->dukPersonRow($second->person_key, $second->nip, 'Second updated'),
            $this->dukPersonRow(hash('sha256', 'new'), '001234567890123458', 'New person'),
            $this->dukPersonRow($this->person->person_key, $this->person->nip, 'First updated')];
        $batch = $this->preparedDuk($actor, $rows);
        $disk = Storage::disk('local');
        Storage::shouldReceive('disk')->with('local')->andReturnUsing(function () use ($disk) {
            $this->assertSame(0, DB::transactionLevel(), 'No storage access while holding DB locks');

            return $disk;
        });
        $scopes = Model::getAllGlobalScopes();
        $locks = [];
        Person::addGlobalScope('lock-order', function ($builder) use (&$locks) {
            $builder->getQuery()->beforeQuery(function ($q) use (&$locks) {
                if ($q->lock === true) {
                    $locks[] = (int) $q->getBindings()[0];
                }
            });
        });
        try {
            app(ImportService::class)->commit($actor, $batch);
        } finally {
            Model::setAllGlobalScopes($scopes);
        }
        $this->assertSame([$this->person->id, $second->id], $locks);
        $this->assertSame(array_column($rows, 'name_at_period'), PersonnelSnapshot::orderBy('id')->pluck('name_at_period')->all());
        $this->assertSame('First updated', $this->person->fresh()->canonical_name);
        $this->assertSame('Second updated', $second->fresh()->canonical_name);
        $this->assertSame(3, Person::count());
        $this->assertSame('committed', $batch->fresh()->status);
        app(ImportService::class)->commit($actor, $batch); // committed no-op
        $this->assertSame(3, PersonnelSnapshot::count());
    }

    public function test_prepared_metadata_mismatch_rejects_before_person_mutation(): void
    {
        Storage::fake('local');
        $actor = $this->actor();
        $batch = $this->preparedDuk($actor, [$this->dukPersonRow($this->person->person_key, $this->person->nip, 'Changed')]);
        $scopes = Model::getAllGlobalScopes();
        $once = true;
        ImportBatch::addGlobalScope('concurrent-change', function ($builder) use (&$once, $batch) {
            $builder->getQuery()->beforeQuery(function ($q) use (&$once, $batch) {
                if ($once && $q->lock === true) {
                    $once = false;
                    DB::table('import_batches')->where('id', $batch->id)->update(['sha256' => str_repeat('d', 64)]);
                }
            });
        });
        try {
            app(ImportService::class)->commit($actor, $batch);
            $this->fail('Prepared metadata mismatch must fail');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('berubah sejak persiapan', $e->errors()['import'][0]);
        } finally {
            Model::setAllGlobalScopes($scopes);
        }
        $this->assertSame('validated', $batch->fresh()->status);
        $this->assertSame(0, PersonnelSnapshot::count());
        $this->assertNotSame('Changed', $this->person->fresh()->canonical_name);
    }

    public function test_mixed_new_and_existing_mutations_rollback_together(): void
    {
        Storage::fake('local');
        $actor = $this->actor();
        $batch = $this->preparedDuk($actor, [$this->dukPersonRow(hash('sha256', 'new'), null, 'New'),
            $this->dukPersonRow($this->person->person_key, $this->person->nip, 'Changed')]);
        $before = $this->person->fresh()->getAttributes();
        $count = 0;
        PersonnelSnapshot::creating(function () use (&$count) {
            if (++$count === 2) {
                throw new RuntimeException('Synthetic insert failure');
            }
        });
        try {
            app(ImportService::class)->commit($actor, $batch);
            $this->fail('Expected failure');
        } catch (RuntimeException $e) {
            $this->assertSame('Synthetic insert failure', $e->getMessage());
        }
        $this->assertSame(1, Person::count());
        $this->assertSame($before, $this->person->fresh()->getAttributes());
        $this->assertSame(0, PersonnelSnapshot::count());
        $this->assertSame('validated', $batch->fresh()->status);
        $this->assertSame(0, $batch->period->revision);
    }
}
