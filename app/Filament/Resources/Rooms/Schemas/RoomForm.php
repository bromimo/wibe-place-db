<?php

namespace App\Filament\Resources\Rooms\Schemas;

use App\Models\Room;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Text;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/** Форма комнаты: номер, название, цена и число коек. */
class RoomForm
{
    /** Наибольшее число коек в комнате. */
    public const MAX_BEDS = 50;

    /** Настраивает форму комнаты.
     * @param Schema $schema Схема формы.
     * @return Schema
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(4)
            ->components([
                TextInput::make('number')
                    ->label('Номер')
                    ->integer()
                    ->minValue(1)
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->default(fn (): int => self::nextNumber())
                    ->live(onBlur: true)
                    ->afterStateUpdated(self::followNumber(...)),
                TextInput::make('name')
                    ->label('Название')
                    ->maxLength(255)
                    ->required()
                    ->default(fn (): string => Room::defaultName(self::nextNumber())),
                TextInput::make('price')
                    ->label('Цена за ночь')
                    ->integer()
                    ->minValue(0)
                    ->required(),
                TextInput::make('bed_count')
                    ->label('Количество коек')
                    ->integer()
                    ->minValue(0)
                    ->maxValue(self::MAX_BEDS)
                    ->required()
                    ->default(1),
                Text::make(fn (?Room $record): string => self::bedsLine($record))
                    ->visibleOn('edit')
                    ->columnSpanFull(),
            ]);
    }

    /** Следующий свободный номер: наибольший существующий плюс один.
     * @return int
     */
    private static function nextNumber(): int
    {
        return (int) Room::query()->max('number') + 1;
    }

    /** При создании переносит номер в название, пока название не изменено вручную.
     * @param Get $get Доступ к состоянию формы.
     * @param Set $set Запись в состояние формы.
     * @param int|string|null $old Прежний номер.
     * @param int|string|null $state Новый номер.
     * @param string $operation Операция формы.
     * @return void
     */
    private static function followNumber(Get $get, Set $set, int|string|null $old, int|string|null $state, string $operation): void
    {
        $name = (string) $get('name');

        if ($operation === 'create' && ($name === '' || $name === Room::defaultName($old))) {
            $set('name', Room::defaultName($state));
        }
    }

    /** Строка с названиями коек комнаты.
     * @param Room|null $record Комната.
     * @return string
     */
    private static function bedsLine(?Room $record): string
    {
        $names = $record?->beds()->pluck('name')->all() ?? [];

        return 'Койки: ' . ($names === [] ? 'нет' : implode(', ', $names));
    }
}
