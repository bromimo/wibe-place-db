<?php

namespace App\Filament\Resources\Rooms\Pages;

use App\Actions\Room\CreateRoomAction;
use Illuminate\Database\Eloquent\Model;
use Filament\Resources\Pages\CreateRecord;
use App\Filament\Resources\Rooms\RoomResource;

/** Создание комнаты с заданным числом коек. */
class CreateRoom extends CreateRecord
{
    protected static string $resource = RoomResource::class;
    protected static ?string $title = 'Создание комнаты';

    /** Создаёт комнату и её койки через действие.
     * @param array<string, mixed> $data Данные формы.
     * @return Model
     */
    protected function handleRecordCreation(array $data): Model
    {
        return (new CreateRoomAction())->run((int) $data['number'], (string) $data['name'], (int) $data['price'], (int) $data['bed_count']);
    }
}
