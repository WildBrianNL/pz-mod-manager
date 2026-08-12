<?php

namespace WildBrianNL\PZModManager\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Thin, cached wrapper around the public Steam Workshop endpoints. */
class SteamClient
{
    private const DETAILS = 'https://api.steampowered.com/ISteamRemoteStorage/GetPublishedFileDetails/v1/';

    private const COLLECTION = 'https://api.steampowered.com/ISteamRemoteStorage/GetCollectionDetails/v1/';

    /** Set after a failed fetch; while it is set, Steam is not contacted. */
    private const BACKOFF_KEY = 'pzmm:steam:backoff';

    private const BACKOFF_SECONDS = 600;

    /**
     * Steam answered about this id and had nothing: deleted, hidden, or made
     * private. Remembered for a while so the same handful of dead Workshop
     * entries do not put every single check back on the wire. Without this, one
     * removed mod is enough to make a warm cache useless.
     */
    private const GONE_SECONDS = 21600;

    /**
     * Whether the most recent details() call had to give up on something.
     *
     * Per call, deliberately, and not the same question as "is the backoff
     * set". A check that answered every id out of a fresh cache is complete
     * even if some unrelated fetch failed nine minutes ago, and reporting that
     * one as incomplete puts a permanent warning on a page that is fine.
     */
    private bool $incomplete = false;

    /**
     * Metadata for workshop items, keyed by id. Missing/unreachable items are
     * simply absent - every caller must degrade gracefully.
     *
     * @param  string[]  $ids
     * @param  ?int  $maxAgeSeconds  Force refresh when cached data is older than this.
     * @return array<string,array<string,mixed>>
     */
    public function details(array $ids, ?int $maxAgeSeconds = null): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        $out = [];
        $missing = [];
        $this->incomplete = false;

        foreach ($ids as $id) {
            $cached = $this->readCachedDetail($id, $maxAgeSeconds);
            if ($cached !== null) {
                $out[$id] = $cached;
            } elseif (!Cache::get("pzmm:steam:gone:$id")) {
                $missing[] = $id;
            }
        }

        // Steam is down, or throttling, or simply refusing. Hammering it every
        // five minutes from the scheduler makes that worse and fixes nothing, so
        // after a failed fetch the endpoint is left alone for a while and stale
        // cache is served instead. Callers already handle missing metadata.
        if ($missing && Cache::get(self::BACKOFF_KEY)) {
            $this->incomplete = true;

            return $out + $this->staleFor($missing);
        }

        $cacheHours = max(1, (int) config('pz-mod-manager.cache.steam_hours', 12));
        $chunks = array_chunk($missing, 50);
        foreach ($chunks as $i => $chunk) {
            $fetched = $this->fetchDetails($chunk);

            // null is "Steam did not answer", which is a real outage and worth
            // backing off from. An empty array is "Steam answered and those
            // items are gone", which is information, not a failure. Treating
            // the two the same let a batch of deleted mods trip the backoff and
            // then report the whole server as unchecked.
            if ($fetched === null) {
                $this->incomplete = true;
                Cache::put(self::BACKOFF_KEY, true, now()->addSeconds(self::BACKOFF_SECONDS));

                // Stop, do not carry on down the list. Steam being unreachable
                // for one batch of fifty means it is unreachable for the next
                // one too, and each attempt costs a full HTTP timeout. Three
                // batches used to mean three timeouts in a row, which on a
                // server with 126 Workshop items put a page request past PHP's
                // thirty second limit and turned an honest "could not reach
                // Steam" into a 500.
                foreach (array_slice($chunks, $i) as $rest) {
                    $out += $this->staleFor($rest);
                }

                return $out;
            }
            foreach ($fetched as $id => $meta) {
                Cache::put("pzmm:steam:$id", [
                    'meta' => $meta,
                    'fetched_at' => now()->timestamp,
                ], now()->addHours($cacheHours));
                $out[$id] = $meta;
            }

            foreach (array_diff($chunk, array_keys($fetched)) as $id) {
                Cache::put("pzmm:steam:gone:$id", true, now()->addSeconds(self::GONE_SECONDS));
            }
        }

