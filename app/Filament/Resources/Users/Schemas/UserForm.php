<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\FileUpload;

/** Форма клиента: раздел «Клиенты» и окно «Новый клиент» на графике. */
class UserForm
{
    /** Раскладка формы клиента в три колонки.
     * @param Schema $schema Схема формы.
     * @return Schema
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                TextInput::make('last_name')
                         ->label('Фамилия')
                         ->maxLength(255)
                         ->required(),
                TextInput::make('first_name')
                         ->label('Имя')
                         ->maxLength(255)
                         ->required(),
                TextInput::make('phone')
                         ->label('Телефон')
                         ->tel()
                         ->maxLength(64)
                         ->validationMessages([
                             'regex' => 'Введите номер телефона',
                         ]),
                Textarea::make('notes')
                        ->label('Особые приметы')
                        ->columnSpanFull(),
                self::docsUpload('docs'),
            ]);
    }

    /** Поле загрузки фото документов клиента: одно изображение, общий диск и каталог загрузок.
     * @param string $name Имя поля в форме.
     * @return FileUpload
     */
    public static function docsUpload(string $name): FileUpload
    {
        return FileUpload::make($name)
                         ->label('Документы')
                         ->columnSpanFull()
                         ->image()
                         ->openable()
                         ->downloadable();
    }
}
