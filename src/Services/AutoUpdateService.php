<?php

namespace WildBrianNL\PZModManager\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AutoUpdateService
{
    private const WARNING_TTL_MINUTES = 15;

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
                continue;
            }

            if (!$this->hasUpdates($server)) {
                Cache::forget($this->pendingRestartKey($server));

                continue;
            }

            $this->restart($server);
            Cache::forget($this->pendingRestartKey($server));
        }
    }

    private function checkServer(Server $server): void
    {
        if (!$this->hasUpdates($server)) {
            Cache::forget($this->pendingRestartKey($server));

            return;
        }

        $pendingAt = (int) Cache::get($this->pendingRestartKey($server), 0);
        if ($pendingAt > 0) {
            return;
        }

        $players = $this->playersCount($server);

        if ($players === 0) {
            $this->restart($server);

            return;
        }

        if ($players !== null && $players > 0) {
            $this->warnPlayers($server);

            Cache::put(
                $this->pendingRestartKey($server),
                now()->addMinute()->timestamp,
                now()->addMinutes(self::WARNING_TTL_MINUTES)
            );

            return;
        }

        Log::warning('PZ auto-update skipped: could not determine player count', ['server_id' => $server->id]);
    }

    private function hasUpdates(Server $server): bool
    {
        $ini = $this->ini->read($server);
        if (!$ini['ok']) {
            return false;
        }

        $activeIds = array_values(array_unique($ini['mods']));
        if (!$activeIds) {
            return false;
        }

        $index = $this->scanner->index($server, (int) config('pz-mod-manager.fallback_build', 42));
        if (!$index['ok']) {
            return false;
        }

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

        if (!$installedPerWorkshop) {
            return false;
        }

        $steam = $this->steam->details(array_keys($installedPerWorkshop));
        foreach ($installedPerWorkshop as $workshopId => $installedAt) {
            $updatedAt = (int) ($steam[$workshopId]['updated'] ?? 0);
            if ($installedAt > 0 && $updatedAt > $installedAt + 300) {
                return true;
            }
        }

        return false;
    }

    private function playersCount(Server $server): ?int
    {
        try {
            $this->console->setServer($server)->send('players');
            usleep(750_000);

            return $this->logs->latestPlayersCount($server);
        } catch (\Throwable $e) {
            Log::warning('PZ auto-update players command failed', ['server_id' => $server->id, 'error' => $e->getMessage()]);

            return null;
        }
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
}
