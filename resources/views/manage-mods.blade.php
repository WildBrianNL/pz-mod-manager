@php
    // Inline styles for anything structural: a plugin cannot rely on its utility
    // classes existing in the panel's compiled Tailwind build.
    $thumb = 'width:38px;height:38px;min-width:38px;max-width:38px;border-radius:6px;object-fit:cover;flex:0 0 38px;display:block;';
    $rowGap = 'display:flex;align-items:center;gap:.65rem;padding:.4rem 0;';
    $meta = 'display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;font-size:11px;line-height:1.4;';

    $statusMeta = [
        'active'      => ['dot' => '#22c55e', 'label' => trans('pzmm::messages.status.active'),      'color' => '#16a34a'],
        'restart'     => ['dot' => '#f59e0b', 'label' => trans('pzmm::messages.status.restart'),     'color' => '#d97706'],
        'downloading' => ['dot' => '#3b82f6', 'label' => trans('pzmm::messages.status.downloading'), 'color' => '#2563eb'],
        'failed'      => ['dot' => '#ef4444', 'label' => trans('pzmm::messages.status.failed'),       'color' => '#dc2626'],
        'orphan'      => ['dot' => '#ef4444', 'label' => trans('pzmm::messages.status.orphan'),      'color' => '#dc2626'],
        'available'   => ['dot' => '#9ca3af', 'label' => '',                                          'color' => '#6b7280'],
    ];
    $alertStyle = [
        'danger'  => 'border-color:rgba(239,68,68,.4);background:rgba(239,68,68,.06);color:#dc2626;',
        'warning' => 'border-color:rgba(245,158,11,.4);background:rgba(245,158,11,.06);color:#d97706;',
        'info'    => 'border-color:rgba(59,130,246,.4);background:rgba(59,130,246,.06);color:#2563eb;',
    ];
@endphp

