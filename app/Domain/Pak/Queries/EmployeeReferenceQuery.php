<?php

namespace App\Domain\Pak\Queries;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
use App\Domain\Pak\DTOs\DukReferenceData;
use App\Models\PersonnelSnapshot;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class EmployeeReferenceQuery
{
    private function query(User $actor): Builder
    {
        Access::authorize($actor, 'pak.employee.view');
        $fields = array_diff(DukReferenceData::FIELDS, ['period_month', 'published_at', 'revision']);

        return PersonnelSnapshot::query()->join('reporting_periods as p', 'p.id', '=', 'personnel_snapshots.reporting_period_id')
            ->where('p.status', 'published')->select(array_map(fn ($f) => 'personnel_snapshots.'.$f, $fields))
            ->addSelect(['p.period_month', 'p.published_at', 'p.revision']);
    }

    public function search(User $actor, int $personId, string $asOf, string $search = '', int $page = 1)
    {
        return $this->query($actor)->where('personnel_snapshots.person_id', $personId)->whereDate('p.period_month', '<=', $asOf)
            ->when($search !== '', fn ($q) => $q->where('personnel_snapshots.name_at_period', 'like', '%'.mb_substr($search, 0, 100).'%'))
            ->orderByDesc('p.period_month')->paginate(20, ['*'], 'page', max(1, $page))
            ->through(fn ($row) => (new DukReferenceData($row->getAttributes()))->toArray());
    }

    public function find(User $actor, int $snapshotId, int $personId, string $asOf, ?int $periodId = null, bool $current = false): DukReferenceData
    {
        $row = $this->query($actor)->where('personnel_snapshots.id', $snapshotId)->where('personnel_snapshots.person_id', $personId)
            ->when($periodId, fn ($q) => $q->where('p.id', $periodId))->whereDate('p.period_month', '<=', $asOf)
            ->when($current, fn ($q) => $q->sharedLock())->firstOrFail();

        return new DukReferenceData($row->getAttributes());
    }
}
