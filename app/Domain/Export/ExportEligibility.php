<?php

namespace App\Domain\Export;

use App\Domain\Dashboard\DashboardQuery;
use App\Models\ReportingPeriod;

class ExportEligibility
{
    public function reason(?ReportingPeriod $period, string $source, array $filters = []): ?string
    {
        if (! $period) {
            return 'Pilih periode terlebih dahulu.';
        }
        if ($period->status !== 'published') {
            return 'Periode belum dipublikasikan. Ekspor tersedia setelah periode dipublikasikan.';
        }
        if ($source === 'personnel') {
            $query = app(DashboardQuery::class)->personnel($period, $filters);
        } else {
            $query = $period->positions();
            foreach (['position_type', 'position_class', 'requirement_status'] as $field) {
                if (isset($filters[$field]) && $filters[$field] !== '') {
                    $query->where($field, $filters[$field]);
                }
            }
        }
        return $query->exists() ? null : 'Tidak ada data sesuai sumber dan filter yang dipilih. Ubah pilihan sebelum mengunduh.';
    }
}
