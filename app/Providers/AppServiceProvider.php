<?php

namespace App\Providers;

use App\Models\Actualite;
use App\Models\Contribution;
use App\Policies\ActualitePolicy;
use App\Policies\ContributionPolicy;
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
        Gate::policy(Contribution::class, ContributionPolicy::class);
        Gate::policy(Actualite::class, ActualitePolicy::class);
    }
}
