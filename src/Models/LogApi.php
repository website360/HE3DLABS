<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Db;
use App\Dominio\Canal;

final class LogApi
{
    private const LIMITE_CORPO = 60000;

    public static function registrar(
        Canal $canal,
        string $metodo,
        string $endpoint,
        ?int $status,
        ?string $requisicao,
        ?string $resposta,
        int $duracaoMs,
        ?string $erro = null
    ): void {
        Db::executar(
            'INSERT INTO log_api (canal, metodo, endpoint, http_status, requisicao, resposta, duracao_ms, erro)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $canal->value,
                $metodo,
                mb_substr($endpoint, 0, 500),
                $status,
                self::truncar($requisicao),
                self::truncar($resposta),
                $duracaoMs,
                $erro !== null ? mb_substr($erro, 0, 500) : null,
            ]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function listar(?string $canal = null, int $limite = 100): array
    {
        $sql = 'SELECT * FROM log_api';
        $parametros = [];

        if ($canal !== null) {
            $sql .= ' WHERE canal = ?';
            $parametros[] = $canal;
        }

        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, $limite);

        return Db::todos($sql, $parametros);
    }

    /** @return array<string,mixed>|null */
    public static function buscar(int $id): ?array
    {
        return Db::um('SELECT * FROM log_api WHERE id = ?', [$id]);
    }

    /** Retenção de 30 dias, chamada pelo cron de tokens. */
    public static function limpar(int $dias = 30): int
    {
        return Db::executar(
            'DELETE FROM log_api WHERE criado_em < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$dias]
        );
    }

    private static function truncar(?string $texto): ?string
    {
        if ($texto === null) {
            return null;
        }

        return mb_strlen($texto) > self::LIMITE_CORPO
            ? mb_substr($texto, 0, self::LIMITE_CORPO) . "\n… [truncado]"
            : $texto;
    }
}
