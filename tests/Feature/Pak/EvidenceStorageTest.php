<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\Services\PakEvidenceStorage;
use App\Models\PakCase;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Shared only by the four bounded evidence suites. */
trait EvidenceFixture
{
    protected User $actor;

    protected PakCase $case;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::getDriverName());
        $this->artisan('migrate:fresh', ['--force' => true]);
        RefreshDatabaseState::$migrated = false;
        $this->seed();
        Storage::fake('local');
        $this->actor = User::factory()->create()->givePermissionTo(['pak.access', 'pak.case.view', 'pak.case.update', 'pak.skp.view', 'pak.skp.manage']);
        $this->case = PakCase::create(['person_id' => Person::factory()->create()->id, 'purpose_type' => 'KP',
            'period_start' => '2023-01-01', 'period_end' => '2026-12-31', 'employee_data_as_of' => '2026-12-31',
            'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]);
        $this->actingAs($this->actor);
    }

    protected function pdfBytes(): string
    {
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] >>'];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 4\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    protected function pdf(string $name = 'synthetic.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->pdfBytes());
    }
}

class EvidenceStorageTest extends TestCase
{
    use EvidenceFixture;

    public function test_real_types_private_random_keys_server_checksum_and_same_content_independence(): void
    {
        foreach ([$this->pdf(), UploadedFile::fake()->image('photo.jpg'), UploadedFile::fake()->image('photo.jpeg'), UploadedFile::fake()->image('photo.png')] as $file) {
            $metadata = app(PakEvidenceStorage::class)->store($file);
            $this->assertMatchesRegularExpression('/\Apak\/evidence\/[a-f0-9]{32}\.bin\z/', $metadata['object_key']);
            $this->assertSame('local', $metadata['disk']);
            $this->assertSame(hash_file('sha256', $file->getRealPath()), $metadata['checksum_sha256']);
            $this->assertSame(file_get_contents($file->getRealPath()), app(PakEvidenceStorage::class)->verifiedBytes($metadata));
            // Windows does not expose POSIX visibility bits; privacy is the non-served local root.
            if (PHP_OS_FAMILY !== 'Windows') {
                $this->assertSame('private', Storage::disk('local')->getVisibility($metadata['object_key']));
            }
            $this->assertFalse(config('filesystems.disks.local.serve'));
            $this->assertStringNotContainsString(public_path(), Storage::disk('local')->path($metadata['object_key']));
        }
        $a = app(PakEvidenceStorage::class)->store($this->pdf());
        $b = app(PakEvidenceStorage::class)->store($this->pdf());
        $this->assertNotSame($a['object_key'], $b['object_key']);
        $this->assertSame($a['checksum_sha256'], $b['checksum_sha256']);
        $this->assertDatabaseCount('pak_case_evidences', 0);
    }

    public function test_exact_size_boundary_and_oversize(): void
    {
        $content = str_pad($this->pdfBytes(), PakEvidenceStorage::MAX_BYTES, ' ');
        $metadata = app(PakEvidenceStorage::class)->store(UploadedFile::fake()->createWithContent('limit.pdf', $content));
        $this->assertSame(10485760, $metadata['size_bytes']);
        $this->expectException(ValidationException::class);
        app(PakEvidenceStorage::class)->store(UploadedFile::fake()->createWithContent('large.pdf', $content.' '));
    }

    public static function invalidFiles(): array
    {
        return [['wrong.png', true], ['script.pdf', false], ['data.txt', true], ['.', true], ['..', true], ["bad\xff.pdf", true], [str_repeat('x', 256).'.pdf', true]];
    }

    #[DataProvider('invalidFiles')]
    public function test_invalid_filename_content_extension_is_rejected(string $name, bool $pdf): void
    {
        $this->expectException(ValidationException::class);
        app(PakEvidenceStorage::class)->store(UploadedFile::fake()->createWithContent($name, $pdf ? $this->pdfBytes() : '<?php echo "not a PDF";'));
    }

    public function test_path_input_is_sanitized_metadata_only_and_tampered_key_is_rejected(): void
    {
        $storage = app(PakEvidenceStorage::class);
        $this->assertSame('safe.pdf', $storage->filename("../folder\\safe\x01.pdf"));
        $metadata = $storage->store($this->pdf());
        $metadata['object_key'] = '../../private.txt';
        $this->expectException(\RuntimeException::class);
        $storage->verifiedBytes($metadata);
    }

    public function test_collision_never_overwrites_existing_object(): void
    {
        $storage = \Mockery::mock(PakEvidenceStorage::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $storage->shouldReceive('newKey')->andReturn('pak/evidence/'.str_repeat('a', 32).'.bin');
        $first = $storage->store($this->pdf());
        try {
            $storage->store($this->pdf());
            $this->fail('Collision must fail.');
        } catch (\RuntimeException) {
            $this->assertSame($this->pdfBytes(), $storage->verifiedBytes($first));
        }
    }

    public function test_failed_readback_cleans_only_new_object(): void
    {
        $storage = \Mockery::mock(PakEvidenceStorage::class)->makePartial();
        $storage->shouldReceive('verifiedBytes')->once()->andThrow(new \RuntimeException('private'));
        try {
            $storage->store($this->pdf());
            $this->fail();
        } catch (\RuntimeException $error) {
            $this->assertStringNotContainsString('private', $error->getMessage());
        }
        $this->assertSame([], Storage::disk('local')->allFiles('pak/evidence'));
    }

    public function test_reference_check_failure_retains_object(): void
    {
        $metadata = app(PakEvidenceStorage::class)->store($this->pdf());
        $storage = \Mockery::mock(PakEvidenceStorage::class)->makePartial();
        $storage->shouldReceive('isReferenced')->andThrow(new \RuntimeException('SQL private'));
        try {
            $storage->cleanupUnreferenced($metadata);
            $this->fail();
        } catch (\RuntimeException $error) {
            $this->assertStringNotContainsString('SQL', $error->getMessage());
        }
        Storage::disk('local')->assertExists($metadata['object_key']);
    }

    public function test_storage_write_and_cleanup_failures_are_safe(): void
    {
        $real = Storage::disk('local');
        $metadata = app(PakEvidenceStorage::class)->store($this->pdf());
        $disk = \Mockery::mock($real);
        $disk->shouldReceive('delete')->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        try {
            app(PakEvidenceStorage::class)->cleanupUnreferenced($metadata);
            $this->fail();
        } catch (\RuntimeException $error) {
            $this->assertStringNotContainsString($metadata['object_key'], $error->getMessage());
        }
        $this->assertTrue($real->exists($metadata['object_key']));
        $disk->shouldReceive('makeDirectory')->andReturn(false);
        $this->expectException(\RuntimeException::class);
        app(PakEvidenceStorage::class)->store($this->pdf());
    }

    public function test_public_disk_configuration_fails_closed(): void
    {
        config(['filesystems.disks.local.serve' => true]);
        $this->expectException(\RuntimeException::class);
        app(PakEvidenceStorage::class)->store($this->pdf());
    }
}
