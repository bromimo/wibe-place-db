<?php

namespace App\Filament\Pages;

use BackedEnum;
use App\Models\Bed;
use App\Models\Stay;
use DomainException;
use DateTimeImmutable;
use Filament\Pages\Page;
use App\Dto\Stay\StayDto;
use App\Enums\StayStatus;
use App\Enums\StayTariff;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Livewire\Attributes\Computed;
use App\Filament\Schemas\StayForm;
use Illuminate\Support\Facades\DB;
use App\Actions\Stay\MoveStayAction;
use Filament\Support\Icons\Heroicon;
use Filament\Support\Exceptions\Halt;
use App\Actions\Stay\CreateStayAction;
use App\Actions\Stay\DeleteStayAction;
use App\Actions\Stay\ResizeStayAction;
use App\Actions\Stay\UpdateStayAction;
use App\Actions\Stay\CheckInStayAction;
use App\Actions\Stay\CheckOutStayAction;
use Filament\Notifications\Notification;
use App\Actions\Stay\SyncStayPaymentsAction;
use App\Http\Queries\Schedule\GetScheduleQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** График заселения: койки по дням с полосами заселений. */
class Schedule extends Page
{
    /** Число дней в окне графика. */
    private const WINDOW_DAYS = 31;

    /** Допустимые годы дат из браузера. */
    private const MIN_YEAR = 2000;
    private const MAX_YEAR = 2100;

    /** Наибольший сдвиг выбранной даты за одно нажатие, в днях. */
    private const MAX_SHIFT_DAYS = 366;

    protected static ?string $title = 'График';
    protected static ?string $navigationLabel = 'График';
    protected static ?int $navigationSort = 1;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;
    protected string $view = 'filament.pages.schedule';
    protected Width|string|null $maxContentWidth = Width::Full;

    /** Выбранная дата Y-m-d; окно начинается за день до неё. */
    public string $date = '';

    /** @var array<int, Stay|null> Заселения, загруженные за запрос, по id. */
    private array $loadedStays = [];

    /** Ставит выбранной датой сегодняшний день. */
    public function mount(): void
    {
        $this->goToday();
    }

    /** Сбрасывает некорректную дату на сегодня. */
    public function updatedDate(): void
    {
        $this->date = $this->selectedDate()->toDateString();
    }

    /** Сдвигает выбранную дату; аргумент приходит из браузера, некорректный или больше года игнорируется.
     * @param mixed $days Число дней, может быть отрицательным.
     * @return void
     */
    public function shift(mixed $days): void
    {
        if (! is_int($days) && ! is_string($days)) {
            return;
        }

        $days = filter_var($days, FILTER_VALIDATE_INT, ['options' => ['min_range' => -self::MAX_SHIFT_DAYS, 'max_range' => self::MAX_SHIFT_DAYS]]);

        if ($days === false) {
            return;
        }

        $this->date = $this->selectedDate()->addDays($days)->toDateString();
    }

    /** Возвращает выбранную дату на сегодня. */
    public function goToday(): void
    {
        $this->date = today()->toDateString();
    }

    /** Данные окна графика.
     * @return array<string, mixed>
     */
    #[Computed]
    public function schedule(): array
    {
        return app(GetScheduleQuery::class)($this->selectedDate()->subDay(), self::WINDOW_DAYS);
    }

    /** Переносит заселение перетаскиванием; аргументы приходят из браузера и проверяются здесь.
     * @param mixed $stayId Id заселения.
     * @param mixed $bedId Id целевой койки.
     * @param mixed $dateFrom Новый день заезда Y-m-d.
     * @return void
     */
    public function moveStay(mixed $stayId, mixed $bedId, mixed $dateFrom): void
    {
        $stay = $this->findStay(['stay' => $this->toId($stayId)]);
        $bedId = $this->toId($bedId);
        $dateFrom = is_string($dateFrom) ? $this->parseDate($dateFrom) : null;

        if ($stay === null || $bedId === null || $dateFrom === null) {
            $this->notifyDragFailed();

            return;
        }

        $this->applyDrag(fn () => (new MoveStayAction())->run($stay, $bedId, $dateFrom));
    }

    /** Меняет день выезда перетаскиванием правого края; аргументы приходят из браузера и проверяются здесь.
     * @param mixed $stayId Id заселения.
     * @param mixed $dateTo Новый день выезда Y-m-d.
     * @return void
     */
    public function resizeStay(mixed $stayId, mixed $dateTo): void
    {
        $stay = $this->findStay(['stay' => $this->toId($stayId)]);
        $dateTo = is_string($dateTo) ? $this->parseDate($dateTo) : null;

        if ($stay === null || $dateTo === null) {
            $this->notifyDragFailed();

            return;
        }

        $this->applyDrag(fn () => (new ResizeStayAction())->run($stay, $dateTo));
    }

