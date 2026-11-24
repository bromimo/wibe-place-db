<?php

namespace App\Filament\Schemas;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Text;
use Filament\Actions\Contracts\HasActions;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ImageEntry;

/** Окно «Клиент» поверх окна заселения: данные выбранного в списке гостя и кнопки «Отменить» / «Выбрать». */
class GuestCard
{
    /** Имя действия окна на поле гостя. */
    public const ACTION = 'guestCard';

    /** Действие окна; гость попадает в поле только по «Выбрать», закрытие окна иначе поле не меняет.
     * @return Action
     */
    public static function action(): Action
    {
        return Action::make(self::ACTION)
            ->modalHeading('Клиент')
            ->modalSubmitActionLabel('Выбрать')
            ->modalCancelActionLabel('Отменить')
            ->schema(fn (array $arguments): array => self::entries(self::findUser($arguments['user'] ?? null)))
            ->action(function (array $arguments, Select $component): void {
                $user = self::findUser($arguments['user'] ?? null);

                if ($user === null) {
                    return;
                }

                $component->state($user->id);
                $component->refreshSelectedOptionLabel();
            });
    }

    /** Вызывается после выбора гостя в списке: возвращает полю прежнее значение и открывает окно «Клиент».
     * Не срабатывает при очистке поля и при создании гостя через форму «Новый клиент» (это вложенное окно).
     * @param Select $component Поле гостя.
     * @param mixed $state Выбранный id гостя.
     * @param mixed $old Значение поля до выбора.
     * @return void
     */
    public static function open(Select $component, mixed $state, mixed $old): void
    {
        /** @var HasActions $livewire */
        $livewire = $component->getLivewire();

        if (blank($state) || count($livewire->mountedActions) !== 1) {
            return;
        }

        $component->state($old);
        $component->refreshSelectedOptionLabel();

        $user = self::findUser($state);

        if ($user === null) {
            return;
        }

        $livewire->mountAction(self::ACTION, ['user' => $user->id], ['schemaComponent' => $component->getKey()]);
    }

    /** Данные гостя только для чтения.
     * @param User|null $user Гость.
     * @return array<int, mixed>
     */
    private static function entries(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return [
            TextEntry::make('last_name')
                ->label('Фамилия')
                ->state($user->last_name),
            TextEntry::make('first_name')
                ->label('Имя')
                ->state($user->first_name),
            TextEntry::make('phone')
                ->label('Телефон')
                ->state($user->phone)
                ->placeholder('—'),
            TextEntry::make('notes')
                ->label('Особые приметы')
                ->state($user->notes)
                ->placeholder('—'),
            ImageEntry::make('docs')
                ->label('Документы')
                ->state($user->docs)
                ->placeholder('нет')
                ->url(fn (ImageEntry $component, ?string $state): ?string => filled($state) ? $component->getImageUrl($state) : null)
                ->openUrlInNewTab(),
            Text::make('Заселений: ' . $user->stays()->count()),
        ];
    }

    /** Гость по id из аргументов окна или поля; некорректный или несуществующий id даёт null.
     * @param mixed $id Id гостя.
     * @return User|null
     */
    private static function findUser(mixed $id): ?User
    {
        if (! is_int($id) && ! is_string($id)) {
            return null;
        }

        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : User::query()->find($id);
    }
}
