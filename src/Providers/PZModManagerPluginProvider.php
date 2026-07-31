<?php

namespace WildBrianNL\PZModManager\Providers;

use Illuminate\Support\ServiceProvider;

class PZModManagerPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(plugin_path('pz-mod-manager', 'config/pz-mod-manager.php'), 'pz-mod-manager');
    }

    public function boot(): void {}
}
