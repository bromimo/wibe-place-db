<?php

namespace App\Filament\Schemas;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Illuminate\Support\HtmlString;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Actions\Contracts\HasActions;

/** Окно «Клиент» поверх окна заселения: данные выбранного в списке гостя и кнопки «Отменить» / «Выбрать». */
class GuestCard
{
    /** Имя действия окна на поле гостя. */
    public const ACTION = 'guestCard';

    /** Действие окна; гость попадает в поле только по «Выбрать», закрытие окна иначе поле не меняет.
     * Оформлено как окно заселения: та же ширина, линии под шапкой и над подвалом, цвета и порядок кнопок.
     * @return Action
     */
    public static function action(): Action
    {
        return Action::make(self::ACTION)
            ->modalHeading('Клиент')
            // Без автофокуса: иначе фокус встаёт на превью документов или на «Выбрать», и кнопка выглядит нажатой.
            ->modalAutofocus(false)
            ->modalSubmitActionLabel('Выбрать')
            ->modalSubmitAction(fn (Action $action): Action => $action->extraAttributes(['class' => 'sch-stay-submit sch-btn-green']))
            ->modalCancelActionLabel('Отменить')
            ->modalCancelAction(fn (Action $action): Action => $action->extraAttributes(['class' => 'sch-btn-neutral']))
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->extraModalWindowAttributes(['class' => 'sch-stay-window sch-guest-card'])
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

    /** Данные гостя только для чтения: строка «Фамилия Имя» с телефоном, под ней документы слева,
     * особые приметы и число заселений справа.
     * @param User|null $user Гость.
     * @return array<int, mixed>
     */
    private static function entries(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return [
            Html::make(StayForm::guestHtml($user->id, null))->key('guestRow'),
            Grid::make(['default' => 1, 'sm' => 2])->schema([
                Html::make(StayForm::docsHtml($user->id))->key('docsRow'),
                Html::make(self::infoHtml($user))->key('infoRow'),
            ]),
        ];
    }

    /** Особые приметы серой подписью с текстом под ней (прочерк, если пусто) и плашка «Заселений: N»:
     * серая без заселений, синяя от одного.
     * @param User $user Гость.
     * @return HtmlString
     */
    private static function infoHtml(User $user): HtmlString
    {
        $notes = filled($user->notes) ? $user->notes : '—';
        $stays = $user->stays()->count();
        $staysClass = $stays > 0 ? 'sch-guest-card-stays sch-guest-card-stays-active' : 'sch-guest-card-stays';

        return new HtmlString(
            '<div class="sch-guest-card-info"><span class="sch-guest-card-label">Особые приметы</span>'
            . '<span class="sch-guest-card-notes">' . e($notes) . '</span>'
            . '<span class="' . $staysClass . '">Заселений: ' . $stays . '</span></div>'
        );
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
