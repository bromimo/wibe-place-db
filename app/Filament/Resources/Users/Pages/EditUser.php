<?php

namespace App\Filament\Resources\Users\Pages;

use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use App\Exceptions\UserHasStaysException;
use App\Filament\Resources\Users\UserResource;

class EditUser extends EditRecord
{
    protected static string  $resource = UserResource::class;
    protected static ?string $title    = 'Редактирование клиента';

    /** Кнопки в шапке: удаление с запретом для клиента с заселениями.
     * @return array<int, DeleteAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(function (User $record): bool {
                    try {
                        return (bool) $record->delete();
                    } catch (UserHasStaysException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return false;
                    }
                }),
        ];
    }
}
