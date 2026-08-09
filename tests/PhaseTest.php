<?php

/**
 * Drives AutoUpdateService through its phases without a panel or a server.
 *
 * This is the part that restarts machines, so the questions worth answering are
 * the destructive ones: does it restart when nothing is out of date, does it
 * restart twice, does it warn before it acts, and does it stop after a failure.
 * Every collaborator is a stub that records what it was asked to do.
 */

require __DIR__ . '/stubs.php';
require __DIR__ . '/../src/Services/AutoUpdateService.php';

use App\Models\Backup;
use App\Models\Server;
use WildBrianNL\PZModManager\Services\AutoUpdateService;
use WildBrianNL\PZModManager\Services\GameBuild;
use WildBrianNL\PZModManager\Services\IniService;
use WildBrianNL\PZModManager\Services\LogInspector;
use WildBrianNL\PZModManager\Services\ModScanner;
use WildBrianNL\PZModManager\Services\PowerService;
use WildBrianNL\PZModManager\Services\StateStore;
use WildBrianNL\PZModManager\Services\SteamClient;

$fail = 0;

function ok(bool $cond, string $label, $got = null): void
{
    global $fail;
    echo ($cond ? '  PASS ' : '  FAIL ') . $label;
    if (!$cond) {
        echo '  <- kreeg ' . var_export($got, true);
        $fail++;
    }
    echo "\n";
}

/**
 * A service wired to stubs, with the side-car preloaded and the world described
 * by `$world`. Returns the service and the store, so a test can tick and then
 * inspect what was written.
 */
function service(array $auto = [], array $run = [], array $world = []): array
{
    IniService::$mods = $world['mods'] ?? ['ModA'];
    ModScanner::$installed = $world['installed'] ?? [['mod_id' => 'ModA', 'workshop_id' => '111', 'installed_at' => 1000]];
    SteamClient::$details = $world['steam'] ?? ['111' => ['updated' => 1000, 'title' => 'Mod A']];
    LogInspector::$players = array_key_exists('players', $world) ? $world['players'] : 0;
    LogInspector::$started = $world['started'] ?? true;
    GameBuild::$result = $world['build'] ?? ['outdated' => false, 'installed' => 1, 'latest' => 1];

    $store = new MemoryStore(array_merge(StateStore::AUTO_DEFAULTS, $auto), $run);

    $svc = new AutoUpdateService(
        new IniService(),
        new ModScanner(),
        new SteamClient(),
        new LogInspector(),
        $store,
        new GameBuild(),
        new PowerService(),
    );

    return [$svc, $store];
}

// --------------------------------------------------------------- no updates

[$svc, $store] = service(['enabled' => true]);
$svc->tickServer(new Server());
ok(($store->state['run']['phase'] ?? 'idle') === 'idle', 'niets verouderd: blijft in rust');
ok(PowerService::$sent === [], 'niets verouderd: geen herstart', PowerService::$sent);

// ------------------------------------------------------------------- off

PowerService::reset();
[$svc, $store] = service(['enabled' => false], [], ['steam' => ['111' => ['updated' => 999_999, 'title' => 'Mod A']]]);
$svc->tickServer(new Server());
ok(PowerService::$sent === [], 'uitgeschakeld: herstart niet, ook al is er een update', PowerService::$sent);
ok(($store->state['run'] ?? []) === [], 'uitgeschakeld: raakt de toestand niet aan');

// ------------------------------------------- update found, players online

