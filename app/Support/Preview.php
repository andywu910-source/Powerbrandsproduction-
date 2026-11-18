<?php

namespace App\Support;

/** The preview / staging site. Everything here is off unless APP_ENV=staging AND its flag is on. */
class Preview
{
    public static function enabled(): bool
    {
        return app()->environment('staging') && (bool) config('pbp.preview');
    }

    public static function guestLoginEnabled(): bool
    {
        return app()->environment('staging') && (bool) config('pbp.preview_guest_login');
    }
}
