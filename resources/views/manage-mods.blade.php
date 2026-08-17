@php
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
    {{-- Inside the page component, not before it. A Livewire component
         must have exactly one root element; a <style> sibling gives it two,
         the DOM diffing gives up, and every wire:model and wire:click on the
         page silently stops working. --}}
    {{-- One scoped stylesheet rather than the same inline style repeated on every
         row. A plugin cannot rely on the panel's compiled Tailwind containing any
         particular utility class, and hover states cannot be expressed inline at
         all, which is why the row actions used to sit at full strength on every
         row at once. Every class is prefixed so nothing here can collide with the
         panel's own. --}}
    <style>
        .pzmm-sec{font-size:10.5px;text-transform:uppercase;letter-spacing:.09em;opacity:.45;font-weight:600;margin:0}
        .pzmm-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.75rem}
        .pzmm-tile{padding:.7rem .9rem;border-radius:12px}
        .pzmm-tile .n{font-size:1.35rem;font-weight:700;line-height:1.1}
        .pzmm-tile .l{font-size:10px;text-transform:uppercase;letter-spacing:.05em;opacity:.55;margin-top:.15rem}
        .pzmm-bar{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center}
        .pzmm-field{border:1px solid rgba(128,128,128,.35);background:rgba(128,128,128,.06);border-radius:8px;
                    padding:.45rem .7rem;font-size:.875rem;min-width:0;color:inherit}
        .pzmm-field:focus{outline:none;border-color:rgba(99,102,241,.7)}
        .pzmm-alert{display:flex;gap:.6rem;align-items:flex-start;border:1px solid;border-radius:8px;padding:.5rem .7rem;font-size:12px;line-height:1.5}
        .pzmm-alert .act{margin-left:auto;flex:none;font-weight:600;text-decoration:underline;white-space:nowrap}
        .pzmm-listhead{display:flex;align-items:center;gap:.5rem;padding:.7rem .9rem;border-bottom:1px solid rgba(128,128,128,.2)}
        .pzmm-listhead h3{margin:0;font-size:.845rem;font-weight:600}
        .pzmm-listhead .hint{font-size:11px;opacity:.55}
        .pzmm-cat{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;opacity:.45;padding:.5rem .9rem .15rem}
        .pzmm-row{display:flex;align-items:center;gap:.6rem;padding:.45rem .9rem;border-bottom:1px solid rgba(128,128,128,.12)}
        .pzmm-row:last-child{border-bottom:0}
        .pzmm-row:hover{background:rgba(128,128,128,.06)}
        .pzmm-idx{width:18px;text-align:right;font-size:11px;font-variant-numeric:tabular-nums;opacity:.45;flex:none}
        .pzmm-thumb{width:30px;height:30px;min-width:30px;border-radius:5px;object-fit:cover;flex:0 0 30px;display:block}
        .pzmm-mi{flex:1;min-width:0}
        .pzmm-t{display:flex;align-items:center;gap:.4rem;min-width:0}
        .pzmm-name{font-size:.845rem;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .pzmm-sub{display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;font-size:11px;line-height:1.4;opacity:.6;margin-top:.05rem}
        .pzmm-sub code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
        .pzmm-pill{font-size:9.5px;text-transform:uppercase;letter-spacing:.05em;padding:1px 6px;border-radius:20px;border:1px solid rgba(128,128,128,.35);opacity:.7;flex:none}
        .pzmm-pill-up{text-transform:none;letter-spacing:0;font-size:10.5px;opacity:1;flex:none;padding:1px 7px;border-radius:20px;
                      border:1px solid rgba(245,158,11,.55);background:rgba(245,158,11,.12);color:#d97706;font-weight:600}
        .pzmm-acts{display:flex;align-items:center;gap:.25rem;flex:none;opacity:.5;transition:opacity .12s}
        .pzmm-row:hover .pzmm-acts,.pzmm-acts:focus-within{opacity:1}
        .pzmm-empty{font-size:.845rem;opacity:.55;padding:.9rem}
        /* Changelog shortcut in the history. Dotted rather than solid: it opens a
           panel, it does not leave the page, and a full underline here reads as
           twenty outbound links in a list nobody asked to be loud. */
        .pzmm-cl-link{text-align:left;cursor:pointer;text-decoration:underline dotted;text-underline-offset:2px}
        .pzmm-cl-link:hover{text-decoration:underline solid}
        /* A flex summary loses its disclosure triangle in every browser and never
           gets one back in Safari, so the ones that use this class carry their own. */
        .pzmm-sum{cursor:pointer;list-style:none}
        .pzmm-sum::-webkit-details-marker{display:none}
        .pzmm-chev{flex:none;opacity:.5;transition:transform .15s;display:inline-block;font-size:.7rem}
        details[open]>.pzmm-sum .pzmm-chev{transform:rotate(90deg)}
        @media (hover:none){.pzmm-acts{opacity:1}}
    </style>

    @if ($configError)
        <div style="border:1px solid rgba(239,68,68,.4);background:rgba(239,68,68,.06);border-radius:12px;padding:1rem;font-size:.875rem;color:#dc2626;">
            {{ trans('pzmm::messages.error.' . $configError) }}
        </div>
    @else

    {{-- Auto-update: status strip, and the settings behind it --}}
    @php
        // What the plugin sends when a field is left alone. Shown as the
        // placeholder on the message boxes so an emptied one still says what
        // it used to do rather than sitting there blank.
        $defaults = \WildBrianNL\PZModManager\Services\StateStore::AUTO_DEFAULTS;
        $phase = $autoRun['phase'] ?? 'idle';
        $on = (bool) ($auto['enabled'] ?? false);
        // Off is grey rather than green: the feature is not "healthy", it is
        // simply not running, and those should not look the same at a glance.
        $degraded = (bool) ($autoRun['degraded'] ?? false);
        $tone = match (true) {
            $phase === 'failed' => ['t' => '#dc2626', 'i' => 'tabler-alert-octagon'],
            $phase === 'warning' => ['t' => '#d97706', 'i' => 'tabler-clock-hour-4'],
            $phase === 'verifying' => ['t' => '#2563eb', 'i' => 'tabler-reload'],
            // Before the healthy case, not after it. "We could not compare
            // everything against Steam" was being painted green with a tick,
            // which is the exact thing this release exists to stop.
            $on && $degraded => ['t' => '#d97706', 'i' => 'tabler-alert-triangle'],
            $on => ['t' => '#16a34a', 'i' => 'tabler-circle-check'],
            default => ['t' => '#6b7280', 'i' => 'tabler-clock-off'],
        };
        $restartAt = (int) ($autoRun['restart_at'] ?? 0);

        // The phase sentence: what the scheduler is doing right now.
        $phaseLine = $phase === 'warning' && $restartAt
            ? trans('pzmm::messages.auto.phase.warning', [
                'minutes' => max(0, (int) ceil(($restartAt - now()->timestamp) / 60)),
                'reason' => $autoRun['reason'] ?? '',
            ])
            : trans('pzmm::messages.auto.phase.' . ($on || $phase !== 'idle' ? $phase : 'off'), [
                'minutes' => (int) ($auto['check_minutes'] ?? 5),
            ]);

        // The headline is what the last check concluded, because that is the
        // one thing somebody opening this page at midnight wants to read. The
        // phase drops to the line underneath it.
        $headline = trim((string) ($autoRun['note'] ?? '')) !== '' ? $autoRun['note'] : $phaseLine;

        // And underneath that, why the plugin believes it. A status nobody can
        // check is a status nobody should trust, and "everything is fine" is
        // exactly the sentence that has to show its working.
        $why = [];
        $why[] = trans_choice('pzmm::messages.auto.watching', ($stats['active'] ?? 0), ['count' => ($stats['active'] ?? 0)])
            . ' ' . trans('pzmm::messages.auto.watching_' . (($auto['check_game'] ?? true) ? 'game' : 'nogame'));

        $checkedAt = (int) ($autoRun['checked_at'] ?? 0);
        $nextAt = (int) ($autoRun['next_check_at'] ?? 0);
        if ($checkedAt > 0) {
            $why[] = trans('pzmm::messages.auto.checked', [
                'ago' => \Carbon\Carbon::createFromTimestamp($checkedAt)->diffForHumans(),
                'next' => $nextAt > now()->timestamp
                    ? \Carbon\Carbon::createFromTimestamp($nextAt)->setTimezone(config('app.timezone'))->format('H:i')
                    : trans('pzmm::messages.auto.next_soon'),
            ]);
        } elseif ($on) {
            $why[] = trans('pzmm::messages.auto.never_checked');
        }

        $last = $history[0] ?? null;
        $why[] = $last
            ? trans('pzmm::messages.auto.last_restart', ['when' => $last['ago'], 'reason' => trans('pzmm::messages.history.reason.' . ($last['trigger'] === 'manual' ? 'manual' : 'update'))])
            : trans('pzmm::messages.auto.no_restarts');
    @endphp

    <h2 class="pzmm-sec">{{ trans('pzmm::messages.auto.section') }}</h2>

    {{-- Status. A panel-coloured card with one coloured edge, not a card washed
         in the status colour: a green box the height of the settings form reads
         as "this whole area is a notification" rather than as the page. --}}
    <div wire:poll.30s="refreshAuto" class="fi-section"
         style="border-left:3px solid {{ $tone['t'] }};border-radius:12px;padding:.85rem 1rem;">
        <div style="display:flex;align-items:flex-start;gap:.55rem;">
            <x-filament::icon :icon="$tone['i']" style="width:18px;height:18px;flex:0 0 18px;color:{{ $tone['t'] }};margin-top:2px;" />
            <div style="flex:1;min-width:0;line-height:1.45;">
                <div style="font-size:.95rem;font-weight:600;color:{{ $tone['t'] }};">
                    {{ $headline }}
                </div>
                <div style="font-size:.78rem;opacity:.85;">{{ $phaseLine }}</div>

                <ul style="margin:.5rem 0 0;padding:.45rem 0 0 1rem;border-top:1px solid rgba(128,128,128,.2);font-size:.74rem;opacity:.75;list-style:disc;">
                    @foreach ($why as $line)
                        <li style="margin:.1rem 0;">{{ $line }}</li>
                    @endforeach
                </ul>

                @if (!$eggAutoUpdate)
                    <div style="font-size:.74rem;color:#d97706;margin-top:.2rem;">
                        {{ trans('pzmm::messages.auto.needs_flag') }}
                        @if ($this->canWrite())
                            <button type="button" wire:click="enableEggAutoUpdate" style="font-weight:600;text-decoration:underline;">
                                {{ trans('pzmm::messages.auto.set_flag') }}
                            </button>
                        @endif
                    </div>
                @endif
            </div>
            <div style="display:flex;gap:.3rem;flex:none;flex-wrap:wrap;justify-content:flex-end;">
                @if ($phase === 'failed' && $this->canWrite())
                    <x-filament::button size="xs" color="danger" wire:click="clearAutoFailure">
                        {{ trans('pzmm::messages.auto.clear_failure') }}
                    </x-filament::button>
                @endif
                {{-- Out here rather than buried in the settings panel: checking
                     is the thing people come to this card to do. --}}
                <x-filament::button size="xs" color="gray" wire:click="checkAutoNow" wire:target="checkAutoNow" wire:loading.attr="disabled">
                    {{ trans('pzmm::messages.auto.check_now') }}
                </x-filament::button>
            </div>
        </div>
    </div>

    {{-- wire:ignore.self so a re-render leaves the open/closed state alone.
         Livewire morphs attributes from the freshly rendered markup, which has
         no "open", so without this the status card's 30 second poll folds this
         shut under whoever is typing in it. Children are still morphed, so the
         inputs inside keep working. --}}
    {{-- Restart history. Closed by default: it answers a question that is only
         asked after something happened, and an open list of twenty restarts on
         every page load is clutter for the other ninety-nine visits. Heading
         inside the condition, not above it: an empty section still announces
         itself, and a header over nothing reads as something that failed to
         load. --}}
    @if ($history)
        <h2 class="pzmm-sec">{{ trans('pzmm::messages.history.title') }}</h2>

        @php
            $outcomeMeta = [
                'verified'   => ['c' => '#16a34a', 'i' => 'tabler-circle-check'],
                'failed'     => ['c' => '#dc2626', 'i' => 'tabler-alert-octagon'],
                'pending'    => ['c' => '#2563eb', 'i' => 'tabler-reload'],
                'unverified' => ['c' => '#6b7280', 'i' => 'tabler-player-play'],
            ];
        @endphp

        <details class="fi-section" style="border-radius:12px;" wire:ignore.self>
            <summary class="pzmm-sum" style="display:flex;align-items:center;gap:.5rem;padding:.7rem .9rem;font-size:.82rem;font-weight:600;">
                <span class="pzmm-chev">&#9656;</span>
                <x-filament::icon icon="tabler-history" style="width:16px;height:16px;flex:0 0 16px;opacity:.7;" />
                {{ trans('pzmm::messages.history.title') }}
                <span style="margin-left:auto;font-weight:400;opacity:.6;font-size:.74rem;">
                    {{ trans('pzmm::messages.history.summary', ['when' => $history[0]['ago']]) }}
                </span>
            </summary>

            @foreach ($history as $entry)
                @php $meta = $outcomeMeta[$entry['outcome']] ?? $outcomeMeta['unverified']; @endphp
                <div style="display:grid;grid-template-columns:130px 1fr;gap:.9rem;padding:.7rem .9rem;border-top:1px solid rgba(128,128,128,.18);">
                    <div style="font-size:.75rem;line-height:1.4;">
                        {{ $entry['at'] }}
                        <span style="display:block;opacity:.55;font-size:.7rem;">{{ $entry['ago'] }}</span>
                    </div>

                    <div style="min-width:0;">
                        <div style="display:flex;align-items:center;gap:.45rem;flex-wrap:wrap;font-size:.75rem;margin-bottom:.35rem;">
                            <span style="font-weight:600;">
                                {{ $entry['trigger'] === 'manual'
                                    ? trans('pzmm::messages.history.trigger.manual')
                                    : trans('pzmm::messages.history.trigger.auto', ['reason' => $entry['reason']]) }}
                            </span>
                            <span style="display:inline-flex;align-items:center;gap:.2rem;color:{{ $meta['c'] }};">
                                <x-filament::icon :icon="$meta['i']" style="width:13px;height:13px;flex:0 0 13px;" />
                                {{ trans('pzmm::messages.history.outcome.' . $entry['outcome']) }}
                            </span>
                            @if ($entry['down'])
                                <span style="opacity:.6;">{{ trans('pzmm::messages.history.down', ['time' => $entry['down']]) }}</span>
                            @endif
                        </div>

                        @foreach ($entry['changes'] as $change)
                            <div style="font-size:.74rem;padding:.1rem 0;display:flex;gap:.5rem;flex-wrap:wrap;align-items:baseline;">
                                {{-- The name is the changelog shortcut when the change
                                     came from a Workshop item. Same overlay the mod list
                                     opens, so "what changed here" is one click from the
                                     line that says something changed. --}}
                                @if ($change['workshop_id'] !== '')
                                    <button type="button" class="pzmm-cl-link"
                                            wire:click="openChangelog({{ \Illuminate\Support\Js::from($change['workshop_id']) }}, {{ \Illuminate\Support\Js::from($change['name']) }})"
                                            title="{{ trans('pzmm::messages.tooltip.changelog') }}">
                                        {{ $change['name'] }}
                                    </button>
                                @else
                                    <span>{{ $change['name'] }}</span>
                                @endif
                                @if ($change['pair'])
                                    <span style="font-family:ui-monospace,monospace;font-size:.7rem;opacity:.75;">
                                        {{ $change['pair'][0] }} &rarr; <span style="color:#16a34a;">{{ $change['pair'][1] }}</span>
                                    </span>
                                @elseif ($change['from_only'] !== '')
                                    <span style="font-family:ui-monospace,monospace;font-size:.7rem;opacity:.6;">
                                        {{ trans('pzmm::messages.history.was', ['value' => $change['from_only']]) }}
                                    </span>
                                @endif
                            </div>
                        @endforeach

                        <div style="font-size:.7rem;opacity:.55;margin-top:.3rem;">
                            @if ($entry['by'] !== '')
                                {{ trans('pzmm::messages.history.by', ['who' => $entry['by']]) }}
                            @endif
                            @if ($entry['players'] !== null)
                                {{ trans_choice('pzmm::messages.history.players', $entry['players'], ['count' => $entry['players']]) }}
                            @endif
                            {{ $entry['backup_id']
                                ? trans('pzmm::messages.history.backup', ['id' => $entry['backup_id']])
                                : trans('pzmm::messages.history.no_backup') }}
                        </div>

                        @if ($entry['note'] !== '')
                            <div style="margin-top:.35rem;border:1px solid rgba(239,68,68,.35);background:rgba(239,68,68,.06);border-radius:8px;padding:.35rem .5rem;font-size:.72rem;color:#dc2626;">
                                {{ $entry['note'] }}
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach

            <div style="padding:.55rem .9rem;border-top:1px solid rgba(128,128,128,.18);font-size:.7rem;opacity:.55;">
                {{ trans('pzmm::messages.history.scope') }}
            </div>
        </details>
    @endif

    <h2 class="pzmm-sec">{{ trans('pzmm::messages.auto.settings') }}</h2>

    {{-- Settings, its own card and closed by default. It used to unfold inside
         the status card, which turned a one-line status into a tinted block the
         height of a form. --}}
    <details class="fi-section" style="border-radius:12px;" wire:ignore.self>
        <summary class="pzmm-sum" style="display:flex;align-items:center;gap:.5rem;padding:.7rem .9rem;font-size:.82rem;font-weight:600;">
            <span class="pzmm-chev">&#9656;</span>
            <x-filament::icon icon="tabler-settings" style="width:16px;height:16px;flex:0 0 16px;opacity:.7;" />
            {{ trans('pzmm::messages.auto.settings') }}
            <span style="margin-left:auto;font-weight:400;opacity:.6;font-size:.74rem;">
                {{ trans('pzmm::messages.auto.settings_hint') }}
            </span>
        </summary>

        <div style="padding:.85rem 1rem;border-top:1px solid rgba(128,128,128,.2);">
                <p style="font-size:.74rem;opacity:.8;line-height:1.5;margin-bottom:.6rem;">
                    {{ trans('pzmm::messages.auto.explain') }}
                </p>

                <label style="display:flex;align-items:center;gap:.4rem;font-size:.8rem;font-weight:600;margin-bottom:.6rem;cursor:pointer;">
                    <input type="checkbox" wire:model="auto.enabled" @checked($auto['enabled'] ?? false) @disabled(!$this->canWrite()) />
                    {{ trans('pzmm::messages.auto.enabled') }}
                </label>

                @php
                    // Named choices instead of five number boxes. The old form
                    // asked an operator to invent a warning window in minutes
                    // before it would let them use the feature at all.
                    //
                    // A stored value that is not on a list is added to it rather
                    // than rounded to the nearest one that is: somebody who set
                    // seven minutes by hand meant seven, and opening the panel
                    // must not quietly turn it into five.
                    $choices = function (string $key, array $options) use ($auto) {
                        $current = (int) ($auto[$key] ?? 0);
                        if (!array_key_exists($current, $options)) {
                            $options[$current] = trans('pzmm::messages.auto.choice.custom', ['value' => $current]);
                            ksort($options);
                        }

                        return $options;
                    };
                    $simple = [
                        ['k' => 'warn_minutes', 'o' => $choices('warn_minutes', [
                            0 => trans('pzmm::messages.auto.choice.warn_none'),
                            2 => trans_choice('pzmm::messages.auto.choice.minutes', 2, ['count' => 2]),
                            5 => trans_choice('pzmm::messages.auto.choice.minutes', 5, ['count' => 5]) . ' ' . trans('pzmm::messages.auto.choice.recommended'),
                            10 => trans_choice('pzmm::messages.auto.choice.minutes', 10, ['count' => 10]),
                            15 => trans_choice('pzmm::messages.auto.choice.minutes', 15, ['count' => 15]),
                        ])],
                        ['k' => 'check_minutes', 'o' => $choices('check_minutes', [
                            5 => trans_choice('pzmm::messages.auto.choice.minutes', 5, ['count' => 5]) . ' ' . trans('pzmm::messages.auto.choice.recommended'),
                            15 => trans_choice('pzmm::messages.auto.choice.minutes', 15, ['count' => 15]),
                            30 => trans_choice('pzmm::messages.auto.choice.minutes', 30, ['count' => 30]),
                            60 => trans_choice('pzmm::messages.auto.choice.minutes', 60, ['count' => 60]),
                        ])],
                    ];
                @endphp

                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:.6rem;margin-bottom:.6rem;">
                    @foreach ($simple as $field)
                        <label style="display:block;font-size:.72rem;">
                            <span style="opacity:.75;">{{ trans('pzmm::messages.auto.field.' . $field['k']) }}</span>
                            <select wire:model="auto.{{ $field['k'] }}" @disabled(!$this->canWrite())
                                    class="pzmm-field" style="width:100%;padding:.3rem .5rem;font-size:.8rem;">
                                {{-- Marked here as well as bound: the stored value
                                     has to be the one showing before Livewire has
                                     hydrated anything, or the panel appears to
                                     have forgotten the setting. --}}
                                @foreach ($field['o'] as $value => $label)
                                    <option value="{{ $value }}" @selected((int) ($auto[$field['k']] ?? 0) === (int) $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endforeach

                    <label style="display:block;font-size:.72rem;">
                        <span style="opacity:.75;">{{ trans('pzmm::messages.auto.field.backup') }}</span>
                        <select wire:model="auto.backup" @disabled(!$this->canWrite())
                                class="pzmm-field" style="width:100%;padding:.3rem .5rem;font-size:.8rem;">
                            <option value="1" @selected($auto['backup'] ?? true)>{{ trans('pzmm::messages.auto.choice.backup_on') }}</option>
                            <option value="0" @selected(!($auto['backup'] ?? true))>{{ trans('pzmm::messages.auto.choice.backup_off') }}</option>
                        </select>
                        <span style="display:block;opacity:.65;margin-top:.15rem;">{{ trans('pzmm::messages.auto.field.backup_hint') }}</span>
                    </label>

                    <label style="display:block;font-size:.72rem;">
                        <span style="opacity:.75;">{{ trans('pzmm::messages.auto.field.check_game') }}</span>
                        <select wire:model="auto.check_game" @disabled(!$this->canWrite())
                                class="pzmm-field" style="width:100%;padding:.3rem .5rem;font-size:.8rem;">
                            <option value="1" @selected($auto['check_game'] ?? true)>{{ trans('pzmm::messages.auto.choice.watch_both') }}</option>
                            <option value="0" @selected(!($auto['check_game'] ?? true))>{{ trans('pzmm::messages.auto.choice.watch_mods') }}</option>
                        </select>
                    </label>
                </div>

                <details style="margin-bottom:.6rem;" wire:ignore.self>
                    <summary style="font-size:.74rem;opacity:.75;cursor:pointer;">{{ trans('pzmm::messages.auto.advanced') }}</summary>

                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:.5rem;margin:.6rem 0;">
                        @foreach ([
                            ['k' => 'countdown_seconds',   'min' => 0, 'max' => 60],
                            ['k' => 'cooldown_minutes',    'min' => 0, 'max' => 1440],
                            ['k' => 'backup_wait_seconds', 'min' => 0, 'max' => 900],
                        ] as $field)
                            <label style="display:block;font-size:.72rem;">
                                <span style="opacity:.75;">{{ trans('pzmm::messages.auto.field.' . $field['k']) }}</span>
                                <input type="number" min="{{ $field['min'] }}" max="{{ $field['max'] }}"
                                       wire:model="auto.{{ $field['k'] }}" @disabled(!$this->canWrite())
                                       value="{{ $auto[$field['k']] ?? $defaults[$field['k']] }}"
                                       class="pzmm-field" style="width:100%;padding:.3rem .5rem;font-size:.8rem;" />
                            </label>
                        @endforeach
                    </div>

                    <div style="display:grid;gap:.4rem;">
                        @foreach (['msg_warn', 'msg_final', 'msg_countdown', 'msg_back'] as $key)
                            <label style="display:block;font-size:.72rem;">
                                <span style="opacity:.75;">{{ trans('pzmm::messages.auto.field.' . $key) }}</span>
                                <input type="text" wire:model="auto.{{ $key }}" @disabled(!$this->canWrite())
                                       value="{{ $auto[$key] ?? $defaults[$key] }}"
                                       placeholder="{{ $defaults[$key] }}"
                                       class="pzmm-field" style="width:100%;padding:.3rem .5rem;font-size:.8rem;" />
                            </label>
                        @endforeach
                        <p style="font-size:.7rem;opacity:.7;">{{ trans('pzmm::messages.auto.placeholders') }}</p>
                    </div>
                </details>

                @if ($this->canWrite())
                    <x-filament::button size="xs" wire:click="saveAutoUpdate" wire:target="saveAutoUpdate" wire:loading.attr="disabled">
                        {{ trans('pzmm::messages.auto.save') }}
                    </x-filament::button>
                @endif
        </div>
    </details>

    <h2 class="pzmm-sec">{{ trans('pzmm::messages.title') }}</h2>
    {{-- Stats. Number first, label under it, no icon: four icons in a row read
         as decoration and pushed the numbers into a column too narrow to scan. --}}
    <div class="pzmm-tiles">
        @foreach ([
            ['k' => 'active',    'v' => ($stats['active'] ?? 0),    'c' => null],
            ['k' => 'available', 'v' => ($stats['available'] ?? 0), 'c' => null],
            ['k' => 'updates',   'v' => ($stats['updates'] ?? 0),   'c' => ($stats['updates'] ?? 0) ? '#d97706' : null],
            ['k' => 'errors',    'v' => ($stats['errors'] ?? 0),    'c' => ($stats['errors'] ?? 0) ? '#dc2626' : null],
        ] as $tile)
            <div class="fi-section pzmm-tile">
                <div class="n" @if ($tile['c']) style="color:{{ $tile['c'] }};" @endif>{{ $tile['v'] }}</div>
                <div class="l">{{ trans('pzmm::messages.stat.' . $tile['k']) }}</div>
            </div>
        @endforeach
    </div>

    {{-- Toolbar. Search stays narrow and the add field takes the slack: one is
         a word, the other is a pasted collection URL. --}}
    <div class="pzmm-bar">
        <input type="search" wire:model.live.debounce.250ms="search" placeholder="{{ trans('pzmm::messages.search') }}"
            class="pzmm-field" style="flex:0 1 190px;" />

        @if ($this->canWrite())
            <input type="text" wire:model="newMod" wire:keydown.enter="addMod" placeholder="{{ trans('pzmm::messages.add_placeholder') }}"
                class="pzmm-field" style="flex:1 1 260px;" />
            <x-filament::button size="sm" wire:click="addMod" wire:target="addMod" wire:loading.attr="disabled">
                {{ trans('pzmm::messages.action.add') }}
            </x-filament::button>
            <label style="display:flex;align-items:center;gap:.35rem;font-size:11px;opacity:.7;white-space:nowrap;cursor:pointer;"
                   title="{{ trans('pzmm::messages.auto_activate_hint') }}">
                <input type="checkbox" wire:model="activateOnAdd" @checked($activateOnAdd) /> {{ trans('pzmm::messages.auto_activate') }}
            </label>
            <x-filament::button size="sm" color="gray" wire:click="autoSort" wire:target="autoSort" wire:loading.attr="disabled">
                {{ trans('pzmm::messages.action.sort') }}
            </x-filament::button>
        @endif
        <x-filament::button size="sm" color="gray" wire:click="refresh" wire:target="refresh" wire:loading.attr="disabled">
            {{ trans('pzmm::messages.action.refresh') }}
        </x-filament::button>
        @if ($this->canRestart())
            <x-filament::button size="sm" :color="(($stats['restart'] ?? 0) || ($stats['updates'] ?? 0)) ? 'warning' : 'gray'" 
                wire:click="restartServer" wire:target="restartServer" wire:loading.attr="disabled"
                wire:confirm="{{ trans('pzmm::messages.confirm.restart') }}">
                {{ trans('pzmm::messages.action.restart') }}
            </x-filament::button>
        @endif
    </div>

    {{-- Alerts --}}
    @if ($this->canWrite() && count($selected))
        <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;padding:.5rem .75rem;margin-bottom:.75rem;
                    border:1px solid rgba(245,158,11,.35);border-radius:.5rem;background:rgba(245,158,11,.08);">
            <span style="font-size:.8125rem;font-weight:600;">
                {{ trans_choice('pzmm::messages.bulk.selected', count($selected), ['count' => count($selected)]) }}
            </span>
            <span style="flex:1;"></span>
            <x-filament::button size="xs" color="gray" icon="tabler-circle-plus"
                wire:click="bulkActivate" wire:target="bulkActivate" wire:loading.attr="disabled">
                {{ trans('pzmm::messages.bulk.enable') }}
            </x-filament::button>
            <x-filament::button size="xs" color="warning" icon="tabler-circle-minus"
                wire:click="bulkDeactivate" wire:target="bulkDeactivate" wire:loading.attr="disabled"
                wire:confirm="{{ trans('pzmm::messages.confirm.bulk_disable') }}">
                {{ trans('pzmm::messages.bulk.disable') }}
            </x-filament::button>
            <x-filament::button size="xs" color="danger" icon="tabler-trash"
                wire:click="bulkRemove" wire:target="bulkRemove" wire:loading.attr="disabled"
                wire:confirm="{{ trans('pzmm::messages.confirm.bulk_delete') }}">
                {{ trans('pzmm::messages.bulk.delete') }}
            </x-filament::button>
            <x-filament::button size="xs" color="gray" wire:click="clearSelection">
                {{ trans('pzmm::messages.bulk.clear') }}
            </x-filament::button>
        </div>
    @endif

    @if (count($alerts))
        <div style="display:flex;flex-direction:column;gap:.4rem;">
            @foreach ($alerts as $alert)
                <div class="pzmm-alert" style="{{ $alertStyle[$alert['type']] ?? $alertStyle['info'] }}">
                    <x-filament::icon :icon="$alert['type'] === 'danger' ? 'tabler-alert-octagon' : ($alert['type'] === 'warning' ? 'tabler-alert-triangle' : 'tabler-info-circle')"
                        style="width:15px;height:15px;flex:0 0 15px;margin-top:2px;" />
                    <span style="min-width:0;">{{ $alert['text'] }}</span>
                    @if ($alert['action'])
                        <button type="button" class="act"
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
    <div class="fi-section" style="border-radius:12px;">
        <div class="pzmm-listhead">
            <h3>{{ trans('pzmm::messages.section.active') }}</h3>
            <span class="hint">{{ trans('pzmm::messages.section.active_hint') }}</span>
            @if ($this->canWrite())
                <button type="button" wire:click="selectAll('active')"
                        style="margin-left:auto;font-size:11px;opacity:.6;text-decoration:underline;">
                    {{ trans('pzmm::messages.bulk.select_all') }}
                </button>
            @endif
        </div>

        @php $activeRows = $this->activeFiltered(); $activeCount = count($activeRows); @endphp
        <div wire:loading.class="fi-disabled" style="transition:opacity .15s;"
             wire:target="activate,deactivate,move,reorder,toggleLock,autoSort,remove,refresh,addMod,removeOrphans,addMapsToConfig,bulkActivate,bulkDeactivate,bulkRemove"
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
                <div class="pzmm-row" @if ($drag) style="cursor:grab;" @endif
                     wire:key="a-{{ $row['mod_id'] }}"
                     data-mod-id="{{ $row['mod_id'] }}"
                     @if ($drag)
                         draggable="true"
                         x-on:dragstart="start({{ $i }}, $event)"
                         x-on:dragover="over($event)"
                         x-on:drop="drop({{ $i }}, $event)"
                         x-on:dragend="from = null"
                     @endif>
                    @if ($this->canWrite())
                        {{-- Outside the drag handle on purpose: a checkbox that
                             starts a drag cannot be ticked with the mouse. --}}
                        <input type="checkbox" value="{{ $row['mod_id'] }}" wire:model.live="selected"
                               draggable="false" x-on:dragstart.stop
                               style="flex:0 0 auto;width:15px;height:15px;cursor:pointer;" />
                    @endif
                    @if ($this->canWrite() && $search === '')
                        <div style="display:flex;flex-direction:column;line-height:.7;font-size:10px;opacity:.45;flex:none;">
                            <button type="button" wire:click="move({{ \Illuminate\Support\Js::from($row['mod_id']) }},'up')" @disabled($i === 0) style="padding:1px 2px;">▲</button>
                            <button type="button" wire:click="move({{ \Illuminate\Support\Js::from($row['mod_id']) }},'down')" @disabled($i === $activeCount - 1) style="padding:1px 2px;">▼</button>
                        </div>
                    @endif
                    <span class="pzmm-idx">{{ $i + 1 }}</span>

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
                        <img src="{{ $row['preview'] }}" alt="" loading="lazy" class="pzmm-thumb" />
                    @else
                        <div class="pzmm-thumb" style="display:grid;place-items:center;background:rgba(128,128,128,.15);font-size:12px;font-weight:600;opacity:.6;">{{ mb_strtoupper(mb_substr($row['name'], 0, 1)) }}</div>
                    @endif

                    <div class="pzmm-mi">
                        <div class="pzmm-t">
                            @if ($row['url'])
                                <a href="{{ $row['url'] }}" target="_blank" rel="noopener" class="pzmm-name">{{ $row['name'] }}</a>
                            @else
                                <span class="pzmm-name">{{ $row['name'] }}</span>
                            @endif
                            <span class="pzmm-pill">{{ trans('pzmm::messages.category.' . $row['category']) }}</span>
                            @if ($row['update_available'])
                                <span class="pzmm-pill-up">{{ trans('pzmm::messages.badge.update') }}</span>
                            @endif
                        </div>
                        <div class="pzmm-sub">
                            <code>{{ $row['mod_id'] }}</code>
                            @if ($row['version'])<span>{{ trans('pzmm::messages.badge.version', ['version' => $row['version']]) }}</span>@endif
                            @if ($row['bundled'])<span title="{{ trans('pzmm::messages.tooltip.bundled', ['title' => $row['bundle_title'] ?? $row['workshop_id']]) }}" style="cursor:help;">🔗</span>@endif
                            @if ($st['label'])<span style="color:{{ $st['color'] }};">{{ $st['label'] }}</span>@endif
                            @if ($row['compat'] === 'mismatch')<span style="color:#d97706;">{{ trans('pzmm::messages.badge.build_mismatch') }}</span>@endif
                            @if ($row['errors'] > 0)<span style="color:#dc2626;font-weight:600;">{{ trans_choice('pzmm::messages.badge.errors', $row['errors'], ['count' => $row['errors']]) }}</span>@endif
                            @if ($row['maps'])<span>🗺 {{ implode(', ', $row['maps']) }}</span>@endif
                        </div>
                    </div>

                    <div class="pzmm-acts">
                        @if ($row['workshop_id'])
                            <x-filament::icon-button icon="tabler-history" color="gray" size="sm"
                                wire:click="openChangelog({{ \Illuminate\Support\Js::from($row['workshop_id']) }}, {{ \Illuminate\Support\Js::from($row['name']) }})"
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
                <p class="pzmm-empty">{{ trans('pzmm::messages.empty.active') }}</p>
            @endforelse
        </div>
    </div>

    {{-- Available --}}
    <details class="fi-section" style="border-radius:12px;" open wire:ignore.self>
        <summary class="pzmm-listhead pzmm-sum">
            <span class="pzmm-chev">&#9656;</span>
            <h3>{{ trans('pzmm::messages.section.available') }}</h3>
            <span class="hint">{{ trans('pzmm::messages.section.available_hint') }}</span>
            @if ($this->canWrite())
                {{-- .stop so ticking every mod does not also fold the list shut. --}}
                <button type="button" wire:click.stop="selectAll('available')"
                        style="margin-left:auto;font-size:11px;opacity:.6;text-decoration:underline;">
                    {{ trans('pzmm::messages.bulk.select_all') }}
                </button>
            @endif
        </summary>

        <div wire:loading.class="fi-disabled" wire:target="activate,remove,refresh,addMod,bulkActivate,bulkDeactivate,bulkRemove">
            @forelse ($this->availableByCategory() as $category => $rows)
                <div>
                    <div class="pzmm-cat">{{ trans('pzmm::messages.category.' . $category) }} ({{ count($rows) }})</div>
                    @foreach ($rows as $row)
                        <div class="pzmm-row" wire:key="v-{{ $row['mod_id'] }}">
                            @if ($this->canWrite())
                                <input type="checkbox" value="{{ $row['mod_id'] }}" wire:model.live="selected"
                                       style="flex:0 0 auto;width:15px;height:15px;cursor:pointer;" />
                            @endif
                            @if ($row['preview'])
                                <img src="{{ $row['preview'] }}" alt="" loading="lazy" class="pzmm-thumb" style="opacity:.65;" />
                            @else
                                <div class="pzmm-thumb" style="display:grid;place-items:center;background:rgba(128,128,128,.15);font-size:12px;font-weight:600;opacity:.6;">{{ mb_strtoupper(mb_substr($row['name'], 0, 1)) }}</div>
                            @endif

                            <div class="pzmm-mi">
                                <div class="pzmm-t">
                                    @if ($row['url'])
                                        <a href="{{ $row['url'] }}" target="_blank" rel="noopener" class="pzmm-name">{{ $row['name'] }}</a>
                                    @else
                                        <span class="pzmm-name">{{ $row['name'] }}</span>
                                    @endif
                                    @if ($row['update_available'])
                                        <span class="pzmm-pill-up">{{ trans('pzmm::messages.badge.update') }}</span>
                                    @endif
                                </div>
                                <div class="pzmm-sub">
                                    <code>{{ $row['mod_id'] }}</code>
                                    @if ($row['version'])<span>{{ trans('pzmm::messages.badge.version', ['version' => $row['version']]) }}</span>@endif
                                    @if ($row['bundled'])<span title="{{ trans('pzmm::messages.tooltip.bundled', ['title' => $row['bundle_title'] ?? $row['workshop_id']]) }}" style="cursor:help;">🔗</span>@endif
                                    @if ($row['compat'] === 'mismatch')<span style="color:#d97706;">{{ trans('pzmm::messages.badge.build_mismatch') }}</span>@endif
                                    @if ($row['maps'])<span>🗺</span>@endif
                                </div>
                            </div>

                            <div class="pzmm-acts">
                                @if ($row['workshop_id'])
                                    <x-filament::icon-button icon="tabler-history" color="gray" size="sm"
                                        wire:click="openChangelog({{ \Illuminate\Support\Js::from($row['workshop_id']) }}, {{ \Illuminate\Support\Js::from($row['name']) }})"
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
                <p class="pzmm-empty">{{ trans('pzmm::messages.empty.available') }}</p>
            @endforelse
        </div>
    </details>

    {{-- Changelog overlay --}}
    @if ($changelogFor)
        <div style="position:fixed;inset:0;z-index:50;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;padding:1rem;"
             wire:click.self="closeChangelog">
            <div class="fi-section" style="max-width:640px;width:100%;max-height:75vh;display:flex;flex-direction:column;border-radius:12px;overflow:hidden;">
                <div style="display:flex;align-items:center;gap:.6rem;padding:.85rem 1rem;border-bottom:1px solid rgba(128,128,128,.2);">
                    <strong style="flex:1;font-size:.9rem;">{{ trans('pzmm::messages.changelog.title', ['mod' => $changelogName]) }}</strong>
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
                    <x-filament::button size="sm" color="gray" tag="a"
                        href="https://steamcommunity.com/sharedfiles/filedetails/changelog/{{ $changelogFor }}"
                        target="_blank" rel="noopener">
                        {{ trans('pzmm::messages.changelog.open_steam') }}
                    </x-filament::button>
                    <x-filament::button size="sm" wire:click="closeChangelog">{{ trans('pzmm::messages.changelog.close') }}</x-filament::button>
                </div>
            </div>
        </div>
    @endif

    @endif
</x-filament-panels::page>
