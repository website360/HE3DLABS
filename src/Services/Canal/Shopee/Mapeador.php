<?php

declare(strict_types=1);

namespace App\Services\Canal\Shopee;

use App\Dominio\ContextoPublicacao;
use App\Dominio\Produto;
use App\Dominio\Variacao;
use App\Services\Canal\ErroPublicacao;

/**
 * Converte um produto no payload da Shopee.
 *
 * A Shopee separa o anúncio em duas chamadas: add_item cria o item base,
 * init_tier_variation cria a matriz de variações. Por isso são dois
 * métodos, ambos puros.
 */
final class Mapeador
{
    /** @return array<string,mixed> */
    public static function montarItem(Produto $produto, ContextoPublicacao $contexto): array
    {
        $referencia = self::variacaoReferencia($produto);

        $item = [
            'item_name'      => $contexto->tituloLimitado(),
            'description'    => $contexto->descricaoLimitada(),
            'category_id'    => (int) $contexto->categoriaId,
            'original_price' => round($contexto->precoDe($referencia), 2),
            'weight'         => max(0.001, $referencia->pesoKg()),
            'item_sku'       => $produto->skuBase,
            'condition'      => (string) $contexto->extra('condicao', 'NEW'),
            'item_status'    => 'NORMAL',
            'seller_stock'   => [['stock' => max(0, $referencia->estoque)]],
            'image'          => ['image_id_list' => self::idsImagens($produto, $contexto)],
            'dimension'      => self::dimensao($referencia),
            'logistic_info'  => self::logistica($contexto),
            'brand'          => self::marca($produto, $contexto),
        ];

        $atributos = self::atributos($contexto);

        if ($atributos !== []) {
            $item['attribute_list'] = $atributos;
        }

        return $item;
    }

    /**
     * Matriz de variações. tier_index aponta para as posições dentro de
     * cada option_list, então a ordem das opções importa e é fixada aqui.
     *
     * @return array<string,mixed>
     */
    public static function montarVariacoes(Produto $produto, ContextoPublicacao $contexto): array
    {
        if (!$produto->temVariacoes()) {
            throw ErroPublicacao::permanente(
                'Produto sem eixos de variação não usa init_tier_variation.',
                'variacoes'
            );
        }

        if (count($produto->eixos) > 2) {
            throw ErroPublicacao::permanente(
                'A Shopee aceita no máximo 2 eixos de variação.',
                'variacoes'
            );
        }

        // Opções de cada eixo, na ordem em que aparecem nas variações.
        $opcoesPorEixo = [];

        foreach ($produto->eixos as $eixo) {
            $opcoes = [];

            foreach ($produto->variacoes as $variacao) {
                if (!$variacao->ativo) {
                    continue;
                }

                $valor = $variacao->valorDoEixo($eixo->nome);

                if ($valor === null || $valor === '') {
                    throw ErroPublicacao::permanente(
                        "A variação {$variacao->sku} não tem valor para o eixo '{$eixo->nome}'.",
                        'variacoes'
                    );
                }

                if (!in_array($valor, $opcoes, true)) {
                    $opcoes[] = $valor;
                }
            }

            $opcoesPorEixo[$eixo->nome] = $opcoes;
        }

        $tierVariation = [];
        foreach ($produto->eixos as $eixo) {
            $tierVariation[] = [
                'name'        => $eixo->nome,
                'option_list' => array_map(
                    static fn (string $opcao): array => ['option' => $opcao],
                    $opcoesPorEixo[$eixo->nome]
                ),
            ];
        }

        $modelos = [];

        foreach ($produto->variacoes as $variacao) {
            if (!$variacao->ativo) {
                continue;
            }

            $indices = [];
            foreach ($produto->eixos as $eixo) {
                $valor = (string) $variacao->valorDoEixo($eixo->nome);
                $posicao = array_search($valor, $opcoesPorEixo[$eixo->nome], true);
                $indices[] = (int) $posicao;
            }

            $modelos[] = [
                'tier_index'     => $indices,
                'model_sku'      => $variacao->sku,
                'original_price' => round($contexto->precoDe($variacao), 2),
                'seller_stock'   => [['stock' => max(0, $variacao->estoque)]],
                'weight'         => max(0.001, $variacao->pesoKg()),
                'dimension'      => self::dimensao($variacao),
            ];
        }

        if ($modelos === []) {
            throw ErroPublicacao::permanente('Nenhuma variação ativa para publicar.', 'variacoes');
        }

        return [
            'tier_variation' => $tierVariation,
            'model'          => $modelos,
        ];
    }

