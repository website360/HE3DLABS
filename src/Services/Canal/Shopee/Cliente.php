<?php

declare(strict_types=1);

namespace App\Services\Canal\Shopee;

use App\Core\Config;
use App\Dominio\Canal;
use App\Models\Contas;
use App\Services\Canal\ErroPublicacao;
use App\Services\Http\ClienteHttp;
use App\Services\Http\RespostaHttp;

final class Cliente
{
    public function __construct(
        private readonly ClienteHttp $http,
        private readonly Oauth $oauth,
    ) {
    }

    public function get(string $caminho, array $query = []): RespostaHttp
    {
        $url = $this->urlAssinada($caminho);

        if ($query !== []) {
            $url .= '&' . http_build_query($query);
        }

        return $this->http->requisitar('GET', $url, ['Accept' => 'application/json']);
    }

    public function post(string $caminho, array $corpo): RespostaHttp
    {
        return $this->http->requisitar(
            'POST',
            $this->urlAssinada($caminho),
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            (string) json_encode($corpo, JSON_UNESCAPED_UNICODE)
        );
    }

    /** Upload multipart para a media space. */
    public function enviarArquivo(string $caminho, string $campo, string $arquivoLocal): RespostaHttp
    {
        if (!is_file($arquivoLocal)) {
            throw ErroPublicacao::permanente("Arquivo não encontrado: {$arquivoLocal}", 'imagens');
        }

        return $this->http->requisitar(
            'POST',
            $this->urlAssinada($caminho),
            ['Accept' => 'application/json'],
            ['multipart' => [$campo => new \CURLFile($arquivoLocal, 'image/jpeg', basename($arquivoLocal))]]
        );
    }

    /**
     * A Shopee devolve HTTP 200 mesmo quando recusa a operação: o que
     * indica falha é o campo `error` no corpo. Tratar só o status HTTP
     * faria erros de validação passarem como sucesso.
     *
     * @return array<string,mixed> o conteúdo de `response`
     */
    public function extrair(RespostaHttp $resposta, string $etapa): array
    {
        if (!$resposta->sucesso()) {
            throw new ErroPublicacao($resposta->resumoErro(), $resposta->transitorio(), $etapa);
        }

        $corpo = $resposta->json();
        $erro = (string) ($corpo['error'] ?? '');

        if ($erro !== '') {
            $mensagem = (string) ($corpo['message'] ?? 'sem detalhe');

            throw new ErroPublicacao(
                "Shopee recusou ({$erro}): {$mensagem}",
                self::erroTransitorio($erro),
                $etapa
            );
        }

        $conteudo = $corpo['response'] ?? [];

        return is_array($conteudo) ? $conteudo : [];
    }

    /** Erros de limite de taxa e indisponibilidade valem retentar; validação, não. */
    private static function erroTransitorio(string $codigo): bool
    {
        return str_contains($codigo, 'rate')
            || str_contains($codigo, 'busy')
            || str_contains($codigo, 'timeout')
            || $codigo === 'error_server'
            || $codigo === 'error_inner';
    }

    private function urlAssinada(string $caminho): string
    {
        $host = rtrim((string) Config::get('SHOPEE_HOST', 'https://partner.shopeemobile.com'), '/');
        $partnerId = (int) Config::obrigatorio('SHOPEE_PARTNER_ID');
        $partnerKey = Config::obrigatorio('SHOPEE_PARTNER_KEY');
        $timestamp = time();

        $conta = Contas::buscar(Canal::Shopee);
        $shopId = (string) ($conta['identificador_loja'] ?? '');
        $token = $this->token();

        $assinatura = Assinatura::deLoja(
            $partnerId,
            $partnerKey,
            $caminho,
            $timestamp,
            $token,
            $shopId
        );

        $parametros = http_build_query([
            'partner_id'   => $partnerId,
            'timestamp'    => $timestamp,
            'access_token' => $token,
            'shop_id'      => $shopId,
            'sign'         => $assinatura,
        ]);

        return "{$host}{$caminho}?{$parametros}";
    }

    /** O access_token da Shopee dura 4 horas. */
    private function token(): string
    {
        if (Contas::precisaRenovar(Canal::Shopee)) {
            $this->oauth->renovar();
        }

        $conta = Contas::buscar(Canal::Shopee);
        $token = $conta['access_token'] ?? null;

        if (!is_string($token) || $token === '') {
            throw ErroPublicacao::permanente(
                'Conta da Shopee não conectada. Conecte em Canais.',
                'oauth'
            );
        }

        return $token;
    }
}
