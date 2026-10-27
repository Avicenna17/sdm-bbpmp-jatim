<?php

namespace App\Domain\Pak\Services;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
use App\Domain\Pak\Enums\EmployeePositionType;
use App\Models\EmployeePositionHistory;
use App\Models\EmployeeRankHistory;
use App\Models\FunctionalCategory;
use App\Models\FunctionalLevel;
use App\Models\FunctionalPosition;
use App\Models\FunctionalPositionLevel;
use App\Models\Person;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class EmployeeHistoryService
{
    public function record(User $actor, Person $person, array $input, bool $position = false, ?int $supersedesId = null, ?string $reason = null)
    {
        Access::authorize($actor, 'pak.employee.manage');
        $rules = ['tmt_start' => 'required|date_format:Y-m-d', 'tmt_end' => 'nullable|date_format:Y-m-d|after_or_equal:tmt_start', 'sk_number' => 'nullable|string|max:150', 'sk_date' => 'nullable|date_format:Y-m-d', 'source_ref' => 'required|string|max:255'];
        $rules += $position ? ['position_name' => 'required|string|max:255', 'position_type' => ['required', Rule::enum(EmployeePositionType::class)], 'functional_position_id' => 'nullable|integer', 'functional_level_id' => 'nullable|integer'] : ['rank_name' => 'required|string|max:150', 'grade_code' => 'required|string|max:30'];
        $data = Validator::make($input, $rules)->validate();
        if ($supersedesId) {
            Validator::make(['reason' => $reason], ['reason' => 'required|string|max:2000'])->validate();
        }

        return DB::transaction(function () use ($actor, $person, $data, $position, $supersedesId, $reason) {
            Person::lockForUpdate()->findOrFail($person->id);
            if ($position) {
                if ($data['position_type'] === 'JF') {
                    $mapping = FunctionalPositionLevel::where('functional_position_id', $data['functional_position_id'] ?? 0)->where('functional_level_id', $data['functional_level_id'] ?? 0)->sharedLock()->first();
                    $masters = $mapping ? app(SharedMasterLocks::class)->acquire(mappingIds: [$mapping->id]) : [];
                    $level = $mapping ? $masters[FunctionalLevel::class][$mapping->functional_level_id] : null;
                    if (! $mapping || ! $mapping->is_active || ! $masters[FunctionalPosition::class][$mapping->functional_position_id]->is_active
                        || ! $level->is_active || ! $masters[FunctionalCategory::class][$level->functional_category_id]->is_active) {
                        throw ValidationException::withMessages(['functional_level_id' => 'Pilih pasangan JF/jenjang aktif yang terverifikasi.']);
                    }
                } elseif (! empty($data['functional_position_id']) || ! empty($data['functional_level_id'])) {
                    throw ValidationException::withMessages(['functional_level_id' => 'Jabatan non-JF tidak memiliki mapping jenjang.']);
                }
            }
            $class = $position ? EmployeePositionHistory::class : EmployeeRankHistory::class;
            // One current read, ordered before validation; no later master acquisition.
            $histories = $class::where('person_id', $person->id)->whereNull('superseded_at')->orderBy('id')->lockForUpdate()->get();
            $old = $supersedesId ? $histories->find($supersedesId) : null;
            if ($supersedesId && ! $old) {
                throw (new ModelNotFoundException)->setModel($class, [$supersedesId]);
            }
            $overlap = $histories->contains(fn ($row) => $row->id !== $old?->id
                && $row->tmt_start->format('Y-m-d') <= ($data['tmt_end'] ?? '9999-12-31')
                && ($row->tmt_end === null || $row->tmt_end->format('Y-m-d') >= $data['tmt_start']));
            if ($overlap) {
                throw ValidationException::withMessages(['tmt_start' => 'Rentang histori utama bertumpang tindih. Koreksi tanggal akhir histori sebelumnya secara eksplisit.']);
            }
            $history = $class::create($data + ['person_id' => $person->id, 'source' => 'MANUAL_VERIFIED', 'verified_by' => $actor->id, 'verified_at' => now(), 'created_by' => $actor->id, 'supersedes_id' => $old?->id]);
            $old?->update(['superseded_at' => now()]);
            app(PakAuditService::class)->record($actor, $history, $old ? 'HISTORY_CORRECTED' : 'HISTORY_VERIFIED', ['after' => $history->getAttributes(), 'supersedes_id' => $old?->id, 'reason' => $reason]);

            return $history;
        });
    }
}
