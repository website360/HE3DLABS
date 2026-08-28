<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Db;
use App\Dominio\Canal;

/**
 * Cache local da árvore de categorias e dos atributos de cada plataforma.
 * As duas têm rate limit, e essa informação muda pouco.
 */
final class Categorias
{
    private const VALIDADE_DIAS = 7;

    /** @return array<string,mixed>|null */
    public static function buscar(Canal $canal, string $categoriaId): ?array
    {
        $linha = Db::um(
            'SELECT * FROM cache_categorias WHERE canal = ? AND categoria_id = ?',
            [$canal->value, $categoriaId]
        );

        if ($linha === null) {
            return null;
        }

        $linha['atributos'] = self::decodificar($linha['atributos_json'] ?? null);

        return $linha;
    }

    public static function expirada(Canal $canal, string $categoriaId): bool
    {
        $linha = self::buscar($canal, $categoriaId);

        if ($linha === null || $linha['atributos'] === []) {
            return true;
        }

        return strtotime((string) $linha['atualizado_em']) < strtotime('-' . self::VALIDADE_DIAS . ' days');
    }

    /** @param array<int,array<string,mixed>> $atributos */
    public static function salvar(
        Canal $canal,
        string $categoriaId,
        string $nome,
        string $caminho = '',
        array $atributos = []
    ): void {
        Db::executar(
            'INSERT INTO cache_categorias (canal, categoria_id, nome, caminho, atributos_json)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                nome           = VALUES(nome),
                caminho        = VALUES(caminho),
                atributos_json = COALESCE(VALUES(atributos_json), atributos_json),
                atualizado_em  = CURRENT_TIMESTAMP',
            [
                $canal->value,
                $categoriaId,
                $nome,
                $caminho,
                $atributos === [] ? null : json_encode($atributos, JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function procurar(Canal $canal, string $termo, int $limite = 25): array
    {
        return Db::todos(
            'SELECT * FROM cache_categorias
              WHERE canal = ? AND (nome LIKE ? OR caminho LIKE ?)
              ORDER BY CHAR_LENGTH(caminho), nome
              LIMIT ' . max(1, $limite),
            [$canal->value, "%{$termo}%", "%{$termo}%"]
        );
    }

    /** @return array<int,array<string,mixed>> atributos obrigatórios da categoria */
    public static function atributosObrigatorios(Canal $canal, string $categoriaId): array
    {
        $categoria = self::buscar($canal, $categoriaId);

        if ($categoria === null) {
            return [];
        }

        return array_values(array_filter(
            $categoria['atributos'],
            static fn (array $a): bool => ($a['obrigatorio'] ?? false) === true
        ));
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
