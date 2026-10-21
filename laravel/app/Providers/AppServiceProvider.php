<?php

namespace App\Providers;

use App\ReportDelivery\CommercialComplianceProvider;
use App\ReportDelivery\UnavailableCommercialComplianceProvider;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Tests\Support\TestingDatabaseServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentLaboratory::class);
        $this->app->bind(CommercialComplianceProvider::class, UnavailableCommercialComplianceProvider::class);

        $testingRequested = $this->app->environment('testing')
            || Env::get('APP_ENV') === 'testing';

        if ($testingRequested) {
            $this->app->register(TestingDatabaseServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('report-verification', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('login', function (Request $request): Limit {
            $email = Str::lower((string) $request->input('email'));

            return Limit::perMinute(5)->by(
                Str::transliterate($email).'|'.$request->ip()
            );
        });
    }
}
