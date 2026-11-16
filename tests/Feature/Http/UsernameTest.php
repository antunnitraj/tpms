<?php

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Setup;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use App\Support\Usernames;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

it('turns names into usernames', function () {
    expect(Usernames::slug('Željko Čačić'))->toBe('zeljko-cacic')
        ->and(Usernames::slug('  Marko   Horvat '))->toBe('marko-horvat')
        ->and(Usernames::suggest('???'))->toBe('user');

    User::factory()->create(['username' => 'ana-horvat']);
    expect(Usernames::suggest('Ana Horvat'))->toBe('ana-horvat2');
});

it('fills the username from the name in the setup wizard', function () {
    Livewire::test(Setup::class)
        ->fillForm(['name' => 'Marko Horvat'])
        ->assertSchemaStateSet(['username' => 'marko-horvat'])
        ->fillForm(['name' => 'Marko H'])
        ->assertSchemaStateSet(['username' => 'marko-h']);
});

it('stops following the name once the username was changed by hand', function () {
    Livewire::test(Setup::class)
        ->fillForm(['name' => 'Marko Horvat'])
        ->fillForm(['username' => 'marko'])
        ->fillForm(['name' => 'Marko Horvat Jr'])
        ->assertSchemaStateSet(['username' => 'marko']);
});

it('suggests a free username when an admin adds a user', function () {
    $admin = User::factory()->admin()->create(['username' => 'boss']);
    User::factory()->create(['username' => 'ivana-kovac']);
    $this->actingAs($admin);

    Livewire::test(ManageUsers::class)
        ->mountAction('create')
        ->fillForm(['name' => 'Ivana Kovač'])
        ->assertSchemaStateSet(['username' => 'ivana-kovac2'])
        ->fillForm(['password' => 'long-enough-1'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(User::query()->where('username', 'ivana-kovac2')->value('name'))->toBe('Ivana Kovač');
});

it('keeps a custom username when the name changes in the profile', function () {
    $user = User::factory()->create(['name' => 'Local Admin', 'username' => 'admin']);
    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->fillForm(['name' => 'Someone Else'])
        ->assertSchemaStateSet(['username' => 'admin']);
});

it('never changes the username of an existing account when its name changes', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['name' => 'QA One', 'username' => 'qa-one']);
    $this->actingAs($admin);

    Livewire::test(ManageUsers::class)
        ->mountAction(TestAction::make('edit')->table($user))
        ->fillForm(['name' => 'QA One Edited'])
        ->assertSchemaStateSet(['username' => 'qa-one']);

    $this->actingAs($user);
    Livewire::test(EditProfile::class)
        ->fillForm(['name' => 'Totally New Name'])
        ->assertSchemaStateSet(['username' => 'qa-one']);
});
