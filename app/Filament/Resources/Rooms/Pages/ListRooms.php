<?php

namespace App\Filament\Resources\Rooms\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\Rooms\RoomResource;

/** Список комнат. */
class ListRooms extends ListRecords
{
    protected static string $resource = RoomResource::class;

    /** Кнопки в шапке страницы.
     * @return array<int, CreateAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
