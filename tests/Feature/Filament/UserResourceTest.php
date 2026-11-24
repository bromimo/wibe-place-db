<?php

namespace Tests\Feature\Filament;

use Tests\TestCase;
use App\Models\User;
use App\Models\Admin;
use Livewire\Livewire;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\CreateUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Тесты страниц создания и правки клиента в разделе «Клиенты». */
class UserResourceTest extends TestCase
{
    use RefreshDatabase;

    /** Подменяет диск загрузок и входит в панель администратором. */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filament.default_filesystem_disk'));

        $admin = Admin::query()->create([
            'first_name' => 'Тест',
            'last_name' => 'Админ',
            'email' => 'admin@example.com',
            'password' => 'secret',
        ]);

        $this->actingAs($admin, 'admin');
    }

    /** Страница создания показывает все поля и сохраняет клиента с приметами и фото документов. */
    public function test_create_page_saves_client_with_docs(): void
    {
        Livewire::test(CreateUser::class)
            ->assertOk()
            ->assertSee(['Фамилия', 'Имя', 'Телефон', 'Особые приметы', 'Документы'])
            ->fillForm([
                'last_name' => 'Петренко',
                'first_name' => 'Олег',
                'phone' => '+380501112233',
                'notes' => 'Шрам на левой руке',
                'docs' => UploadedFile::fake()->image('passport.jpg'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::query()->sole();

        $this->assertSame('Шрам на левой руке', $user->notes);
        Storage::disk(config('filament.default_filesystem_disk'))->assertExists($user->docs);
    }

    /** Страница правки открывается с данными клиента и сохраняет изменения. */
    public function test_edit_page_saves_client(): void
    {
        $user = User::factory()->create(['last_name' => 'Петренко', 'notes' => null]);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->assertOk()
            ->assertSchemaStateSet(['last_name' => 'Петренко'])
            ->fillForm(['notes' => 'Шрам на левой руке'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Шрам на левой руке', $user->refresh()->notes);
    }
}
