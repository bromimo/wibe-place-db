<?php

namespace App\Filament\Schemas;

use Filament\Infolists\Components\ImageEntry;

/** Ссылка на фото документов гостя — общая для окна «Клиент» и окна правки заселения. */
class GuestDocs
{
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
}
