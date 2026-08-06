<?php

namespace WildBrianNL\PZModManager\Services;

use App\Repositories\Daemon\DaemonRepository;

class ConsoleCommandService extends DaemonRepository
{
    public function send(string $command): void
    {
        $this->getHttpClient()->post("/api/servers/{$this->server->uuid}/command", ['command' => $command]);
    }
}
