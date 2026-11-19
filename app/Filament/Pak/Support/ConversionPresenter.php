<?php

namespace App\Filament\Pak\Support;

use App\Domain\Pak\Authorization\PakEmployeeAuthorization as Access;
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
