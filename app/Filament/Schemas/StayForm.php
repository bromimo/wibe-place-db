<?php

namespace App\Filament\Schemas;

use App\Models\Bed;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use DateTimeImmutable;
use App\Models\Payment;
use App\Enums\StayStatus;
use App\Enums\StayTariff;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Support\HtmlString;
use Filament\Support\Icons\Heroicon;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\DatePicker;
use App\Actions\Stay\ResolveStayPriceAction;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use App\Filament\Resources\Users\Schemas\UserForm;

/** Поля и раскладка окна заселения на графике. */
class StayForm
{
    /** Допустимый диапазон дат заселения: годы 2000–2100, как у дат из браузера на странице графика. */
    private const MIN_DATE = '2000-01-01';
    private const MAX_DATE = '2100-12-31';

    /** Id сворачиваемой секции «Детали заселения»; по нему её открывает и закрывает кнопка. */
    public const DETAILS_ID = 'sch-stay-details';

    /** Окно «Новое заселение»: гость и койка, даты с ночами, тариф со стоимостью, состояние, примечание.
     * @return array<int, mixed>
     */
    public static function createComponents(): array
    {
        return [
            Grid::make(['default' => 1, 'sm' => 2])->schema([
                self::guestSelect(),
                self::bedSelect(),
            ]),
            self::datesRow(null),
            self::tariffRow(null),
            Grid::make(['default' => 1, 'sm' => 2])->schema([
                self::statusSelect(),
            ]),
            self::notesField(),
        ];
    }

    /** Окно правки: гость, даты с ночами, тариф со стоимостью, деньги, быстрая оплата, кнопки и «Детали заселения».
     * @param Stay $stay Правимое заселение.
     * @param Action $takePayment Кнопка «Оплата» быстрого приёма оплаты.
     * @param Action $editGuest Карандаш «Редактировать клиента» в строке гостя.
     * @param array<int, Action|null> $stateActions Кнопки смены состояния: «Заселить», «Выезд».
     * @return array<int, mixed>
     */
    public static function editComponents(Stay $stay, Action $takePayment, Action $editGuest, array $stateActions): array
    {
        return [
            Html::make(fn (Get $get, Html $component): HtmlString => self::guestHtml($get('user_id'), $component->getAction($editGuest->getName())))
                ->key('guestRow')
                ->registerActions([$editGuest]),
            Html::make(fn (Get $get): HtmlString => self::docsHtml($get('user_id')))->key('docsRow'),
            GuestDocs::uploadGroup(),
            self::datesRow($stay),
            self::tariffRow($stay),
            Html::make(fn (Get $get): HtmlString => self::moneyHtml($get, $stay))->key('moneyRow'),
            Grid::make(['default' => 1, 'sm' => 2])->schema([
                TextInput::make('quick_payment')
                    ->label('Принять оплату')
                    ->prefix('₴')
                    ->inputMode('numeric')
                    ->placeholder(fn (Get $get): ?string => self::debtPlaceholder($get, $stay))
                    ->dehydrated(false)
                    // Enter в поле суммы принимает оплату (нажимает «Оплата»), а не сохраняет и закрывает окно.
                    ->extraInputAttributes([
                        'x-on:keydown.enter.prevent.stop' => "let n = \$el; while (n && ! n.querySelector('.sch-pay-btn')) { n = n.parentElement } n?.querySelector('.sch-pay-btn')?.click()",
                    ])
                    ->afterContent($takePayment),
            ]),
            Actions::make([
                ...array_filter($stateActions),
                Action::make('toggleDetails')
                    ->label('Детали заселения')
                    ->color('gray')
                    ->icon(Heroicon::ChevronUpDown)
                    ->extraAttributes(['class' => 'sch-stay-details-toggle sch-btn-blue'])
                    ->alpineClickHandler("\$dispatch('toggle-section', { id: '" . self::DETAILS_ID . "' })"),
            ])
                ->key('stayButtons')
                ->extraAttributes(['class' => 'sch-stay-buttons']),
            Section::make()
                ->id(self::DETAILS_ID)
                ->collapsible()
                ->collapsed()
                ->extraAttributes(['class' => 'sch-stay-details'])
                ->schema([
                    Grid::make(['default' => 1, 'sm' => 2])->schema([
                        self::guestSelect(),
                        self::bedSelect(),
                        self::statusSelect(),
                    ]),
                    self::notesField(),
                    Hidden::make('opened_payment_ids'),
                    self::paymentsRepeater(),
                ]),
        ];
    }

