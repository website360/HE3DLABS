<?php

declare(strict_types=1);

namespace App\Dominio;

final class Imagem
{
    public function __construct(
        public readonly int $id,
        public readonly string $arquivo,
        public readonly int $ordem = 0,
        public readonly ?int $variacaoId = null,
    ) {
    }

    /**
     * URL pública da imagem. O Mercado Livre baixa a foto por URL, então
     * ela precisa ser alcançável pela internet — em localhost, a
     * publicação real falha, e é esperado.
     */
    public function url(string $baseUrl): string
    {
        return rtrim($baseUrl, '/') . '/uploads/' . $this->arquivo;
    }

    public function caminhoLocal(): string
    {
        return BASE_PATH . '/public/uploads/' . $this->arquivo;
    }
}
