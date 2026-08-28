<?php

declare(strict_types=1);

namespace App\Dominio;

/**
 * Produto montado em memória, com suas variações, eixos e imagens.
 *
 * Existe separado dos repositórios para que os mapeadores de payload
 * sejam funções puras: recebem este objeto e devolvem o array que vai
 * para a API, sem tocar em banco nem em rede. É o que torna a parte
 * mais defeituosa da integração barata de testar.
 */
final class Produto
{
    /**
     * @param array<int,Eixo>     $eixos
     * @param array<int,Variacao> $variacoes
     * @param array<int,Imagem>   $imagens imagens do produto (sem variação)
     */
    public function __construct(
        public readonly int $id,
        public readonly string $skuBase,
        public readonly string $titulo,
        public readonly string $descricao,
        public readonly ?string $marca,
        public readonly array $eixos = [],
        public readonly array $variacoes = [],
        public readonly array $imagens = [],
    ) {
    }

    public function temVariacoes(): bool
    {
        return $this->eixos !== [];
    }

    /**
     * Produto simples é um produto com uma variação só. Este método
     * existe para os pontos em que a API do canal separa os dois casos.
     */
    public function variacaoUnica(): Variacao
    {
        if (count($this->variacoes) !== 1) {
            throw new \LogicException(
                "Produto {$this->skuBase} não é simples: tem " . count($this->variacoes) . ' variações.'
            );
        }

        return $this->variacoes[array_key_first($this->variacoes)];
    }

    public function estoqueTotal(): int
    {
        return array_sum(array_map(static fn (Variacao $v): int => $v->estoque, $this->variacoes));
    }

    /** Menor preço entre as variações ativas — usado como preço-vitrine. */
    public function precoMinimo(): float
    {
        $precos = array_map(
            static fn (Variacao $v): float => $v->preco,
            array_filter($this->variacoes, static fn (Variacao $v): bool => $v->ativo)
        );

        return $precos === [] ? 0.0 : min($precos);
    }

    /** @return array<int,Imagem> imagens do produto seguidas das de variação, sem repetir */
    public function todasImagens(): array
    {
        $todas = $this->imagens;

        foreach ($this->variacoes as $variacao) {
            foreach ($variacao->imagens as $imagem) {
                $todas[] = $imagem;
            }
        }

        $vistas = [];
        $unicas = [];

        foreach ($todas as $imagem) {
            if (isset($vistas[$imagem->id])) {
                continue;
            }
            $vistas[$imagem->id] = true;
            $unicas[] = $imagem;
        }

        return $unicas;
    }
}