    /** Заголовок окна правки: номер заселения слева, статус цветом справа.
     * @param Stay $stay Заселение.
     * @return HtmlString
     */
    public static function editHeading(Stay $stay): HtmlString
    {
        return new HtmlString(
            '<span class="sch-stay-heading"><span>Заселение № ' . $stay->id . '</span>'
            . '<span class="sch-stay-status sch-stay-status-' . e($stay->status->value) . '">Статус: ' . e($stay->status->label()) . '</span></span>'
        );
    }

    /** Значения формы правки из заселения; opened_payment_ids — оплаты, которые видит открытая форма.
     * @param Stay $stay Заселение.
     * @return array<string, mixed>
     */
    public static function fillFromStay(Stay $stay): array
    {
        $payments = $stay->payments()->orderBy('paid_at')->orderBy('id')->get();

        return [
            'user_id' => $stay->user_id,
            'bed_id' => $stay->bed_id,
            'date_from' => $stay->date_from->toDateString(),
            'date_to' => $stay->date_to->toDateString(),
            'status' => $stay->status->value,
            'tariff' => $stay->tariff->value,
            'manual_total' => $stay->manual_total,
            'notes' => $stay->notes,
            'opened_payment_ids' => $payments->pluck('id')->all(),
            'payments' => $payments->map(fn (Payment $payment): array => self::paymentRow($payment))->all(),
        ];
    }

    /** Добавляет принятую оплату в открытую форму: строка в списке оплат и id среди «виденных при открытии»,
     * чтобы последующее «Сохранить изменения» её не удалило; поле суммы очищается.
     * @param Get $get Доступ к состоянию формы.
     * @param Set $set Запись в состояние формы.
     * @param Payment $payment Созданная оплата.
     * @return void
     */
    public static function addPaymentToState(Get $get, Set $set, Payment $payment): void
    {
        $rows = (array) ($get('payments') ?? []);
        $rows[(string) Str::uuid()] = self::paymentRow($payment);

        $openedIds = (array) ($get('opened_payment_ids') ?? []);
        $openedIds[] = $payment->id;

        $set('payments', $rows);
        $set('opened_payment_ids', array_values($openedIds));
        $set('quick_payment', null);
    }

    /** Сумма в виде «₴ 2300».
     * @param int $amount Сумма в гривнах.
     * @return string
     */
    public static function hryvnias(int $amount): string
    {
        return '₴ ' . $amount;
    }

