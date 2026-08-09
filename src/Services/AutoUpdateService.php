<?php

namespace WildBrianNL\PZModManager\Services;

use App\Models\Backup;
use App\Models\Server;
use App\Models\ServerVariable;
use App\Services\Backups\InitiateBackupService;
use Illuminate\Support\Facades\Log;

/**
 * Restarts a server, on its own, when Steam has a newer copy of something it is
 * running.
 *
 * The problem being solved is narrow. A Workshop mod that updates while the
 * server is up leaves the server running the old files and every client holding
 * the new ones, and Project Zomboid refuses the mismatch, so nobody new can
 * join. Existing players notice nothing. Only a restart makes the server pick up
 * the new files. The same is true of a game update.
 *
 * Everything here is built around one fear: a plugin that restarts servers on a
 * timer is one bad assumption away from restarting a populated server every five
 * minutes, all night. The safeguards below are not decoration.
 *
 * **It cannot start without AUTO_UPDATE.** The steamcmd images only re-run
 * steamcmd on boot when the egg variable AUTO_UPDATE is 1. Without it a restart
 * downloads nothing, the update is still pending afterwards, and the plugin
 * would restart again, forever. Turning the feature on is therefore refused
 * unless that variable is set.
 *
 * **One attempt, then it stops.** After restarting, the service waits for the
 * server to come back and checks whether the update actually landed. If it did
 * not, it disables itself and says so, rather than trying again. A server that
 * needs a human is better than a server in a reboot loop.
 *
 * **Nothing is ever inferred from a failed lookup.** Steam unreachable, log
 * unreadable, player count unknown: each of these means "no information", and no
 * restart is scheduled on no information.
 *
 * The phases are driven by the panel scheduler, one tick a minute, with the
 * state on disk beside the server rather than in the cache. Phases:
 *
 *   idle      nothing to do; checks for updates every `check_minutes`
 *   warning   restart is scheduled; players are being told; backup is running
 *   verifying restarted, waiting for the server to come back to confirm
 *   failed    verification failed; auto-restart is off and needs a human
 */
class AutoUpdateService
{
    /**
     * How long after a restart before verification bothers looking.
     *
     * A Project Zomboid server takes a while to boot: steamcmd first, then the
     * map. Checking too early reads a stale log from before the restart and
     * "proves" the update failed.
     */
    private const VERIFY_GRACE_MINUTES = 8;

    /** Give up waiting for the server to come back, and call it a failure. */
    private const VERIFY_DEADLINE_MINUTES = 30;

    /**
     * Steam publishes a mod, the server downloads it, and the two timestamps
     * never match to the second. Only a gap larger than this counts as an
     * update rather than as normal clock drift around a download.
     */
    private const SKEW_SECONDS = 300;

    /** Metadata this old is refetched from Steam rather than reused. */
    private const STEAM_MAX_AGE_SECONDS = 60;

    public function __construct(
        private IniService $ini,
        private ModScanner $scanner,
        private SteamClient $steam,
        private LogInspector $logs,
        private StateStore $store,
        private GameBuild $build,
        private PowerService $power,
    ) {}

    // ------------------------------------------------------------------ entry

