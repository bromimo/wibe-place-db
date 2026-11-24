<?php

namespace App\Filament\Resources\Rooms\Tables;

use App\Models\Room;
use Filament\Tables\Table;
use Filament\Actions\EditAction;
use Illuminate\Support\Collection;
use Filament\Actions\BulkActionGroup;
use App\Actions\Room\DeleteRoomAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use App\Exceptions\BedHasStaysException;
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
            ->defaultSort('number')
            ->columns([
                TextColumn::make('number')
                    ->label('Номер')
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('price')
                    ->label('Цена за ночь')
                    ->sortable(),
                TextColumn::make('beds_count')
                    ->label('Число коек')
                    ->counts('beds')
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
                                    (new DeleteRoomAction())->run($record);
                                } catch (BedHasStaysException $e) {
                                    $action->reportBulkProcessingFailure($e::class, $e->getMessage());
                                }
                            });
                        })
                        ->failureNotification(fn (Notification $notification): Notification => $notification->danger()),
                ]),
            ]);
    }
}
