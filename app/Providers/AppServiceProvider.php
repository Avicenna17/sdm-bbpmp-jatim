<?php

namespace App\Providers;

use App\Models\EmployeePositionHistory;
use App\Models\EmployeeProfile;
use App\Models\EmployeeRankHistory;
use App\Models\FunctionalCategory;
use App\Models\FunctionalLevel;
use App\Models\FunctionalPosition;
use App\Models\FunctionalPositionLevel;
use App\Models\PakCase;
use App\Models\PakCaseEmployeeSnapshot;
use App\Models\PakEvent;
use App\Models\PakRuleSet;
use App\Policies\Pak\EmployeePositionHistoryPolicy;
use App\Policies\Pak\EmployeeProfilePolicy;
use App\Policies\Pak\EmployeeRankHistoryPolicy;
use App\Policies\Pak\FunctionalCategoryPolicy;
use App\Policies\Pak\FunctionalLevelPolicy;
use App\Policies\Pak\FunctionalPositionLevelPolicy;
use App\Policies\Pak\FunctionalPositionPolicy;
use App\Policies\Pak\PakCaseEmployeeSnapshotPolicy;
use App\Policies\Pak\PakCasePolicy;
use App\Policies\Pak\PakEventPolicy;
use App\Policies\Pak\PakRuleSetPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(EmployeeProfile::class, EmployeeProfilePolicy::class);
        Gate::policy(EmployeeRankHistory::class, EmployeeRankHistoryPolicy::class);
        Gate::policy(EmployeePositionHistory::class, EmployeePositionHistoryPolicy::class);
        Gate::policy(FunctionalCategory::class, FunctionalCategoryPolicy::class);
        Gate::policy(FunctionalLevel::class, FunctionalLevelPolicy::class);
        Gate::policy(FunctionalPosition::class, FunctionalPositionPolicy::class);
        Gate::policy(FunctionalPositionLevel::class, FunctionalPositionLevelPolicy::class);
        Gate::policy(PakRuleSet::class, PakRuleSetPolicy::class);
        Gate::policy(PakEvent::class, PakEventPolicy::class);
        Gate::policy(PakCaseEmployeeSnapshot::class, PakCaseEmployeeSnapshotPolicy::class);
        Gate::policy(PakCase::class, PakCasePolicy::class);
    }
}
