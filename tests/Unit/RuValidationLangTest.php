<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

/** Тесты русских сообщений валидации. */
class RuValidationLangTest extends TestCase
{
    /** Путь к английскому файлу фреймворка.
     * @return string
     */
    private function enPath(): string
    {
        return base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
    }

    /** Русский файл повторяет структуру английского, без пустых значений и потерянных плейсхолдеров. */
    public function test_ru_file_matches_english_structure(): void
    {
        $en = Arr::dot(require $this->enPath());
        $ru = Arr::dot(require lang_path('ru/validation.php'));

        $this->assertEqualsCanonicalizing(array_keys($en), array_keys($ru));

        foreach ($en as $key => $message) {
            if (! is_string($message)) {
                continue;
            }

            $this->assertNotSame('', trim((string) ($ru[$key] ?? '')), "Пустое значение: {$key}");

            preg_match_all('/:[a-z_]+/', (string) $message, $found);
            foreach ($found[0] as $placeholder) {
                $this->assertStringContainsString($placeholder, (string) $ru[$key], "Нет {$placeholder} в {$key}");
            }
        }
    }

    /** Обязательное поле даёт русское сообщение. */
    public function test_required_message(): void
    {
        $this->app->setLocale('ru');

        $validator = Validator::make(['x' => ''], ['x' => 'required'], [], ['x' => 'Название']);

        $this->assertSame('Поле «Название» обязательно для заполнения.', $validator->errors()->first('x'));
    }

    /** Числовой минимум даёт русское сообщение. */
    public function test_numeric_min_message(): void
    {
        $this->app->setLocale('ru');

        $validator = Validator::make(['x' => 1], ['x' => 'numeric|min:5'], [], ['x' => 'Цена']);

        $this->assertSame('Значение поля «Цена» должно быть не меньше 5.', $validator->errors()->first('x'));
    }

    /** Правило after даёт русское сообщение. */
    public function test_after_message(): void
    {
        $this->app->setLocale('ru');

        $validator = Validator::make(['x' => '2026-01-01'], ['x' => 'date|after:2026-02-01'], [], ['x' => 'Выезд']);

        $this->assertSame('Поле «Выезд» должно быть датой позже 2026-02-01.', $validator->errors()->first('x'));
    }
}
