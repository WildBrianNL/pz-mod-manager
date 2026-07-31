<?php

namespace WildBrianNL\PZModManager;

use Filament\Contracts\Plugin;
use Filament\Panel;
use WildBrianNL\PZModManager\Filament\Server\Pages\ManageMods;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\View;

class PZModManagerPlugin implements Plugin
{
    public function getId(): string
    {
        return 'pz-mod-manager';
    }

    public function register(Panel $panel): void
    {
        $root = plugin_path($this->getId());

        View::addNamespace('pz-mod-manager', $root . '/resources/views');
        Lang::addNamespace('pzmm', $root . '/lang');

        $panel->pages([
            ManageMods::class,
        ]);
    }

    public function boot(Panel $panel): void {}
}
