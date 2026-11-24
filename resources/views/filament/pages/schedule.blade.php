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
            --sch-label-w: 120px;
            --sch-skew: 10px;
            position: relative;
            overflow: auto;
            /* Высоту до низа окна считает компонент scheduleDrag (fitHeight); запасное значение — до его запуска. */
            max-height: var(--sch-avail-h, calc(100vh - 20rem));
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
            white-space: nowrap; overflow: hidden;
        }
        .sch-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; }
        .sch-cell {
            flex: 0 0 var(--sch-day-w); width: var(--sch-day-w);
            border-right: 1px solid #f0f1f3; border-bottom: 1px solid #e5e7eb;
        }
        .sch-cell.sch-weekend { background: #f6f7f9; }
        .sch-track .sch-cell { cursor: pointer; }
        .sch-track .sch-cell:hover { background: #eef2f7; }
        .sch-bar { cursor: pointer; }

        .sch-head { position: sticky; top: 0; z-index: 4; background: #fff; }
        .sch-head .sch-label { z-index: 5; font-weight: 600; color: #6b7280; }
        .sch-head .sch-row { height: 28px; }
        .sch-month {
            flex: 0 0 auto; border-right: 1px solid #d1d5db; border-bottom: 1px solid #e5e7eb;
            font-weight: 600; display: flex; align-items: center; overflow: clip;
        }
        .sch-month span { position: sticky; left: var(--sch-label-w); padding: 0 .75rem; white-space: nowrap; }
        .sch-day { display: flex; align-items: center; justify-content: center; gap: .25rem; color: #4b5563; }
        .sch-day.sch-weekend { color: #b91c1c; }
        .sch-day.sch-today { font-weight: 700; color: #dc2626; }

        .sch-room-row .sch-label { font-weight: 600; background: #f3f4f6; }
        /* Ячейка комнаты: сверху число свободных коек, снизу цена за ночь. */
        .sch-room-row .sch-cell {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            background: #f3f4f6; color: #374151; line-height: 1.15;
        }
        .sch-room-row .sch-free { font-size: .875rem; font-weight: 700; }
        .sch-room-row .sch-price { font-size: .75rem; color: #6b7280; white-space: nowrap; }
        .sch-room-row .sch-cell.sch-full .sch-free { color: #b91c1c; }
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
        /* Ручка шире скоса правого края, чтобы за неё можно было взяться по всей высоте полосы. */
        .sch-resize { position: absolute; top: 0; right: 0; bottom: 0; width: calc(var(--sch-skew-r) + 8px); }
        .sch-bar[data-draggable="1"] .sch-resize { cursor: ew-resize; }
        .sch-bar { touch-action: none; }
        .sch-bar.sch-dragged { opacity: .35; }
        .sch-ghosts { position: absolute; inset: 0; z-index: 2; overflow: hidden; pointer-events: none; }
        .sch-ghost { opacity: .7; }
        .sch-dragging, .sch-dragging * { user-select: none; cursor: grabbing !important; }
        .sch-dragging.sch-resizing, .sch-dragging.sch-resizing * { cursor: ew-resize !important; }

        .sch-overdue {
            position: absolute; top: 5px; height: calc(var(--sch-row-h) - 10px); z-index: 0;
            background: repeating-linear-gradient(45deg, rgba(220, 38, 38, .55) 0 5px, rgba(220, 38, 38, .12) 5px 10px);
            cursor: pointer; touch-action: none;
        }
        .sch-overdue-labeled {
            display: flex; align-items: center; gap: .375rem;
            padding: 0 .375rem; box-sizing: border-box; overflow: hidden; white-space: nowrap;
        }
        .sch-overdue-labeled .sch-guest { padding: 0 .3rem; border-radius: .25rem; background: #fff; color: #7f1d1d; line-height: 1.25rem; }

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
        <div
            class="sch-scroll"
            style="--sch-day-w: 104px; --sch-row-h: 40px; --sch-days: {{ $dayCount }};"
            x-data="scheduleDrag"
            x-on:pointerdown="down($event)"
            x-on:pointermove="move($event)"
            x-on:pointerup="up($event)"
            x-on:pointercancel="cancel()"
            x-on:lostpointercapture.window="lost($event)"
            x-on:keydown.escape.window="cancel()"
        >
            <div class="sch-grid" x-ref="grid">
                <div class="sch-ghosts" x-ref="ghosts" wire:ignore></div>

                <div class="sch-head">
                    <div class="sch-row">
                        <div class="sch-label"></div>
                        @foreach ($schedule['months'] as $month)
                            @php
                                // В столбец одного дня полное название не помещается: «Декабрь 2026» → «Дек 2026».
                                [$monthName, $monthYear] = explode(' ', $month['label'], 2);
                                $monthLabel = $month['span'] < 2 ? mb_substr($monthName, 0, 3) . ' ' . $monthYear : $month['label'];
                            @endphp
                            <div class="sch-month" style="width: calc({{ $month['span'] }} * var(--sch-day-w));" title="{{ $month['label'] }}">
                                <span>{{ $monthLabel }}</span>
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
                                <button
                                    type="button"
                                    class="sch-toggle"
                                    x-on:click="toggle()"
                                    x-text="collapsed ? '▶' : '▼'"
                                    x-bind:aria-expanded="collapsed ? 'false' : 'true'"
                                    x-bind:aria-label="collapsed ? 'Развернуть комнату' : 'Свернуть комнату'"
                                    aria-label="Свернуть комнату"
                                >▼</button>
                                <span class="sch-name">{{ $room['name'] }}</span>
                            </div>
                            @foreach ($room['free'] as $free)
                                <div @class(['sch-cell', 'sch-full' => $free <= 0])>
                                    <span class="sch-free">{{ $free }}</span>
                                    <span class="sch-price">₴ {{ $room['price'] }}</span>
                                </div>
                            @endforeach
                        </div>

                        <div x-show="! collapsed">
                            @forelse ($room['beds'] as $bed)
                                <div class="sch-row sch-bed-row" data-bed-id="{{ $bed['id'] }}" wire:key="bed-{{ $bed['id'] }}">
                                    <div class="sch-label" title="{{ $bed['name'] }}"><span class="sch-name">{{ $bed['name'] }}</span></div>
                                    <div class="sch-track">
                                        @foreach ($schedule['days'] as $day)
                                            <div
                                                @class(['sch-cell', 'sch-weekend' => $day['weekend']])
                                                data-date="{{ $day['date'] }}"
                                                wire:click="mountAction('createStay', { bed_id: {{ $bed['id'] }}, date: '{{ $day['date'] }}' })"
                                            ></div>
                                        @endforeach

                                        @foreach ($bed['stays'] as $stay)
                                            @php
                                                $barDrawn = $stay['end'] > $stay['start'];
                                                $stayTitle = collect([
                                                    $stay['guest'],
                                                    CarbonImmutable::parse($stay['date_from'])->format('d.m.Y') . ' — ' . CarbonImmutable::parse($stay['date_to'])->format('d.m.Y'),
                                                    StayStatus::from($stay['status'])->label(),
                                                    "Оплачено: {$stay['paid']}, долг: {$stay['debt']}",
                                                ])->map(fn (string $line): string => e($line))->implode('&#10;');
                                            @endphp

                                            @if ($stay['overdue_end'] !== null && $stay['overdue_end'] > $stay['end'])
                                                {{-- Хвост просрочки открывает заселение; если основная полоса за окном, он подписан сам. --}}
                                                <div
                                                    @class(['sch-overdue', 'sch-overdue-labeled' => ! $barDrawn])
                                                    wire:key="overdue-{{ $stay['id'] }}"
                                                    style="left: calc({{ $stay['end'] }} * var(--sch-day-w)); width: calc({{ $stay['overdue_end'] - $stay['end'] }} * var(--sch-day-w));"
                                                    data-stay-id="{{ $stay['id'] }}"
                                                    data-date-from="{{ $stay['date_from'] }}"
                                                    data-date-to="{{ $stay['date_to'] }}"
                                                    data-draggable="0"
                                                    title="{!! $stayTitle !!}&#10;Просрочено: не продлён и не выселен"
                                                >
                                                    @unless ($barDrawn)
                                                        <span class="sch-guest">{{ $stay['guest'] }}</span>
                                                        @if ($stay['debt'] > 0)
                                                            <span class="sch-debt">{{ $stay['debt'] }}</span>
                                                        @endif
                                                    @endunless
                                                </div>
                                            @endif

                                            @if ($barDrawn)
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
                                                    title="{!! $stayTitle !!}"
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

    @script
    <script>
        // Перетаскивание полос: перенос за тело, смена дня выезда за правый край. Шаг — один день.
        Alpine.data('scheduleDrag', () => {
            // Порог в пикселях, меньше которого нажатие считается кликом.
            const CLICK_SLOP = 4;
            // Ширина зоны у края видимой области, где сетка прокручивается сама, и шаг прокрутки за кадр.
            const EDGE = 60;
            const SCROLL_STEP = 12;

            // Текущее нажатие на полосу; хранится вне реактивных данных Alpine.
            let drag = null;
            // Число запросов переноса, ожидающих ответа сервера.
            let pending = 0;
            // Указатель отменённого нажатия: клик после его отпускания гасится.
            let cancelledPointer = null;

            // Даты считаются в UTC по строкам Y-m-d, чтобы часовой пояс и переход на летнее время браузера не сдвигали день.
            const toUtc = (ymd) => Date.parse(ymd + 'T00:00:00Z');
            const addDays = (ymd, days) => new Date(toUtc(ymd) + days * 86400000).toISOString().slice(0, 10);
            const nights = (from, to) => Math.round((toUtc(to) - toUtc(from)) / 86400000);
            const cssPx = (el, name) => parseFloat(getComputedStyle(el).getPropertyValue(name)) || 0;

            // Пересчёт высоты сетки при изменении размера окна; снимается в destroy().
            let onResize = null;

            return {
                init() {
                    onResize = () => this.fitHeight();
                    window.addEventListener('resize', onResize);
                    this.$nextTick(() => this.fitHeight());
                },

                // Сетка занимает место от своего верха до низа окна, чтобы вертикально прокручивалась только она,
                // а меню и шапка страницы оставались на месте. Значение хранится в переменной на корне документа:
                // атрибут style самой сетки Livewire перезаписывает при каждой перерисовке.
                fitHeight() {
                    const root = document.documentElement;
                    const top = this.$el.getBoundingClientRect().top + window.scrollY;
                    let height = Math.floor(window.innerHeight - top);

                    root.style.setProperty('--sch-avail-h', height + 'px');

                    // Под сеткой остаются отступы макета панели: убираем ровно столько, на сколько страница выше окна.
                    const overflow = root.scrollHeight - window.innerHeight;

                    if (overflow > 0) {
                        height = Math.max(240, height - overflow);
                        root.style.setProperty('--sch-avail-h', height + 'px');
                    }
                },

                // Компонент удалён посреди перетаскивания: останавливаем автопрокрутку и убираем «тень».
                destroy() {
                    window.removeEventListener('resize', onResize);
                    document.documentElement.style.removeProperty('--sch-avail-h');                    this.cancel();
                },

                down(e) {
                    cancelledPointer = null;

                    const bar = e.target.closest('[data-stay-id]');

                    if (! bar || e.button !== 0 || drag !== null) {
                        return;
                    }

                    e.preventDefault();
                    bar.setPointerCapture(e.pointerId);

                    const draggable = bar.dataset.draggable === '1' && pending === 0;
                    const barRect = bar.getBoundingClientRect();
                    const gridRect = this.$refs.grid.getBoundingClientRect();
                    const row = bar.closest('[data-bed-id]');

                    drag = {
                        bar,
                        row,
                        draggable,
                        scroller: e.currentTarget,
                        pointerId: e.pointerId,
                        mode: e.target.closest('[data-resize-handle]') ? 'resize' : 'move',
                        stayId: Number(bar.dataset.stayId),
                        bedId: Number(row.dataset.bedId),
                        dateFrom: bar.dataset.dateFrom,
                        dateTo: bar.dataset.dateTo,
                        dayWidth: cssPx(e.currentTarget, '--sch-day-w'),
                        // Положение и размер полосы в координатах сетки: от них строится «тень».
                        left: barRect.left - gridRect.left,
                        top: bar.offsetTop,
                        width: barRect.width,
                        startX: e.clientX,
                        startY: e.clientY,
                        startScroll: e.currentTarget.scrollLeft,
                        x: e.clientX,
                        y: e.clientY,
                        moved: false,
                        active: false,
                        deltaDays: 0,
                        targetBedId: Number(row.dataset.bedId),
                        ghost: null,
                        frame: null,
                    };
                },

                move(e) {
                    if (drag === null || e.pointerId !== drag.pointerId) {
                        return;
                    }

                    drag.x = e.clientX;
                    drag.y = e.clientY;

                    if (! drag.moved && Math.hypot(drag.x - drag.startX, drag.y - drag.startY) >= CLICK_SLOP) {
                        drag.moved = true;

                        if (drag.draggable) {
                            this.start();
                        }
                    }

                    if (drag.active) {
                        this.update();
                    }
                },

                up(e) {
                    if (drag === null) {
                        if (cancelledPointer !== null && e.pointerId === cancelledPointer) {
                            cancelledPointer = null;
                            this.swallowClick();
                        }

                        return;
                    }

                    if (e.pointerId !== drag.pointerId) {
                        return;
                    }

                    const current = drag;
                    drag = null;

                    if (! current.active) {
                        if (current.moved) {
                            // Сдвинутая, но не перенесённая полоса (выселившийся гость или ждём ответа сервера) — не клик.
                            this.swallowClick();
                        } else {
                            this.$wire.mountAction('editStay', { stay: current.stayId });
                        }

                        return;
                    }

                    this.stop(current);
                    this.swallowClick();

                    let request = null;

                    if (current.mode === 'move' && (current.deltaDays !== 0 || current.targetBedId !== current.bedId)) {
                        request = this.$wire.moveStay(current.stayId, current.targetBedId, addDays(current.dateFrom, current.deltaDays));
                    } else if (current.mode === 'resize' && current.deltaDays !== 0) {
                        request = this.$wire.resizeStay(current.stayId, addDays(current.dateTo, current.deltaDays));
                    }

                    if (request === null) {
                        this.clear(current);

                        return;
                    }

                    // До ответа «тень» стоит в новой позиции; после ответа сервер перерисует полосу сам.
                    pending++;

                    const done = () => {
                        pending--;
                        this.clear(current);
                    };

                    request.then(done, done);
                },

                cancel() {
                    if (drag === null) {
                        return;
                    }

                    if (drag.active) {
                        this.stop(drag);
                        this.clear(drag);
                    }

                    cancelledPointer = drag.pointerId;
                    drag = null;
                },

                // Захват указателя потерян без отпускания (полосу убрала перерисовка, окно потеряло фокус) — отмена.
                lost(e) {
                    if (drag !== null && e.pointerId === drag.pointerId) {
                        this.cancel();
                    }
                },

                // Начало перетаскивания: полупрозрачная копия полосы в слое над сеткой.
                start() {
                    const ghost = drag.bar.cloneNode(true);

                    ['wire:key', 'data-stay-id', 'data-date-from', 'data-date-to', 'data-draggable', 'title'].forEach((name) => ghost.removeAttribute(name));
                    ghost.querySelector('[data-resize-handle]')?.removeAttribute('data-resize-handle');
                    ghost.classList.add('sch-ghost');
                    this.$refs.ghosts.appendChild(ghost);

                    drag.ghost = ghost;
                    drag.active = true;
                    drag.bar.classList.add('sch-dragged');
                    drag.scroller.classList.add('sch-dragging');
                    drag.scroller.classList.toggle('sch-resizing', drag.mode === 'resize');

                    this.autoScroll();
                },

                // Пересчёт сдвига в днях, целевой койки и положения «тени».
                update() {
                    // Сдвиг указателя плюс прокрутка сетки с начала перетаскивания.
                    const dx = drag.x - drag.startX + drag.scroller.scrollLeft - drag.startScroll;
                    let deltaDays = Math.round(dx / drag.dayWidth);
                    let row = drag.row;

                    if (drag.mode === 'resize') {
                        // Не короче одной ночи.
                        deltaDays = Math.max(deltaDays, 1 - nights(drag.dateFrom, drag.dateTo));
                    } else {
                        const hits = document.elementsFromPoint(drag.x, drag.y);
                        // Под закреплённой шапкой лежат скрытые прокруткой строки — над шапкой койка не меняется.
                        const overHead = hits.some((el) => el.classList.contains('sch-head'));
                        const hit = overHead ? null : hits.find((el) => el.matches('[data-bed-id]'));
                        row = hit ?? drag.row;
                    }

                    drag.deltaDays = deltaDays;
                    drag.targetBedId = Number(row.dataset.bedId);

                    const shift = deltaDays * drag.dayWidth;
                    const top = row.getBoundingClientRect().top - this.$refs.grid.getBoundingClientRect().top + drag.top;

                    drag.ghost.style.top = top + 'px';
                    drag.ghost.style.left = (drag.mode === 'move' ? drag.left + shift : drag.left) + 'px';
                    drag.ghost.style.width = Math.max(0, drag.mode === 'resize' ? drag.width + shift : drag.width) + 'px';
                },

                // Пока указатель у левого или правого края видимой области, сетка прокручивается каждый кадр.
                autoScroll() {
                    if (drag === null || ! drag.active) {
                        return;
                    }

                    const scroller = drag.scroller;
                    const rect = scroller.getBoundingClientRect();
                    // Слева видимую область дней закрывает закреплённая колонка коек.
                    const left = rect.left + cssPx(scroller, '--sch-label-w');
                    let step = 0;

                    if (drag.x < left + EDGE) {
                        step = -SCROLL_STEP;
                    } else if (drag.x > rect.right - EDGE) {
                        step = SCROLL_STEP;
                    }

                    if (step !== 0) {
                        const before = scroller.scrollLeft;

                        scroller.scrollLeft += step;

                        if (scroller.scrollLeft !== before) {
                            this.update();
                        }
                    }

                    drag.frame = requestAnimationFrame(() => this.autoScroll());
                },

                // Останавливает автопрокрутку.
                stop(state) {
                    state.active = false;
                    cancelAnimationFrame(state.frame);
                },

                // Убирает «тень» и временные классы; полосу на верное место ставит перерисовка Livewire.
                clear(state) {
                    state.ghost?.remove();
                    state.bar.classList.remove('sch-dragged');
                    state.scroller.classList.remove('sch-dragging', 'sch-resizing');
                },

                // Гасит клик, который браузер может отправить после отпускания, чтобы клетка под указателем не открыла «Новое заселение».
                swallowClick() {
                    const block = (e) => {
                        e.stopPropagation();
                        e.preventDefault();
                    };

                    window.addEventListener('click', block, { capture: true, once: true });
                    setTimeout(() => window.removeEventListener('click', block, { capture: true }), 0);
                },
            };
        });
    </script>
    @endscript
</x-filament-panels::page>
