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

    /**
     * Longest the Restart button waits for its backup before going ahead.
     *
     * Short on purpose, and then clamped again against whatever PHP allows the
     * request. The panel image ships `max_execution_time = 30` on php-fpm, so a
     * flat thirty second sleep here would eat the entire budget and turn a slow
     * backup into a 500 on the Restart button. The automatic path has a
     * scheduler and no request timeout, and keeps its own `backup_wait_seconds`.
     */
    private const MANUAL_BACKUP_WAIT_SECONDS = 12;

    /** Left for everything else in the request: the restart call, and rendering. */
    private const REQUEST_HEADROOM_SECONDS = 15;

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
        $servers = $this->servers();
        $this->warmSteam($servers);

        foreach ($servers as $server) {
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

    /**
     * One server's turn. Public so a single server can be driven directly, by a
     * test or by an operator, without waiting for the panel's scheduler.
     */
    public function tickServer(Server $server): void
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
        // Carried so the page can colour the status by it. A check that could
        // not reach Steam must not be shown in the same green, with the same
        // tick, as a check that compared everything and found nothing.
        $run['degraded'] = $found['degraded'];

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
            'stale_ids' => $found['ids'],
            // The "from" side of the history, captured now. After the restart
            // the old version numbers are gone from disk and unrecoverable.
            'stale_before' => $found['stale'] ?? [],
            'build_before' => $found['build'],
            'last_restart_at' => $run['last_restart_at'] ?? 0,
            'note' => $found['note'],
        ];

        // Flushing the world before the snapshot means the backup holds a saved
        // game rather than whatever was in memory when the copy started.
        if ($auto['backup']) {
            $this->say($server, 'save');
            $run['backup_id'] = $this->startBackup($server, 'Auto-update ' . $found['reason']);
        }

        if ($warnMinutes > 0) {
            $this->announce($server, $auto['msg_warn'], $found['reason'], $warnMinutes, $warnMinutes * 60);
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
                $this->announce($server, $auto['msg_final'], (string) ($run['reason'] ?? ''), 1, 60);
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

        $this->countdown($server, $auto, (string) ($run['reason'] ?? ''));

        // Recorded before the restart is sent, not after it is verified. If the
        // panel goes down in between, the operator still sees that a restart was
        // attempted rather than a gap in the list.
        $state['history'] = $this->store->remember($state['history'] ?? [], [
            'at' => $now,
            'trigger' => 'auto',
            'reason' => (string) ($run['reason'] ?? ''),
            'changes' => $this->changesBefore($run),
            'players' => $run['players_at_start'] ?? null,
            'backup_id' => $run['backup_id'] ?? null,
            'outcome' => 'pending',
        ]);

        $run = [
            'phase' => 'verifying',
            'reason' => $run['reason'],
            'detail' => $run['detail'] ?? [],
            'restarted_at' => $now,
            'verify_after' => $now + self::VERIFY_GRACE_MINUTES * 60,
            'verify_before' => $now + self::VERIFY_DEADLINE_MINUTES * 60,
            'last_restart_at' => $now,
            // Carried across the phase change, not rebuilt. This array used to
            // be assembled from scratch here, which dropped stale_ids and left
            // verification falling back to "is anything outdated at all" - the
            // exact test 2.5.1 replaced. The fix was real, the phase transition
            // quietly undid it, and only a test that hand-built the verifying
            // state kept passing.
            'stale_ids' => $run['stale_ids'] ?? [],
            'stale_before' => $run['stale_before'] ?? [],
            'build_before' => $run['build_before'] ?? null,
            'note' => 'Restarted to apply a ' . $run['reason'] . ' update. Verifying.',
        ];
        $this->save($server, $state, $run);

        $this->power->setServer($server)->send('restart');
    }

    /**
     * The "from" half of a history entry, from what detection saw beforehand.
     *
     * A mod is named by its `modversion` when it declares one, and by the
     * timestamps when it does not, which is most of them. Never both, and never
     * a version invented to fill the column.
     *
     * @param  array<string,mixed>  $run
     * @return array<int,array<string,mixed>>
     */
    private function changesBefore(array $run): array
    {
        $changes = [];

        foreach (is_array($run['stale_before'] ?? null) ? $run['stale_before'] : [] as $row) {
            $changes[] = [
                'kind' => 'mod',
                'id' => (string) ($row['id'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'from' => (string) ($row['version'] ?? ''),
                'from_at' => (int) ($row['at'] ?? 0),
            ];
        }

        if (str_contains((string) ($run['reason'] ?? ''), 'game') && ($run['build_before'] ?? null) !== null) {
            $changes[] = [
                'kind' => 'game',
                'name' => 'Project Zomboid build',
                'from' => (string) $run['build_before'],
            ];
        }

        return $changes;
    }

    /**
     * Fill in the "to" half and the outcome of the newest history entry.
     *
     * Matched on the timestamp the restart was issued rather than on position,
     * because a manual restart during verification would otherwise push the
     * automatic one down and get the result written onto the wrong row.
     *
     * @param  array<string,mixed>  $state
     * @param  array<string,mixed>  $run
     * @param  array<string,mixed>  $found
     * @return array<int,array<string,mixed>>
     */
    private function settleHistory(Server $server, array $state, array $run, string $outcome, string $note, array $found = []): array
    {
        $at = (int) ($run['restarted_at'] ?? 0);
        $history = is_array($state['history'] ?? null) ? $state['history'] : [];
        $after = $outcome === 'verified' ? $this->snapshot($server, array_keys($run['stale_before'] ?? [])) : [];

        foreach ($history as $i => $entry) {
            if ((int) ($entry['at'] ?? 0) !== $at || ($entry['outcome'] ?? '') !== 'pending') {
                continue;
            }

            foreach ($entry['changes'] ?? [] as $j => $change) {
                if (($change['kind'] ?? '') === 'game') {
                    $history[$i]['changes'][$j]['to'] = (string) ($found['build'] ?? '');

                    continue;
                }
                $now = $after[$change['id'] ?? ''] ?? null;
                if ($now === null) {
                    continue;
                }
                $history[$i]['changes'][$j]['to'] = (string) ($now['version'] ?? '');
                $history[$i]['changes'][$j]['to_at'] = (int) ($now['at'] ?? 0);
            }

            $history[$i]['outcome'] = $outcome;
            $history[$i]['note'] = $note;
            // Only on success. The view renders this as "back up in 12m", and
            // the commonest failure is the server never coming back at all, so
            // on that row the number would be measuring the wait for something
            // that did not happen.
            $history[$i]['down'] = $outcome === 'verified' ? max(0, now()->timestamp - $at) : 0;
            break;
        }

        return $history;
    }

    /**
     * Name, declared version and file timestamp for the given Workshop items,
     * as they are on disk right now.
     *
     * @param  string[]  $workshopIds
     * @return array<string,array<string,mixed>>
     */
    private function snapshot(Server $server, array $workshopIds): array
    {
        if (!$workshopIds) {
            return [];
        }

        try {
            $index = $this->scanner->index($server, (int) config('pz-mod-manager.fallback_build', 42));
        } catch (\Throwable $e) {
            return [];
        }

        $wanted = array_flip($workshopIds);
        $out = [];
        foreach ($index['mods'] ?? [] as $mod) {
            $workshopId = (string) ($mod['workshop_id'] ?? '');
            if (!isset($wanted[$workshopId])) {
                continue;
            }
            $out[$workshopId] = [
                'version' => ($mod['version'] ?? '') ?: null,
                'at' => max((int) ($out[$workshopId]['at'] ?? 0), (int) ($mod['installed_at'] ?? 0)),
            ];
        }

        return $out;
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

        // Only the items this restart was for. A mod that updated while the
        // server was coming back is a reason for the next cycle, not evidence
        // that this one failed: checking "is anything outdated" turned a second
        // update arriving mid-restart into a false failure that disabled the
        // whole feature.
        // State written by 2.5.0 has no stale_ids. Fall back to the old
        // "is anything outdated" test rather than passing everything blindly.
        $stillStale = array_key_exists('stale_ids', $run)
            ? array_values(array_intersect($found['ids'] ?? [], $run['stale_ids']))
            : ($found['ids'] ?? []);

        $gameStuck = str_contains((string) ($run['reason'] ?? ''), 'game')
            && $found['build'] !== null
            && $found['build'] === ($run['build_before'] ?? null);

        if ($stillStale || $gameStuck) {
            // Nothing moved, so restarting again would not move it either.
            // Usually AUTO_UPDATE is off, or a Workshop item was pulled.
            $this->stop(
                $server,
                $state,
                'Restarted, but ' . ($gameStuck ? 'the game build' : implode(', ', $stillStale))
                . ' is still not updated. Auto-restart is off so the server is not restarted again.'
            );

            return;
        }

        $this->announce($server, $state['auto']['msg_back'], (string) ($run['reason'] ?? ''), 0, 0);

        $state['history'] = $this->settleHistory($server, $state, $run, 'verified', '', $found);

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
        // A restart that did not work is the entry an operator most wants to
        // find in the morning, so it is closed off with its reason rather than
        // left sitting at "pending" forever.
        $state['history'] = $this->settleHistory($server, $state, $state['run'] ?? [], 'failed', $why);
        $this->save($server, $state, [
            'phase' => 'failed',
            'note' => $why,
            'failed_at' => now()->timestamp,
            'last_restart_at' => $state['run']['last_restart_at'] ?? 0,
        ]);
    }

    // ----------------------------------------------------------------- manual

    /**
     * The Restart button on the Mods page: back up if that is switched on,
     * restart, and leave a record of it.
     *
     * The backup setting is deliberately shared with the automatic path. An
     * operator who ticked "back up before restarting" means it whoever presses
     * the button, and a manual restart to apply a mod is exactly as capable of
     * eating a world as an automatic one.
     *
     * Unlike the automatic path this waits only briefly. It runs inside a page
     * request, so it sends `save`, gives the snapshot a short head start and
     * then restarts either way. The world is already flushed to disk by then,
     * which is the part that matters.
     *
     * @return array{restarted:bool,wanted_backup:bool,backup_id:?int,backup_done:bool}
     */
    public function restartManually(Server $server, string $why, string $by): array
    {
        $state = $this->store->read($server);
        $wanted = (bool) ($state['auto']['backup'] ?? true);
        $backupId = null;
        $settled = false;

        if ($wanted) {
            $this->say($server, 'save');
            $backupId = $this->startBackup($server, 'Before restart');

            // A panel that allows the request less time than we want to wait
            // gets less waiting, not a half-finished request. Zero means no
            // limit, which is the CLI, where the full window is fine.
            $budget = (int) ini_get('max_execution_time');
            $allowed = $budget > 0
                ? max(0, min(self::MANUAL_BACKUP_WAIT_SECONDS, $budget - self::REQUEST_HEADROOM_SECONDS))
                : self::MANUAL_BACKUP_WAIT_SECONDS;

            $waited = 0;
            while ($backupId !== null && $waited < $allowed) {
                if ($this->backupSettled($backupId)) {
                    $settled = true;
                    break;
                }
                sleep(2);
                $waited += 2;
            }
        }

        // Restart first, record second. The automatic path writes its entry
        // beforehand because the panel may not survive to write it afterwards,
        // but here the caller is a page request that can catch the failure, and
        // a history claiming a restart that never went out is worse than none.
        $this->power->setServer($server)->send('restart');

        $state['history'] = $this->store->remember($state['history'] ?? [], [
            'at' => now()->timestamp,
            'trigger' => 'manual',
            'reason' => 'manual',
            'by' => $by,
            'changes' => [['kind' => 'mod', 'name' => $why]],
            'backup_id' => $backupId,
            // Nothing verifies a manual restart, and calling it verified would
            // be a lie told in a column an operator is meant to trust.
            'outcome' => 'unverified',
        ]);
        $this->store->write($server, $state);

        return ['restarted' => true, 'wanted_backup' => $wanted, 'backup_id' => $backupId, 'backup_done' => $settled];
    }

    // -------------------------------------------------------------- detection

    /**
     * What, if anything, this server is behind on.
     *
     * `$fresh` is the Check now button. It refetches everything instead of
     * reusing a cached answer, because the reason somebody presses that button
     * is almost always that they know an update exists and want to see it. The
     * scheduler never passes it: caching is what keeps a five-minute interval
     * across a whole panel from hammering Steam.
     *
     * @param  array<string,mixed>  $auto
     * @return array{reason:?string,detail:array<int,string>,ids:string[],idle:array<int,array<string,mixed>>,build:?int,degraded:bool,note:string}
     */
    public function detect(Server $server, array $auto, bool $fresh = false): array
    {
        $reasons = [];

        $game = [];
        $mods = $this->outdatedMods($server, $fresh);
        $detail = $mods['detail'];
        $degraded = $mods['degraded'];
        if ($mods['ids']) {
            $reasons[] = 'mod';
        }

        if ($auto['check_game'] ?? true) {
            $game = $this->build->compare($server, $fresh);
            if ($game['installed'] === null) {
                $detail[] = 'Game build: no appmanifest on disk, skipped.';
                $degraded = true;
            } elseif ($game['latest'] === null) {
                $detail[] = 'Game build: installed ' . $game['installed'] . ', Steam unreachable, skipped.';
                $degraded = true;
            } else {
                $detail[] = 'Game build: installed ' . $game['installed'] . ', public ' . $game['latest'] . '.';
                if ($game['outdated']) {
                    $reasons[] = 'game';
                }
            }
        }

        $reason = $reasons ? implode(' and ', $reasons) : null;

        // Three outcomes, not two. "Nothing found" and "we could not look" used
        // to produce the same sentence, which is how Check now came to report
        // everything up to date while a restart went on to download updates.
        if ($reason !== null) {
            $note = 'Update found: ' . $reason . '.';
        } elseif ($degraded) {
            $note = 'Could not check everything. Some of this server was not compared against Steam.';
        } else {
            $note = 'Everything up to date.';
        }

        return [
            'reason' => $reason,
            'detail' => $detail,
            // Which items, not just that there were some: verification has to
            // ask about the ones it restarted for and ignore anything that
            // showed up since.
            'ids' => $mods['ids'],
            // Outdated, but not loaded by anybody. Reported, never restarted for.
            'idle' => $mods['idle'],
            // Per outdated enabled item: name, version and timestamp as they are
            // now. The history needs the "from" side captured before the restart.
            'stale' => $mods['stale'],
            'build' => $game['installed'] ?? null,
            'degraded' => $degraded,
            'note' => $note,
        ];
    }

    /**
     * Workshop items whose Steam publish time is newer than the files on disk.
     *
     * Every id in WorkshopItems= is compared, not only the ones backing a mod in
     * Mods=. SteamCMD downloads the whole WorkshopItems list on boot, so limiting
     * the check to enabled mods produced exactly the complaint this release
     * exists for: the check reports nothing, the restart downloads something.
     *
     * The two lists are kept apart on purpose. An outdated item nobody loads
     * cannot lock a player out, so it is worth telling an operator about and not
     * worth taking a server down for.
     *
     * @return array{ids:string[],idle:array<int,array<string,mixed>>,stale:array<string,array<string,mixed>>,detail:array<int,string>,degraded:bool}
     */
    private function outdatedMods(Server $server, bool $fresh = false): array
    {
        $blank = ['ids' => [], 'idle' => [], 'stale' => [], 'degraded' => true];

        $ini = $this->ini->read($server);
        if (!$ini['ok']) {
            return $blank + ['detail' => ['Config could not be read, mod check skipped.']];
        }

        $index = $this->scanner->index($server, (int) config('pz-mod-manager.fallback_build', 42));
        if (!$index['ok']) {
            return $blank + ['detail' => ['Mod scan failed, mod check skipped.']];
        }

        $enabled = array_flip($ini['mods']);

        // Per Workshop item: when its files were last written, whether any mod
        // inside it is enabled, and what it calls itself.
        $items = [];
        foreach ($index['mods'] as $mod) {
            $workshopId = (string) ($mod['workshop_id'] ?? '');
            if ($workshopId === '') {
                continue;
            }
            $on = isset($enabled[$mod['mod_id']]);
            $item = $items[$workshopId] ?? ['at' => 0, 'enabled' => false, 'name' => '', 'version' => null];
            $items[$workshopId] = [
                'at' => max($item['at'], (int) ($mod['installed_at'] ?? 0)),
                'enabled' => $item['enabled'] || $on,
                // Name and version come from an enabled mod when there is one,
                // so the history names what the operator actually runs.
                'name' => ($on || $item['name'] === '') ? (string) ($mod['name'] ?? '') : $item['name'],
                'version' => ($on || $item['name'] === '') ? (($mod['version'] ?? '') ?: null) : $item['version'],
            ];
        }

        // Listed in the config but with no files on disk yet. Not outdated, it
        // has simply never been downloaded, and that is a different alert.
        $listed = array_values(array_unique(array_filter($ini['workshopItems'])));
        $missing = array_values(array_diff($listed, array_keys($items)));

        if (!$items) {
            // Nothing to compare is not the same as failing to compare: a server
            // that has never downloaded a mod is genuinely up to date.
            return ['ids' => [], 'idle' => [], 'stale' => [], 'degraded' => false,
                'detail' => ['No Workshop items with files on disk yet.']];
        }

        if ($fresh) {
            $this->steam->clearBackoff();
        }
        $steam = $this->steam->details(array_keys($items), $fresh ? 0 : self::STEAM_MAX_AGE_SECONDS);
        $degraded = $this->steam->degraded();

        $ids = [];
        $idle = [];
        $stale = [];
        foreach ($items as $workshopId => $item) {
            $onSteam = (int) ($steam[$workshopId]['updated'] ?? 0);
            if ($item['at'] <= 0 || $onSteam <= $item['at'] + self::SKEW_SECONDS) {
                continue;
            }

            $row = [
                'id' => (string) $workshopId,
                'name' => $item['name'] !== '' ? $item['name'] : (string) ($steam[$workshopId]['title'] ?? $workshopId),
                'version' => $item['version'],
                'at' => $item['at'],
                'steam_at' => $onSteam,
            ];

            if ($item['enabled']) {
                $ids[] = (string) $workshopId;
                $stale[(string) $workshopId] = $row;
            } else {
                $idle[] = $row;
            }
        }

        $detail = ['Checked ' . count($items) . ' of ' . max(count($listed), count($items))
            . ' Workshop items, ' . count($ids) . ' outdated and enabled, ' . count($idle) . ' outdated but not enabled.'];

        if ($degraded) {
            $detail[] = 'Steam could not be reached, so some of this is from an older answer.';
        }
        if ($missing) {
            $detail[] = 'Not downloaded yet: ' . implode(', ', array_slice($missing, 0, 10)) . '.';
        }
        foreach (array_slice($stale, 0, 10) as $row) {
            $detail[] = 'Outdated: ' . $row['name'] . ' (' . $row['id'] . ').';
        }
        foreach (array_slice($idle, 0, 5) as $row) {
            $detail[] = 'Outdated but not in Mods=, no restart needed: ' . $row['name'] . ' (' . $row['id'] . ').';
        }

        return [
            'ids' => $ids,
            'idle' => $idle,
            'stale' => $stale,
            'detail' => $detail,
            'degraded' => $degraded,
        ];
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

    /**
     * Broadcast to everyone in game.
     *
     * Every message gets every placeholder, whichever phase it belongs to. The
     * settings panel offers `:minutes`, `:seconds` and `:reason` without saying
     * which message may use which, and a placeholder that is not substituted is
     * not a silent no-op: the literal text ":reason" goes out to every player on
     * the server.
     *
     * Never fatal. A missed message is not worth aborting a restart over.
     */
    private function announce(Server $server, string $template, string $reason, int $minutes, int $seconds): void
    {
        $text = strtr(trim($template), [
            ':minutes' => (string) $minutes,
            ':seconds' => (string) $seconds,
            ':reason' => $reason,
        ]);
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
    private function countdown(Server $server, array $auto, string $reason): void
    {
        $seconds = (int) $auto['countdown_seconds'];
        for ($i = $seconds; $i > 0; $i--) {
            $this->announce($server, $auto['msg_countdown'], $reason, 0, $i);
            sleep(1);
        }
    }

    // ----------------------------------------------------------------- backup

    /**
     * Kick off a backup of this game server and return its id.
     *
     * The same service the panel's own Backups button calls, with the same
     * server, so the result is an ordinary server backup in the same list and
     * against the same limit. Nothing here backs up the panel.
     *
     * `override` lets the panel rotate its own oldest unlocked backup when the
     * server is at its limit, which is the difference between a backup every
     * time and a backup until the limit is reached and then never again. Locked
     * backups are left alone by the panel, so a deliberately kept one is safe.
     */
    private function startBackup(Server $server, string $label): ?int
    {
        try {
            $backup = app(InitiateBackupService::class)->handle(
                $server,
                $label . ' ' . now()->format('Y-m-d H:i'),
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

    /**
     * One Steam request for the whole panel, before any server is ticked.
     *
     * The API takes fifty ids per call, so a panel with six Project Zomboid
     * servers would otherwise make six calls where one will do, and servers
     * sharing a mod would each ask about it separately. The per-server checks
     * then read a warm cache and contact Steam not at all.
     *
     * @param \Illuminate\Support\Collection<int,Server> $servers
     */
    private function warmSteam($servers): void
    {
        $ids = [];
        foreach ($servers as $server) {
            try {
                $state = $this->store->read($server);
                if (!($state['auto']['enabled'] ?? false)) {
                    continue;
                }

                // Only servers whose next check is actually due. The tick runs
                // every minute while a server is checked every five, so warming
                // on every tick refetched everything four times over for no
                // reader. On a server with 126 Workshop items that is three
                // Steam requests a minute, which is how a keyless, IP rate
                // limited endpoint starts refusing, and a refused endpoint is
                // how the page ends up saying it could not check anything.
                if (now()->timestamp < (int) ($state['run']['next_check_at'] ?? 0)) {
                    continue;
                }
                // Every item on disk, matching the check itself. This used to
                // warm only the enabled ones, which left the rest of the check
                // making its own call per server the moment the check started
                // covering all of WorkshopItems=.
                $index = $this->scanner->index($server, (int) config('pz-mod-manager.fallback_build', 42));
                foreach ($index['mods'] ?? [] as $mod) {
                    $workshopId = (string) ($mod['workshop_id'] ?? '');
                    if ($workshopId !== '') {
                        $ids[$workshopId] = true;
                    }
                }
            } catch (\Throwable $e) {
                // A server that cannot be read here is handled, and reported,
                // when its own tick runs.
                continue;
            }
        }

        if ($ids) {
            $this->steam->details(array_keys($ids), self::STEAM_MAX_AGE_SECONDS);
        }
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
