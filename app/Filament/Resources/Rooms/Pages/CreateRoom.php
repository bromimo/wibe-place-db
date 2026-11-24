<?php

namespace App\Filament\Resources\Rooms\Pages;

use Filament\Resources\Pages\CreateRecord;
use App\Filament\Resources\Rooms\RoomResource;

/** Создание комнаты. */
class CreateRoom extends CreateRecord
{
    protected static string $resource = RoomResource::class;
    protected static ?string $title = 'Создание комнаты';
}
