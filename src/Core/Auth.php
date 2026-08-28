<?php

declare(strict_types=1);

namespace App\Core;

final class Auth
{
    /** @var array<string,mixed>|null cache do usuário dentro da requisição */
    private static ?array $cache = null;

    public static function tentar(string $email, string $senha): bool
    {
        $usuario = Db::um('SELECT * FROM usuarios WHERE email = ? AND ativo = 1', [$email]);

        if ($usuario === null || !password_verify($senha, (string) $usuario['senha_hash'])) {
            return false;
        }

        // Reidrata o hash se o custo padrão do PHP tiver mudado.
        if (password_needs_rehash((string) $usuario['senha_hash'], PASSWORD_DEFAULT)) {
            Db::executar(
                'UPDATE usuarios SET senha_hash = ? WHERE id = ?',
                [password_hash($senha, PASSWORD_DEFAULT), $usuario['id']]
            );
        }

        Sessao::iniciar();
        session_regenerate_id(true);
        $_SESSION['usuario_id'] = (int) $usuario['id'];
        self::$cache = $usuario;

        return true;
    }

    public static function logado(): bool
    {
        return self::usuario() !== null;
    }

    /** @return array<string,mixed>|null */
    public static function usuario(): ?array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        Sessao::iniciar();
        $id = $_SESSION['usuario_id'] ?? null;

        if (!is_int($id)) {
            return null;
        }

        $usuario = Db::um('SELECT * FROM usuarios WHERE id = ? AND ativo = 1', [$id]);

        if ($usuario === null) {
            self::sair();

            return null;
        }

        return self::$cache = $usuario;
    }

    public static function sair(): void
    {
        Sessao::iniciar();
        self::$cache = null;
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /** Barra a requisição quando não há sessão ativa. */
    public static function exigir(): void
    {
        if (self::logado()) {
            return;
        }

        header('Location: /login');
        exit;
    }
}
