<?php

namespace App\Domain\Pak\DTOs;

use App\Domain\Pak\Enums\PakPurposeType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final readonly class CreatePakCaseData
{
    public function __construct(public array $values) {}

    public static function from(array $input): self
    {
        return new self(Validator::make($input, [
            'person_id' => 'required|integer|exists:people,id',
            'purpose_type' => ['required', Rule::enum(PakPurposeType::class)],
            'period_start' => 'required|date_format:Y-m-d', 'period_end' => 'required|date_format:Y-m-d|after_or_equal:period_start',
            'employee_data_as_of' => 'required|date_format:Y-m-d', 'pak_rule_set_id' => 'nullable|integer|exists:pak_rule_sets,id',
            'notes' => 'nullable|string|max:10000',
            'source_type' => 'sometimes|in:ADMIN_MANUAL', 'status' => 'sometimes|in:DRAFT',
            'finalized_at' => 'prohibited', 'finalized_by' => 'prohibited', 'case_type' => 'prohibited',
        ])->validate());
    }
}
