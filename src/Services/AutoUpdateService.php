<?php

namespace WildBrianNL\PZModManager\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AutoUpdateService
{
    // Keep delayed-restart markers around long enough for missed scheduler ticks.
    private const WARNING_TTL_MINUTES = 15;
    private const PLAYER_COUNT_POLL_INTERVAL_US = 500_000;
    private const PLAYER_COUNT_POLL_TIMEOUT_US = 6_000_000;
    // Steam timestamps can differ by a few minutes around propagation/download windows.
    private const UPDATE_SKEW_SECONDS = 300;

    public function __construct(
        private IniService $ini,
        private ModScanner $scanner,
        private SteamClient $steam,
        private LogInspector $logs,
        private ConsoleCommandService $console,
        private PowerService $power,
    ) {}

    public function runChecks(): void
    {
        foreach ($this->zomboidServers() as $server) {
            $this->checkServer($server);
        }
    }

    public function processPendingRestarts(): void
    {
        foreach ($this->zomboidServers() as $server) {
            $pendingAt = (int) Cache::get($this->pendingRestartKey($server), 0);
            if ($pendingAt === 0 || now()->timestamp < $pendingAt) {
                if ($pendingAt > 0) {
                    $this->setStatus($server, 'pending_restart', $pendingAt, $this->currentDiagnostics($server));
                }
                continue;
            }

            $updateCheck = $this->inspectUpdates($server);
            if (!$updateCheck['has_updates']) {
                Cache::forget($this->pendingRestartKey($server));
                $this->setStatus(
                    $server,
                    'idle',
                    null,
                    $this->withSummary($updateCheck, 'Pending restart cleared because no Workshop updates are currently detected.')
                );

                continue;
            }

            $this->setStatus(
                $server,
                'restarting',
                null,
                $this->withSummary($updateCheck, 'Pending restart window reached. Restarting now to apply updates.')
            );
            $this->restart($server);
            Cache::forget($this->pendingRestartKey($server));
        }
    }

    private function checkServer(Server $server): void
    {
        $this->setStatus($server, 'checking', null, [
            'summary' => 'Starting Workshop update check.',
            'details' => [],
        ]);

        $updateCheck = $this->inspectUpdates($server);
        if (!$updateCheck['has_updates']) {
            Cache::forget($this->pendingRestartKey($server));
            $this->setStatus($server, 'idle', null, $updateCheck);

            return;
        }

        $pendingAt = (int) Cache::get($this->pendingRestartKey($server), 0);
        if ($pendingAt > 0) {
            $this->setStatus(
                $server,
                'pending_restart',
                $pendingAt,
                $this->withSummary($updateCheck, 'Workshop updates are still pending restart.')
            );

            return;
        }

        $players = $this->playersCount($server);

        if ($players === 0) {
            $this->setStatus(
                $server,
                'restarting',
                null,
                $this->withSummary($updateCheck, 'Workshop updates found and no players online. Restarting now.')
            );
            $this->restart($server);

            return;
        }

        if ($players !== null && $players > 0) {
            $this->warnPlayers($server);
            $pendingAt = now()->addMinute()->timestamp;

            Cache::put(
                $this->pendingRestartKey($server),
                $pendingAt,
                now()->addMinutes(self::WARNING_TTL_MINUTES)
            );
            $this->setStatus(
                $server,
                'pending_restart',
                $pendingAt,
                $this->withSummary($updateCheck, "Workshop updates found with {$players} player(s) online. Warned players and scheduled restart in 1 minute.")
            );

            return;
        }

        $this->setStatus(
            $server,
            'check_failed',
            null,
            $this->withSummary($updateCheck, 'Workshop updates found, but player count could not be confirmed. Will retry on the next check.')
        );
        Log::warning('PZ auto-update skipped: could not determine player count', ['server_id' => $server->id]);
    }

