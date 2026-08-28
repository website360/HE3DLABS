<?php

declare(strict_types=1);

namespace App\Services\Canal;

use App\Dominio\Canal;
use App\Services\Canal\MercadoLivre\Cliente as ClienteML;
use App\Services\Canal\MercadoLivre\Oauth as OauthML;
use App\Services\Canal\MercadoLivre\Publicador as PublicadorML;
use App\Services\Canal\Shopee\Cliente as ClienteShopee;
use App\Services\Canal\Shopee\Oauth as OauthShopee;
use App\Services\Canal\Shopee\Publicador as PublicadorShopee;
use App\Services\Http\ClienteComLog;
use App\Services\Http\ClienteCurl;
use App\Services\Http\ClienteHttp;

/**
 * Monta a implementação de canal correspondente.
 *
 * Aceita um cliente HTTP alternativo para que os testes injetem
 * respostas gravadas em vez de tocar a rede.
 */
final class Fabrica
{
    public static function para(Canal $canal, ?ClienteHttp $http = null): CanalInterface
    {
        $http ??= new ClienteComLog(new ClienteCurl(), $canal);

        return match ($canal) {
            Canal::MercadoLivre => new PublicadorML(new ClienteML($http, new OauthML($http))),
            Canal::Shopee       => new PublicadorShopee(new ClienteShopee($http, new OauthShopee($http))),
        };
    }

    /** @return array<string,CanalInterface> */
    public static function todos(): array
    {
        $mapa = [];

        foreach (Canal::todos() as $canal) {
            $mapa[$canal->value] = self::para($canal);
        }

        return $mapa;
    }
}
