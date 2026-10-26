<?php

namespace App\Domain\Pak\DTOs;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

/** Internal server input. Case dates must come from authoritative persisted state, not browser values. */
final readonly class MonthlyCoverageRequest
{
    private function __construct(
        public string $case_start,
        public string $case_end,
        public string $requested_start,
        public string $requested_end,
    ) {}

    public static function from(mixed $caseStart, mixed $caseEnd, mixed $requestedStart, mixed $requestedEnd): self
    {
        foreach (['case_start' => $caseStart, 'case_end' => $caseEnd,
            'requested_start' => $requestedStart, 'requested_end' => $requestedEnd] as $field => $date) {
            if (! is_string($date) || ! preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $date, $parts)
                || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                throw ValidationException::withMessages([$field => 'INVALID_COVERAGE_DATE']);
            }
        }
        if ($caseStart > $caseEnd) {
            throw ValidationException::withMessages(['case_end' => 'INVALID_CASE_RANGE']);
        }
        if ($requestedStart > $requestedEnd) {
            throw ValidationException::withMessages(['requested_end' => 'INVALID_REQUESTED_RANGE']);
        }
        if ($requestedStart < $caseStart || $requestedEnd > $caseEnd) {
            throw ValidationException::withMessages(['requested_range' => 'REQUESTED_RANGE_OUTSIDE_CASE']);
        }

        return new self($caseStart, $caseEnd, $requestedStart, $requestedEnd);
    }

    /** OD-03 boundary check only; never normalizes the validated original dates. */
    public function isFullMonth(): bool
    {
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $this->requested_end, new DateTimeZone('UTC'));

        return substr($this->requested_start, 8, 2) === '01' && $end->format('d') === $end->format('t');
    }
}