    /**
     * A Shopee usa o item base como referência de preço, peso e estoque
     * mesmo quando há variações. Usar a primeira variação ativa mantém o
     * valor coerente com o que aparece na vitrine.
     */
    private static function variacaoReferencia(Produto $produto): Variacao
    {
        foreach ($produto->variacoes as $variacao) {
            if ($variacao->ativo) {
                return $variacao;
            }
        }

        throw ErroPublicacao::permanente(
            'O produto não tem nenhuma variação ativa.',
            'variacoes'
        );
    }

    /** @return array<int,string> */
    private static function idsImagens(Produto $produto, ContextoPublicacao $contexto): array
    {
        $ids = [];

        foreach (array_slice($produto->todasImagens(), 0, $contexto->canal->limiteImagens()) as $imagem) {
            $remoto = $contexto->imagemRemota($imagem);

            if ($remoto === null) {
                throw ErroPublicacao::permanente(
                    "A imagem {$imagem->arquivo} ainda não foi enviada para a media space da Shopee.",
                    'imagens'
                );
            }

            $ids[] = $remoto;
        }

        if ($ids === []) {
            throw ErroPublicacao::permanente(
                'A Shopee exige ao menos uma imagem no anúncio.',
                'imagens'
            );
        }

        return $ids;
    }

    /** Dimensões em centímetros inteiros, como a Shopee espera. */
    private static function dimensao(Variacao $variacao): array
    {
        return [
            'package_length' => max(1, (int) ceil($variacao->comprimentoCm)),
            'package_width'  => max(1, (int) ceil($variacao->larguraCm)),
            'package_height' => max(1, (int) ceil($variacao->alturaCm)),
        ];
    }

    /**
     * Sem ao menos um canal de logística habilitado, a Shopee recusa a
     * criação — e a mensagem de erro não deixa claro que é isso.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function logistica(ContextoPublicacao $contexto): array
    {
        $ids = (array) $contexto->extra('logisticas', []);
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if ($ids === []) {
            throw ErroPublicacao::permanente(
                'Nenhum canal de logística habilitado. A Shopee exige pelo menos um em logistic_info; '
                . 'escolha os canais na tela de configuração da Shopee.',
                'logistica'
            );
        }

        return array_map(
            static fn (int $id): array => ['logistic_id' => $id, 'enabled' => true],
            $ids
        );
    }

    /**
     * A marca precisa vir do catálogo da Shopee. Texto livre é recusado,
     * então sem brand_id configurado o anúncio sai como "NoBrand".
     */
    private static function marca(Produto $produto, ContextoPublicacao $contexto): array
    {
        $brandId = (int) $contexto->extra('brand_id', 0);

        return [
            'brand_id'            => $brandId,
            'original_brand_name' => $brandId === 0
                ? 'NoBrand'
                : (string) $contexto->extra('brand_nome', $produto->marca ?? 'NoBrand'),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function atributos(ContextoPublicacao $contexto): array
    {
        $lista = [];

        foreach ($contexto->atributos as $id => $valor) {
            if ($valor === null || $valor === '' || !is_numeric($id)) {
                continue;
            }

            $lista[] = [
                'attribute_id'         => (int) $id,
                'attribute_value_list' => [
                    is_array($valor)
                        ? $valor
                        : ['original_value_name' => (string) $valor],
                ],
            ];
        }

        return $lista;
    }
}
