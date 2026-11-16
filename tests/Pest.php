<?php

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

/** A vehicle shared with the given user (or a fresh one). */
function vehicleFor(?User $user = null, array $attributes = []): Vehicle
{
    $user ??= User::factory()->create();
    $vehicle = Vehicle::factory()->create($attributes + ['created_by' => $user->getKey()]);
    $vehicle->users()->attach($user);

    return $vehicle;
}
