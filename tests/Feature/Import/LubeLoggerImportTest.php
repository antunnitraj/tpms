<?php

use App\Enums\DistanceUnit;
use App\Enums\FuelUnit;
use App\Enums\RecordCategory;
use App\Import\LubeLogger\LubeLoggerImporter;
use App\Models\BackupImport;
use App\Models\FuelRecord;
use App\Models\Record;
use App\Models\Supply;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Support\LiteDbFixtureWriter;
use Tests\Support\NetDecimal;

beforeEach(function () {
    Storage::fake('media');
    $this->admin = User::factory()->admin()->create(['username' => 'admin']);
    $this->importer = app(LubeLoggerImporter::class);
});

it('previews the demo backup without writing anything', function () {
    $import = $this->importer->analyze(base_path('tests/Fixtures/lubelogger-demo.zip'), 'demo.zip', $this->admin);

    expect(Vehicle::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(1)
        ->and($import->status)->toBe(BackupImport::STATUS_PREVIEWED)
        ->and($import->preview['vehicles'])->toHaveCount(1)
        ->and($import->preview['users'])->toHaveCount(1)
        ->and($import->preview['users'][0])->toMatchArray(['username' => 'test', 'action' => 'create', 'has_password' => true])
        ->and($import->options['vehicles']['1'])->toMatchArray(['distance_unit' => 'mi', 'fuel_unit' => 'us_gal']);
});

it('skips LubeLogger\'s automatic odometer copies even when they are a day off', function () {
    // In the demo, the copies sit at the previous day (another server timezone) with the same mileage.
    $import = $this->importer->analyze(base_path('tests/Fixtures/lubelogger-demo.zip'), 'demo.zip', $this->admin);

    expect($import->preview['vehicles'][0]['counts']['odometer_skipped'] ?? null)->toBe(110);
});

it('leaves out images and documents nothing in the backup refers to', function () {
    $zipPath = sys_get_temp_dir().'/tpms-unused-'.uniqid().'.zip';
    copy(base_path('tests/Fixtures/lubelogger-demo.zip'), $zipPath);
    $zip = new ZipArchive;
    $zip->open($zipPath);
    $zip->addFromString('images/left-behind.jpg', 'old upload');
    $zip->addFromString('documents/old-invoice.pdf', 'old upload');
    $zip->close();

    $import = $this->importer->analyze($zipPath, 'demo.zip', $this->admin);
    unlink($zipPath);
    $files = collect($import->preview['summary'])->firstWhere('label', 'Photos & files');

    expect(glob($import->work_path.'/images/*'))->toBe([$import->work_path.'/images/a5fc022e-3d82-40d0-94bc-33ed07f19bfb.jpg'])
        ->and(is_dir($import->work_path.'/documents'))->toBeFalse()
        ->and($files)->toMatchArray(['source' => 3, 'imported' => 1, 'note' => '2 unused files left out']);

    $this->importer->import($import->fresh());
    expect(Media::query()->count())->toBe(1);
});

it('puts inspection results in the log entry\'s notes and leaves out templates and shop supplies', function () {
    $dir = sys_get_temp_dir().'/tpms-inspection-'.uniqid();
    mkdir($dir);
    $date = CarbonImmutable::parse('2025-03-01', 'UTC');
    (new LiteDbFixtureWriter)
        ->add('vehicles', ['_id' => 1, 'Year' => 2010, 'Make' => 'Toyota', 'Model' => 'Corolla'])
        ->add('servicerecords', ['_id' => 5, 'VehicleId' => 1, 'Date' => $date, 'Mileage' => 100_000, 'Description' => 'Annual inspection',
            'Cost' => new NetDecimal('50'), 'Notes' => 'At the garage', 'Files' => [['Name' => 'Inspection', 'Location' => '::InspectionRecord:3']]])
        ->add('inspectionrecords', ['_id' => 3, 'VehicleId' => 1, 'Date' => $date, 'Mileage' => 100_000, 'Description' => 'Yearly check', 'Cost' => new NetDecimal('0'),
            'Results' => [
                ['Description' => 'Brakes', 'FieldType' => 'Radio', 'Failed' => false, 'Values' => [
                    ['Description' => 'OK', 'IsSelected' => true, 'IsFail' => false], ['Description' => 'Worn', 'IsSelected' => false, 'IsFail' => true]]],
                ['Description' => 'Tyres', 'FieldType' => 'Radio', 'Failed' => true, 'Notes' => 'front left', 'Values' => [
                    ['Description' => 'OK', 'IsSelected' => false, 'IsFail' => false], ['Description' => 'Worn', 'IsSelected' => true, 'IsFail' => true]]],
            ]])
        ->add('supplyrecords', ['_id' => 9, 'VehicleId' => 0, 'Description' => 'Shop rags', 'Quantity' => new NetDecimal('5'), 'Cost' => new NetDecimal('10')])
        ->add('planrecordtemplates', ['_id' => 1, 'VehicleId' => 1, 'Description' => 'Oil change'])
        ->write($dir.'/cartracker.db');
    $zipPath = $dir.'/backup.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFile($dir.'/cartracker.db', 'data/cartracker.db');
    $zip->close();

    $import = $this->importer->analyze($zipPath, 'backup.zip', $this->admin);
    File::deleteDirectory($dir);
    $this->importer->import($import->fresh());

    expect(Record::query()->sole()->notes)->toBe("At the garage\n\nInspection: Yearly check\n✓ Brakes: OK\n✗ Tyres: Worn (front left)")
        ->and(Supply::query()->count())->toBe(0)
        ->and($import->fresh()->warnings)->toContain('1 shop supply isn\'t tied to a vehicle and was left out.');
});

it('imports the demo backup with its user, access, supplies and fuel', function () {
    $import = $this->importer->analyze(base_path('tests/Fixtures/lubelogger-demo.zip'), 'demo.zip', $this->admin);
    $preview = $import->preview;
    $this->importer->import($import);

    $vehicle = Vehicle::query()->sole();
    $test = User::query()->where('username', 'test')->sole();

    expect($import->fresh()->status)->toBe(BackupImport::STATUS_IMPORTED)
        ->and($vehicle->distance_unit)->toBe(DistanceUnit::Miles)
        ->and($vehicle->fuel_unit)->toBe(FuelUnit::UsGallons)
        ->and($vehicle->users->pluck('username')->sort()->values()->all())->toBe(['admin', 'test'])
        ->and(FuelRecord::query()->count())->toBe(109)
        ->and(FuelRecord::query()->count())->toBe($preview['vehicles'][0]['counts']['fuel'])
        ->and(Record::query()->count())->toBe(22 + 34 + 13 + 3)
        ->and(Record::query()->where('category', RecordCategory::TaxFee)->count())->toBe(3)
        ->and(Supply::query()->count())->toBe(10)
        ->and($vehicle->getFirstMedia('photos'))->not->toBeNull();

    // Every supply's ledger ends at the quantity LubeLogger had left.
    Supply::query()->withStock()->get()->each(fn (Supply $supply) => expect($supply->stock())->toBeGreaterThanOrEqual(0));

    // The LubeLogger password keeps working and is upgraded to bcrypt on first use.
    expect($test->legacy_sha256)->not->toBeNull()
        ->and(Auth::attempt(['login' => 'test', 'password' => 'wrong']))->toBeFalse()
        ->and(Auth::attempt(['login' => 'test', 'password' => '1234']))->toBeTrue()
        ->and($test->fresh()->legacy_sha256)->toBeNull()
        ->and(Auth::attempt(['login' => 'test@lubelogger.com', 'password' => '1234']))->toBeTrue();
});

it('lets the admin skip users and vehicles in the preview', function () {
    $import = $this->importer->analyze(base_path('tests/Fixtures/lubelogger-demo.zip'), 'demo.zip', $this->admin);
    $options = $import->options;
    $options['users']['1'] = ['action' => 'skip', 'user_id' => null];

    $this->importer->preview($import, $options);
    $this->importer->import($import->fresh());

    expect(User::query()->count())->toBe(1)
        ->and(Vehicle::query()->sole()->users->pluck('id')->all())->toBe([$this->admin->id]);
});

it('undoes an import completely', function () {
    $import = $this->importer->analyze(base_path('tests/Fixtures/lubelogger-demo.zip'), 'demo.zip', $this->admin);
    $this->importer->import($import);
    $this->importer->undo($import->fresh());

    expect(Vehicle::query()->count())->toBe(0)
        ->and(FuelRecord::query()->count())->toBe(0)
        ->and(Supply::query()->count())->toBe(0)
        ->and(User::query()->pluck('username')->all())->toBe(['admin'])
        ->and(Media::query()->count())->toBe(0)
        ->and($import->fresh()->status)->toBe(BackupImport::STATUS_UNDONE);
});

it('rejects files that are not LubeLogger backups', function () {
    $zip = tempnam(sys_get_temp_dir(), 'zip');
    $archive = new ZipArchive;
    $archive->open($zip, ZipArchive::OVERWRITE);
    $archive->addFromString('hello.txt', 'hi');
    $archive->addFromString('../escape.txt', 'nope');
    $archive->close();

    expect(fn () => $this->importer->analyze($zip, 'x.zip', $this->admin))->toThrow(RuntimeException::class)
        ->and(BackupImport::query()->count())->toBe(0);
});
