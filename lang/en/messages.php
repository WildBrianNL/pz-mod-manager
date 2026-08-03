<?php

return [
    'title' => 'Mods',
    'search' => 'Search mods…',
    'add_placeholder' => 'Steam Workshop ID, item URL or collection URL',
    'auto_activate' => 'auto-enable',
    'auto_activate_hint' => 'Try to enable the mod immediately so a single restart is enough. Verified and corrected automatically after download.',

    'stat' => [
        'active' => 'Enabled',
        'available' => 'Available',
        'restart' => 'Needs restart',
        'errors' => 'Mod errors',
    ],

    'section' => [
        'active' => 'Enabled',
        'active_hint' => '— order = load order',
        'available' => 'Available',
        'available_hint' => '— on the server, not enabled',
    ],

    'status' => [
        'active' => 'running',
        'restart' => 'restart to apply',
        'downloading' => 'downloads on restart',
        'failed' => 'server could not load it',
        'orphan' => 'not found',
    ],

    'badge' => [
        'build_mismatch' => 'build mismatch',
        'errors' => '{1} :count error|[2,*] :count errors',
        'update' => 'update available',
        'version' => 'v:version',
        'installed' => 'installed :date',
        'updated' => 'Steam :date',
    ],

    'tooltip' => [
        'bundled' => 'bundled — same Workshop item as :title, deleting removes both',
        'disable' => 'Disable — clients stop downloading it, files stay on the server',
        'delete' => 'Delete — removes the mod and its files from the server',
        'changelog' => 'Show changelog',
    ],

    'changelog' => [
        'title' => 'Changelog — :mod',
        'empty' => 'Steam returned no changelog (it rate-limits this page). Open it on Steam instead.',
        'open_steam' => 'View on Steam',
        'close' => 'Close',
    ],

    'category' => [
        'framework' => 'Framework',
        'interface' => 'Interface',
        'vehicles' => 'Vehicles',
        'building' => 'Building',
        'items' => 'Items',
        'weapons' => 'Weapons',
        'balance' => 'Balance',
        'map' => 'Maps',
        'multiplayer' => 'Multiplayer',
        'other' => 'Other',
    ],

    'action' => [
        'lock' => 'Lock to this position (survives auto-sort)',
        'unlock' => 'Unlock',
        'add' => 'Add',
        'sort' => 'Auto-sort',
        'refresh' => 'Refresh',
        'restart' => 'Restart',
        'restart_now' => 'Restart now',
        'enable' => 'Enable',
        'disable' => 'Disable',
        'delete' => 'Delete',
        'changelog' => 'Changelog',
        'enable_dep' => 'Enable it',
        'clean_up' => 'Clean up',
        'add_maps' => 'Add to Map=',
    ],

    'alert' => [
        'crash' => 'The last start failed: :reason. The server will not load the world until this is fixed.',
        'missing_dep' => '":dep" is required by :mods but is not enabled.',
        'missing_dep_uninstalled' => '":dep" is required by :mods but is not installed on this server — add its Workshop item, otherwise the mod will never load.',
        'orphans' => 'Enabled but not installed: :mods. These entries do nothing.',
        'awaiting_download' => '{1} :count mod has not been downloaded yet — restart the server.|[2,*] :count mods have not been downloaded yet — restart the server.',
        'needs_restart' => '{1} :count mod is enabled but not loaded yet.|[2,*] :count mods are enabled but not loaded yet.',
        'build_mismatch' => 'These mods do not list build :build support: :mods.',
        'maps_missing' => 'Map mods found that are not in Map=: :maps.',
        'mod_errors' => 'These mods reported errors during the last start: :mods.',
        'order_changed' => 'The load order has changed but is not active yet — restart the server to apply it.',
        'duplicates' => 'Duplicate entries were removed automatically: :mods.',
        'updates' => '{1} :count mod has a newer version on Steam — restart the server to update it.|[2,*] :count mods have a newer version on Steam — restart the server to update them.',
    ],

    'confirm' => [
        'restart' => 'Restart the server now? Players who are online will be disconnected.',
        'disable' => 'Disable this mod? Clients will stop downloading it. The files stay on the server so you can switch it back on.',
        'delete' => 'Delete this mod and its files from the server? This cannot be undone.',
    ],

    'empty' => [
        'active' => 'No mods enabled yet — enable one below.',
        'available' => 'Nothing on the server yet. Add a mod and restart to download it.',
    ],

    'error' => [
        'config_not_found' => 'No server config found. Start the server once so it creates its .ini file.',
        'config_unreadable' => 'The server config could not be read. Nothing was changed.',
    ],

    'notify' => [
        'locked' => 'Locked at position :position. Auto-sort will keep it there.',
        'unlocked' => 'Unlocked.',
        'order_stale' => 'Load order was out of date, reloaded. Please try again.',
        'added' => '{1} :count mod queued for download|[2,*] :count mods queued for download',
        'added_body' => 'Restart the server to download. They appear under "Available" afterwards.',
        'already_added' => 'That workshop item was already added.',
        'invalid_id' => 'No valid Workshop ID found in that input.',
        'removed' => 'Removed and files deleted.',
        'disabled' => 'Disabled — clients will no longer download it.',
        'files_kept' => 'Removed from the config, but the files could not be deleted.',
        'refreshed' => 'Refreshed.',
        'sorted' => 'Sorted — frameworks and dependencies first.',
        'cleaned' => 'Cleaned up.',
        'maps_added' => 'Maps added to the config.',
        'restarting' => 'Restart requested.',
        'restart_failed' => 'Restart failed',
        'config_error' => 'The server config could not be read — nothing was changed.',
        'auto_activated' => 'Enabled automatically after download',
        'repaired_workshop' => 'Repaired: missing Workshop IDs added so clients can download these mods',
    ],
];
