<?php

declare(strict_types=1);

namespace App\Services\Canal;

use App\Core\Config;
use App\Dominio\Canal;
use App\Models\Contas;

/**
 * De onde vem cada credencial.
 *
 * Ordem: o que foi salvo na tela de Canais vence; se estiver vazio, cai
 * para o .env. Assim quem tem acesso ao servidor pode continuar
 * configurando por arquivo, e quem não tem resolve pelo painel.
 *
 * O segredo fica criptografado no banco (AES-256-GCM), mesmo tratamento
 * já dado aos tokens.
 */
final class Credenciais
{
    public static function clientId(Canal $canal): ?string
    {
        $conta = Contas::buscar($canal);
        $doBanco = $conta['client_id'] ?? null;

        if (is_string($doBanco) && $doBanco !== '') {
            return $doBanco;
        }

        return Config::get(match ($canal) {
            Canal::MercadoLivre => 'ML_CLIENT_ID',
            Canal::Shopee       => 'SHOPEE_PARTNER_ID',
        });
    }

    public static function segredo(Canal $canal): ?string
    {
        $conta = Contas::buscar($canal);
        $doBanco = $conta['client_secret'] ?? null;

        if (is_string($doBanco) && $doBanco !== '') {
            return $doBanco;
        }

        return Config::get(match ($canal) {
            Canal::MercadoLivre => 'ML_CLIENT_SECRET',
            Canal::Shopee       => 'SHOPEE_PARTNER_KEY',
        });
    }

    /**
     * URL de retorno do OAuth.
     *
     * Derivada de APP_URL, porque ela precisa bater exatamente com a
     * cadastrada no portal — deixar o sistema montar evita o erro de
     * digitação que produz um "invalid redirect_uri" sem explicação.
     */
    public static function redirectUri(Canal $canal): string
    {
        $doEnv = Config::get(match ($canal) {
            Canal::MercadoLivre => 'ML_REDIRECT_URI',
            Canal::Shopee       => 'SHOPEE_REDIRECT_URI',
        });

        if (is_string($doEnv) && $doEnv !== '' && !str_contains($doEnv, 'seudominio')) {
            return $doEnv;
        }

        $base = rtrim((string) Config::get('APP_URL', ''), '/');

        return "{$base}/canais/{$canal->value}/callback";
    }

    /** Host da API da Shopee: produção ou sandbox. */
    public static function hostShopee(): string
    {
        $conta = Contas::buscar(Canal::Shopee);
        $doBanco = $conta['extra']['host'] ?? null;

        if (is_string($doBanco) && $doBanco !== '') {
            return rtrim($doBanco, '/');
        }

        return rtrim((string) Config::get('SHOPEE_HOST', 'https://partner.shopeemobile.com'), '/');
    }

    public static function completo(Canal $canal): bool
    {
        $id = self::clientId($canal);
        $segredo = self::segredo($canal);

        return is_string($id) && $id !== '' && is_string($segredo) && $segredo !== '';
    }

    /** Falha com mensagem que diz onde resolver, em vez de um erro genérico. */
    public static function exigir(Canal $canal): array
    {
        if (!self::completo($canal)) {
            throw ErroPublicacao::permanente(
                "Faltam as credenciais {$canal->com('de')}. Preencha em Canais.",
                'credenciais'
            );
        }

        return [(string) self::clientId($canal), (string) self::segredo($canal)];
    }
}
