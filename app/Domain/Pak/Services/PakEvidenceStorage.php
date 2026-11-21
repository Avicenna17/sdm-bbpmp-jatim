<?php

namespace App\Domain\Pak\Services;

use App\Models\PakCaseEvidence;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class PakEvidenceStorage
{
    public const MAX_BYTES = 10485760;

    public const TYPES = ['application/pdf' => ['pdf'], 'image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png']];

    public function store(UploadedFile $file): array
    {
        if (! $file->isValid()) {
            $this->invalid();
        }
        $filename = $this->filename($file->getClientOriginalName());
        $source = @fopen($file->getRealPath(), 'rb');
        if (! is_resource($source)) {
            throw new RuntimeException('Dokumen tidak dapat dibaca.');
        }
        try {
            $bytes = stream_get_contents($source, self::MAX_BYTES + 1);
        } finally {
            fclose($source);
        }
        if (! is_string($bytes)) {
            throw new RuntimeException('Dokumen tidak dapat dibaca.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES || ! isset(self::TYPES[$mime])
            || ! in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), self::TYPES[$mime], true)) {
            $this->invalid();
        }
        $metadata = ['disk' => 'local', 'object_key' => $this->newKey(), 'original_filename' => $filename,
            'mime_type' => $mime, 'size_bytes' => strlen($bytes), 'checksum_sha256' => hash('sha256', $bytes)];
        $disk = $this->disk();
        $this->validateMetadata($metadata);
        $this->path($disk, $metadata['object_key']);
        if (! $disk->makeDirectory('pak/evidence')) {
            throw new RuntimeException('Penyimpanan dokumen tidak tersedia.');
        }
        $path = $this->path($disk, $metadata['object_key']);
        // Exclusive creation never overwrites another operation's object.
        $output = @fopen($path, 'xb');
        if (! is_resource($output)) {
            throw new RuntimeException('Object dokumen baru tidak dapat dibuat.');
        }
        try {
            try {
                if (! $disk->setVisibility($metadata['object_key'], 'private')) {
                    throw new RuntimeException('Penyimpanan private tidak tersedia.');
                }
                $offset = 0;
                while ($offset < strlen($bytes)) {
                    $written = fwrite($output, substr($bytes, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Penulisan dokumen gagal.');
                    }
                    $offset += $written;
                }
                if (! fflush($output)) {
                    throw new RuntimeException('Penulisan dokumen gagal.');
                }
            } finally {
                fclose($output);
            }
            $this->verifiedBytes($metadata);

            return $metadata;
        } catch (Throwable) {
            $this->cleanupUnreferenced($metadata);
            throw new RuntimeException('Penyimpanan dokumen tidak dapat dikonfirmasi.');
        }
    }

    protected function newKey(): string
    {
        return 'pak/evidence/'.bin2hex(random_bytes(16)).'.bin';
    }

    public function filename(string $name): string
    {
        if (! mb_check_encoding($name, 'UTF-8')) {
            $this->invalid();
        }
        $name = trim(preg_replace('/[\x00-\x1f\x7f]/u', '', basename(str_replace('\\', '/', $name))));
        if ($name === '' || in_array($name, ['.', '..'], true) || mb_strlen($name) > 255) {
            $this->invalid();
        }

        return $name;
    }

    public function validateMetadata(array $metadata): void
    {
        if (($metadata['disk'] ?? null) !== 'local'
            || ! is_string($metadata['object_key'] ?? null)
            || preg_match('/\Apak\/evidence\/[0-9a-f]{32}\.bin\z/D', $metadata['object_key']) !== 1
            || ! is_string($metadata['mime_type'] ?? null) || ! isset(self::TYPES[$metadata['mime_type']])
            || ! is_int($metadata['size_bytes'] ?? null) || $metadata['size_bytes'] < 1 || $metadata['size_bytes'] > self::MAX_BYTES
            || ! is_string($metadata['checksum_sha256'] ?? null) || preg_match('/\A[0-9a-f]{64}\z/D', $metadata['checksum_sha256']) !== 1
            || ! is_string($metadata['original_filename'] ?? null)) {
            throw new RuntimeException('Metadata dokumen tidak tersedia.');
        }
        $filename = $this->filename($metadata['original_filename']);
        if ($filename !== $metadata['original_filename']
            || ! in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), self::TYPES[$metadata['mime_type']], true)) {
            throw new RuntimeException('Metadata dokumen tidak tersedia.');
        }
    }

    /** Returns the exact verified bytes; download never reopens the object afterward. */
    public function verifiedBytes(array $metadata): string
    {
        $this->validateMetadata($metadata);
        $disk = $this->disk();
        $this->path($disk, $metadata['object_key']);
        $stream = null;
        try {
            $stream = $disk->readStream($metadata['object_key']);
            if (! is_resource($stream)) {
                throw new RuntimeException;
            }
            $bytes = stream_get_contents($stream, self::MAX_BYTES + 1);
            if (! is_string($bytes) || strlen($bytes) !== $metadata['size_bytes']
                || ! hash_equals($metadata['checksum_sha256'], hash('sha256', $bytes))
                || (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== $metadata['mime_type']) {
                throw new RuntimeException;
            }

            return $bytes;
        } catch (Throwable) {
            throw new RuntimeException('Dokumen tidak tersedia atau integritasnya tidak dapat diverifikasi.');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function isReferenced(array $metadata): bool
    {
        $this->validateMetadata($metadata);

        return PakCaseEvidence::query()->useWritePdo()->where('disk', 'local')
            ->where('object_key', $metadata['object_key'])->exists();
    }

    /** Only the newly created object of a confirmed failed operation may be compensated. */
    public function cleanupUnreferenced(array $metadata): void
    {
        try {
            if ($this->isReferenced($metadata)) {
                return;
            }
            $disk = $this->disk();
            $this->path($disk, $metadata['object_key']);
            if ($disk->exists($metadata['object_key']) && (! $disk->delete($metadata['object_key']) || $disk->exists($metadata['object_key']))) {
                throw new RuntimeException;
            }
        } catch (Throwable) {
            throw new RuntimeException('Pembersihan dokumen belum dapat dikonfirmasi; diperlukan pemeriksaan operasional.');
        }
    }

    private function disk(): FilesystemAdapter
    {
        $config = config('filesystems.disks.local');
        if (! is_array($config) || ($config['driver'] ?? null) !== 'local' || ($config['serve'] ?? false)
            || ($config['visibility'] ?? 'private') !== 'private' || ! empty($config['url'])) {
            throw new RuntimeException('Penyimpanan private tidak tersedia.');
        }
        $disk = Storage::disk('local');
        $root = realpath($disk->path(''));
        if ($root === false) {
            throw new RuntimeException('Penyimpanan private tidak tersedia.');
        }
        $normalize = fn ($value) => rtrim(str_replace('\\', '/', PHP_OS_FAMILY === 'Windows' ? strtolower($value) : $value), '/');
        foreach ([public_path(), storage_path('app/public'), ...array_values(config('filesystems.links', []))] as $publicRoot) {
            $publicRoot = $normalize(realpath($publicRoot) ?: $publicRoot);
            if ($normalize($root) === $publicRoot || str_starts_with($normalize($root), $publicRoot.'/')) {
                throw new RuntimeException('Penyimpanan private tidak tersedia.');
            }
        }

        return $disk;
    }

    private function path(FilesystemAdapter $disk, string $key): string
    {
        foreach (['pak', 'pak/evidence', $key] as $part) {
            if (is_link($disk->path($part))) {
                throw new RuntimeException('Penyimpanan private tidak tersedia.');
            }
        }

        return $disk->path($key);
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['evidence' => 'Gunakan PDF, JPEG, atau PNG yang sesuai ekstensi, maksimum 10 MiB, dengan nama file yang valid.']);
    }
}
