<?php

namespace App\Console\Commands;

use Throwable;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Contracts\Filesystem\Filesystem;
use DaniHidayatX\ImageOptimizer\ImageProcessor;

/**
 * Конвертирует и сжимает ранее загруженные изображения в целевой формат.
 */
final class OptimizeImagesCommand extends Command
{
    /**
     * Модели и их атрибуты, хранящие пути к загруженным изображениям.
     * @var array<class-string<Model>, list<string>>
     */
    private const TARGETS = [
        User::class => ['docs'],
    ];

    /**
     * Форматы, которые поддерживает ImageProcessor пакета.
     * @var list<string>
     */
    private const FORMATS = ['webp', 'jpg', 'jpeg', 'png', 'gif', 'bmp'];

    protected $signature = 'images:optimize
                            {--format=webp : Целевой формат: webp, jpg, png, gif, bmp}
                            {--quality=85 : Качество сжатия 0-100 (учитывается для webp и jpg)}
                            {--resize= : Уменьшить изображение на указанный процент}
                            {--max-width= : Максимальная ширина в пикселях}
                            {--max-height= : Максимальная высота в пикселях}
                            {--disk= : Диск с изображениями (по умолчанию — диск Filament)}
                            {--chunk=100 : Количество записей, выбираемых из БД за один проход}
                            {--limit= : Обработать не более указанного количества изображений}
                            {--force : Перекодировать изображения, уже находящиеся в целевом формате}
                            {--keep-original : Не удалять исходные файлы после конвертации}
                            {--touch : Обновлять updated_at у изменённых записей}
                            {--dry-run : Прогон без записи: конвертация выполняется только в память}';

    protected $description = 'Конвертирует ранее загруженные изображения в целевой формат (по умолчанию webp)';

    /**
     * Диск, на котором лежат изображения.
     */
    private Filesystem $disk;

    /**
     * Расширение файлов целевого формата.
     */
    private string $extension = 'webp';

    /**
     * Настройки обработки для ImageProcessor.
     * @var array<string, int|string>
     */
    private array $settings = [];

    /**
     * Счётчики обработки.
     * @var array<string, int>
     */
    private array $stats = [
        'converted'    => 0,
        'skipped'      => 0,
        'missing'      => 0,
        'failed'       => 0,
        'bytes_before' => 0,
        'bytes_after'  => 0,
    ];

    /**
     * Сообщения о пропущенных и неудачно обработанных файлах.
     * @var list<string>
     */
    private array $warnings = [];

