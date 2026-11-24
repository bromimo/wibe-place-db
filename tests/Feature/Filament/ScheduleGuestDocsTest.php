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
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Тесты полной формы «Новый клиент» и загрузки документов гостя из окна заселения. */
class ScheduleGuestDocsTest extends TestCase
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

    /** Создаёт койку в комнате с ценой 460.
     * @return Bed
     */
    private function makeBed(): Bed
    {
        $room = Room::factory()->create(['number' => 1, 'name' => '1 кімната', 'price' => 460]);

        return Bed::factory()->create(['room_id' => $room->id, 'number' => 1]);
    }

    /** Создаёт заселение гостя с заданным путём документов.
     * @param string|null $docs Путь документов гостя.
     * @return Stay
     */
    private function makeStay(?string $docs): Stay
    {
        $guest = User::factory()->create(['docs' => $docs]);

        return Stay::factory()->create([
            'bed_id' => $this->makeBed()->id,
            'user_id' => $guest->id,
            'date_from' => '2026-11-24',
            'date_to' => '2026-11-26',
            'status' => StayStatus::Living,
            'price_per_night' => 460,
        ]);
    }

    /** Открывает окно «Новое заселение» и в нём форму «Новый клиент».
     * @return Testable
     */
    private function newClientForm(): Testable
    {
        return Livewire::test(Schedule::class)
            ->mountAction('createStay', ['bed_id' => $this->makeBed()->id, 'date' => '2026-11-24'])
            ->mountAction(TestAction::make('createOption')->schemaComponent('user_id'));
    }

    /** Кнопка «Сохранить документ» под полем загрузки окна правки.
     * @return TestAction
     */
    private function saveDocs(): TestAction
    {
        return TestAction::make('saveGuestDocs')->schemaComponent('guest_docs');
    }

    /** Диск загрузок документов.
     * @return \Illuminate\Contracts\Filesystem\Filesystem
     */
    private function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk(config('filament.default_filesystem_disk'));
    }

    /** Форма «Новый клиент» в окне заселения содержит все поля карточки клиента и кнопки «Создать» / «Отмена». */
    public function test_new_client_form_has_all_client_fields(): void
    {
        $this->newClientForm()
            ->assertMountedActionModalSee(['Новый клиент', 'Фамилия', 'Имя', 'Телефон', 'Особые приметы', 'Документы', 'Создать', 'Отмена'])
            ->assertFormFieldExists('notes')
            ->assertFormFieldExists('docs');
    }

    /** Новый клиент со всеми полями и фото документов сохраняется, становится гостем, окно «Клиент» не открывается. */
    public function test_new_client_with_notes_and_docs_becomes_guest(): void
    {
        $component = $this->newClientForm()
            ->setActionData([
                'last_name' => 'Петренко',
                'first_name' => 'Олег',
                'phone' => '+380501112233',
                'notes' => 'Шрам на левой руке',
                'docs' => UploadedFile::fake()->image('passport.jpg'),
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $guest = User::query()->where('last_name', 'Петренко')->sole();

        $this->assertSame('Шрам на левой руке', $guest->notes);
        $this->assertNotNull($guest->docs);
        $this->disk()->assertExists($guest->docs);

        $component->assertOk();
        $this->assertCount(1, $component->instance()->mountedActions);

        $component
            ->assertActionDataSet(['user_id' => $guest->id])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame($guest->id, Stay::query()->sole()->user_id);
    }

    /** «Отмена» в форме «Новый клиент»: клиент не создаётся, гость не выбран. */
    public function test_new_client_cancel_creates_nothing(): void
    {
        $component = $this->newClientForm()
            ->setActionData([
                'last_name' => 'Петренко',
                'first_name' => 'Олег',
                'docs' => UploadedFile::fake()->image('passport.jpg'),
            ])
            ->unmountAction();

        $this->assertCount(1, $component->instance()->mountedActions);
        $this->assertSame(0, User::query()->count());

        $component->assertActionDataSet(['user_id' => null]);
    }

    /** Гость без документов: под «Документы: нет» поле загрузки и кнопка «Сохранить документ». */
    public function test_edit_shows_upload_for_guest_without_docs(): void
    {
        $stay = $this->makeStay(null);

        Livewire::test(Schedule::class)
            ->mountAction('editStay', ['stay' => $stay->id])
            ->assertMountedActionModalSee(['Документы: нет', 'Сохранить документ'])
            ->assertFormFieldExists('guest_docs');
    }

    /** «Сохранить документ» сразу записывает фото гостю; окно и несохранённая правка остаются, блок показывает превью. */
    public function test_save_docs_stores_file_and_keeps_window(): void
    {
        $stay = $this->makeStay(null);

        $component = Livewire::test(Schedule::class)
            ->mountAction('editStay', ['stay' => $stay->id])
            ->setActionData(['notes' => 'Поздний выезд', 'guest_docs' => UploadedFile::fake()->image('passport.jpg')])
            ->callAction($this->saveDocs())
            ->assertNotified('Документ сохранён')
            ->assertActionMounted('editStay')
            ->assertActionDataSet(['notes' => 'Поздний выезд']);

        $guest = $stay->user->refresh();

        $this->assertNotNull($guest->docs);
        $this->disk()->assertExists($guest->docs);
        $this->assertNull($stay->refresh()->notes);

        $component
            ->assertMountedActionModalDontSee(['Документы: нет', 'Сохранить документ'])
            ->assertMountedActionModalSeeHtml(['sch-stay-docs-image', basename($guest->docs), 'target="_blank"']);

        $component->callMountedAction()->assertHasNoActionErrors()->assertActionNotMounted('editStay');

        $this->assertSame('Поздний выезд', $stay->refresh()->notes);
        $this->assertSame($guest->docs, $guest->refresh()->docs);
    }

    /** «Сохранить документ» без выбранного файла: уведомление «Выберите файл», ничего не меняется. */
    public function test_save_docs_without_file_notifies(): void
    {
        $stay = $this->makeStay(null);

        Livewire::test(Schedule::class)
            ->mountAction('editStay', ['stay' => $stay->id])
            ->callAction($this->saveDocs())
            ->assertNotified('Выберите файл')
            ->assertActionMounted('editStay')
            ->assertMountedActionModalSee('Документы: нет');

        $this->assertNull($stay->user->refresh()->docs);
        $this->assertSame([], $this->disk()->allFiles());
    }

    /** Гость с существующим фото документов: только превью, поля загрузки нет. */
    public function test_edit_hides_upload_for_guest_with_docs(): void
    {
        $this->disk()->put('passport.png', 'png');
        $stay = $this->makeStay('passport.png');

        Livewire::test(Schedule::class)
            ->mountAction('editStay', ['stay' => $stay->id])
            ->assertMountedActionModalDontSee(['Документы: нет', 'Сохранить документ'])
            ->assertFormFieldDoesNotExist('guest_docs');
    }

    /** Путь документов есть, а файла нет: поле загрузки показывается, сохранение заменяет путь. */
    public function test_save_docs_replaces_missing_file_path(): void
    {
        $stay = $this->makeStay('missing.png');

        Livewire::test(Schedule::class)
            ->mountAction('editStay', ['stay' => $stay->id])
            ->assertMountedActionModalSee(['Документы: нет', 'Сохранить документ'])
            ->setActionData(['guest_docs' => UploadedFile::fake()->image('passport.jpg')])
            ->callAction($this->saveDocs())
            ->assertNotified('Документ сохранён')
            ->assertActionMounted('editStay');

        $docs = $stay->user->refresh()->docs;

        $this->assertNotSame('missing.png', $docs);
        $this->disk()->assertExists($docs);
    }

    /** Окно «Новое заселение» не показывает поля загрузки документов. */
    public function test_create_window_has_no_upload(): void
    {
        Livewire::test(Schedule::class)
            ->mountAction('createStay', ['bed_id' => $this->makeBed()->id, 'date' => '2026-11-24'])
            ->assertMountedActionModalDontSee('Сохранить документ')
            ->assertFormFieldDoesNotExist('guest_docs');
    }
}
