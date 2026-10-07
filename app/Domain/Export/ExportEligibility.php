<?php

namespace App\Domain\Export;

use App\Domain\Dashboard\DashboardQuery;
use App\Models\PositionDataset;
use App\Models\ReportingPeriod;

class ExportEligibility
{
    public function reason(?ReportingPeriod $period, string $source, array $filters = []): ?string
    {
        if ($source === 'personnel' && ! $period) {
            return 'Pilih periode terlebih dahulu.';
        }
        if ($source === 'personnel' && $period->status !== 'published') {
            return 'Periode belum dipublikasikan. Ekspor tersedia setelah periode dipublikasikan.';
        }
        if ($source === 'personnel') {
            $query = app(DashboardQuery::class)->personnel($period, $filters);
        } else {
            $query = PositionDataset::exportSnapshots(isset($filters['position_version_id']) ? (int) $filters['position_version_id'] : null);
            foreach (['position_type', 'position_class', 'requirement_status'] as $field) {
                if (isset($filters[$field]) && $filters[$field] !== '') {
                    $query->where($field, $filters[$field]);
                }
            }
        }

        return $query->exists() ? null : 'Tidak ada data sesuai sumber dan filter yang dipilih. Ubah pilihan sebelum mengunduh.';
    }
}
