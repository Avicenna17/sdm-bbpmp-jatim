<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\DTOs\ConversionReplaceRequest;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConversionReplaceRequestTest extends TestCase
{
    private function input(): array
    {
        return ['pak_case_id' => 1, 'expected_predecessor_run_id' => 2,
            'requested_start' => '2023-11-01', 'requested_end' => '2026-10-31',
            'expected_case_lock_version' => 0, 'expected_dependency_fingerprint' => str_repeat('a', 64),
            'expected_snapshot_fingerprint' => str_repeat('b', 64), 'revision_reason' => "  Explicit correction\n"];
    }

    public function test_exact_eight_fields_immutable_zero_version_and_trimmed_reason(): void
    {
        $request = ConversionReplaceRequest::from($this->input());
        $this->assertSame(array_replace($this->input(), ['revision_reason' => 'Explicit correction']), get_object_vars($request));
        $this->assertCount(8, get_object_vars($request));
        $reflection = new \ReflectionClass($request);
        $this->assertTrue($reflection->isReadOnly());
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->getConstructor()->isPrivate());
    }

    public function test_weak_caller_cannot_construct_directly(): void
    {
        // Deliberately no strict_types: factory must reject coercion before assignment.
        $this->expectException(\Error::class);
        new ConversionReplaceRequest(...$this->input());
    }

    public function test_reason_has_no_arbitrary_length_limit_and_valid_dates_are_not_normalized(): void
    {
        $input = array_replace($this->input(), ['revision_reason' => str_repeat('x', 2001),
            'requested_start' => '2024-02-29', 'requested_end' => '2024-02-29', 'expected_case_lock_version' => 3]);
        $this->assertSame($input, get_object_vars(ConversionReplaceRequest::from($input)));
    }

    public static function forbidden(): array
    {
        return array_map(fn ($key) => [$key], ['unknown', 'revision_number', 'status', 'active_case_id',
            'superseded_at', 'superseded_by_user_id', 'manifest', 'total', 'total_numerator', 'periods',
            'source_id', 'history_id', 'pak_rule_set_id', 'rule_snapshot', 'snapshot', 'person_id']);
    }

    #[DataProvider('forbidden')]
    public function test_unknown_or_client_authority_rejected(string $field): void
    {
        $this->expectException(ValidationException::class);
        ConversionReplaceRequest::from($this->input() + [$field => 'untrusted']);
    }

    public function test_every_field_is_mandatory(): void
    {
        foreach (array_keys($this->input()) as $key) {
            $input = $this->input();
            unset($input[$key]);
            try {
                ConversionReplaceRequest::from($input);
                $this->fail('Missing field accepted: '.$key);
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('request', $error->errors());
            }
        }
    }

    public static function invalid(): array
    {
        $cases = [];
        foreach (['pak_case_id', 'expected_predecessor_run_id', 'expected_case_lock_version'] as $field) {
            foreach (['1', '0', 1.0, 0.0, true, false, null, -1] as $value) {
                $cases[] = [$field, $value];
            }
        }
        foreach (['pak_case_id', 'expected_predecessor_run_id'] as $field) {
            $cases[] = [$field, 0];
        }
        foreach (['expected_dependency_fingerprint', 'expected_snapshot_fingerprint'] as $field) {
            foreach ([null, true, 1, [], str_repeat('A', 64), str_repeat('g', 64), str_repeat('a', 63), str_repeat('a', 65), str_repeat('a', 64)."\n"] as $value) {
                $cases[] = [$field, $value];
            }
        }
        foreach (['requested_start', 'requested_end'] as $field) {
            foreach ([null, true, 2024, '2024-2-01', '2023-02-29', '2024-04-31', '0000-01-01', '2024-00-01', '2024-01-00', '2024-01-01T00:00:00', '2024-01-01 ', "2024-01-01\n"] as $value) {
                $cases[] = [$field, $value];
            }
        }
        foreach ([null, true, 1, [], '', " \n\t "] as $value) {
            $cases[] = ['revision_reason', $value];
        }

        return $cases;
    }

    #[DataProvider('invalid')]
    public function test_invalid_input_rejected_without_weak_caller_coercion(string $field, mixed $value): void
    {
        $this->expectException(ValidationException::class);
        ConversionReplaceRequest::from(array_replace($this->input(), [$field => $value]));
    }

    public function test_reversed_range_rejected(): void
    {
        try {
            ConversionReplaceRequest::from(array_replace($this->input(), ['requested_start' => '2027-01-01']));
            $this->fail('Reversed range accepted.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('requested_end', $error->errors());
        }
    }
}
