<?php

declare(strict_types=1);

namespace App\Services\Http;

final class RespostaHttp
{
    public function __construct(
        public readonly int $status,
        public readonly string $corpo,
        public readonly int $duracaoMs = 0,
        public readonly ?string $erroRede = null,
    ) {
    }

    public function sucesso(): bool
    {
        return $this->erroRede === null && $this->status >= 200 && $this->status < 300;
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        $decodificado = json_decode($this->corpo, true);

        return is_array($decodificado) ? $decodificado : [];
    }

    /**
     * Falha que provavelmente some sozinha: rede, limite de taxa, erro do
     * servidor remoto. Distinguir isso de erro de validação é o que impede
     * a fila de retentar para sempre um payload que nunca vai ser aceito.
     */
    public function transitorio(): bool
    {
        if ($this->erroRede !== null) {
            return true;
        }

        return $this->status === 429 || $this->status >= 500 || $this->status === 408;
    }

    public function resumoErro(): string
    {
        if ($this->erroRede !== null) {
            return "Falha de rede: {$this->erroRede}";
        }

        $json = $this->json();

        // Mercado Livre devolve {message, error, cause[]}.
        $mensagem = $json['message'] ?? $json['error'] ?? null;

        // Shopee devolve {error, message}.
        if (isset($json['message']) && $json['message'] !== '') {
            $mensagem = $json['message'];
        }

        $detalhes = [];

        if (isset($json['cause']) && is_array($json['cause'])) {
            foreach ($json['cause'] as $causa) {
                if (is_array($causa) && isset($causa['message'])) {
                    $detalhes[] = (string) $causa['message'];
                }
            }
        }

        $texto = is_string($mensagem) && $mensagem !== ''
            ? $mensagem
            : mb_substr($this->corpo, 0, 300);

        if ($detalhes !== []) {
            $texto .= ' — ' . implode('; ', $detalhes);
        }

        return "HTTP {$this->status}: {$texto}";
    }
}
