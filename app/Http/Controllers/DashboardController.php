<?php

namespace App\Http\Controllers;

use App\Domain\Dashboard\DashboardQuery;
use App\Models\ReportingPeriod;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardQuery $query)
    {
        $filters = $request->validate(array_merge(['period' => 'nullable|date_format:Y-m', 'employment_group' => 'nullable|in:ASN,PPNPN', 'position_search' => 'nullable|string|max:255', 'position_type' => 'nullable|string|max:255'], array_fill_keys(array_keys(DashboardQuery::FILTERS), 'nullable|string|max:255')));
        $filters['employment_group'] = $filters['employment_group'] ?? 'ASN';
        $periods = ReportingPeriod::where('status', 'published')->orderByDesc('period_month')->get(['id', 'period_month', 'published_at', 'status']);
        $period = isset($filters['period']) ? $periods->first(fn ($p) => $p->period_month->format('Y-m') === $filters['period']) : $periods->first();
        if (isset($filters['period']) && ! $period) {
            abort(404);
        }
        $data = $period ? $query->get($period, $filters) : null;

        return response()->view('dashboard', compact('periods', 'period', 'data', 'filters'))->header('Cache-Control', 'no-store');
    }
}
