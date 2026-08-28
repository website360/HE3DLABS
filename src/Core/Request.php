<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    public static function metodo(): string
    {
        $metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Formulários HTML só enviam GET e POST; _metodo permite DELETE/PUT.
        if ($metodo === 'POST' && isset($_POST['_metodo'])) {
            return strtoupper((string) $_POST['_metodo']);
        }

        return $metodo;
    }

    public static function caminho(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $caminho = parse_url($uri, PHP_URL_PATH);
        $caminho = is_string($caminho) ? $caminho : '/';

        return rtrim($caminho, '/') ?: '/';
    }

    public static function post(string $chave, ?string $padrao = null): ?string
    {
        $valor = $_POST[$chave] ?? null;
        if (!is_string($valor)) {
            return $padrao;
        }

        $valor = trim($valor);

        return $valor === '' ? $padrao : $valor;
    }

    public static function postInt(string $chave, ?int $padrao = null): ?int
    {
        $valor = self::post($chave);

        return $valor === null ? $padrao : (int) $valor;
    }

    /** Aceita vírgula como separador decimal, como o usuário digita. */
    public static function postDecimal(string $chave, ?float $padrao = null): ?float
    {
        $valor = self::post($chave);
        if ($valor === null) {
            return $padrao;
        }

        $valor = str_replace(['.', ','], ['', '.'], $valor);

        return is_numeric($valor) ? (float) $valor : $padrao;
    }

    public static function postBool(string $chave): bool
    {
        return isset($_POST[$chave]) && $_POST[$chave] !== '0';
    }

    /** @return array<mixed> */
    public static function postArray(string $chave): array
    {
        $valor = $_POST[$chave] ?? [];

        return is_array($valor) ? $valor : [];
    }

    public static function query(string $chave, ?string $padrao = null): ?string
    {
        $valor = $_GET[$chave] ?? null;
        if (!is_string($valor)) {
            return $padrao;
        }

        $valor = trim($valor);

        return $valor === '' ? $padrao : $valor;
    }

    /** @return array<int,array{name:string,tmp_name:string,size:int,error:int}> */
    public static function arquivos(string $chave): array
    {
        if (!isset($_FILES[$chave]) || !is_array($_FILES[$chave]['name'])) {
            return [];
        }

        $bruto = $_FILES[$chave];
        $lista = [];

        foreach ($bruto['name'] as $indice => $nome) {
            if ((int) $bruto['error'][$indice] === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $lista[] = [
                'name'     => (string) $nome,
                'tmp_name' => (string) $bruto['tmp_name'][$indice],
                'size'     => (int) $bruto['size'][$indice],
                'error'    => (int) $bruto['error'][$indice],
            ];
        }

        return $lista;
    }

    public static function baseUrl(): string
    {
        $config = Config::get('APP_URL');
        if ($config !== null) {
            return rtrim($config, '/');
        }

        $esquema = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return "{$esquema}://{$host}";
    }
}
