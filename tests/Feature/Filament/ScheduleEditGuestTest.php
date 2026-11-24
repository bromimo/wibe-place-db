<?php

namespace Tests\Feature\Filament;

use App\Models\Bed;
use Tests\TestCase;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use App\Models\Admin;
use Livewire\Livewire;
use App\Enums\StayStatus;
use App\Filament\Pages\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Filament\Actions\Testing\TestAction;
use Livewire\Features\SupportTesting\Testable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Тесты окна правки клиента из окна заселения (карандаш в строке гостя). */
class ScheduleEditGuestTest extends TestCase
{
    use RefreshDatabase;

    /** Фиксирует текущее время на 24 ноября 2026, подменяет диск загрузок и входит администратором. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-24 12:00');

        Storage::fake(config('filament.default_filesystem_disk'));

        $admin = Admin::query()->create([
            'first_name' => 'Тест',
            'last_name' => 'Админ',
            'email' => 'admin@example.com',
            'password' => 'secret',
        ]);

        $this->actingAs($admin, 'admin');
    }

    /** Создаёт заселение гостя «Шевченко Тарас» с телефоном, приметами и путём документов.
     * @param string|null $docs Путь документов гостя.
     * @return Stay
     */
    private function makeStay(?string $docs = null): Stay
    {
        $room = Room::factory()->create(['number' => 1, 'name' => '1 кімната', 'price' => 460]);
        $bed = Bed::factory()->create(['room_id' => $room->id, 'number' => 1]);
        $guest = User::factory()->create([
            'last_name' => 'Шевченко',
            'first_name' => 'Тарас',
            'phone' => '+380501112233',
            'notes' => 'Шрам',
            'docs' => $docs,
        ]);

        return Stay::factory()->create([
            'bed_id' => $bed->id,
            'user_id' => $guest->id,
            'date_from' => '2026-11-24',
            'date_to' => '2026-11-26',
            'status' => StayStatus::Living,
            'price_per_night' => 460,
        ]);
    }

    /** Открывает окно правки заселения.
     * @param Stay $stay Заселение.
     * @return Testable
     */
    private function editStay(Stay $stay): Testable
    {
        return Livewire::test(Schedule::class)->mountAction('editStay', ['stay' => $stay->id]);
    }

    /** Карандаш «Редактировать клиента» в строке гостя.
     * @return TestAction
     */
    private function editGuest(): TestAction
    {
        return TestAction::make('editGuest')->schemaComponent('guestRow');
    }

    /** Диск загрузок документов.
     * @return Filesystem
     */
    private function disk(): Filesystem
    {
        return Storage::disk(config('filament.default_filesystem_disk'));
    }

    /** Строка гостя окна правки содержит карандаш «Редактировать клиента». */
    public function test_edit_window_has_edit_guest_icon(): void
    {
        $stay = $this->makeStay();

        $this->editStay($stay)
            ->assertActionExists($this->editGuest())
            ->assertMountedActionModalSeeHtml(['Редактировать клиента', "mountAction('editGuest'"]);
    }

    /** Окно клиента: заголовок с именем, все поля карточки, заполненные данными гостя, кнопки «Сохранить» / «Отмена». */
    public function test_edit_guest_form_is_filled(): void
    {
        $this->disk()->put('passport.png', 'png');
        $stay = $this->makeStay('passport.png');

        $this->editStay($stay)
            ->mountAction($this->editGuest())
            ->assertMountedActionModalSee(['Клиент: Шевченко Тарас', 'Особые приметы', 'Документы', 'Сохранить', 'Отмена'])
            ->assertActionDataSet([
                'last_name' => 'Шевченко',
                'first_name' => 'Тарас',
                'phone' => '+380501112233',
                'notes' => 'Шрам',
            ])
            ->assertFormFieldExists('docs');

        $this->assertSame(['passport.png'], array_values((array) Livewire::test(Schedule::class)
            ->mountAction('editStay', ['stay' => $stay->id])
            ->mountAction($this->editGuest())
            ->get('mountedActions.1.data.docs')));
    }

