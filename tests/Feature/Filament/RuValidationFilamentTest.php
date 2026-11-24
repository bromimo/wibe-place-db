<?php

namespace Tests\Feature\Filament;

use Tests\TestCase;
use App\Models\Admin;
use Livewire\Livewire;
use App\Filament\Resources\Rooms\Pages\CreateRoom;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Тест русских ошибок валидации в формах панели. */
class RuValidationFilamentTest extends TestCase
{
    use RefreshDatabase;

    /** Пустое обязательное поле даёт русскую ошибку без ключа validation. */
    public function test_empty_required_field_shows_russian_error(): void
    {
        $this->app->setLocale('ru');

        $admin = Admin::query()->create([
            'first_name' => 'Тест',
            'last_name' => 'Админ',
            'email' => 'admin@example.com',
            'password' => 'secret',
        ]);
        $this->actingAs($admin, 'admin');

        $component = Livewire::test(CreateRoom::class)
            ->fillForm(['name' => ''])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required']);

        $message = $component->errors()->first('data.name');

        $this->assertSame('Поле «название» обязательно для заполнения.', $message);
        $this->assertStringNotContainsString('validation.', $component->errors()->toJson());
    }
}
