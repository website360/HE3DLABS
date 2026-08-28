<?php

declare(strict_types=1);

namespace App\Services\Canal\Shopee;

use App\Core\Db;
use App\Dominio\Canal;
use App\Models\Contas;
use App\Services\Canal\Credenciais;
use App\Services\Canal\ErroPublicacao;
use App\Services\Http\ClienteHttp;

/**
 * Autorização de loja da Shopee.
 *
 * O fluxo é diferente do OAuth comum: o lojista autoriza o parceiro, a
 * Shopee redireciona com `code` e `shop_id`, e a troca por token é uma
 * chamada pública assinada com a chave do parceiro.
 */
final class Oauth
{
    private const CAMINHO_AUTORIZACAO = '/api/v2/shop/auth_partner';
    private const CAMINHO_TOKEN = '/api/v2/auth/token/get';
    private const CAMINHO_RENOVACAO = '/api/v2/auth/access_token/get';
    private const TRAVA = 'he3d_token_shopee';

    public function __construct(
        private readonly ClienteHttp $http,
    ) {
    }

    /** URL para onde o lojista é enviado a fim de autorizar o app. */
    public static function urlAutorizacao(): string
    {
        [$id, $partnerKey] = Credenciais::exigir(Canal::Shopee);
        $partnerId = (int) $id;
        $redirect = Credenciais::redirectUri(Canal::Shopee);
        $host = Credenciais::hostShopee();
        $timestamp = time();

        $assinatura = Assinatura::publica($partnerId, $partnerKey, self::CAMINHO_AUTORIZACAO, $timestamp);

        $parametros = http_build_query([
            'partner_id' => $partnerId,
            'timestamp'  => $timestamp,
            'sign'       => $assinatura,
            'redirect'   => $redirect,
        ]);

        return $host . self::CAMINHO_AUTORIZACAO . '?' . $parametros;
    }

    /** Troca o code recebido no callback pelo primeiro par de tokens. */
    public function trocarCodigo(string $codigo, string $shopId): void
    {
        $resposta = $this->chamarPublico(self::CAMINHO_TOKEN, [
            'code'       => $codigo,
            'shop_id'    => (int) $shopId,
            'partner_id' => (int) Credenciais::exigir(Canal::Shopee)[0],
        ]);

        $this->gravar($resposta, $shopId);
    }

    /**
     * Renova o par de tokens. Serializado por trava para evitar que dois
     * processos renovem ao mesmo tempo.
     */
    public function renovar(): void
    {
        Db::comTrava(self::TRAVA, 15, function (): void {
            if (!Contas::precisaRenovar(Canal::Shopee)) {
                return;
            }

            $conta = Contas::buscar(Canal::Shopee);
            $refresh = $conta['refresh_token'] ?? null;
            $shopId = (string) ($conta['identificador_loja'] ?? '');

            if (!is_string($refresh) || $refresh === '' || $shopId === '') {
                throw ErroPublicacao::permanente(
                    'Conta da Shopee sem refresh token ou shop_id. Reconecte a loja.',
                    'oauth'
                );
            }

            $resposta = $this->chamarPublico(self::CAMINHO_RENOVACAO, [
                'refresh_token' => $refresh,
                'shop_id'       => (int) $shopId,
                'partner_id'    => (int) Credenciais::exigir(Canal::Shopee)[0],
            ]);

            $this->gravar($resposta, $shopId);
        });
    }

    /**
     * Chamada pública assinada. Não usa o Cliente porque este ainda não
     * tem token para assinar.
     *
     * @param array<string,mixed> $corpo
     * @return array<string,mixed>
     */
    private function chamarPublico(string $caminho, array $corpo): array
    {
        [$id, $partnerKey] = Credenciais::exigir(Canal::Shopee);
        $partnerId = (int) $id;
        $host = Credenciais::hostShopee();
        $timestamp = time();

        $parametros = http_build_query([
            'partner_id' => $partnerId,
            'timestamp'  => $timestamp,
            'sign'       => Assinatura::publica($partnerId, $partnerKey, $caminho, $timestamp),
        ]);

        $resposta = $this->http->requisitar(
            'POST',
            "{$host}{$caminho}?{$parametros}",
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            (string) json_encode($corpo, JSON_UNESCAPED_UNICODE)
        );

        if (!$resposta->sucesso()) {
            throw new ErroPublicacao(
                'Falha na chamada de token da Shopee: ' . $resposta->resumoErro(),
                $resposta->transitorio(),
                'oauth'
            );
        }

        $json = $resposta->json();
        $erro = (string) ($json['error'] ?? '');

        if ($erro !== '') {
            throw ErroPublicacao::permanente(
                "Shopee recusou a autorização ({$erro}): " . (string) ($json['message'] ?? ''),
                'oauth'
            );
        }

        return $json;
    }

    /** @param array<string,mixed> $dados */
    private function gravar(array $dados, string $shopId): void
    {
        $acesso = $dados['access_token'] ?? null;
        $refresh = $dados['refresh_token'] ?? null;

        if (!is_string($acesso) || !is_string($refresh)) {
            throw ErroPublicacao::permanente(
                'Resposta de token da Shopee sem access_token ou refresh_token.',
                'oauth'
            );
        }

        Contas::salvarTokens(
            Canal::Shopee,
            $acesso,
            $refresh,
            (int) ($dados['expire_in'] ?? 14400),
            $shopId
        );
    }
}
