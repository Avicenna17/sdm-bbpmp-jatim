<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\DTOs\ConversionReviewRequest;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ConversionReviewRequestTest extends TestCase
{
    private function input(): array
    {
        return ['pak_case_id' => 1, 'run_id' => 2, 'expected_run_status' => 'PREVIEW',
            'expected_case_lock_version' => 0, 'expected_assessment_sequence' => 0,
            'expected_assessment_fingerprint' => str_repeat('a', 64), 'reason' => 'Synthetic reason'];
    }

    public function test_exact_native_request_trims_reason_without_arbitrary_limit_and_is_immutable(): void
    {
        $input = $this->input();
        $this->assertSame($input, get_object_vars(ConversionReviewRequest::from($input)));
        $input['reason'] = " \n".str_repeat('r', 3000)."\t ";
        $input['expected_run_status'] = 'REQUIRES_REVIEW';
        $request = ConversionReviewRequest::from($input);
        $this->assertSame(str_repeat('r', 3000), $request->reason);
        $this->expectException(\Error::class);
        $request->reason = 'Changed';
    }

    public function test_missing_unknown_and_coerced_values_are_rejected(): void
    {
        $valid = $this->input();
        $bad = [[]];
        foreach (array_keys($valid) as $field) {
            $missing = $valid;
            unset($missing[$field]);
            $bad[] = $missing;
        }
        foreach (['desired_status', 'actor', 'timestamp', 'review_assessment', 'manifest', 'issues', 'totals',
            'source_id', 'history_id', 'rule_id', 'current_dependency_fingerprint', 'requested_start'] as $field) {
            $bad[] = $valid + [$field => 'untrusted'];
        }
        foreach (['pak_case_id', 'run_id', 'expected_case_lock_version', 'expected_assessment_sequence'] as $field) {
            foreach ([null, '1', 1.0, true, false, -1, [], new \stdClass] as $value) {
                $bad[] = array_replace($valid, [$field => $value]);
            }
        }
        foreach (['pak_case_id', 'run_id'] as $field) {
            $bad[] = array_replace($valid, [$field => 0]);
        }
        foreach ([null, false, 1, [], 'SUPERSEDED', 'preview', 'APPROVED', ' PREVIEW'] as $value) {
            $bad[] = array_replace($valid, ['expected_run_status' => $value]);
        }
        foreach ([null, 1, [], str_repeat('A', 64), str_repeat('g', 64), str_repeat('a', 63), str_repeat('a', 65), str_repeat('a', 64)."\n"] as $value) {
            $bad[] = array_replace($valid, ['expected_assessment_fingerprint' => $value]);
        }
        foreach ([null, 1, false, [], '', " \n\t "] as $value) {
            $bad[] = array_replace($valid, ['reason' => $value]);
        }
        foreach ($bad as $input) {
            try {
                ConversionReviewRequest::from($input);
                $this->fail('Invalid request accepted.');
            } catch (ValidationException $error) {
                $this->assertCount(1, $error->errors());
            }
        }
    }
}
