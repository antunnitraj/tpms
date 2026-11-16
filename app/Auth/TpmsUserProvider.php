<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use SensitiveParameter;

/**
 * Users sign in with their username or email. Accounts imported from
 * LubeLogger carry an unsalted SHA-256 hash; the first successful login with
 * it replaces it with a proper bcrypt hash.
 */
class TpmsUserProvider extends EloquentUserProvider
{
    public function retrieveByCredentials(#[SensitiveParameter] array $credentials): ?Authenticatable
    {
        if (! array_key_exists('login', $credentials)) {
            return parent::retrieveByCredentials($credentials);
        }

        $login = mb_strtolower(trim((string) $credentials['login']));

        if ($login === '') {
            return null;
        }

        return $this->newModelQuery()
            ->whereRaw('lower(username) = ?', [$login])
            ->orWhereRaw('lower(email) = ?', [$login])
            ->first();
    }

    public function validateCredentials(Authenticatable $user, #[SensitiveParameter] array $credentials): bool
    {
        $password = (string) ($credentials['password'] ?? '');

        if ($user instanceof User && $user->legacy_sha256 !== null && $password !== '') {
            if (! hash_equals($user->legacy_sha256, hash('sha256', $password))) {
                return parent::validateCredentials($user, $credentials);
            }

            $user->forceFill(['password' => $password, 'legacy_sha256' => null])->save();

            return true;
        }

        return parent::validateCredentials($user, $credentials);
    }
}
