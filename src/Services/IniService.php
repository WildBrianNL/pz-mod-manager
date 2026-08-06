<?php

namespace WildBrianNL\PZModManager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use RuntimeException;

/**
 * Reads and writes the Project Zomboid server .ini.
 *
 * The .ini is the single source of truth for mods. This service only ever
 * rewrites the WorkshopItems / Mods / Map lines and leaves the rest of the
 * file byte-for-byte intact.
 */
class IniService
{
    public function __construct(private DaemonFileRepository $files) {}

    /** Locate the active server config, e.g. `.cache/Server/myserver.ini`. */
    public function path(Server $server): ?string
    {
        try {
            $entries = $this->files->setServer($server)->getDirectory('.cache/Server');
        } catch (\Throwable $e) {
            return null;
        }

        foreach ($entries as $entry) {
            $name = $entry['name'] ?? '';
            if (str_ends_with($name, '.ini') && !str_contains($name, '_')) {
                return '.cache/Server/' . $name;
            }
        }

        return null;
    }

    /**
     * @return array{ok:bool,path:?string,raw:string,workshopItems:string[],mods:string[],maps:string[],error:?string}
     */
    public function read(Server $server): array
    {
        $blank = ['ok' => false, 'path' => null, 'raw' => '', 'workshopItems' => [], 'mods' => [], 'maps' => [], 'error' => null];

        $path = $this->path($server);
        if ($path === null) {
            return [...$blank, 'error' => 'config_not_found'];
        }

        try {
            $raw = (string) $this->files->setServer($server)->getContent($path, 2_000_000);
        } catch (\Throwable $e) {
            return [...$blank, 'path' => $path, 'error' => 'config_unreadable'];
        }

        if (!$this->looksLikeServerConfig($raw)) {
            return [...$blank, 'path' => $path, 'raw' => $raw, 'error' => 'config_unreadable'];
        }

        return [
            'ok' => true,
            'path' => $path,
            'raw' => $raw,
            'workshopItems' => $this->parse($raw, 'WorkshopItems'),
            'mods' => $this->parse($raw, 'Mods'),
            'maps' => $this->parse($raw, 'Map'),
            'error' => null,
        ];
    }

    /**
     * Mods hoisted to the front of Mods= on every write, in this order.
     *
     * Project Zomboid loads mods in Mods= order and silently drops any mod
     * whose `require=` target has not loaded yet ("required mod X not found").
     * Frameworks that others depend on must therefore never drift behind them,
     * which is exactly what happens when the list is rebuilt from a directory
     * scan. Add further framework ids here; unknown ids are ignored.
     */
    private const PRIORITY_MODS = [
        'storm-core-b42',
    ];

    /**
     * Persist mod state. Values are de-duplicated but order is preserved,
     * except for the framework mods hoisted to the front by hoistFrameworks().
     *
     * @param  string[]  $workshopItems
     * @param  string[]  $mods
     * @param  string[]|null  $maps  null keeps the existing Map= line untouched
     */
    public function write(Server $server, array $workshopItems, array $mods, ?array $maps = null): void
    {
        $current = $this->read($server);
        if (!$current['ok']) {
            // Never write a config we could not safely read - that would wipe it.
            throw new RuntimeException('Refusing to write: server config could not be read.');
        }

        $raw = $current['raw'];
        $raw = $this->replace($raw, 'WorkshopItems', $this->join($workshopItems));
        $raw = $this->replace($raw, 'Mods', $this->join($this->hoistFrameworks($mods)));
        if ($maps !== null) {
            $raw = $this->replace($raw, 'Map', $this->join($maps));
        }

        if (!$this->looksLikeServerConfig($raw)) {
            throw new RuntimeException('Refusing to write: rendered config failed the sanity check.');
        }

        $this->files->setServer($server)->putContent($current['path'], $raw);
    }

    private function looksLikeServerConfig(string $raw): bool
    {
        return strlen($raw) > 200
            && str_contains($raw, 'Mods=')
            && str_contains($raw, 'WorkshopItems=');
    }

    /**
     * Move PRIORITY_MODS to the front, keeping every other mod in its
     * existing relative order. Only hoists ids that are already enabled -
     * this never adds a mod the caller did not ask for.
     *
     * @param  string[]  $mods
     * @return string[]
     */
    private function hoistFrameworks(array $mods): array
    {
        $front = array_values(array_filter(
            self::PRIORITY_MODS,
            fn ($id) => in_array($id, $mods, true)
        ));

        if ($front === []) {
            return $mods;
        }

        $rest = array_values(array_filter(
            $mods,
            fn ($m) => !in_array($m, $front, true)
        ));

        return [...$front, ...$rest];
    }

    /** @param string[] $values */
    private function join(array $values): string
    {
        $seen = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '' && !in_array($value, $seen, true)) {
                $seen[] = $value;
            }
        }

        return implode(';', $seen);
    }

    /** @return string[] */
    private function parse(string $raw, string $key): array
    {
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            if (stripos($line, $key . '=') === 0) {
                $values = array_map('trim', explode(';', substr($line, strlen($key) + 1)));

                return array_values(array_filter($values, fn ($v) => $v !== ''));
            }
        }

        return [];
    }

    private function replace(string $raw, string $key, string $value): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        foreach ($lines as $i => $line) {
            if (stripos($line, $key . '=') === 0) {
                $lines[$i] = $key . '=' . $value;

                return implode("\n", $lines);
            }
        }
        $lines[] = $key . '=' . $value;

        return implode("\n", $lines);
    }
}
