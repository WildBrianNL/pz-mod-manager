<?php
// Exercises StateStore's coercion without a panel: the class only touches the
// file repository inside read()/write(), so the clamping can be reached through
// reflection on a stub.
require __DIR__ . "/../src/Services/StateStore.php";

use WildBrianNL\PZModManager\Services\StateStore;

$store = (new ReflectionClass(StateStore::class))->newInstanceWithoutConstructor();
$auto = (new ReflectionClass(StateStore::class))->getMethod('auto');
$call = fn (array $in) => $auto->invoke($store, $in);

$fail = 0;
function ok(bool $cond, string $label, $got = null) {
    global $fail;
    echo ($cond ? "  PASS " : "  FAIL ") . $label;
    if (!$cond) { echo "  <- kreeg " . var_export($got, true); $fail++; }
    echo "\n";
}

$d = $call([]);
ok($d['enabled'] === false, 'standaard staat auto-herstart uit', $d['enabled']);
ok($d['warn_minutes'] === 5, 'standaard waarschuwing is 5 minuten', $d['warn_minutes']);
ok($d['backup'] === true, 'standaard wordt er geback-upt', $d['backup']);

$r = $call(['warn_minutes' => 100000]);
ok($r['warn_minutes'] === 60, 'absurde waarschuwtijd wordt afgekapt op 60', $r['warn_minutes']);

$r = $call(['check_minutes' => 0]);
ok($r['check_minutes'] === 1, 'controle-interval kan niet onder 1', $r['check_minutes']);

$r = $call(['check_minutes' => -5]);
ok($r['check_minutes'] === 1, 'negatief interval wordt opgetrokken', $r['check_minutes']);

$r = $call(['countdown_seconds' => 'tien']);
ok($r['countdown_seconds'] === 10, 'onzin-tekst laat de standaard staan', $r['countdown_seconds']);

$r = $call(['enabled' => 'ja']);
ok($r['enabled'] === true, 'checkbox-string wordt een bool', $r['enabled']);

$r = $call(['msg_warn' => '  hoi  ']);
ok($r['msg_warn'] === 'hoi', 'bericht wordt getrimd', $r['msg_warn']);

$r = $call(['msg_warn' => str_repeat('x', 900)]);
ok(strlen($r['msg_warn']) === 400, 'te lang bericht wordt afgekapt', strlen($r['msg_warn']));

$r = $call(['msg_warn' => '']);
ok($r['msg_warn'] === '', 'leeg bericht blijft leeg (= niets sturen)', $r['msg_warn']);

$r = $call(['onzin' => 1]);
ok(!array_key_exists('onzin', $r), 'onbekende sleutel wordt genegeerd');

$r = $call(['cooldown_minutes' => 99999]);
ok($r['cooldown_minutes'] === 1440, 'afkoeltijd max 24 uur', $r['cooldown_minutes']);

// -------------------------------------------------------------- geschiedenis
// De view loopt hier blind doorheen om te vertellen wat de server 's nachts
// heeft gedaan. Een half weggeschreven of met de hand aangepast bestand moet
// een kortere lijst geven, nooit een pagina die halverwege stukloopt.
$history = (new ReflectionClass(StateStore::class))->getMethod('history');
$hist = fn (array $in) => $history->invoke($store, $in);

$r = $hist([['at' => 100, 'trigger' => 'auto', 'reason' => 'mod', 'outcome' => 'verified']]);
ok(count($r) === 1 && $r[0]['at'] === 100, 'een geldige regel overleeft de ronde', $r);
ok($r[0]['changes'] === [], 'ontbrekende changes worden een lege lijst', $r[0]['changes']);

ok($hist([['trigger' => 'auto']]) === [], 'regel zonder tijdstip wordt weggegooid');
ok($hist(['kapot']) === [], 'regel die geen array is wordt weggegooid');

$r = $hist([['at' => 1, 'outcome' => 'wat dan ook']]);
ok($r[0]['outcome'] === 'unverified', 'onbekende uitkomst wordt niet geloofd', $r[0]['outcome']);

$r = $hist([['at' => 1], ['at' => 300], ['at' => 200]]);
ok(array_column($r, 'at') === [300, 200, 1], 'nieuwste eerst, wat er ook in het bestand staat', array_column($r, 'at'));

$many = [];
for ($i = 1; $i <= 30; $i++) { $many[] = ['at' => $i]; }
$r = $hist($many);
ok(count($r) === 20, 'lijst wordt afgekapt op 20', count($r));
ok($r[0]['at'] === 30, 'en het is de oudste die eraf gaat, niet de nieuwste', $r[0]['at']);

$r = $hist([['at' => 1, 'changes' => [['kind' => 'spook', 'name' => str_repeat('x', 900)]]]]);
ok($r[0]['changes'][0]['kind'] === 'mod', 'onbekende soort verandering wordt mod', $r[0]['changes'][0]['kind']);
ok(strlen($r[0]['changes'][0]['name']) === 200, 'te lange naam wordt afgekapt', strlen($r[0]['changes'][0]['name']));

$r = $store->remember([['at' => 10]], ['at' => 20, 'outcome' => 'pending']);
ok(array_column($r, 'at') === [20, 10], 'remember zet de nieuwe herstart bovenaan', array_column($r, 'at'));

echo $fail ? "\nRESULT: $fail gefaald\n" : "\nRESULT: alles ok\n";
exit($fail ? 1 : 0);
