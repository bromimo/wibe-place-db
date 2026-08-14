<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Тесты команды массовой оптимизации ранее загруженных изображений.
 */
final class OptimizeImagesCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Текст подтверждения удаления исходных файлов.
     */
    private const CONFIRMATION = 'Исходные файлы будут удалены после конвертации. Продолжить?';

    /**
     * Конвертирует изображение в webp, обновляет запись и удаляет оригинал.
     * @return void
     */
    public function test_converts_image_and_replaces_original(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('photo.JPG', $this->jpeg());

        $user = $this->user('photo.JPG');

        $this->artisan('images:optimize', ['--disk' => 'local'])
             ->expectsConfirmation(self::CONFIRMATION, 'yes')
             ->assertExitCode(0);

        Storage::disk('local')->assertExists('photo.webp');
        Storage::disk('local')->assertMissing('photo.JPG');

        $this->assertSame('photo.webp', $user->refresh()->docs);
        $this->assertSame(
            'image/webp',
            getimagesizefromstring(Storage::disk('local')->get('photo.webp'))['mime'],
        );
    }

    /**
     * Сохраняет вложенную директорию файла при конвертации.
     * @return void
     */
    public function test_keeps_directory_of_the_original_file(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('docs/2026/photo.jpg', $this->jpeg());

        $user = $this->user('docs/2026/photo.jpg');

        $this->artisan('images:optimize', ['--disk' => 'local'])
             ->expectsConfirmation(self::CONFIRMATION, 'yes')
             ->assertExitCode(0);

        Storage::disk('local')->assertExists('docs/2026/photo.webp');
        $this->assertSame('docs/2026/photo.webp', $user->refresh()->docs);
    }

    /**
     * В пробном прогоне не изменяет ни файлы, ни записи.
     * @return void
     */
    public function test_dry_run_changes_nothing(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('photo.jpg', $this->jpeg());

        $user = $this->user('photo.jpg');

        $this->artisan('images:optimize', ['--disk' => 'local', '--dry-run' => true])
             ->assertExitCode(0);

        Storage::disk('local')->assertExists('photo.jpg');
        Storage::disk('local')->assertMissing('photo.webp');

        $this->assertSame('photo.jpg', $user->refresh()->docs);
    }

    /**
     * Сохраняет исходный файл при указании --keep-original.
     * @return void
     */
    public function test_keeps_original_file_when_requested(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('photo.jpg', $this->jpeg());

        $user = $this->user('photo.jpg');

        $this->artisan('images:optimize', ['--disk' => 'local', '--keep-original' => true])
             ->assertExitCode(0);

        Storage::disk('local')->assertExists('photo.jpg');
        Storage::disk('local')->assertExists('photo.webp');

        $this->assertSame('photo.webp', $user->refresh()->docs);
    }

    /**
     * Пропускает файлы, уже находящиеся в целевом формате, и перекодирует их с --force.
     * @return void
     */
    public function test_skips_target_format_unless_forced(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('photo.webp', $this->webp());

        $user = $this->user('photo.webp');
        $before = Storage::disk('local')->size('photo.webp');

        $this->artisan('images:optimize', ['--disk' => 'local'])
             ->expectsConfirmation(self::CONFIRMATION, 'yes')
             ->assertExitCode(0);

        $this->assertSame($before, Storage::disk('local')->size('photo.webp'));

        $this->artisan('images:optimize', ['--disk' => 'local', '--force' => true, '--quality' => 1])
             ->expectsConfirmation(self::CONFIRMATION, 'yes')
             ->assertExitCode(0);

        $this->assertLessThan($before, Storage::disk('local')->size('photo.webp'));
        $this->assertSame('photo.webp', $user->refresh()->docs);
    }

    /**
     * Не изменяет запись, если файл отсутствует на диске.
     * @return void
     */
    public function test_reports_missing_files_without_touching_the_record(): void
    {
        Storage::fake('local');

        $user = $this->user('missing.jpg');

        $this->artisan('images:optimize', ['--disk' => 'local'])
             ->expectsConfirmation(self::CONFIRMATION, 'yes')
             ->assertExitCode(0);

        Storage::disk('local')->assertMissing('missing.webp');

        $this->assertSame('missing.jpg', $user->refresh()->docs);
    }

    /**
     * Не изменяет updated_at, если не указан --touch.
     * @return void
     */
    public function test_does_not_touch_updated_at_by_default(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('photo.jpg', $this->jpeg());

        $user = $this->user('photo.jpg');
        $user->forceFill(['updated_at' => now()->subYear()])->saveQuietly();
        $updatedAt = $user->refresh()->updated_at;

        $this->artisan('images:optimize', ['--disk' => 'local'])
             ->expectsConfirmation(self::CONFIRMATION, 'yes')
             ->assertExitCode(0);

        $this->assertTrue($updatedAt->equalTo($user->refresh()->updated_at));
    }

    /**
     * Создаёт пользователя с указанным путём к изображению.
     * @param string $docs
     * @return User
     */
    private function user(string $docs): User
    {
        return User::query()->create([
            'last_name'  => 'Тестов',
            'first_name' => 'Тест',
            'phone'      => '+70000000000',
            'docs'       => $docs,
        ]);
    }

    /**
     * Возвращает бинарное содержимое тестового JPEG-изображения.
     * @return string
     */
    private function jpeg(): string
    {
        return $this->render('imagejpeg');
    }

    /**
     * Возвращает бинарное содержимое тестового WEBP-изображения.
     * @return string
     */
    private function webp(): string
    {
        return $this->render('imagewebp');
    }

    /**
     * Рисует изображение с шумом и кодирует его указанной функцией GD.
     * @param callable-string $encoder
     * @return string
     */
    private function render(string $encoder): string
    {
        $image = imagecreatetruecolor(200, 150);

        for ($x = 0; $x < 200; $x++) {
            for ($y = 0; $y < 150; $y++) {
                imagesetpixel($image, $x, $y, imagecolorallocate($image, ($x * 7) % 256, ($y * 11) % 256, ($x + $y) % 256));
            }
        }

        ob_start();
        $encoder($image);
        $binary = (string) ob_get_clean();

        imagedestroy($image);

        return $binary;
    }
}
