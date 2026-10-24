<?php

namespace Tests\Feature\Pak;

use App\Domain\Pak\DTOs\SkpPersonResolution;
use App\Domain\Pak\Enums\SkpPersonResolutionStatus;
use App\Domain\Pak\Services\SkpPersonMatcher;
use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SkpPersonMatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_nip_preserves_leading_zeros_and_trims_only_edges(): void
    {
        $nip = '001234567890123456';
        $person = Person::factory()->create(['nip' => $nip]);
        $before = Person::all()->toArray();
        $result = (new SkpPersonMatcher)->match("\u{00A0}\t{$nip}\n\u{2003}");
        $this->assertSame(SkpPersonResolutionStatus::MATCHED, $result->status);
        $this->assertSame($person->id, $result->person_id);
        $this->assertSame($person->id, $result->requirePersonId());
        $this->assertSame($nip, $result->normalized_nip);
        $this->assertSame('EXACT_NIP', $result->match_method);
        $this->assertNull($result->issue_code);
        $this->assertSame($before, Person::all()->toArray());
    }

    public function test_no_name_fuzzy_or_other_identifier_fallback_and_no_creation(): void
    {
        $nip = '001234567890123456';
        Person::factory()->create(['nip' => '001234567890123457', 'canonical_name' => $nip, 'person_key' => $nip]);
        $result = (new SkpPersonMatcher)->match($nip);
        $this->assertSame(SkpPersonResolutionStatus::NOT_FOUND, $result->status);
        $this->assertSame('PERSON_NOT_FOUND', $result->issue_code);
        $this->assertNull($result->person_id);
        $this->assertSame($nip, $result->normalized_nip);
        $this->assertSame('EXACT_NIP', $result->match_method);
        $this->assertDatabaseCount('people', 1);
    }

    public static function malformedNips(): array
    {
        return [[null], [''], [" \t\u{00A0}"], [123456789012345678], [true], [[]],
            ['00123456789012345'], ['0012345678901234567'], ['001234567 890123456'],
            ['00123456789012345a'], ['1.23456789012345E17'], ["00123456789012345\xFF"],
            ['００１２３４５６７８９０１２３４５６']];
    }

    #[DataProvider('malformedNips')]
    public function test_malformed_inputs_are_invalid_without_query(mixed $nip): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $result = (new SkpPersonMatcher)->match($nip);
        $this->assertSame(SkpPersonResolutionStatus::INVALID, $result->status);
        $this->assertSame('PERSON_IDENTIFIER_INVALID', $result->issue_code);
        $this->assertNull($result->match_method);
        $this->assertNull($result->person_id);
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_ambiguity_is_explicit_without_removing_database_uniqueness(): void
    {
        // Controlled candidate fixture, not a fabricated production query or schema change.
        $matcher = new class extends SkpPersonMatcher
        {
            protected function candidateIds(string $nip): array
            {
                return [101, 102];
            }
        };
        $result = $matcher->match('001234567890123456');
        $this->assertSame(SkpPersonResolutionStatus::AMBIGUOUS, $result->status);
        $this->assertSame('PERSON_AMBIGUOUS', $result->issue_code);
        $this->assertNull($result->person_id);
        $this->assertSame('EXACT_NIP', $result->match_method);
    }

    public static function unresolvedStatuses(): array
    {
        return [[SkpPersonResolutionStatus::NOT_FOUND], [SkpPersonResolutionStatus::AMBIGUOUS], [SkpPersonResolutionStatus::INVALID]];
    }

    #[DataProvider('unresolvedStatuses')]
    public function test_unresolved_result_cannot_supply_person_id_to_normalization(SkpPersonResolutionStatus $status): void
    {
        $result = SkpPersonResolution::unresolved($status, null);
        $this->assertNull($result->person_id);
        $this->expectException(ValidationException::class);
        $result->requirePersonId();
    }

    public function test_unresolved_factory_cannot_claim_matched_status(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SkpPersonResolution::unresolved(SkpPersonResolutionStatus::MATCHED, '001234567890123456');
    }

    public function test_pending_is_not_a_terminal_resolution(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SkpPersonResolution::unresolved(SkpPersonResolutionStatus::PENDING, null);
    }

    public function test_matched_factory_rejects_nonpositive_person_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SkpPersonResolution::matched(0, '001234567890123456');
    }

    public function test_resolution_factories_assign_method_and_issue_consistently(): void
    {
        foreach ([
            [SkpPersonResolutionStatus::NOT_FOUND, 'PERSON_NOT_FOUND', 'EXACT_NIP'],
            [SkpPersonResolutionStatus::AMBIGUOUS, 'PERSON_AMBIGUOUS', 'EXACT_NIP'],
            [SkpPersonResolutionStatus::INVALID, 'PERSON_IDENTIFIER_INVALID', null],
        ] as [$status, $issue, $method]) {
            $result = SkpPersonResolution::unresolved($status, null);
            $this->assertSame($status, $result->status);
            $this->assertNull($result->person_id);
            $this->assertSame($issue, $result->issue_code);
            $this->assertSame($method, $result->match_method);
        }
        $matched = SkpPersonResolution::matched(7, '001234567890123456');
        $this->assertSame(SkpPersonResolutionStatus::MATCHED, $matched->status);
        $this->assertSame(7, $matched->person_id);
        $this->assertNull($matched->issue_code);
        $this->assertSame('EXACT_NIP', $matched->match_method);
    }
}
