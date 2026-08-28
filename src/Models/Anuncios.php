<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Db;
use App\Dominio\Canal;

final class Anuncios
{
    /**
     * Devolve o anúncio do produto no canal, criando a linha em estado
     * "não publicado" se ainda não existir. Serve de âncora para a fila.
     */
    public static function garantir(int $produtoId, Canal $canal): array
    {
        $anuncio = self::buscar($produtoId, $canal);

        if ($anuncio !== null) {
            return $anuncio;
        }

        Db::executar(
            'INSERT INTO anuncios (produto_id, canal, status) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
            [$produtoId, $canal->value, 'nao_publicado']
        );

        $anuncio = self::buscar($produtoId, $canal);

        if ($anuncio === null) {
            throw new \RuntimeException('Falha ao criar o registro de anúncio.');
        }

        return $anuncio;
    }

    /** @return array<string,mixed>|null */
    public static function buscar(int $produtoId, Canal $canal): ?array
    {
        return Db::um(
            'SELECT * FROM anuncios WHERE produto_id = ? AND canal = ?',
            [$produtoId, $canal->value]
        );
    }

    /** @return array<string,mixed>|null */
    public static function porId(int $id): ?array
    {
        return Db::um('SELECT * FROM anuncios WHERE id = ?', [$id]);
    }

    /** @return array<string,array<string,mixed>> canal => anúncio */
    public static function doProduto(int $produtoId): array
    {
        $linhas = Db::todos('SELECT * FROM anuncios WHERE produto_id = ?', [$produtoId]);

        $mapa = [];
        foreach ($linhas as $linha) {
            $mapa[(string) $linha['canal']] = $linha;
        }

        return $mapa;
    }

    public static function marcarNaFila(int $id): void
    {
        Db::executar(
            "UPDATE anuncios SET status = 'na_fila', ultimo_erro = NULL WHERE id = ?",
            [$id]
        );
    }

    public static function marcarPublicado(int $id, string $idRemoto, ?string $url): void
    {
        Db::executar(
            "UPDATE anuncios
                SET status = 'publicado', id_remoto = ?, url = ?, ultimo_erro = NULL,
                    publicado_em = COALESCE(publicado_em, NOW())
              WHERE id = ?",
            [$idRemoto, $url, $id]
        );
    }

    public static function marcarErro(int $id, string $erro): void
    {
        Db::executar(
            "UPDATE anuncios SET status = 'erro', ultimo_erro = ? WHERE id = ?",
            [mb_substr($erro, 0, 4000), $id]
        );
    }

    /**
     * Guarda o id remoto da variação assim que a plataforma o devolve.
     * Sem isso não há como atualizar a variação depois.
     */
    public static function registrarVariacao(int $anuncioId, int $variacaoId, ?string $idRemoto): void
    {
        Db::executar(
            'INSERT INTO anuncio_variacoes (anuncio_id, variacao_id, id_remoto)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE id_remoto = VALUES(id_remoto)',
            [$anuncioId, $variacaoId, $idRemoto]
        );
    }

    /** @return array<int,string> id da variação => id remoto */
    public static function variacoesRemotas(int $anuncioId): array
    {
        $linhas = Db::todos(
            'SELECT variacao_id, id_remoto FROM anuncio_variacoes WHERE anuncio_id = ?',
            [$anuncioId]
        );

        $mapa = [];
        foreach ($linhas as $linha) {
            if ($linha['id_remoto'] !== null) {
                $mapa[(int) $linha['variacao_id']] = (string) $linha['id_remoto'];
            }
        }

        return $mapa;
    }

    /** @return array<int,array<string,mixed>> */
    public static function comProblema(): array
    {
        return Db::todos(
            "SELECT a.*, p.titulo, p.sku_base
               FROM anuncios a
               JOIN produtos p ON p.id = a.produto_id
              WHERE a.status = 'erro'
              ORDER BY a.atualizado_em DESC"
        );
    }

    /** @return array<string,int> contagem por status */
    public static function resumo(): array
    {
        $linhas = Db::todos('SELECT status, COUNT(*) AS total FROM anuncios GROUP BY status');

        $resumo = ['nao_publicado' => 0, 'na_fila' => 0, 'publicado' => 0, 'erro' => 0];

        foreach ($linhas as $linha) {
            $resumo[(string) $linha['status']] = (int) $linha['total'];
        }

        return $resumo;
    }
}
