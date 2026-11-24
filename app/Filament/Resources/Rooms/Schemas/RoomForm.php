<?php

namespace App\Filament\Resources\Rooms\Schemas;

use App\Models\Bed;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Repeater;
use App\Exceptions\BedHasStaysException;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/** Форма комнаты с койками. */
class RoomForm
{
    /** Настраивает форму комнаты.
     * @param Schema $schema Схема формы.
     * @return Schema
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                TextInput::make('name')
                    ->label('Название')
                    ->maxLength(255)
                    ->required(),
                TextInput::make('price')
                    ->label('Цена за ночь')
                    ->integer()
                    ->minValue(0)
                    ->required(),
                TextInput::make('sort')
                    ->label('Порядок')
                    ->integer()
                    ->minValue(0)
                    ->default(0)
                    ->required(),
                Repeater::make('beds')
                    ->label('Койки')
                    ->relationship('beds')
                    ->columns(2)
                    ->columnSpanFull()
                    ->addActionLabel('Добавить койку')
                    ->deleteAction(fn (Action $action): Action => $action->before(self::guardBedDeletion(...)))
                    ->schema([
                        TextInput::make('name')
                            ->label('Название')
                            ->maxLength(255)
                            ->required(),
                        TextInput::make('sort')
                            ->label('Порядок')
                            ->integer()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                    ]),
            ]);
    }

    /** Не даёт убрать из формы сохранённую койку с заселениями.
     * @param array<string, mixed> $arguments Аргументы действия: ключ элемента repeater.
     * @param Action $action Действие удаления элемента.
     * @return void
     */
    private static function guardBedDeletion(array $arguments, Action $action): void
    {
        $item = (string) ($arguments['item'] ?? '');

        if (! str_starts_with($item, 'record-')) {
            return;
        }

        $hasStays = Bed::query()
            ->whereKey(substr($item, strlen('record-')))
            ->whereHas('stays')
            ->exists();

        if ($hasStays) {
            Notification::make()->danger()->title((new BedHasStaysException())->getMessage())->send();

            $action->cancel();
        }
    }
}
