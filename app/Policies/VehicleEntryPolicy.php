<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared policy for everything that belongs to a vehicle (log entries, fuel,
 * reminders, plans, notes, equipment, supplies…): access follows the vehicle.
 */
class VehicleEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Model $entry): bool
    {
        return $entry->isAccessibleBy($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Model $entry): bool
    {
        return $entry->isAccessibleBy($user);
    }

    public function delete(User $user, Model $entry): bool
    {
        return $entry->isAccessibleBy($user);
    }

    public function deleteAny(User $user): bool
    {
        return true;
    }
}
