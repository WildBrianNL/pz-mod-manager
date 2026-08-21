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
     * Workshop ids SteamCMD believes it has installed, whatever is on disk.
     *
     * This is the list that decides what comes back after a restart, and it is
     * not the same list as `WorkshopItems=` or as the folders on disk. Anything
     * in here that the server is not configured to run is a mod that deletes
     * itself back onto the server every boot.
     *
     * @return string[]
     */
    public function installedManifest(Server $server): array
    {
        $path = '/steamapps/workshop/appworkshop_' . self::APP_ID . '.acf';

        try {
            $raw = (string) $this->files->setServer($server)->getContent($path, 20_000_000);
        } catch (\Throwable $e) {
            return [];
        }

        $start = strpos($raw, '"WorkshopItemsInstalled"');
        if ($start === false) {
            return [];
        }

        $open = strpos($raw, '{', $start);
        $end = $open === false ? null : $this->matchBrace($raw, $open);
        if ($end === null) {
            return [];
        }

        preg_match_all('/^\s*"(\d{6,})"/m', substr($raw, $open, $end - $open), $m);

        return array_values(array_unique($m[1] ?? []));
    }

    /**
     * Take Workshop items out of SteamCMD's own record of what is installed.
     *
     * Deleting the files and the `WorkshopItems=` line is not enough to delete a
     * mod. SteamCMD keeps `steamapps/workshop/appworkshop_<appid>.acf`, a list of
     * every item it believes it has installed, and on the next boot it sees an
     * item in that list with no files on disk and helpfully downloads it again.
     * The mod is then back on the server, absent from `Mods=`, and shows up under
     * Available as if it had never been deleted. Every restart, forever.
     *
     * The file belongs to Valve's tooling, so this is deliberately timid: it
     * only removes whole balanced blocks it can find, it verifies the result is
     * still balanced, and at the first sign of anything unexpected it writes
     * nothing at all and reports false. A stale entry is a nuisance; a mangled
     * manifest stops the server downloading anything.
     *
     * @param  string[]  $workshopIds
     * @return bool whether the file was rewritten
     */
    public function forgetInstalled(Server $server, array $workshopIds): bool
    {
        $workshopIds = array_values(array_unique(array_filter(array_map('strval', $workshopIds))));
        if (!$workshopIds) {
            return false;
        }

        $path = '/steamapps/workshop/appworkshop_' . self::APP_ID . '.acf';
        $repo = $this->files->setServer($server);

        try {
            $raw = (string) $repo->getContent($path, 20_000_000);
        } catch (\Throwable $e) {
            return false;
        }

        if ($raw === '' || substr_count($raw, '{') !== substr_count($raw, '}')) {
            return false;
        }

        $out = $raw;
        foreach ($workshopIds as $id) {
            $out = $this->dropBlocks($out, $id);
        }

        // Nothing matched, or the walk left the braces uneven. Either way this
        // file is not getting written.
        if ($out === $raw || substr_count($out, '{') !== substr_count($out, '}')) {
            return false;
        }

        try {
            $repo->putContent($path, $out);
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    /**
     * Remove every `"<id>" { ... }` block from a Valve KeyValues document.
     *
     * Walks braces rather than matching with a regex, because the blocks nest
     * and a greedy match would swallow the rest of the file. Quoted strings are
     * stepped over so a brace inside a value cannot throw the depth count off.
     */
    private function dropBlocks(string $raw, string $id): string
    {
        $needle = '"' . $id . '"';
        $offset = 0;

        while (($at = strpos($raw, $needle, $offset)) !== false) {
            // Only a key sitting on its own line, never an id appearing as a
            // value somewhere else in the file.
            $lineStart = strrpos(substr($raw, 0, $at), "\n");
            $lineStart = $lineStart === false ? 0 : $lineStart + 1;
            if (trim(substr($raw, $lineStart, $at - $lineStart)) !== '') {
                $offset = $at + strlen($needle);

                continue;
            }

            $open = strpos($raw, '{', $at + strlen($needle));
            if ($open === false || trim(substr($raw, $at + strlen($needle), $open - $at - strlen($needle))) !== '') {
                $offset = $at + strlen($needle);

                continue;
            }

            $end = $this->matchBrace($raw, $open);
            if ($end === null) {
                return $raw;
            }

            $after = strpos($raw, "\n", $end);
            $cut = $after === false ? strlen($raw) : $after + 1;
            $raw = substr($raw, 0, $lineStart) . substr($raw, $cut);
            $offset = $lineStart;
        }

        return $raw;
    }

    /** Index of the `}` closing the `{` at $open, or null when it is unbalanced. */
    private function matchBrace(string $raw, int $open): ?int
    {
        $depth = 0;
        $len = strlen($raw);

        for ($i = $open; $i < $len; $i++) {
            $c = $raw[$i];
            if ($c === '"') {
                // Skip the whole quoted string, escapes included.
                for ($i++; $i < $len; $i++) {
                    if ($raw[$i] === '\\') {
                        $i++;

                        continue;
                    }
                    if ($raw[$i] === '"') {
                        break;
                    }
                }

                continue;
            }
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

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

        // Wings used to cap a recursive search at about a hundred results, which
        // silently hid mods on a well stocked server. The old guard here assumed
        // that any result set at the cap was truncated and re-searched every
        // workshop directory one by one: 127 extra requests on a server whose
        // Wings had answered the first search in full with 364 hits, on every
        // page load and every click. Ask the cheap question instead. The
        // directory listing is one request and says which items exist, so only
        // the items the search really missed get looked up.
        if (count($hits) >= self::SEARCH_CAP) {
            $hits = $this->fillMissingItems($repo, $base, $hits);
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

    /**
     * Top up a search that may have been truncated, one workshop item at a time.
     *
     * A directory with no mod.info in it is not evidence of truncation: a part
     * downloaded item, or one holding something that is not a mod, legitimately
     * has none. Such a directory is searched once, comes back empty, and that is
     * the end of it, rather than being treated as a reason to distrust the whole
     * result.
     *
     * @param  array<int,array<string,mixed>>  $hits
     * @return array<int,array<string,mixed>>
     */
    private function fillMissingItems(DaemonFileRepository $repo, string $base, array $hits): array
    {
        $seen = [];
        foreach ($hits as $hit) {
            if (preg_match('#/content/' . self::APP_ID . '/(\d+)/#', (string) ($hit['name'] ?? ''), $m)) {
                $seen[$m[1]] = true;
            }
        }

        try {
            $dirs = $repo->getDirectory($base);
        } catch (\Throwable $e) {
            // No listing means no way to tell what is missing. The search we
            // already have is better than nothing and better than 127 guesses.
            return $hits;
        }

        foreach ($dirs as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if (empty($entry['directory']) || isset($seen[$name])) {
                continue;
            }
            try {
                $hits = array_merge($hits, $repo->search('mod.info', $base . '/' . $name));
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $hits;
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
