<?php

namespace App\Filament\Schemas;

use App\Models\Bed;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use DateTimeImmutable;
use App\Enums\StayStatus;
use App\Enums\StayTariff;
use Carbon\CarbonInterface;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Text;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Components\Utilities\Get;

/** Поля окна заселения на графике. */
class StayForm
{
    /** Поля формы; для правки заселения добавляются оплаты и строка «Оплачено / Долг».
     * @param Stay|null $stay Правимое заселение или null при создании.
     * @return array<int, mixed>
     */
    public static function components(?Stay $stay = null): array
    {
        $components = [
            self::guestSelect(),
            Select::make('bed_id')
                ->label('Койка')
                ->options(self::bedOptions(...))
                ->required()
                ->live(),
            DatePicker::make('date_from')
                ->label('Заезд')
                ->required()
                ->live(),
            DatePicker::make('date_to')
                ->label('Выезд')
                ->required()
                ->after('date_from')
                ->live(),
            Select::make('status')
                ->label('Состояние')
                ->options(StayStatus::options())
                ->required(),
            Select::make('tariff')
                ->label('Тариф')
                ->options(StayTariff::options())
                ->required()
                ->live(),
            TextInput::make('manual_total')
                ->label('Сумма')
                ->integer()
                ->minValue(0)
                ->required()
                ->visible(fn (Get $get): bool => $get('tariff') === StayTariff::Manual->value)
                ->live(onBlur: true),
            Text::make(fn (Get $get): string => self::costLine($get, $stay))
                ->visible(fn (Get $get): bool => $get('tariff') !== StayTariff::Manual->value),
            Textarea::make('notes')
                ->label('Примечание'),
        ];

        if ($stay === null) {
            return $components;
        }

        return [
            ...$components,
            Hidden::make('opened_payment_ids'),
            Repeater::make('payments')
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
                ]),
            Text::make(fn (Get $get): string => self::paidLine($get, $stay)),
        ];
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
            'payments' => $payments
                ->map(fn ($payment): array => [
                    'id' => $payment->id,
                    'amount' => $payment->amount,
                    'paid_at' => $payment->paid_at->toDateString(),
                    'note' => $payment->note,
                ])
                ->all(),
        ];
    }

    /** Поле гостя: поиск по фамилии, имени и телефону, создание нового клиента.
     * @return Select
     */
    private static function guestSelect(): Select
    {
        return Select::make('user_id')
            ->label('Гость')
            ->searchable()
            ->required()
            ->getSearchResultsUsing(fn (string $search): array => User::query()
                ->where(fn ($query) => $query
                    ->where('last_name', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%"))
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->limit(50)
                ->get()
                ->mapWithKeys(fn (User $user): array => [$user->id => $user->full_name])
                ->all())
            ->getOptionLabelUsing(fn ($value): ?string => User::query()->find($value)?->full_name)
            ->createOptionModalHeading('Новый клиент')
            ->createOptionForm([
                TextInput::make('last_name')
                    ->label('Фамилия')
                    ->maxLength(255)
                    ->required(),
                TextInput::make('first_name')
                    ->label('Имя')
                    ->maxLength(255)
                    ->required(),
                TextInput::make('phone')
                    ->label('Телефон')
                    ->tel()
                    ->maxLength(64)
                    ->validationMessages([
                        'regex' => 'Введите номер телефона',
                    ]),
            ])
            ->createOptionUsing(fn (array $data): int => User::query()->create($data)->id);
    }

    /** Койки, сгруппированные по комнатам.
     * @return array<string, array<int, string>>
     */
    private static function bedOptions(): array
    {
        $options = [];

        $rooms = Room::query()->with('beds')->orderBy('sort')->orderBy('id')->get();

        foreach ($rooms as $room) {
            foreach ($room->beds as $bed) {
                $options[$room->name][$bed->id] = $bed->name;
            }
        }

        return $options;
    }

    /** Строка стоимости по «нашему» тарифу или прочерк, если данных не хватает.
     * @param Get $get Доступ к состоянию формы.
     * @param Stay|null $stay Правимое заселение.
     * @return string
     */
    private static function costLine(Get $get, ?Stay $stay): string
    {
        $cost = self::standardCost($get, $stay);

        if ($cost === null) {
            return 'Стоимость: —';
        }

        return "Стоимость: {$cost['nights']} × {$cost['price']} = {$cost['total']}";
    }

    /** Строка «Оплачено / Долг» по введённым оплатам.
     * @param Get $get Доступ к состоянию формы.
     * @param Stay $stay Правимое заселение.
     * @return string
     */
    private static function paidLine(Get $get, Stay $stay): string
    {
        $paid = 0;

        foreach ((array) $get('payments') as $row) {
            $paid += (int) ($row['amount'] ?? 0);
        }

        $total = $get('tariff') === StayTariff::Manual->value
            ? (is_numeric($get('manual_total')) ? (int) $get('manual_total') : null)
            : (self::standardCost($get, $stay)['total'] ?? null);

        $debt = $total === null ? '—' : max(0, $total - $paid);

        return "Оплачено: {$paid} / Долг: {$debt}";
    }

    /** Ночи, цена за ночь и итог по «нашему» тарифу.
     * Для правки в той же комнате берётся зафиксированная цена заселения, иначе текущая цена комнаты.
     * @param Get $get Доступ к состоянию формы.
     * @param Stay|null $stay Правимое заселение.
     * @return array{nights: int, price: int, total: int}|null
     */
    private static function standardCost(Get $get, ?Stay $stay): ?array
    {
        $from = self::parseDate($get('date_from'));
        $to = self::parseDate($get('date_to'));
        $bedId = $get('bed_id');

        if ($from === null || $to === null || ! is_numeric($bedId) || $to <= $from) {
            return null;
        }

        $bed = Bed::query()->with('room')->find((int) $bedId);

        if ($bed === null) {
            return null;
        }

        $keepsPrice = $stay !== null
            && $stay->tariff !== StayTariff::Manual
            && $stay->bed->room_id === $bed->room_id;

        $price = (int) ($keepsPrice ? $stay->price_per_night : $bed->room->price);
        $nights = (int) $from->diff($to)->days;

        return ['nights' => $nights, 'price' => $price, 'total' => $nights * $price];
    }

    /** Дата из состояния поля или null, если она некорректна.
     * @param mixed $value Значение поля.
     * @return DateTimeImmutable|null
     */
    private static function parseDate(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof CarbonInterface) {
            $value = $value->toDateString();
        }

        if (! is_string($value)) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? $parsed : null;
    }
}
