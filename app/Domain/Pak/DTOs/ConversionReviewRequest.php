<?php

declare(strict_types=1);

namespace App\Domain\Pak\DTOs;

use Illuminate\Validation\ValidationException;

final readonly class ConversionReviewRequest
{
    private function __construct(
        public int $pak_case_id,
        public int $run_id,
        public string $expected_run_status,
        public int $expected_case_lock_version,
        public int $expected_assessment_sequence,
        public string $expected_assessment_fingerprint,
        public string $reason,
    ) {}

    public static function from(array $input): self
    {
        $keys = ['pak_case_id', 'run_id', 'expected_run_status', 'expected_case_lock_version',
            'expected_assessment_sequence', 'expected_assessment_fingerprint', 'reason'];
        if (array_diff(array_keys($input), $keys) || array_diff($keys, array_keys($input))) {
            self::reject('request');
        }
        foreach (['pak_case_id' => 1, 'run_id' => 1, 'expected_case_lock_version' => 0, 'expected_assessment_sequence' => 0] as $field => $minimum) {
            if (! is_int($input[$field]) || $input[$field] < $minimum) {
                self::reject($field);
            }
        }
        if (! in_array($input['expected_run_status'], ['PREVIEW', 'REQUIRES_REVIEW'], true)) {
            self::reject('expected_run_status');
        }
        if (! is_string($input['expected_assessment_fingerprint']) || ! preg_match('/\A[0-9a-f]{64}\z/', $input['expected_assessment_fingerprint'])) {
            self::reject('expected_assessment_fingerprint');
        }
        if (! is_string($input['reason']) || trim($input['reason']) === '') {
            self::reject('reason');
        }
        $input['reason'] = trim($input['reason']);

        return new self(...$input);
    }

    private static function reject(string $field): never
    {
        throw ValidationException::withMessages([$field => 'Invalid conversion review request.']);
    }
}
