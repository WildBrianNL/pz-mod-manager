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
    ['phase' => 'verifying', 'reason' => 'mod', 'stale_ids' => ['111'],
     'verify_after' => $now - 10, 'verify_before' => $now + 600, 'last_restart_at' => $now - 600],
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

// ------------------------------------------------------------ placeholders

// The settings panel offers :minutes, :seconds and :reason without saying which
// message may use which, so every message has to substitute all three. One that
// is missed does not fail quietly: the literal ":reason" is broadcast to every
// player on the server.
PowerService::reset();
Server::reset();
$now = time();
$all = ['msg_warn' => 'W :minutes :seconds :reason', 'msg_final' => 'F :minutes :seconds :reason',
        'msg_countdown' => 'C :minutes :seconds :reason', 'msg_back' => 'B :minutes :seconds :reason'];

[$svc, $store] = service(
    ['enabled' => true, 'warn_minutes' => 5, 'backup' => false] + $all,
    [],
    ['players' => 2, 'steam' => ['111' => ['updated' => 999_999, 'title' => 'Mod A']]]
);
$svc->tickServer(new Server());

// One minute warning.
[$svc2, $store2] = service(
    ['enabled' => true, 'backup' => false] + $all,
    ['phase' => 'warning', 'reason' => 'mod', 'restart_at' => $now + 45, 'started_at' => $now - 300, 'announced' => [5]],
);
$svc2->tickServer(new Server());

// Countdown and the message after coming back.
[$svc3, $store3] = service(
    ['enabled' => true, 'backup' => false, 'countdown_seconds' => 1] + $all,
    ['phase' => 'warning', 'reason' => 'mod', 'restart_at' => $now, 'started_at' => $now - 300, 'announced' => [5, 1]],
);
$svc3->tickServer(new Server());

[$svc4, $store4] = service(
    ['enabled' => true] + $all,
    ['phase' => 'verifying', 'reason' => 'mod', 'verify_after' => $now - 10, 'verify_before' => $now + 600, 'last_restart_at' => $now - 600],
);
$svc4->tickServer(new Server());

$broadcast = implode(' | ', Server::$sent);
ok(!str_contains($broadcast, ':reason'), 'geen onvervangen :reason naar spelers', $broadcast);
ok(!str_contains($broadcast, ':minutes'), 'geen onvervangen :minutes naar spelers', $broadcast);
ok(!str_contains($broadcast, ':seconds'), 'geen onvervangen :seconds naar spelers', $broadcast);
ok(substr_count($broadcast, 'W 5 300 mod') === 1, 'eerste waarschuwing vult minuten en reden', $broadcast);
ok(substr_count($broadcast, 'F 1 60 mod') === 1, 'minuut-waarschuwing vult ook de reden', $broadcast);
ok(substr_count($broadcast, 'C 0 1 mod') === 1, 'aftelling vult seconden en reden', $broadcast);
ok(substr_count($broadcast, 'B 0 0 mod') === 1, 'bericht na herstart vult de reden', $broadcast);

// ------------------------------------------------- alleen ingeschakelde mods

// A mod on disk but absent from Mods= is not loaded, so it cannot cause a
// version mismatch. Restarting a populated server for a mod nobody is running
// would be the most annoying possible false positive.
PowerService::reset();
[$svc, $store] = service(
    ['enabled' => true],
    [],
    [
        'mods' => ['ModA'],                       // only ModA is enabled
        'installed' => [
            ['mod_id' => 'ModA', 'workshop_id' => '111', 'installed_at' => 1000],
            ['mod_id' => 'ModB', 'workshop_id' => '222', 'installed_at' => 1000],
        ],
        'steam' => [
            '111' => ['updated' => 1000, 'title' => 'Mod A'],
            '222' => ['updated' => 999_999, 'title' => 'Mod B'],  // outdated, disabled
        ],
    ]
);
$svc->tickServer(new Server());
ok(PowerService::$sent === [], 'verouderde uitgeschakelde mod: geen herstart', PowerService::$sent);
ok(($store->state['run']['phase'] ?? 'idle') === 'idle', 'verouderde uitgeschakelde mod: blijft in rust');

