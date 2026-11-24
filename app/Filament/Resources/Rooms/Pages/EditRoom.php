<?php

namespace App\Filament\Resources\Rooms\Pages;

use App\Models\Room;
use Filament\Actions\DeleteAction;
use App\Actions\Room\DeleteRoomAction;
use App\Actions\Room\UpdateRoomAction;
use Illuminate\Database\Eloquent\Model;
use App\Exceptions\BedHasStaysException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use App\Exceptions\BedCountDecreaseException;
use App\Filament\Resources\Rooms\RoomResource;

/** Редактирование комнаты и числа её коек. */
class EditRoom extends EditRecord
{
    protected static string $resource = RoomResource::class;
    protected static ?string $title = 'Редактирование комнаты';

    /** Кнопки в шапке: удаление комнаты вместе с койками, если на них нет заселений.
     * @return array<int, DeleteAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(function (Room $record): bool {
                    try {
                        (new DeleteRoomAction())->run($record);

                        return true;
                    } catch (BedHasStaysException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return false;
                    }
                }),
        ];
    }

    /** Добавляет в форму текущее число коек.
     * @param array<string, mixed> $data Атрибуты комнаты.
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['bed_count'] = $this->getRecord()->beds()->count();

        return $data;
    }

    /** Сохраняет комнату и число коек через действие.
     * @param Model $record Комната.
     * @param array<string, mixed> $data Данные формы.
     * @throws BedCountDecreaseException
     * @return Model
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Room $record */
        return (new UpdateRoomAction())->run($record, (int) $data['number'], (string) $data['name'], (int) $data['price'], (int) $data['bed_count']);
    }

    /** Сохраняет комнату; если число коек уменьшить нельзя, показывает причину и оставляет форму открытой.
     * @param bool $shouldRedirect Переходить ли после сохранения.
     * @param bool $shouldSendSavedNotification Показывать ли уведомление о сохранении.
     * @return void
     */
    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        try {
            parent::save($shouldRedirect, $shouldSendSavedNotification);
        } catch (BedCountDecreaseException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }
}
