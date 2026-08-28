<?php

declare(strict_types=1);

namespace App\Core;

/** Mensagens de uma requisição para a seguinte, através da sessão. */
final class Flash
{
    public static function sucesso(string $mensagem): void
    {
        self::definir('sucesso', $mensagem);
    }

    public static function erro(string $mensagem): void
    {
        self::definir('erro', $mensagem);
    }

    public static function aviso(string $mensagem): void
    {
        self::definir('aviso', $mensagem);
    }

    private static function definir(string $tipo, string $mensagem): void
    {
        Sessao::iniciar();
        $_SESSION['_flash'] = ['tipo' => $tipo, 'mensagem' => $mensagem];
    }

    /** @return array{tipo:string,mensagem:string}|null */
    public static function consumir(): ?array
    {
        Sessao::iniciar();
        $flash = $_SESSION['_flash'] ?? null;
        unset($_SESSION['_flash']);

        return is_array($flash) ? $flash : null;
    }
}
