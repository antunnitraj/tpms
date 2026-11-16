<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

class Usernames
{
    /** "Željko Čačić" → "zeljko-cacic"; only letters, digits and dashes so it passes alpha_dash. */
    public static function slug(?string $name): string
    {
        return Str::of((string) $name)->slug('-')->limit(50, '')->trim('-')->toString();
    }

    /** A free username derived from a name: "marko-horvat", then "marko-horvat2", … */
    public static function suggest(?string $name, ?int $ignoreUserId = null): string
    {
        $base = static::slug($name) ?: 'user';
        $candidate = $base;
        $i = 2;

        while (User::query()
            ->whereRaw('lower(username) = ?', [mb_strtolower($candidate)])
            ->when($ignoreUserId, fn ($query) => $query->whereKeyNot($ignoreUserId))
            ->exists()) {
            $candidate = Str::limit($base, 47, '').$i++;
        }

        return $candidate;
    }
}
