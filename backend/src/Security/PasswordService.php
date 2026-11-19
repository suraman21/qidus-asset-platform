<?php
declare(strict_types=1);

namespace QidusAsset\Security;

final class PasswordService
{
    public static function hash(string $plain): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        return password_hash($plain, $algo);
    }

    public static function verify(string $plain, ?string $hash): bool
    {
        if (!is_string($hash) || $hash === '') {
            return false;
        }
        return password_verify($plain, $hash);
    }
}
