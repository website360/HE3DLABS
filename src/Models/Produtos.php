<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Db;
use App\Dominio\Eixo;
use App\Dominio\Imagem;
use App\Dominio\Produto;
use App\Dominio\Variacao;

final class Produtos
{
    /** @return array<int,array<string,mixed>> */
    public static function listar(?string $busca = null, ?string $status = null): array
    {
        $sql = 'SELECT p.*, m.nome AS modelo_nome,
                       (SELECT COUNT(*) FROM variacoes v WHERE v.produto_id = p.id) AS total_variacoes,
                       (SELECT COALESCE(SUM(v.estoque), 0) FROM variacoes v WHERE v.produto_id = p.id) AS estoque_total,
                       (SELECT MIN(v.preco) FROM variacoes v WHERE v.produto_id = p.id AND v.ativo = 1) AS preco_min,
                       (SELECT i.arquivo FROM imagens i WHERE i.produto_id = p.id ORDER BY i.ordem, i.id LIMIT 1) AS capa
                FROM produtos p
                LEFT JOIN modelos_produto m ON m.id = p.modelo_id
                WHERE 1 = 1';

        $parametros = [];

        if ($busca !== null) {
            $sql .= ' AND (p.titulo LIKE ? OR p.sku_base LIKE ?)';
            $parametros[] = "%{$busca}%";
            $parametros[] = "%{$busca}%";
        }

        if ($status !== null) {
            $sql .= ' AND p.status = ?';
            $parametros[] = $status;
        }

        $sql .= ' ORDER BY p.atualizado_em DESC';

        return Db::todos($sql, $parametros);
    }

    /** @return array<string,mixed>|null */
    public static function buscar(int $id): ?array
    {
        return Db::um('SELECT * FROM produtos WHERE id = ?', [$id]);
    }

    public static function existeSku(string $sku, ?int $ignorarId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM produtos WHERE sku_base = ?';
        $parametros = [$sku];

        if ($ignorarId !== null) {
            $sql .= ' AND id <> ?';
            $parametros[] = $ignorarId;
        }

        return (int) Db::valor($sql, $parametros) > 0;
    }

