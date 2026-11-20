<?php

namespace App\Filament\Pak\Support;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
use App\Domain\Pak\DTOs\ConversionAssessment;
use App\Domain\Pak\DTOs\ConversionPreview;
use App\Domain\Pak\DTOs\ConversionPreviewLine;
use App\Domain\Pak\Queries\ConversionRunQuery;
use App\Domain\Pak\Services\ConversionRoundingPolicy;
use App\Models\PakConversionRun;
use App\Models\User;
use Brick\Math\BigRational;
use Closure;

final class ConversionPresenter
{
    public function present(User $actor, PakConversionRun $run, Closure $url): array
    {
        abort_unless(ConversionRunQuery::allows($actor), 403);
        $source = Access::allows($actor, 'pak.skp.view');
        $position = Access::allows($actor, 'pak.employee.view');
        $rule = Access::allows($actor, 'pak.rule-set.view');
        $chain = [];
        foreach (['predecessor' => 'Revisi sebelumnya', 'successor' => 'Revisi berikutnya'] as $relation => $label) {
            $related = $run->getRelation($relation);
            if ($related !== null && (int) $related->pak_case_id === (int) $run->pak_case_id) {
                $chain[] = ['label' => $label.' · '.$related->revision_number, 'url' => $url($related->id)];
            }
        }

        return [
            'summary' => ['ID run' => $run->id, 'Revisi' => $run->revision_number, 'Status perhitungan / pemeriksaan' => $run->status->value,
                'Awal permintaan' => $run->requested_start->format('Y-m-d'), 'Akhir permintaan' => $run->requested_end->format('Y-m-d'),
                'Versi case saat disimpan' => $run->case_lock_version, 'Alasan revisi' => $run->revision_reason ?? '—', 'Disimpan pada' => $run->created_at?->format('Y-m-d H:i:s') ?? '—'],
            'total' => $this->amount($run->total_numerator, $run->total_denominator),
            'issues' => $this->issues($run->original_issues),
            'review' => $this->reviewProjection($run),
            'rule' => $rule ? $this->fields($run->dependency_manifest['rule_set'] ?? [], ['id' => 'ID rule set', 'code' => 'Kode aturan', 'version' => 'Versi aturan']) : [],
            'chain' => $chain,
            'periods' => $run->periods->map(fn ($line) => [
                'summary' => ['Baris' => $line->line_number, 'Awal' => $line->period_start->format('Y-m-d'), 'Akhir' => $line->period_end->format('Y-m-d'),
                    'Jumlah bulan' => $line->month_count, 'Faktor tersimpan' => $line->predicate_factor ?? 'Tidak tersedia', 'Koefisien tersimpan' => $line->annual_coefficient ?? 'Tidak tersedia'],
                'amount' => $this->amount($line->result_numerator, $line->result_denominator),
                'issues' => $this->issues($line->original_issues),
                'provenance' => [
                    'Sumber SKP tersimpan' => $source ? $this->fields($line->source_snapshot ?? [], ['evaluation_id' => 'ID evaluasi', 'evaluation_type' => 'Jenis', 'performance_year' => 'Tahun', 'predicate_code' => 'Predikat', 'coverage_start' => 'Cakupan formal mulai', 'coverage_end' => 'Cakupan formal akhir', 'selection_reason' => 'Alasan pemilihan', 'source_reference' => 'Referensi sumber', 'source_date' => 'Tanggal sumber', 'history_proof_method' => 'Metode bukti jabatan']) : [],
                    'Posisi tersimpan' => $position ? $this->fields($line->position_snapshot ?? [], ['history_id' => 'ID histori', 'position_name' => 'Jabatan', 'functional_level_name' => 'Jenjang', 'tmt_start' => 'TMT mulai', 'tmt_end' => 'TMT akhir', 'sk_number' => 'Nomor SK', 'sk_date' => 'Tanggal SK', 'source_ref' => 'Referensi histori', 'selection_reason' => 'Bukti kontinuitas']) : [],
                    'Aturan baris tersimpan' => $rule ? array_merge(
                        $this->fields($line->rule_snapshot['predicate'] ?? [], ['ruleSetId' => 'ID rule set predikat', 'childRuleId' => 'ID aturan predikat', 'predicateCode' => 'Kode predikat', 'predicateFactor' => 'Faktor']),
                        $this->fields($line->rule_snapshot['coefficient'] ?? [], ['ruleSetId' => 'ID rule set koefisien', 'childRuleId' => 'ID aturan koefisien', 'annualCoefficient' => 'Koefisien tahunan'])
                    ) : [],
                ],
            ])->all(),
        ];
    }

