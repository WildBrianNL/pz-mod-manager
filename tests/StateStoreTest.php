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

echo $fail ? "\nRESULT: $fail gefaald\n" : "\nRESULT: alles ok\n";
exit($fail ? 1 : 0);
