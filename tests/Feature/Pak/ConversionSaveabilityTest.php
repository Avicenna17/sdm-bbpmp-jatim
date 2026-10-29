<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\DTOs\ConversionDependencyGraph;
use App\Domain\Pak\DTOs\ConversionPreview;
use App\Domain\Pak\DTOs\ConversionPreviewLine;
use App\Domain\Pak\DTOs\ConversionPreviewRequest;
use App\Domain\Pak\DTOs\MonthlyCoverageRequest;
use App\Domain\Pak\Enums\ConversionRunStatus;
use App\Domain\Pak\Rules\ConversionSaveability;
use App\Domain\Pak\Services\MonthlyCoverageResolver;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConversionSaveabilityTest extends TestCase
{
    private function makeResult(array $issues, bool $line = true): ConversionPreview
    {
        $partial = in_array('PARTIAL_MONTH_REQUIRES_REVIEW', array_column($issues, 'issue_code'), true);
        $start = $partial ? '2024-01-02' : '2024-01-01';
        $line = $line && ! $partial;
        $coverage = app(MonthlyCoverageResolver::class)->resolve(MonthlyCoverageRequest::from('2024-01-01', '2024-12-31', $start, '2024-01-31'));
        $groups = array_fill_keys(['case_context', 'coverage', 'source_candidates', 'selected_sources', 'position_assignments', 'history_dependencies', 'rule_set', 'versions'], []);

        return new ConversionPreview(new ConversionPreviewRequest(1, $start, '2024-01-31'), 0, $coverage, null,
            $line ? [new ConversionPreviewLine(['2024-01'], null, null, null, null, null, null, $issues, null, null)] : [],
            null, $issues, new ConversionDependencyGraph($groups), str_repeat('a', 64), str_repeat('b', 64), null, null);
    }

    public static function codes(): array
    {
        return array_map(fn ($c) => [$c], ConversionSaveability::REVIEW_CODES);
    }

    #[DataProvider('codes')]
    public function test_explicit_allowlist(string $code): void
    {
        $this->assertSame(ConversionRunStatus::REQUIRES_REVIEW, (new ConversionSaveability)->statusFor($this->makeResult([['issue_code' => $code]])));
    }

    public static function blocking(): array
    {
        return array_map(fn ($c) => [$c], ['SOURCE_INTEGRITY_ERROR', 'RULE_INTEGRITY_ERROR', 'RULE_SET_NOT_USABLE', 'RULE_SET_NOT_FOUND', 'INVALID_RULE_INPUT', 'UNKNOWN_FUTURE_ISSUE']);
    }

    #[DataProvider('blocking')]
    public function test_mixed_or_unknown_is_default_denied(string $code): void
    {
        $this->expectException(ValidationException::class);
        (new ConversionSaveability)->statusFor($this->makeResult([['issue_code' => 'SOURCE_NOT_FOUND'], ['issue_code' => $code]]));
    }

    public function test_no_issues_incomplete_result_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        (new ConversionSaveability)->statusFor($this->makeResult([], false));
    }

    public function test_missing_diagnostic_periods_are_not_fabricated_or_accepted(): void
    {
        $this->expectException(ValidationException::class);
        (new ConversionSaveability)->statusFor($this->makeResult([['issue_code' => 'SOURCE_NOT_FOUND']], false));
    }
}