<x-filament-panels::page>
    @if ($configError)
        <div style="border:1px solid rgba(239,68,68,.4);background:rgba(239,68,68,.06);border-radius:12px;padding:1rem;font-size:.875rem;color:#dc2626;">
            {{ trans('pzmm::messages.error.' . $configError) }}
        </div>
    @else

    {{-- Stats --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.75rem;">
        @foreach ([
            ['k' => 'active',    'v' => $stats['active'],    'i' => 'tabler-circle-check',   'c' => '#22c55e'],
            ['k' => 'available', 'v' => $stats['available'], 'i' => 'tabler-package',        'c' => '#9ca3af'],
            ['k' => 'restart',   'v' => $stats['restart'],   'i' => 'tabler-refresh-alert',  'c' => $stats['restart'] ? '#f59e0b' : '#9ca3af'],
            ['k' => 'errors',    'v' => $stats['errors'],    'i' => 'tabler-alert-triangle', 'c' => $stats['errors'] ? '#ef4444' : '#9ca3af'],
        ] as $tile)
            <div class="fi-section" style="display:flex;align-items:center;gap:.75rem;padding:.7rem .9rem;border-radius:12px;">
                <x-filament::icon :icon="$tile['i']" style="width:20px;height:20px;flex:0 0 20px;color:{{ $tile['c'] }};" />
                <div style="min-width:0;">
                    <div style="font-size:1.25rem;font-weight:600;line-height:1;">{{ $tile['v'] }}</div>
                    <div style="font-size:10px;text-transform:uppercase;letter-spacing:.04em;opacity:.6;">{{ trans('pzmm::messages.stat.' . $tile['k']) }}</div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Toolbar --}}
    <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;">
        <input type="search" wire:model.live.debounce.250ms="search" placeholder="{{ trans('pzmm::messages.search') }}"
            class="fi-input" style="flex:1 1 200px;min-width:0;border-radius:8px;padding:.45rem .7rem;font-size:.875rem;" />

        @if ($this->canWrite())
            <input type="text" wire:model="newMod" wire:keydown.enter="addMod" placeholder="{{ trans('pzmm::messages.add_placeholder') }}"
                class="fi-input" style="flex:2 1 280px;min-width:0;border-radius:8px;padding:.45rem .7rem;font-size:.875rem;" />
            <x-filament::button wire:click="addMod" wire:target="addMod" wire:loading.attr="disabled" icon="tabler-download">
                {{ trans('pzmm::messages.action.add') }}
            </x-filament::button>
            <label style="display:flex;align-items:center;gap:.35rem;font-size:11px;opacity:.7;white-space:nowrap;cursor:pointer;"
                   title="{{ trans('pzmm::messages.auto_activate_hint') }}">
                <input type="checkbox" wire:model="activateOnAdd" /> {{ trans('pzmm::messages.auto_activate') }}
            </label>
            <x-filament::button color="gray" icon="tabler-arrows-sort" wire:click="autoSort" wire:target="autoSort" wire:loading.attr="disabled">
                {{ trans('pzmm::messages.action.sort') }}
            </x-filament::button>
        @endif
        <x-filament::button color="gray" icon="tabler-refresh" wire:click="refresh" wire:target="refresh" wire:loading.attr="disabled">
            {{ trans('pzmm::messages.action.refresh') }}
        </x-filament::button>
        @if ($this->canRestart())
            <x-filament::button :color="$stats['restart'] ? 'warning' : 'gray'" icon="tabler-reload"
                wire:click="restartServer" wire:confirm="{{ trans('pzmm::messages.confirm.restart') }}">
                {{ trans('pzmm::messages.action.restart') }}
            </x-filament::button>
        @endif
    </div>

    {{-- Alerts --}}
    @if (count($alerts))
        <div style="display:flex;flex-direction:column;gap:.4rem;">
            @foreach ($alerts as $alert)
                <div style="border:1px solid;border-radius:8px;padding:.5rem .7rem;display:flex;align-items:flex-start;gap:.6rem;font-size:12px;{{ $alertStyle[$alert['type']] ?? $alertStyle['info'] }}">
                    <x-filament::icon :icon="$alert['type'] === 'danger' ? 'tabler-alert-octagon' : ($alert['type'] === 'warning' ? 'tabler-alert-triangle' : 'tabler-info-circle')"
                        style="width:15px;height:15px;flex:0 0 15px;margin-top:1px;" />
                    <span style="flex:1;line-height:1.5;">{{ $alert['text'] }}</span>
                    @if ($alert['action'])
                        <button type="button" style="flex:none;font-weight:600;text-decoration:underline;"
                            wire:click="{{ $alert['action']['method'] }}({{ $alert['action']['arg'] !== null ? \Illuminate\Support\Js::from($alert['action']['arg']) : '' }})"
                            @if ($alert['action']['method'] === 'restartServer') wire:confirm="{{ trans('pzmm::messages.confirm.restart') }}" @endif>
                            {{ $alert['action']['label'] }}
                        </button>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    {{-- Enabled --}}
    <x-filament::section>
        <x-slot name="heading">
            <span style="font-size:.875rem;font-weight:600;">{{ trans('pzmm::messages.section.active') }}</span>
            <span style="font-size:11px;font-weight:400;opacity:.6;">{{ trans('pzmm::messages.section.active_hint') }}</span>
        </x-slot>

        @php $activeRows = $this->activeFiltered(); $activeCount = count($activeRows); @endphp
        <div wire:loading.class="fi-disabled" style="transition:opacity .15s;"
             wire:target="activate,deactivate,move,reorder,toggleLock,autoSort,remove,refresh,addMod,removeOrphans,addMapsToConfig"
             x-data="{
                 from: null,
                 start(i, e) { this.from = i; e.dataTransfer.effectAllowed = 'move'; },
                 over(e) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; },
                 drop(to, e) {
                     e.preventDefault();
                     const from = this.from;
                     this.from = null;
                     if (from === null || from === to) return;
                     // Read the order from the DOM so it always matches what the
                     // user sees, then send the whole permuted list at once.
                     // $root, not $el: this method is invoked from a row handler,
                     // so $el is that row and querySelectorAll would search only
                     // its descendants and find nothing.
                     const ids = Array.from(this.$root.querySelectorAll('[data-mod-id]'))
                                      .map(n => n.dataset.modId);
                     const [moved] = ids.splice(from, 1);
                     ids.splice(to, 0, moved);
                     this.$wire.reorder(ids);
                 }
             }">
            @forelse ($activeRows as $i => $row)
                @php $st = $statusMeta[$row['status']]; $drag = $this->canWrite() && $search === ''; @endphp
                <div style="{{ $rowGap }}border-bottom:1px solid rgba(128,128,128,.12);{{ $drag ? 'cursor:grab;' : '' }}"
                     wire:key="a-{{ $row['mod_id'] }}"
                     data-mod-id="{{ $row['mod_id'] }}"
                     @if ($drag)
                         draggable="true"
                         x-on:dragstart="start({{ $i }}, $event)"
                         x-on:dragover="over($event)"
                         x-on:drop="drop({{ $i }}, $event)"
                         x-on:dragend="from = null"
                     @endif>
                    @if ($this->canWrite() && $search === '')
                        <div style="display:flex;flex-direction:column;line-height:.7;font-size:10px;opacity:.45;flex:none;">
                            <button type="button" wire:click="move({{ \Illuminate\Support\Js::from($row['mod_id']) }},'up')" @disabled($i === 0) style="padding:1px 2px;">▲</button>
                            <button type="button" wire:click="move({{ \Illuminate\Support\Js::from($row['mod_id']) }},'down')" @disabled($i === $activeCount - 1) style="padding:1px 2px;">▼</button>
                        </div>
                    @endif
                    <span style="width:22px;text-align:right;font-size:11px;font-variant-numeric:tabular-nums;opacity:.45;flex:none;">{{ $i + 1 }}</span>

                    @if ($this->canWrite())
                        @php $locked = isset($locks[$row['mod_id']]); @endphp
                        <button type="button" title="{{ $locked ? trans('pzmm::messages.action.unlock') : trans('pzmm::messages.action.lock') }}"
                                wire:click="toggleLock({{ \Illuminate\Support\Js::from($row['mod_id']) }})"
                                wire:target="toggleLock({{ \Illuminate\Support\Js::from($row['mod_id']) }})"
                                wire:loading.attr="disabled"
                                style="flex:none;padding:0 2px;line-height:1;font-size:12px;{{ $locked ? 'opacity:1;' : 'opacity:.3;' }}">
                            <x-filament::icon icon="{{ $locked ? 'tabler-lock' : 'tabler-lock-open' }}"
                                              style="width:14px;height:14px;{{ $locked ? 'color:#f59e0b;' : '' }}" />
                        </button>
                    @endif

                    @if ($row['preview'])
                        <img src="{{ $row['preview'] }}" alt="" loading="lazy" style="{{ $thumb }}" />
                    @else
                        <div style="{{ $thumb }}display:grid;place-items:center;background:rgba(128,128,128,.15);font-size:12px;font-weight:600;opacity:.6;">{{ mb_strtoupper(mb_substr($row['name'], 0, 1)) }}</div>
                    @endif

                    <div style="flex:1;min-width:0;">
                        <div style="display:flex;align-items:center;gap:.4rem;min-width:0;">
                            <span style="width:6px;height:6px;border-radius:50%;flex:none;background:{{ $st['dot'] }};"></span>
                            @if ($row['url'])
                                <a href="{{ $row['url'] }}" target="_blank" rel="noopener" style="font-size:.875rem;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $row['name'] }}</a>
                            @else
                                <span style="font-size:.875rem;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $row['name'] }}</span>
                            @endif
                            <span style="font-size:9px;text-transform:uppercase;letter-spacing:.04em;padding:1px 5px;border-radius:4px;background:rgba(128,128,128,.15);opacity:.7;flex:none;">
                                {{ trans('pzmm::messages.category.' . $row['category']) }}
                            </span>
                        </div>
                        <div style="{{ $meta }}opacity:.65;">
                            <code style="font-family:ui-monospace,monospace;">{{ $row['mod_id'] }}</code>
                            @if ($row['version'])<span>{{ trans('pzmm::messages.badge.version', ['version' => $row['version']]) }}</span>@endif
                            @if ($row['bundled'])<span title="{{ trans('pzmm::messages.tooltip.bundled', ['title' => $row['bundle_title'] ?? $row['workshop_id']]) }}" style="cursor:help;">🔗</span>@endif
                            @if ($st['label'])<span style="color:{{ $st['color'] }};">{{ $st['label'] }}</span>@endif
                            @if ($row['update_available'])<span style="color:#2563eb;font-weight:600;">⬆ {{ trans('pzmm::messages.badge.update') }}</span>@endif
                            @if ($row['compat'] === 'mismatch')<span style="color:#d97706;">{{ trans('pzmm::messages.badge.build_mismatch') }}</span>@endif
                            @if ($row['errors'] > 0)<span style="color:#dc2626;font-weight:600;">{{ trans_choice('pzmm::messages.badge.errors', $row['errors'], ['count' => $row['errors']]) }}</span>@endif
                            @if ($row['maps'])<span>🗺 {{ implode(', ', $row['maps']) }}</span>@endif
                        </div>
                    </div>

                    <div style="display:flex;align-items:center;gap:.3rem;flex:none;">
                        @if ($row['workshop_id'])
                            <x-filament::icon-button icon="tabler-history" color="gray" size="sm"
                                wire:click="openChangelog({{ \Illuminate\Support\Js::from($row['mod_id']) }})"
                                :label="trans('pzmm::messages.tooltip.changelog')" />
                        @endif
                        @if ($this->canWrite())
                            <x-filament::icon-button icon="tabler-circle-minus" color="warning" size="sm"
                                wire:click="deactivate({{ \Illuminate\Support\Js::from($row['mod_id']) }})"
                                wire:confirm="{{ trans('pzmm::messages.confirm.disable') }}"
                                :label="trans('pzmm::messages.tooltip.disable')" />
                            <x-filament::icon-button icon="tabler-trash" color="danger" size="sm"
                                wire:click="remove({{ \Illuminate\Support\Js::from($row['mod_id']) }})"
                                wire:confirm="{{ trans('pzmm::messages.confirm.delete') }}"
                                :label="trans('pzmm::messages.tooltip.delete')" />
                        @endif
                    </div>
                </div>
            @empty
                <p style="font-size:.875rem;opacity:.6;padding:.5rem 0;">{{ trans('pzmm::messages.empty.active') }}</p>
            @endforelse
        </div>
    </x-filament::section>

    {{-- Available --}}
    <x-filament::section collapsible>
        <x-slot name="heading">
            <span style="font-size:.875rem;font-weight:600;">{{ trans('pzmm::messages.section.available') }}</span>
            <span style="font-size:11px;font-weight:400;opacity:.6;">{{ trans('pzmm::messages.section.available_hint') }}</span>
        </x-slot>

        <div wire:loading.class="fi-disabled" wire:target="activate,remove,refresh,addMod">
            @forelse ($this->availableByCategory() as $category => $rows)
                <div style="margin-top:.6rem;">
                    <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;opacity:.5;margin-bottom:.2rem;">
                        {{ trans('pzmm::messages.category.' . $category) }} ({{ count($rows) }})
                    </div>
                    @foreach ($rows as $row)
                        <div style="{{ $rowGap }}border-bottom:1px solid rgba(128,128,128,.12);" wire:key="v-{{ $row['mod_id'] }}">
                            @if ($row['preview'])
                                <img src="{{ $row['preview'] }}" alt="" loading="lazy" style="{{ $thumb }}opacity:.65;" />
                            @else
                                <div style="{{ $thumb }}display:grid;place-items:center;background:rgba(128,128,128,.15);font-size:12px;font-weight:600;opacity:.6;">{{ mb_strtoupper(mb_substr($row['name'], 0, 1)) }}</div>
                            @endif

                            <div style="flex:1;min-width:0;">
                                @if ($row['url'])
                                    <a href="{{ $row['url'] }}" target="_blank" rel="noopener" style="font-size:.875rem;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $row['name'] }}</a>
                                @else
                                    <span style="font-size:.875rem;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $row['name'] }}</span>
                                @endif
                                <div style="{{ $meta }}opacity:.6;">
                                    <code style="font-family:ui-monospace,monospace;">{{ $row['mod_id'] }}</code>
                                    @if ($row['version'])<span>{{ trans('pzmm::messages.badge.version', ['version' => $row['version']]) }}</span>@endif
                                    @if ($row['bundled'])<span title="{{ trans('pzmm::messages.tooltip.bundled', ['title' => $row['bundle_title'] ?? $row['workshop_id']]) }}" style="cursor:help;">🔗</span>@endif
                                    @if ($row['compat'] === 'mismatch')<span style="color:#d97706;">{{ trans('pzmm::messages.badge.build_mismatch') }}</span>@endif
                                    @if ($row['maps'])<span>🗺</span>@endif
                                </div>
                            </div>

                            <div style="display:flex;align-items:center;gap:.3rem;flex:none;">
                                @if ($row['workshop_id'])
                                    <x-filament::icon-button icon="tabler-history" color="gray" size="sm"
                                        wire:click="openChangelog({{ \Illuminate\Support\Js::from($row['mod_id']) }})"
                                        :label="trans('pzmm::messages.tooltip.changelog')" />
                                @endif
                                @if ($this->canWrite())
                                    <x-filament::button size="xs" color="gray" icon="tabler-plus"
                                        wire:click="activate({{ \Illuminate\Support\Js::from($row['mod_id']) }})">
                                        {{ trans('pzmm::messages.action.enable') }}
                                    </x-filament::button>
                                    <x-filament::icon-button icon="tabler-trash" color="danger" size="sm"
                                        wire:click="remove({{ \Illuminate\Support\Js::from($row['mod_id']) }})"
                                        wire:confirm="{{ trans('pzmm::messages.confirm.delete') }}"
                                        :label="trans('pzmm::messages.tooltip.delete')" />
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @empty
                <p style="font-size:.875rem;opacity:.6;padding:.5rem 0;">{{ trans('pzmm::messages.empty.available') }}</p>
            @endforelse
        </div>
    </x-filament::section>

    {{-- Changelog overlay --}}
    @if ($changelogFor)
        @php $clRow = collect(array_merge($active, $available))->firstWhere('mod_id', $changelogFor); @endphp
        <div style="position:fixed;inset:0;z-index:50;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;padding:1rem;"
             wire:click.self="closeChangelog">
            <div class="fi-section" style="max-width:640px;width:100%;max-height:75vh;display:flex;flex-direction:column;border-radius:12px;overflow:hidden;">
                <div style="display:flex;align-items:center;gap:.6rem;padding:.85rem 1rem;border-bottom:1px solid rgba(128,128,128,.2);">
                    <strong style="flex:1;font-size:.9rem;">{{ trans('pzmm::messages.changelog.title', ['mod' => $clRow['name'] ?? $changelogFor]) }}</strong>
                    <x-filament::icon-button icon="tabler-x" color="gray" size="sm" wire:click="closeChangelog" :label="trans('pzmm::messages.changelog.close')" />
                </div>
                <div style="overflow-y:auto;padding:1rem;font-size:.8rem;line-height:1.55;">
                    @if ($changelogFailed)
                        <p style="opacity:.7;">{{ trans('pzmm::messages.changelog.empty') }}</p>
                    @else
                        @foreach ($changelog as $entry)
                            <div style="margin-bottom:1rem;">
                                <div style="font-weight:600;font-size:.75rem;opacity:.7;margin-bottom:.2rem;">{{ $entry['date'] }}</div>
                                <div style="white-space:pre-wrap;">{{ $entry['text'] }}</div>
                            </div>
                        @endforeach
                    @endif
                </div>
                <div style="padding:.7rem 1rem;border-top:1px solid rgba(128,128,128,.2);display:flex;justify-content:flex-end;gap:.5rem;">
                    @if (($clRow['workshop_id'] ?? '') !== '')
                        <x-filament::button size="sm" color="gray" tag="a"
                            href="https://steamcommunity.com/sharedfiles/filedetails/changelog/{{ $clRow['workshop_id'] }}" target="_blank">
                            {{ trans('pzmm::messages.changelog.open_steam') }}
                        </x-filament::button>
                    @endif
                    <x-filament::button size="sm" wire:click="closeChangelog">{{ trans('pzmm::messages.changelog.close') }}</x-filament::button>
                </div>
            </div>
        </div>
    @endif

    @endif
</x-filament-panels::page>
