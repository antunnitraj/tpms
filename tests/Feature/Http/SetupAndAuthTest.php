<?php

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Setup;
use App\Models\AppSetting;
use App\Models\User;
use Livewire\Livewire;

it('sends every visitor to the setup wizard until an admin exists', function () {
    $this->get('/')->assertRedirect('/setup');
    $this->get('/login')->assertRedirect('/setup');
    $this->get('/setup')->assertOk()->assertSee('Create administrator');
});

it('creates the first administrator and offers a LubeLogger import', function () {
    Livewire::test(Setup::class)
        ->fillForm([
            'name' => 'Marko', 'username' => 'marko', 'email' => 'marko@example.test',
            'password' => 'correct-horse', 'passwordConfirmation' => 'correct-horse',
            'currency' => 'eur', 'timezone' => 'Europe/Zagreb',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertSet('created', true)
        ->assertSee('Import a LubeLogger backup');

    $admin = User::query()->sole();
    expect($admin->is_admin)->toBeTrue()
        ->and(auth()->id())->toBe($admin->id)
        ->and(AppSetting::currency())->toBe('EUR');

    $this->get('/setup')->assertRedirect('/');
    auth()->logout();
    $this->get('/setup')->assertRedirect('/');
    $this->get('/')->assertRedirect();
    $this->get('/vehicles')->assertRedirect('/login');
});

it('refuses a second setup once an account exists', function () {
    $component = Livewire::test(Setup::class);
    User::factory()->admin()->create();

    $component->fillForm([
        'name' => 'Mallory', 'username' => 'mallory', 'password' => 'correct-horse', 'passwordConfirmation' => 'correct-horse',
        'currency' => 'EUR', 'timezone' => 'UTC',
    ])->call('create')->assertRedirect(Filament\Facades\Filament::getLoginUrl());

    expect(User::query()->where('username', 'mallory')->exists())->toBeFalse();
});

it('lets the new admin carry on after tapping create twice', function () {
    $component = Livewire::test(Setup::class)->fillForm([
        'name' => 'Ana Admin', 'username' => 'ana', 'password' => 'correct-horse', 'passwordConfirmation' => 'correct-horse',
        'currency' => 'eur', 'timezone' => 'Europe/Zagreb',
    ]);

    $component->call('create')->call('create')->assertSet('created', true)->assertNoRedirect();

    expect(User::query()->count())->toBe(1)
        ->and(AppSetting::currency())->toBe('EUR');
});

it('takes the browser timezone when it is a real one', function () {
    Livewire::test(Setup::class)
        ->call('useBrowserTimezone', 'Europe/Zagreb')->assertSet('data.timezone', 'Europe/Zagreb')
        ->call('useBrowserTimezone', 'Mars/Olympus')->assertSet('data.timezone', 'Europe/Zagreb');
});

it('has no public registration', function () {
    User::factory()->admin()->create();

    $this->get('/register')->assertNotFound();
});

it('signs in with username or email', function () {
    User::factory()->create(['username' => 'ana', 'email' => 'ana@example.test', 'password' => 'secret-pass']);

    Livewire::test(Login::class)->fillForm(['login' => 'ANA', 'password' => 'secret-pass'])->call('authenticate')->assertHasNoFormErrors();
    expect(auth()->user()?->username)->toBe('ana');

    auth()->logout();
    Livewire::test(Login::class)->fillForm(['login' => 'ana@example.test', 'password' => 'wrong'])->call('authenticate')->assertHasFormErrors(['login']);
});
