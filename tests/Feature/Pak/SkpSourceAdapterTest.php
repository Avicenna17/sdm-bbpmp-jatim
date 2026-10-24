<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\Contracts\SkpSourceAdapter;
use App\Domain\Pak\DTOs\SkpSourceRowData;
use App\Domain\Pak\Enums\PerformanceEvaluationType;
use App\Domain\Pak\Enums\PerformanceSourceKind;
use App\Domain\Pak\Services\SkpActiveSourceValidator;
use App\Domain\Pak\Services\SkpFingerprintSerializer;
use App\Domain\Pak\Services\SkpPersonMatcher;
use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Pak\SyntheticSkpSourceAdapter;
use Tests\TestCase;

class SkpSourceAdapterTest extends TestCase
{
    use RefreshDatabase;

    private function rows(array $changes = []): array
    {
        $row = array_replace([
            'nip' => '001234567890123456', 'source_kind' => 'OFFICIAL',
            'performance_year' => 2025, 'evaluation_type' => 'ANNUAL',
            'source_reference' => 'SYNTHETIC/REGISTER', 'source_record_identifier' => 'SRC-0007',
            'predicate_code' => 'BAIK', 'predicate_label_original' => 'Baik',
            'coverage_start' => '2025-01-01', 'coverage_end' => '2025-12-31',
            'source_locator' => ['section' => 'evaluations', 'row' => 42],
            'raw_provenance' => ['label' => ' Baik '], 'validation_issues' => [],
        ], $changes);
        $stream = fopen('php://memory', 'w+');
        try {
            fwrite($stream, json_encode([$row], JSON_THROW_ON_ERROR));
            rewind($stream);
            $rows = iterator_to_array((new SyntheticSkpSourceAdapter)->read($stream));
            $this->assertTrue(is_resource($stream));

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    public function test_adapter_contract_preserves_occurrence_without_database_or_fingerprints(): void
    {
        $adapter = new SyntheticSkpSourceAdapter;
        $this->assertInstanceOf(SkpSourceAdapter::class, $adapter);
        $this->assertSame('synthetic-skp', $adapter->key());
        $this->assertSame('1', $adapter->version());
        $this->assertSame('synthetic-skp-source-v1', $adapter->schemaVersion());
        DB::enableQueryLog();
        DB::flushQueryLog();
        [$row] = $this->rows(['validation_issues' => ['SYNTHETIC_SOURCE_ISSUE']]);
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertInstanceOf(SkpSourceRowData::class, $row);
        $this->assertSame(['section' => 'evaluations', 'row' => 42], $row->source_locator);
        $this->assertSame(['label' => ' Baik '], $row->raw_provenance);
        $this->assertSame(['SYNTHETIC_SOURCE_ISSUE'], $row->validation_issues);
        $this->assertSame('SRC-0007', $row->source_record_identifier);
        $this->assertSame('SRC-0007', $row->stable_identifier_evidence->component_value);
        $this->assertSame('source_record_identifier', $row->stable_identifier_evidence->component_reference);
        $this->assertSame('synthetic-record-code-v1', $row->stable_identifier_evidence->strategy_key);
        $this->assertArrayNotHasKey('person_id', get_object_vars($row));
        $this->assertArrayNotHasKey('source_identity_fingerprint', get_object_vars($row));
        $this->assertDatabaseCount('people', 0);
        $this->assertDatabaseCount('performance_evaluations', 0);
        $this->assertDatabaseCount('pak_skp_import_rows', 0);
        [$moved] = $this->rows(['source_locator' => ['row' => 100]]);
        $this->assertSame($row->source_record_identifier, $moved->source_record_identifier);
    }

    public static function activeTypes(): array
    {
        return [['Q1'], ['Q2'], ['Q3'], ['Q4'], ['ANNUAL']];
    }

    #[DataProvider('activeTypes')]
    public function test_active_source_accepted_then_matched_and_normalized(string $type): void
    {
        $person = Person::factory()->create(['nip' => '001234567890123456']);
        [$row] = $this->rows(['evaluation_type' => $type]);
        $validator = new SkpActiveSourceValidator;
        $this->assertSame([], $validator->issues($row));
        $this->assertSame($row, $validator->validate($row));
        $resolution = (new SkpPersonMatcher)->match($row->nip);
        $record = (new SkpFingerprintSerializer)->normalize($row, $resolution->requirePersonId());
        $this->assertSame($person->id, $record->person_id);
        $this->assertSame(PerformanceSourceKind::OFFICIAL, $record->source_kind);
        $this->assertSame(PerformanceEvaluationType::from($type), $record->evaluation_type);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', (new SkpFingerprintSerializer)->contentFingerprint($record));
        $this->assertDatabaseCount('performance_evaluations', 0);
    }

    public static function inactiveSources(): array
    {
        return [
            [['source_kind' => 'PROVISIONAL'], 'source_kind', 'SOURCE_KIND_NOT_ACTIVE'],
            [['evaluation_type' => 'CUSTOM_PERIOD'], 'evaluation_type', 'EVALUATION_TYPE_NOT_ACTIVE'],
            [['source_kind' => null], 'source_kind', 'SOURCE_KIND_INVALID'],
            [['evaluation_type' => null], 'evaluation_type', 'EVALUATION_TYPE_INVALID'],
            [['source_kind' => 'UNKNOWN'], 'source_kind', 'SOURCE_KIND_INVALID'],
            [['evaluation_type' => 'UNKNOWN'], 'evaluation_type', 'EVALUATION_TYPE_INVALID'],
        ];
    }

    #[DataProvider('inactiveSources')]
    public function test_inactive_input_is_known_validation_outcome(array $changes, string $field, string $code): void
    {
        [$row] = $this->rows($changes);
        if (str_ends_with($code, '_INVALID')) {
            $this->assertNull($row->$field);
        }
        $validator = new SkpActiveSourceValidator;
        $this->assertSame([['field' => $field, 'code' => $code]], $validator->issues($row));
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($code);
        $validator->validate($row);
    }

    public function test_historical_serializer_representation_is_unchanged(): void
    {
        [$row] = $this->rows(['source_kind' => 'PROVISIONAL', 'evaluation_type' => 'CUSTOM_PERIOD']);
        $this->assertCount(2, (new SkpActiveSourceValidator)->issues($row));
        $record = (new SkpFingerprintSerializer)->normalize($row, 7);
        $this->assertSame(PerformanceSourceKind::PROVISIONAL, $record->source_kind);
        $this->assertSame(PerformanceEvaluationType::CUSTOM_PERIOD, $record->evaluation_type);
    }

    public function test_missing_source_identifier_is_not_synthesized(): void
    {
        [$row] = $this->rows(['source_record_identifier' => null]);
        $this->assertNull($row->source_record_identifier);
        $this->assertNull($row->stable_identifier_evidence);
    }
}
