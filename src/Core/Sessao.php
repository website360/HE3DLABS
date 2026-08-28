<?php

declare(strict_types=1);

namespace App\Core;

final class Sessao
{
    public static function iniciar(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        // Em CLI (cron) não há sessão, e tentar iniciar emite warning.
        if (PHP_SAPI === 'cli') {
            return;
        }

        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off'),
        ]);

        session_start();
    }
}