    /** Окно «Новое заселение» для клетки койки; аргументы: bed_id и date (Y-m-d).
     * @return Action
     */
    public function createStayAction(): Action
    {
        return Action::make('createStay')
            ->modalHeading('Новое заселение')
            ->modalSubmitActionLabel('Сохранить')
            ->schema(StayForm::components())
            ->mountUsing(function (Action $action, ?Schema $schema, array $arguments): void {
                $defaults = $this->createDefaults($arguments);

                if ($defaults === null) {
                    Notification::make()->danger()->title('Не удалось открыть форму заселения')->send();

                    $action->cancel();
                }

                $schema?->fill($defaults);
            })
            ->action(function (Action $action, array $data): void {
                try {
                    (new CreateStayAction())->run($this->toDto($data));
                } catch (DomainException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    $action->halt();
                }

                $this->refreshSchedule();
            });
    }

    /** Окно правки заселения с оплатами и кнопками смены состояния; аргумент: stay (id).
     * @return Action
     */
    public function editStayAction(): Action
    {
        return Action::make('editStay')
            ->modalHeading(fn (array $arguments): ?string => $this->findStay($arguments)?->user->full_name)
            ->modalSubmitActionLabel('Сохранить')
            ->schema(fn (array $arguments): array => StayForm::components($this->findStay($arguments)))
            ->mountUsing(function (Action $action, ?Schema $schema, array $arguments): void {
                $stay = $this->findStay($arguments);

                if ($stay === null) {
                    $this->notifyStayMissing();

                    $action->cancel();
                }

                $schema?->fill(StayForm::fillFromStay($stay));
            })
            ->action(function (Action $action, array $data, array $arguments): void {
                $stay = $this->findStay($arguments);

                if ($stay === null) {
                    $this->notifyStayMissing();
                    $this->refreshSchedule();

                    return;
                }

                try {
                    DB::transaction(function () use ($stay, $data): void {
                        (new UpdateStayAction())->run($stay, $this->toDto($data));
                        (new SyncStayPaymentsAction())->run($stay, $this->paymentRows($data), $this->openedPaymentIds($data));
                    });
                } catch (DomainException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    $action->halt();
                } catch (ModelNotFoundException) {
                    $this->notifyStayMissing();
                }

                $this->refreshSchedule();
            })
            ->extraModalFooterActions(fn (array $arguments): array => $this->stayFooterActions($this->findStay($arguments)));
    }

    /** Кнопки подвала окна правки: заселить, выселить, удалить.
     * @param Stay|null $stay Правимое заселение.
     * @return array<int, Action>
     */
    private function stayFooterActions(?Stay $stay): array
    {
        return [
            Action::make('checkIn')
                ->label('Заселить')
                ->color('success')
                ->visible($stay?->status === StayStatus::Booked)
                ->cancelParentActions()
                ->action(function (Action $action) use ($stay): void {
                    $this->applyFooterChange($action, fn () => (new CheckInStayAction())->run($stay));
                }),
            Action::make('checkOut')
                ->label('Выселить')
                ->color('warning')
                ->visible($stay?->status === StayStatus::Living)
                ->requiresConfirmation()
                ->modalHeading('Выселить гостя?')
                ->cancelParentActions()
                ->action(function (Action $action) use ($stay): void {
                    $checkOut = new CheckOutStayAction();

                    $this->applyFooterChange($action, fn () => $checkOut->run($stay));

                    if ($checkOut->clippedTo() !== null) {
                        Notification::make()
                            ->warning()
                            ->title('Выезд ограничен началом следующего заселения: ' . $checkOut->clippedTo()->format('d.m'))
                            ->send();
                    }
                }),
            Action::make('delete')
                ->label('Удалить')
                ->color('danger')
                ->visible($stay !== null)
                ->requiresConfirmation()
                ->modalHeading('Удалить заселение?')
                ->modalDescription('Заселение и его оплаты будут удалены')
                ->cancelParentActions()
                ->action(function (Action $action) use ($stay): void {
                    $this->applyFooterChange($action, fn () => (new DeleteStayAction())->run($stay));
                }),
        ];
    }

    /** Выполняет действие подвала окна правки. Ошибка предметной области: уведомление, закрывается только
     * само действие, окно правки остаётся открытым. Пропавшее заселение: уведомление, окна закрываются.
     * @param Action $action Действие подвала.
     * @param callable(): mixed $change Вызов действия над заселением.
     * @throws Halt
     * @return void
     */
    private function applyFooterChange(Action $action, callable $change): void
    {
        try {
            $change();
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            $this->refreshSchedule();
            $this->unmountAction(canCancelParentActions: false);

            $action->halt();
        } catch (ModelNotFoundException) {
            $this->notifyStayMissing();
        }

        $this->refreshSchedule();
    }