// And the enabled one still triggers, so the filter is not simply refusing all.
PowerService::reset();
[$svc, $store] = service(
    ['enabled' => true],
    [],
    [
        'mods' => ['ModA'],
        'installed' => [
            ['mod_id' => 'ModA', 'workshop_id' => '111', 'installed_at' => 1000],
            ['mod_id' => 'ModB', 'workshop_id' => '222', 'installed_at' => 1000],
        ],
        'steam' => [
            '111' => ['updated' => 999_999, 'title' => 'Mod A'],
            '222' => ['updated' => 1000, 'title' => 'Mod B'],
        ],
    ]
);
$svc->tickServer(new Server());
ok(($store->state['run']['phase'] ?? 'idle') === 'warning', 'verouderde ingeschakelde mod: wel opgepikt', $store->state['run']['phase'] ?? 'idle');

// ------------------------------- tweede update tijdens de herstart

// Reported by AlfElFriki: two mod updates minutes apart. The restart applied the
// first, a second landed while the server was coming back, and verification saw
// "something is outdated" and declared the restart a failure, disabling the
// whole feature. It must only judge the items it restarted for.
PowerService::reset();
$now = time();
[$svc, $store] = service(
    ['enabled' => true],
    ['phase' => 'verifying', 'reason' => 'mod', 'stale_ids' => ['111'],
     'verify_after' => $now - 10, 'verify_before' => $now + 600, 'last_restart_at' => $now - 600],
    [
        'mods' => ['ModA', 'ModB'],
        'installed' => [
            ['mod_id' => 'ModA', 'workshop_id' => '111', 'installed_at' => 1_000_000],
            ['mod_id' => 'ModB', 'workshop_id' => '222', 'installed_at' => 1000],
        ],
        'steam' => [
            '111' => ['updated' => 1_000_000, 'title' => 'Mod A'],   // ours, applied
            '222' => ['updated' => 999_999, 'title' => 'Mod B'],     // new, arrived since
        ],
    ]
);
$svc->tickServer(new Server());
ok($store->state['run']['phase'] === 'idle', 'nieuwe update tijdens herstart: geen valse mislukking', $store->state['run']['phase']);
ok($store->state['auto']['enabled'] === true, 'nieuwe update tijdens herstart: blijft aan');

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

// ------------------------------------ de fase-overgang houdt stale_ids vast

// duringWarning() bouwde de run-array voor de verificatiefase opnieuw op en
// liet stale_ids daarbij vallen, waarna duringVerify() terugviel op "is er iets
// verouderd" - precies de test die 2.5.1 verving. De fix zat er wel, de
// overgang maakte hem ongedaan, en de test die dat moest zien zette de
// verificatiefase met de hand klaar en liep er dus omheen.
PowerService::reset();
$now = time();
[$svc, $store] = service(
    ['enabled' => true, 'backup' => false, 'countdown_seconds' => 0],
    ['phase' => 'warning', 'reason' => 'mod', 'restart_at' => $now, 'started_at' => $now - 300,
     'announced' => [5], 'stale_ids' => ['111'], 'build_before' => 7],
    ['steam' => ['111' => ['updated' => 999_999, 'title' => 'Mod A']]]
);
$svc->tickServer(new Server());
ok(($store->state['run']['stale_ids'] ?? null) === ['111'], 'stale_ids overleeft de overgang naar verifieren', $store->state['run']['stale_ids'] ?? null);
ok(($store->state['run']['build_before'] ?? null) === 7, 'build_before overleeft de overgang ook', $store->state['run']['build_before'] ?? null);

// Zonder dat is dit een valse mislukking: de mod waarvoor herstart werd is bij,
// een tweede update kwam er tijdens de herstart bij, en de hele functie zou
// zichzelf uitzetten. Nu wordt alleen gekeken naar waar voor herstart is.
$store->state['run']['verify_after'] = $now - 10;
$store->state['run']['verify_before'] = $now + 600;
IniService::$mods = ['ModA', 'ModB'];
ModScanner::$installed = [
    ['mod_id' => 'ModA', 'workshop_id' => '111', 'installed_at' => 1_000_000],
    ['mod_id' => 'ModB', 'workshop_id' => '222', 'installed_at' => 1000],
];
SteamClient::$details = [
    '111' => ['updated' => 1_000_000, 'title' => 'Mod A'],
    '222' => ['updated' => 999_999, 'title' => 'Mod B'],
];
$svc->tickServer(new Server());
ok($store->state['run']['phase'] === 'idle', 'na de echte overgang: geen valse mislukking', $store->state['run']['phase']);
ok($store->state['auto']['enabled'] === true, 'na de echte overgang: blijft aan');

