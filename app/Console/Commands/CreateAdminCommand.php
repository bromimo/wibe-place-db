<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Dto\Admin\CreateAdminDto;
use App\Actions\Admin\CreateAdminAction;
use Illuminate\Contracts\Console\PromptsForMissingInput;

final class CreateAdminCommand extends Command implements PromptsForMissingInput
{
    protected $signature = 'admin:create-admin {email} {last_name} {first_name} {password}';

    protected $description = 'Создание нового администратора системы';

    public function handle(CreateAdminAction $createAdminAction): void
    {
        $createAdminAction->run(new CreateAdminDto(
            last_name: $this->argument('last_name'),
            first_name: $this->argument('first_name'),
            email: $this->argument('email'),
            password: $this->argument('password'),
        ));

        $this->info('Администратор создан успешно:');
        $this->info($this->argument('email'));
        $this->info($this->argument('password'));
    }

    /**
     * Подсказки для незаполненных аргументов команды.
     * @return array<string, string>
     */
    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'email'      => 'Введите email-адрес для нового администратора',
            'last_name'  => 'Введите фамилию для нового администратора',
            'first_name' => 'Введите имя для нового администратора',
            'password'   => 'Введите пароль для нового администратора',
        ];
    }
}