    public static function criar(array $dados): int
    {
        return Db::inserir(
            'INSERT INTO produtos (sku_base, titulo, descricao, marca, modelo_id, status)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $dados['sku_base'],
                $dados['titulo'],
                $dados['descricao'] ?? null,
                $dados['marca'] ?? null,
                $dados['modelo_id'] ?? null,
                $dados['status'] ?? 'rascunho',
            ]
        );
    }

    public static function atualizar(int $id, array $dados): void
    {
        Db::executar(
            'UPDATE produtos
                SET sku_base = ?, titulo = ?, descricao = ?, marca = ?, modelo_id = ?, status = ?
              WHERE id = ?',
            [
                $dados['sku_base'],
                $dados['titulo'],
                $dados['descricao'] ?? null,
                $dados['marca'] ?? null,
                $dados['modelo_id'] ?? null,
                $dados['status'] ?? 'rascunho',
                $id,
            ]
        );
    }

    public static function excluir(int $id): void
    {
        Db::executar('DELETE FROM produtos WHERE id = ?', [$id]);
    }

    // ------------------------------------------------------------------
    // Eixos e variações
    // ------------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public static function eixos(int $produtoId): array
    {
        return Db::todos(
            'SELECT * FROM eixos_variacao WHERE produto_id = ? ORDER BY ordem',
            [$produtoId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function variacoes(int $produtoId): array
    {
        return Db::todos(
            'SELECT * FROM variacoes WHERE produto_id = ? ORDER BY id',
            [$produtoId]
        );
    }

    /** @return array<int,array<string,mixed>> valores de variação com o nome do eixo */
    public static function valoresDeVariacao(int $produtoId): array
    {
        return Db::todos(
            'SELECT vv.*, e.nome AS eixo_nome, e.ordem AS eixo_ordem
               FROM variacao_valores vv
               JOIN eixos_variacao e ON e.id = vv.eixo_id
               JOIN variacoes v ON v.id = vv.variacao_id
              WHERE v.produto_id = ?
              ORDER BY e.ordem',
            [$produtoId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function imagens(int $produtoId): array
    {
        return Db::todos(
            'SELECT * FROM imagens WHERE produto_id = ? ORDER BY ordem, id',
            [$produtoId]
        );
    }

    /**
     * Substitui os eixos do produto. Só deve ser chamado quando não há
     * variações, porque apagar um eixo derruba em cascata os valores.
     *
     * @param array<int,string> $nomes
     */
    public static function definirEixos(int $produtoId, array $nomes): void
    {
        $nomes = array_values(array_filter(array_map('trim', $nomes), static fn ($n) => $n !== ''));

        if (count($nomes) > 2) {
            throw new \InvalidArgumentException(
                'Um produto aceita no máximo 2 eixos de variação, limite da Shopee.'
            );
        }

        Db::executar('DELETE FROM eixos_variacao WHERE produto_id = ?', [$produtoId]);

        foreach ($nomes as $indice => $nome) {
            Db::executar(
                'INSERT INTO eixos_variacao (produto_id, nome, ordem) VALUES (?, ?, ?)',
                [$produtoId, $nome, $indice + 1]
            );
        }
    }

    public static function criarVariacao(int $produtoId, array $dados): int
    {
        return Db::inserir(
            'INSERT INTO variacoes
                (produto_id, sku, preco, estoque, peso_g, comprimento_cm, largura_cm, altura_cm, gtin, ativo)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $produtoId,
                $dados['sku'],
                $dados['preco'] ?? 0,
                $dados['estoque'] ?? 0,
                $dados['peso_g'] ?? 0,
                $dados['comprimento_cm'] ?? 0,
                $dados['largura_cm'] ?? 0,
                $dados['altura_cm'] ?? 0,
                $dados['gtin'] ?? null,
                isset($dados['ativo']) ? (int) $dados['ativo'] : 1,
            ]
        );
    }

    public static function atualizarVariacao(int $id, array $dados): void
    {
        Db::executar(
            'UPDATE variacoes
                SET sku = ?, preco = ?, estoque = ?, peso_g = ?,
                    comprimento_cm = ?, largura_cm = ?, altura_cm = ?, gtin = ?, ativo = ?
              WHERE id = ?',
            [
                $dados['sku'],
                $dados['preco'] ?? 0,
                $dados['estoque'] ?? 0,
                $dados['peso_g'] ?? 0,
                $dados['comprimento_cm'] ?? 0,
                $dados['largura_cm'] ?? 0,
                $dados['altura_cm'] ?? 0,
                $dados['gtin'] ?? null,
                isset($dados['ativo']) ? (int) $dados['ativo'] : 1,
                $id,
            ]
        );
    }

    public static function excluirVariacao(int $id): void
    {
        Db::executar('DELETE FROM variacoes WHERE id = ?', [$id]);
    }

    public static function definirValorDeVariacao(int $variacaoId, int $eixoId, string $valor): void
    {
        Db::executar(
            'INSERT INTO variacao_valores (variacao_id, eixo_id, valor)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)',
            [$variacaoId, $eixoId, $valor]
        );
    }

    public static function existeSkuVariacao(string $sku, ?int $ignorarId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM variacoes WHERE sku = ?';
        $parametros = [$sku];

        if ($ignorarId !== null) {
            $sql .= ' AND id <> ?';
            $parametros[] = $ignorarId;
        }

        return (int) Db::valor($sql, $parametros) > 0;
    }

    // ------------------------------------------------------------------
    // Montagem do agregado de domínio
    // ------------------------------------------------------------------

    /**
     * Monta o objeto de domínio completo, que é o que os mapeadores
     * consomem. Uma consulta por coleção, sem N+1.
     */
    public static function montar(int $id): ?Produto
    {
        $linha = self::buscar($id);

        if ($linha === null) {
            return null;
        }

        $eixos = [];
        foreach (self::eixos($id) as $e) {
            $eixos[(int) $e['id']] = new Eixo((int) $e['id'], (string) $e['nome'], (int) $e['ordem']);
        }

        // Agrupa valores por variação: "Cor" => "Preto".
        $valoresPorVariacao = [];
        foreach (self::valoresDeVariacao($id) as $v) {
            $valoresPorVariacao[(int) $v['variacao_id']][(string) $v['eixo_nome']] = (string) $v['valor'];
        }

        // Agrupa imagens: as sem variacao_id pertencem ao produto.
        $imagensProduto = [];
        $imagensPorVariacao = [];

        foreach (self::imagens($id) as $i) {
            $imagem = new Imagem(
                (int) $i['id'],
                (string) $i['arquivo'],
                (int) $i['ordem'],
                $i['variacao_id'] === null ? null : (int) $i['variacao_id']
            );

            if ($imagem->variacaoId === null) {
                $imagensProduto[] = $imagem;
            } else {
                $imagensPorVariacao[$imagem->variacaoId][] = $imagem;
            }
        }

        $variacoes = [];
        foreach (self::variacoes($id) as $v) {
            $variacaoId = (int) $v['id'];

            $variacoes[] = new Variacao(
                $variacaoId,
                (string) $v['sku'],
                (float) $v['preco'],
                (int) $v['estoque'],
                (int) $v['peso_g'],
                (float) $v['comprimento_cm'],
                (float) $v['largura_cm'],
                (float) $v['altura_cm'],
                $v['gtin'] !== null ? (string) $v['gtin'] : null,
                (bool) $v['ativo'],
                $valoresPorVariacao[$variacaoId] ?? [],
                $imagensPorVariacao[$variacaoId] ?? []
            );
        }

        return new Produto(
            (int) $linha['id'],
            (string) $linha['sku_base'],
            (string) $linha['titulo'],
            (string) ($linha['descricao'] ?? ''),
            $linha['marca'] !== null ? (string) $linha['marca'] : null,
            array_values($eixos),
            $variacoes,
            $imagensProduto
        );
    }
}