    /** Значения новой формы по клетке: койка, заезд, выезд на следующий день, состояние по дате.
     * @param array<string, mixed> $arguments Аргументы действия: bed_id и date.
     * @return array<string, mixed>|null
     */
    private function createDefaults(array $arguments): ?array
    {
        $date = $arguments['date'] ?? null;
        $bedId = $arguments['bed_id'] ?? null;

        if (! is_string($date) || ! is_numeric($bedId) || ! Bed::query()->whereKey((int) $bedId)->exists()) {
            return null;
        }

        $from = $this->parseDate($date);

        if ($from === null) {
            return null;
        }

        return [
            'bed_id' => (int) $bedId,
            'date_from' => $from->toDateString(),
            'date_to' => $from->addDay()->toDateString(),
            'status' => ($from->lte(today()) ? StayStatus::Living : StayStatus::Booked)->value,
            'tariff' => StayTariff::Standard->value,
        ];
    }

    /** Заселение из аргументов действия; загружается один раз за запрос.
     * @param array<string, mixed> $arguments Аргументы действия: stay.
     * @return Stay|null
     */
    private function findStay(array $arguments): ?Stay
    {
        $id = $arguments['stay'] ?? null;

        if (! is_numeric($id)) {
            return null;
        }

        $id = (int) $id;

        if (! array_key_exists($id, $this->loadedStays)) {
            $this->loadedStays[$id] = Stay::query()->with(['user', 'bed'])->find($id);
        }

        return $this->loadedStays[$id];
    }

    /** DTO заселения из данных формы.
     * @param array<string, mixed> $data Данные формы.
     * @return StayDto
     */
    private function toDto(array $data): StayDto
    {
        return StayDto::from([
            'bed_id' => (int) $data['bed_id'],
            'user_id' => (int) $data['user_id'],
            'date_from' => $data['date_from'],
            'date_to' => $data['date_to'],
            'status' => $data['status'],
            'tariff' => $data['tariff'],
            'manual_total' => filled($data['manual_total'] ?? null) ? (int) $data['manual_total'] : null,
            'notes' => filled($data['notes'] ?? null) ? $data['notes'] : null,
        ]);
    }

    /** Строки оплат для синхронизации из repeater.
     * @param array<string, mixed> $data Данные формы.
     * @return array<int, array{id: int|null, amount: int, paid_at: string, note: string|null}>
     */
    private function paymentRows(array $data): array
    {
        $rows = [];

        foreach (array_values($data['payments'] ?? []) as $row) {
            $rows[] = [
                'id' => filled($row['id'] ?? null) ? (int) $row['id'] : null,
                'amount' => (int) $row['amount'],
                'paid_at' => (string) $row['paid_at'],
                'note' => filled($row['note'] ?? null) ? $row['note'] : null,
            ];
        }

        return $rows;
    }

    /** Id оплат, бывших в форме правки при её открытии; нечисловые значения отбрасываются.
     * @param array<string, mixed> $data Данные формы.
     * @return array<int, int>
     */
    private function openedPaymentIds(array $data): array
    {
        $ids = $data['opened_payment_ids'] ?? [];

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_filter(array_map(fn (mixed $id): ?int => $this->toId($id), $ids)));
    }

    /** Выполняет изменение от перетаскивания; ошибки предметной области превращает в уведомления.
     * @param callable(): Stay $change Вызов действия переноса или растягивания.
     * @return void
     */
    private function applyDrag(callable $change): void
    {
        try {
            $change();
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        } catch (ModelNotFoundException) {
            $this->notifyDragFailed();
        }

        $this->refreshSchedule();
    }

    /** Целое положительное число из аргумента браузера.
     * @param mixed $value Значение аргумента.
     * @return int|null
     */
    private function toId(mixed $value): ?int
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }

    /** Показывает уведомление о неудачном переносе. */
    private function notifyDragFailed(): void
    {
        Notification::make()->danger()->title('Не удалось перенести заселение')->send();
    }

    /** Показывает уведомление о том, что заселение не найдено. */
    private function notifyStayMissing(): void
    {
        Notification::make()->danger()->title('Заселение не найдено')->send();
    }

    /** Сбрасывает закэшированные за запрос данные, чтобы сетка показала свежее состояние. */
    private function refreshSchedule(): void
    {
        $this->loadedStays = [];

        unset($this->schedule);
    }

    /** Выбранная дата; при некорректном значении — сегодня.
     * @return CarbonImmutable
     */
    private function selectedDate(): CarbonImmutable
    {
        return $this->parseDate($this->date) ?? today()->toImmutable();
    }

    /** Строгий разбор даты в формате Y-m-d; годы вне 2000–2100 отклоняются, чтобы не дойти до БД.
     * @param string $value Строка даты.
     * @return CarbonImmutable|null
     */
    private function parseDate(string $value): ?CarbonImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            return null;
        }

        $year = (int) $parsed->format('Y');

        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            return null;
        }

        return CarbonImmutable::parse($value);
    }
}
