<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\DTOs\NormalizedSkpRecord;
use App\Domain\Pak\DTOs\SkpSourceIdentityEvidence;
use App\Domain\Pak\DTOs\SkpSourceRowData;
use App\Domain\Pak\Enums\PerformanceEvaluationType;
use App\Domain\Pak\Enums\PerformanceSourceKind;
use App\Domain\Pak\Services\SkpFingerprintSerializer;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Unit-level contract tests: application boot supplies validation, no database access. */
class SkpFingerprintSerializerTest extends TestCase
{
    private function source(array $changes = []): SkpSourceRowData
    {
        return new SkpSourceRowData(...array_replace([
            'nip' => '001234',
            'source_kind' => PerformanceSourceKind::OFFICIAL,
            'performance_year' => 2025,
            'evaluation_type' => PerformanceEvaluationType::ANNUAL,
            'source_reference' => 'SKP/001',
            'source_record_identifier' => '0007a',
            'predicate_code' => 'BAIK',
            'predicate_label_original' => 'Baik',
            'coverage_start' => '2025-01-01',
            'coverage_end' => '2025-12-31',
            'stable_identifier_evidence' => new SkpSourceIdentityEvidence('synthetic-register-v1', 'register.record_code', $changes['source_record_identifier'] ?? '0007a'),
        ], $changes));
    }

    private function record(array $changes = [], int $personId = 7): NormalizedSkpRecord
    {
        return (new SkpFingerprintSerializer)->normalize($this->source($changes), $personId);
    }