PowerService::reset();
Server::reset();
[$svc, $store] = service(
    ['enabled' => true, 'warn_minutes' => 5],
    [],
    ['players' => 3, 'steam' => ['111' => ['updated' => 999_999, 'title' => 'Mod A']]]
);
$svc->tickServer(new Server());
$run = $store->state['run'];
ok($run['phase'] === 'warning', 'update met spelers: gaat waarschuwen', $run['phase']);
ok(PowerService::$sent === [], 'update met spelers: herstart nog niet', PowerService::$sent);
ok($run['restart_at'] - $run['started_at'] === 300, 'herstart staat 5 minuten vooruit', $run['restart_at'] - $run['started_at']);
$msgs = implode(' | ', Server::$sent);
ok(str_contains($msgs, 'servermsg'), 'waarschuwt via servermsg, niet via say', $msgs);
ok(!str_contains($msgs, 'say '), 'stuurt geen niet-bestaand say-commando', $msgs);
ok(str_contains($msgs, 'save'), 'slaat de wereld op voor de back-up', $msgs);

// -------------------------------------------- update found, nobody online

PowerService::reset();
Server::reset();
[$svc, $store] = service(
    ['enabled' => true, 'warn_minutes' => 5],
    [],
    ['players' => 0, 'steam' => ['111' => ['updated' => 999_999, 'title' => 'Mod A']]]
);
$svc->tickServer(new Server());
$run = $store->state['run'];
ok($run['restart_at'] === $run['started_at'], 'lege server: geen wachttijd', $run['restart_at'] - $run['started_at']);

// -------------------------------------------------------------- countdown

PowerService::reset();
Server::reset();
$now = time();
[$svc, $store] = service(
    ['enabled' => true, 'countdown_seconds' => 2, 'backup' => false],
    ['phase' => 'warning', 'reason' => 'mod', 'restart_at' => $now, 'started_at' => $now - 300, 'announced' => [5]],
    ['steam' => ['111' => ['updated' => 999_999, 'title' => 'Mod A']]]
);
$svc->tickServer(new Server());
ok(PowerService::$sent === ['restart'], 'tijd om: herstart precies een keer', PowerService::$sent);
ok(substr_count(implode(' ', Server::$sent), 'Restarting in') === 2, 'telt af zoals ingesteld', Server::$sent);
ok($store->state['run']['phase'] === 'verifying', 'na herstart: gaat verifieren', $store->state['run']['phase']);

// ------------------------------------------------------- backup not ready

PowerService::reset();
$now = time();
[$svc, $store] = service(
    ['enabled' => true, 'backup' => true, 'backup_wait_seconds' => 120],
    ['phase' => 'warning', 'reason' => 'mod', 'restart_at' => $now, 'started_at' => $now - 30,
     'announced' => [5], 'backup_id' => Backup::RUNNING],
);
$svc->tickServer(new Server());
ok(PowerService::$sent === [], 'back-up nog bezig: wacht met herstarten', PowerService::$sent);
ok($store->state['run']['phase'] === 'warning', 'back-up nog bezig: blijft in waarschuwfase');

// De wachttijd is begrensd, anders houdt een trage back-up de herstart tegen.
PowerService::reset();
[$svc, $store] = service(
    ['enabled' => true, 'backup' => true, 'backup_wait_seconds' => 120, 'countdown_seconds' => 0],
    ['phase' => 'warning', 'reason' => 'mod', 'restart_at' => $now, 'started_at' => $now - 300,
     'announced' => [5], 'backup_id' => Backup::RUNNING],
);
$svc->tickServer(new Server());
ok(PowerService::$sent === ['restart'], 'wachttijd verstreken: herstart toch', PowerService::$sent);

// ----------------------------------------------------- verification passes

PowerService::reset();
Server::reset();
$now = time();
[$svc, $store] = service(
    ['enabled' => true],
    ['phase' => 'verifying', 'reason' => 'mod', 'verify_after' => $now - 10, 'verify_before' => $now + 600, 'last_restart_at' => $now - 600],
);
$svc->tickServer(new Server());
ok($store->state['run']['phase'] === 'idle', 'update binnen: terug naar rust', $store->state['run']['phase']);
ok($store->state['auto']['enabled'] === true, 'update binnen: blijft aan staan');
ok(PowerService::$sent === [], 'update binnen: herstart niet nog eens', PowerService::$sent);

// ------------------------------------------------------ verification fails

