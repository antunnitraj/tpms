<?php

use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Filament\Resources\Vehicles\Widgets\Media\VehiclePhotos;
use App\Filament\Resources\Vehicles\Widgets\VehicleStatsOverview;
use App\Models\Record;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('media');
    $this->user = User::factory()->create();
    $this->vehicle = vehicleFor($this->user);
});

it('never opens an uploaded page or SVG as part of the app', function (string $name, string $content) {
    $record = Record::factory()->for($this->vehicle)->create();
    $media = $record->addMedia(UploadedFile::fake()->createWithContent($name, $content))->toMediaCollection('attachments');

    $response = $this->actingAs($this->user)->get($media->getUrl())->assertOk();

    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toBe('sandbox');
})->with([
    'html' => ['notes.html', '<html><script>alert(1)</script></html>'],
    'svg' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
]);

it('opens pictures and PDFs in the browser', function () {
    $photo = $this->vehicle->addMedia(UploadedFile::fake()->image('front.jpg'))->toMediaCollection('photos');
    $pdf = $this->vehicle->addMedia(UploadedFile::fake()->createWithContent('insurance.pdf', "%PDF-1.4\n%%EOF\n"))->toMediaCollection('documents');

    $picture = $this->actingAs($this->user)->get($photo->getUrl())->assertOk();
    expect($picture->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($picture->headers->get('Content-Security-Policy'))->toBe('sandbox');

    expect($this->get($pdf->getUrl())->headers->get('Content-Disposition'))->toStartWith('inline');
});

it('stops a page left open after the vehicle was unshared', function () {
    $friend = User::factory()->create();
    $this->vehicle->users()->attach($friend);
    $photo = $this->vehicle->addMedia(UploadedFile::fake()->image('front.jpg'))->toMediaCollection('photos');
    $this->actingAs($friend);

    $photos = Livewire::test(VehiclePhotos::class, ['record' => $this->vehicle])->assertOk();
    $stats = Livewire::test(VehicleStatsOverview::class, ['record' => $this->vehicle])->assertOk();

    $this->vehicle->users()->detach($friend);

    $photos->call('reorderPhotos', [$photo->id])->assertForbidden();
    $stats->call('$refresh')->assertForbidden();
    Livewire::test(VehiclePhotos::class, ['record' => $this->vehicle])->assertForbidden();
});

it('lets the stats step aside when the vehicle is deleted from its own page', function () {
    // The browser updates the widget in the same request as the delete, after it,
    // while the widget still holds the vehicle.
    $this->actingAs($this->user);
    $stats = new VehicleStatsOverview;
    $stats->record = $this->vehicle;

    Vehicle::query()->find($this->vehicle->getKey())->delete();
    $stats->bootedChecksVehicleAccess();

    expect($stats->shouldSkipRender())->toBeTrue();
});

it('limits sign-in attempts per account, whatever the IP address', function () {
    $ana = User::factory()->create(['username' => 'ana', 'email' => 'ana@example.com', 'password' => 'secret-pass']);
    $key = 'login-account:'.$ana->getKey();

    Livewire::test(Login::class)->fillForm(['login' => 'Ana', 'password' => 'wrong'])->call('authenticate')->assertHasFormErrors(['login']);
    expect(RateLimiter::attempts($key))->toBe(1);

    // As if nine more guesses came from other addresses: even the right password is refused now.
    foreach (range(1, 9) as $i) {
        RateLimiter::hit($key, 900);
    }
    Livewire::test(Login::class)->fillForm(['login' => 'ana', 'password' => 'secret-pass'])->call('authenticate');
    $this->assertGuest();

    // Typing the email instead counts against the same account.
    Livewire::test(Login::class)->fillForm(['login' => 'Ana@Example.com', 'password' => 'secret-pass'])->call('authenticate');
    $this->assertGuest();

    RateLimiter::clear($key);
    Livewire::test(Login::class)->fillForm(['login' => 'ana', 'password' => 'secret-pass'])->call('authenticate')->assertHasNoFormErrors();
    $this->assertAuthenticated();
});

it('ignores X-Forwarded-For unless a proxy is configured', function () {
    Route::get('/_test/ip', fn () => request()->ip());

    $this->get('/_test/ip', ['X-Forwarded-For' => '203.0.113.9'])->assertSee('127.0.0.1');

    config(['trustedproxy.proxies' => '*']);
    $this->get('/_test/ip', ['X-Forwarded-For' => '203.0.113.9'])->assertSee('203.0.113.9');
});

it('links to https on the APP_URL host even when the proxy is not trusted', function () {
    config(['app.url' => 'https://cars.example.test']);

    // A tunnel forwards plain HTTP with the public host name.
    $this->get('http://cars.example.test/login')
        ->assertOk()
        ->assertSee('https://cars.example.test/', false)
        ->assertDontSee('http://cars.example.test', false);

    // Opened by its LAN address, it stays on plain HTTP.
    $this->get('http://192.168.1.5/login')
        ->assertOk()
        ->assertSee('http://192.168.1.5/', false);
});

it('treats usernames and emails case-insensitively', function () {
    $admin = User::factory()->admin()->create(['username' => 'boss']);
    User::factory()->create(['username' => 'ana', 'email' => 'ana@example.test']);
    $this->actingAs($admin);

    Livewire::test(ManageUsers::class)
        ->mountAction('create')
        ->fillForm(['name' => 'Ana', 'username' => 'Ana', 'email' => 'ANA@example.test', 'password' => 'long-enough-1'])
        ->callMountedAction()
        ->assertHasActionErrors(['username' => 'unique', 'email' => 'unique']);

    expect(User::factory()->create(['username' => 'Marko', 'email' => 'Marko@Example.test']))
        ->username->toBe('marko')
        ->email->toBe('marko@example.test');
});
