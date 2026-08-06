<?php

namespace WildBrianNL\PZModManager\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use WildBrianNL\PZModManager\Services\AutoUpdateService;

class PZModManagerPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(plugin_path('pz-mod-manager', 'config/pz-mod-manager.php'), 'pz-mod-manager');
    }

    public function boot(): void
    {
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);

            $schedule->call(fn () => app(AutoUpdateService::class)->runChecks())
                ->name('pzmm:auto-update-check')
                ->everyFiveMinutes()
                ->withoutOverlapping();

            $schedule->call(fn () => app(AutoUpdateService::class)->processPendingRestarts())
                ->name('pzmm:auto-update-pending-restart')
                ->everyMinute()
                ->withoutOverlapping();
        });
    }
}