    public function test_exact_byte_vectors_order_and_repeat_are_deterministic(): void
    {
        $source = '{"schema_version":"skp-source-v1","person_id":7,"source_kind":"OFFICIAL","performance_year":2025,"evaluation_type":"ANNUAL","source_reference":"SKP/001","source_record_identifier":"0007a"}';
        $content = '{"schema_version":"skp-content-v1","source_schema_version":"skp-source-v1","person_id":7,"source_kind":"OFFICIAL","performance_year":2025,"evaluation_type":"ANNUAL","source_reference":"SKP/001","source_record_identifier":"0007a","predicate_code":"BAIK","predicate_label_original":"Baik","coverage_start":"2025-01-01","coverage_end":"2025-12-31","source_date":null,"source_position_name":null,"source_functional_level_label":null,"source_position_tmt":null,"source_sk_number":null,"source_sk_date":null}';
        for ($i = 0; $i < 3; $i++) {
            $serializer = new SkpFingerprintSerializer;
            $record = $this->record();
            $this->assertSame($source, $serializer->sourceIdentityPayload($record));
            $this->assertSame($content, $serializer->contentPayload($record));
            $this->assertSame('e4adae7aeafe17acd93cd790b6e152bc6ed5c4280d2819788d0f388a99edead2', $serializer->sourceIdentityFingerprint($record));
            $this->assertSame('cb0db7eda50ee5ead02d1506b7a9e0f66c140fc2929c40c1e2340f9cd53ed96c', $serializer->contentFingerprint($record));
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $serializer->sourceIdentityFingerprint($record));
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $serializer->contentFingerprint($record));
        }
    }

    public static function identityChanges(): array
    {
        return [
            'person' => [[], 8],
            'kind' => [['source_kind' => PerformanceSourceKind::PROVISIONAL]],
            // Coverage must move with the year to retain the existing coverage invariant.
            'year' => [['performance_year' => 2024, 'coverage_start' => '2024-01-01', 'coverage_end' => '2024-12-31']],
            'type' => [['evaluation_type' => PerformanceEvaluationType::Q4]],
            'reference' => [['source_reference' => 'SKP/002']],
            'identifier' => [['source_record_identifier' => '0007A']],
        ];
    }

    #[DataProvider('identityChanges')]
    public function test_every_identity_field_changes_both_hashes(array $changes, int $personId = 7): void
    {
        $serializer = new SkpFingerprintSerializer;
        $before = $this->record();
        $after = $this->record($changes, $personId);
        $this->assertNotSame($serializer->sourceIdentityFingerprint($before), $serializer->sourceIdentityFingerprint($after));
        $this->assertNotSame($serializer->contentFingerprint($before), $serializer->contentFingerprint($after));
    }

    public static function contentChanges(): array
    {
        return [
            ['predicate_code', 'BAIK_2'], ['predicate_label_original', 'Baik sekali'],
            ['coverage_start', '2025-02-01'], ['coverage_end', '2025-11-30'],
            ['source_date', '2026-01-01'], ['source_position_name', 'Analis'],
            ['source_functional_level_label', 'Ahli Pertama'], ['source_position_tmt', '2024-12-01'],
            ['source_sk_number', '001/SK'], ['source_sk_date', '2024-11-20'],
        ];
    }

    #[DataProvider('contentChanges')]
    public function test_every_content_field_changes_only_content_hash(string $field, string $value): void
    {
        $serializer = new SkpFingerprintSerializer;
        $before = $this->record();
        $after = $this->record([$field => $value]);
        $this->assertSame($serializer->sourceIdentityFingerprint($before), $serializer->sourceIdentityFingerprint($after));
        $this->assertNotSame($serializer->contentFingerprint($before), $serializer->contentFingerprint($after));
    }

    public function test_null_is_not_empty_and_identifier_internal_whitespace_is_preserved(): void
    {
        $serializer = new SkpFingerprintSerializer;
        $this->assertNotSame($serializer->contentFingerprint($this->record()), $serializer->contentFingerprint($this->record(['source_position_name' => ''])));
        $record = $this->record(['source_record_identifier' => "\u{00A0}000a  B\t \u{2003}", 'source_sk_number' => ' 001  /a ']);
        $this->assertSame('000a  B', $record->source_record_identifier);
        $this->assertSame('001  /a', $record->source_sk_number);
        $this->assertNotSame($serializer->sourceIdentityFingerprint($record), $serializer->sourceIdentityFingerprint($this->record(['source_record_identifier' => '000a B'])));
    }

    public function test_cosmetic_whitespace_utf8_and_raw_provenance(): void
    {
        $source = $this->source([
            'source_reference' => "\tSKP/001\n",
            'predicate_code' => ' BAIK ',
            'predicate_label_original' => "\u{2003}Baik \t\n sekali\u{00A0}",
            'source_position_name' => " Analis\u{00A0}\u{2003}Éva/東京 ",
            'raw_provenance' => ['original' => " Analis\u{00A0}\u{2003}Éva/東京 "],
        ]);
        $record = (new SkpFingerprintSerializer)->normalize($source, 7);
        $plain = $this->record(['predicate_label_original' => 'Baik sekali', 'source_position_name' => 'Analis Éva/東京']);
        $serializer = new SkpFingerprintSerializer;
        $this->assertSame($serializer->sourceIdentityFingerprint($plain), $serializer->sourceIdentityFingerprint($record));
        $this->assertSame($serializer->contentFingerprint($plain), $serializer->contentFingerprint($record));
        $this->assertStringContainsString('Analis Éva/東京', $serializer->contentPayload($record));
        $this->assertSame(" Analis\u{00A0}\u{2003}Éva/東京 ", $source->raw_provenance['original']);
        $this->assertSame('001234', $source->nip);
    }

    public function test_no_case_folding_or_extra_unicode_normalization(): void
    {
        $serializer = new SkpFingerprintSerializer;
        $this->assertNotSame($serializer->contentFingerprint($this->record(['source_position_name' => 'Éva'])), $serializer->contentFingerprint($this->record(['source_position_name' => "E\u{0301}va"])));
        $this->assertNotSame($serializer->sourceIdentityFingerprint($this->record(['source_reference' => 'SKP/001'])), $serializer->sourceIdentityFingerprint($this->record(['source_reference' => 'skp/001'])));
        $this->assertSame('0007a', $this->record()->source_record_identifier);
    }

    public static function ineligibleSources(): array
    {
        return [
            [['source_record_identifier' => null]], [['source_record_identifier' => '']],
            [['source_record_identifier' => " \t\n\u{00A0}\u{2003}"]],
            [['stable_identifier_evidence' => null]],
            [['stable_identifier_evidence' => new SkpSourceIdentityEvidence('', 'register.code', '0007a')]],
            [['stable_identifier_evidence' => new SkpSourceIdentityEvidence('register-v1', "\u{00A0}\t", '0007a')]],
            [['stable_identifier_evidence' => new SkpSourceIdentityEvidence('register-v1', 'register.code', ' ')]],
            [['stable_identifier_evidence' => new SkpSourceIdentityEvidence('register-v1', 'register.code', 'DIFFERENT')]],
        ];
    }

    #[DataProvider('ineligibleSources')]
    public function test_missing_identifier_or_structural_evidence_cannot_create_eligible_dto(array $changes): void
    {
        $source = $this->source($changes);
        $this->assertInstanceOf(SkpSourceRowData::class, $source);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('SOURCE_IDENTITY_AMBIGUOUS');
        (new SkpFingerprintSerializer)->normalize($source, 7);
    }

    public static function invalidSources(): array
    {
        return [
            [['source_reference' => "bad\xFF"]], [['predicate_label_original' => "\xC3\x28"]],
            [['source_date' => "2025-01-\xFF"]], [['stable_identifier_evidence' => new SkpSourceIdentityEvidence("\xFF", 'register.code', '0007a')]],
            [['source_date' => '2025-02-29']], [['source_date' => '2025-1-01']],
            [['source_date' => '2025-01-01T00:00:00Z']], [['source_date' => ' 2025-01-01']],
            [['source_date' => '']], [['coverage_start' => '2024-01-01']],
            [['coverage_end' => '2025-01-01', 'coverage_start' => '2025-02-01']],
            [['source_kind' => null]], [['evaluation_type' => null]], [['predicate_code' => null]],
            [['predicate_label_original' => null]], [['source_reference' => ' ']],
            [['source_record_identifier' => str_repeat('x', 256)]],
            [['validation_issues' => ['SOURCE_VALIDATION_FAILED']]],
        ];
    }

    #[DataProvider('invalidSources')]
    public function test_invalid_source_is_rejected_before_hashing(array $changes): void
    {
        $this->expectException(ValidationException::class);
        $this->record($changes);
    }

    public function test_iso_dates_and_metadata_exclusion(): void
    {
        $record = $this->record(['source_date' => '2024-02-29']);
        $this->assertSame('2024-02-29', $record->source_date);
        $serializer = new SkpFingerprintSerializer;
        $other = $this->record(['source_date' => '2024-02-29', 'nip' => 'other', 'source_locator' => ['row' => 99], 'raw_provenance' => ['file' => 'other.xlsx'], 'stable_identifier_evidence' => new SkpSourceIdentityEvidence('other-register-v2', 'records.identifier', '0007a')]);
        $this->assertSame($serializer->sourceIdentityFingerprint($record), $serializer->sourceIdentityFingerprint($other));
        $this->assertSame($serializer->contentFingerprint($record), $serializer->contentFingerprint($other));
        $this->assertFalse((new \ReflectionClass(NormalizedSkpRecord::class))->getConstructor()->isPublic());
    }

    public function test_free_text_cannot_be_supplied_as_structural_evidence(): void
    {
        $this->expectException(\TypeError::class);
        $this->source(['stable_identifier_evidence' => 'Trust me, this is stable.']);
    }

    public function test_structural_evidence_is_preserved_without_generating_an_identifier(): void
    {
        $source = $this->source(['stable_identifier_evidence' => new SkpSourceIdentityEvidence(' register-v1 ', ' register.code ', ' 0007a ')]);
        $record = (new SkpFingerprintSerializer)->normalize($source, 7);
        $this->assertInstanceOf(NormalizedSkpRecord::class, $record);
        $this->assertSame('0007a', $record->source_record_identifier);
        $this->assertSame('register-v1', $record->stable_identifier_evidence->strategy_key);
        $this->assertSame('register.code', $record->stable_identifier_evidence->component_reference);
        $this->assertSame('0007a', $record->stable_identifier_evidence->component_value);
        $this->assertSame(' 0007a ', $source->stable_identifier_evidence->component_value);
    }

    public static function invalidFactoryValues(): array
    {
        return [
            [['source_record_identifier' => '']],
            [['stable_identifier_evidence' => new SkpSourceIdentityEvidence('register', '', '0007a')]],
            [['source_date' => 'not-a-date']],
            [['predicate_label_original' => "\xFF"]],
        ];
    }

    #[DataProvider('invalidFactoryValues')]
    public function test_internal_factory_cannot_bypass_fingerprint_boundary(array $changes): void
    {
        $record = NormalizedSkpRecord::fromCanonicalValues(array_replace(get_object_vars($this->record()), $changes));
        foreach (['sourceIdentityPayload', 'contentPayload', 'sourceIdentityFingerprint', 'contentFingerprint'] as $method) {
            try {
                (new SkpFingerprintSerializer)->$method($record);
                $this->fail('Invalid factory values must never reach canonical payloads or hashes.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public static function nonCanonicalValues(): array
    {
        return [
            [['source_reference' => ' SKP/001 ']],
            [['predicate_label_original' => "\tBaik\n"]],
            [['source_position_name' => 'Analis  Data']],
            [['stable_identifier_evidence' => new SkpSourceIdentityEvidence(' synthetic-register-v1 ', 'register.record_code', '0007a')]],
        ];
    }

    #[DataProvider('nonCanonicalValues')]
    public function test_internal_factory_cannot_bypass_canonicalization(array $changes): void
    {
        $canonical = $this->record();
        $manual = NormalizedSkpRecord::fromCanonicalValues(array_replace(get_object_vars($canonical), $changes));
        foreach (['sourceIdentityPayload', 'contentPayload', 'sourceIdentityFingerprint', 'contentFingerprint'] as $method) {
            try {
                (new SkpFingerprintSerializer)->$method($manual);
                $this->fail('Non-canonical records must be rejected, never silently repaired.');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString('NON_CANONICAL_RECORD', $exception->getMessage());
            }
        }
        foreach ($changes as $field => $value) {
            $this->assertSame($value, $manual->$field);
        }
    }

    public function test_canonical_evidence_uses_value_semantics_and_historical_enums_remain_representable(): void
    {
        $canonical = $this->record(['source_kind' => PerformanceSourceKind::PROVISIONAL, 'evaluation_type' => PerformanceEvaluationType::CUSTOM_PERIOD]);
        $equivalent = NormalizedSkpRecord::fromCanonicalValues(array_replace(get_object_vars($canonical), [
            'stable_identifier_evidence' => new SkpSourceIdentityEvidence('synthetic-register-v1', 'register.record_code', '0007a'),
        ]));
        $this->assertNotSame($canonical->stable_identifier_evidence, $equivalent->stable_identifier_evidence);
        $serializer = new SkpFingerprintSerializer;
        foreach (['sourceIdentityPayload', 'contentPayload', 'sourceIdentityFingerprint', 'contentFingerprint'] as $method) {
            $this->assertSame($serializer->$method($canonical), $serializer->$method($equivalent));
        }
        $this->assertStringContainsString('"source_kind":"PROVISIONAL"', $serializer->sourceIdentityPayload($equivalent));
        $this->assertStringContainsString('"evaluation_type":"CUSTOM_PERIOD"', $serializer->sourceIdentityPayload($equivalent));
    }
}
