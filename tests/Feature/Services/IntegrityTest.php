<?php

use App\Enums\FuelType;
use App\Enums\FuelUnit;
use App\Enums\RecordCategory;
use App\Enums\RepeatUnit;
use App\Filament\Resources\Vehicles\Pages\EditVehicle;
use App\Filament\Resources\Vehicles\Pages\ManageVehicleLog;
use App\Filament\Resources\Vehicles\Pages\ViewVehicle;
use App\Import\LubeLogger\LubeLoggerImporter;
use App\Models\BackupImport;
use App\Models\FuelRecord;
use App\Models\Plan;
use App\Models\Record;
use App\Models\Reminder;
use App\Models\Supply;
use App\Models\User;
use App\Services\Plans;
use App\Services\Reminders;
use App\Services\Supplies;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function () {
    Storage::fake('media');
    $this->user = User::factory()->create();
    $this->vehicle = vehicleFor($this->user);
});

it('drops a due date that doesn\'t repeat once a distance-based reminder is done', function () {
    $reminder = Reminder::factory()->for($this->vehicle)->create([
        'due_date' => '2026-12-01', 'due_mileage' => 50_000, 'repeat_distance' => 10_000,
    ]);

    Reminders::complete($reminder, CarbonImmutable::parse('2026-06-01'), 49_000);

    expect($reminder->fresh())->due_date->toBeNull()->due_mileage->toBe(59_000);
});

it('drops a due mileage that doesn\'t repeat once a time-based reminder is done', function () {
    $reminder = Reminder::factory()->for($this->vehicle)->create([
        'due_mileage' => 120_000, 'repeat_every' => 60, 'repeat_unit' => RepeatUnit::Month,
    ]);

    Reminders::complete($reminder, CarbonImmutable::parse('2026-01-01'), 119_000);

    expect($reminder->fresh())->due_mileage->toBeNull()->due_date->toDateString()->toBe('2031-01-01');
});

it('removes every entry\'s files when a vehicle with a long history is deleted', function () {
    $now = now();
    FuelRecord::query()->insert(array_map(fn (int $i) => [
        'vehicle_id' => $this->vehicle->id, 'date' => '2020-01-01', 'mileage' => $i, 'quantity' => 1, 'cost' => 1,
        'is_full' => true, 'missed_previous' => false, 'created_at' => $now, 'updated_at' => $now,
    ], range(1, 1_005)));
    $last = FuelRecord::query()->latest('id')->first();
    $media = $last->addMedia(UploadedFile::fake()->image('receipt.jpg'))->toMediaCollection('attachments');

    $this->vehicle->delete();

    expect(Media::query()->count())->toBe(0)
        ->and(Storage::disk('media')->exists($media->getPathRelativeToRoot()))->toBeFalse();
});

it('keeps shared vehicles, hands over unshared ones and keeps supplies when a user is deleted', function () {
    $admin = User::factory()->admin()->create();
    $friend = User::factory()->create();
    $this->vehicle->users()->attach($friend);
    $own = vehicleFor($friend);
    $supply = Supply::factory()->for($this->vehicle)->create(['user_id' => $friend->id]);

    $this->actingAs($admin);
    $friend->delete();

    expect($this->vehicle->users()->pluck('users.id')->all())->toBe([$this->user->id])
        ->and($own->users()->pluck('users.id')->all())->toBe([$admin->id])
        ->and($supply->fresh())->not->toBeNull()->user_id->toBeNull();
});

it('keeps the price a supply usage was booked at when its entry is saved again', function () {
    $this->actingAs($this->user);
    $supply = Supply::factory()->for($this->vehicle)->create(['user_id' => $this->user->id]);
    $record = Record::factory()->for($this->vehicle)->create();
    Supplies::purchase($supply, 10, 100, CarbonImmutable::parse('2026-01-01'));
    Supplies::use($supply, 2, $record, CarbonImmutable::parse('2026-01-02'));
    Supplies::purchase($supply, 10, 200, CarbonImmutable::parse('2026-01-03'));

    Livewire::test(ManageVehicleLog::class, ['record' => $this->vehicle->getRouteKey()])
        ->callAction(TestAction::make('edit')->table($record), data: ['notes' => 'Only the notes changed'])
        ->assertHasNoActionErrors();

    expect((float) $record->supplyUsages()->sole()->cost)->toBe(-20.0);
});

