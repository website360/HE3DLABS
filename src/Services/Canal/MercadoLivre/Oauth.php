<?php

declare(strict_types=1);

namespace App\Services\Canal\MercadoLivre;

use App\Core\Config;
use App\Core\Db;
use App\Core\Sessao;
use App\Dominio\Canal;
use App\Models\Contas;
use App\Services\Canal\ErroPublicacao;
use App\Services\Http\ClienteHttp;

/**
 * OAuth 2.0 do Mercado Livre, com PKCE.
 *
 * O ponto delicado: o refresh_token é de uso único. Cada renovação
 * devolve um par novo e invalida o anterior, então duas renovações
 * concorrentes queimam um token e desconectam a conta. Por isso a
 * renovação roda dentro de uma trava nomeada do MySQL.
 */
final class Oauth
{
    private const URL_AUTORIZACAO = 'https://auth.mercadolivre.com.br/authorization';
    private const URL_TOKEN = 'https://api.mercadolibre.com/oauth/token';
    private const TRAVA = 'he3d_token_mercadolivre';

    public function __construct(
        private readonly ClienteHttp $http,
    ) {
    }

    /** Monta a URL para onde o usuário é enviado a fim de autorizar o app. */
    public static function urlAutorizacao(): string
    {
        Sessao::iniciar();

        $verificador = self::gerarVerificador();
        $_SESSION['ml_code_verifier'] = $verificador;
        $_SESSION['ml_state'] = bin2hex(random_bytes(16));

        $parametros = [
            'response_type'         => 'code',
            'client_id'             => Config::obrigatorio('ML_CLIENT_ID'),
            'redirect_uri'          => Config::obrigatorio('ML_REDIRECT_URI'),
            'state'                 => $_SESSION['ml_state'],
            'code_challenge'        => self::desafio($verificador),
            'code_challenge_method' => 'S256',
        ];

        return self::URL_AUTORIZACAO . '?' . http_build_query($parametros);
    }

    /** Troca o código de autorização pelo primeiro par de tokens. */
    public function trocarCodigo(string $codigo, string $verificador): void
    {
        $resposta = $this->http->requisitar(
            'POST',
            self::URL_TOKEN,
            [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept'       => 'application/json',
            ],
            http_build_query([
                'grant_type'    => 'authorization_code',
                'client_id'     => Config::obrigatorio('ML_CLIENT_ID'),
                'client_secret' => Config::obrigatorio('ML_CLIENT_SECRET'),
                'code'          => $codigo,
                'redirect_uri'  => Config::obrigatorio('ML_REDIRECT_URI'),
                'code_verifier' => $verificador,
            ])
        );

        if (!$resposta->sucesso()) {
            throw ErroPublicacao::permanente(
                'Falha ao trocar o código pelo token: ' . $resposta->resumoErro(),
                'oauth'
            );
        }

        $this->gravar($resposta->json());
    }

    /**
     * Renova o par de tokens.
     *
     * A trava serializa renovações concorrentes; ao entrar, relê a conta,
     * porque outro processo pode já ter renovado enquanto se esperava.
     */
    public function renovar(): void
    {
        Db::comTrava(self::TRAVA, 15, function (): void {
            if (!Contas::precisaRenovar(Canal::MercadoLivre)) {
                return; // outro processo renovou enquanto aguardávamos
            }

            $conta = Contas::buscar(Canal::MercadoLivre);
            $refresh = $conta['refresh_token'] ?? null;

            if (!is_string($refresh) || $refresh === '') {
                throw ErroPublicacao::permanente(
                    'Conta do Mercado Livre sem refresh token. Reconecte a conta.',
                    'oauth'
                );
            }

            $resposta = $this->http->requisitar(
                'POST',
                self::URL_TOKEN,
                [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept'       => 'application/json',
                ],
                http_build_query([
                    'grant_type'    => 'refresh_token',
                    'client_id'     => Config::obrigatorio('ML_CLIENT_ID'),
                    'client_secret' => Config::obrigatorio('ML_CLIENT_SECRET'),
                    'refresh_token' => $refresh,
                ])
            );

            if (!$resposta->sucesso()) {
                // 400 aqui costuma significar refresh token já queimado:
                // não adianta retentar, a conta precisa ser reconectada.
                throw new ErroPublicacao(
                    'Falha ao renovar o token do Mercado Livre: ' . $resposta->resumoErro(),
                    $resposta->transitorio(),
                    'oauth'
                );
            }

            $this->gravar($resposta->json());
        });
    }

    /** @param array<string,mixed> $dados */
    private function gravar(array $dados): void
    {
        $acesso = $dados['access_token'] ?? null;
        $refresh = $dados['refresh_token'] ?? null;

        if (!is_string($acesso) || !is_string($refresh)) {
            throw ErroPublicacao::permanente(
                'Resposta de token do Mercado Livre sem access_token ou refresh_token. '
                . 'O app tem o escopo offline_access?',
                'oauth'
            );
        }

        Contas::salvarTokens(
            Canal::MercadoLivre,
            $acesso,
            $refresh,
            (int) ($dados['expires_in'] ?? 21600),
            isset($dados['user_id']) ? (string) $dados['user_id'] : null
        );
    }

    private static function gerarVerificador(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    }

    private static function desafio(string $verificador): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verificador, true)), '+/', '-_'), '=');
    }
}
