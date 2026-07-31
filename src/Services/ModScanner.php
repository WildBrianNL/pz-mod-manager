<?php

namespace WildBrianNL\PZModManager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Builds an index of the mods actually installed on the node.
 *
 * Project Zomboid workshop items look like:
 *   <workshopId>/mods/<folder>/mod.info            (classic layout)
 *   <workshopId>/mods/<folder>/common/mod.info     (shared metadata, B42)
 *   <workshopId>/mods/<folder>/42.14/mod.info      (build-specific, B42)
 *
 * Older versions of this plugin only looked at the first two shapes, so every
 * mod shipping build folders was reported as "not downloaded" forever. We now
 * discover every mod.info in one Wings search call and pick the best variant
 * for the target build.
 */
class ModScanner
{
    public const APP_ID = '108600';

    /** Wings caps search results; above this we fall back to per-item searches. */
    private const SEARCH_CAP = 95;

    public function __construct(private DaemonFileRepository $files) {}

    /**
     * @return array{mods:array<int,array<string,mixed>>,fingerprint:string,ok:bool}
     */
    public function index(Server $server, int $targetBuild = 42): array
    {
        $base = '/steamapps/workshop/content/' . self::APP_ID;
        $repo = $this->files->setServer($server);

        try {
            $hits = $repo->search('mod.info', $base);
        } catch (\Throwable $e) {
            return ['mods' => [], 'fingerprint' => 'error', 'ok' => false];
        }

        if (count($hits) >= self::SEARCH_CAP) {
            $hits = $this->searchPerItem($repo, $base) ?: $hits;
        }

        $groups = [];
        $fingerprint = [];
        foreach ($hits as $hit) {
            $path = (string) ($hit['name'] ?? '');
            if (!preg_match('#/content/' . self::APP_ID . '/(\d+)/mods/([^/]+)(?:/([^/]+))?/mod\.info$#', $path, $m)) {
                continue;
            }
            [$workshopId, $folder, $variant] = [$m[1], $m[2], $m[3] ?? ''];
            $modified = (string) ($hit['modified'] ?? '');
            $groups[$workshopId . '|' . $folder][$variant] = ['path' => $path, 'modified' => $modified];
            $fingerprint[] = $path . ':' . $modified;
        }

        sort($fingerprint);
        $print = md5(implode('|', $fingerprint));

        // Parsing needs one file read per mod, so only redo it when the files
        // on disk actually changed. This makes a download show up immediately
        // without ever serving stale data.
        $mods = Cache::remember(
            "pzmm:index:{$server->id}:{$print}",
            now()->addDays(7),
            fn () => $this->parseGroups($repo, $groups, $targetBuild)
        );

        return ['mods' => $mods, 'fingerprint' => $print, 'ok' => true];
    }

    /** @return array<int,array<string,mixed>> */
    private function parseGroups(DaemonFileRepository $repo, array $groups, int $targetBuild): array
    {
        $maps = $this->mapFolders($repo);
        $out = [];

        foreach ($groups as $key => $variants) {
            [$workshopId, $folder] = explode('|', $key, 2);

            $chosen = $this->pickVariant(array_keys($variants), $targetBuild);
            $pick = $variants[$chosen] ?? reset($variants);
            $info = $this->readInfo($repo, $pick['path']);
            if ($info === null) {
                continue;
            }

            $installedAt = 0;
            foreach ($variants as $variant) {
                $installedAt = max($installedAt, strtotime($variant['modified']) ?: 0);
            }

            $builds = [];
            foreach (array_keys($variants) as $variant) {
                if (preg_match('/^(\d{2})(?:\.\d+)*$/', $variant, $m)) {
                    $builds[] = (int) $m[1];
                }
            }

            $modId = $this->field($info, 'id') ?: $folder;

            $out[] = [
                'mod_id' => $modId,
                'folder' => $folder,
                'workshop_id' => $workshopId,
                'name' => $this->field($info, 'name') ?: $folder,
                'require' => $this->requirements($info),
                'version' => $this->field($info, 'modversion'),
                'installed_at' => $installedAt,
                'builds' => array_values(array_unique($builds)),
                'variants' => array_values(array_filter(array_keys($variants))),
                'maps' => $maps[$workshopId] ?? [],
            ];
        }

        usort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $out;
    }

    /** Prefer the newest folder for the target build, else common, else root. */
    private function pickVariant(array $variants, int $targetBuild): string
    {
        $versions = [];
        foreach ($variants as $variant) {
            if (preg_match('/^(\d{2})(?:\.\d+)*$/', $variant, $m) && (int) $m[1] === $targetBuild) {
                $versions[] = $variant;
            }
        }
        if ($versions) {
            usort($versions, 'version_compare');

            return end($versions);
        }
        if (in_array('common', $variants, true)) {
            return 'common';
        }
        if (in_array('', $variants, true)) {
            return '';
        }

        return (string) reset($variants);
    }

    /** @return array<string,string[]> workshopId => map folder names */
    private function mapFolders(DaemonFileRepository $repo): array
    {
        $out = [];
        try {
            foreach ($repo->search('map.info', '/steamapps/workshop/content/' . self::APP_ID) as $hit) {
                $path = (string) ($hit['name'] ?? '');
                if (preg_match('#/content/' . self::APP_ID . '/(\d+)/.*/media/maps/([^/]+)/map\.info$#', $path, $m)) {
                    $out[$m[1]][] = $m[2];
                }
            }
        } catch (\Throwable $e) {
            // Map detection is a nice-to-have.
        }

        foreach ($out as $id => $names) {
            $out[$id] = array_values(array_unique($names));
        }

        return $out;
    }

    private function searchPerItem(DaemonFileRepository $repo, string $base): array
    {
        $all = [];
        try {
            foreach ($repo->getDirectory($base) as $entry) {
                if (empty($entry['directory'])) {
                    continue;
                }
                try {
                    $all = array_merge($all, $repo->search('mod.info', $base . '/' . $entry['name']));
                } catch (\Throwable $e) {
                    continue;
                }
            }
        } catch (\Throwable $e) {
            return [];
        }

        return $all;
    }

    private function readInfo(DaemonFileRepository $repo, string $path): ?string
    {
        try {
            return (string) $repo->getContent($path, 300_000);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function field(string $info, string $key): ?string
    {
        foreach (preg_split('/\r\n|\r|\n/', $info) as $line) {
            $line = trim($line);
            if (stripos($line, $key . '=') === 0) {
                return trim(substr($line, strlen($key) + 1));
            }
        }

        return null;
    }

    /**
     * mod.info dependencies are written as `require=\\ModA,\\ModB,` - with a
     * leading backslash and often a trailing comma. Normalise to bare ids,
     * otherwise every dependency check raises a false alarm.
     *
     * @return string[]
     */
    private function requirements(string $info): array
    {
        $raw = (string) $this->field($info, 'require');
        $parts = array_map(fn ($p) => trim($p, " \t\\/"), explode(',', $raw));

        return array_values(array_filter($parts, fn ($p) => $p !== ''));
    }
}
