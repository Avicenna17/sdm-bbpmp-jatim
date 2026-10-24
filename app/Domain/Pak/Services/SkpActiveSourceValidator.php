<?php

namespace App\Domain\Pak\Services;

use App\Domain\Pak\DTOs\SkpSourceRowData;
use App\Domain\Pak\Enums\PerformanceEvaluationType;
use App\Domain\Pak\Enums\PerformanceSourceKind;
use Illuminate\Validation\ValidationException;

final class SkpActiveSourceValidator
{
    /** @return list<array{field: string, code: string}> */
    public function issues(SkpSourceRowData $source): array
    {
        $issues = [];
        if ($source->source_kind === null) {
            $issues[] = ['field' => 'source_kind', 'code' => 'SOURCE_KIND_INVALID'];
        } elseif ($source->source_kind !== PerformanceSourceKind::OFFICIAL) {
            $issues[] = ['field' => 'source_kind', 'code' => 'SOURCE_KIND_NOT_ACTIVE'];
        }
        if ($source->evaluation_type === null) {
            $issues[] = ['field' => 'evaluation_type', 'code' => 'EVALUATION_TYPE_INVALID'];
        } elseif (! in_array($source->evaluation_type, [PerformanceEvaluationType::Q1, PerformanceEvaluationType::Q2,
            PerformanceEvaluationType::Q3, PerformanceEvaluationType::Q4, PerformanceEvaluationType::ANNUAL], true)) {
            $issues[] = ['field' => 'evaluation_type', 'code' => 'EVALUATION_TYPE_NOT_ACTIVE'];
        }

        return $issues;
    }

    public function validate(SkpSourceRowData $source): SkpSourceRowData
    {
        $issues = $this->issues($source);
        if ($issues !== []) {
            throw ValidationException::withMessages(array_column($issues, 'code', 'field'));
        }

        return $source;
    }
}
