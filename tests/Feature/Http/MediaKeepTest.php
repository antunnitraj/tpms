<?php

use App\Enums\RecordCategory;
use App\Filament\Resources\Vehicles\Pages\EditVehicle;
use App\Filament\Resources\Vehicles\Pages\ManageVehicleLog;
use App\Filament\Resources\Vehicles\Pages\ViewVehicle;
use App\Filament\Resources\Vehicles\VehicleResource;
use App\Filament\Resources\Vehicles\Widgets\Media\VehicleDocuments;
use App\Filament\Resources\Vehicles\Widgets\Media\VehiclePhotos;
use App\Models\Record;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('media');
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->vehicle = vehicleFor($this->user);
    foreach (['front', 'side', 'rear'] as $name) {
        $this->vehicle->addMedia(UploadedFile::fake()->image("{$name}.jpg"))->toMediaCollection('photos');
    }
    $this->photos = fn () => Livewire::test(VehiclePhotos::class, ['record' => $this->vehicle]);
    $this->photoNames = fn () => $this->vehicle->fresh()->getMedia('photos')->pluck('name')->all();
    $this->photo = fn (string $name) => $this->vehicle->fresh()->getMedia('photos')->firstWhere('name', $name);
});

it('shows photos and documents on the Photos & files tab, not files on entries', function () {
    $this->get(VehicleResource::getUrl('media', ['record' => $this->vehicle]))->assertOk()
        ->assertSeeLivewire(VehiclePhotos::class)
        ->assertSeeLivewire(VehicleDocuments::class)
        ->assertDontSee('Files on entries');
});

it('adds photos after the ones already there', function () {
    // The gallery has no form of its own, so the action's form is named explicitly.
    ($this->photos)()
        ->mountAction('addPhotos')
        ->fillForm(['files' => [UploadedFile::fake()->image('interior.jpg'), UploadedFile::fake()->image('engine.jpg')]], 'mountedActionSchema0')
        ->callMountedAction();

    expect(($this->photoNames)())->toBe(['front', 'side', 'rear', 'interior', 'engine']);
});

it('adds documents without touching the photos', function () {
    Livewire::test(VehicleDocuments::class, ['record' => $this->vehicle])
        ->callAction(TestAction::make('addDocuments')->table(), data: ['files' => [UploadedFile::fake()->create('registration.pdf', 20, 'application/pdf')]])
        ->assertHasNoActionErrors();

    expect($this->vehicle->fresh()->getMedia('documents')->pluck('name')->all())->toBe(['registration'])
        ->and(($this->photoNames)())->toBe(['front', 'side', 'rear']);
});

it('makes a photo the cover and shows it straight away', function () {
    ($this->photos)()
        ->assertSeeInOrder(['front', 'side', 'rear'])
        ->callAction(TestAction::make('makeCover')->arguments(['media' => ($this->photo)('rear')->id]))
        ->assertSeeInOrder(['rear', 'front', 'side']);

    expect(($this->photoNames)())->toBe(['rear', 'front', 'side']);
});

it('saves the photo order after a drag, ignoring other vehicles\' photos', function () {
    $other = vehicleFor(User::factory()->create());
    $foreign = $other->addMedia(UploadedFile::fake()->image('x.jpg'))->toMediaCollection('photos');
    $ids = $this->vehicle->getMedia('photos')->pluck('id');

    ($this->photos)()
        ->call('reorderPhotos', [$ids[2], $foreign->id, $ids[0]])
        ->assertSeeInOrder(['rear', 'front', 'side']);

    expect(($this->photoNames)())->toBe(['rear', 'front', 'side'])
        ->and($other->fresh()->getMedia('photos')->first()->order_column)->toBe($foreign->order_column);
});

it('keeps photo addresses the same when the order changes, so they are not downloaded again', function () {
    $urls = fn () => $this->vehicle->fresh()->getMedia('photos')->mapWithKeys(fn ($m) => [$m->id => $m->getUrl('thumb')])->sortKeys()->all();
    $before = $urls();
    $this->travel(1)->minute();

    ($this->photos)()->call('reorderPhotos', array_reverse(array_keys($before)));

    expect($urls())->toBe($before);
});

it('renames and deletes one photo at a time', function () {
    ($this->photos)()
        ->mountAction('renamePhoto', ['media' => ($this->photo)('side')->id])
        ->fillForm(['name' => 'Side view'], 'mountedActionSchema0')
        ->callMountedAction()
        ->mountAction('deletePhoto', ['media' => ($this->photo)('front')->id])
        ->callMountedAction();

    expect(($this->photoNames)())->toBe(['Side view', 'rear']);
});

