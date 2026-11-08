<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Support\Carbon;
use App\Services\LicenseService;
use PHPUnit\Framework\Attributes\DataProvider;

/** Тесты проверки срока лицензии. */
class LicenseServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['license.expires_at' => '2026-11-30']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, array{string, bool, bool}>
     */
    public static function datesProvider(): array
    {
        return [
            'больше месяца'           => ['2026-10-29 23:59:59', false, false],
            'ровно месяц до конца'    => ['2026-10-30 00:00:00', true, false],
            'последний день лицензии' => ['2026-11-29 23:59:59', true, false],
            'дата окончания'          => ['2026-11-30 00:00:00', false, true],
            'после окончания'         => ['2026-12-15 12:00:00', false, true],
        ];
    }

    #[DataProvider('datesProvider')]
    public function test_status_by_date(string $now, bool $expiringSoon, bool $expired): void
    {
        Carbon::setTestNow($now);

        $license = new LicenseService;

        $this->assertSame($expiringSoon, $license->isExpiringSoon());
        $this->assertSame($expired, $license->isExpired());
    }

    public function test_no_restrictions_without_date(): void
    {
        config(['license.expires_at' => null]);
        Carbon::setTestNow('2030-01-01');

        $license = new LicenseService;

        $this->assertFalse($license->isExpiringSoon());
        $this->assertFalse($license->isExpired());
    }
}
