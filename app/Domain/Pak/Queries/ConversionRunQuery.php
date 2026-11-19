<?php

namespace App\Domain\Pak\Queries;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
use App\Models\PakCase;
use App\Models\PakConversionRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class ConversionRunQuery
{
    public static function allows(?User $actor): bool
    {
        return Access::allows($actor, 'pak.case.view') && Access::allows($actor, 'pak.conversion.view');
    }

    public function caseFor(User $actor, int $caseId): PakCase
    {
        abort_unless(self::allows($actor), 403);

        return PakCase::findOrFail($caseId);
    }

    public function revisions(User $actor, int $caseId): Collection
    {
        $this->caseFor($actor, $caseId);

        return PakConversionRun::where('pak_case_id', $caseId)->orderByDesc('revision_number')->orderByDesc('id')
            ->get(['id', 'pak_case_id', 'revision_number', 'status']);
    }

    public function find(User $actor, int $caseId, int $runId): PakConversionRun
    {
        $this->caseFor($actor, $caseId);

        return PakConversionRun::where('pak_case_id', $caseId)->with([
            'periods',
            'predecessor' => fn ($query) => $query->where('pak_case_id', $caseId)->select('id', 'pak_case_id', 'revision_number'),
            'successor' => fn ($query) => $query->where('pak_case_id', $caseId)->select('id', 'pak_case_id', 'predecessor_run_id', 'revision_number'),
        ])->findOrFail($runId);
    }
}
