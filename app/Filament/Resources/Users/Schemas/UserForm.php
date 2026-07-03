<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\FileUpload;

class UserForm
{
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
                         ])
                         ->required(),
                Textarea::make('notes')
                        ->label('Особые приметы')
                        ->columnSpanFull(),
                FileUpload::make('docs')
                          ->label('Документы')
                          ->columnSpanFull()
                          ->image()
                          ->openable()
                          ->downloadable(),
            ]);
    }
}