PowerService::reset();
[$svc, $store] = service(
    ['enabled' => true],
    ['phase' => 'verifying', 'reason' => 'mod', 'verify_after' => $now - 10, 'verify_before' => $now + 600, 'last_restart_at' => $now - 600],
    ['steam' => ['111' => ['updated' => 999_999, 'title' => 'Mod A']]]
);
$svc->tickServer(new Server());
ok($store->state['run']['phase'] === 'failed', 'update niet binnen: stopt', $store->state['run']['phase']);
ok($store->state['auto']['enabled'] === false, 'update niet binnen: zet zichzelf uit', $store->state['auto']['enabled']);
ok(PowerService::$sent === [], 'update niet binnen: herstart NIET opnieuw', PowerService::$sent);

// Een gestopte toestand blijft gestopt, ook als er updates blijven binnenkomen.
PowerService::reset();
[$svc, $store] = service(
    ['enabled' => true],
    ['phase' => 'failed', 'note' => 'eerder mislukt'],
    ['steam' => ['111' => ['updated' => 999_999, 'title' => 'Mod A']]]
);
$svc->tickServer(new Server());
ok(PowerService::$sent === [], 'gestopt blijft gestopt: geen herstart-lus', PowerService::$sent);

// ---------------------------------------------------------------- cooldown

PowerService::reset();
[$svc, $store] = service(
    ['enabled' => true, 'cooldown_minutes' => 60],
    ['phase' => 'idle', 'last_restart_at' => time() - 60],
    ['steam' => ['111' => ['updated' => 999_999, 'title' => 'Mod A']]]
);
$svc->tickServer(new Server());
ok(PowerService::$sent === [], 'binnen afkoeltijd: herstart niet', PowerService::$sent);
ok(($store->state['run']['phase'] ?? 'idle') !== 'warning', 'binnen afkoeltijd: waarschuwt niet');

// ------------------------------------------------- game update on its own

PowerService::reset();
[$svc, $store] = service(
    ['enabled' => true, 'check_game' => true],
    [],
    ['players' => 0, 'build' => ['outdated' => true, 'installed' => 1, 'latest' => 2]]
);
$svc->tickServer(new Server());
ok(($store->state['run']['reason'] ?? null) === 'game', 'game-update wordt opgepikt', $store->state['run']['reason'] ?? null);

// Een onbereikbare build-API mag nooit een herstart uitlokken.
PowerService::reset();
[$svc, $store] = service(
    ['enabled' => true, 'check_game' => true],
    [],
    ['build' => ['outdated' => false, 'installed' => 1, 'latest' => null]]
);
$svc->tickServer(new Server());
ok(($store->state['run']['phase'] ?? 'idle') === 'idle', 'build-API onbereikbaar: doet niets');

// --------------------------------------------------------- Steam-verkeer

// One check must cost one Steam request, not one per mod: the API takes fifty
// ids per call and the scheduler runs all day.
SteamClient::$calls = 0;
PowerService::reset();
$many = [];
$steam = [];
for ($i = 0; $i < 40; $i++) {
    $many[] = ['mod_id' => "Mod$i", 'workshop_id' => (string) (1000 + $i), 'installed_at' => 1000];
    $steam[(string) (1000 + $i)] = ['updated' => 1000, 'title' => "Mod $i"];
}
[$svc, $store] = service(
    ['enabled' => true],
    [],
    ['mods' => array_column($many, 'mod_id'), 'installed' => $many, 'steam' => $steam]
);
$svc->tickServer(new Server());
ok(SteamClient::$calls === 1, '40 mods kosten een Steam-aanroep, niet 40', SteamClient::$calls);

// Nothing outdated means no restart either, however many mods there are.
ok(PowerService::$sent === [], '40 mods, niets verouderd: geen herstart', PowerService::$sent);

echo $fail ? "\nRESULT: $fail gefaald\n" : "\nRESULT: alles ok\n";
exit($fail ? 1 : 0);
