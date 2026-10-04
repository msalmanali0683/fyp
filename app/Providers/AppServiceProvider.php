<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($appUrl = config('app.url')) {
            URL::forceRootUrl($appUrl);
        }

        // This app is a single-origin Vue SPA served by routes/web.php's
        // catch-all, not the default Laravel password-reset Blade view, so the
        // emailed link must point at the SPA's own /reset-password route.
        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            $email = urlencode($notifiable->getEmailForPasswordReset());

            return rtrim(config('app.url'), '/')."/reset-password?token={$token}&email={$email}";
        });
    }
}