        return $out;
    }

    /**
     * True when the last details() call could not reach Steam for some of what
     * it was asked about, and answered from stale cache instead.
     *
     * Without this a caller cannot tell "Steam says everything is current" from
     * "Steam said nothing and you are looking at yesterday's answer". Those two
     * must never be reported to an operator with the same words.
     */
    public function degraded(): bool
    {
        return $this->incomplete;
    }

    /**
     * Drop the backoff so the next call really contacts Steam.
     *
     * Only for a check somebody asked for by hand. The scheduler must keep
     * backing off, or a Steam outage turns into us hammering it every five
     * minutes for every server on the panel.
     */
    public function clearBackoff(): void
    {
        Cache::forget(self::BACKOFF_KEY);
    }

    /**
     * Whatever is cached for these ids, however old.
     *
     * Used only when a fetch has just failed. Metadata a few hours out of date is
     * better than none: it keeps titles and thumbnails on the page, and the
     * update check simply sees no change rather than a false one.
     *
     * @param  string[]  $ids
     * @return array<string,array<string,mixed>>
     */
    private function staleFor(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $entry = Cache::get("pzmm:steam:$id");
            if (is_array($entry) && isset($entry['meta'])) {
                $out[$id] = $entry['meta'];
            }
        }

        return $out;
    }

    /** @return string[] workshop ids inside a collection ("" when not a collection) */
    public function collection(string $id): array
    {
        try {
            $json = Http::asForm()->timeout(12)->post(self::COLLECTION, [
                'collectioncount' => 1,
                'publishedfileids' => [$id],
            ])->json();
        } catch (\Throwable $e) {
            return [];
        }

        $children = $json['response']['collectiondetails'][0]['children'] ?? [];

        return array_values(array_filter(array_map(
            fn ($c) => (string) ($c['publishedfileid'] ?? ''),
            is_array($children) ? $children : []
        )));
    }

    /**
     * Best-effort mod ids advertised in a workshop description ("Mod ID: X").
     * Always verified against the real mod.info after download.
     *
     * @return string[]
     */
    public function advertisedModIds(string $workshopId): array
    {
        $meta = $this->details([$workshopId])[$workshopId] ?? null;
        if (!$meta) {
            return [];
        }
        preg_match_all('/Mod\s?ID\s*[:=]\s*([A-Za-z0-9_.\-]+)/i', (string) ($meta['description'] ?? ''), $m);

        return array_values(array_unique($m[1] ?? []));
    }

    /**
     * @return ?array<string,array<string,mixed>>
     *         null when Steam could not be reached or answered with something
     *         that is not a response at all. An empty array means Steam did
     *         answer and knows nothing about any of these ids, which is a fact
     *         about the ids rather than a fault on our side.
     */
    private function fetchDetails(array $ids): ?array
    {
        if (!$ids) {
            return [];
        }

        try {
            $json = Http::asForm()->timeout(10)->post(self::DETAILS, [
                'itemcount' => count($ids),
                'publishedfileids' => array_values($ids),
            ])->json();
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_array($json) || !isset($json['response']['publishedfiledetails'])) {
            return null;
        }

        $out = [];
        foreach ($json['response']['publishedfiledetails'] ?? [] as $item) {
            $id = (string) ($item['publishedfileid'] ?? '');
            if ($id === '' || (int) ($item['result'] ?? 0) !== 1) {
                continue;
            }
            $tags = array_values(array_filter(array_map(
                fn ($t) => is_array($t) ? ($t['tag'] ?? '') : (string) $t,
                $item['tags'] ?? []
            )));

            $out[$id] = [
                'title' => $item['title'] ?? null,
                'description' => $item['description'] ?? '',
                'tags' => $tags,
                'category' => $this->category($tags),
                'builds' => $this->builds($tags),
                'preview' => $item['preview_url'] ?? null,
                'updated' => (int) ($item['time_updated'] ?? 0),
                'url' => "https://steamcommunity.com/sharedfiles/filedetails/?id=$id",
            ];
        }

        return $out;
    }

    /** @return ?array<string,mixed> */
    private function readCachedDetail(string $id, ?int $maxAgeSeconds): ?array
    {
        $cached = Cache::get("pzmm:steam:$id");
        if (!is_array($cached)) {
            return null;
        }

        // Legacy shape: cached raw metadata without an age marker.
        if (!array_key_exists('meta', $cached) || !is_array($cached['meta'] ?? null)) {
            return $maxAgeSeconds === null ? $cached : null;
        }

        $meta = $cached['meta'];
        $fetchedAt = (int) ($cached['fetched_at'] ?? 0);
        if ($maxAgeSeconds !== null && $fetchedAt > 0 && now()->timestamp - $fetchedAt > $maxAgeSeconds) {
            return null;
        }

        return $meta;
    }

    /** @param string[] $tags */
    private function category(array $tags): string
    {
        $lower = array_map('strtolower', $tags);
        $map = [
            'framework' => ['framework', 'library'],
            'vehicles' => ['vehicles'],
            'map' => ['map'],
            'weapons' => ['weapons'],
            'building' => ['building'],
            'interface' => ['interface', 'ui'],
            'items' => ['items', 'food', 'clothing/models'],
            'balance' => ['balance', 'realistic', 'hardcore'],
            'multiplayer' => ['multiplayer'],
        ];
        foreach ($map as $category => $needles) {
            foreach ($needles as $needle) {
                if (in_array($needle, $lower, true)) {
                    return $category;
                }
            }
        }

        return 'other';
    }

    /** @param string[] $tags @return int[] */
    private function builds(array $tags): array
    {
        $builds = [];
        foreach ($tags as $tag) {
            if (preg_match('/build\s*(\d{2})/i', $tag, $m)) {
                $builds[] = (int) $m[1];
            }
        }

        return array_values(array_unique($builds));
    }

    /**
     * Workshop changelog entries, newest first. Steam rate-limits this page
     * aggressively (HTTP 429), so it is fetched only on demand and cached; an
     * empty result simply means "read it on Steam instead".
     *
     * @return array<int,array{date:string,text:string}>
     */
    public function changelog(string $workshopId): array
    {
        return Cache::remember("pzmm:changelog:$workshopId", now()->addHours(6), function () use ($workshopId) {
            try {
                $response = Http::timeout(12)
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; PelicanModManager/2.0)'])
                    ->get("https://steamcommunity.com/sharedfiles/filedetails/changelog/$workshopId");
                if (!$response->successful()) {
                    return [];
                }
                $html = $response->body();
            } catch (\Throwable $e) {
                return [];
            }

            if (!preg_match_all('#<div class="changelog headline">(.*?)</div>(.*?)(?=<div class="changelog headline">|<div class="footerLinks|\Z)#s', $html, $matches, PREG_SET_ORDER)) {
                return [];
            }

            $entries = [];
            foreach (array_slice($matches, 0, 12) as $match) {
                $date = trim(html_entity_decode(strip_tags($match[1])));
                $body = preg_replace('#<br\s*/?>#i', "\n", $match[2] ?? '');
                $text = trim(html_entity_decode(strip_tags((string) $body)));
                $text = preg_replace('/\n{3,}/', "\n\n", $text);
                if ($date !== '') {
                    $entries[] = ['date' => $date, 'text' => mb_substr($text, 0, 1200)];
                }
            }

            return $entries;
        });
    }
}
