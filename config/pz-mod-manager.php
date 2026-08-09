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

    // Auto-restart settings are deliberately NOT here. They are per server and
    // live in the side-car beside the server files, because two servers on one
    // panel need different windows, and a panel-wide config file is the wrong
    // place to decide that one particular game server restarts itself.

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
