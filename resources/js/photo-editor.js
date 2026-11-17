// Cropper.js for the photo editor, loaded only when someone edits a photo.
// Its styles already come with Filament's theme.
//
// Pinned to 1.6.2, the version Filament ships. 1.7 measures every photo by adding a full-size copy
// to the page first, which makes phone browsers zoom out, so the editor came out several times too big.
import Cropper from 'cropperjs';

window.Cropper = Cropper;
