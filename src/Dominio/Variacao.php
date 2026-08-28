<?php

declare(strict_types=1);

namespace App\Dominio;

final class Variacao
{
    /**
     * @param array<string,string> $valores  nome do eixo => valor ("Cor" => "Preto")
     * @param array<int,Imagem>    $imagens
     */
    public function __construct(
        public readonly int $id,
        public readonly string $sku,
        public readonly float $preco,
        public readonly int $estoque,
        public readonly int $pesoG,
        public readonly float $comprimentoCm,
        public readonly float $larguraCm,
        public readonly float $alturaCm,
        public readonly ?string $gtin = null,
        public readonly bool $ativo = true,
        public readonly array $valores = [],
        public readonly array $imagens = [],
    ) {
    }

    /** Peso em quilos, unidade usada pela Shopee. */
    public function pesoKg(): float
    {
        return round($this->pesoG / 1000, 3);
    }

    /** Rótulo legível da combinação: "Preto / Grande". */
    public function rotulo(): string
    {
        return $this->valores === [] ? $this->sku : implode(' / ', $this->valores);
    }

    public function valorDoEixo(string $nomeEixo): ?string
    {
        return $this->valores[$nomeEixo] ?? null;
    }
}
