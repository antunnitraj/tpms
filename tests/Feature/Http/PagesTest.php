<?php

use App\Enums\Theme;
use App\Filament\Pages\Settings;
use App\Filament\Resources\Vehicles\VehicleResource;
use App\Import\LubeLogger\LubeLoggerImporter;
use App\Models\User;
use App\Models\Vehicle;
use Filament\Support\Colors\Color;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('media');
    $this->admin = User::factory()->admin()->create();
    $import = app(LubeLoggerImporter::class)->analyze(base_path('tests/Fixtures/lubelogger-demo.zip'), 'demo.zip', $this->admin);
    app(LubeLoggerImporter::class)->import($import);
    $this->vehicle = Vehicle::query()->sole();
});

it('renders every vehicle page', function (string $page) {
    $this->actingAs($this->admin)
        ->get(VehicleResource::getUrl($page, ['record' => $this->vehicle]))
        ->assertOk();
})->with(['view', 'edit', 'log', 'fuel', 'odometer', 'reminders', 'plans', 'supplies', 'notes', 'equipment', 'media', 'reports']);

it('renders every vehicle page of a new, empty vehicle', function (string $page) {
    // Empty tables show their empty state, which the demo vehicle never does.
    $this->actingAs($this->admin)
        ->get(VehicleResource::getUrl($page, ['record' => vehicleFor($this->admin)]))
        ->assertOk();
})->with(['view', 'edit', 'log', 'fuel', 'odometer', 'reminders', 'plans', 'supplies', 'notes', 'equipment', 'media', 'reports']);

it('renders the garage, settings and admin pages', function (string $path) {
    $this->actingAs($this->admin)->get($path)->assertOk();
})->with(['/vehicles', '/settings', '/profile',
    '/admin/users', '/admin/import', '/admin/settings']);

it('puts the printable report on the reports page and serves media to collaborators only', function () {
    $media = $this->vehicle->getFirstMedia('photos');

    $this->actingAs($this->admin)->get(VehicleResource::getUrl('reports', ['record' => $this->vehicle]))
        ->assertOk()->assertSee('tpms-print', escape: false)->assertSee('Vehicle report');
    $this->actingAs($this->admin)->get($media->getUrl())->assertOk();

    $stranger = User::factory()->create();
    $this->actingAs($stranger)->get($media->getUrl())->assertForbidden();
    $this->actingAs($stranger)->get(VehicleResource::getUrl('reports', ['record' => $this->vehicle]))->assertNotFound();
});

it('hides other people\'s vehicles', function () {
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get(VehicleResource::getUrl('view', ['record' => $this->vehicle]))->assertNotFound();
    $this->actingAs($stranger)->get(VehicleResource::getUrl('fuel', ['record' => $this->vehicle]))->assertNotFound();
    $this->actingAs($stranger)->get('/vehicles')->assertOk()->assertDontSee($this->vehicle->model);
});

it('keeps administration pages away from regular users', function (string $path) {
    $this->actingAs(User::factory()->create())->get($path)->assertForbidden();
})->with(['/admin/users', '/admin/import', '/admin/settings']);

it('shows the bottom tab bar on app pages but not on the sign-in page', function () {
    $this->actingAs($this->admin)->get('/vehicles')->assertOk()->assertSee('tpms-tab-bar', escape: false);

    auth()->logout();
    $this->get('/login')->assertOk()->assertDontSee('tpms-tab-bar', escape: false);
});

it('saves the theme a user picks', function () {
    $user = User::factory()->create();

    Livewire\Livewire::actingAs($user)->test(Settings::class)
        ->call('setTheme', 'blue')
        ->assertRedirect(Settings::getUrl());

    expect($user->fresh()->theme())->toBe(Theme::Blue);
});

// Filament caches colours per process (a single request in production), so one rendered page per test.
it('shows each user their own theme', function () {
    $user = User::factory()->create(['settings' => ['theme' => 'blue']]);

    $this->actingAs($user)->get('/settings')->assertOk()
        ->assertSee('--primary-500:'.Color::Blue[500], escape: false);
});

it('shows TPMS yellow to users who never picked a theme', function () {
    $this->actingAs($this->admin)->get('/settings')->assertOk()
        ->assertSee('--primary-500:'.Color::Amber[500], escape: false);
});

it('renders every theme with its own accent', function (Theme $theme) {
    $user = User::factory()->create(['settings' => ['theme' => $theme->value]]);

    $this->actingAs($user)->get('/settings')->assertOk()
        ->assertSee('--primary-500:'.$theme->primary()[500], escape: false)
        ->assertSee('--tpms-solid-fg: '.($theme->isBright() ? 'var(--gray-950)' : '#fff'), escape: false);
})->with(Theme::cases());