    /**
     * Выполняет команду.
     * @return int
     */
    public function handle(): int
    {
        $format = strtolower((string) $this->option('format'));

        if (! in_array($format, self::FORMATS, true)) {
            $this->error("Неподдерживаемый формат «{$format}». Доступны: " . implode(', ', self::FORMATS));

            return self::FAILURE;
        }

        $diskName = (string) ($this->option('disk')
            ?: config('filament.default_filesystem_disk')
            ?: config('filesystems.default'));

        $this->disk      = Storage::disk($diskName);
        $this->extension = $format === 'jpeg' ? 'jpg' : $format;
        $this->settings  = array_filter([
            'format'     => $format,
            'quality'    => $this->intOption('quality'),
            'resize'     => $this->intOption('resize'),
            'max_width'  => $this->intOption('max-width'),
            'max_height' => $this->intOption('max-height'),
        ], static fn (mixed $value): bool => $value !== null);

        $this->line("Диск: {$diskName}");
        $this->line('Настройки: ' . json_encode($this->settings, JSON_UNESCAPED_UNICODE));

        if ($this->option('dry-run')) {
            $this->warn('Пробный прогон: файлы и записи в БД изменены не будут.');
        } elseif (! $this->option('keep-original')
            && ! $this->confirm('Исходные файлы будут удалены после конвертации. Продолжить?', true)) {
            return self::SUCCESS;
        }

        foreach (self::TARGETS as $model => $attributes) {
            foreach ($attributes as $attribute) {
                $this->processAttribute($model, $attribute);
            }
        }

        $this->renderSummary();

        return $this->stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Обрабатывает все изображения одного атрибута модели.
     * @param class-string<Model> $model
     * @param string $attribute
     * @return void
     */
    private function processAttribute(string $model, string $attribute): void
    {
        $query = $model::query()
                       ->whereNotNull($attribute)
                       ->where($attribute, '!=', '');

        $total = (int) $query->clone()->count();

        $this->newLine();
        $this->line(class_basename($model) . "::{$attribute} — записей с изображениями: {$total}");

        if ($total === 0) {
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById((int) $this->option('chunk'), function ($records) use ($attribute, $bar): bool {
            foreach ($records as $record) {
                $this->processRecord($record, $attribute);
                $bar->advance();

                if ($this->limitReached()) {
                    return false;
                }
            }

            return true;
        });

        $bar->finish();
        $this->newLine();
    }

    /**
     * Обрабатывает изображения одной записи и обновляет её при необходимости.
     * @param Model $record
     * @param string $attribute
     * @return void
     */
    private function processRecord(Model $record, string $attribute): void
    {
        $value = $record->getAttribute($attribute);
        $paths = $this->extractPaths($value);

        $newPaths = [];
        $obsolete = [];

        foreach ($paths as $path) {
            $newPath = $this->convertFile($path);

            if ($newPath === null) {
                $newPaths[] = $path;

                continue;
            }

            $newPaths[] = $newPath;

            if ($newPath !== $path) {
                $obsolete[] = $path;
            }
        }

        if ($newPaths === $paths || $this->option('dry-run')) {
            return;
        }

        $record->setAttribute($attribute, $this->packPaths($value, $newPaths));
        $record->timestamps = (bool) $this->option('touch');
        $record->saveQuietly();

        if ($this->option('keep-original')) {
            return;
        }

        foreach ($obsolete as $path) {
            $this->disk->delete($path);
        }
    }

    /**
     * Конвертирует один файл и возвращает его новый путь либо null, если файл не был обработан.
     * @param string $path
     * @return string|null
     */
    private function convertFile(string $path): ?string
    {
        if ($this->isTargetFormat($path) && ! $this->option('force')) {
            $this->stats['skipped']++;

            return null;
        }

        if (! $this->disk->exists($path)) {
            $this->stats['missing']++;
            $this->warnings[] = "Файл отсутствует на диске: {$path}";

            return null;
        }

        try {
            $original  = (string) $this->disk->get($path);
            $optimized = ImageProcessor::process($original, $this->settings);
        } catch (Throwable $exception) {
            $this->stats['failed']++;
            $this->warnings[] = "Ошибка обработки {$path}: {$exception->getMessage()}";

            return null;
        }

        $this->stats['converted']++;
        $this->stats['bytes_before'] += strlen($original);
        $this->stats['bytes_after'] += strlen($optimized);

        if ($this->option('dry-run')) {
            return null;
        }

        $newPath = $this->targetPath($path);
        $this->disk->put($newPath, $optimized);

        return $newPath;
    }

    /**
     * Возвращает путь файла с расширением целевого формата.
     * @param string $path
     * @return string
     */
    private function targetPath(string $path): string
    {
        $directory = pathinfo($path, PATHINFO_DIRNAME);
        $name      = pathinfo($path, PATHINFO_FILENAME);

        return ($directory === '.' || $directory === '' ? '' : $directory . '/') . $name . '.' . $this->extension;
    }

    /**
     * Проверяет, находится ли файл уже в целевом формате.
     * @param string $path
     * @return bool
     */
    private function isTargetFormat(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        return $extension === $this->extension;
    }

    /**
     * Извлекает список путей из значения атрибута (строка или JSON-массив).
     * @param mixed $value
     * @return list<string>
     */
    private function extractPaths(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, 'is_string'));
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded)
            ? array_values(array_filter($decoded, 'is_string'))
            : [$value];
    }

    /**
     * Приводит новые пути к тому же виду, в котором значение хранилось изначально.
     * @param mixed $original
     * @param list<string> $paths
     * @return mixed
     */
    private function packPaths(mixed $original, array $paths): mixed
    {
        if (is_array($original)) {
            return $paths;
        }

        if (is_string($original) && is_array(json_decode($original, true))) {
            return json_encode($paths, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $paths[0] ?? null;
    }

    /**
     * Проверяет, достигнут ли лимит обработанных изображений.
     * @return bool
     */
    private function limitReached(): bool
    {
        $limit = $this->intOption('limit');

        if ($limit === null) {
            return false;
        }

        return $this->stats['converted'] >= $limit;
    }

    /**
     * Возвращает целочисленное значение опции или null, если она не задана.
     * @param string $name
     * @return int|null
     */
    private function intOption(string $name): ?int
    {
        $value = $this->option($name);

        return ($value === null || $value === '') ? null : (int) $value;
    }

    /**
     * Выводит итоговую статистику обработки.
     * @return void
     */
    private function renderSummary(): void
    {
        $before = $this->stats['bytes_before'];
        $after  = $this->stats['bytes_after'];
        $saved  = $before - $after;

        $this->newLine();
        $this->table(['Показатель', 'Значение'], [
            ['Сконвертировано', $this->stats['converted']],
            ['Пропущено (уже в целевом формате)', $this->stats['skipped']],
            ['Файлов не найдено на диске', $this->stats['missing']],
            ['Ошибок обработки', $this->stats['failed']],
            ['Размер до', $this->humanBytes($before)],
            ['Размер после', $this->humanBytes($after)],
            ['Экономия', $this->humanBytes($saved) . ($before > 0 ? ' (' . round($saved / $before * 100, 1) . '%)' : '')],
        ]);

        if ($this->warnings === []) {
            return;
        }

        $this->newLine();
        $this->warn('Проблемные файлы (' . count($this->warnings) . '):');

        foreach (array_slice($this->warnings, 0, 20) as $warning) {
            $this->line('  ' . $warning);
        }

        if (count($this->warnings) > 20) {
            $this->line('  … и ещё ' . (count($this->warnings) - 20));
        }
    }

    /**
     * Форматирует количество байт в человекочитаемый вид.
     * @param int $bytes
     * @return string
     */
    private function humanBytes(int $bytes): string
    {
        $units = ['Б', 'КБ', 'МБ', 'ГБ'];
        $index = 0;
        $value = (float) $bytes;

        while (abs($value) >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return round($value, 2) . ' ' . $units[$index];
    }
}
