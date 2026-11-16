<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vehicle;

/** Anyone a vehicle is shared with has full access to it (LubeLogger-style collaboration). */
class VehiclePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Vehicle $vehicle): bool
    {
        return $vehicle->isAccessibleBy($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Vehicle $vehicle): bool
    {
        return $vehicle->isAccessibleBy($user);
    }

    public function delete(User $user, Vehicle $vehicle): bool
    {
        return $vehicle->isAccessibleBy($user);
    }
}
