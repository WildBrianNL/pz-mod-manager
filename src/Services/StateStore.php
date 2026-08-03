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
 */
class StateStore
{
    private const FILE = '.pz-mod-manager.json';

    public function __construct(private DaemonFileRepository $files) {}

    /** @return array{pending:array<string,string[]>,locks:array<string,int>} */
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
        ];
    }

    /**
     * Both keys are always written from the passed state, so a caller that only
     * touched one of them must pass the other through unchanged. read(), mutate,
     * write() is the intended flow.
     *
     * @param array{pending?:array<string,string[]>,locks?:array<string,int>} $state
     */
    public function write(Server $server, array $state): void
    {
        try {
            $this->files->setServer($server)->putContent(
                self::FILE,
                (string) json_encode([
                    'pending' => $state['pending'] ?? [],
                    'locks' => $state['locks'] ?? [],
                ], JSON_PRETTY_PRINT)
            );
        } catch (\Throwable $e) {
            // Non-critical: the queue is an optimisation, not a source of truth.
        }
    }
}
