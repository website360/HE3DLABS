<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Db;
use App\Dominio\Canal;
use App\Support\Crypto;

/**
 * Credenciais e tokens dos canais.
 *
 * Segredos entram criptografados e saem decifrados, de modo que nenhum
 * outro ponto do sistema precise lembrar disso.
 */
final class Contas
{
    private const CAMPOS_SECRETOS = ['client_secret', 'access_token', 'refresh_token'];

    /** @return array<string,mixed>|null */
    public static function buscar(Canal $canal): ?array
    {
        $linha = Db::um('SELECT * FROM contas_canal WHERE canal = ?', [$canal->value]);

        if ($linha === null) {
            return null;
        }

        foreach (self::CAMPOS_SECRETOS as $campo) {
            $linha[$campo] = self::decifrarOuNulo($linha[$campo] ?? null);
        }

        $linha['extra'] = self::decodificar($linha['extra_json'] ?? null);

        return $linha;
    }

    public static function garantir(Canal $canal): array
    {
        $conta = self::buscar($canal);

        if ($conta !== null) {
            return $conta;
        }

        Db::executar(
            'INSERT INTO contas_canal (canal) VALUES (?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
            [$canal->value]
        );

        return self::buscar($canal) ?? throw new \RuntimeException('Falha ao criar a conta do canal.');
    }

    public static function conectada(Canal $canal): bool
    {
        $conta = self::buscar($canal);

        return $conta !== null
            && ($conta['access_token'] ?? null) !== null
            && ($conta['refresh_token'] ?? null) !== null;
    }

    /** Grava as credenciais de aplicação (não os tokens). */
    public static function salvarCredenciais(
        Canal $canal,
        string $clientId,
        string $clientSecret,
        float $markup
    ): void {
        self::garantir($canal);

        Db::executar(
            'UPDATE contas_canal
                SET client_id = ?, client_secret = ?, markup_percentual = ?
              WHERE canal = ?',
            [$clientId, Crypto::criptografar($clientSecret), $markup, $canal->value]
        );
    }

    public static function salvarMarkup(Canal $canal, float $markup): void
    {
        self::garantir($canal);
        Db::executar(
            'UPDATE contas_canal SET markup_percentual = ? WHERE canal = ?',
            [$markup, $canal->value]
        );
    }

    /**
     * Grava o par de tokens recém-obtido.
     *
     * No Mercado Livre o refresh_token é de uso único, então o par precisa
     * ser gravado junto — nunca só o access_token.
     */
    public static function salvarTokens(
        Canal $canal,
        string $accessToken,
        string $refreshToken,
        int $expiraEmSegundos,
        ?string $identificadorLoja = null,
        array $extra = []
    ): void {
        self::garantir($canal);

        $campos = [
            Crypto::criptografar($accessToken),
            Crypto::criptografar($refreshToken),
            date('Y-m-d H:i:s', time() + $expiraEmSegundos),
        ];

        $sql = 'UPDATE contas_canal
                   SET access_token = ?, refresh_token = ?, expira_em = ?,
                       conectado_em = COALESCE(conectado_em, NOW())';

        if ($identificadorLoja !== null) {
            $sql .= ', identificador_loja = ?';
            $campos[] = $identificadorLoja;
        }

        if ($extra !== []) {
            $sql .= ', extra_json = ?';
            $campos[] = json_encode($extra, JSON_UNESCAPED_UNICODE);
        }

        $sql .= ' WHERE canal = ?';
        $campos[] = $canal->value;

        Db::executar($sql, $campos);
    }

    public static function desconectar(Canal $canal): void
    {
        Db::executar(
            'UPDATE contas_canal
                SET access_token = NULL, refresh_token = NULL, expira_em = NULL,
                    identificador_loja = NULL, conectado_em = NULL
              WHERE canal = ?',
            [$canal->value]
        );
    }

    /** Token vencido ou a menos de 10 minutos de vencer. */
    public static function precisaRenovar(Canal $canal): bool
    {
        $conta = self::buscar($canal);

        if ($conta === null || ($conta['refresh_token'] ?? null) === null) {
            return false;
        }

        if ($conta['expira_em'] === null) {
            return true;
        }

        return strtotime((string) $conta['expira_em']) <= time() + 600;
    }

    private static function decifrarOuNulo(mixed $valor): ?string
    {
        if (!is_string($valor) || $valor === '') {
            return null;
        }

        try {
            return Crypto::descriptografar($valor);
        } catch (\Throwable) {
            // APP_KEY trocada ou valor corrompido: tratar como desconectado
            // é melhor que derrubar a tela de configuração.
            return null;
        }
    }

    /** @return array<string,mixed> */
    private static function decodificar(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }

        $decodificado = json_decode($json, true);

        return is_array($decodificado) ? $decodificado : [];
    }
}
