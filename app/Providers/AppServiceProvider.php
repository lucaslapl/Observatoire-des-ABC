<?php

namespace App\Providers;

use App\Models\Actualite;
use App\Models\Contribution;
use App\Policies\ActualitePolicy;
use App\Policies\ContributionPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

        // Anti brute-force sur /login : 5 essais/min par couple email+IP,
        // 20 essais/min par IP (distribué sur plusieurs emails).
        RateLimiter::for('login', fn ($request) => [
            Limit::perMinute(5)->by($request->input('email').'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        // Inscription ouverte : 5 comptes/h par couple email+IP, 10/h par IP.
        RateLimiter::for('register', fn ($request) => [
            Limit::perHour(5)->by($request->input('email').'|'.$request->ip()),
            Limit::perHour(10)->by($request->ip()),
        ]);

        // Réinitialisation de mot de passe : 3 demandes/h par email+IP.
        RateLimiter::for('password-reset', fn ($request) => [
            Limit::perHour(3)->by($request->input('email').'|'.$request->ip()),
        ]);
    }
}