it('deletes several photos at once', function () {
    ($this->photos)()
        ->mountAction('deletePhotos', ['ids' => [($this->photo)('front')->id, ($this->photo)('rear')->id]])
        ->callMountedAction();

    expect(($this->photoNames)())->toBe(['side']);
});

it('ignores other vehicles\' photos in a selection', function () {
    $other = vehicleFor(User::factory()->create());
    $foreign = $other->addMedia(UploadedFile::fake()->image('x.jpg'))->toMediaCollection('photos');

    ($this->photos)()->mountAction('deletePhotos', ['ids' => [$foreign->id, ($this->photo)('side')->id]])->callMountedAction();

    expect($other->fresh()->getMedia('photos'))->toHaveCount(1)
        ->and(($this->photoNames)())->toBe(['front', 'rear']);
});

it('never touches files of a vehicle the user cannot open', function () {
    $other = vehicleFor(User::factory()->create());
    $foreign = $other->addMedia(UploadedFile::fake()->image('x.jpg'))->toMediaCollection('photos');

    // The record isn't in this vehicle's list, so the action can't find it.
    try {
        ($this->photos)()->callAction(TestAction::make('deletePhoto')->arguments(['media' => $foreign->id]));
    } catch (Throwable) {
    }

    expect($other->fresh()->getMedia('photos'))->toHaveCount(1);
});

it('keeps the photos when vehicle settings are saved', function () {
    Livewire::test(EditVehicle::class, ['record' => $this->vehicle->getRouteKey()])
        ->fillForm(['nickname' => 'Daily'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertSee('Manage photos');

    expect(($this->photoNames)())->toBe(['front', 'side', 'rear']);
});

it('keeps an entry\'s attachments when the entry is edited', function () {
    $record = Record::factory()->for($this->vehicle)->create();
    $record->addMedia(UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf'))->toMediaCollection('attachments');

    Livewire::test(ManageVehicleLog::class, ['record' => $this->vehicle->getRouteKey()])
        ->callAction(TestAction::make('edit')->table($record), data: ['description' => 'Oil change'])
        ->assertHasNoActionErrors();

    expect($record->fresh()->getMedia('attachments'))->toHaveCount(1);
});

it('shows a preview of pictures among the documents', function () {
    $picture = $this->vehicle->addMedia(UploadedFile::fake()->image('registration.jpg'))->toMediaCollection('documents');
    $pdf = $this->vehicle->addMedia(UploadedFile::fake()->create('insurance.pdf', 10, 'application/pdf'))->toMediaCollection('documents');

    Livewire::test(VehicleDocuments::class, ['record' => $this->vehicle])
        ->assertSeeHtml("/media/{$picture->uuid}/thumb")
        ->assertDontSeeHtml("/media/{$pdf->uuid}/thumb")
        ->assertSee(['registration', 'insurance']);
});

it('keeps the name of a file attached to an entry, also when it is downloaded', function () {
    Livewire::test(ViewVehicle::class, ['record' => $this->vehicle->getRouteKey()])
        ->callAction('addRecord', data: [
            'category' => RecordCategory::Service->value, 'description' => 'Oil change', 'date' => '2026-01-10',
            'attachments' => [UploadedFile::fake()->createWithContent('Račun servis.pdf', "%PDF-1.4\n%%EOF\n")],
        ])
        ->assertHasNoActionErrors();

    $media = $this->vehicle->records()->sole()->getFirstMedia('attachments');
    // Stored on the private media disk like every other file, not Filament's default disk.
    expect($media->disk)->toBe('media')
        ->and($media->name)->toBe('Račun servis')
        ->and($media->file_name)->toStartWith('Ra')->toEndWith('.pdf')->not->toMatch('/^[0-9A-Za-z]{30,}/');

    // Browsers use the UTF-8 name; the plain one is a fallback for old clients.
    $download = $this->get($media->getUrl().'?download=1')->assertOk()->assertDownload('Racun servis.pdf');
    expect($download->headers->get('Content-Disposition'))->toContain("filename*=utf-8''".rawurlencode('Račun servis.pdf'));

    // A rename in the app is the name the file is saved and opened under.
    $media->update(['name' => 'Oil change receipt']);
    expect($this->get($media->fresh()->getUrl())->headers->get('Content-Disposition'))
        ->toStartWith('inline')->toContain('Oil change receipt.pdf');
});
