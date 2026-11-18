<?php

declare(strict_types=1);

namespace App\Domain\Pak\DTOs;

use Illuminate\Validation\ValidationException;

final readonly class ConversionAssessmentRequest
{
    private function __construct(public int $pak_case_id, public int $run_id) {}

    public static function from(array $input): self
    {
        $keys = ['pak_case_id', 'run_id'];
        if (array_diff(array_keys($input), $keys) || array_diff($keys, array_keys($input))
            || ! is_int($input['pak_case_id']) || $input['pak_case_id'] < 1
            || ! is_int($input['run_id']) || $input['run_id'] < 1) {
            throw ValidationException::withMessages(['request' => 'Only positive integer case and run identities are accepted.']);
        }

        return new self(...$input);
    }
}