// ---------------------------------------------------------- geschiedenis

PowerService::reset();
Server::reset();
$now = time();
[$svc, $store] = service(
    ['enabled' => true, 'backup' => false, 'countdown_seconds' => 0],
    ['phase' => 'warning', 'reason' => 'mod', 'restart_at' => $now, 'started_at' => $now - 300,
     'announced' => [5], 'players_at_start' => 2, 'stale_ids' => ['111'],
     'stale_before' => ['111' => ['id' => '111', 'name' => 'Mod A', 'version' => '1.0', 'at' => 1000]]],
    ['steam' => ['111' => ['updated' => 999_999, 'title' => 'Mod A']]]
);
$svc->tickServer(new Server());
$entry = $store->state['history'][0] ?? [];
ok(count($store->state['history'] ?? []) === 1, 'een herstart schrijft een regel in de geschiedenis', count($store->state['history'] ?? []));
ok(($entry['outcome'] ?? null) === 'pending', 'de regel staat op pending tot de verificatie klaar is', $entry['outcome'] ?? null);
ok(($entry['changes'][0]['from'] ?? null) === '1.0', 'de oude versie is vastgelegd voordat hij van schijf verdween', $entry['changes'][0] ?? null);
ok(($entry['changes'][0]['name'] ?? null) === 'Mod A', 'de mod staat met naam in de regel', $entry['changes'][0] ?? null);
ok(($entry['players'] ?? null) === 2, 'het aantal spelers staat erbij', $entry['players'] ?? null);

// De verificatie vult de andere helft in.
$store->state['run']['verify_after'] = $now - 10;
$store->state['run']['verify_before'] = $now + 600;
ModScanner::$installed = [['mod_id' => 'ModA', 'workshop_id' => '111', 'installed_at' => 2_000_000, 'version' => '1.1']];
SteamClient::$details = ['111' => ['updated' => 2_000_000, 'title' => 'Mod A']];
$svc->tickServer(new Server());
$entry = $store->state['history'][0] ?? [];
ok(($entry['outcome'] ?? null) === 'verified', 'geslaagde verificatie sluit de regel af', $entry['outcome'] ?? null);
ok(($entry['changes'][0]['to'] ?? null) === '1.1', 'de nieuwe versie wordt na de herstart bijgeschreven', $entry['changes'][0] ?? null);

// Een mislukte verificatie laat de regel niet op pending staan.
PowerService::reset();
$now = time();
[$svc, $store] = service(
    ['enabled' => true],
    ['phase' => 'verifying', 'reason' => 'mod', 'stale_ids' => ['111'], 'restarted_at' => $now - 600,
     'verify_after' => $now - 10, 'verify_before' => $now + 600, 'last_restart_at' => $now - 600],
    ['steam' => ['111' => ['updated' => 999_999, 'title' => 'Mod A']]]
);
$store->state['history'] = [['at' => $now - 600, 'trigger' => 'auto', 'reason' => 'mod',
    'changes' => [['kind' => 'mod', 'id' => '111', 'name' => 'Mod A', 'from' => '1.0', 'to' => '', 'from_at' => 1000, 'to_at' => 0]],
    'players' => null, 'backup_id' => null, 'outcome' => 'pending', 'note' => '', 'down' => 0]];
$svc->tickServer(new Server());
$entry = $store->state['history'][0] ?? [];
ok(($entry['outcome'] ?? null) === 'failed', 'mislukte verificatie markeert de regel als mislukt', $entry['outcome'] ?? null);
ok(str_contains((string) ($entry['note'] ?? ''), 'still not updated'), 'en zet de reden erbij', $entry['note'] ?? null);
// De view toont dit als "na X weer online". Bij de meest voorkomende mislukking
// komt de server juist niet terug, dus daar mag geen hersteltijd staan.
ok(($entry['down'] ?? null) === 0, 'een mislukte herstart krijgt geen hersteltijd', $entry['down'] ?? null);

