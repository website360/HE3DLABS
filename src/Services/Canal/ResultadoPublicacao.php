<?php

declare(strict_types=1);

namespace App\Services\Canal;

final class ResultadoPublicacao
{
    /**
     * @param array<int,string>   $variacoesRemotas id da variação local => id remoto
     * @param array<string,mixed> $resposta         corpo devolvido pela plataforma
     */
    public function __construct(
        public readonly string $idRemoto,
        public readonly ?string $url = null,
        public readonly array $variacoesRemotas = [],
        public readonly array $resposta = [],
    ) {
    }
}
