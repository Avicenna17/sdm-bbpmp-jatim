<?php

namespace App\Domain\Pak\DTOs;

use App\Domain\Pak\Enums\SkpPersonResolutionStatus;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class SkpPersonResolution
{
    private function __construct(
        public SkpPersonResolutionStatus $status,
        public ?int $person_id,
        public ?string $normalized_nip,
        public ?string $issue_code,
        public ?string $match_method,
    ) {}

    public static function matched(int $personId, string $nip): self
    {
        if ($personId < 1) {
            throw new InvalidArgumentException('Matched person ID must be positive.');
        }

        return new self(SkpPersonResolutionStatus::MATCHED, $personId, $nip, null, 'EXACT_NIP');
    }

    public static function unresolved(SkpPersonResolutionStatus $status, ?string $nip): self
    {
        $issue = match ($status) {
            SkpPersonResolutionStatus::INVALID => 'PERSON_IDENTIFIER_INVALID',
            SkpPersonResolutionStatus::NOT_FOUND => 'PERSON_NOT_FOUND',
            SkpPersonResolutionStatus::AMBIGUOUS => 'PERSON_AMBIGUOUS',
            default => throw new InvalidArgumentException('Expected an unresolved terminal status.'),
        };

        return new self($status, null, $nip, $issue, $status === SkpPersonResolutionStatus::INVALID ? null : 'EXACT_NIP');
    }

    public function requirePersonId(): int
    {
        if ($this->status !== SkpPersonResolutionStatus::MATCHED) {
            throw ValidationException::withMessages(['nip' => $this->issue_code]);
        }

        return $this->person_id;
    }
}
