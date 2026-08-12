<?php

namespace WildBrianNL\PZModManager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;

/**
 * Tiny JSON side-car stored next to the server files. Holds plugin state that
 * does not belong in the game config:
 *
 *  - `pending`: "activate this workshop item once it finishes downloading",
 *    which lets us self-heal wrong mod-id guesses instead of leaving dead
 *    entries in Mods=.
 *  - `locks`: mod-id => load-order index that auto-sort must preserve. Not every
 *    framework declares itself as one, so some mods have to stay put by hand.
 *  - `auto`: auto-update settings, and the phase of a restart in progress.
 *  - `history`: what this plugin restarted, when, and what changed.
 *
 * The auto-update data lives here rather than in the cache on purpose. It
 * decides whether a server restarts on its own, and `artisan optimize:clear` is
 * a normal thing to run on a panel. A cleared cache must not silently turn the
 * feature back on, forget that a restart is half-finished, or lose the record of
 * an attempt that already failed.
 */
class StateStore
{
    private const FILE = '.pz-mod-manager.json';

    /**
     * How many restarts are kept.
     *
     * The side-car is read on every page load and written on every phase change,
     * so it has to stay small. Twenty covers weeks of a normal server, and the
     * point of the list is answering "what happened last night", not archiving.
     */
    public const HISTORY_MAX = 20;

    /**
     * Auto-update settings and their defaults. Everything an operator can change
     * is listed here once; read() coerces whatever is on disk to these types, so
     * a hand-edited side-car cannot feed a string into a countdown or a negative
     * number into a timer.
     */
    public const AUTO_DEFAULTS = [
        // Off until somebody turns it on. This feature restarts servers.
        'enabled' => false,
        'check_minutes' => 5,
        'warn_minutes' => 5,
        'countdown_seconds' => 10,
        'backup' => true,
        // How long a restart may wait for its backup before going ahead anyway.
        'backup_wait_seconds' => 120,
        'check_game' => true,
        // No second restart within this window, whatever else is detected.
        'cooldown_minutes' => 60,
        'msg_warn' => 'Server restart in :minutes minutes to apply a :reason update.',
        'msg_final' => 'Server restart in one minute. Find somewhere safe and log out if you can.',
        'msg_countdown' => 'Restarting in :seconds',
        'msg_back' => 'Update applied. Welcome back.',
    ];

    private const AUTO_MIN = [
        'check_minutes' => 1,
        'warn_minutes' => 0,
        'countdown_seconds' => 0,
        'backup_wait_seconds' => 0,
        'cooldown_minutes' => 0,
    ];

    private const AUTO_MAX = [
        'check_minutes' => 240,
        'warn_minutes' => 60,
        'countdown_seconds' => 60,
        'backup_wait_seconds' => 900,
        'cooldown_minutes' => 1440,
    ];

    public function __construct(private DaemonFileRepository $files) {}

    /** @return array{pending:array<string,string[]>,locks:array<string,int>,auto:array<string,mixed>,run:array<string,mixed>,history:array<int,array<string,mixed>>} */
    public function read(Server $server): array
    {
        try {
            $raw = (string) $this->files->setServer($server)->getContent(self::FILE, 200_000);
            $data = json_decode($raw, true);
        } catch (\Throwable $e) {
            $data = null;
        }

        // Validate on read: a hand-edited or truncated side-car must not be able
        // to feed a bogus index into the ordering logic.
        $locks = [];
        foreach (is_array($data['locks'] ?? null) ? $data['locks'] : [] as $modId => $index) {
            if (is_string($modId) && is_int($index) && $index >= 0) {
                $locks[$modId] = $index;
            }
        }

        return [
            'pending' => is_array($data['pending'] ?? null) ? $data['pending'] : [],
            'locks' => $locks,
            'auto' => $this->auto(is_array($data['auto'] ?? null) ? $data['auto'] : []),
            'run' => is_array($data['run'] ?? null) ? $data['run'] : [],
            'history' => $this->history(is_array($data['history'] ?? null) ? $data['history'] : []),
        ];
    }

