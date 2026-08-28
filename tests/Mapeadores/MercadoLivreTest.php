<?php

declare(strict_types=1);

use App\Dominio\Canal;
use App\Dominio\ContextoPublicacao;
use App\Dominio\Eixo;
use App\Dominio\Imagem;
use App\Dominio\Produto;
use App\Dominio\Variacao;
use App\Services\Canal\MercadoLivre\Mapeador;
use Testes\Afirma;

$variacao = static fn (int $id, string $sku, array $valores = [], float $preco = 79.9, bool $ativo = true): Variacao
    => new Variacao($id, $sku, $preco, 12, 210, 24.0, 12.0, 10.0, null, $ativo, $valores);

$contexto = static fn (array $extra = [], string $titulo = 'Suporte de fone'): ContextoPublicacao
    => new ContextoPublicacao(
        canal: Canal::MercadoLivre,
        categoriaId: 'MLB264188',
        titulo: $titulo,
        descricao: 'Impresso em PLA.',
        atributos: ['BRAND' => 'HE 3D Labs'],
        precos: [],
        imagensRemotas: [],
        baseUrl: 'https://exemplo.com.br',
        extra: $extra,
    );

$simples = static fn (): Produto => new Produto(
    1, 'HE3D-ORG', 'Organizador de cabos', 'Kit com 4.', 'HE 3D Labs',
    [], [new Variacao(10, 'HE3D-ORG', 24.9, 40, 45, 12.0, 8.0, 3.0)],
    [new Imagem(100, 'org.jpg')]
);

$comVariacoes = static fn () => new Produto(
    2, 'HE3D-SUP', 'Suporte de fone', 'Impresso em PLA.', 'HE 3D Labs',
    [new Eixo(5, 'Cor', 1)],
    [
        new Variacao(20, 'HE3D-SUP-PRETO', 79.9, 12, 210, 24.0, 12.0, 10.0, null, true, ['Cor' => 'Preto']),
        new Variacao(21, 'HE3D-SUP-BRANCO', 79.9, 3, 210, 24.0, 12.0, 10.0, null, true, ['Cor' => 'Branco']),
    ],
    [new Imagem(101, 'sup.jpg')]
);

return [
    'produto simples leva preço e estoque no próprio item' => static function () use ($simples, $contexto): void {
        $payload = Mapeador::montarItem($simples(), $contexto());

        Afirma::igual(24.9, $payload['price'], 'o preço deve ir na raiz do item');
        Afirma::igual(40, $payload['available_quantity'], 'o estoque deve ir na raiz do item');
        Afirma::verdadeiro(!isset($payload['variations']), 'produto simples não deve enviar variations');
    },

    'produto com variações não leva preço na raiz' => static function () use ($comVariacoes, $contexto): void {
        $payload = Mapeador::montarItem($comVariacoes(), $contexto());

        Afirma::verdadeiro(!isset($payload['price']), 'com variações, o preço vive dentro de cada variação');
        Afirma::igual(2, count($payload['variations']), 'deve enviar as duas variações ativas');
        Afirma::igual(
            'COLOR',
            $payload['variations'][0]['attribute_combinations'][0]['id'],
            'o eixo "Cor" deve virar o atributo COLOR'
        );
        Afirma::igual(
            'Preto',
            $payload['variations'][0]['attribute_combinations'][0]['value_name'],
            'o valor da combinação deve ser o valor do eixo'
        );
    },

    'variação inativa fica de fora' => static function () use ($contexto): void {
        $produto = new Produto(
            3, 'HE3D-X', 'Peça', 'Descrição.', null,
            [new Eixo(5, 'Cor', 1)],
            [
                new Variacao(30, 'X-A', 10.0, 1, 100, 5.0, 5.0, 5.0, null, true, ['Cor' => 'Preto']),
                new Variacao(31, 'X-B', 10.0, 1, 100, 5.0, 5.0, 5.0, null, false, ['Cor' => 'Branco']),
            ],
            [new Imagem(102, 'x.jpg')]
        );

        $payload = Mapeador::montarItem($produto, $contexto());

        Afirma::igual(1, count($payload['variations']), 'só a variação ativa deve ser enviada');
    },

    'título é cortado no limite de 60 do Mercado Livre' => static function () use ($simples, $contexto): void {
        $longo = 'Suporte de Fone de Ouvido para Mesa em PLA Resistente Headset Gamer Profissional';
        $payload = Mapeador::montarItem($simples(), $contexto([], $longo));

        Afirma::verdadeiro(
            mb_strlen($payload['title']) <= 60,
            'o título deve respeitar o limite de 60 caracteres, e veio com ' . mb_strlen($payload['title'])
        );
        Afirma::verdadeiro(
            !str_ends_with($payload['title'], ' '),
            'o corte não deve deixar espaço sobrando no fim'
        );
    },

    'peso e dimensões viram atributos de embalagem' => static function () use ($simples, $contexto): void {
        $payload = Mapeador::montarItem($simples(), $contexto());
        $ids = array_column($payload['attributes'], 'value_name', 'id');

        Afirma::igual('45 g', $ids['PACKAGE_WEIGHT'], 'o peso deve ir em gramas');
        Afirma::igual('12 cm', $ids['PACKAGE_LENGTH'], 'o comprimento deve ir em centímetros');
        Afirma::igual('HE3D-ORG', $ids['SELLER_SKU'], 'o SKU deve ir como SELLER_SKU');
    },

    'preço do canal sobrepõe o preço base' => static function () use ($simples): void {
        $contexto = new ContextoPublicacao(
            canal: Canal::MercadoLivre,
            categoriaId: 'MLB264188',
            titulo: 'Organizador',
            descricao: 'x',
            precos: [10 => 31.50],
            baseUrl: 'https://exemplo.com.br',
        );

        $payload = Mapeador::montarItem($simples(), $contexto);

        Afirma::igual(31.5, $payload['price'], 'deve usar o preço configurado para o canal');
    },

    'eixo sem correspondência falha com mensagem acionável' => static function (): void {
        Afirma::lanca(
            static fn () => Mapeador::idDoEixo('Acabamento'),
            'Configure o mapeamento',
            'um eixo desconhecido deve orientar o usuário a mapeá-lo'
        );
    },

    'eixo mapeado manualmente é respeitado' => static function (): void {
        Afirma::igual(
            'FINISH',
            Mapeador::idDoEixo('Acabamento', ['Acabamento' => 'FINISH']),
            'o mapeamento configurado no modelo deve prevalecer'
        );
    },

    'produto sem imagem é recusado antes de sair' => static function () use ($contexto): void {
        $produto = new Produto(
            4, 'HE3D-Y', 'Peça', 'Descrição.', null, [],
            [new Variacao(40, 'Y', 10.0, 1, 100, 5.0, 5.0, 5.0)],
            []
        );

        Afirma::lanca(
            static fn () => Mapeador::montarItem($produto, $contexto()),
            'ao menos uma imagem',
            'sem imagem, o mapeador deve falhar em vez de gerar payload inválido'
        );
    },
];
