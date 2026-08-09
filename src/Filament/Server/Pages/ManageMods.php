<?php

namespace WildBrianNL\PZModManager\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use WildBrianNL\PZModManager\Services\IniService;
use WildBrianNL\PZModManager\Services\LogInspector;
use WildBrianNL\PZModManager\Services\ModScanner;
use WildBrianNL\PZModManager\Services\PowerService;
use WildBrianNL\PZModManager\Services\StateStore;
use WildBrianNL\PZModManager\Services\SteamClient;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class ManageMods extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'tabler-puzzle';

    protected static ?int $navigationSort = 9;

    protected string $view = 'pz-mod-manager::manage-mods';

    /** Used only until the running server reports its own build. */
    private const FALLBACK_BUILD = 42;

    private const CATEGORY_ORDER = ['framework', 'interface', 'vehicles', 'building', 'items', 'weapons', 'balance', 'map', 'multiplayer', 'other'];

    /** @var array<int,array<string,mixed>> active mods, in load order */
    public array $active = [];

    /** @var array<int,array<string,mixed>> installed but inactive mods */
    public array $available = [];

    /** @var array<int,array<string,mixed>> */
    public array $alerts = [];

    /**
     * mod-id => load-order index that auto-sort must preserve.
     *
     * Not every framework declares `category=framework` in its mod.info, so
     * dependency sorting alone puts some mods in the wrong place. A lock pins
     * one to an index and auto-sort works around it.
     *
     * Locks pin against auto-sort only: any manual reorder re-records the locked
     * mods at wherever they ended up, so dragging never leaves a lock pointing
     * at a stale index.
     *
     * @var array<string,int>
     */
    public array $locks = [];

    /** @var array<string,int> */
    public array $stats = ['active' => 0, 'available' => 0, 'restart' => 0, 'errors' => 0];

    /** @var array{state:string,checked_at:?string,pending_at:?string,summary:?string,details:array<int,string>} */
    public array $autoUpdate = ['state' => 'idle', 'checked_at' => null, 'pending_at' => null, 'summary' => null, 'details' => []];

    public bool $autoUpdateVerbose = false;

    public string $search = '';

    /**
     * Mod ids ticked for a bulk action, from either section.
     *
     * @var string[]
     */
    public array $selected = [];

    public string $newMod = '';

    public bool $activateOnAdd = true;

    public ?string $configError = null;

    public bool $indexOk = true;

    public bool $serverRunning = false;

    /** @var array<string,int> mods per workshop item - used to name bundled mods correctly */
    public array $bundleSize = [];

    public int $targetBuild = self::FALLBACK_BUILD;

    public ?string $changelogFor = null;

    /** @var array<int,array{date:string,text:string}> */
    public array $changelog = [];

    public bool $changelogFailed = false;

    // ---------------------------------------------------------------- access

    public static function canAccess(): bool
    {
        /** @var Server|null $server */
        $server = Filament::getTenant();

        return $server
            && str_contains(strtolower($server->egg->name ?? ''), 'zomboid')
            && (bool) user()?->can(SubuserPermission::FileRead, $server);
    }

    public function getServer(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    public function canWrite(): bool
    {
        return (bool) user()?->can(SubuserPermission::FileUpdate, $this->getServer());
    }

    public function canRestart(): bool
    {
        return (bool) user()?->can(SubuserPermission::ControlRestart, $this->getServer());
    }

    public function getTitle(): string
    {
        return trans('pzmm::messages.title');
    }

    public static function getNavigationLabel(): string
    {
        return trans('pzmm::messages.title');
    }

    public function mount(): void
    {
        abort_unless(user()?->can(SubuserPermission::FileRead, $this->getServer()), 403);
        $this->load();
    }

    // ------------------------------------------------------------ state load

    public function load(): void
    {
        $server = $this->getServer();
        $this->loadAutoUpdateStatus($server);

        $ini = app(IniService::class)->read($server);
        $this->configError = $ini['error'];
        if (!$ini['ok']) {
            $this->active = $this->available = $this->alerts = [];
            $this->stats = ['active' => 0, 'available' => 0, 'restart' => 0, 'errors' => 0];

            return;
        }

        $log = Cache::remember(
            "pzmm:log:{$server->id}",
            now()->addSeconds(45),
            fn () => app(LogInspector::class)->inspect($server)
        );
        $this->targetBuild = $log['build'] ?: self::FALLBACK_BUILD;

        $index = app(ModScanner::class)->index($server, $this->targetBuild);
        $this->indexOk = $index['ok'];
        $installed = $index['mods'];

        $ini = $this->selfHeal($server, $ini, $installed);
        $ini = $this->repairWorkshopItems($server, $ini, $installed);
        $this->serverRunning = (bool) $log['started'];
        $loaded = array_flip($log['loaded'] ?? []);
        $failed = array_flip($log['notFound'] ?? []);

        $steam = app(SteamClient::class)->details(array_merge(
            $ini['workshopItems'],
            array_map(fn ($m) => $m['workshop_id'], $installed)
        ));

        $byModId = [];
        $byWorkshop = [];
        foreach ($installed as $mod) {
            $byModId[$mod['mod_id']] = $mod;
            $byWorkshop[$mod['workshop_id']][] = $mod['mod_id'];
        }
        $this->bundleSize = array_map('count', $byWorkshop);
        $awaitingDownload = array_values(array_diff($ini['workshopItems'], array_keys($byWorkshop)));

        $this->locks = app(StateStore::class)->read($server)['locks'];

        $active = [];
        $seen = [];
        $duplicates = [];
        foreach ($ini['mods'] as $modId) {
            if (isset($seen[$modId])) {
                $duplicates[] = $modId;

                continue;
            }
            $seen[$modId] = true;
            $active[] = $this->row($modId, $byModId[$modId] ?? null, $steam, $log, $loaded, true, $awaitingDownload, $failed);
        }

        $available = [];
        foreach ($installed as $mod) {
            if (!isset($seen[$mod['mod_id']])) {
                $available[] = $this->row($mod['mod_id'], $mod, $steam, $log, $loaded, false, $awaitingDownload, $failed);
            }
        }
        usort($available, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        $this->active = $active;
        $this->available = $available;
        $this->stats = [
            'active' => count($active),
            'available' => count($available),
            'restart' => count(array_filter($active, fn ($r) => in_array($r['status'], ['restart', 'downloading'], true))),
            'errors' => array_sum(array_map(fn ($r) => $r['errors'], $active)),
        ];
        $this->alerts = $this->buildAlerts($active, $duplicates, $awaitingDownload, $log, $ini);

        if ($duplicates && $this->canWrite()) {
            // Writing the de-duplicated list back is safe and permanent.
            $this->persist(array_map(fn ($r) => $r['mod_id'], $active), false);
        }
    }

    /**
     * Verify earlier "activate on add" guesses against reality. A guessed mod
     * id that does not exist after download is replaced by the real ids, so a
     * wrong guess can never leave a dead entry behind.
     */
    private function selfHeal(Server $server, array $ini, array $installed): array
    {
        $store = app(StateStore::class);
        $state = $store->read($server);
        if (!$state['pending']) {
            return $ini;
        }

        $byWorkshop = [];
        foreach ($installed as $mod) {
            $byWorkshop[$mod['workshop_id']][] = $mod['mod_id'];
        }

        $mods = $ini['mods'];
        $changed = false;
        $fixed = [];

        foreach ($state['pending'] as $workshopId => $guesses) {
            if (!isset($byWorkshop[$workshopId])) {
                continue; // still downloading
            }
            $real = $byWorkshop[$workshopId];
            foreach ((array) $guesses as $guess) {
                if (!in_array($guess, $real, true) && in_array($guess, $mods, true)) {
                    $mods = array_values(array_filter($mods, fn ($m) => $m !== $guess));
                    $changed = true;
                }
            }
            foreach ($real as $modId) {
                if (!in_array($modId, $mods, true)) {
                    $mods[] = $modId;
                    $changed = true;
                    $fixed[] = $modId;
                }
            }
            unset($state['pending'][$workshopId]);
        }

        $store->write($server, $state);

        if ($changed && $this->canWrite()) {
            app(IniService::class)->write($server, $ini['workshopItems'], $mods);
            $ini['mods'] = $mods;
            if ($fixed) {
                Notification::make()
                    ->title(trans('pzmm::messages.notify.auto_activated'))
                    ->body(implode(', ', $fixed))
                    ->success()
                    ->send();
            }
        }

        return $ini;
    }

    /** Guarantee every active mod's workshop id is present, or clients cannot download it. */
    private function repairWorkshopItems(Server $server, array $ini, array $installed): array
    {
        $modToWorkshop = [];
        foreach ($installed as $mod) {
            $modToWorkshop[$mod['mod_id']] = $mod['workshop_id'];
        }

        $items = $ini['workshopItems'];
        $added = [];
        foreach ($ini['mods'] as $modId) {
            $workshopId = $modToWorkshop[$modId] ?? null;
            if ($workshopId && !in_array($workshopId, $items, true)) {
                $items[] = $workshopId;
                $added[] = $workshopId;
            }
        }

        if ($added && $this->canWrite()) {
            app(IniService::class)->write($server, $items, $ini['mods']);
            $ini['workshopItems'] = $items;
            Notification::make()
                ->title(trans('pzmm::messages.notify.repaired_workshop'))
                ->body(implode(', ', $added))
                ->warning()
                ->send();
        }

        return $ini;
    }

    /** @return array<string,mixed> */
    private function row(string $modId, ?array $installed, array $steam, array $log, array $loaded, bool $isActive, array $awaitingDownload, array $failed = []): array
    {
        $workshopId = $installed['workshop_id'] ?? '';
        $meta = $workshopId !== '' ? ($steam[$workshopId] ?? []) : [];

        $builds = array_values(array_unique(array_merge($installed['builds'] ?? [], $meta['builds'] ?? [])));
        $compat = !$builds ? 'unknown' : (in_array($this->targetBuild, $builds, true) ? 'ok' : 'mismatch');

        $errors = (int) ($log['errors'][$modId] ?? 0);
        foreach ($log['names'] ?? [] as $name => $count) {
            if (strcasecmp($name, $installed['name'] ?? '') === 0 || strcasecmp($name, $meta['title'] ?? '') === 0) {
                $errors += (int) $count;
            }
        }

        if (!$isActive) {
            $status = 'available';
        } elseif ($installed === null) {
            $status = $awaitingDownload ? 'downloading' : 'orphan';
        } elseif (isset($loaded[$modId])) {
            $status = 'active';
        } elseif (isset($failed[$modId])) {
            $status = 'failed';
        } else {
            $status = 'restart';
        }

        $bundled = $workshopId !== '' && (($this->bundleSize[$workshopId] ?? 1) > 1);
        $installedAt = (int) ($installed['installed_at'] ?? 0);
        $updatedAt = (int) ($meta['updated'] ?? 0);

        return [
            'mod_id' => $modId,
            'workshop_id' => $workshopId,
            'version' => $installed['version'] ?? null,
            'installed_at' => $installedAt,
            'updated_at' => $updatedAt,
            // Steam published a newer build than the files we have on disk.
            'update_available' => $installedAt > 0 && $updatedAt > $installedAt + 300,
            // One workshop item can ship several mods; they all share the same
            // Steam title, so fall back to the per-mod name to keep them apart.
            'name' => $bundled
                ? ($installed['name'] ?? $meta['title'] ?? $modId)
                : ($meta['title'] ?? $installed['name'] ?? $modId),
            'bundled' => $bundled,
            'bundle_title' => $bundled ? ($meta['title'] ?? null) : null,
            'category' => $meta['category'] ?? 'other',
            'preview' => $meta['preview'] ?? null,
            'url' => $meta['url'] ?? ($workshopId !== '' ? "https://steamcommunity.com/sharedfiles/filedetails/?id=$workshopId" : null),
            'require' => $installed['require'] ?? [],
            'maps' => $installed['maps'] ?? [],
            'builds' => $builds,
            'compat' => $compat,
            'errors' => $errors,
            'status' => $status,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function buildAlerts(array $active, array $duplicates, array $awaitingDownload, array $log, array $ini): array
    {
        $alerts = [];
        $activeIds = array_column($active, 'mod_id');

        if ($log['fatal'] ?? null) {
            $alerts[] = ['type' => 'danger', 'text' => trans('pzmm::messages.alert.crash', ['reason' => $log['fatal']]), 'action' => null];
        }

        $missingDeps = [];
        foreach ($active as $row) {
            foreach ($row['require'] as $dep) {
                if (!in_array($dep, $activeIds, true)) {
                    $missingDeps[$dep][] = $row['name'];
                }
            }
        }
        $failedIds = $log['notFound'] ?? [];
        foreach ($missingDeps as $dep => $needers) {
            $installable = collect($this->available)->firstWhere('mod_id', $dep);
            $blocking = (bool) array_intersect($failedIds, array_keys(array_filter(
                array_column($active, 'require', 'mod_id'),
                fn ($req) => in_array($dep, $req, true)
            )));
            $alerts[] = [
                'type' => $blocking ? 'danger' : 'warning',
                'text' => trans($installable ? 'pzmm::messages.alert.missing_dep' : 'pzmm::messages.alert.missing_dep_uninstalled', [
                    'dep' => $dep,
                    'mods' => implode(', ', array_unique($needers)),
                ]),
                'action' => $installable ? ['method' => 'activate', 'arg' => $dep, 'label' => trans('pzmm::messages.action.enable_dep')] : null,
            ];
        }

        $orphans = array_column(array_filter($active, fn ($r) => $r['status'] === 'orphan'), 'mod_id');
        if ($orphans) {
            $alerts[] = [
                'type' => 'warning',
                'text' => trans('pzmm::messages.alert.orphans', ['mods' => implode(', ', $orphans)]),
                'action' => ['method' => 'removeOrphans', 'arg' => null, 'label' => trans('pzmm::messages.action.clean_up')],
            ];
        }

        if ($awaitingDownload) {
            $alerts[] = [
                'type' => 'info',
                'text' => trans_choice('pzmm::messages.alert.awaiting_download', count($awaitingDownload), ['count' => count($awaitingDownload)]),
                'action' => $this->canRestart() ? ['method' => 'restartServer', 'arg' => null, 'label' => trans('pzmm::messages.action.restart_now')] : null,
            ];
        }

        $needRestart = array_filter($active, fn ($r) => $r['status'] === 'restart');
        if ($needRestart) {
            $alerts[] = [
                'type' => 'info',
                'text' => trans_choice('pzmm::messages.alert.needs_restart', count($needRestart), ['count' => count($needRestart)]),
                'action' => $this->canRestart() ? ['method' => 'restartServer', 'arg' => null, 'label' => trans('pzmm::messages.action.restart_now')] : null,
            ];
        }

        $mismatched = array_filter($active, fn ($r) => $r['compat'] === 'mismatch');
        if ($mismatched) {
            $alerts[] = [
                'type' => 'warning',
                'text' => trans('pzmm::messages.alert.build_mismatch', [
                    'build' => $this->targetBuild,
                    'mods' => implode(', ', array_column($mismatched, 'name')),
                ]),
                'action' => null,
            ];
        }

        $mapMods = [];
        foreach ($active as $row) {
            foreach ($row['maps'] as $map) {
                if (!in_array($map, $ini['maps'], true)) {
                    $mapMods[] = $map;
                }
            }
        }
        if ($mapMods) {
            $alerts[] = [
                'type' => 'info',
                'text' => trans('pzmm::messages.alert.maps_missing', ['maps' => implode(', ', array_unique($mapMods))]),
                'action' => $this->canWrite() ? ['method' => 'addMapsToConfig', 'arg' => null, 'label' => trans('pzmm::messages.action.add_maps')] : null,
            ];
        }

        $broken = array_filter($active, fn ($r) => $r['errors'] > 0);
        if ($broken) {
            $alerts[] = [
                'type' => 'warning',
                'text' => trans('pzmm::messages.alert.mod_errors', ['mods' => implode(', ', array_column($broken, 'name'))]),
                'action' => null,
            ];
        }

        // The set can match while the order does not - a reordered load order
        // silently does nothing until the server restarts.
        $loadedOrder = array_values(array_filter($log['loaded'] ?? [], fn ($id) => in_array($id, $activeIds, true)));
        $wantedOrder = array_values(array_filter($activeIds, fn ($id) => in_array($id, $log['loaded'] ?? [], true)));
        if (count($wantedOrder) > 1 && $loadedOrder !== $wantedOrder) {
            $alerts[] = [
                'type' => 'info',
                'text' => trans('pzmm::messages.alert.order_changed'),
                'action' => $this->canRestart() ? ['method' => 'restartServer', 'arg' => null, 'label' => trans('pzmm::messages.action.restart_now')] : null,
            ];
        }

        $updatable = array_filter($active, fn ($r) => $r['update_available']);
        if ($updatable) {
            $alerts[] = [
                'type' => 'info',
                'text' => trans_choice('pzmm::messages.alert.updates', count($updatable), ['count' => count($updatable)])
                    . ' ' . implode(', ', array_column($updatable, 'name')),
                'action' => $this->canRestart() ? ['method' => 'restartServer', 'arg' => null, 'label' => trans('pzmm::messages.action.restart_now')] : null,
            ];
        }

        if ($duplicates) {
            $alerts[] = ['type' => 'info', 'text' => trans('pzmm::messages.alert.duplicates', ['mods' => implode(', ', array_unique($duplicates))]), 'action' => null];
        }

        return $alerts;
    }

    // -------------------------------------------------------------- mutation

    /** @param string[] $modIds */
    private function persist(array $modIds, bool $reload = true): void
    {
        abort_unless($this->canWrite(), 403);
        $server = $this->getServer();
        $ini = app(IniService::class)->read($server);
        if (!$ini['ok']) {
            Notification::make()->title(trans('pzmm::messages.notify.config_error'))->danger()->send();

            return;
        }
        $modIds = array_values(array_unique($modIds));
        app(IniService::class)->write($server, $this->workshopItemsFor($modIds, $ini), $modIds);
        $this->syncLocks($modIds);
        if ($reload) {
            $this->load();
        }
    }

    /**
     * WorkshopItems must list exactly what the server - and therefore every
     * connecting client - should download: the items backing the enabled mods,
     * plus anything still waiting to arrive. Disabling a mod consequently also
     * stops clients from downloading it, while its files stay on disk so it can
     * be switched back on without a re-download.
     *
     * @param  string[]  $modIds
     * @return string[]
     */
    private function workshopItemsFor(array $modIds, array $ini): array
    {
        $modToWorkshop = [];
        $installed = [];
        foreach (array_merge($this->active, $this->available) as $row) {
            if (($row['workshop_id'] ?? '') !== '') {
                $modToWorkshop[$row['mod_id']] = $row['workshop_id'];
                $installed[$row['workshop_id']] = true;
            }
        }

        $items = [];
        foreach ($modIds as $modId) {
            if (isset($modToWorkshop[$modId])) {
                $items[] = $modToWorkshop[$modId];
            }
        }
        foreach ($ini['workshopItems'] as $workshopId) {
            if (!isset($installed[$workshopId])) {
                $items[] = $workshopId; // not on disk yet - still downloading
            }
        }

        return array_values(array_unique($items));
    }

    /** Fresh ordered list straight from the config - never from stale component state. */
    private function currentModIds(): array
    {
        return app(IniService::class)->read($this->getServer())['mods'];
    }

    public function activate(string $modId): void
    {
        $ids = $this->currentModIds();
        if (!in_array($modId, $ids, true)) {
            $ids[] = $modId;
        }
        $this->persist($ids);
    }

    public function deactivate(string $modId): void
    {
        $this->persist(array_values(array_filter($this->currentModIds(), fn ($id) => $id !== $modId)));
        Notification::make()->title(trans('pzmm::messages.notify.disabled'))->success()->send();
    }

    public function move(string $modId, string $direction): void
    {
        $ids = $this->currentModIds();
        $i = array_search($modId, $ids, true);
        if ($i === false) {
            return;
        }
        unset($ids[$i]);
        $ids = array_values($ids);
        $target = match ($direction) {
            'up' => max(0, $i - 1),
            'down' => min(count($ids), $i + 1),
            'top' => 0,
            default => count($ids),
        };
        array_splice($ids, $target, 0, [$modId]);
        $this->persist($ids);
    }

    /**
     * Apply a whole new load order in one go (drag and drop).
     *
     * A drag may only permute the list. If the incoming set does not match what
     * is on disk the page was showing stale state, so refuse and reload rather
     * than write an order that silently drops or duplicates a mod. Losing a mod
     * from Mods= is invisible until the server next boots.
     */
    public function reorder(array $modIds): void
    {
        abort_unless($this->canWrite(), 403);

        $current = $this->currentModIds();
        $incoming = array_values(array_filter($modIds, 'is_string'));

        $a = $incoming;
        $b = $current;
        sort($a);
        sort($b);
        if ($a !== $b) {
            Notification::make()
                ->title(trans('pzmm::messages.notify.order_stale'))
                ->warning()
                ->send();
            $this->load();

            return;
        }

        $this->persist($incoming);
    }

    /**
     * Pin or unpin a mod at its current load-order position.
     *
     * The index recorded is the position in the real config, not in the filtered
     * view, so locking while a search is active still stores the right number.
     */
    public function toggleLock(string $modId): void
    {
        abort_unless($this->canWrite(), 403);

        $store = app(StateStore::class);
        $state = $store->read($this->getServer());
        $locks = $state['locks'];

        if (isset($locks[$modId])) {
            unset($locks[$modId]);
            $title = trans('pzmm::messages.notify.unlocked');
        } else {
            $index = array_search($modId, $this->currentModIds(), true);
            if ($index === false) {
                return;
            }
            $locks[$modId] = $index;
            $title = trans('pzmm::messages.notify.locked', ['position' => $index + 1]);
        }

        $state['locks'] = $locks;
        $store->write($this->getServer(), $state);
        $this->locks = $locks;

        Notification::make()->title($title)->success()->send();
    }

    /**
     * Re-record every lock at the position the mod actually occupies now.
     *
     * Called after each write so a lock always means "keep it where it is",
     * never "keep it at the number it had three edits ago". Locks for mods that
     * left the load order are dropped.
     *
     * @param  string[]  $modIds
     */
    private function syncLocks(array $modIds): void
    {
        $store = app(StateStore::class);
        $state = $store->read($this->getServer());
        if (!$state['locks']) {
            $this->locks = [];

            return;
        }

        $locks = [];
        foreach ($state['locks'] as $modId => $_old) {
            $index = array_search($modId, $modIds, true);
            if ($index !== false) {
                $locks[$modId] = $index;
            }
        }

        if ($locks !== $state['locks']) {
            $state['locks'] = $locks;
            $store->write($this->getServer(), $state);
        }
        $this->locks = $locks;
    }

    /**
     * Put locked mods back on their pinned indices after a sort.
     *
     * Locked mods are pulled out, then re-inserted in ascending index order.
     * Ascending matters: each insertion shifts everything after it right, so
     * processing low indices first leaves the earlier ones already correct.
     * An index past the end of the list clamps to the end rather than being
     * dropped, which would silently lose a mod from Mods=.
     *
     * @param  string[]  $sorted
     * @return string[]
     */
    private function applyLocks(array $sorted): array
    {
        $locks = array_filter(
            $this->locks,
            fn ($modId) => in_array($modId, $sorted, true),
            ARRAY_FILTER_USE_KEY
        );
        if (!$locks) {
            return $sorted;
        }

        asort($locks);
        $rest = array_values(array_filter($sorted, fn ($id) => !isset($locks[$id])));

        foreach ($locks as $modId => $index) {
            array_splice($rest, min($index, count($rest)), 0, [$modId]);
        }

        return $rest;
    }

    /** Topological sort on mod.info `require=`, frameworks first. */
    public function autoSort(): void
    {
        $rows = [];
        foreach ($this->active as $row) {
            $rows[$row['mod_id']] = $row;
        }
        $ids = array_keys($rows);
        $indegree = array_fill_keys($ids, 0);
        $edges = array_fill_keys($ids, []);

        foreach ($rows as $modId => $row) {
            foreach ($row['require'] as $dep) {
                if (isset($rows[$dep])) {
                    $edges[$dep][] = $modId;
                    $indegree[$modId]++;
                }
            }
        }

        $compare = function ($a, $b) use ($rows) {
            $fa = ($rows[$a]['category'] ?? '') === 'framework' ? 0 : 1;
            $fb = ($rows[$b]['category'] ?? '') === 'framework' ? 0 : 1;

            return $fa <=> $fb ?: strcasecmp($rows[$a]['name'], $rows[$b]['name']);
        };

        $ready = array_values(array_filter($ids, fn ($id) => $indegree[$id] === 0));
        usort($ready, $compare);
        $sorted = [];
        while ($ready) {
            $id = array_shift($ready);
            $sorted[] = $id;
            foreach ($edges[$id] as $next) {
                if (--$indegree[$next] === 0) {
                    $ready[] = $next;
                }
            }
            usort($ready, $compare);
        }
        foreach ($ids as $id) {
            if (!in_array($id, $sorted, true)) {
                $sorted[] = $id; // dependency cycle - keep it, do not drop it
            }
        }

        $this->persist($this->applyLocks($sorted));
        Notification::make()->title(trans('pzmm::messages.notify.sorted'))->success()->send();
    }

    public function removeOrphans(): void
    {
        $orphans = array_column(array_filter($this->active, fn ($r) => $r['status'] === 'orphan'), 'mod_id');
        $this->persist(array_values(array_diff($this->currentModIds(), $orphans)));
        Notification::make()->title(trans('pzmm::messages.notify.cleaned'))->success()->send();
    }

    public function addMapsToConfig(): void
    {
        abort_unless($this->canWrite(), 403);
        $server = $this->getServer();
        $ini = app(IniService::class)->read($server);
        if (!$ini['ok']) {
            return;
        }

        $maps = $ini['maps'];
        foreach ($this->active as $row) {
            foreach ($row['maps'] as $map) {
                if (!in_array($map, $maps, true)) {
                    array_unshift($maps, $map); // mod maps must precede the vanilla map
                }
            }
        }
        app(IniService::class)->write($server, $ini['workshopItems'], $ini['mods'], $maps);
        $this->load();
        Notification::make()->title(trans('pzmm::messages.notify.maps_added'))->success()->send();
    }

    /** Removes the workshop item from the config and deletes its files. */
    public function remove(string $modId): void
    {
        abort_unless($this->canWrite(), 403);
        $server = $this->getServer();

        $row = collect(array_merge($this->active, $this->available))->firstWhere('mod_id', $modId);
        $workshopId = $row['workshop_id'] ?? null;

        $ini = app(IniService::class)->read($server);
        if (!$ini['ok']) {
            return;
        }

        $siblings = [$modId];
        if ($workshopId) {
            foreach (array_merge($this->active, $this->available) as $candidate) {
                if ($candidate['workshop_id'] === $workshopId) {
                    $siblings[] = $candidate['mod_id'];
                }
            }
        }

        $items = $workshopId
            ? array_values(array_filter($ini['workshopItems'], fn ($w) => $w !== $workshopId))
            : $ini['workshopItems'];
        $mods = array_values(array_filter($ini['mods'], fn ($m) => !in_array($m, $siblings, true)));

        app(IniService::class)->write($server, $items, $mods);

        if ($workshopId) {
            try {
                app(DaemonFileRepository::class)->setServer($server)
                    ->deleteFiles('/steamapps/workshop/content/' . ModScanner::APP_ID, [$workshopId]);
            } catch (\Throwable $e) {
                Notification::make()->title(trans('pzmm::messages.notify.files_kept'))->warning()->send();
            }
        }

        $this->forgetCaches();
        $this->load();
        Notification::make()->title(trans('pzmm::messages.notify.removed'))->success()->send();
    }

    public function addMod(): void
    {
        abort_unless($this->canWrite(), 403);

        $input = trim($this->newMod);
        if (!preg_match('/(\d{6,})/', $input, $m)) {
            Notification::make()->title(trans('pzmm::messages.notify.invalid_id'))->danger()->send();

            return;
        }
        $id = $m[1];

        $steam = app(SteamClient::class);

        // A collection URL resolves to its children; a plain item resolves to itself.
        $ids = $steam->collection($id) ?: [$id];

        $server = $this->getServer();
        $ini = app(IniService::class)->read($server);
        if (!$ini['ok']) {
            Notification::make()->title(trans('pzmm::messages.notify.config_error'))->danger()->send();

            return;
        }

        $items = $ini['workshopItems'];
        $mods = $ini['mods'];
        $added = [];
        $store = app(StateStore::class);
        $state = $store->read($server);

        foreach ($ids as $workshopId) {
            if (in_array($workshopId, $items, true)) {
                continue;
            }
            $items[] = $workshopId;
            $added[] = $workshopId;

            if ($this->activateOnAdd) {
                $guesses = $steam->advertisedModIds($workshopId);
                foreach ($guesses as $guess) {
                    if (!in_array($guess, $mods, true)) {
                        $mods[] = $guess;
                    }
                }
                // Remember the guess so it can be corrected after download.
                $state['pending'][$workshopId] = $guesses;
            }
        }

        if (!$added) {
            Notification::make()->title(trans('pzmm::messages.notify.already_added'))->warning()->send();

            return;
        }

        app(IniService::class)->write($server, $items, $mods);
        $store->write($server, $state);
        $this->newMod = '';
        $this->forgetCaches();
        $this->load();

        Notification::make()
            ->title(trans_choice('pzmm::messages.notify.added', count($added), ['count' => count($added)]))
            ->body(trans('pzmm::messages.notify.added_body'))
            ->success()
            ->persistent()
            ->send();
    }

    // ------------------------------------------------------------------ bulk

    /**
     * Tick or untick every row currently visible in a section.
     *
     * Visible, not every mod that exists. The search box is the only practical
     * way to narrow a list of a hundred and twenty, and a select-all that
     * silently reached past the filter would be the most dangerous control on
     * the page: you would tick eight vehicle mods and delete all of them.
     */
    public function selectAll(string $scope): void
    {
        $rows = $scope === 'active'
            ? $this->activeFiltered()
            : array_merge([], ...array_values($this->availableByCategory()));
        $ids = array_column($rows, 'mod_id');
        if (!$ids) {
            return;
        }

        $selected = array_flip($this->selected);
        // Ticking a section that is already fully ticked unticks it, so the same
        // control does both and there is no second button to hunt for.
        $allOn = !array_diff($ids, $this->selected);
        foreach ($ids as $id) {
            if ($allOn) {
                unset($selected[$id]);
            } else {
                $selected[$id] = true;
            }
        }
        $this->selected = array_keys($selected);
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function bulkActivate(): void
    {
        abort_unless($this->canWrite(), 403);

        $ids = $this->currentModIds();
        $before = count($ids);
        foreach ($this->selected as $modId) {
            if (!in_array($modId, $ids, true)) {
                $ids[] = $modId;
            }
        }
        $count = count($ids) - $before;
        if ($count === 0) {
            Notification::make()->title(trans('pzmm::messages.notify.bulk_nothing'))->warning()->send();

            return;
        }

        $this->selected = [];
        $this->persist($ids);
        Notification::make()
            ->title(trans_choice('pzmm::messages.notify.bulk_enabled', $count, ['count' => $count]))
            ->success()->send();
    }

    public function bulkDeactivate(): void
    {
        abort_unless($this->canWrite(), 403);

        $drop = array_flip($this->selected);
        $current = $this->currentModIds();
        $keep = array_values(array_filter($current, fn ($id) => !isset($drop[$id])));
        $count = count($current) - count($keep);
        if ($count === 0) {
            Notification::make()->title(trans('pzmm::messages.notify.bulk_nothing'))->warning()->send();

            return;
        }

        $this->selected = [];
        $this->persist($keep);
        Notification::make()
            ->title(trans_choice('pzmm::messages.notify.bulk_disabled', $count, ['count' => $count]))
            ->success()->send();
    }

    /**
     * Delete every selected mod in one pass.
     *
     * One config write and one delete call, not a loop over remove(). Removing
     * forty mods one at a time would be forty reads, forty writes and forty
     * Wings round trips, and a failure halfway would leave the ini describing
     * mods whose files are already gone.
     */
    public function bulkRemove(): void
    {
        abort_unless($this->canWrite(), 403);
        if (!$this->selected) {
            return;
        }

        $server = $this->getServer();
        $ini = app(IniService::class)->read($server);
        if (!$ini['ok']) {
            Notification::make()->title(trans('pzmm::messages.notify.config_error'))->danger()->send();

            return;
        }

        $rows = array_merge($this->active, $this->available);
        $chosen = array_flip($this->selected);

        // A Workshop item can carry several mods. Deleting the item takes all of
        // them with it, so its siblings have to leave the config as well or it
        // would go on listing mods whose files no longer exist.
        $workshopIds = [];
        foreach ($rows as $row) {
            if (isset($chosen[$row['mod_id']]) && ($row['workshop_id'] ?? '') !== '') {
                $workshopIds[$row['workshop_id']] = true;
            }
        }
        $doomed = $chosen;
        foreach ($rows as $row) {
            if (($row['workshop_id'] ?? '') !== '' && isset($workshopIds[$row['workshop_id']])) {
                $doomed[$row['mod_id']] = true;
            }
        }

        $items = array_values(array_filter($ini['workshopItems'], fn ($w) => !isset($workshopIds[$w])));
        $mods = array_values(array_filter($ini['mods'], fn ($m) => !isset($doomed[$m])));
        app(IniService::class)->write($server, $items, $mods);

        $filesKept = false;
        if ($workshopIds) {
            try {
                // Cast back to strings. Workshop ids are numeric, and PHP turns a
                // numeric string used as an array key into an int, so array_keys
                // hands back integers. Wings wants strings and rejects the whole
                // request with "cannot unmarshal number into Go struct field
                // .files of type string", which surfaced as a successful config
                // write followed by files that were still there.
                $paths = array_map('strval', array_keys($workshopIds));
                app(DaemonFileRepository::class)->setServer($server)
                    ->deleteFiles('/steamapps/workshop/content/' . ModScanner::APP_ID, $paths);
            } catch (\Throwable $e) {
                $filesKept = true;
            }
        }

        $count = count($doomed);
        $this->selected = [];
        $this->forgetCaches();
        $this->load();

        if ($filesKept) {
            Notification::make()->title(trans('pzmm::messages.notify.files_kept'))->warning()->send();
        }
        Notification::make()
            ->title(trans_choice('pzmm::messages.notify.bulk_removed', $count, ['count' => $count]))
            ->success()->send();
    }

    public function restartServer(): void
    {
        abort_unless($this->canRestart(), 403);
        try {
            app(PowerService::class)->setServer($this->getServer())->send('restart');
            Notification::make()->title(trans('pzmm::messages.notify.restarting'))->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title(trans('pzmm::messages.notify.restart_failed'))->body($e->getMessage())->danger()->send();
        }
    }

    public function openChangelog(string $modId): void
    {
        $row = collect(array_merge($this->active, $this->available))->firstWhere('mod_id', $modId);
        $workshopId = $row['workshop_id'] ?? '';
        $this->changelogFor = $modId;
        $this->changelog = $workshopId !== '' ? app(SteamClient::class)->changelog($workshopId) : [];
        $this->changelogFailed = $this->changelog === [];
    }

    public function closeChangelog(): void
    {
        $this->changelogFor = null;
        $this->changelog = [];
        $this->changelogFailed = false;
    }

    public function refresh(): void
    {
        $this->forgetCaches();
        $this->load();
        Notification::make()->title(trans('pzmm::messages.notify.refreshed'))->success()->send();
    }

    public function refreshAutoUpdateStatus(): void
    {
        $this->loadAutoUpdateStatus($this->getServer());
    }

    public function toggleAutoUpdateVerbose(): void
    {
        $this->autoUpdateVerbose = !$this->autoUpdateVerbose;
        $this->loadAutoUpdateStatus($this->getServer());
    }

    private function forgetCaches(): void
    {
        Cache::forget("pzmm:log:{$this->getServer()->id}");
    }

    private function loadAutoUpdateStatus(Server $server): void
    {
        $pendingAt = (int) Cache::get($this->pendingRestartKey($server), 0);
        $status = Cache::get($this->autoUpdateStatusKey($server), []);

        $state = (string) ($status['state'] ?? '');
        if ($state === '') {
            $state = $pendingAt > now()->timestamp ? 'pending_restart' : 'idle';
        }

        $checkedAt = (int) ($status['checked_at'] ?? 0);
        $statusPendingAt = (int) ($status['pending_at'] ?? 0);
        $summary = trim((string) ($status['summary'] ?? ''));
        $details = array_values(array_filter(array_map(
            fn ($line) => trim((string) $line),
            is_array($status['details'] ?? null) ? $status['details'] : []
        ), fn ($line) => $line !== ''));
        $pendingAt = max($pendingAt, $statusPendingAt);

        $this->autoUpdate = [
            'state' => $state,
            'checked_at' => $checkedAt > 0 ? Carbon::createFromTimestamp($checkedAt)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s') : null,
            'pending_at' => $pendingAt > now()->timestamp ? Carbon::createFromTimestamp($pendingAt)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s') : null,
            'summary' => $summary !== '' ? $summary : null,
            'details' => array_slice($details, 0, 40),
        ];
    }

    private function autoUpdateStatusKey(Server $server): string
    {
        return "pzmm:auto-update:status:{$server->id}";
    }

    private function pendingRestartKey(Server $server): string
    {
        return "pzmm:auto-update:pending-restart:{$server->id}";
    }

    // ------------------------------------------------------------------ view

    /** @return array<string,array<int,array<string,mixed>>> */
    public function availableByCategory(): array
    {
        $filtered = $this->filter($this->available);
        $groups = [];
        foreach ($filtered as $row) {
            $groups[$row['category']][] = $row;
        }

        $ordered = [];
        foreach (self::CATEGORY_ORDER as $category) {
            if (!empty($groups[$category])) {
                $ordered[$category] = $groups[$category];
            }
        }

        return $ordered + array_diff_key($groups, $ordered);
    }

    /** @return array<int,array<string,mixed>> */
    public function activeFiltered(): array
    {
        return $this->filter($this->active);
    }

    private function filter(array $rows): array
    {
        $needle = trim(mb_strtolower($this->search));
        if ($needle === '') {
            return $rows;
        }

        return array_values(array_filter($rows, fn ($r) => str_contains(mb_strtolower($r['name'] . ' ' . $r['mod_id'] . ' ' . $r['workshop_id']), $needle)));
    }
}
