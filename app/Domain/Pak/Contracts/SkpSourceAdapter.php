<?php

namespace App\Domain\Pak\Contracts;

use App\Domain\Pak\DTOs\SkpSourceRowData;

interface SkpSourceAdapter
{
    public function key(): string;

    public function version(): string;

    public function schemaVersion(): string;

    /**
     * Read a caller-owned readable stream without closing it.
     * Preserve source locator, minimum raw provenance, validation issues and structural
     * identity evidence. Do not synthesize identifiers from occurrence metadata.
     * No matching, fingerprinting, persistence, classification, authorization or calculation.
     * Consumers must apply the active-source validator before matching/normalizing.
     *
     * @param  resource  $stream
     * @return iterable<SkpSourceRowData>
     */
    public function read(mixed $stream): iterable;
}