    public function previewProjection(ConversionPreview $preview, ?string $status): array
    {
        return [
            'start' => $preview->request->requested_start, 'end' => $preview->request->requested_end,
            'status' => $status, 'exact' => $preview->exact_total === null ? 'Tidak tersedia' : (string) $preview->exact_total,
            'human' => $preview->human_total ?? 'Tidak tersedia', 'issues' => $this->issues($preview->issues),
            'lines' => array_map(fn (ConversionPreviewLine $line) => [
                'months' => $line->months, 'exact' => $line->exact === null ? 'Tidak tersedia' : (string) $line->exact,
                'human' => $line->human ?? 'Tidak tersedia', 'issues' => $this->issues($line->issues),
                'source' => $line->source === null ? [] : $this->fields(get_object_vars($line->source), [
                    'evaluation_id' => 'ID evaluasi', 'performance_year' => 'Tahun', 'predicate_code' => 'Predikat',
                    'coverage_start' => 'Cakupan formal mulai', 'coverage_end' => 'Cakupan formal akhir',
                    'source_reference' => 'Referensi sumber', 'source_date' => 'Tanggal sumber', 'selection_reason' => 'Alasan pemilihan',
                ]),
                'position' => $line->position === null ? [] : $this->fields(get_object_vars($line->position), [
                    'history_id' => 'ID histori', 'position_name' => 'Jabatan', 'functional_level_name' => 'Jenjang',
                    'tmt_start' => 'TMT mulai', 'tmt_end' => 'TMT akhir', 'selection_reason' => 'Bukti kontinuitas',
                ]),
                'rule' => array_merge(
                    $line->predicate === null ? [] : $this->fields(get_object_vars($line->predicate), ['ruleSetId' => 'ID rule set', 'childRuleId' => 'ID aturan predikat', 'predicateFactor' => 'Faktor']),
                    $line->coefficient === null ? [] : $this->fields(get_object_vars($line->coefficient), ['childRuleId' => 'ID aturan koefisien', 'annualCoefficient' => 'Koefisien tahunan']),
                ),
            ], $preview->lines),
        ];
    }

    public function assessmentProjection(ConversionAssessment $assessment): array
    {
        return [
            'comparison' => $assessment->comparison,
            'status' => $assessment->observed_run_status,
            'revision' => $assessment->revision_number,
            'active' => $assessment->is_current_active,
            'case_version' => $assessment->observed_case_lock_version,
            'sequence' => $assessment->assessment_sequence,
            'changed_groups' => array_values(array_intersect($assessment->changed_groups, [
                'case_context', 'coverage', 'source_candidates', 'selected_sources',
                'position_assignments', 'history_dependencies', 'rule_set', 'versions',
            ])),
            'issue_codes' => array_values(array_filter($assessment->current_issue_codes, fn ($code) => $this->displayCode($code))),
            'failure_code' => $this->displayCode($assessment->failure_code) ? $assessment->failure_code : null,
        ];
    }

    private function displayCode(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[A-Z][A-Z0-9_]{0,127}\z/', $value) === 1;
    }

    private function reviewProjection(PakConversionRun $run): array
    {
        $metadata = $run->review_assessment;
        $keys = ['schema_version', 'assessment_sequence', 'comparison', 'assessment_fingerprint',
            'current_dependency_fingerprint', 'current_snapshot_fingerprint', 'changed_groups', 'current_issue_codes', 'failure_code', 'reason'];
        // Presentation only: malformed metadata is never repaired or used as lifecycle authority.
        if (! is_array($metadata) || array_diff($keys, array_keys($metadata)) || array_diff(array_keys($metadata), $keys)
            || ($metadata['schema_version'] ?? null) !== 1
            || ! is_int($metadata['assessment_sequence'] ?? null) || $metadata['assessment_sequence'] < 1
            || ! in_array($metadata['comparison'] ?? null, ['UNCHANGED', 'CHANGED', 'UNASSESSABLE'], true)
            || ! is_string($metadata['reason'] ?? null) || trim($metadata['reason']) === ''
            || trim($metadata['reason']) !== $metadata['reason']) {
            return [];
        }
        foreach (['assessment_fingerprint', 'current_dependency_fingerprint', 'current_snapshot_fingerprint'] as $field) {
            if ($field !== 'assessment_fingerprint' && $metadata['comparison'] === 'UNASSESSABLE' && $metadata[$field] === null) {
                continue;
            }
            if (! is_string($metadata[$field]) || preg_match('/\A[0-9a-f]{64}\z/', $metadata[$field]) !== 1) {
                return [];
            }
        }
        foreach (['changed_groups', 'current_issue_codes'] as $field) {
            if (! is_array($metadata[$field]) || ! array_is_list($metadata[$field])) {
                return [];
            }
            foreach ($metadata[$field] as $value) {
                if ($field === 'current_issue_codes' ? ! $this->displayCode($value) : ! in_array($value, [
                    'case_context', 'coverage', 'source_candidates', 'selected_sources',
                    'position_assignments', 'history_dependencies', 'rule_set', 'versions',
                ], true)) {
                    return [];
                }
            }
            if (count(array_unique($metadata[$field])) !== count($metadata[$field])) {
                return [];
            }
        }
        if ($metadata['comparison'] === 'UNASSESSABLE' ? ! $this->displayCode($metadata['failure_code']) : $metadata['failure_code'] !== null) {
            return [];
        }

        return ['Urutan asesmen' => $metadata['assessment_sequence'], 'Hasil asesmen' => $metadata['comparison'],
            'Alasan tinjauan' => $metadata['reason'], 'Tinjauan dicatat pada' => $run->review_assessed_at?->format('Y-m-d H:i:s') ?? 'Tidak tersedia'];
    }

    private function amount(?string $numerator, ?string $denominator): array
    {
        if ($numerator === null || $denominator === null) {
            return ['exact' => 'Tidak tersedia', 'human' => 'Tidak tersedia'];
        }

        return ['exact' => $numerator.'/'.$denominator, 'human' => app(ConversionRoundingPolicy::class)->display(BigRational::of($numerator.'/'.$denominator))];
    }

    private function fields(array $values, array $labels): array
    {
        $result = [];
        foreach ($labels as $key => $label) {
            if (isset($values[$key]) && is_scalar($values[$key])) {
                $result[$label] = (string) $values[$key];
            }
        }

        return $result;
    }

    private function issues(array $issues): array
    {
        return array_map(fn (array $issue) => $this->fields($issue, ['issue_code' => 'Kode', 'month' => 'Bulan', 'interval_start' => 'Awal interval', 'interval_end' => 'Akhir interval']), $issues);
    }
}
