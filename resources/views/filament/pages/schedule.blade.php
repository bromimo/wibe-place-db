@php
    use App\Enums\StayStatus;
    use Carbon\CarbonImmutable;
    use App\Filament\Resources\Rooms\RoomResource;

    $schedule = $this->schedule;
    $dayCount = count($schedule['days']);
@endphp

<x-filament-panels::page>
    <style>
        .sch-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
        .sch-toolbar .sch-date { width: 11rem; }

        .sch-scroll {
            --sch-label-w: 150px;
            --sch-skew: 10px;
            position: relative;
            overflow: auto;
            max-height: calc(100vh - 13rem);
            border: 1px solid #e5e7eb;
            border-radius: .5rem;
            background: #fff;
            font-size: .8125rem;
        }
        .sch-grid { position: relative; width: calc(var(--sch-label-w) + var(--sch-days) * var(--sch-day-w)); }

        .sch-row { display: flex; height: var(--sch-row-h); }
        .sch-label {
            position: sticky; left: 0; z-index: 3;
            flex: 0 0 var(--sch-label-w); width: var(--sch-label-w);
            display: flex; align-items: center; gap: .375rem;
            padding: 0 .75rem; background: #fff;
            border-right: 1px solid #d1d5db; border-bottom: 1px solid #e5e7eb;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .sch-cell {
            flex: 0 0 var(--sch-day-w); width: var(--sch-day-w);
            border-right: 1px solid #f0f1f3; border-bottom: 1px solid #e5e7eb;
        }
        .sch-cell.sch-weekend { background: #f6f7f9; }

        .sch-head { position: sticky; top: 0; z-index: 4; background: #fff; }
        .sch-head .sch-label { z-index: 5; font-weight: 600; color: #6b7280; }
        .sch-head .sch-row { height: 28px; }
        .sch-month {
            flex: 0 0 auto; border-right: 1px solid #d1d5db; border-bottom: 1px solid #e5e7eb;
            font-weight: 600; display: flex; align-items: center; overflow: hidden;
        }
        .sch-month span { position: sticky; left: var(--sch-label-w); padding: 0 .75rem; white-space: nowrap; }
        .sch-day { display: flex; align-items: center; justify-content: center; gap: .25rem; color: #4b5563; }
        .sch-day.sch-weekend { color: #b91c1c; }
        .sch-day.sch-today { font-weight: 700; color: #dc2626; }

        .sch-room-row .sch-label { font-weight: 600; background: #f3f4f6; }
        .sch-room-row .sch-cell { display: flex; align-items: center; justify-content: center; background: #f3f4f6; color: #374151; }
        .sch-room-row .sch-cell.sch-full { color: #b91c1c; }
        .sch-toggle { flex: none; width: 1.25rem; color: #6b7280; cursor: pointer; background: none; border: 0; padding: 0; }
        .sch-bed-row .sch-label { padding-left: 2.125rem; }
        .sch-empty { display: flex; align-items: center; padding: 0 .75rem; color: #9ca3af; border-bottom: 1px solid #e5e7eb; flex: 1; }

        .sch-track { position: relative; display: flex; }

        .sch-bar {
            --sch-skew-l: var(--sch-skew);
            --sch-skew-r: var(--sch-skew);
            position: absolute; top: 5px; height: calc(var(--sch-row-h) - 10px); z-index: 1;
            display: flex; align-items: center; gap: .375rem;
            padding: 0 calc(var(--sch-skew-r) + 6px) 0 calc(var(--sch-skew-l) + 4px);
            box-sizing: border-box; color: #fff; overflow: hidden; white-space: nowrap;
            clip-path: polygon(var(--sch-skew-l) 0, 100% 0, calc(100% - var(--sch-skew-r)) 100%, 0 100%);
        }
        .sch-bar.sch-clip-left { --sch-skew-l: 0px; }
        .sch-bar.sch-clip-right { --sch-skew-r: 0px; }
        .sch-bar[data-draggable="1"] { cursor: grab; }
        .sch-booked { background: #38649c; }
        .sch-living { background: #2f9e58; }
        .sch-checked_out { background: #9ca3af; }
        .sch-guest { overflow: hidden; text-overflow: ellipsis; font-weight: 500; }
        .sch-paid, .sch-debt { flex: none; padding: 0 .3rem; border-radius: .25rem; background: #fff; font-weight: 600; line-height: 1.25rem; }
        .sch-paid { color: #15803d; }
        .sch-debt { color: #dc2626; }
        .sch-resize { position: absolute; top: 0; right: 0; bottom: 0; width: 10px; }
        .sch-bar[data-draggable="1"] .sch-resize { cursor: ew-resize; }

        .sch-overdue {
            position: absolute; top: 5px; height: calc(var(--sch-row-h) - 10px); z-index: 0;
            background: repeating-linear-gradient(45deg, rgba(220, 38, 38, .55) 0 5px, rgba(220, 38, 38, .12) 5px 10px);
        }

        .sch-today-line {
            position: absolute; top: 0; bottom: 0; z-index: 2; width: 2px; margin-left: -1px;
            left: calc(var(--sch-label-w) + var(--sch-today) * var(--sch-day-w));
            background: #dc2626; pointer-events: none;
        }

        .sch-hint { padding: 2rem; text-align: center; color: #6b7280; }
        .sch-hint a { color: #38649c; text-decoration: underline; }
    </style>

    <div class="sch-toolbar">
        <x-filament::input.wrapper class="sch-date">
            <x-filament::input type="date" wire:model.live="date" />
        </x-filament::input.wrapper>

        @foreach ([-31, -15, -7] as $step)
            <x-filament::button color="gray" outlined size="sm" wire:click="shift({{ $step }})">−{{ abs($step) }}</x-filament::button>
        @endforeach

        <x-filament::button color="gray" size="sm" wire:click="goToday">Сегодня</x-filament::button>

        @foreach ([7, 15, 31] as $step)
            <x-filament::button color="gray" outlined size="sm" wire:click="shift({{ $step }})">+{{ $step }}</x-filament::button>
        @endforeach
    </div>

    @if ($schedule['rooms'] === [])
        <div class="sch-hint">
            <a href="{{ RoomResource::getUrl('index') }}">Добавьте комнаты и койки в разделе „Комнаты“</a>
        </div>
    @else
        <div class="sch-scroll" style="--sch-day-w: 130px; --sch-row-h: 40px; --sch-days: {{ $dayCount }};">
            <div class="sch-grid">
                <div class="sch-head">
                    <div class="sch-row">
                        <div class="sch-label"></div>
                        @foreach ($schedule['months'] as $month)
                            <div class="sch-month" style="width: calc({{ $month['span'] }} * var(--sch-day-w));">
                                <span>{{ $month['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                    <div class="sch-row">
                        <div class="sch-label">Койка</div>
                        @foreach ($schedule['days'] as $day)
                            <div @class(['sch-cell', 'sch-day', 'sch-weekend' => $day['weekend'], 'sch-today' => $day['today']])>
                                {{ $day['weekday'] }} {{ $day['day'] }}
                            </div>
                        @endforeach
                    </div>
                </div>

                @foreach ($schedule['rooms'] as $room)
                    <div
                        wire:key="room-{{ $room['id'] }}"
                        x-data="{
                            collapsed: false,
                            storageKey: 'sch-room-{{ $room['id'] }}',
                            init() {
                                try { this.collapsed = window.localStorage.getItem(this.storageKey) === '1' } catch (e) {}
                            },
                            toggle() {
                                this.collapsed = ! this.collapsed
                                try { window.localStorage.setItem(this.storageKey, this.collapsed ? '1' : '0') } catch (e) {}
                            },
                        }"
                    >
                        <div class="sch-row sch-room-row">
                            <div class="sch-label" title="{{ $room['name'] }}">
                                <button type="button" class="sch-toggle" x-on:click="toggle()" x-text="collapsed ? '▼' : '▲'">▲</button>
                                {{ $room['name'] }}
                            </div>
                            @foreach ($room['free'] as $free)
                                <div @class(['sch-cell', 'sch-full' => $free <= 0])>{{ $free }} · {{ $room['price'] }}</div>
                            @endforeach
                        </div>

                        <div x-show="! collapsed">
                            @forelse ($room['beds'] as $bed)
                                <div class="sch-row sch-bed-row" data-bed-id="{{ $bed['id'] }}" wire:key="bed-{{ $bed['id'] }}">
                                    <div class="sch-label">{{ $bed['name'] }}</div>
                                    <div class="sch-track">
                                        @foreach ($schedule['days'] as $day)
                                            <div @class(['sch-cell', 'sch-weekend' => $day['weekend']]) data-date="{{ $day['date'] }}"></div>
                                        @endforeach

                                        @foreach ($bed['stays'] as $stay)
                                            @if ($stay['overdue_end'] !== null && $stay['overdue_end'] > $stay['end'])
                                                <div
                                                    class="sch-overdue"
                                                    wire:key="overdue-{{ $stay['id'] }}"
                                                    style="left: calc({{ $stay['end'] }} * var(--sch-day-w)); width: calc({{ $stay['overdue_end'] - $stay['end'] }} * var(--sch-day-w));"
                                                    title="Просрочено: не продлён и не выселен"
                                                ></div>
                                            @endif

                                            @if ($stay['end'] > $stay['start'])
                                                <div
                                                    wire:key="stay-{{ $stay['id'] }}"
                                                    @class([
                                                        'sch-bar',
                                                        'sch-' . $stay['status'],
                                                        'sch-clip-left' => $stay['clipped_left'],
                                                        'sch-clip-right' => $stay['clipped_right'],
                                                    ])
                                                    style="left: calc({{ $stay['start'] }} * var(--sch-day-w)); width: calc({{ $stay['end'] - $stay['start'] }} * var(--sch-day-w));"
                                                    data-stay-id="{{ $stay['id'] }}"
                                                    data-date-from="{{ $stay['date_from'] }}"
                                                    data-date-to="{{ $stay['date_to'] }}"
                                                    data-draggable="{{ $stay['draggable'] ? '1' : '0' }}"
                                                    title="{{ $stay['guest'] }}&#10;{{ CarbonImmutable::parse($stay['date_from'])->format('d.m.Y') }} — {{ CarbonImmutable::parse($stay['date_to'])->format('d.m.Y') }}&#10;{{ StayStatus::from($stay['status'])->label() }}&#10;Оплачено: {{ $stay['paid'] }}, долг: {{ $stay['debt'] }}"
                                                >
                                                    <span class="sch-guest">{{ $stay['guest'] }}</span>
                                                    <span class="sch-paid">{{ $stay['paid'] }}</span>
                                                    @if ($stay['debt'] > 0)
                                                        <span class="sch-debt">{{ $stay['debt'] }}</span>
                                                    @endif
                                                    <span class="sch-resize" data-resize-handle></span>
                                                </div>
                                            @endif
                                        @endforeach
                                    </div>
                                </div>
                            @empty
                                <div class="sch-row">
                                    <div class="sch-label"></div>
                                    <div class="sch-empty">В комнате нет коек</div>
                                </div>
                            @endforelse
                        </div>
                    </div>
                @endforeach

                @if ($schedule['today_index'] !== null)
                    <div class="sch-today-line" style="--sch-today: {{ $schedule['today_index'] }};"></div>
                @endif
            </div>
        </div>
    @endif
</x-filament-panels::page>
