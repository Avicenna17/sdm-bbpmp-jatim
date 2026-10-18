<?php

namespace App\Domain\Pak\DTOs;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class HistoricalOrganizationContext
{
    public function __construct(public array $values) {}

    public static function from(?array $input, string $asOf): self
    {
        if (! $input || ! array_filter($input, fn ($v) => $v !== null && $v !== '')) {
            return new self([]);
        }
        $data = Validator::make($input, [
            'institution_name' => 'nullable|string|max:255', 'work_unit' => 'nullable|string|max:255',
            'source_reference' => 'required|string|max:255', 'source_date' => 'required|date_format:Y-m-d',
            'valid_from' => 'required|date_format:Y-m-d', 'valid_until' => 'nullable|date_format:Y-m-d|after_or_equal:valid_from',
        ])->validate();
        // Missing end is a point-in-time assertion, not permission to extrapolate forever.
        $end = $data['valid_until'] ?? $data['valid_from'];
        if ($asOf < $data['valid_from'] || $asOf > $end) {
            throw ValidationException::withMessages(['organization.valid_from' => 'Sumber organisasi harus mencakup tanggal acuan; tanpa akhir berlaku hanya pada tanggal sumber berlaku.']);
        }

        return new self($data);
    }
}
