<?php

declare(strict_types=1);

namespace App\Domain\Pak\DTOs;

use DateTimeImmutable;
use Illuminate\Validation\ValidationException;

final readonly class ConversionReplaceRequest
{
    private function __construct(
        public int $pak_case_id,
        public int $expected_predecessor_run_id,
        public string $requested_start,
        public string $requested_end,
        public int $expected_case_lock_version,
        public string $expected_dependency_fingerprint,
        public string $expected_snapshot_fingerprint,
        public string $revision_reason,
    ) {}

    public static function from(array $input): self
    {
        $keys = ['pak_case_id', 'expected_predecessor_run_id', 'requested_start', 'requested_end',
            'expected_case_lock_version', 'expected_dependency_fingerprint', 'expected_snapshot_fingerprint', 'revision_reason'];
        if (array_diff(array_keys($input), $keys) || array_diff($keys, array_keys($input))) {
            self::reject('request');
        }
        foreach (['pak_case_id' => 1, 'expected_predecessor_run_id' => 1, 'expected_case_lock_version' => 0] as $field => $minimum) {
            if (! is_int($input[$field]) || $input[$field] < $minimum) {
                self::reject($field);
            }
        }
        foreach (array_diff($keys, ['pak_case_id', 'expected_predecessor_run_id', 'expected_case_lock_version']) as $field) {
            if (! is_string($input[$field])) {
                self::reject($field);
            }
        }
        foreach (['requested_start', 'requested_end'] as $field) {
            $value = $input[$field];
            if (! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value)
                || substr($value, 0, 4) === '0000'
                || ($date = DateTimeImmutable::createFromFormat('!Y-m-d', $value)) === false
                || $date->format('Y-m-d') !== $value) {
                self::reject($field);
            }
        }
        if ($input['requested_start'] > $input['requested_end']) {
            self::reject('requested_end');
        }
        foreach (['expected_dependency_fingerprint', 'expected_snapshot_fingerprint'] as $field) {
            if (! preg_match('/\A[0-9a-f]{64}\z/', $input[$field])) {
                self::reject($field);
            }
        }
        $input['revision_reason'] = trim($input['revision_reason']);
        if ($input['revision_reason'] === '') {
            self::reject('revision_reason');
        }

        return new self(...$input);
    }

    private static function reject(string $field): never
    {
        throw ValidationException::withMessages([$field => 'Invalid conversion replacement request.']);
    }
}