    /**
     * Coerce the restart history into a shape the view can render blindly.
     *
     * The view walks this list to tell an operator what their server did while
     * they were asleep. A truncated write or a hand-edited file must produce a
     * shorter list, never a page that dies halfway down.
     *
     * @param  array<mixed>  $stored
     * @return array<int,array<string,mixed>>
     */
    private function history(array $stored): array
    {
        $out = [];

        foreach ($stored as $entry) {
            if (!is_array($entry) || !is_numeric($entry['at'] ?? null)) {
                continue;
            }

            $changes = [];
            foreach (is_array($entry['changes'] ?? null) ? $entry['changes'] : [] as $change) {
                if (!is_array($change)) {
                    continue;
                }
                $changes[] = [
                    'kind' => ($change['kind'] ?? '') === 'game' ? 'game' : 'mod',
                    'id' => mb_substr(trim((string) ($change['id'] ?? '')), 0, 40),
                    'name' => mb_substr(trim((string) ($change['name'] ?? '')), 0, 200),
                    'from' => mb_substr(trim((string) ($change['from'] ?? '')), 0, 80),
                    'to' => mb_substr(trim((string) ($change['to'] ?? '')), 0, 80),
                    'from_at' => (int) ($change['from_at'] ?? 0),
                    'to_at' => (int) ($change['to_at'] ?? 0),
                ];
            }

            $out[] = [
                'at' => (int) $entry['at'],
                'trigger' => ($entry['trigger'] ?? '') === 'manual' ? 'manual' : 'auto',
                'reason' => mb_substr(trim((string) ($entry['reason'] ?? '')), 0, 40),
                'by' => mb_substr(trim((string) ($entry['by'] ?? '')), 0, 60),
                'changes' => array_slice($changes, 0, 40),
                'players' => is_numeric($entry['players'] ?? null) ? (int) $entry['players'] : null,
                'backup_id' => is_numeric($entry['backup_id'] ?? null) ? (int) $entry['backup_id'] : null,
                'outcome' => in_array($entry['outcome'] ?? '', ['pending', 'verified', 'failed', 'unverified'], true)
                    ? (string) $entry['outcome']
                    : 'unverified',
                'note' => mb_substr(trim((string) ($entry['note'] ?? '')), 0, 400),
                'down' => (int) ($entry['down'] ?? 0),
            ];
        }

        // Newest first, then capped. Sorting before slicing matters: a file
        // written in the wrong order must not lose its most recent entries.
        usort($out, fn ($a, $b) => $b['at'] <=> $a['at']);

        return array_slice($out, 0, self::HISTORY_MAX);
    }

    /**
     * Put a restart at the top of the history and return the new list.
     *
     * @param  array<int,array<string,mixed>>  $history
     * @param  array<string,mixed>  $entry
     * @return array<int,array<string,mixed>>
     */
    public function remember(array $history, array $entry): array
    {
        array_unshift($history, $entry);

        return $this->history($history);
    }

    /**
     * Coerce stored auto-update settings onto the defaults.
     *
     * Numbers are clamped rather than rejected. A warn_minutes of 100000 read
     * back verbatim would schedule a restart eleven weeks out and look like the
     * feature was simply broken.
     *
     * @param  array<string,mixed>  $stored
     * @return array<string,mixed>
     */
    private function auto(array $stored): array
    {
        $out = self::AUTO_DEFAULTS;

        foreach ($out as $key => $default) {
            if (!array_key_exists($key, $stored)) {
                continue;
            }
            $value = $stored[$key];

            if (is_bool($default)) {
                $out[$key] = (bool) $value;
            } elseif (is_int($default)) {
                if (!is_numeric($value)) {
                    continue;
                }
                $out[$key] = max(
                    self::AUTO_MIN[$key] ?? 0,
                    min(self::AUTO_MAX[$key] ?? PHP_INT_MAX, (int) $value)
                );
            } elseif (is_string($value)) {
                // An empty message means "say nothing", which is a legitimate
                // choice, so only the type is enforced here.
                $out[$key] = mb_substr(trim($value), 0, 400);
            }
        }

        return $out;
    }

    /**
     * Both keys are always written from the passed state, so a caller that only
     * touched one of them must pass the other through unchanged. read(), mutate,
     * write() is the intended flow.
     *
     * @param array{pending?:array<string,string[]>,locks?:array<string,int>,auto?:array<string,mixed>,run?:array<string,mixed>,history?:array<int,array<string,mixed>>} $state
     */
    public function write(Server $server, array $state): void
    {
        try {
            $this->files->setServer($server)->putContent(
                self::FILE,
                (string) json_encode([
                    'pending' => $state['pending'] ?? [],
                    'locks' => $state['locks'] ?? [],
                    // Coerced on the way out as well as the way in. Reading is
                    // what protects the code, but a file holding warn_minutes
                    // 99999 while the plugin quietly uses 60 is a file that lies
                    // to whoever opens it.
                    'auto' => $this->auto($state['auto'] ?? []),
                    'run' => $state['run'] ?? [],
                    'history' => $this->history($state['history'] ?? []),
                ], JSON_PRETTY_PRINT)
            );
        } catch (\Throwable $e) {
            // Non-critical: the queue is an optimisation, not a source of truth.
        }
    }
}
