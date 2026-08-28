<?php

declare(strict_types=1);

namespace Testes;

use RuntimeException;

/** Asserções mínimas. O projeto não tem dependências, e os testes também não. */
final class Afirma
{
    public static function verdadeiro(bool $condicao, string $mensagem): void
    {
        if (!$condicao) {
            throw new RuntimeException($mensagem);
        }
    }

    public static function igual(mixed $esperado, mixed $obtido, string $mensagem): void
    {
        if ($esperado !== $obtido) {
            throw new RuntimeException(sprintf(
                "%s\n     esperado: %s\n      obtido: %s",
                $mensagem,
                self::descrever($esperado),
                self::descrever($obtido)
            ));
        }
    }

    public static function contem(string $agulha, string $palheiro, string $mensagem): void
    {
        if (!str_contains($palheiro, $agulha)) {
            throw new RuntimeException("{$mensagem}\n     não encontrou '{$agulha}' em: {$palheiro}");
        }
    }

    /** Verifica que o callback lança exceção, opcionalmente com um trecho na mensagem. */
    public static function lanca(callable $callback, string $trechoEsperado, string $mensagem): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            if ($trechoEsperado !== '' && !str_contains($e->getMessage(), $trechoEsperado)) {
                throw new RuntimeException(
                    "{$mensagem}\n     lançou, mas sem '{$trechoEsperado}': {$e->getMessage()}"
                );
            }

            return;
        }

        throw new RuntimeException("{$mensagem}\n     nenhuma exceção foi lançada");
    }

    private static function descrever(mixed $valor): string
    {
        return is_scalar($valor) || $valor === null
            ? var_export($valor, true)
            : json_encode($valor, JSON_UNESCAPED_UNICODE);
    }
}
