<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Admin;
use Livewire\Livewire;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use App\Filament\Pages\Auth\Login;
use Filament\Notifications\Notification;
use App\Livewire\LicenseExpirationNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Тесты уведомлений и блокировки входа по лицензии. */
class LicenseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'license.expires_at'            => '2026-11-30',
            'license.notification_interval' => 60,
            'license.notification_text'     => 'Лицензия скоро истечёт',
            'license.expired_text'          => 'Лицензия истекла',
        ]);

        Filament::setCurrentPanel('admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_notification_is_sent_when_less_than_month_left(): void
    {
        Carbon::setTestNow('2026-11-08 10:00:00');

        Livewire::test(LicenseExpirationNotifier::class)
            ->call('check', true)
            ->assertNotified(
                Notification::make(LicenseExpirationNotifier::NOTIFICATION_ID)
                    ->danger()
                    ->color('danger')
                    ->title('Лицензия скоро истечёт')
                    ->persistent()
            );
    }

    public function test_notification_is_not_sent_when_more_than_month_left(): void
    {
        Carbon::setTestNow('2026-10-01 10:00:00');

        Livewire::test(LicenseExpirationNotifier::class)
            ->call('check', true)
            ->assertNotNotified();
    }

    public function test_notification_is_throttled_by_interval(): void
    {
        Carbon::setTestNow('2026-11-08 10:00:00');
        session()->put(LicenseExpirationNotifier::NOTIFIED_AT_KEY, now()->subSeconds(30)->getTimestamp());

        Livewire::test(LicenseExpirationNotifier::class)
            ->call('check')
            ->assertNotNotified();

        Carbon::setTestNow('2026-11-08 10:00:30');

        Livewire::test(LicenseExpirationNotifier::class)
            ->call('check')
            ->assertNotified('Лицензия скоро истечёт');
    }

    public function test_open_notification_is_not_stacked_by_polling(): void
    {
        Carbon::setTestNow('2026-11-08 10:00:00');

        $component = Livewire::test(LicenseExpirationNotifier::class)->call('check');
        Notification::assertNotified('Лицензия скоро истечёт');

        Carbon::setTestNow('2026-11-08 12:00:00');

        $component->call('check')->assertNotNotified();
    }

    public function test_open_notification_is_restored_after_page_reload(): void
    {
        Carbon::setTestNow('2026-11-08 10:00:00');
        session()->put(LicenseExpirationNotifier::OPEN_KEY, true);
        session()->put(LicenseExpirationNotifier::NOTIFIED_AT_KEY, now()->getTimestamp());

        Livewire::test(LicenseExpirationNotifier::class)
            ->call('check', true)
            ->assertNotified('Лицензия скоро истечёт');
    }

    public function test_interval_counts_from_closing(): void
    {
        Carbon::setTestNow('2026-11-08 10:00:00');
        $component = Livewire::test(LicenseExpirationNotifier::class)->call('check');
        Notification::assertNotified('Лицензия скоро истечёт');

        Carbon::setTestNow('2026-11-08 10:00:50');
        $component->dispatch('notificationClosed', id: LicenseExpirationNotifier::NOTIFICATION_ID);

        Carbon::setTestNow('2026-11-08 10:01:20');
        $component->call('check')->assertNotNotified();

        Carbon::setTestNow('2026-11-08 10:01:50');
        $component->call('check')->assertNotified('Лицензия скоро истечёт');
    }

    public function test_dashboard_renders_notifier_with_polling(): void
    {
        Carbon::setTestNow('2026-11-08 10:00:00');

        $this->actingAs($this->admin(), 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertSee('wire:poll.60s="check"', false);
    }

    public function test_login_is_blocked_after_expiration(): void
    {
        Carbon::setTestNow('2026-11-30 00:00:00');
        $this->admin();

        Livewire::test(Login::class)
            ->assertHasErrors(['data.email'])
            ->set('data.email', 'admin@example.com')
            ->set('data.password', 'secret')
            ->call('authenticate')
            ->assertHasErrors(['data.email']);

        $this->assertGuest('admin');
    }

    public function test_login_works_before_expiration(): void
    {
        Carbon::setTestNow('2026-11-29 23:00:00');
        $this->admin();

        Livewire::test(Login::class)
            ->set('data.email', 'admin@example.com')
            ->set('data.password', 'secret')
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertAuthenticated('admin');
    }

    public function test_active_session_is_terminated_after_expiration(): void
    {
        Carbon::setTestNow('2026-11-30 00:00:01');

        $this->actingAs($this->admin(), 'admin')
            ->get('/admin')
            ->assertRedirect('/admin/login');

        $this->assertGuest('admin');
    }

    private function admin(): Admin
    {
        return Admin::query()->create([
            'first_name' => 'Иван',
            'last_name'  => 'Иванов',
            'email'      => 'admin@example.com',
            'password'   => 'secret',
        ]);
    }
}
