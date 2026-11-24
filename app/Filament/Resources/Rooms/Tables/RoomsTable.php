<?php

namespace App\Filament\Resources\Rooms\Tables;

use App\Models\Room;
use Filament\Tables\Table;
use Filament\Actions\EditAction;
use Illuminate\Support\Collection;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use App\Exceptions\RoomHasBedsException;
use Filament\Notifications\Notification;

/** Таблица комнат. */
class RoomsTable
{
    /** Настраивает таблицу комнат.
     * @param Table $table Таблица.
     * @return Table
     */
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('price')
                    ->label('Цена за ночь')
                    ->sortable(),
                TextColumn::make('beds_count')
                    ->label('Коек')
                    ->counts('beds')
                    ->sortable(),
                TextColumn::make('sort')
                    ->label('Порядок')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->using(function (DeleteBulkAction $action, Collection $records): void {
                            $records->each(function (Room $record) use ($action): void {
                                try {
                                    $record->delete() || $action->reportBulkProcessingFailure();
                                } catch (RoomHasBedsException $e) {
                                    $action->reportBulkProcessingFailure($e::class, $e->getMessage());
                                }
                            });
                        })
                        ->failureNotification(fn (Notification $notification): Notification => $notification->danger()),
                ]),
            ]);
    }
}
