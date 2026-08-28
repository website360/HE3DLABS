<?php

declare(strict_types=1);

namespace App\Services\Canal\MercadoLivre;

use App\Dominio\ContextoPublicacao;
use App\Dominio\Produto;
use App\Dominio\Variacao;
use App\Services\Canal\ErroPublicacao;

/**
 * Converte um produto no payload de item do Mercado Livre.
 *
 * Função pura: recebe domínio, devolve array. Sem banco, sem rede, sem
 * relógio. É o que permite testar a parte mais frágil da integração de graça.
 */
final class Mapeador
{
    /**
     * Nomes de eixo em português para os ids de atributo do Mercado Livre.
     * Cobre os casos comuns; o resto vem do mapeamento configurado no modelo.
     */
    private const EIXOS_CONHECIDOS = [
        'cor'       => 'COLOR',
        'cores'     => 'COLOR',
        'tamanho'   => 'SIZE',
        'material'  => 'MATERIAL',
        'modelo'    => 'MODEL',
        'voltagem'  => 'VOLTAGE',
        'capacidade' => 'CAPACITY',
        'formato'   => 'FORMAT',
    ];

    /** @return array<string,mixed> */
    public static function montarItem(Produto $produto, ContextoPublicacao $contexto): array
    {
        $item = [
            'title'           => $contexto->tituloLimitado(),
            'category_id'     => $contexto->categoriaId,
            'currency_id'     => 'BRL',
            'buying_mode'     => 'buy_it_now',
            'condition'       => (string) $contexto->extra('condicao', 'new'),
            'listing_type_id' => (string) $contexto->extra('tipo_anuncio', 'gold_special'),
            'pictures'        => self::imagens($produto, $contexto),
            'attributes'      => self::atributos($produto, $contexto),
        ];

        $modoEnvio = (string) $contexto->extra('modo_envio', 'me2');
        $item['shipping'] = [
            'mode'          => $modoEnvio,
            'local_pick_up' => (bool) $contexto->extra('retirada_local', false),
            'free_shipping' => (bool) $contexto->extra('frete_gratis', false),
        ];

        if ($produto->temVariacoes()) {
            // Com variações, preço e estoque vivem dentro de cada variação.
            $item['variations'] = self::variacoes($produto, $contexto);

            return $item;
        }

        $variacao = $produto->variacaoUnica();
        $item['price'] = round($contexto->precoDe($variacao), 2);
        $item['available_quantity'] = max(0, $variacao->estoque);

        return $item;
    }

    /**
     * A descrição é uma chamada separada, feita depois que o item existe.
     *
     * @return array<string,string>
     */
    public static function montarDescricao(ContextoPublicacao $contexto): array
    {
        return ['plain_text' => $contexto->descricaoLimitada()];
    }

    /** @return array<int,array<string,string>> */
    private static function imagens(Produto $produto, ContextoPublicacao $contexto): array
    {
        $imagens = array_slice($produto->todasImagens(), 0, $contexto->canal->limiteImagens());

        if ($imagens === []) {
            throw ErroPublicacao::permanente(
                'O Mercado Livre exige ao menos uma imagem no anúncio.',
                'imagens'
            );
        }

        return array_map(
            static fn ($imagem): array => ['source' => $imagem->url($contexto->baseUrl)],
            $imagens
        );
    }

    /** @return array<int,array<string,mixed>> */
    private static function atributos(Produto $produto, ContextoPublicacao $contexto): array
    {
        $atributos = [];

        // Atributos configurados no modelo de produto.
        foreach ($contexto->atributos as $id => $valor) {
            if ($valor === null || $valor === '') {
                continue;
            }

            $atributos[] = is_array($valor)
                ? ['id' => (string) $id] + $valor
                : ['id' => (string) $id, 'value_name' => (string) $valor];
        }

        $jaTem = static function (string $id) use (&$atributos): bool {
            foreach ($atributos as $atributo) {
                if (($atributo['id'] ?? '') === $id) {
                    return true;
                }
            }

            return false;
        };

        if ($produto->marca !== null && !$jaTem('BRAND')) {
            $atributos[] = ['id' => 'BRAND', 'value_name' => $produto->marca];
        }

        // Produto simples leva SKU, GTIN e medidas no próprio item.
        // Com variações, isso vai dentro de cada variação.
        if (!$produto->temVariacoes()) {
            $variacao = $produto->variacaoUnica();

            if (!$jaTem('SELLER_SKU')) {
                $atributos[] = ['id' => 'SELLER_SKU', 'value_name' => $variacao->sku];
            }

            foreach (self::medidas($variacao) as $medida) {
                if (!$jaTem($medida['id'])) {
                    $atributos[] = $medida;
                }
            }

            if ($variacao->gtin !== null && !$jaTem('GTIN')) {
                $atributos[] = ['id' => 'GTIN', 'value_name' => $variacao->gtin];
            }
        }

        return $atributos;
    }

