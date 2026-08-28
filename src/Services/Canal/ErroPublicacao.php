<?php

declare(strict_types=1);

namespace App\Services\Canal;

use RuntimeException;

/**
 * Falha ao publicar. O sinalizador `transitorio` decide se a fila
 * retenta ou desiste: retentar um payload recusado por validação apenas
 * repete o mesmo erro cinco vezes.
 */
final class ErroPublicacao extends RuntimeException
{
    public function __construct(
        string $mensagem,
        public readonly bool $transitorio = false,
        public readonly ?string $etapa = null,
    ) {
        parent::__construct($mensagem);
    }

    public static function transitoria(string $mensagem, ?string $etapa = null): self
    {
        return new self($mensagem, true, $etapa);
    }

    public static function permanente(string $mensagem, ?string $etapa = null): self
    {
        return new self($mensagem, false, $etapa);
    }

    public function descricaoCompleta(): string
    {
        return $this->etapa === null
            ? $this->getMessage()
            : "[{$this->etapa}] {$this->getMessage()}";
    }
}
