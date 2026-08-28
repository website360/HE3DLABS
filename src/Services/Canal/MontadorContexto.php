<?php

declare(strict_types=1);

namespace App\Services\Canal;

use App\Core\Config;
use App\Core\Db;
use App\Dominio\Canal;
use App\Dominio\ContextoPublicacao;
use App\Dominio\Produto;
use App\Models\Contas;
use App\Models\Imagens;
use App\Models\Modelos;
use App\Models\Produtos;

/**
 * Reúne tudo que é específico do canal — categoria, atributos, título e
 * descrição efetivos, preços com markup — num objeto só.
 *
 * Fica fora dos mapeadores de propósito: é aqui que se toca o banco,
 * para que lá seja função pura.
 */
final class MontadorContexto
{
    public static function montar(Produto $produto, Canal $canal): ContextoPublicacao
    {
        $linhaProduto = Produtos::buscar($produto->id);
        $modeloId = $linhaProduto['modelo_id'] ?? null;

        $configuracao = $modeloId !== null
            ? Modelos::configuracao((int) $modeloId, $canal)
            : null;

        if ($configuracao === null) {
            throw ErroPublicacao::permanente(
                "O produto não tem categoria configurada para {$canal->rotulo()}. "
                . 'Defina um modelo de produto com a categoria deste canal.',
                'contexto'
            );
        }

        $conteudo = self::conteudo($produto->id, $canal);
        $conta = Contas::buscar($canal);
        $markup = (float) ($conta['markup_percentual'] ?? 0);

        return new ContextoPublicacao(
            canal: $canal,
            categoriaId: (string) $configuracao['categoria_id_remota'],
            titulo: $conteudo['titulo'] ?? $produto->titulo,
            descricao: $conteudo['descricao'] ?? $produto->descricao,
            atributos: self::atributos($configuracao),
            precos: self::precos($produto, $canal, $markup),
            imagensRemotas: Imagens::idsRemotosDoProduto($produto->id, $canal),
            baseUrl: (string) Config::get('APP_URL', ''),
            extra: self::extras($configuracao, $conta),
        );
    }

    /** @return array{titulo:?string,descricao:?string} */
    private static function conteudo(int $produtoId, Canal $canal): array
    {
        $linha = Db::um(
            'SELECT titulo, descricao FROM produto_canal_conteudo WHERE produto_id = ? AND canal = ?',
            [$produtoId, $canal->value]
        );

        return [
            'titulo'    => ($linha['titulo'] ?? null) ?: null,
            'descricao' => ($linha['descricao'] ?? null) ?: null,
        ];
    }

    /**
     * Preço por variação: override explícito do canal, ou o preço base
     * com o markup do canal aplicado.
     *
     * @return array<int,float>
     */
    private static function precos(Produto $produto, Canal $canal, float $markup): array
    {
        $overrides = [];

        foreach (Db::todos(
            'SELECT pc.variacao_id, pc.preco
               FROM precos_canal pc
               JOIN variacoes v ON v.id = pc.variacao_id
              WHERE v.produto_id = ? AND pc.canal = ?',
            [$produto->id, $canal->value]
        ) as $linha) {
            $overrides[(int) $linha['variacao_id']] = (float) $linha['preco'];
        }

        $precos = [];

        foreach ($produto->variacoes as $variacao) {
            $precos[$variacao->id] = $overrides[$variacao->id]
                ?? round($variacao->preco * (1 + $markup / 100), 2);
        }

        return $precos;
    }

    /** @param array<string,mixed> $configuracao */
    private static function atributos(array $configuracao): array
    {
        $atributos = $configuracao['atributos'] ?? [];

        if (!is_array($atributos)) {
            return [];
        }

        // As chaves reservadas descrevem o canal, não são atributos de categoria.
        return array_diff_key($atributos, array_flip(self::CHAVES_RESERVADAS));
    }

    private const CHAVES_RESERVADAS = [
        'eixos', 'logisticas', 'brand_id', 'brand_nome', 'condicao',
        'tipo_anuncio', 'modo_envio', 'frete_gratis', 'retirada_local',
    ];

    /**
     * Configurações do canal que não são atributos de categoria:
     * mapeamento de eixos, logística da Shopee, tipo de anúncio do ML.
     *
     * @param array<string,mixed>      $configuracao
     * @param array<string,mixed>|null $conta
     * @return array<string,mixed>
     */
    private static function extras(array $configuracao, ?array $conta): array
    {
        $atributos = is_array($configuracao['atributos'] ?? null) ? $configuracao['atributos'] : [];
        $extras = array_intersect_key($atributos, array_flip(self::CHAVES_RESERVADAS));

        // Logística configurada uma vez na conta vale para todos os produtos.
        if (!isset($extras['logisticas']) && isset($conta['extra']['logisticas'])) {
            $extras['logisticas'] = $conta['extra']['logisticas'];
        }

        return $extras;
    }
}
