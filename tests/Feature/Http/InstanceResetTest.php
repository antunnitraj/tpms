<?php

use App\Filament\Pages\Admin\AppSettings;
use App\Models\AppSetting;
use App\Models\Record;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('media');
    $this->admin = User::factory()->admin()->create(['password' => Hash::make('correct-horse')]);
    $this->actingAs($this->admin);
});

it('deletes everything and goes back to setup', function () {
    Record::factory()->for(vehicleFor($this->admin))->create();
    User::factory()->create();
    AppSetting::put('currency', 'USD');
    Storage::disk('media')->put('1/photo.jpg', 'photo');

    Livewire::test(AppSettings::class)
        ->callAction('reset', data: ['confirmation' => 'RESET', 'password' => 'correct-horse'])
        ->assertHasNoActionErrors()
        ->assertRedirect(route('filament.app.setup'));

    expect(User::query()->count())->toBe(0)
        ->and(Vehicle::query()->count())->toBe(0)
        ->and(Record::query()->count())->toBe(0)
        ->and(AppSetting::query()->count())->toBe(0)
        ->and(Storage::disk('media')->allFiles())->toBe([])
        ->and(auth()->check())->toBeFalse();

    $this->get('/')->assertRedirect(route('filament.app.setup'));
    $this->get('/setup')->assertOk();
});

it('needs RESET typed out and the admin\'s password', function () {
    Livewire::test(AppSettings::class)
        ->callAction('reset', data: ['confirmation' => 'reset', 'password' => 'wrong-password'])
        ->assertHasActionErrors(['confirmation', 'password']);

    expect(User::query()->count())->toBe(1);
});

it('is only for administrators', function () {
    $this->actingAs(User::factory()->create())->get(AppSettings::getUrl())->assertForbidden();
});
