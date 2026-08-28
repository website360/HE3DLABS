<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use RuntimeException;

/**
 * Criptografia simétrica dos tokens de API guardados no banco.
 *
 * AES-256-GCM: além de cifrar, autentica. Se alguém alterar o valor
 * na tabela, a decifragem falha em vez de devolver lixo silenciosamente.
 * O pacote gravado é base64( iv[12] || tag[16] || cifrado ).
 */
final class Crypto
{
    private const CIFRA = 'aes-256-gcm';
    private const TAM_IV = 12;
    private const TAM_TAG = 16;

    public static function criptografar(string $texto): string
    {
        $iv = random_bytes(self::TAM_IV);
        $tag = '';

        $cifrado = openssl_encrypt($texto, self::CIFRA, self::chave(), OPENSSL_RAW_DATA, $iv, $tag);

        if ($cifrado === false) {
            throw new RuntimeException('Falha ao criptografar o valor.');
        }

        return base64_encode($iv . $tag . $cifrado);
    }

    public static function descriptografar(string $pacote): string
    {
        $bruto = base64_decode($pacote, true);

        if ($bruto === false || strlen($bruto) <= self::TAM_IV + self::TAM_TAG) {
            throw new RuntimeException('Valor criptografado malformado.');
        }

        $iv      = substr($bruto, 0, self::TAM_IV);
        $tag     = substr($bruto, self::TAM_IV, self::TAM_TAG);
        $cifrado = substr($bruto, self::TAM_IV + self::TAM_TAG);

        $texto = openssl_decrypt($cifrado, self::CIFRA, self::chave(), OPENSSL_RAW_DATA, $iv, $tag);

        if ($texto === false) {
            throw new RuntimeException(
                'Falha ao descriptografar. A APP_KEY mudou desde que o valor foi gravado?'
            );
        }

        return $texto;
    }

    public static function gerarChave(): string
    {
        return base64_encode(random_bytes(32));
    }

    private static function chave(): string
    {
        $chave = base64_decode(Config::obrigatorio('APP_KEY'), true);

        if ($chave === false || strlen($chave) !== 32) {
            throw new RuntimeException(
                'APP_KEY inválida: precisa ser 32 bytes em base64. Gere uma com "php bin/chave.php".'
            );
        }

        return $chave;
    }
}
