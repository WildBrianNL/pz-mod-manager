<?php

return [
    // Steam application id for Project Zomboid. Workshop content lives under
    // steamapps/workshop/content/<app_id>/ inside the server volume.
    'app_id' => '108600',

    // Build used to pick version folders (mods/<mod>/42.14/) when the running
    // server has not reported its own version yet.
    'fallback_build' => 42,

    // Only show the Mods page for eggs whose name contains this.
    'egg_match' => 'zomboid',

    'auto_update' => [
        // How often to check Workshop updates for enabled mods.
        'check_interval_minutes' => 1,
        // Maximum age of cached Steam metadata used by auto-update checks.
        'max_steam_meta_age_seconds' => 60,
    ],

    'cache' => [
        // Parsed mod index; invalidated automatically when files change.
        'index_days' => 7,
        // Steam metadata (titles, tags, thumbnails).
        'steam_hours' => 12,
        // Workshop changelogs.
        'changelog_hours' => 6,
        // Parsed server log (loaded mods, errors).
        'log_seconds' => 45,
    ],
];
