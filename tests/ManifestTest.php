<?php

/**
 * The SteamCMD manifest editor, on strings only.
 *
 * `appworkshop_<appid>.acf` is Valve's file and it decides what the server
 * downloads at boot. Cutting the wrong bytes out of it does not break a mod, it
 * breaks downloading. So the brace walker gets exercised on the shapes that
 * actually turn up: nested blocks, ids appearing as values, and a truncated
 * file.
 */
// The real class, not the stub in stubs.php: this is the code under test, and
// requiring both would redeclare it. Nothing here needs a panel, because the
// brace walker only ever sees strings.
require __DIR__ . '/../src/Services/ModScanner.php';

use WildBrianNL\PZModManager\Services\ModScanner;

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

$scanner = (new ReflectionClass(ModScanner::class))->newInstanceWithoutConstructor();
$drop = (new ReflectionClass(ModScanner::class))->getMethod('dropBlocks');
$cut = fn (string $raw, string $id) => $drop->invoke($scanner, $raw, $id);

$acf = <<<'ACF'
"AppWorkshop"
{
	"appid"		"108600"
	"SizeOnDisk"		"123"
	"WorkshopItemsInstalled"
	{
		"111"
		{
			"size"		"10"
			"manifest"		"999"
		}
		"222"
		{
			"size"		"20"
		}
	}
	"WorkshopItemDetails"
	{
		"111"
		{
			"manifest"		"999"
		}
		"222"
		{
			"manifest"		"888"
			"subscribedby"		"111"
		}
	}
}
ACF;

$balanced = fn (string $s) => substr_count($s, '{') === substr_count($s, '}');
ok($balanced($acf), 'het voorbeeld is om te beginnen in balans');

$r = $cut($acf, '111');
ok($balanced($r), 'na het knippen zijn de accolades nog in balans');
// Beide blokken weg, maar de verwijzing naar 111 in het blok van 222 blijft:
// die is een waarde, geen blok, en die mag niet meegesneuveld zijn.
ok(!preg_match('/^\s*"111"\s*$/m', $r), '111 staat nergens meer als sleutel');
ok(substr_count($r, '"111"') === 1, 'alleen de verwijzing als waarde is over', substr_count($r, '"111"'));
ok(substr_count($r, '"222"') === 2, '222 houdt zijn twee blokken', substr_count($r, '"222"'));
ok(str_contains($r, '"subscribedby"		"111"'), 'een id dat als waarde voorkomt wordt niet aangezien voor een blok');
ok(str_contains($r, '"appid"'), 'de rest van het bestand blijft heel');

$r = $cut($acf, '333');
ok($r === $acf, 'een id dat er niet in staat verandert niets');

// Een half weggeschreven bestand is al scheef. Het knippen mag die scheefheid
// niet vergroten, want forgetInstalled() weigert daarna toch te schrijven.
$skew = fn (string $s) => substr_count($s, '{') - substr_count($s, '}');
$truncated = substr($acf, 0, strpos($acf, '"WorkshopItemDetails"'));
$r = $cut($truncated, '111');
ok($skew($r) === $skew($truncated), 'knippen maakt een afgekapt bestand niet schever', [$skew($truncated), $skew($r)]);

// Accolade in een waarde mag de telling niet in de war schoppen.
$weird = str_replace('"size"		"20"', '"title"		"Mod {with} braces"', $acf);
$r = $cut($weird, '111');
ok($balanced($r), 'accolades binnen een tekstwaarde tellen niet mee');
ok(str_contains($r, 'Mod {with} braces'), 'en die waarde blijft ongemoeid');

echo $fail ? "\nRESULT: $fail gefaald\n" : "\nRESULT: alles ok\n";
exit($fail ? 1 : 0);
