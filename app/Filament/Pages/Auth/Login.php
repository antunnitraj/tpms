<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class Login extends BaseLogin
{
    /**
     * Filament limits attempts per IP address; this also limits them per account
     * (10 failures in 15 minutes), so guessing from many addresses doesn't help.
     * Counted per account, so switching between username and email doesn't either.
     */
    public function authenticate(): ?LoginResponse
    {
        $login = mb_strtolower(trim((string) ($this->data['login'] ?? '')));
        $account = User::query()->where('username', $login)->orWhere('email', $login)->value('id');
        $key = 'login-account:'.($account ?? sha1($login));

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->getRateLimitedNotification(new TooManyRequestsException(static::class, 'authenticate', (string) request()->ip(), RateLimiter::availableIn($key)))?->send();

            return null;
        }

        $response = null;

        try {
            $response = parent::authenticate();
        } finally {
            $response ? RateLimiter::clear($key) : RateLimiter::hit($key, 15 * 60);
        }

        return $response;
    }

    protected function getRateLimitedNotification(TooManyRequestsException $exception): ?Notification
    {
        return Notification::make()
            ->title(__('filament-panels::auth/pages/login.notifications.throttled.title'))
            ->body($exception->secondsUntilAvailable < 60
                ? trans_choice('Please try again in :count second.|Please try again in :count seconds.', $exception->secondsUntilAvailable)
                : trans_choice('Please try again in :count minute.|Please try again in :count minutes.', $exception->minutesUntilAvailable))
            ->danger();
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('login')
            ->label(__('Username or email'))
            ->required()
            ->autocomplete('username')
            ->autofocus();
    }

    // Stay signed in by default: TPMS is mostly used as a home-screen app on phones.
    protected function getRememberFormComponent(): Component
    {
        return Checkbox::make('remember')
            ->label(__('filament-panels::auth/pages/login.form.remember.label'))
            ->default(true);
    }

    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        return [
            'login' => $data['login'],
            'password' => $data['password'],
        ];
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.login' => __('filament-panels::auth/pages/login.messages.failed'),
        ]);
    }
}
