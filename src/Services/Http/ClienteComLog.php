<?php

declare(strict_types=1);

namespace App\Services\Http;

use App\Dominio\Canal;
use App\Models\LogApi;

/**
 * Envolve outro cliente e registra ida e volta em log_api.
 *
 * Quando um marketplace recusar um anúncio com mensagem críptica, é este
 * registro que permite descobrir o que foi enviado de fato.
 */
final class ClienteComLog implements ClienteHttp
{
    public function __construct(
        private readonly ClienteHttp $interno,
        private readonly Canal $canal,
    ) {
    }

    public function requisitar(
        string $metodo,
        string $url,
        array $cabecalhos = [],
        string|array|null $corpo = null
    ): RespostaHttp {
        $resposta = $this->interno->requisitar($metodo, $url, $cabecalhos, $corpo);

        LogApi::registrar(
            $this->canal,
            $metodo,
            $this->mascararUrl($url),
            $resposta->erroRede === null ? $resposta->status : null,
            $this->descreverCorpo($corpo),
            $resposta->corpo,
            $resposta->duracaoMs,
            $resposta->erroRede
        );

        return $resposta;
    }

    private function descreverCorpo(string|array|null $corpo): ?string
    {
        if ($corpo === null) {
            return null;
        }

        if (is_array($corpo)) {
            return isset($corpo['multipart'])
                ? '[upload de arquivo]'
                : (string) json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        return $corpo;
    }

    /**
     * A Shopee leva assinatura e token na query string. Guardar isso em
     * texto claro no log espalharia segredo por uma tabela que existe
     * para ser lida à vontade.
     */
    private function mascararUrl(string $url): string
    {
        return (string) preg_replace(
            '/(access_token|sign|partner_key|refresh_token)=[^&]+/i',
            '$1=***',
            $url
        );
    }
}
