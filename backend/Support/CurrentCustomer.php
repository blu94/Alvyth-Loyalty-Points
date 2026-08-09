<?php

namespace Plugin\LoyaltyPoints\Backend\Support;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Who the storefront visitor is, during a Blade render.
 *
 * Extracted the moment a second placement needed it. Two copies of this would be two places
 * to get an authentication check wrong, and they would drift the first time one was fixed —
 * a points badge that thinks you are signed in and a redemption box that does not is worse
 * than either being wrong on its own.
 *
 * **Read from the raw cookie rather than `$request->cookie()`.** `EncryptCookies` is in the
 * `web` group and this cookie is written in plaintext by the theme's login script
 * (`document.cookie = ...`), so Laravel's decryption fails and hands back null. Going to
 * `$_COOKIE` is not a shortcut past a security control — the value is a bearer token the
 * browser is meant to hold and send, and Sanctum verifies it below either way.
 *
 * The token is resolved to its owner rather than trusted as an identifier: an expired or
 * revoked token resolves to nothing, which is the same outcome as being logged out.
 *
 * Blade-render only. Anywhere a request is actually being handled — a listener, a controller
 * — `auth('sanctum')->user()` already works and should be used instead.
 */
class CurrentCustomer
{
    public static function resolve(): ?User
    {
        $raw = request()->cookie('customer_access_token') ?: ($_COOKIE['customer_access_token'] ?? null);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $token = PersonalAccessToken::findToken($raw);

        if ($token === null || ($token->expires_at !== null && $token->expires_at->isPast())) {
            return null;
        }

        $owner = $token->tokenable;

        return $owner instanceof User ? $owner : null;
    }
}
