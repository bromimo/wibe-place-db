<?php

namespace App\Filament\Resources\Rooms;

use BackedEnum;
use App\Models\Room;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use App\Filament\Resources\Rooms\Pages\EditRoom;
use App\Filament\Resources\Rooms\Pages\ListRooms;
use App\Filament\Resources\Rooms\Pages\CreateRoom;
use App\Filament\Resources\Rooms\Schemas\RoomForm;
use App\Filament\Resources\Rooms\Tables\RoomsTable;

/** Справочник комнат и коек. */
class RoomResource extends Resource
{
    protected static ?string $model = Room::class;
    protected static ?string $modelLabel = 'Комната';
    protected static ?string $pluralModelLabel = 'Комнаты';
    protected static ?int $navigationSort = 2;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    /** Форма комнаты с койками.
     * @param Schema $schema Схема формы.
     * @return Schema
     */
    public static function form(Schema $schema): Schema
    {
        return RoomForm::configure($schema);
    }

    /** Таблица комнат.
     * @param Table $table Таблица.
     * @return Table
     */
    public static function table(Table $table): Table
    {
        return RoomsTable::configure($table);
    }

    /** Страницы ресурса.
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListRooms::route('/'),
            'create' => CreateRoom::route('/create'),
            'edit' => EditRoom::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::count();

        return empty($count) ? '' : $count;
    }
}
