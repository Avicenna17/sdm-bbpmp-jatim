<?php

namespace App\Domain\Pak\Services;

use App\Domain\Pak\DTOs\NormalizedSkpRecord;
use App\Domain\Pak\DTOs\SkpSourceIdentityEvidence;
use App\Domain\Pak\DTOs\SkpSourceRowData;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SkpFingerprintSerializer
{
    private const WHITESPACE = '[\p{Z}\x{0009}-\x{000D}]';

    private const CONTENT_FIELDS = [
        'predicate_code', 'predicate_label_original', 'coverage_start', 'coverage_end',
        'source_date', 'source_position_name', 'source_functional_level_label',
        'source_position_tmt', 'source_sk_number', 'source_sk_date',
    ];

    private const TEXT_FIELDS = ['predicate_label_original', 'source_position_name', 'source_functional_level_label'];

    /** No matching, persistence, or identity inference. */
    public function normalize(SkpSourceRowData $source, int $personId): NormalizedSkpRecord
    {
        $identifier = $this->string($source->source_record_identifier, 'source_record_identifier');
        $evidence = $source->stable_identifier_evidence;
        if ($identifier === null || $identifier === '' || $evidence === null) {
            throw ValidationException::withMessages(['source_record_identifier' => 'SOURCE_IDENTITY_AMBIGUOUS: identifier stabil dan bukti struktur sumber wajib tersedia.']);
        }
        $components = [];
        foreach (['strategy_key', 'component_reference', 'component_value'] as $field) {
            $components[$field] = $this->string($evidence->$field, 'stable_identifier_evidence.'.$field);
            if ($components[$field] === '') {
                throw ValidationException::withMessages(['stable_identifier_evidence.'.$field => 'SOURCE_IDENTITY_AMBIGUOUS: komponen bukti struktur wajib nonblank.']);
            }
        }
        if ($components['component_value'] !== $identifier) {
            throw ValidationException::withMessages(['stable_identifier_evidence.component_value' => 'SOURCE_IDENTITY_AMBIGUOUS: nilai komponen sumber tidak sama dengan identifier.']);
        }
        if ($source->validation_issues !== []) {
            throw ValidationException::withMessages(['source' => 'SOURCE_VALIDATION_FAILED: occurrence masih memiliki validation issues.']);
        }

        $values = [
            'person_id' => $personId,
            'source_kind' => $source->source_kind,
            'performance_year' => $source->performance_year,
            'evaluation_type' => $source->evaluation_type,
            'source_reference' => $this->string($source->source_reference, 'source_reference'),
            'source_record_identifier' => $identifier,
        ];
        foreach (self::CONTENT_FIELDS as $field) {
            // Dates must already be exact ISO dates: do not trim or repair them.
            $values[$field] = in_array($field, ['coverage_start', 'coverage_end', 'source_date', 'source_position_tmt', 'source_sk_date'], true)
                ? $this->date($source->$field, $field)
                : $this->string($source->$field, $field, in_array($field, self::TEXT_FIELDS, true));
        }

        Validator::make($values, [
            'person_id' => ['required', 'integer', 'min:1'],
            'source_kind' => ['required'], 'evaluation_type' => ['required'],
            'performance_year' => ['required', 'integer', 'min:0', 'max:65535'],
            'source_reference' => ['required', 'string', 'max:255'],
            'source_record_identifier' => ['required', 'string', 'max:255'],
            'predicate_code' => ['present', 'string', 'max:50'],
            'predicate_label_original' => ['present', 'string', 'max:255'],
            'coverage_start' => ['required'], 'coverage_end' => ['required'],
            'source_position_name' => ['nullable', 'string', 'max:255'],
            'source_functional_level_label' => ['nullable', 'string', 'max:255'],
            'source_sk_number' => ['nullable', 'string', 'max:150'],
        ])->validate();

        // Matches the approved Phase 02A.1 PerformanceEvaluation coverage invariant.
        if ($values['coverage_start'] > $values['coverage_end']
            || (int) substr($values['coverage_start'], 0, 4) !== $values['performance_year']
            || (int) substr($values['coverage_end'], 0, 4) !== $values['performance_year']) {
            throw ValidationException::withMessages(['coverage_start' => 'Coverage harus berurutan dan berada pada tahun kinerja.']);
        }

        return NormalizedSkpRecord::fromCanonicalValues($values + [
            'stable_identifier_evidence' => new SkpSourceIdentityEvidence(...$components),
        ]);
    }

    public function sourceIdentityPayload(NormalizedSkpRecord $record): string
    {
        $record = $this->validatedRecord($record);

        return $this->json(['schema_version' => 'skp-source-v1'] + $this->identity($record));
    }

    public function contentPayload(NormalizedSkpRecord $record): string
    {
        $record = $this->validatedRecord($record);
        $payload = ['schema_version' => 'skp-content-v1', 'source_schema_version' => 'skp-source-v1'] + $this->identity($record);
        foreach (self::CONTENT_FIELDS as $field) {
            $payload[$field] = $record->$field;
        }

        return $this->json($payload);
    }

    public function sourceIdentityFingerprint(NormalizedSkpRecord $record): string
    {
        return hash('sha256', $this->sourceIdentityPayload($record));
    }

    public function contentFingerprint(NormalizedSkpRecord $record): string
    {
        return hash('sha256', $this->contentPayload($record));
    }

    private function validatedRecord(NormalizedSkpRecord $record): NormalizedSkpRecord
    {
        $source = new SkpSourceRowData(...array_diff_key(get_object_vars($record), ['person_id' => true]));

        $canonical = $this->normalize($source, $record->person_id);
        foreach (get_object_vars($record) as $field => $value) {
            // Evidence is a value object: compare its scalar components, not its reference.
            $actual = $field === 'stable_identifier_evidence' ? get_object_vars($value) : $value;
            $expected = $field === 'stable_identifier_evidence' ? get_object_vars($canonical->$field) : $canonical->$field;
            if ($actual !== $expected) {
                throw ValidationException::withMessages([$field => 'NON_CANONICAL_RECORD: gunakan normalize() untuk raw source.']);
            }
        }

        return $record;
    }

    private function identity(NormalizedSkpRecord $record): array
    {
        return [
            'person_id' => $record->person_id,
            'source_kind' => $record->source_kind->value,
            'performance_year' => $record->performance_year,
            'evaluation_type' => $record->evaluation_type->value,
            'source_reference' => $record->source_reference,
            'source_record_identifier' => $record->source_record_identifier,
        ];
    }

    private function string(?string $value, string $field, bool $descriptive = false): ?string
    {
        if ($value === null) {
            return null;
        }
        $this->utf8($value, $field);
        $value = preg_replace('/\A'.self::WHITESPACE.'+|'.self::WHITESPACE.'+\z/u', '', $value);

        return $descriptive ? preg_replace('/'.self::WHITESPACE.'+/u', ' ', $value) : $value;
    }

    private function date(?string $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        $this->utf8($value, $field);
        if (! preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $value, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw ValidationException::withMessages([$field => 'Tanggal harus valid dengan format YYYY-MM-DD.']);
        }

        return $value;
    }

    private function utf8(string $value, string $field): void
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            throw ValidationException::withMessages([$field => 'UTF-8 tidak valid.']);
        }
    }

    private function json(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