    /** «Сохранить» обновляет клиента и возвращает в окно заселения со свежими данными; несохранённая правка остаётся. */
    public function test_save_updates_guest_and_keeps_stay_window(): void
    {
        $stay = $this->makeStay();

        $component = $this->editStay($stay)
            ->setActionData(['notes' => 'Поздний выезд'])
            ->mountAction($this->editGuest())
            ->setActionData(['last_name' => 'Коваленко', 'phone' => '+380671234567', 'notes' => 'Очки'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Клиент сохранён');

        $guest = $stay->user->refresh();

        $this->assertSame('Коваленко', $guest->last_name);
        $this->assertSame('+380671234567', $guest->phone);
        $this->assertSame('Очки', $guest->notes);

        $this->assertCount(1, $component->instance()->mountedActions);

        $component
            ->assertActionMounted('editStay')
            ->assertActionDataSet(['notes' => 'Поздний выезд', 'user_id' => $guest->id])
            ->assertSeeHtml(['sch-stay-guest-name">Коваленко Тарас<', 'sch-stay-guest-phone">+380671234567<'])
            ->assertSeeHtml('<span class="sch-guest">Коваленко Тарас</span>')
            ->assertDispatched('filament-forms::select.refreshSelectedOptionLabel');

        $this->assertNull($stay->refresh()->notes);
    }

    /** Замена фото документов: новый файл на диске, старый удалён. */
    public function test_replace_docs_stores_new_and_removes_old(): void
    {
        $this->disk()->put('old.png', 'png');
        $stay = $this->makeStay('old.png');

        $this->editStay($stay)
            ->mountAction($this->editGuest())
            ->setActionData(['docs' => null])
            ->setActionData(['docs' => [UploadedFile::fake()->image('new.jpg')]])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $docs = $stay->user->refresh()->docs;

        $this->assertNotSame('old.png', $docs);
        $this->disk()->assertExists($docs);
        $this->disk()->assertMissing('old.png');
    }

    /** Удаление фото документов: окно заселения показывает «Документы: нет» и поле загрузки. */
    public function test_remove_docs_shows_upload_control(): void
    {
        $this->disk()->put('old.png', 'png');
        $stay = $this->makeStay('old.png');

        $this->editStay($stay)
            ->mountAction($this->editGuest())
            ->setActionData(['docs' => null])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertActionMounted('editStay')
            ->assertSee(['Документы: нет', 'Сохранить документ']);

        $this->assertNull($stay->user->refresh()->docs);
        $this->disk()->assertMissing('old.png');
    }

    /** «Отмена»: клиент не меняется, окно заселения остаётся. */
    public function test_cancel_changes_nothing(): void
    {
        $stay = $this->makeStay();

        $component = $this->editStay($stay)
            ->setActionData(['notes' => 'Поздний выезд'])
            ->mountAction($this->editGuest())
            ->setActionData(['last_name' => 'Коваленко'])
            ->unmountAction();

        $this->assertCount(1, $component->instance()->mountedActions);
        $this->assertSame('Шевченко', $stay->user->refresh()->last_name);

        $component->assertActionDataSet(['notes' => 'Поздний выезд']);
    }

    /** Пустая фамилия: ошибка, окно клиента открыто, клиент не меняется. */
    public function test_validation_error_keeps_modal(): void
    {
        $stay = $this->makeStay();

        $component = $this->editStay($stay)
            ->mountAction($this->editGuest())
            ->setActionData(['last_name' => '', 'phone' => '+380671234567'])
            ->callMountedAction()
            ->assertHasActionErrors(['last_name' => 'required']);

        $this->assertCount(2, $component->instance()->mountedActions);

        $guest = $stay->user->refresh();

        $this->assertSame('Шевченко', $guest->last_name);
        $this->assertSame('+380501112233', $guest->phone);
    }

    /** В окне «Новое заселение» карандаша нет. */
    public function test_create_window_has_no_edit_icon(): void
    {
        $room = Room::factory()->create(['number' => 1, 'name' => '1 кімната', 'price' => 460]);
        $bed = Bed::factory()->create(['room_id' => $room->id, 'number' => 1]);

        Livewire::test(Schedule::class)
            ->mountAction('createStay', ['bed_id' => $bed->id, 'date' => '2026-11-24'])
            ->assertMountedActionModalDontSeeHtml("mountAction('editGuest'");
    }
}
