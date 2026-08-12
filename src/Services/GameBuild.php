<?php

namespace WildBrianNL\PZModManager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Answers one question: is the installed dedicated server older than the build
 * Steam is currently handing out?
 *
 * The installed side is exact. SteamCMD leaves an `appmanifest_<appid>.acf`
 * beside the game files with the `buildid` it last downloaded, and that file is
 * readable through the same Wings file API the rest of this plugin uses.
 *
 * The public side has no official source. Valve does not publish the current
 * buildid of an app through the Web API without authenticating as an owner, so
 * this asks api.steamcmd.net, a community mirror of `app_info_print`. That is a
 * third party, which is why every failure here returns null and is treated as
 * "no information" rather than "no update": a server must never be restarted on
 * the word of an endpoint that might simply be down.
 */
class GameBuild
{
    /** Project Zomboid dedicated server. Overridden per server by SRCDS_APPID. */
    public const DEFAULT_APP_ID = '380870';

    private const INFO = 'https://api.steamcmd.net/v1/info/';

    public function __construct(private DaemonFileRepository $files) {}

    /** The Steam app the server actually installs, straight from its egg variables. */
    public function appId(Server $server): string
    {
        foreach ($server->variables as $variable) {
            if ($variable->env_variable === 'SRCDS_APPID') {
                $value = trim((string) ($variable->server_value ?? $variable->default_value ?? ''));
                if ($value !== '' && ctype_digit($value)) {
                    return $value;
                }
            }
        }

        return self::DEFAULT_APP_ID;
    }

    /** Build id currently on disk, or null when the manifest is missing or unreadable. */
    public function installed(Server $server, ?string $appId = null): ?int
    {
        $appId ??= $this->appId($server);

        try {
            $raw = (string) $this->files->setServer($server)
                ->getContent("/steamapps/appmanifest_{$appId}.acf", 500_000);
        } catch (\Throwable $e) {
            return null;
        }

        // The acf format is Valve's own key-value text: "buildid" "24574884"
        if (preg_match('/"buildid"\s+"(\d+)"/', $raw, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Build id on the public branch, or null when it cannot be established.
     *
     * Cached for a few minutes so a five-minute check interval does not hammer a
     * volunteer-run service, and negative results are not cached at all, so a
     * brief outage does not blind the check for the rest of the hour.
     *
     * `$fresh` skips the cache read. It is for the Check now button and nothing
     * else: somebody who presses it has usually just watched an update land and
     * wants an answer about now, not about up to ten minutes ago.
     */
    public function latest(string $appId, bool $fresh = false): ?int
    {
        $key = "pzmm:build:$appId";
        $cached = Cache::get($key);
        if (!$fresh && is_int($cached)) {
            return $cached;
        }

        try {
            $json = Http::timeout(8)
                ->withHeaders(['User-Agent' => 'PelicanPZModManager/2.5'])
                ->get(self::INFO . $appId)
                ->json();
        } catch (\Throwable $e) {
            return null;
        }

        $build = $json['data'][$appId]['depots']['branches']['public']['buildid'] ?? null;
        if (!is_numeric($build)) {
            return null;
        }

        Cache::put($key, (int) $build, now()->addMinutes(10));

        return (int) $build;
    }

    /**
     * @return array{outdated:bool,installed:?int,latest:?int}
     *         `outdated` is only ever true when both numbers are known.
     */
    public function compare(Server $server, bool $fresh = false): array
    {
        $appId = $this->appId($server);
        $installed = $this->installed($server, $appId);
        $latest = $this->latest($appId, $fresh);

        return [
            'outdated' => $installed !== null && $latest !== null && $latest > $installed,
            'installed' => $installed,
            'latest' => $latest,
        ];
    }
}
