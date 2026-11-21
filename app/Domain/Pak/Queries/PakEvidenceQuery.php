<?php

namespace App\Domain\Pak\Queries;

use App\Domain\Pak\Authorization\PakEvidenceAuthorization as Access;
use App\Models\PakCase;
use App\Models\PakCaseEvidence;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class PakEvidenceQuery
{
    public function forCase(User $actor, int $caseId): Builder
    {
        Access::authorize($actor);
        PakCase::query()->findOrFail($caseId);

        return PakCaseEvidence::query()->where('pak_case_id', $caseId)->with('uploader')
            ->orderByDesc('created_at')->orderByDesc('id');
    }

    public function find(User $actor, int $caseId, int $evidenceId): PakCaseEvidence
    {
        return $this->forCase($actor, $caseId)->findOrFail($evidenceId);
    }
}
