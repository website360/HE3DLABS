<?php

declare(strict_types=1);

namespace App\Services\Canal\Shopee;

/**
 * Assinatura HMAC-SHA256 exigida em toda chamada da Shopee.
 *
 * A string base muda conforme o tipo de endpoint:
 *   - público (obter/renovar token): partner_id + caminho + timestamp
 *   - de loja (todo o resto):        + access_token + shop_id
 *
 * O timestamp precisa estar a menos de 5 minutos do relógio da Shopee.
 * Fora disso, toda chamada volta com erro de assinatura — e a mensagem
 * não indica que a causa é o relógio do servidor.
 */
final class Assinatura
{
    public static function publica(
        int $partnerId,
        string $partnerKey,
        string $caminho,
        int $timestamp
    ): string {
        return self::calcular($partnerKey, $partnerId . $caminho . $timestamp);
    }

    public static function deLoja(
        int $partnerId,
        string $partnerKey,
        string $caminho,
        int $timestamp,
        string $accessToken,
        string $shopId
    ): string {
        return self::calcular(
            $partnerKey,
            $partnerId . $caminho . $timestamp . $accessToken . $shopId
        );
    }

    private static function calcular(string $chave, string $base): string
    {
        return hash_hmac('sha256', $base, $chave);
    }
}
