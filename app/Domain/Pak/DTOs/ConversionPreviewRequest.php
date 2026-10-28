<?php

namespace App\Domain\Pak\DTOs;

use Illuminate\Validation\ValidationException;

final readonly class ConversionPreviewRequest
{
    public function __construct(public int $pak_case_id, public string $requested_start, public string $requested_end)
    {
        if ($pak_case_id < 1) {
            throw ValidationException::withMessages(['pak_case_id' => 'Case ID wajib positif.']);
        }
        // Calendar/range validation belongs after authoritative case/rule checks.
    }
}
