<?php

declare(strict_types=1);

namespace App\Dominio;

enum Canal: string
{
    case MercadoLivre = 'mercadolivre';
    case Shopee = 'shopee';

    public function rotulo(): string
    {
        return match ($this) {
            self::MercadoLivre => 'Mercado Livre',
            self::Shopee       => 'Shopee',
        };
    }

    /**
     * Contração do artigo com a preposição: "do Mercado Livre", "da Shopee".
     * Existe porque os dois nomes têm gêneros diferentes, e escrever
     * "do Shopee" em toda mensagem da interface soa descuidado.
     */
    public function com(string $preposicao): string
    {
        $feminino = $this === self::Shopee;

        $contraido = match ($preposicao) {
            'de'   => $feminino ? 'da' : 'do',
            'em'   => $feminino ? 'na' : 'no',
            'para' => $feminino ? 'para a' : 'para o',
            'a'    => $feminino ? 'à' : 'ao',
            default => $preposicao . ($feminino ? ' a' : ' o'),
        };

        return $contraido . ' ' . $this->rotulo();
    }

    /** Limite de caracteres do título do anúncio, imposto pela plataforma. */
    public function limiteTitulo(): int
    {
        return match ($this) {
            self::MercadoLivre => 60,
            self::Shopee       => 120,
        };
    }

    /** Limite de caracteres da descrição. */
    public function limiteDescricao(): int
    {
        return match ($this) {
            self::MercadoLivre => 50000,
            self::Shopee       => 3000,
        };
    }

    /** Máximo de imagens aceitas por anúncio. */
    public function limiteImagens(): int
    {
        return match ($this) {
            self::MercadoLivre => 12,
            self::Shopee       => 9,
        };
    }

    public function cor(): string
    {
        return match ($this) {
            self::MercadoLivre => '#ffe600',
            self::Shopee       => '#ee4d2d',
        };
    }

    /** @return array<int,self> */
    public static function todos(): array
    {
        return self::cases();
    }
}
