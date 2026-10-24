<?php

namespace App\Domain\Pak\Services;

use App\Models\PakEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

final class PakAuditService
{
    public function record(User $actor, Model $subject, string $code, array $payload = [], ?int $caseId = null): PakEvent
    {
        $types = ['User', 'EmployeeProfile', 'EmployeeRankHistory', 'EmployeePositionHistory', 'FunctionalCategory', 'FunctionalLevel', 'FunctionalPosition', 'FunctionalPositionLevel', 'PakRuleSet', 'PakCase', 'PerformanceEvaluation', 'PakSkpImportBatch'];
        abort_unless(in_array(class_basename($subject), $types, true), 422);

        return PakEvent::create(['pak_case_id' => $caseId, 'subject_type' => class_basename($subject), 'subject_id' => $subject->getKey(), 'event_code' => $code, 'actor_id' => $actor->id, 'payload' => Arr::only($payload, ['before', 'after', 'reason', 'supersedes_id']), 'occurred_at' => now()]);
    }
}