// ------------------------------ dode Workshop-items zijn geen storing

// Een verwijderde of prive gezette mod is informatie, geen mislukte controle.
// Toen die twee hetzelfde waren, zette een handvol dode items de backoff aan en
// meldde de pagina de hele server als niet gecontroleerd.
SteamClient::$degraded = false;
[$svc, $store] = service(
    ['enabled' => true, 'check_game' => false],
    [],
    [
        'mods' => ['ModA', 'ModB'],
        'installed' => [
            ['mod_id' => 'ModA', 'workshop_id' => '111', 'installed_at' => 1000],
            ['mod_id' => 'ModB', 'workshop_id' => '222', 'installed_at' => 1000],
        ],
        // Steam antwoordde wel, maar kent 222 niet meer.
        'steam' => ['111' => ['updated' => 1000, 'title' => 'Mod A']],
    ]
);
$found = $svc->detect(new Server(), $store->state['auto']);
ok($found['degraded'] === false, 'een verdwenen mod is geen storing', $found['degraded']);
ok($found['reason'] === null, 'en levert geen herstart op', $found['reason']);
ok(str_contains($found['note'], 'up to date'), 'de melding blijft eerlijk positief', $found['note']);

// --------------------------------------------------- Steam niet bereikbaar

// De ergste storing die deze plugin kan hebben is een groen vinkje over
// ontbrekende informatie. Onbereikbaar Steam mag nooit "alles is bij" heten.
PowerService::reset();
SteamClient::$degraded = true;
[$svc, $store] = service(
    ['enabled' => true, 'check_game' => false],
    [],
    ['steam' => ['111' => ['updated' => 1000, 'title' => 'Mod A']]]
);
$found = $svc->detect(new Server(), $store->state['auto']);
ok($found['degraded'] === true, 'onbereikbaar Steam wordt gemeld', $found['degraded']);
ok($found['reason'] === null, 'onbereikbaar Steam lokt geen herstart uit', $found['reason']);
ok(!str_contains($found['note'], 'up to date'), 'en zegt niet dat alles bij is', $found['note']);
SteamClient::$degraded = false;

// ----------------------------------- uitgeschakelde mods worden wel gemeld

// Ze mogen geen herstart uitlokken, maar verzwijgen is precies waardoor "Check
// now" leek te liegen: de server haalde ze bij een herstart wel binnen.
[$svc, $store] = service(
    ['enabled' => true, 'check_game' => false],
    [],
    [
        'mods' => ['ModA'],
        'installed' => [
            ['mod_id' => 'ModA', 'workshop_id' => '111', 'installed_at' => 1000],
            ['mod_id' => 'ModB', 'workshop_id' => '222', 'installed_at' => 1000],
        ],
        'steam' => [
            '111' => ['updated' => 1000, 'title' => 'Mod A'],
            '222' => ['updated' => 999_999, 'title' => 'Mod B'],
        ],
    ]
);
$found = $svc->detect(new Server(), $store->state['auto']);
ok($found['reason'] === null, 'uitgeschakelde verouderde mod: geen reden om te herstarten', $found['reason']);
ok(count($found['idle']) === 1, 'uitgeschakelde verouderde mod: wel gemeld', $found['idle']);
ok(($found['idle'][0]['id'] ?? null) === '222', 'en met de juiste id', $found['idle'][0] ?? null);

// ------------------------------------- Check now negeert de cache

SteamClient::$ages = [];
[$svc, $store] = service(['enabled' => true, 'check_game' => false]);
$svc->detect(new Server(), $store->state['auto']);
ok(SteamClient::$ages === [60], 'de planner mag een minuut oude gegevens hergebruiken', SteamClient::$ages);

SteamClient::$ages = [];
$svc->detect(new Server(), $store->state['auto'], true);
ok(SteamClient::$ages === [0], 'Check now vraagt het opnieuw op, hoe vers de cache ook is', SteamClient::$ages);

echo $fail ? "\nRESULT: $fail gefaald\n" : "\nRESULT: alles ok\n";
exit($fail ? 1 : 0);
