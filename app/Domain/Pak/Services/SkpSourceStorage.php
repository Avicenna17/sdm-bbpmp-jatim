<?php

namespace App\Domain\Pak\Services;

use App\Domain\Pak\DTOs\SkpStoredSource;
use App\Domain\Pak\Exceptions\SkpSourceStorageException;
use App\Models\PakSkpImportBatch;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class SkpSourceStorage
{
    /**
     * Consume bytes from the current position to EOF. Never rewind or close the caller's stream.
     * The caller owns DB persistence; no database writes/transactions occur here.
     *
     * @param  resource  $stream
     */
    public function store(mixed $stream, string $originalFilename, string $disk): SkpStoredSource
    {
        $filesystem = $this->privateDisk($disk);
        $filename = $this->filename($originalFilename);
        if (! is_resource($stream) || get_resource_type($stream) !== 'stream'
            || strpbrk(stream_get_meta_data($stream)['mode'], 'r+') === false) {
            throw new SkpSourceStorageException('SOURCE_STREAM_INVALID');
        }
        // Process-owned temporary staging; never a public path or a shared content-addressed object.
        $staging = @fopen('php://temp', 'w+b');
        if ($staging === false) {
            throw new SkpSourceStorageException('SOURCE_STORAGE_FAILED');
        }
        $source = null;
        $writeAttempted = false;
        try {
            if (@stream_copy_to_stream($stream, $staging) === false || ! feof($stream) || ! rewind($staging)) {
                throw new SkpSourceStorageException('SOURCE_STREAM_READ_FAILED');
            }
            $checksum = $this->checksum($staging);
            if (! rewind($staging)) {
                throw new SkpSourceStorageException('SOURCE_STREAM_READ_FAILED');
            }
            $key = 'pak/skp/sources/'.bin2hex(random_bytes(16)).'.source';
            $source = new SkpStoredSource($disk, $key, $filename, $checksum);
            if ($filesystem->exists($key)) {
                // Never overwrite or compensate an existing object, even in an opaque-key collision.
                throw new SkpSourceStorageException('SOURCE_OBJECT_COLLISION');
            }
            $writeAttempted = true;
            if (! $filesystem->put($key, $staging, ['visibility' => 'private'])) {
                throw new SkpSourceStorageException('SOURCE_STORAGE_FAILED');
            }
            $stored = $filesystem->readStream($key);
            if (! is_resource($stored)) {
                throw new SkpSourceStorageException('SOURCE_STORAGE_READ_FAILED');
            }
            try {
                if (! hash_equals($checksum, $this->checksum($stored))) {
                    throw new SkpSourceStorageException('SOURCE_CHECKSUM_MISMATCH');
                }
            } finally {
                fclose($stored);
            }

            return $source;
        } catch (Throwable $error) {
            if ($writeAttempted) {
                // Only this operation's newly generated object is eligible for compensation.
                if (! $this->cleanupUnreferenced($source)) {
                    throw new SkpSourceStorageException('SOURCE_COMPENSATION_REFERENCED', $source);
                }
            }
            throw new SkpSourceStorageException($error instanceof SkpSourceStorageException ? $error->issue_code : 'SOURCE_STORAGE_FAILED');
        } finally {
            fclose($staging);
        }
    }

    public function exists(SkpStoredSource $source): bool
    {
        $this->validateKey($source);
        try {
            return $this->privateDisk($source->disk)->exists($source->object_key);
        } catch (Throwable) {
            throw new SkpSourceStorageException('SOURCE_STORAGE_READ_FAILED', $source);
        }
    }

    public function isReferenced(SkpStoredSource $source): bool
    {
        $this->validateKey($source);
        try {
            // Primary connection: a stale replica must not authorize deletion.
            return PakSkpImportBatch::query()->useWritePdo()->where('disk', $source->disk)
                ->where('object_key', $source->object_key)->exists();
        } catch (Throwable) {
            throw new SkpSourceStorageException('SOURCE_REFERENCE_CHECK_FAILED', $source);
        }
    }

    /**
     * Only for this operation's orphan/new object after DB rollback is confirmed.
     * False = retained reference; true = absent/deleted. Failure throws with recovery metadata.
     * Caller must reconcile uncertain commit outcomes first. Cross-operation concurrency is deferred.
     */
    public function cleanupUnreferenced(SkpStoredSource $source): bool
    {
        if ($this->isReferenced($source)) {
            return false;
        }
        try {
            $filesystem = $this->privateDisk($source->disk);
            if (! $filesystem->exists($source->object_key)) {
                return true;
            }
            if (! $filesystem->delete($source->object_key) || $filesystem->exists($source->object_key)) {
                throw new SkpSourceStorageException('SOURCE_CLEANUP_FAILED', $source);
            }

            return true;
        } catch (Throwable) {
            throw new SkpSourceStorageException('SOURCE_CLEANUP_FAILED', $source);
        }
    }

    private function privateDisk(string $disk): FilesystemAdapter
    {
        $config = config('filesystems.disks.'.$disk);
        // Only configured private local disks are supported in this foundation slice.
        if ($disk === 'public' || strlen($disk) > 50 || ! is_array($config)
            || ($config['driver'] ?? null) !== 'local' || ($config['serve'] ?? false)
            || ($config['visibility'] ?? 'private') !== 'private' || ! empty($config['url'])) {
            throw new SkpSourceStorageException('SOURCE_DISK_INVALID');
        }
        try {
            $filesystem = Storage::disk($disk);
            $root = realpath($filesystem->path(''));
            if ($root === false) {
                throw new SkpSourceStorageException('SOURCE_DISK_UNAVAILABLE');
            }
            foreach ([public_path(), storage_path('app/public'), ...array_values(config('filesystems.links', []))] as $publicRoot) {
                $publicRoot = realpath($publicRoot) ?: $publicRoot;
                $normalize = fn (string $path) => rtrim(str_replace('\\', '/', PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path), '/');
                $rootPath = $normalize($root);
                $publicPath = $normalize($publicRoot);
                if ($rootPath === $publicPath || str_starts_with($rootPath, $publicPath.'/')) {
                    throw new SkpSourceStorageException('SOURCE_DISK_INVALID');
                }
            }

            return $filesystem;
        } catch (Throwable $error) {
            throw new SkpSourceStorageException($error instanceof SkpSourceStorageException ? $error->issue_code : 'SOURCE_DISK_UNAVAILABLE');
        }
    }

    private function validateKey(SkpStoredSource $source): void
    {
        if (! preg_match('/\Apak\/skp\/sources\/[0-9a-f]{32}\.source\z/', $source->object_key)) {
            throw new SkpSourceStorageException('SOURCE_OBJECT_KEY_INVALID');
        }
    }

    private function filename(string $filename): string
    {
        if (! mb_check_encoding($filename, 'UTF-8')) {
            throw new SkpSourceStorageException('SOURCE_FILENAME_INVALID');
        }
        $filename = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $filename))));
        if ($filename === '' || in_array($filename, ['.', '..'], true) || mb_strlen($filename) > 255) {
            throw new SkpSourceStorageException('SOURCE_FILENAME_INVALID');
        }

        return $filename;
    }

    /** @param  resource  $stream */
    private function checksum(mixed $stream): string
    {
        $hash = hash_init('sha256');
        if (@hash_update_stream($hash, $stream) === false || ! feof($stream)) {
            throw new SkpSourceStorageException('SOURCE_STREAM_READ_FAILED');
        }

        return hash_final($hash);
    }
}
