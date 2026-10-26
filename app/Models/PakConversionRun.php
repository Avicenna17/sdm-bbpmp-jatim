<?php

namespace App\Models;

use App\Domain\Pak\Enums\ConversionRunStatus;
use App\Domain\Pak\Rules\ConversionPersistenceValues as Values;
use App\Domain\Pak\Services\ConversionRoundingPolicy;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PakConversionRun extends PakModel
{
    public function setAttribute($key, $value)
    {
        if ($key === 'status' && ! ($value instanceof ConversionRunStatus) && (! is_string($value) || ConversionRunStatus::tryFrom($value) === null)) {
            Values::reject('status');
        }
        if (in_array($key, ['requested_start', 'requested_end'], true)) {
            if (! is_string($value)) {
                Values::reject($key);
            }
            Values::date($value, $key);
            $this->attributes[$key] = $value;

            return $this;
        }
        if (in_array($key, ['dependency_manifest', 'original_issues', 'calculation_trace', 'review_assessment'], true)) {
            $this->attributes[$key] = Values::json($value, $key, $key === 'review_assessment');

            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    protected function casts(): array
    {
        return ['status' => ConversionRunStatus::class, 'requested_start' => 'date', 'requested_end' => 'date', 'dependency_manifest' => 'array', 'original_issues' => 'array', 'calculation_trace' => 'array', 'review_assessment' => 'array', 'review_assessed_at' => 'datetime', 'superseded_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        parent::booted();
        static::saving(function (self $run) {
            // Lifecycle services arrive in 02C.5/6; generic writes cannot rewrite a saved snapshot.
            if ($run->exists && $run->isDirty()) {
                Values::reject('snapshot');
            }
            $a = $run->getAttributes();
            Validator::make($a, [
                'status' => ['required', Rule::in(array_column(ConversionRunStatus::cases(), 'value'))],
                'revision_number' => ['required', 'integer', 'min:1'],
                'case_lock_version' => ['required', 'integer', 'min:1'],
                'snapshot_schema_version' => ['required', 'integer', Rule::in([Values::SNAPSHOT_SCHEMA_VERSION])],
                'calculation_contract_version' => ['required', 'integer', Rule::in([Values::CALCULATION_CONTRACT_VERSION])],
                'rounding_profile' => ['required', Rule::in([ConversionRoundingPolicy::PROFILE])],
                'rounding_version' => ['required', Rule::in([ConversionRoundingPolicy::VERSION])],
                'dependency_fingerprint' => ['required', 'regex:/\\A[0-9a-f]{64}\\z/'],
                'snapshot_fingerprint' => ['required', 'regex:/\\A[0-9a-f]{64}\\z/'],
            ])->validate();
            $start = Values::date($a['requested_start'] ?? '', 'requested_start');
            $end = Values::date($a['requested_end'] ?? '', 'requested_end');
            if ($start > $end) {
                Values::reject('requested_end');
            }
            $case = PakCase::find($run->pak_case_id);
            if (! $case || (string) $case->person_id !== (string) $run->person_id || (string) $case->pak_rule_set_id !== (string) $run->pak_rule_set_id) {
                Values::reject('pak_case_id');
            }
            if ($start->format('Y-m-d') < $case->period_start->format('Y-m-d') || $end->format('Y-m-d') > $case->period_end->format('Y-m-d')) {
                Values::reject('requested_start');
            }
            $superseded = $run->status === ConversionRunStatus::SUPERSEDED;
            if ($superseded ? $run->active_case_id !== null : (string) $run->active_case_id !== (string) $run->pak_case_id) {
                Values::reject('active_case_id');
            }
            if ($run->predecessor_run_id !== null) {
                $previous = self::find($run->predecessor_run_id);
                if (! $previous || $previous->id === $run->id || (string) $previous->pak_case_id !== (string) $run->pak_case_id || (int) $run->revision_number !== (int) $previous->revision_number + 1) {
                    Values::reject('predecessor_run_id');
                }
                if (! is_string($run->revision_reason) || trim($run->revision_reason) === '') {
                    Values::reject('revision_reason');
                }
            } elseif ((int) $run->revision_number !== 1) {
                Values::reject('revision_number');
            }
            foreach (['dependency_manifest', 'original_issues', 'calculation_trace'] as $field) {
                Values::json($run->$field, $field);
            }
            foreach (['case_context', 'coverage', 'source_candidates', 'selected_sources', 'position_assignments', 'history_dependencies', 'rule_set', 'versions'] as $group) {
                if (! array_key_exists($group, $run->dependency_manifest) || ! is_array($run->dependency_manifest[$group])) {
                    Values::reject('dependency_manifest');
                }
            }
            Values::json($run->review_assessment, 'review_assessment', true);
            Values::rational($a['total_numerator'] ?? null, $a['total_denominator'] ?? null, 'total');
            if ($run->total_numerator === null && $run->original_issues === []) {
                Values::reject('original_issues');
            }
            if ($run->status === ConversionRunStatus::PREVIEW && ($run->total_numerator === null || $run->original_issues !== [])) {
                Values::reject('total');
            }
            if (($start->format('d') !== '01' || $end->format('d') !== $end->format('t')) && $run->total_numerator !== null) {
                Values::reject('total');
            }
        });
    }

    public function pakCase()
    {
        return $this->belongsTo(PakCase::class, 'pak_case_id');
    }

    public function person()
    {
        return $this->belongsTo(Person::class, 'person_id');
    }

    public function ruleSet()
    {
        return $this->belongsTo(PakRuleSet::class, 'pak_rule_set_id');
    }

    public function predecessor()
    {
        return $this->belongsTo(PakConversionRun::class, 'predecessor_run_id');
    }

    public function reviewAssessedBy()
    {
        return $this->belongsTo(User::class, 'review_assessed_by');
    }

    public function supersededByUser()
    {
        return $this->belongsTo(User::class, 'superseded_by_user_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function successor()
    {
        return $this->hasOne(self::class, 'predecessor_run_id');
    }

    public function periods()
    {
        return $this->hasMany(PakConversionPeriod::class)->orderBy('line_number');
    }
}
