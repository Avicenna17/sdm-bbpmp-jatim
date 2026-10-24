<?php

namespace App\Domain\Pak\DTOs;

/** Private metadata for batch creation or controlled orphan reconciliation; never an audit payload. */
final readonly class SkpStoredSource
{
    public function __construct(
        public string $disk,
        public string $object_key,
        public string $original_filename,
        public string $checksum_sha256,
    ) {}
}
