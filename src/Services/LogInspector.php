<?php

namespace WildBrianNL\PZModManager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;

/**
 * Reads the most recent server DebugLog and attributes errors to mods, so a
 * broken mod is visible in the UI instead of buried in a 700 KB log file.
 */
class LogInspector
{
    public function __construct(private DaemonFileRepository $files) {}

    /**
     * @return array{ok:bool,file:?string,errors:array<string,int>,names:array<string,int>,fatal:?string,started:bool,loaded:string[],notFound:string[],build:?int}
     */
    public function inspect(Server $server): array
    {
        $empty = ['ok' => false, 'file' => null, 'errors' => [], 'names' => [], 'fatal' => null, 'started' => false, 'loaded' => [], 'notFound' => [], 'build' => null];
        $repo = $this->files->setServer($server);

        try {
            $entries = $repo->getDirectory('.cache/Logs');
        } catch (\Throwable $e) {
            return $empty;
        }

        $latest = null;
        $latestAt = '';
        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '');
            $at = (string) ($entry['modified'] ?? '');
            if (str_contains($name, 'DebugLog-server') && $at >= $latestAt) {
                $latest = $name;
                $latestAt = $at;
            }
        }
        if ($latest === null) {
            return $empty;
        }

        try {
            $content = (string) $repo->getContent(".cache/Logs/$latest", 8_000_000);
        } catch (\Throwable $e) {
            return [...$empty, 'file' => $latest];
        }

        $byId = [];
        $byName = [];
        $loaded = [];
        $notFound = [];
        $build = null;
        $fatal = null;
        $started = false;

        foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
            // "version=42.20.0" - lets us target the right build folders
            // instead of assuming one.
            if ($build === null && preg_match('/\bversion=(\d{2})\.\d+/', $line, $m)) {
                $build = (int) $m[1];
            }

            if (str_contains($line, 'SERVER STARTED')) {
                $started = true;
                continue;
            }

            // "LOG  : Mod  ...> loading <ModId>." - what the running server actually has.
            if (str_contains($line, ': Mod ') && preg_match('/>\\s*loading\\s+(.+?)\\.?\\s*$/', $line, $m)) {
                $loaded[] = trim($m[1]);
                continue;
            }

            // The server tried to load this mod and gave up; restarting again
            // will not help, so surface it as a failure rather than "pending".
            if (preg_match('/required mod "([^"]+)" not found/i', $line, $m)) {
                $notFound[] = trim($m[1]);

                continue;
            }

            if ($fatal === null) {
                if (preg_match('/Fluid not found: ([A-Za-z0-9_]+)/', $line, $m)) {
                    $fatal = "Fluid not found: {$m[1]}";
                } elseif (preg_match('/Missing dictionary string on client: ([A-Za-z0-9_]+)/', $line, $m)) {
                    $fatal = "Missing dictionary string: {$m[1]}";
                } elseif (str_contains($line, 'WorldDictionaryException')) {
                    $fatal = 'WorldDictionaryException (script load error)';
                }
            }

            if (!str_contains($line, 'ERROR')) {
                continue;
            }
            if (preg_match('/Lua\(\(MOD:([^)]+)\)\)/', $line, $m)) {
                $key = trim($m[1]);
                $byName[$key] = ($byName[$key] ?? 0) + 1;
            } elseif (preg_match('/Lua\(([^)]+)\)/', $line, $m)) {
                $key = trim($m[1]);
                if (strcasecmp($key, 'Vanilla') !== 0) {
                    $byId[$key] = ($byId[$key] ?? 0) + 1;
                }
            }
        }

        // A crash line from an older boot is stale once the server came up.
        if ($started) {
            $fatal = null;
        }

        return [
            'ok' => true,
            'file' => $latest,
            'errors' => $byId,
            'names' => $byName,
            'fatal' => $fatal,
            'started' => $started,
            'loaded' => array_values(array_unique($loaded)),
            'notFound' => array_values(array_unique($notFound)),
            'build' => $build,
        ];
    }

    public function latestPlayersCount(Server $server): ?int
    {
        $content = $this->latestDebugLogContent($server);
        if ($content === null) {
            return null;
        }

        return $this->extractPlayersCount($content);
    }

    public function latestPlayersCountSince(Server $server, int $offset): ?int
    {
        $content = $this->latestDebugLogContent($server);
        if ($content === null) {
            return null;
        }

        $length = strlen($content);
        $start = $offset;
        if ($start < 0 || $start > $length) {
            $start = 0;
        }

        return $this->extractPlayersCount(substr($content, $start));
    }

    public function latestLogLength(Server $server): ?int
    {
        $content = $this->latestDebugLogContent($server);

        return $content === null ? null : strlen($content);
    }

    private function latestDebugLogContent(Server $server): ?string
    {
        $repo = $this->files->setServer($server);
        $latest = $this->latestDebugLogName($repo);
        if ($latest === null) {
            return null;
        }

        try {
            return (string) $repo->getContent(".cache/Logs/$latest", 8_000_000);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function latestDebugLogName(DaemonFileRepository $repo): ?string
    {
        try {
            $entries = $repo->getDirectory('.cache/Logs');
        } catch (\Throwable $e) {
            return null;
        }

        $latest = null;
        $latestAt = '';
        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '');
            $at = (string) ($entry['modified'] ?? '');
            if (str_contains($name, 'DebugLog-server') && $at >= $latestAt) {
                $latest = $name;
                $latestAt = $at;
            }
        }

        return $latest;
    }

    private function extractPlayersCount(string $content): ?int
    {
        $count = null;
        foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
            if (preg_match('/Players connected\s*\((\d+)\)/i', $line, $m)) {
                $count = (int) $m[1];
            }
        }

        return $count;
    }
}
