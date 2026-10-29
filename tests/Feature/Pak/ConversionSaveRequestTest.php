<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\DTOs\ConversionSaveRequest;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConversionSaveRequestTest extends TestCase
{
    private function input(): array
    {
        return ['pak_case_id' => 1, 'requested_start' => '2023-11-01', 'requested_end' => '2026-10-31', 'expected_case_lock_version' => 0,
            'expected_dependency_fingerprint' => str_repeat('a', 64), 'expected_snapshot_fingerprint' => str_repeat('b', 64)];
    }

    public static function forbidden(): array
    {
        return array_map(fn ($key) => [$key], ['person_id', 'pak_rule_set_id', 'evaluation_id', 'history_id', 'selected_source', 'position_assignment', 'predicate_factor', 'annual_coefficient', 'exact', 'total', 'issues', 'manifest', 'periods', 'presentation']);
    }

    #[DataProvider('forbidden')]
    public function test_client_authority_keys_rejected(string $key): void
    {
        $this->expectException(ValidationException::class);
        ConversionSaveRequest::from($this->input() + [$key => 'untrusted']);
    }

    public static function missing(): array
    {
        return [['expected_case_lock_version'], ['expected_dependency_fingerprint'], ['expected_snapshot_fingerprint']];
    }

    #[DataProvider('missing')]
    public function test_preconditions_mandatory(string $key): void
    {
        $data = $this->input();
        unset($data[$key]);
        $this->expectException(ValidationException::class);
        ConversionSaveRequest::from($data);
    }

    public static function invalid(): array
    {
        return [['pak_case_id', 0], ['pak_case_id', '1'], ['expected_case_lock_version', -1], ['expected_case_lock_version', '0'],
            ['expected_dependency_fingerprint', str_repeat('A', 64)], ['expected_snapshot_fingerprint', str_repeat('b', 63)],
            ['expected_dependency_fingerprint', str_repeat('g', 64)], ['expected_snapshot_fingerprint', null], ['requested_start', 2023]];
    }

    #[DataProvider('invalid')]
    public function test_invalid_shape_rejected(string $key, mixed $value): void
    {
        $data = $this->input();
        $data[$key] = $value;
        $this->expectException(ValidationException::class);
        ConversionSaveRequest::from($data);
    }

    public static function coercibleInputs(): array
    {
        return [...array_map(fn ($value) => ['pak_case_id', $value], ['1', 1.0, true, false]),
            ...array_map(fn ($value) => ['expected_case_lock_version', $value], ['0', '1', 0.0, 1.0, true, false])];
    }

    #[DataProvider('coercibleInputs')]
    public function test_weak_caller_cannot_coerce_request_fields(string $key, mixed $value): void
    {
        // This test file deliberately has no strict_types declaration.
        $input = $this->input();
        $input[$key] = $value;
        $this->expectException(ValidationException::class);
        ConversionSaveRequest::from($input);
    }

    public function test_weak_caller_cannot_bypass_factory(): void
    {
        $this->assertTrue((new \ReflectionClass(ConversionSaveRequest::class))->getConstructor()->isPrivate());
        $this->expectException(\Error::class);
        new ConversionSaveRequest('1', '2023-01-01', '2023-12-31', false, str_repeat('a', 64), str_repeat('b', 64));
    }

    public function test_positive_integer_version_is_accepted(): void
    {
        $input = $this->input();
        $input['expected_case_lock_version'] = 2;
        $this->assertSame(2, ConversionSaveRequest::from($input)->expected_case_lock_version);
    }

    public function test_zero_version_valid_and_calendar_validation_deferred(): void
    {
        $data = $this->input();
        $data['requested_start'] = 'not-a-date';
        $request = ConversionSaveRequest::from($data);
        $this->assertSame(0, $request->expected_case_lock_version);
        $this->assertSame('not-a-date', $request->requested_start);
        $this->assertCount(6, get_object_vars($request));
        $this->assertTrue((new \ReflectionClass($request))->isReadOnly());
    }
}