    /** @return array{has_updates:bool,summary:string,details:string[]} */
    private function inspectUpdates(Server $server): array
    {
        $details = [];
        $ini = $this->ini->read($server);
        if (!$ini['ok']) {
            return [
                'has_updates' => false,
                'summary' => 'Server config could not be read.',
                'details' => $details,
            ];
        }

        $activeIds = array_values(array_unique($ini['mods']));
        $details[] = 'Enabled mod IDs in config: '.count($activeIds).'.';
        if (!$activeIds) {
            return [
                'has_updates' => false,
                'summary' => 'No enabled mods are configured.',
                'details' => $details,
            ];
        }

        $index = $this->scanner->index($server, (int) config('pz-mod-manager.fallback_build', 42));
        if (!$index['ok']) {
            return [
                'has_updates' => false,
                'summary' => 'Installed mod scan failed.',
                'details' => $details,
            ];
        }
        $details[] = 'Installed mods discovered on disk: '.count($index['mods']).'.';

        $installedPerWorkshop = [];
        foreach ($index['mods'] as $mod) {
            if (!in_array($mod['mod_id'], $activeIds, true)) {
                continue;
            }

            $workshopId = (string) ($mod['workshop_id'] ?? '');
            if ($workshopId === '') {
                continue;
            }

            $installedPerWorkshop[$workshopId] = max(
                (int) ($installedPerWorkshop[$workshopId] ?? 0),
                (int) ($mod['installed_at'] ?? 0)
            );
        }

        $details[] = 'Active Workshop items with installed files: '.count($installedPerWorkshop).'.';
        if (!$installedPerWorkshop) {
            return [
                'has_updates' => false,
                'summary' => 'No installed Workshop items match enabled mods.',
                'details' => $details,
            ];
        }

        $freshSteamAge = max(30, (int) config('pz-mod-manager.auto_update.max_steam_meta_age_seconds', 60));
        $steam = $this->steam->details(array_keys($installedPerWorkshop), $freshSteamAge);
        $details[] = 'Steam metadata max age for this check: '.$freshSteamAge.'s.';
        $details[] = 'Steam metadata returned for '.count($steam).' of '.count($installedPerWorkshop).' Workshop items.';
        $compareLines = [];
        $omitted = 0;
        foreach ($installedPerWorkshop as $workshopId => $installedAt) {
            $updatedAt = (int) ($steam[$workshopId]['updated'] ?? 0);

            if (count($compareLines) < 25) {
                if ($updatedAt === 0) {
                    $compareLines[] = "Workshop {$workshopId}: Steam timestamp unavailable.";
                } elseif ($installedAt <= 0) {
                    $compareLines[] = "Workshop {$workshopId}: installed timestamp unavailable.";
                } else {
                    $delta = $updatedAt - $installedAt;
                    $compareLines[] = "Workshop {$workshopId}: Steam minus installed = {$delta}s (needs > ".self::UPDATE_SKEW_SECONDS."s).";
                }
            } else {
                $omitted++;
            }

            if ($installedAt > 0 && $updatedAt > $installedAt + self::UPDATE_SKEW_SECONDS) {
                $details = array_merge($details, $compareLines);
                if ($omitted > 0) {
                    $details[] = "Comparison lines omitted: {$omitted}.";
                }

                return [
                    'has_updates' => true,
                    'summary' => "Workshop update detected for {$workshopId}.",
                    'details' => $details,
                ];
            }
        }

        $details = array_merge($details, $compareLines);
        if ($omitted > 0) {
            $details[] = "Comparison lines omitted: {$omitted}.";
        }

        return [
            'has_updates' => false,
            'summary' => 'No Workshop updates detected.',
            'details' => $details,
        ];
    }

    private function playersCount(Server $server): ?int
    {
        try {
            $baselineLength = $this->logs->latestLogLength($server);
            $this->console->setServer($server)->send('players');

            $waited = 0;
            while ($waited <= self::PLAYER_COUNT_POLL_TIMEOUT_US) {
                $count = $this->logs->latestPlayersCountSince($server, $baselineLength ?? 0);
                if ($count !== null) {
                    return $count;
                }

                usleep(self::PLAYER_COUNT_POLL_INTERVAL_US);
                $waited += self::PLAYER_COUNT_POLL_INTERVAL_US;
            }
        } catch (\Throwable $e) {
            Log::warning('PZ auto-update players command failed', ['server_id' => $server->id, 'error' => $e->getMessage()]);
        }

        return null;
    }

    private function warnPlayers(Server $server): void
    {
        try {
            $this->console
                ->setServer($server)
                ->send('say [PZ Mod Manager] Mod updates found. Server restart in 1 minute.');
        } catch (\Throwable $e) {
            Log::warning('PZ auto-update warn command failed', ['server_id' => $server->id, 'error' => $e->getMessage()]);
        }
    }

    private function restart(Server $server): void
    {
        try {
            $this->power->setServer($server)->send('restart');
        } catch (\Throwable $e) {
            Log::warning('PZ auto-update restart failed', ['server_id' => $server->id, 'error' => $e->getMessage()]);
        }
    }

    /** @return \Illuminate\Support\Collection<int,Server> */
    private function zomboidServers()
    {
        $match = strtolower((string) config('pz-mod-manager.egg_match', 'zomboid'));

        return Server::query()
            ->with('egg')
            ->get()
            ->filter(fn (Server $server) => str_contains(strtolower((string) ($server->egg->name ?? '')), $match))
            ->values();
    }

    private function pendingRestartKey(Server $server): string
    {
        return "pzmm:auto-update:pending-restart:{$server->id}";
    }

    private function statusKey(Server $server): string
    {
        return "pzmm:auto-update:status:{$server->id}";
    }

    /**
     * @param  array{summary?:string,details?:array<int,string>}  $diagnostics
     */
    private function setStatus(Server $server, string $state, ?int $pendingAt = null, array $diagnostics = []): void
    {
        $status = [
            'state' => $state,
            'checked_at' => now()->timestamp,
            'pending_at' => $pendingAt,
        ];

        $summary = trim((string) ($diagnostics['summary'] ?? ''));
        if ($summary !== '') {
            $status['summary'] = $summary;
        }

        $details = array_values(array_filter(array_map(
            fn ($line) => trim((string) $line),
            is_array($diagnostics['details'] ?? null) ? $diagnostics['details'] : []
        ), fn ($line) => $line !== ''));
        if ($details) {
            $status['details'] = array_slice($details, 0, 40);
        }

        Cache::put($this->statusKey($server), $status, now()->addDay());
    }

    /** @param array{has_updates:bool,summary:string,details:string[]} $check */
    private function withSummary(array $check, string $summary): array
    {
        return [
            'summary' => $summary,
            'details' => $check['details'] ?? [],
        ];
    }

    /** @return array{summary?:string,details?:array<int,string>} */
    private function currentDiagnostics(Server $server): array
    {
        $status = Cache::get($this->statusKey($server), []);

        return [
            'summary' => trim((string) ($status['summary'] ?? '')),
            'details' => is_array($status['details'] ?? null) ? $status['details'] : [],
        ];
    }
}
