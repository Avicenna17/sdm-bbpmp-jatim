<?php

declare(strict_types=1);

namespace App\Domain\Pak\DTOs;

use Illuminate\Validation\ValidationException;

final readonly class ConversionSaveRequest
{
    private function __construct(
        public int $pak_case_id,
        public string $requested_start,
        public string $requested_end,
        public int $expected_case_lock_version,
        public string $expected_dependency_fingerprint,
        public string $expected_snapshot_fingerprint,
    ) {
        if ($pak_case_id < 1 || $expected_case_lock_version < 0
            || ! preg_match('/\A[0-9a-f]{64}\z/', $expected_dependency_fingerprint)
            || ! preg_match('/\A[0-9a-f]{64}\z/', $expected_snapshot_fingerprint)) {
            throw ValidationException::withMessages(['request' => 'Invalid conversion save preconditions.']);
        }
    }

    public static function from(array $input): self
    {
        $keys = ['pak_case_id', 'requested_start', 'requested_end', 'expected_case_lock_version', 'expected_dependency_fingerprint', 'expected_snapshot_fingerprint'];
        if (array_diff(array_keys($input), $keys) || array_diff($keys, array_keys($input))
            || ! is_int($input['pak_case_id']) || ! is_int($input['expected_case_lock_version'])) {
            throw ValidationException::withMessages(['request' => 'Only conversion intent and all three preconditions are accepted.']);
        }
        foreach (array_diff($keys, ['pak_case_id', 'expected_case_lock_version']) as $key) {
            if (! is_string($input[$key])) {
                throw ValidationException::withMessages([$key => 'String required.']);
            }
        }

        return new self(...$input);
    }
}
