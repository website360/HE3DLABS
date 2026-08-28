<?php

declare(strict_types=1);

namespace App\Services\Http;

final class ClienteCurl implements ClienteHttp
{
    public function __construct(
        private readonly int $timeoutSegundos = 45,
    ) {
    }

    public function requisitar(
        string $metodo,
        string $url,
        array $cabecalhos = [],
        string|array|null $corpo = null
    ): RespostaHttp {
        $inicio = microtime(true);
        $ch = curl_init();

        $opcoes = [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => strtoupper($metodo),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeoutSegundos,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($corpo !== null) {
            if (is_array($corpo) && isset($corpo['multipart'])) {
                // Upload de arquivo: o cURL monta o multipart e define
                // o Content-Type com o boundary correto sozinho.
                $opcoes[CURLOPT_POSTFIELDS] = $corpo['multipart'];
                $cabecalhos = array_filter(
                    $cabecalhos,
                    static fn (string $chave): bool => strtolower($chave) !== 'content-type',
                    ARRAY_FILTER_USE_KEY
                );
            } else {
                $opcoes[CURLOPT_POSTFIELDS] = is_array($corpo)
                    ? (string) json_encode($corpo, JSON_UNESCAPED_UNICODE)
                    : $corpo;
            }
        }

        $linhasCabecalho = [];
        foreach ($cabecalhos as $chave => $valor) {
            $linhasCabecalho[] = "{$chave}: {$valor}";
        }

        if ($linhasCabecalho !== []) {
            $opcoes[CURLOPT_HTTPHEADER] = $linhasCabecalho;
        }

        curl_setopt_array($ch, $opcoes);

        $resposta = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $erro = curl_errno($ch) !== 0 ? curl_error($ch) : null;

        curl_close($ch);

        $duracao = (int) round((microtime(true) - $inicio) * 1000);

        return new RespostaHttp(
            $status,
            is_string($resposta) ? $resposta : '',
            $duracao,
            $erro
        );
    }
}
