<?php

namespace App\Filament\Schemas;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Schemas\Components\Group;
use Filament\Notifications\Notification;
use Filament\Forms\Components\FileUpload;
use Filament\Infolists\Components\ImageEntry;
use Filament\Schemas\Components\Utilities\Get;
use App\Filament\Resources\Users\Schemas\UserForm;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/** Фото документов гостя: ссылка для окна «Клиент» и окна правки заселения, загрузка из окна правки. */
class GuestDocs
{
    /** Имя поля загрузки документов в окне правки заселения. */
    public const FIELD = 'guest_docs';

    /** Ссылка на фото документов тем же способом, что у картинки Filament (диск и видимость поля загрузки клиента).
     * @param string|null $path Путь к файлу документов гостя (users.docs).
     * @return string|null Ссылка или null, если документов нет или файла нет на диске.
     */
    public static function url(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        return ImageEntry::make('docs')
            ->checkFileExistence()
            ->getImageUrl($path);
    }

    /** Загрузка документов под блоком «Документы: нет»: поле фото и кнопка «Сохранить документ».
     * Видна, только когда у гостя формы нет документов или их файла нет на диске.
     * @return Group
     */
    public static function uploadGroup(): Group
    {
        return Group::make([
            UserForm::docsUpload(self::FIELD)
                ->hiddenLabel()
                ->dehydrated(false)
                ->belowContent(self::saveAction()),
        ])
            ->visible(fn (Get $get): bool => ($user = self::findUser($get('user_id'))) !== null && self::url($user->docs) === null);
    }

    /** Кнопка «Сохранить документ»: сразу записывает выбранное фото гостю формы, окно и несохранённые правки остаются.
     * @return Action
     */
    private static function saveAction(): Action
    {
        return Action::make('saveGuestDocs')
            ->label('Сохранить документ')
            ->button()
            ->action(function (FileUpload $component, Get $get): void {
                $user = self::findUser($get('user_id'));
                $files = array_filter((array) $component->getRawState(), fn (mixed $file): bool => $file instanceof TemporaryUploadedFile);

                if ($user === null) {
                    return;
                }

                if ($files === []) {
                    Notification::make()->danger()->title('Выберите файл')->send();

                    return;
                }

                $component->getContainer()->validate();
                $component->saveUploadedFiles();

                $user->update(['docs' => $component->getState()]);

                $component->state(null);

                Notification::make()->success()->title('Документ сохранён')->send();
            });
    }

    /** Гость по id из формы; некорректный или несуществующий id даёт null.
     * @param mixed $id Id гостя.
     * @return User|null
     */
    private static function findUser(mixed $id): ?User
    {
        return is_numeric($id) ? User::query()->find((int) $id) : null;
    }
}