    /**
     * Peso e dimensões da embalagem. O Mercado Livre espera o valor com a
     * unidade no texto, e usa isso para cotar o frete do Mercado Envios.
     *
     * @return array<int,array<string,string>>
     */
    private static function medidas(Variacao $variacao): array
    {
        return [
            ['id' => 'PACKAGE_WEIGHT', 'value_name' => $variacao->pesoG . ' g'],
            ['id' => 'PACKAGE_LENGTH', 'value_name' => self::cm($variacao->comprimentoCm)],
            ['id' => 'PACKAGE_WIDTH',  'value_name' => self::cm($variacao->larguraCm)],
            ['id' => 'PACKAGE_HEIGHT', 'value_name' => self::cm($variacao->alturaCm)],
        ];
    }

    private static function cm(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 2, '.', ''), '0'), '.') . ' cm';
    }

    /** @return array<int,array<string,mixed>> */
    private static function variacoes(Produto $produto, ContextoPublicacao $contexto): array
    {
        /** @var array<string,string> $mapaEixos */
        $mapaEixos = (array) $contexto->extra('eixos', []);
        $payload = [];

        foreach ($produto->variacoes as $variacao) {
            if (!$variacao->ativo) {
                continue;
            }

            $combinacoes = [];

            foreach ($produto->eixos as $eixo) {
                $valor = $variacao->valorDoEixo($eixo->nome);

                if ($valor === null || $valor === '') {
                    throw ErroPublicacao::permanente(
                        "A variação {$variacao->sku} não tem valor para o eixo '{$eixo->nome}'.",
                        'variacoes'
                    );
                }

                $combinacoes[] = [
                    'id'         => self::idDoEixo($eixo->nome, $mapaEixos),
                    'value_name' => $valor,
                ];
            }

            $itemVariacao = [
                'attribute_combinations' => $combinacoes,
                'price'                  => round($contexto->precoDe($variacao), 2),
                'available_quantity'     => max(0, $variacao->estoque),
                'attributes'             => array_merge(
                    [['id' => 'SELLER_SKU', 'value_name' => $variacao->sku]],
                    self::medidas($variacao)
                ),
            ];

            if ($variacao->gtin !== null) {
                $itemVariacao['attributes'][] = ['id' => 'GTIN', 'value_name' => $variacao->gtin];
            }

            $payload[] = $itemVariacao;
        }

        if ($payload === []) {
            throw ErroPublicacao::permanente(
                'Nenhuma variação ativa para publicar.',
                'variacoes'
            );
        }

        return $payload;
    }

    /**
     * Traduz o nome do eixo para o id de atributo do Mercado Livre.
     *
     * Falhar aqui com mensagem clara é melhor que enviar um id inválido e
     * receber de volta um erro genérico da plataforma.
     *
     * @param array<string,string> $mapaConfigurado
     */
    public static function idDoEixo(string $nomeEixo, array $mapaConfigurado = []): string
    {
        if (isset($mapaConfigurado[$nomeEixo]) && $mapaConfigurado[$nomeEixo] !== '') {
            return (string) $mapaConfigurado[$nomeEixo];
        }

        $normalizado = mb_strtolower(trim($nomeEixo));

        if (isset(self::EIXOS_CONHECIDOS[$normalizado])) {
            return self::EIXOS_CONHECIDOS[$normalizado];
        }

        throw ErroPublicacao::permanente(
            "O eixo de variação '{$nomeEixo}' não tem atributo correspondente no Mercado Livre. "
            . 'Configure o mapeamento no modelo de produto.',
            'variacoes'
        );
    }
}
