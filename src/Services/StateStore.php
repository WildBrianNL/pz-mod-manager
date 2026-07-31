<?php

namespace WildBrianNL\PZModManager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;

/**
 * Tiny JSON side-car stored next to the server files. Holds plugin state that
 * does not belong in the game config - currently the "activate this workshop
 * item once it finishes downloading" queue, which lets us self-heal wrong
 * mod-id guesses instead of leaving dead entries in Mods=.
 */
class StateStore
{
    private const FILE = '.pz-mod-manager.json';

    public function __construct(private DaemonFileRepository $files) {}

    /** @return array{pending:array<string,string[]>} */
    public function read(Server $server): array
    {
        try {
            $raw = (string) $this->files->setServer($server)->getContent(self::FILE, 200_000);
            $data = json_decode($raw, true);
        } catch (\Throwable $e) {
            $data = null;
        }

        return ['pending' => is_array($data['pending'] ?? null) ? $data['pending'] : []];
    }

    /** @param array{pending:array<string,string[]>} $state */
    public function write(Server $server, array $state): void
    {
        try {
            $this->files->setServer($server)->putContent(
                self::FILE,
                (string) json_encode(['pending' => $state['pending'] ?? []], JSON_PRETTY_PRINT)
            );
        } catch (\Throwable $e) {
            // Non-critical: the queue is an optimisation, not a source of truth.
        }
    }
}
