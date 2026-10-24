<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\DTOs\SkpStoredSource;
use App\Domain\Pak\Exceptions\SkpSourceStorageException;
use App\Domain\Pak\Services\SkpSourceStorage;
use App\Models\PakSkpImportBatch;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Pak\SkpSourceFixtures;
use Tests\TestCase;

class SkpSourceStorageTest extends TestCase
{
    use RefreshDatabase, SkpSourceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function store(string $bytes = 'source bytes', string $filename = 'source.xlsx', string $disk = 'local'): SkpStoredSource
    {
        $stream = fopen('php://memory', 'w+b');
        try {
            fwrite($stream, $bytes);
            rewind($stream);

            return (new SkpSourceStorage)->store($stream, $filename, $disk);
        } finally {
            fclose($stream);
        }
    }

    public function test_store_exact_bytes_private_metadata_and_no_database_side_effects(): void
    {
        $real = Storage::disk('local');
        $proxy = Mockery::mock($real);
        $proxy->shouldReceive('put')->once()->withArgs(fn ($key, $stream, $options) => $options === ['visibility' => 'private'])
            ->andReturnUsing(fn ($key, $stream, $options) => $real->put($key, $stream, $options));
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);
        $bytes = "\x00\xFF\r\nraw UTF-8 東京\n";
        $source = $this->store($bytes, '../../evil.xlsx');
        $this->assertSame('local', $source->disk);
        $this->assertSame('evil.xlsx', $source->original_filename);
        $this->assertMatchesRegularExpression('/\Apak\/skp\/sources\/[0-9a-f]{32}\.source\z/', $source->object_key);
        $this->assertStringNotContainsString('evil', $source->object_key);
        $this->assertStringNotContainsString('../', $source->object_key);
        $this->assertLessThanOrEqual(500, strlen($source->object_key));
        $this->assertSame($bytes, Storage::disk('local')->get($source->object_key));
        // Windows does not expose POSIX 0600 visibility; assert the private write option above.
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame('private', $real->getVisibility($source->object_key));
        }
        $this->assertSame(hash('sha256', $bytes), $source->checksum_sha256);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $source->checksum_sha256);
        $this->assertSame(['disk', 'object_key', 'original_filename', 'checksum_sha256'], array_keys(get_object_vars($source)));
        foreach (['pak_skp_import_batches', 'pak_skp_import_rows', 'performance_evaluations', 'pak_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_checksum_depends_only_on_bytes_and_keys_do_not_share_identity(): void
    {
        $a = $this->store('abc', '001234567890123456-BAIK-SKP001-SRC007.xlsx');
        $b = $this->store('abc', 'different.xlsx');
        $c = $this->store('abcd');
        $this->assertSame('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad', $a->checksum_sha256);
        $this->assertSame($a->checksum_sha256, $b->checksum_sha256);
        $this->assertNotSame($a->object_key, $b->object_key);
        $this->assertNotSame($a->checksum_sha256, $c->checksum_sha256);
        foreach (['001234567890123456', 'BAIK', 'SKP001', 'SRC007'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $a->object_key);
        }
    }

    public function test_caller_stream_is_consumed_from_current_position_but_remains_open(): void
    {
        $stream = fopen('php://memory', 'w+b');
        try {
            fwrite($stream, 'prefix:payload');
            fseek($stream, 7);
            $source = (new SkpSourceStorage)->store($stream, 'source.xlsx', 'local');
            $this->assertTrue(is_resource($stream));
            $this->assertTrue(feof($stream));
            $this->assertSame('payload', Storage::disk('local')->get($source->object_key));
        } finally {
            fclose($stream);
        }
    }

    public function test_cleanup_is_idempotent_for_unreferenced_object(): void
    {
        $source = $this->store();
        $service = new SkpSourceStorage;
        $this->assertTrue($service->exists($source));
        $this->assertFalse($service->isReferenced($source));
        $this->assertTrue($service->cleanupUnreferenced($source));
        $this->assertFalse($service->exists($source));
        $this->assertTrue($service->cleanupUnreferenced($source));
    }

    public function test_any_batch_reference_including_failed_batch_prevents_cleanup(): void
    {
        $source = $this->store();
        PakSkpImportBatch::create($this->batchData(get_object_vars($source) + ['status' => 'FAILED']));
        $service = new SkpSourceStorage;
        $this->assertTrue($service->isReferenced($source));
        $this->assertFalse($service->cleanupUnreferenced($source));
        $this->assertTrue($service->exists($source));
        $this->assertDatabaseCount('pak_skp_import_batches', 1);
    }

    public function test_reference_uses_disk_and_key_not_filename_or_checksum(): void
    {
        config(['filesystems.disks.other_private' => config('filesystems.disks.local')]);
        Storage::fake('other_private');
        $source = $this->store();
        $other = new SkpStoredSource('other_private', $source->object_key, $source->original_filename, $source->checksum_sha256);
        Storage::disk('other_private')->put($other->object_key, 'other bytes');
        PakSkpImportBatch::create($this->batchData(get_object_vars($other)));
        $service = new SkpSourceStorage;
        $this->assertTrue($service->cleanupUnreferenced($source));
        $this->assertFalse($service->cleanupUnreferenced($other));
        $this->assertSame('other bytes', Storage::disk('other_private')->get($other->object_key));
    }

    public static function invalidDisks(): array
    {
        return [['public'], ['not_configured'], ['s3']];
    }

    #[DataProvider('invalidDisks')]
    public function test_invalid_disk_is_known_failure(string $disk): void
    {
        $this->expectException(SkpSourceStorageException::class);
        $this->expectExceptionMessage('SOURCE_DISK_INVALID');
        $this->store(disk: $disk);
    }

    public function test_public_root_is_rejected_even_with_private_named_disk(): void
    {
        $mock = Mockery::mock(FilesystemAdapter::class);
        $mock->shouldReceive('path')->with('')->andReturn(public_path());
        Storage::shouldReceive('disk')->with('local')->andReturn($mock);
        $this->expectExceptionMessage('SOURCE_DISK_INVALID');
        $this->store();
    }

    public function test_unavailable_root_is_known_failure(): void
    {
        $mock = Mockery::mock(FilesystemAdapter::class);
        $mock->shouldReceive('path')->with('')->andReturn(storage_path('not-existing-skp-test-root'));
        Storage::shouldReceive('disk')->with('local')->andReturn($mock);
        $this->expectExceptionMessage('SOURCE_DISK_UNAVAILABLE');
        $this->store();
    }

    private function failingDisk(string $mode): FilesystemAdapter
    {
        $real = Storage::disk('local');
        $mock = Mockery::mock(FilesystemAdapter::class);
        $mock->shouldReceive('path')->with('')->andReturn($real->path(''));
        $mock->shouldReceive('exists')->andReturnUsing(fn ($key) => $real->exists($key));
        $mock->shouldReceive('put')->andReturnUsing(function ($key, $stream, $options) use ($real, $mode) {
            $real->put($key, 'partial or corrupted bytes', $options);
            if ($mode === 'throw') {
                throw new \RuntimeException('private driver detail');
            }

            return $mode === 'corrupt';
        });
        $mock->shouldReceive('readStream')->andReturnUsing(fn ($key) => $real->readStream($key));
        $mock->shouldReceive('delete')->andReturnUsing(fn ($key) => $mode === 'cleanup-fails' ? false : $real->delete($key));
        Storage::shouldReceive('disk')->with('local')->andReturn($mock);

        return $real;
    }

    public static function writeFailures(): array
    {
        return [['false', 'SOURCE_STORAGE_FAILED'], ['throw', 'SOURCE_STORAGE_FAILED'], ['corrupt', 'SOURCE_CHECKSUM_MISMATCH']];
    }

    #[DataProvider('writeFailures')]
    public function test_failed_write_or_verification_never_returns_success_and_cleans_partial_object(string $mode, string $issue): void
    {
        $real = $this->failingDisk($mode);
        try {
            $this->store();
            $this->fail('Expected storage failure.');
        } catch (SkpSourceStorageException $error) {
            $this->assertSame($issue, $error->issue_code);
            $this->assertSame([], $real->allFiles());
            $this->assertNull($error->recovery_source);
        }
        $this->assertDatabaseCount('pak_skp_import_batches', 0);
    }

    public function test_cleanup_failure_returns_recovery_metadata_and_retry_rechecks_reference(): void
    {
        $real = $this->failingDisk('cleanup-fails');
        try {
            $this->store();
            $this->fail('Expected cleanup failure.');
        } catch (SkpSourceStorageException $error) {
            $this->assertSame('SOURCE_CLEANUP_FAILED', $error->issue_code);
            $source = $error->recovery_source;
            $this->assertNotNull($source);
            $this->assertTrue($real->exists($source->object_key));
        }
        PakSkpImportBatch::create($this->batchData(get_object_vars($source)));
        $this->assertFalse((new SkpSourceStorage)->cleanupUnreferenced($source));
        $this->assertTrue($real->exists($source->object_key));
    }

    public function test_cleanup_rejects_arbitrary_traversal_key(): void
    {
        $source = new SkpStoredSource('local', '../old.xlsx', 'old.xlsx', str_repeat('a', 64));
        $this->expectExceptionMessage('SOURCE_OBJECT_KEY_INVALID');
        (new SkpSourceStorage)->cleanupUnreferenced($source);
    }

    public function test_empty_source_is_stored_without_changing_bytes(): void
    {
        $source = $this->store('');
        $this->assertSame(hash('sha256', ''), $source->checksum_sha256);
        $this->assertSame('', Storage::disk('local')->get($source->object_key));
    }

    public function test_cleanup_can_retry_successfully_after_filesystem_failure(): void
    {
        $real = $this->failingDisk('cleanup-fails');
        try {
            $this->store();
            $this->fail('Expected cleanup failure.');
        } catch (SkpSourceStorageException $error) {
            $source = $error->recovery_source;
            $this->assertNotNull($source);
        }
        Storage::swap(new FilesystemManager($this->app));
        Storage::set('local', $real);
        $this->assertTrue((new SkpSourceStorage)->cleanupUnreferenced($source));
        $this->assertFalse($real->exists($source->object_key));
    }

    public function test_read_back_failure_compensates_successful_write_without_side_effects(): void
    {
        $real = Storage::disk('local');
        $proxy = Mockery::mock($real);
        $proxy->shouldReceive('put')->once()->andReturnUsing(function ($key, $stream, $options) use ($real) {
            $written = $real->put($key, $stream, $options);
            $this->assertTrue($written);
            $this->assertTrue($real->exists($key));

            return $written;
        });
        $proxy->shouldReceive('readStream')->once()->andReturn(false);
        $proxy->shouldReceive('delete')->once()->andReturnUsing(fn ($key) => $real->delete($key));
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);
        try {
            $this->store();
            $this->fail('Successful write alone must not yield a successful store result.');
        } catch (SkpSourceStorageException $error) {
            $this->assertSame('SOURCE_STORAGE_READ_FAILED', $error->issue_code);
            $this->assertNull($error->recovery_source);
            $this->assertSame([], $real->allFiles());
        }
        foreach (['pak_skp_import_batches', 'pak_skp_import_rows', 'performance_evaluations', 'pak_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_reference_query_failure_preserves_object_and_allows_retry_after_recovery(): void
    {
        $source = $this->store();
        $real = Storage::disk('local');
        $service = new SkpSourceStorage;
        $failReferenceQuery = true;
        $queryFailed = false;
        // Fail only this SELECT before execution, without altering schema or the connection resolver.
        DB::connection()->beforeExecuting(function ($sql, $bindings, $connection) use (&$failReferenceQuery, &$queryFailed) {
            if ($failReferenceQuery && str_contains($sql, 'pak_skp_import_batches')) {
                $queryFailed = true;
                throw new QueryException($connection->getName(), $sql, $bindings, new \RuntimeException('Synthetic reference query failure'));
            }
        });
        try {
            $service->cleanupUnreferenced($source);
            $this->fail('Database failure must not be interpreted as absence of references.');
        } catch (SkpSourceStorageException $error) {
            $this->assertSame('SOURCE_REFERENCE_CHECK_FAILED', $error->issue_code);
            $this->assertSame($source, $error->recovery_source);
            $this->assertTrue($queryFailed);
            $this->assertTrue($real->exists($source->object_key));
            $this->assertSame('source bytes', $real->get($source->object_key));
        } finally {
            $failReferenceQuery = false;
        }
        $this->assertFalse($service->isReferenced($source));
        $this->assertTrue($service->cleanupUnreferenced($source));
        $this->assertFalse($real->exists($source->object_key));
        $this->assertTrue($service->cleanupUnreferenced($source));
    }
}
