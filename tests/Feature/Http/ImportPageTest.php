<?php

use App\Filament\Pages\Admin\ImportLubeLogger;
use App\Import\LubeLogger\LubeLoggerImporter;
use App\Models\BackupImport;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('media');
    $this->actingAs(User::factory()->admin()->create());
});

it('previews an uploaded backup, then imports it', function () {
    $zip = UploadedFile::fake()->createWithContent('demo.zip', file_get_contents(base_path('tests/Fixtures/lubelogger-demo.zip')));

    $page = Livewire::test(ImportLubeLogger::class)
        ->fillForm(['backup' => $zip], 'uploadForm')
        ->call('analyze')
        ->assertHasNoFormErrors([], 'uploadForm')
        ->assertSee('LubeLogger users')
        ->assertSee('test');

    expect(Vehicle::query()->count())->toBe(0)
        ->and(BackupImport::query()->sole()->status)->toBe(BackupImport::STATUS_PREVIEWED);

    $page->callAction('import')->assertHasNoActionErrors()->assertSee('Import complete');

    expect(Vehicle::query()->count())->toBe(1)
        ->and(User::query()->where('username', 'test')->exists())->toBeTrue();
});

it('asks for a file when nothing was uploaded', function () {
    Livewire::test(ImportLubeLogger::class)
        ->call('analyze')
        ->assertHasFormErrors(['backup' => 'required'], 'uploadForm');

    expect(BackupImport::query()->count())->toBe(0);
});

it('does not list earlier imports', function () {
    $importer = app(LubeLoggerImporter::class);
    $importer->import($importer->analyze(base_path('tests/Fixtures/lubelogger-demo.zip'), 'demo.zip', auth()->user()));

    Livewire::test(ImportLubeLogger::class)
        ->assertSee('Upload your LubeLogger backup')
        ->assertDontSee('Previous imports')
        ->assertDontSee('demo.zip');
});

it('keeps a switched-off vehicle on the page so it can be switched back on', function () {
    $zip = UploadedFile::fake()->createWithContent('demo.zip', file_get_contents(base_path('tests/Fixtures/lubelogger-demo.zip')));

    $page = Livewire::test(ImportLubeLogger::class)
        ->fillForm(['backup' => $zip], 'uploadForm')
        ->call('analyze')
        ->set('options.vehicles.1.distance_unit', 'km')
        ->assertActionEnabled('import');

    $preview = fn () => BackupImport::query()->latest('id')->first()->preview;
    expect($preview()['vehicles'][0]['distance_unit'])->toBe('km');

    $page->set('options.vehicles.1.import', false)
        ->assertSee('Not imported')
        ->assertActionDisabled('import');
    expect($preview()['vehicles'])->toBe([])
        ->and($preview()['excluded_vehicles'][0]['source_id'])->toBe(1);

    $page->set('options.vehicles.1.import', true)->assertActionEnabled('import');
    expect($preview()['vehicles'])->toHaveCount(1)
        ->and($preview()['excluded_vehicles'])->toBe([]);
});

it('can read another file after a backup was rejected', function () {
    $zip = new ZipArchive;
    $zip->open($path = tempnam(sys_get_temp_dir(), 'tpms').'.zip', ZipArchive::CREATE);
    $zip->addFromString('readme.txt', 'not a backup');
    $zip->close();

    Livewire::test(ImportLubeLogger::class)
        ->fillForm(['backup' => UploadedFile::fake()->createWithContent('other.zip', file_get_contents($path))], 'uploadForm')
        ->call('analyze')
        ->assertNotified('Could not read the backup')
        // The rejected file is gone, so pressing the button again asks for a new one instead of failing.
        ->call('analyze')
        ->assertHasFormErrors(['backup' => 'required'], 'uploadForm');
});

it('says what is missing when a user should map to an account but none is chosen', function () {
    $page = Livewire::test(ImportLubeLogger::class)
        ->fillForm(['backup' => UploadedFile::fake()->createWithContent('demo.zip', file_get_contents(base_path('tests/Fixtures/lubelogger-demo.zip')))], 'uploadForm')
        ->call('analyze');
    $ref = BackupImport::query()->sole()->preview['users'][0]['ref'];

    $page->set("options.users.{$ref}.action", 'map')
        ->callAction('import')
        ->assertNotified('Something is missing')
        ->assertActionNotMounted('import');

    expect(BackupImport::query()->sole()->status)->toBe(BackupImport::STATUS_PREVIEWED);
});
