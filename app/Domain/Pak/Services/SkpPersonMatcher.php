<?php

namespace App\Domain\Pak\Services;

use App\Domain\Pak\DTOs\SkpPersonResolution;
use App\Domain\Pak\Enums\SkpPersonResolutionStatus;
use App\Models\Person;

class SkpPersonMatcher
{
    public function match(mixed $nip): SkpPersonResolution
    {
        if (! is_string($nip) || ! mb_check_encoding($nip, 'UTF-8')) {
            return SkpPersonResolution::unresolved(SkpPersonResolutionStatus::INVALID, null);
        }
        $normalized = preg_replace('/\A[\p{Z}\x{0009}-\x{000D}]+|[\p{Z}\x{0009}-\x{000D}]+\z/u', '', $nip);
        // Existing SDM WorkbookParser requires 18 intact digits. Do not repair internal whitespace.
        if (! preg_match('/\A[0-9]{18}\z/', $normalized)) {
            return SkpPersonResolution::unresolved(SkpPersonResolutionStatus::INVALID, $normalized);
        }
        $candidates = $this->candidateIds($normalized);

        return match (count($candidates)) {
            0 => SkpPersonResolution::unresolved(SkpPersonResolutionStatus::NOT_FOUND, $normalized),
            1 => SkpPersonResolution::matched($candidates[0], $normalized),
            default => SkpPersonResolution::unresolved(SkpPersonResolutionStatus::AMBIGUOUS, $normalized),
        };
    }

    /** @return list<int> */
    protected function candidateIds(string $nip): array
    {
        return Person::query()->where('nip', $nip)->get(['id', 'nip'])
            // Database collations may treat trailing spaces as equal; the contract is exact.
            ->filter(fn (Person $person) => $person->nip === $nip)
            ->map(fn (Person $person) => (int) $person->getKey())->values()->all();
    }
}
