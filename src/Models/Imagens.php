<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Db;
use App\Dominio\Canal;

final class Imagens
{
    public static function criar(int $produtoId, string $arquivo, ?int $variacaoId = null): int
    {
        $ordem = (int) Db::valor(
            'SELECT COALESCE(MAX(ordem), -1) + 1 FROM imagens WHERE produto_id = ?',
            [$produtoId]
        );

        return Db::inserir(
            'INSERT INTO imagens (produto_id, variacao_id, arquivo, ordem) VALUES (?, ?, ?, ?)',
            [$produtoId, $variacaoId, $arquivo, $ordem]
        );
    }

    /** @return array<string,mixed>|null */
    public static function buscar(int $id): ?array
    {
        return Db::um('SELECT * FROM imagens WHERE id = ?', [$id]);
    }

    public static function excluir(int $id): void
    {
        $imagem = self::buscar($id);

        if ($imagem === null) {
            return;
        }

        $caminho = BASE_PATH . '/public/uploads/' . $imagem['arquivo'];
        if (is_file($caminho)) {
            @unlink($caminho);
        }

        Db::executar('DELETE FROM imagens WHERE id = ?', [$id]);
    }

    public static function definirVariacao(int $imagemId, ?int $variacaoId): void
    {
        Db::executar('UPDATE imagens SET variacao_id = ? WHERE id = ?', [$variacaoId, $imagemId]);
    }

    /** @param array<int,int> $ordemIds ids na ordem desejada */
    public static function reordenar(array $ordemIds): void
    {
        foreach (array_values($ordemIds) as $posicao => $id) {
            Db::executar('UPDATE imagens SET ordem = ? WHERE id = ?', [$posicao, (int) $id]);
        }
    }

    // ------------------------------------------------------------------
    // Ids remotos (media space da Shopee)
    // ------------------------------------------------------------------

    public static function idRemoto(int $imagemId, Canal $canal): ?string
    {
        $valor = Db::valor(
            'SELECT id_remoto FROM imagens_canal WHERE imagem_id = ? AND canal = ?',
            [$imagemId, $canal->value]
        );

        return $valor === null ? null : (string) $valor;
    }

    /**
     * Grava o id devolvido pela plataforma. Chamado logo após cada upload
     * bem-sucedido, para que uma republicação não reenvie a mesma foto.
     */
    public static function registrarIdRemoto(int $imagemId, Canal $canal, string $idRemoto): void
    {
        Db::executar(
            'INSERT INTO imagens_canal (imagem_id, canal, id_remoto)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE id_remoto = VALUES(id_remoto), enviado_em = CURRENT_TIMESTAMP',
            [$imagemId, $canal->value, $idRemoto]
        );
    }

    /** @return array<int,string> id da imagem => id remoto */
    public static function idsRemotosDoProduto(int $produtoId, Canal $canal): array
    {
        $linhas = Db::todos(
            'SELECT ic.imagem_id, ic.id_remoto
               FROM imagens_canal ic
               JOIN imagens i ON i.id = ic.imagem_id
              WHERE i.produto_id = ? AND ic.canal = ?',
            [$produtoId, $canal->value]
        );

        $mapa = [];
        foreach ($linhas as $linha) {
            $mapa[(int) $linha['imagem_id']] = (string) $linha['id_remoto'];
        }

        return $mapa;
    }
}
