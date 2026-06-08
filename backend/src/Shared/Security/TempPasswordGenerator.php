<?php

declare(strict_types=1);

namespace App\Shared\Security;

/**
 * Génère un mot de passe temporaire fort (16 caractères) garantissant
 * au moins 1 caractère de chaque catégorie : minuscule, majuscule,
 * chiffre, caractère spécial.
 *
 * Factorisé pour `StaffController`, `OnboardingService::provision()`
 * (Sprint 13bis-B), `CreateSuperAdminCommand`.
 */
final class TempPasswordGenerator
{
    public function generate(int $length = 16): string
    {
        $lower   = 'abcdefghijklmnopqrstuvwxyz';
        $upper   = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $digits  = '0123456789';
        $special = '!@#$%^&*-_=+';
        $chars   = $lower . $upper . $digits . $special;

        if ($length < 4) {
            $length = 4;
        }

        $pwd = [
            $lower[random_int(0, strlen($lower) - 1)],
            $upper[random_int(0, strlen($upper) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
            $special[random_int(0, strlen($special) - 1)],
        ];
        for ($i = 4; $i < $length; $i++) {
            $pwd[] = $chars[random_int(0, strlen($chars) - 1)];
        }
        shuffle($pwd);

        return implode('', $pwd);
    }
}
