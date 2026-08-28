<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Db;
use App\Dominio\Canal;

/**
 * Modelos de produto: guardam categoria e atributos padrão por canal.
 * É a peça que evita reconfigurar categoria a cada produto do mesmo tipo.
 */
final class Modelos
{
    /** @return array<int,array<string,mixed>> */
    public static function listar(): array
    {
        return Db::todos(
            'SELECT m.*,
                    (SELECT COUNT(*) FROM produtos p WHERE p.modelo_id = m.id) AS total_produtos,
                    (SELECT COUNT(*) FROM modelo_canal mc WHERE mc.modelo_id = m.id) AS canais_configurados
               FROM modelos_produto m
              ORDER BY m.nome'
        );
    }

    /** @return array<string,mixed>|null */
    public static function buscar(int $id): ?array
    {
        return Db::um('SELECT * FROM modelos_produto WHERE id = ?', [$id]);
    }

    public static function criar(string $nome): int
    {
        return Db::inserir('INSERT INTO modelos_produto (nome) VALUES (?)', [$nome]);
    }

    public static function renomear(int $id, string $nome): void
    {
        Db::executar('UPDATE modelos_produto SET nome = ? WHERE id = ?', [$nome, $id]);
    }

    public static function excluir(int $id): void
    {
        Db::executar('DELETE FROM modelos_produto WHERE id = ?', [$id]);
    }

    /** @return array<string,array<string,mixed>> canal => configuração */
    public static function configuracoes(int $modeloId): array
    {
        $linhas = Db::todos('SELECT * FROM modelo_canal WHERE modelo_id = ?', [$modeloId]);

        $mapa = [];
        foreach ($linhas as $linha) {
            $linha['atributos'] = self::decodificar($linha['atributos_json'] ?? null);
            $mapa[(string) $linha['canal']] = $linha;
        }

        return $mapa;
    }

    /** @return array<string,mixed>|null */
    public static function configuracao(int $modeloId, Canal $canal): ?array
    {
        $linha = Db::um(
            'SELECT * FROM modelo_canal WHERE modelo_id = ? AND canal = ?',
            [$modeloId, $canal->value]
        );

        if ($linha === null) {
            return null;
        }

        $linha['atributos'] = self::decodificar($linha['atributos_json'] ?? null);

        return $linha;
    }

    /** @param array<string,mixed> $atributos */
    public static function salvarConfiguracao(
        int $modeloId,
        Canal $canal,
        string $categoriaId,
        string $categoriaNome,
        array $atributos
    ): void {
        Db::executar(
            'INSERT INTO modelo_canal (modelo_id, canal, categoria_id_remota, categoria_nome, atributos_json)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                categoria_id_remota = VALUES(categoria_id_remota),
                categoria_nome      = VALUES(categoria_nome),
                atributos_json      = VALUES(atributos_json)',
            [
                $modeloId,
                $canal->value,
                $categoriaId,
                $categoriaNome,
                json_encode($atributos, JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    /** @return array<string,mixed> */
    private static function decodificar(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }

        $decodificado = json_decode($json, true);

        return is_array($decodificado) ? $decodificado : [];
    }
}
