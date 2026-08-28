<?php

declare(strict_types=1);

namespace App\Services\Canal\MercadoLivre;

use App\Dominio\Canal;
use App\Models\Contas;
use App\Services\Canal\ErroPublicacao;
use App\Services\Http\ClienteHttp;
use App\Services\Http\RespostaHttp;

/**
 * Wrapper da API do Mercado Livre: cuida do token e monta as chamadas.
 */
final class Cliente
{
    private const BASE = 'https://api.mercadolibre.com';

    public function __construct(
        private readonly ClienteHttp $http,
        private readonly Oauth $oauth,
    ) {
    }

    public function get(string $caminho, array $query = []): RespostaHttp
    {
        $url = self::BASE . $caminho;

        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $this->http->requisitar('GET', $url, $this->cabecalhos());
    }

    public function post(string $caminho, array $corpo): RespostaHttp
    {
        return $this->http->requisitar(
            'POST',
            self::BASE . $caminho,
            $this->cabecalhos(),
            (string) json_encode($corpo, JSON_UNESCAPED_UNICODE)
        );
    }

    public function put(string $caminho, array $corpo): RespostaHttp
    {
        return $this->http->requisitar(
            'PUT',
            self::BASE . $caminho,
            $this->cabecalhos(),
            (string) json_encode($corpo, JSON_UNESCAPED_UNICODE)
        );
    }

    /** Chamadas públicas (categorias) não precisam de token. */
    public function getPublico(string $caminho, array $query = []): RespostaHttp
    {
        $url = self::BASE . $caminho;

        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $this->http->requisitar('GET', $url, ['Accept' => 'application/json']);
    }

    public function idVendedor(): ?string
    {
        $conta = Contas::buscar(Canal::MercadoLivre);
        $id = $conta['identificador_loja'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** @return array<string,string> */
    private function cabecalhos(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->token(),
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
    }

    /**
     * Devolve um access_token válido, renovando antes de vencer.
     * O access_token do Mercado Livre dura 6 horas.
     */
    private function token(): string
    {
        if (Contas::precisaRenovar(Canal::MercadoLivre)) {
            $this->oauth->renovar();
        }

        $conta = Contas::buscar(Canal::MercadoLivre);
        $token = $conta['access_token'] ?? null;

        if (!is_string($token) || $token === '') {
            throw ErroPublicacao::permanente(
                'Conta do Mercado Livre não conectada. Conecte em Canais.',
                'oauth'
            );
        }

        return $token;
    }
}
