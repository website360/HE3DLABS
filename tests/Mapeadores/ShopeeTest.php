<?php

declare(strict_types=1);

use App\Dominio\Canal;
use App\Dominio\ContextoPublicacao;
use App\Dominio\Eixo;
use App\Dominio\Imagem;
use App\Dominio\Produto;
use App\Dominio\Variacao;
use App\Services\Canal\Shopee\Assinatura;
use App\Services\Canal\Shopee\Mapeador;
use Testes\Afirma;

$contexto = static fn (array $extra = ['logisticas' => [90003]], array $imagens = [101 => 'img-remota-1']): ContextoPublicacao
    => new ContextoPublicacao(
        canal: Canal::Shopee,
        categoriaId: '100639',
        titulo: 'Suporte de fone',
        descricao: 'Impresso em PLA.',
        atributos: [],
        precos: [],
        imagensRemotas: $imagens,
        baseUrl: 'https://exemplo.com.br',
        extra: $extra,
    );

$comVariacoes = static fn (): Produto => new Produto(
    2, 'HE3D-SUP', 'Suporte de fone', 'Impresso em PLA.', 'HE 3D Labs',
    [new Eixo(5, 'Cor', 1)],
    [
        new Variacao(20, 'HE3D-SUP-PRETO', 79.9, 12, 210, 24.0, 12.4, 10.0, null, true, ['Cor' => 'Preto']),
        new Variacao(21, 'HE3D-SUP-BRANCO', 89.9, 3, 230, 24.0, 12.4, 10.0, null, true, ['Cor' => 'Branco']),
    ],
    [new Imagem(101, 'sup.jpg')]
);

return [
    'peso vai em quilos e dimensões em centímetros inteiros' => static function () use ($comVariacoes, $contexto): void {
        $payload = Mapeador::montarItem($comVariacoes(), $contexto());

        Afirma::igual(0.21, $payload['weight'], '210 g devem virar 0.21 kg');
        Afirma::igual(13, $payload['dimension']['package_width'], '12,4 cm deve arredondar para cima');
        Afirma::igual(24, $payload['dimension']['package_length'], 'o comprimento deve ir como inteiro');
    },

    'matriz de variações usa tier_index apontando para as opções' => static function () use ($comVariacoes, $contexto): void {
        $payload = Mapeador::montarVariacoes($comVariacoes(), $contexto());

        Afirma::igual('Cor', $payload['tier_variation'][0]['name'], 'o eixo deve virar uma tier_variation');
        Afirma::igual(2, count($payload['tier_variation'][0]['option_list']), 'devem existir duas opções de cor');
        Afirma::igual([0], $payload['model'][0]['tier_index'], 'a primeira variação aponta para a opção 0');
        Afirma::igual([1], $payload['model'][1]['tier_index'], 'a segunda variação aponta para a opção 1');
        Afirma::igual(89.9, $payload['model'][1]['original_price'], 'cada modelo leva o próprio preço');
    },

    'sem logística configurada, recusa antes de chamar a API' => static function () use ($comVariacoes, $contexto): void {
        Afirma::lanca(
            static fn () => Mapeador::montarItem($comVariacoes(), $contexto([])),
            'logistic_info',
            'a Shopee exige logistic_info; falhar cedo evita um erro críptico da plataforma'
        );
    },

    'imagem sem upload prévio é recusada' => static function () use ($comVariacoes, $contexto): void {
        Afirma::lanca(
            static fn () => Mapeador::montarItem($comVariacoes(), $contexto(['logisticas' => [90003]], [])),
            'media space',
            'sem image_id, o anúncio não pode ser montado'
        );
    },

    'marca cai para NoBrand quando não há brand_id' => static function () use ($comVariacoes, $contexto): void {
        $payload = Mapeador::montarItem($comVariacoes(), $contexto());

        Afirma::igual(0, $payload['brand']['brand_id'], 'sem brand_id configurado, usa 0');
        Afirma::igual('NoBrand', $payload['brand']['original_brand_name'], 'a Shopee recusa marca em texto livre');
    },

    'mais de dois eixos é recusado' => static function () use ($contexto): void {
        $produto = new Produto(
            9, 'X', 'Peça', 'd', null,
            [new Eixo(1, 'Cor', 1), new Eixo(2, 'Tamanho', 2), new Eixo(3, 'Material', 3)],
            [new Variacao(90, 'X-1', 10.0, 1, 100, 5.0, 5.0, 5.0, null, true,
                ['Cor' => 'Preto', 'Tamanho' => 'P', 'Material' => 'PLA'])],
            [new Imagem(101, 'x.jpg')]
        );

        Afirma::lanca(
            static fn () => Mapeador::montarVariacoes($produto, $contexto()),
            'no máximo 2 eixos',
            'o limite de duas tier variations precisa ser respeitado'
        );
    },

    'assinatura pública segue partner_id + caminho + timestamp' => static function (): void {
        $esperado = hash_hmac('sha256', '1000/api/v2/auth/token/get1700000000', 'chave-secreta');

        Afirma::igual(
            $esperado,
            Assinatura::publica(1000, 'chave-secreta', '/api/v2/auth/token/get', 1700000000),
            'a string base da assinatura pública não pode mudar'
        );
    },

    'assinatura de loja acrescenta token e shop_id' => static function (): void {
        $esperado = hash_hmac(
            'sha256',
            '1000/api/v2/product/add_item1700000000token-abc55555',
            'chave-secreta'
        );

        Afirma::igual(
            $esperado,
            Assinatura::deLoja(1000, 'chave-secreta', '/api/v2/product/add_item', 1700000000, 'token-abc', '55555'),
            'a string base da assinatura de loja não pode mudar'
        );
    },
];