    /** Гости для поля поиска: каждое слово запроса должно найтись в фамилии, имени или телефоне.
     * @param string $search Строка поиска, например «Фамилия Имя».
     * @return array<int, string>
     */
    public static function searchGuests(string $search): array
    {
        $words = preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $query = User::query();

        foreach ($words as $word) {
            $query->where(fn ($match) => $match
                ->where('last_name', 'like', "%{$word}%")
                ->orWhere('first_name', 'like', "%{$word}%")
                ->orWhere('phone', 'like', "%{$word}%"));
        }

        return $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (User $user): array => [$user->id => $user->full_name])
            ->all();
    }

    /** Поле гостя: поиск по фамилии, имени и телефону, создание нового клиента, окно «Клиент» при выборе из списка.
     * @return Select
     */
    private static function guestSelect(): Select
    {
        return Select::make('user_id')
            ->label('Гость')
            ->searchable()
            ->required()
            ->exists(User::class, 'id')
            ->getSearchResultsUsing(fn (string $search): array => self::searchGuests($search))
            ->getOptionLabelUsing(fn ($value): ?string => User::query()->find($value)?->full_name)
            ->live()
            ->registerActions([GuestCard::action()])
            ->afterStateUpdated(fn (Select $component, mixed $state, mixed $old) => GuestCard::open($component, $state, $old))
            ->createOptionModalHeading('Новый клиент')
            ->createOptionForm(fn (Schema $schema): Schema => UserForm::configure($schema))
            ->createOptionAction(fn (Action $action): Action => $action
                ->modalWidth(Width::FourExtraLarge)
                ->modalSubmitActionLabel('Создать')
                ->modalCancelActionLabel('Отмена'))
            ->createOptionUsing(fn (array $data): int => User::query()->create($data)->id);
    }

    /** Поле койки, сгруппированной по комнатам.
     * @return Select
     */
    private static function bedSelect(): Select
    {
        return Select::make('bed_id')
            ->label('Койка')
            ->options(self::bedOptions(...))
            ->required()
            ->exists(Bed::class, 'id')
            ->live();
    }

    /** Поле состояния заселения.
     * @return Select
     */
    private static function statusSelect(): Select
    {
        return Select::make('status')
            ->label('Состояние')
            ->options(StayStatus::options())
            ->required();
    }

    /** Поле примечания.
     * @return Textarea
     */
    private static function notesField(): Textarea
    {
        return Textarea::make('notes')
            ->label('Примечание');
    }

    /** Строка дат: «с» слева, число ночей в рамке по центру, «по» справа.
     * @param Stay|null $stay Правимое заселение.
     * @return Flex
     */
    private static function datesRow(?Stay $stay): Flex
    {
        return Flex::make([
            DatePicker::make('date_from')
                ->label('с')
                ->required()
                ->minDate(self::MIN_DATE)
                ->maxDate(self::MAX_DATE)
                ->live(),
            Html::make(fn (Get $get): HtmlString => self::nightsHtml($get))
                ->key('nightsBox')
                ->grow(false),
            DatePicker::make('date_to')
                ->label('по')
                ->required()
                ->minDate(self::MIN_DATE)
                ->maxDate(self::MAX_DATE)
                ->after('date_from')
                ->live(),
        ])
            ->from('sm')
            ->verticalAlignment('end')
            ->extraAttributes(['class' => 'sch-stay-dates']);
    }

    /** Строка тарифа: выбор тарифа слева, стоимость справа — расчётная для «Нашего», поле суммы для «Ручного».
     * @param Stay|null $stay Правимое заселение.
     * @return Grid
     */
    private static function tariffRow(?Stay $stay): Grid
    {
        return Grid::make(['default' => 1, 'sm' => 2])->schema([
            Select::make('tariff')
                ->label('Тариф')
                ->options(StayTariff::options())
                ->required()
                ->live(),
            Html::make(fn (Get $get): HtmlString => self::costHtml($get, $stay))
                ->key('costBox')
                ->visible(fn (Get $get): bool => $get('tariff') !== StayTariff::Manual->value),
            TextInput::make('manual_total')
                ->label('Стоимость')
                ->prefix('₴')
                ->integer()
                ->minValue(0)
                ->required()
                ->extraInputAttributes(['class' => 'sch-stay-manual-total'])
                ->visible(fn (Get $get): bool => $get('tariff') === StayTariff::Manual->value)
                ->live(onBlur: true),
        ]);
    }

    /** Список оплат заселения в «Деталях заселения».
     * @return Repeater
     */
    private static function paymentsRepeater(): Repeater
    {
        return Repeater::make('payments')
            ->label('Оплаты')
            ->reorderable(false)
            ->columns(3)
            ->defaultItems(0)
            ->addActionLabel('Добавить оплату')
            ->live()
            ->schema([
                Hidden::make('id'),
                TextInput::make('amount')
                    ->label('Сумма')
                    ->integer()
                    ->minValue(1)
                    ->required()
                    ->live(onBlur: true),
                DatePicker::make('paid_at')
                    ->label('Дата')
                    ->default(fn (): string => today()->toDateString())
                    ->required(),
                TextInput::make('note')
                    ->label('Примечание')
                    ->maxLength(255),
            ]);
    }

    /** Строка оплаты для списка оплат формы.
     * @param Payment $payment Оплата.
     * @return array{id: int, amount: int, paid_at: string, note: string|null}
     */
    private static function paymentRow(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'amount' => $payment->amount,
            'paid_at' => $payment->paid_at->toDateString(),
            'note' => $payment->note,
        ];
    }

    /** Койки, сгруппированные по комнатам.
     * @return array<string, array<int, string>>
     */
    private static function bedOptions(): array
    {
        $options = [];

        $rooms = Room::query()->with('beds')->orderBy('number')->get();

        foreach ($rooms as $room) {
            foreach ($room->beds as $bed) {
                $options[$room->name][$bed->id] = $bed->name;
            }
        }

        return $options;
    }

    /** Строка гостя окна заселения и окна «Клиент»: «Фамилия Имя» крупно слева с карандашом правки клиента,
     * телефон справа (прочерк, если пуст).
     * @param mixed $userId Id гостя из формы.
     * @param Action|null $editGuest Карандаш «Редактировать клиента»; не показывается без гостя или при null.
     * @return HtmlString
     */
    public static function guestHtml(mixed $userId, ?Action $editGuest): HtmlString
    {
        $user = is_numeric($userId) ? User::query()->find((int) $userId) : null;
        $name = $user === null ? '—' : $user->full_name;
        $phone = filled($user?->phone) ? $user->phone : '—';
        $icon = $user === null || $editGuest === null ? '' : $editGuest->toHtml();

        return new HtmlString(
            '<div class="sch-stay-guest"><span class="sch-stay-guest-title"><span class="sch-stay-guest-name">' . e($name) . '</span>' . $icon . '</span>'
            . '<span class="sch-stay-guest-phone">' . e($phone) . '</span></div>'
        );
    }

    /** Блок «Документы» окна заселения и окна «Клиент»: превью фото документов гостя со ссылкой в новой вкладке
     * или серое «Документы: нет».
     * @param mixed $userId Id гостя из формы.
     * @return HtmlString
     */
    public static function docsHtml(mixed $userId): HtmlString
    {
        $user = is_numeric($userId) ? User::query()->find((int) $userId) : null;
        $url = GuestDocs::url($user?->docs);

        if ($url === null) {
            return new HtmlString('<div class="sch-stay-docs sch-stay-docs-none">Документы: нет</div>');
        }

        return new HtmlString(
            '<div class="sch-stay-docs"><span class="sch-stay-docs-label">Документы</span>'
            . '<a class="sch-stay-docs-link" href="' . e($url) . '" target="_blank" rel="noopener">'
            . '<img class="sch-stay-docs-image" src="' . e($url) . '" alt="Документы"></a></div>'
        );
    }

    /** Рамка с числом ночей и подписью «Ночей»; прочерк при неполных или неверных датах.
     * @param Get $get Доступ к состоянию формы.
     * @return HtmlString
     */
    private static function nightsHtml(Get $get): HtmlString
    {
        $nights = self::nights($get);

        return new HtmlString(
            '<div class="sch-stay-nights"><span class="sch-stay-nights-value">' . ($nights ?? '—') . '</span>'
            . '<span class="sch-stay-nights-caption">Ночей</span></div>'
        );
    }

    /** Стоимость по «нашему» тарифу крупно с подписью «ночи × ₴ цена»; прочерк, если данных не хватает.
     * @param Get $get Доступ к состоянию формы.
     * @param Stay|null $stay Правимое заселение.
     * @return HtmlString
     */
    private static function costHtml(Get $get, ?Stay $stay): HtmlString
    {
        $cost = self::standardCost($get, $stay);
        $value = $cost === null ? '—' : self::hryvnias($cost['total']);
        $hint = $cost === null ? '' : "{$cost['nights']} × " . self::hryvnias($cost['price']);

        return new HtmlString(
            '<div class="sch-stay-cost"><span class="sch-stay-cost-label">Стоимость</span>'
            . '<span class="sch-stay-cost-value">' . e($value) . '</span>'
            . '<span class="sch-stay-cost-hint">' . e($hint) . '</span></div>'
        );
    }

    /** Строка денег: «Оплачено» зелёным слева, «Остаток» красным справа (серым при нуле).
     * @param Get $get Доступ к состоянию формы.
     * @param Stay $stay Правимое заселение.
     * @return HtmlString
     */
    private static function moneyHtml(Get $get, Stay $stay): HtmlString
    {
        $debt = self::debt($get, $stay);
        $debtClass = $debt === 0 ? 'sch-stay-debt sch-stay-zero' : 'sch-stay-debt';

        return new HtmlString(
            '<div class="sch-stay-money"><span class="sch-stay-paid">Оплачено: ' . e(self::hryvnias(self::paid($get))) . '</span>'
            . '<span class="' . $debtClass . '">Остаток: ' . e($debt === null ? '—' : self::hryvnias($debt)) . '</span></div>'
        );
    }

    /** Подсказка поля быстрой оплаты: текущий остаток.
     * @param Get $get Доступ к состоянию формы.
     * @param Stay $stay Правимое заселение.
     * @return string|null
     */
    private static function debtPlaceholder(Get $get, Stay $stay): ?string
    {
        $debt = self::debt($get, $stay);

        return $debt === null ? null : (string) $debt;
    }

    /** Сумма строк оплат формы.
     * @param Get $get Доступ к состоянию формы.
     * @return int
     */
    private static function paid(Get $get): int
    {
        $paid = 0;

        foreach ((array) $get('payments') as $row) {
            $paid += (int) ($row['amount'] ?? 0);
        }

        return $paid;
    }

    /** Остаток по форме: стоимость минус оплаты, не меньше нуля; null, если стоимость не определена.
     * @param Get $get Доступ к состоянию формы.
     * @param Stay $stay Правимое заселение.
     * @return int|null
     */
    private static function debt(Get $get, Stay $stay): ?int
    {
        $total = $get('tariff') === StayTariff::Manual->value
            ? (is_numeric($get('manual_total')) ? (int) $get('manual_total') : null)
            : (self::standardCost($get, $stay)['total'] ?? null);

        return $total === null ? null : max(0, $total - self::paid($get));
    }

    /** Число ночей между датами формы или null, если даты неполные или выезд не позже заезда.
     * @param Get $get Доступ к состоянию формы.
     * @return int|null
     */
    private static function nights(Get $get): ?int
    {
        $from = self::parseDate($get('date_from'));
        $to = self::parseDate($get('date_to'));

        if ($from === null || $to === null || $to <= $from) {
            return null;
        }

        return Stay::nightsBetween($from, $to);
    }

    /** Ночи, цена за ночь и итог по «нашему» тарифу.
     * Цена — по общему правилу ResolveStayPriceAction, ночи — по Stay::nightsBetween().
     * @param Get $get Доступ к состоянию формы.
     * @param Stay|null $stay Правимое заселение.
     * @return array{nights: int, price: int, total: int}|null
     */
    private static function standardCost(Get $get, ?Stay $stay): ?array
    {
        $nights = self::nights($get);
        $bedId = $get('bed_id');

        if ($nights === null || ! is_numeric($bedId)) {
            return null;
        }

        $bed = Bed::query()->with('room')->find((int) $bedId);

        if ($bed === null) {
            return null;
        }

        $price = (int) (new ResolveStayPriceAction())->run(StayTariff::Standard, $bed, $stay, null)['price_per_night'];

        return ['nights' => $nights, 'price' => $price, 'total' => $nights * $price];
    }

    /** Дата из состояния поля или null, если она некорректна.
     * @param mixed $value Значение поля.
     * @return CarbonImmutable|null
     */
    private static function parseDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonInterface) {
            $value = $value->toDateString();
        }

        if (! is_string($value)) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? CarbonImmutable::parse($value) : null;
    }
}
