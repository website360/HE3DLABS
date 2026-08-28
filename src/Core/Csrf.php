<?php

declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        Sessao::iniciar();

        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['_csrf'];
    }

    public static function campo(): string
    {
        return '<input type="hidden" name="_csrf" value="' . View::e(self::token()) . '">';
    }

    public static function valido(?string $enviado): bool
    {
        if ($enviado === null || $enviado === '') {
            return false;
        }

        Sessao::iniciar();
        $esperado = $_SESSION['_csrf'] ?? '';

        return is_string($esperado) && $esperado !== '' && hash_equals($esperado, $enviado);
    }

    /** Interrompe a requisição quando o token não confere. */
    public static function exigir(): void
    {
        $enviado = $_POST['_csrf'] ?? null;

        if (!self::valido(is_string($enviado) ? $enviado : null)) {
            http_response_code(419);
            exit('Sessão expirada ou requisição inválida. Recarregue a página e tente de novo.');
        }
    }
}
