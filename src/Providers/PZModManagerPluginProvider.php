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
            // One tick a minute for every server, rather than a cron expression
            // per interval. How often a given server is actually checked is its
            // own setting, kept beside the server, so two servers on one panel
            // can poll at different rates and a change takes effect on the next
            // minute instead of after a config cache rebuild.
            //
            // withoutOverlapping matters here: a tick can sit through a ten
            // second countdown, and two overlapping ticks would announce and
            // restart twice.
            $this->app->make(Schedule::class)
                ->call(fn () => app(AutoUpdateService::class)->tick())
                ->name('pzmm:auto-update')
                ->everyMinute()
                ->withoutOverlapping();
        });
    }
}
