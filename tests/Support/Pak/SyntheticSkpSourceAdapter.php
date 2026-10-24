<?php

namespace Tests\Support\Pak;

use App\Domain\Pak\Contracts\SkpSourceAdapter;
use App\Domain\Pak\DTOs\SkpSourceIdentityEvidence;
use App\Domain\Pak\DTOs\SkpSourceRowData;
use App\Domain\Pak\Enums\PerformanceEvaluationType;
use App\Domain\Pak\Enums\PerformanceSourceKind;

/** Test-only JSON source with explicit stable record codes; not a client workbook format. */
final class SyntheticSkpSourceAdapter implements SkpSourceAdapter
{
    public function key(): string
    {
        return 'synthetic-skp';
    }

    public function version(): string
    {
        return '1';
    }

    public function schemaVersion(): string
    {
        return 'synthetic-skp-source-v1';
    }

    public function read(mixed $stream): iterable
    {
        foreach (json_decode(stream_get_contents($stream), true, flags: JSON_THROW_ON_ERROR) as $row) {
            yield new SkpSourceRowData(...array_replace($row, [
                'source_kind' => PerformanceSourceKind::tryFrom($row['source_kind'] ?? ''),
                'evaluation_type' => PerformanceEvaluationType::tryFrom($row['evaluation_type'] ?? ''),
                'stable_identifier_evidence' => isset($row['source_record_identifier'])
                    ? new SkpSourceIdentityEvidence('synthetic-record-code-v1', 'source_record_identifier', $row['source_record_identifier']) : null,
            ]));
        }
    }
}
