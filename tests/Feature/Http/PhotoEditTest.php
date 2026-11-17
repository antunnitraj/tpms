<?php

use App\Filament\Resources\Vehicles\Widgets\Media\VehiclePhotos;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function () {
    Storage::fake('media');
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->vehicle = vehicleFor($this->user);
    $this->photos = fn () => Livewire::test(VehiclePhotos::class, ['record' => $this->vehicle]);
    $this->onlyPhoto = fn () => $this->vehicle->fresh()->getMedia('photos')->sole();
});

/** A 400 × 200 PNG in four colours: red top left, green top right, blue bottom left, yellow bottom right. */
function addQuadrantPhoto($vehicle): Media
{
    $image = imagecreatetruecolor(400, 200);
    imagefilledrectangle($image, 0, 0, 199, 99, imagecolorallocate($image, 255, 0, 0));
    imagefilledrectangle($image, 200, 0, 399, 99, imagecolorallocate($image, 0, 255, 0));
    imagefilledrectangle($image, 0, 100, 199, 199, imagecolorallocate($image, 0, 0, 255));
    imagefilledrectangle($image, 200, 100, 399, 199, imagecolorallocate($image, 255, 255, 0));
    $path = sys_get_temp_dir().'/'.uniqid('quadrants').'.png';
    imagepng($image, $path);

    return $vehicle->addMedia($path)->usingName('front')->toMediaCollection('photos');
}

function colourAt(Media $photo, int $x, int $y): string
{
    $rgb = imagecolorat(imagecreatefromstring(file_get_contents($photo->getPath())), $x, $y);

    return match ([($rgb >> 16 & 255) > 127, ($rgb >> 8 & 255) > 127, ($rgb & 255) > 127]) {
        [true, false, false] => 'red',
        [false, true, false] => 'green',
        [false, false, true] => 'blue',
        [true, true, false] => 'yellow',
        default => sprintf('#%06x', $rgb),
    };
}

/** What the editor sends: Cropper.js getData(true), on the preview it showed (here half size). */
function cropperData(array $data): array
{
    return $data + ['x' => 0, 'y' => 0, 'rotate' => 0, 'scaleX' => 1, 'scaleY' => 1, 'naturalWidth' => 200, 'naturalHeight' => 100];
}

it('turns and crops the full-size photo the way the editor showed it', function () {
    $old = addQuadrantPhoto($this->vehicle);

    // Turned right, the photo is 100 × 200 in the editor; keep its top half.
    ($this->photos)()->call('editPhoto', $old->id, cropperData(['rotate' => 90, 'width' => 100, 'height' => 100]))
        ->assertNotified('Photo saved');

    $photo = ($this->onlyPhoto)();
    expect($photo->id)->not->toBe($old->id)
        ->and($photo->name)->toBe('front')
        ->and($photo->created_at->equalTo($old->created_at))->toBeTrue()
        ->and(getimagesize($photo->getPath()))->toMatchArray([0 => 200, 1 => 200, 'mime' => 'image/png'])
        // Turning right brings the bottom left (blue) to the top left, the top left (red) to the top right.
        ->and(colourAt($photo, 50, 100))->toBe('blue')
        ->and(colourAt($photo, 150, 100))->toBe('red')
        ->and(Media::query()->whereKey($old->id)->exists())->toBeFalse();
});

it('mirrors before turning, as Cropper.js does', function () {
    $old = addQuadrantPhoto($this->vehicle);

    ($this->photos)()->call('editPhoto', $old->id, cropperData(['rotate' => 90, 'scaleX' => -1, 'width' => 100, 'height' => 200]));

    $photo = ($this->onlyPhoto)();
    expect(getimagesize($photo->getPath()))->toMatchArray([0 => 200, 1 => 400])
        ->and([colourAt($photo, 50, 100), colourAt($photo, 150, 100), colourAt($photo, 50, 300), colourAt($photo, 150, 300)])
        ->toBe(['yellow', 'green', 'blue', 'red']);
});

it('keeps the edited photo in its place, so the cover stays the cover, and opens it in the viewer', function () {
    foreach (['front', 'side', 'rear'] as $name) {
        $this->vehicle->addMedia(UploadedFile::fake()->image("{$name}.jpg", 40, 30))->toMediaCollection('photos');
    }
    $front = $this->vehicle->getFirstMedia('photos');

    $photos = ($this->photos)()->call('editPhoto', $front->id, cropperData(['x' => 5, 'y' => 5, 'width' => 20, 'height' => 20, 'naturalWidth' => 40, 'naturalHeight' => 30]));

    $edited = $this->vehicle->fresh()->getFirstMedia('photos');
    expect($this->vehicle->fresh()->getMedia('photos')->pluck('name')->all())->toBe(['front', 'side', 'rear'])
        ->and($edited->id)->not->toBe($front->id)
        ->and($edited->file_name)->toBe('front.jpg')
        ->and(getimagesize($edited->getPath()))->toMatchArray([0 => 20, 1 => 20, 'mime' => 'image/jpeg']);
    $photos->assertSeeHtml("item.id === {$edited->id}");
});

it('only edits this vehicle\'s photos, with numbers the editor can produce', function () {
    $photo = addQuadrantPhoto($this->vehicle);
    $other = addQuadrantPhoto(vehicleFor(User::factory()->create()));

    ($this->photos)()->call('editPhoto', $other->id, cropperData(['width' => 100, 'height' => 100]))->assertNotFound();

    ($this->photos)()->call('editPhoto', $photo->id, cropperData(['rotate' => 45, 'scaleX' => 2, 'width' => 0, 'height' => 100]))
        ->assertHasErrors(['rotate', 'scaleX', 'width']);

    expect(($this->onlyPhoto)()->id)->toBe($photo->id)
        ->and($other->fresh())->not->toBeNull();
});

it('leaves photos it can\'t read untouched', function () {
    $photo = addQuadrantPhoto($this->vehicle);
    $photo->forceFill(['mime_type' => 'image/heic'])->save();

    ($this->photos)()->assertSeeHtml('\\u0022editable\\u0022:false')
        ->call('editPhoto', $photo->id, cropperData(['width' => 100, 'height' => 100]))
        ->assertNotified('The photo could not be saved');

    expect(($this->onlyPhoto)()->id)->toBe($photo->id);
});

it('refuses an edit made on a different image', function () {
    $photo = addQuadrantPhoto($this->vehicle);

    // A square in the editor can't be a preview of a 2:1 photo.
    ($this->photos)()->call('editPhoto', $photo->id, cropperData(['width' => 50, 'height' => 50, 'naturalHeight' => 200]))
        ->assertNotified('The photo could not be saved');

    expect(($this->onlyPhoto)()->id)->toBe($photo->id);
});
