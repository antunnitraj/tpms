<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** User management and imports are admin-only. */
class AdminOnlyPolicy
{
    public function viewAny(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    public function view(User $user, Model $model): bool
    {
        return (bool) $user->is_admin;
    }

    public function create(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    public function update(User $user, Model $model): bool
    {
        return (bool) $user->is_admin;
    }

    public function delete(User $user, Model $model): bool
    {
        // Admins can't delete themselves; that could lock everyone out.
        return (bool) $user->is_admin && ! $model->is($user);
    }

    public function deleteAny(User $user): bool
    {
        return (bool) $user->is_admin;
    }
}