    /** Called once a minute by the panel scheduler. */
    public function tick(): void
    {
        foreach ($this->servers() as $server) {
            try {
                $this->tickServer($server);
            } catch (\Throwable $e) {
                // One broken server must not stop the others from being handled.
                Log::error('pz-mod-manager auto-update tick failed', [
                    'server_id' => $server->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function tickServer(Server $server): void
    {
        $state = $this->store->read($server);
        if (!($state['auto']['enabled'] ?? false)) {
            return;
        }

        match ($state['run']['phase'] ?? 'idle') {
            'warning' => $this->duringWarning($server, $state),
            'verifying' => $this->duringVerify($server, $state),
            // 'failed' is terminal on purpose: it means the last restart did not
            // fix anything, and repeating it would only kick players again.
            'failed' => null,
            default => $this->whenIdle($server, $state),
        };
    }

    // ----------------------------------------------------------------- phases

    /** @param array<string,mixed> $state */
    private function whenIdle(Server $server, array $state): void
    {
        $auto = $state['auto'];
        $run = $state['run'];
        $now = now()->timestamp;

        // Two clocks: how recently a restart happened, and how recently we
        // looked. The first keeps a wave of mod updates from becoming a wave of
        // restarts; the second is just the poll interval.
        $cooldownUntil = (int) ($run['last_restart_at'] ?? 0) + $auto['cooldown_minutes'] * 60;
        if ($now < $cooldownUntil) {
            return;
        }
        if ($now < (int) ($run['next_check_at'] ?? 0)) {
            return;
        }

        $run['next_check_at'] = $now + max(60, $auto['check_minutes'] * 60);
        $run['checked_at'] = $now;

        $found = $this->detect($server, $auto);
        $run['note'] = $found['note'];

        if (!$found['reason']) {
            $this->save($server, $state, $run);

            return;
        }

        // Anything that arrives during the warning window rides along with the
        // same restart, so a modder publishing five updates in a row costs one
        // restart rather than five. That is why there is no separate settling
        // phase: the warning window is the settling window.
        $players = $this->players($server);
        $warnMinutes = $players === 0 ? 0 : $auto['warn_minutes'];

        $run = [
            'phase' => 'warning',
            'reason' => $found['reason'],
            'detail' => $found['detail'],
            'restart_at' => $now + $warnMinutes * 60,
            'started_at' => $now,
            'announced' => [],
            'players_at_start' => $players,
            'last_restart_at' => $run['last_restart_at'] ?? 0,
            'note' => $found['note'],
        ];

        // Flushing the world before the snapshot means the backup holds a saved
        // game rather than whatever was in memory when the copy started.
        if ($auto['backup']) {
            $this->say($server, 'save');
            $run['backup_id'] = $this->startBackup($server, $found['reason']);
        }

        if ($warnMinutes > 0) {
            $this->announce($server, $auto['msg_warn'], [
                ':minutes' => (string) $warnMinutes,
                ':reason' => $found['reason'],
            ]);
            $run['announced'][] = $warnMinutes;
        }

        $this->save($server, $state, $run);
    }

    /** @param array<string,mixed> $state */
    private function duringWarning(Server $server, array $state): void
    {
        $auto = $state['auto'];
        $run = $state['run'];
        $now = now()->timestamp;
        $left = (int) $run['restart_at'] - $now;

        if ($left > 30) {
            // One more warning close to the end. Somebody who joined after the
            // first announcement has otherwise had no warning at all.
            $minutes = (int) ceil($left / 60);
            if ($minutes <= 1 && !in_array(1, $run['announced'] ?? [], true)) {
                $this->announce($server, $auto['msg_final'], [':minutes' => '1']);
                $run['announced'][] = 1;
                $this->save($server, $state, $run);
            }

            return;
        }

        // Time is up, but the backup may not be. Waiting is bounded: a slow
        // backup may delay a restart, it may not hold one hostage.
        if ($auto['backup'] && isset($run['backup_id'])) {
            $waited = $now - (int) $run['started_at'];
            if (!$this->backupSettled((int) $run['backup_id']) && $waited < $auto['backup_wait_seconds']) {
                $run['restart_at'] = $now + 60;
                $run['note'] = 'Waiting for the backup to finish before restarting.';
                $this->save($server, $state, $run);

                return;
            }
        }

        $this->countdown($server, $auto);

        $run = [
            'phase' => 'verifying',
            'reason' => $run['reason'],
            'detail' => $run['detail'] ?? [],
            'restarted_at' => $now,
            'verify_after' => $now + self::VERIFY_GRACE_MINUTES * 60,
            'verify_before' => $now + self::VERIFY_DEADLINE_MINUTES * 60,
            'last_restart_at' => $now,
            'note' => 'Restarted to apply a ' . $run['reason'] . ' update. Verifying.',
        ];
        $this->save($server, $state, $run);

        $this->power->setServer($server)->send('restart');
    }

    /** @param array<string,mixed> $state */
    private function duringVerify(Server $server, array $state): void
    {
        $run = $state['run'];
        $now = now()->timestamp;

        if ($now < (int) $run['verify_after']) {
            return;
        }

        // The server has to be up before anything it reports means anything.
        $log = $this->logs->inspect($server);
        if (!($log['started'] ?? false)) {
            if ($now < (int) $run['verify_before']) {
                return;
            }

            $this->stop($server, $state, 'The server did not come back up after the restart. Auto-restart is off.');

            return;
        }

        $found = $this->detect($server, $state['auto']);
        if ($found['reason']) {
            // A restart that changed nothing means restarting again would change
            // nothing either. Usually AUTO_UPDATE is off, or a Workshop item was
            // pulled and can no longer be downloaded.
            $this->stop(
                $server,
                $state,
                'Restarted, but the ' . $found['reason'] . ' update is still not applied. '
                . 'Auto-restart is off so the server is not restarted again. ' . $found['note']
            );

            return;
        }

        $this->announce($server, $state['auto']['msg_back'], []);

        $this->save($server, $state, [
            'phase' => 'idle',
            'last_restart_at' => (int) $run['last_restart_at'],
            'next_check_at' => $now + max(60, $state['auto']['check_minutes'] * 60),
            'checked_at' => $now,
            'note' => 'Update applied and verified: ' . $run['reason'] . '.',
            'verified_at' => $now,
        ]);
    }

    /**
     * Turn the feature off and leave a reason behind.
     *
     * @param array<string,mixed> $state
     */
    private function stop(Server $server, array $state, string $why): void
    {
        Log::warning('pz-mod-manager auto-update disabled itself', [
            'server_id' => $server->id,
            'reason' => $why,
        ]);

        $state['auto']['enabled'] = false;
        $this->save($server, $state, [
            'phase' => 'failed',
            'note' => $why,
            'failed_at' => now()->timestamp,
            'last_restart_at' => $state['run']['last_restart_at'] ?? 0,
        ]);
    }

    // -------------------------------------------------------------- detection

    /**
     * @param  array<string,mixed>  $auto
     * @return array{reason:?string,detail:array<int,string>,note:string}
     */
    public function detect(Server $server, array $auto): array
    {
        $detail = [];
        $reasons = [];

        $mods = $this->outdatedMods($server);
        $detail = array_merge($detail, $mods['detail']);
        if ($mods['ids']) {
            $reasons[] = 'mod';
        }

        if ($auto['check_game'] ?? true) {
            $game = $this->build->compare($server);
            if ($game['installed'] === null) {
                $detail[] = 'Game build: no appmanifest on disk, skipped.';
            } elseif ($game['latest'] === null) {
                $detail[] = 'Game build: installed ' . $game['installed'] . ', Steam unreachable, skipped.';
            } else {
                $detail[] = 'Game build: installed ' . $game['installed'] . ', public ' . $game['latest'] . '.';
                if ($game['outdated']) {
                    $reasons[] = 'game';
                }
            }
        }

        $reason = $reasons ? implode(' and ', $reasons) : null;

        return [
            'reason' => $reason,
            'detail' => $detail,
            'note' => $reason
                ? 'Update found: ' . $reason . '.'
                : 'Everything up to date.',
        ];
    }

    /**
     * Workshop items whose Steam publish time is newer than the files on disk.
     *
     * @return array{ids:string[],detail:array<int,string>}
     */
    private function outdatedMods(Server $server): array
    {
        $detail = [];

        $ini = $this->ini->read($server);
        if (!$ini['ok']) {
            return ['ids' => [], 'detail' => ['Config could not be read, mod check skipped.']];
        }

        $index = $this->scanner->index($server, (int) config('pz-mod-manager.fallback_build', 42));
        if (!$index['ok']) {
            return ['ids' => [], 'detail' => ['Mod scan failed, mod check skipped.']];
        }

        // Only what the server is set to load. An outdated mod sitting on disk
        // but absent from Mods= cannot cause a version mismatch for anyone.
        $enabled = array_flip($ini['mods']);
        $installedAt = [];
        foreach ($index['mods'] as $mod) {
            $workshopId = (string) ($mod['workshop_id'] ?? '');
            if ($workshopId === '' || !isset($enabled[$mod['mod_id']])) {
                continue;
            }
            $installedAt[$workshopId] = max(
                $installedAt[$workshopId] ?? 0,
                (int) ($mod['installed_at'] ?? 0)
            );
        }

        if (!$installedAt) {
            return ['ids' => [], 'detail' => ['No enabled mods with files on disk.']];
        }

        $steam = $this->steam->details(array_keys($installedAt), self::STEAM_MAX_AGE_SECONDS);
        $stale = [];
        foreach ($installedAt as $workshopId => $onDisk) {
            $onSteam = (int) ($steam[$workshopId]['updated'] ?? 0);
            if ($onDisk > 0 && $onSteam > $onDisk + self::SKEW_SECONDS) {
                $stale[] = (string) $workshopId;
            }
        }

        $detail[] = 'Checked ' . count($installedAt) . ' Workshop items, ' . count($stale) . ' outdated.';
        foreach (array_slice($stale, 0, 10) as $workshopId) {
            $name = $steam[$workshopId]['title'] ?? $workshopId;
            $detail[] = 'Outdated: ' . $name . ' (' . $workshopId . ').';
        }

        return ['ids' => $stale, 'detail' => $detail];
    }

    // ----------------------------------------------------------------- server

    /**
     * Players currently connected, or null when it cannot be established.
     *
     * The `players` console command writes "Players connected (N)" into the
     * DebugLog, so this asks and then reads. Callers must treat null as "assume
     * somebody is there": announcing to an empty server costs nothing, while
     * restarting without warning because a log read failed costs a player their
     * progress.
     */
    private function players(Server $server): ?int
    {
        try {
            $before = $this->logs->latestLogLength($server) ?? 0;
            $server->send('players');
        } catch (\Throwable $e) {
            return null;
        }

        // The answer appears within a tick or two. Six seconds inside a
        // once-a-minute job is affordable; the alternative is another phase.
        for ($waited = 0; $waited < 6_000_000; $waited += 500_000) {
            usleep(500_000);
            $count = $this->logs->latestPlayersCountSince($server, $before);
            if ($count !== null) {
                return $count;
            }
        }

        return null;
    }

    /** Broadcast to everyone in game. Never fatal: a missed message is not worth aborting a restart. */
    private function announce(Server $server, string $template, array $replace): void
    {
        $text = strtr(trim($template), $replace);
        if ($text === '') {
            return;
        }

        // `servermsg` is the only broadcast the dedicated server has. `say` does
        // not exist, and sending it fails silently, which reads exactly like a
        // working warning nobody happened to see.
        $this->say($server, 'servermsg "' . str_replace('"', "'", $text) . '"');
    }

    private function say(Server $server, string $command): void
    {
        try {
            $server->send($command);
        } catch (\Throwable $e) {
            Log::info('pz-mod-manager could not send a console command', [
                'server_id' => $server->id,
                'command' => $command,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** The last few seconds, one message a second, then the caller restarts. */
    private function countdown(Server $server, array $auto): void
    {
        $seconds = (int) $auto['countdown_seconds'];
        for ($i = $seconds; $i > 0; $i--) {
            $this->announce($server, $auto['msg_countdown'], [':seconds' => (string) $i]);
            sleep(1);
        }
    }

    // ----------------------------------------------------------------- backup

    /**
     * Kick off a panel backup and return its id.
     *
     * `override` lets the panel rotate its own oldest unlocked backup when the
     * server is at its limit, which is the difference between a backup every
     * time and a backup until the limit is reached and then never again. Locked
     * backups are left alone by the panel, so a deliberately kept one is safe.
     */
    private function startBackup(Server $server, string $reason): ?int
    {
        try {
            $backup = app(InitiateBackupService::class)->handle(
                $server,
                'Auto-update ' . $reason . ' ' . now()->format('Y-m-d H:i'),
                true
            );

            return $backup->id;
        } catch (\Throwable $e) {
            // Includes the panel's own throttle. A missing backup is a reason to
            // note it, not a reason to leave the server unable to be joined.
            Log::warning('pz-mod-manager could not start a backup', [
                'server_id' => $server->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** True once the backup is no longer running, successfully or not. */
    private function backupSettled(int $backupId): bool
    {
        $backup = Backup::find($backupId);

        return $backup === null || $backup->completed_at !== null;
    }

    // ------------------------------------------------------- egg AUTO_UPDATE

    /**
     * Whether the server re-runs steamcmd on boot.
     *
     * This is the difference between a restart that downloads the update and a
     * restart that changes nothing, so the feature refuses to run without it.
     */
    public function autoUpdateEnabled(Server $server): bool
    {
        foreach ($server->variables as $variable) {
            if ($variable->env_variable === 'AUTO_UPDATE') {
                return trim((string) ($variable->server_value ?? $variable->default_value ?? '')) === '1';
            }
        }

        // No such variable in this egg. Some images always update; assume the
        // operator knows their egg rather than blocking the feature outright.
        return true;
    }

    /** @return bool false when the egg has no AUTO_UPDATE variable to set. */
    public function enableAutoUpdateVariable(Server $server): bool
    {
        foreach ($server->variables as $variable) {
            if ($variable->env_variable === 'AUTO_UPDATE') {
                ServerVariable::updateOrCreate(
                    ['server_id' => $server->id, 'variable_id' => $variable->id],
                    ['variable_value' => '1'],
                );
                $server->load('variables');

                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ state

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $run
     */
    private function save(Server $server, array $state, array $run): void
    {
        $state['run'] = $run;
        $this->store->write($server, $state);
    }

    /** @return \Illuminate\Support\Collection<int,Server> */
    private function servers()
    {
        $match = strtolower((string) config('pz-mod-manager.egg_match', 'zomboid'));

        return Server::query()
            ->with(['egg', 'variables'])
            ->get()
            ->filter(fn (Server $server) => str_contains(strtolower((string) ($server->egg->name ?? '')), $match))
            ->values();
    }
}
