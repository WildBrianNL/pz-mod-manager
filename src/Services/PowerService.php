<?php

namespace WildBrianNL\PZModManager\Services;

use App\Repositories\Daemon\DaemonRepository;

/** Sends power signals (restart) straight to Wings. */
class PowerService extends DaemonRepository
{
    public function send(string $action): void
    {
        $this->getHttpClient()->post("/api/servers/{$this->server->uuid}/power", ['action' => $action]);
    }
}