it('keeps gallons when a vehicle switches between petrol and diesel', function () {
    $this->actingAs($this->user);
    $this->vehicle->update(['fuel_type' => FuelType::Gasoline, 'fuel_unit' => FuelUnit::UsGallons]);

    $page = Livewire::test(EditVehicle::class, ['record' => $this->vehicle->getRouteKey()]);
    $unit = fn () => ($unit = $page->get('data.fuel_unit')) instanceof FuelUnit ? $unit : FuelUnit::from($unit);

    $page->fillForm(['fuel_type' => FuelType::Diesel->value]);
    expect($unit())->toBe(FuelUnit::UsGallons);

    $page->fillForm(['fuel_type' => FuelType::Electric->value]);
    expect($unit())->toBe(FuelUnit::KilowattHours);
});

it('logs a finished plan only once', function () {
    $plan = Plan::factory()->for($this->vehicle)->create();

    $first = Plans::complete($plan, CarbonImmutable::parse('2026-01-05'));
    $second = Plans::complete($plan->fresh(), CarbonImmutable::parse('2026-01-05'));

    expect($second->is($first))->toBeTrue()
        ->and($this->vehicle->records()->count())->toBe(1);
});

it('refuses dates like the year 20 that would stall the reports', function () {
    $this->actingAs($this->user);

    Livewire::test(ViewVehicle::class, ['record' => $this->vehicle->getRouteKey()])
        ->callAction('addFuel', data: ['date' => '0020-01-05', 'mileage' => 12_000, 'quantity' => 40, 'cost' => 60])
        ->assertHasActionErrors(['date']);
});

it('won\'t undo an import that created the signed-in account', function () {
    $import = BackupImport::query()->create(['user_id' => null, 'filename' => 'b.zip', 'sha256' => str_repeat('a', 64), 'status' => BackupImport::STATUS_IMPORTED]);
    $imported = User::factory()->admin()->create(['import_id' => $import->id]);
    $this->actingAs($imported);

    expect(fn () => app(LubeLoggerImporter::class)->undo($import))->toThrow(RuntimeException::class);
    expect($imported->fresh())->not->toBeNull();
});

it('deletes an entry\'s files with the entry', function () {
    $record = Record::factory()->for($this->vehicle)->create();
    $record->addMedia(UploadedFile::fake()->image('receipt.jpg'))->toMediaCollection('attachments');
    Supplies::purchase($supply = Supply::factory()->for($this->vehicle)->create(['user_id' => $this->user->id]), 5, 50, CarbonImmutable::now());
    Supplies::use($supply, 1, $record, CarbonImmutable::now());

    $record->delete();

    expect(Media::query()->count())->toBe(0)
        ->and($supply->stock())->toBe(5.0);
});

it('logs an entry that uses a supply', function () {
    $this->actingAs($this->user);
    $supply = Supply::factory()->for($this->vehicle)->create(['user_id' => $this->user->id]);
    Supplies::purchase($supply, 10, 100, CarbonImmutable::parse('2026-01-01'));
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(ViewVehicle::class, ['record' => $this->vehicle->getRouteKey()])
        ->callAction('addRecord', data: [
            'category' => RecordCategory::Service->value, 'description' => 'Oil change', 'date' => '2026-01-10', 'cost' => 0,
            'supplyUsages' => [['supply_id' => $supply->id, 'quantity' => 4]],
        ])
        ->assertHasNoActionErrors();

    $undoRepeaterFake();
    expect($supply->stock())->toBe(6.0)
        ->and((float) $this->vehicle->records()->sole()->supplyUsages()->sole()->cost)->toBe(-40.0);
});
