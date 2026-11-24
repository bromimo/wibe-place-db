<?php

namespace App\Filament\Resources\Rooms\Pages;

use App\Models\Room;
use Filament\Actions\DeleteAction;
use App\Exceptions\BedHasStaysException;
use App\Exceptions\RoomHasBedsException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use App\Filament\Resources\Rooms\RoomResource;

/** Редактирование комнаты и её коек. */
class EditRoom extends EditRecord
{
    protected static string $resource = RoomResource::class;
    protected static ?string $title = 'Редактирование комнаты';

    /** Кнопки в шапке: удаление с запретом для комнаты с койками.
     * @return array<int, DeleteAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(function (Room $record): bool {
                    try {
                        return (bool) $record->delete();
                    } catch (RoomHasBedsException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return false;
                    }
                }),
        ];
    }

    /** Сохраняет комнату; если удалённая в форме койка занята, откатывает сохранение и показывает причину.
     * @param bool $shouldRedirect Переходить ли после сохранения.
     * @param bool $shouldSendSavedNotification Показывать ли уведомление о сохранении.
     * @return void
     */
    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        try {
            parent::save($shouldRedirect, $shouldSendSavedNotification);
        } catch (BedHasStaysException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            $this->getRecord()->refresh();
            $this->fillForm();
        }
    }
}
