<?php

declare(strict_types=1);

namespace App\Dominio;

/** Um eixo de variação: "Cor", "Tamanho". No máximo dois por produto. */
final class Eixo
{
    public function __construct(
        public readonly int $id,
        public readonly string $nome,
        public readonly int $ordem,
    ) {
    }
}
