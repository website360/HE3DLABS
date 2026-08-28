<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Leitura do .env. Sem dependência externa: o formato aceito é
 * CHAVE=valor, uma por linha, com # iniciando comentário.
 */
final class Config
{
    /** @var array<string,string> */
    private static array $valores = [];

    public static function carregar(string $arquivo): void
    {
        if (!is_file($arquivo)) {
            throw new RuntimeException(
                "Arquivo .env não encontrado em {$arquivo}. Copie .env.example para .env e preencha."
            );
        }

        $linhas = file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($linhas as $linha) {
            $linha = trim($linha);
            if ($linha === '' || str_starts_with($linha, '#')) {
                continue;
            }

            $posicao = strpos($linha, '=');
            if ($posicao === false) {
                continue;
            }

            $chave = trim(substr($linha, 0, $posicao));
            $valor = trim(substr($linha, $posicao + 1));

            // Remove aspas envolventes, se houver.
            if (strlen($valor) >= 2
                && ($valor[0] === '"' || $valor[0] === "'")
                && $valor[strlen($valor) - 1] === $valor[0]
            ) {
                $valor = substr($valor, 1, -1);
            }

            self::$valores[$chave] = $valor;
        }
    }

    public static function get(string $chave, ?string $padrao = null): ?string
    {
        $valor = self::$valores[$chave] ?? null;

        return ($valor === null || $valor === '') ? $padrao : $valor;
    }

    /**
     * Para configurações sem padrão razoável, onde seguir sem valor
     * produziria um erro confuso mais adiante.
     */
    public static function obrigatorio(string $chave): string
    {
        $valor = self::$valores[$chave] ?? '';
        if ($valor === '') {
            throw new RuntimeException("Configuração obrigatória ausente no .env: {$chave}");
        }

        return $valor;
    }

    public static function bool(string $chave, bool $padrao = false): bool
    {
        $valor = self::$valores[$chave] ?? null;
        if ($valor === null) {
            return $padrao;
        }

        return in_array(strtolower($valor), ['1', 'true', 'yes', 'on', 'sim'], true);
    }

    public static function definir(string $chave, string $valor): void
    {
        self::$valores[$chave] = $valor;
    }
}
