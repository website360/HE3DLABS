<?php

declare(strict_types=1);

namespace App\Services\Imagem;

use RuntimeException;

/**
 * Recebe o upload, valida que é mesmo uma imagem e normaliza para JPEG.
 *
 * As duas plataformas recusam arquivos muito grandes e formatos exóticos,
 * então converter na entrada evita descobrir o problema só na publicação.
 */
final class Uploader
{
    private const TAM_MAX_BYTES = 10 * 1024 * 1024;
    private const LADO_MAX = 1600;
    private const QUALIDADE = 88;

    /** @param array{name:string,tmp_name:string,size:int,error:int} $arquivo */
    public static function salvar(array $arquivo, string $prefixoSku): string
    {
        if ($arquivo['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::mensagemErro($arquivo['error']));
        }

        if ($arquivo['size'] > self::TAM_MAX_BYTES) {
            throw new RuntimeException('Imagem maior que 10 MB.');
        }

        $info = @getimagesize($arquivo['tmp_name']);

        if ($info === false) {
            throw new RuntimeException("O arquivo '{$arquivo['name']}' não é uma imagem válida.");
        }

        $origem = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($arquivo['tmp_name']),
            IMAGETYPE_PNG  => @imagecreatefrompng($arquivo['tmp_name']),
            IMAGETYPE_WEBP => @imagecreatefromwebp($arquivo['tmp_name']),
            IMAGETYPE_GIF  => @imagecreatefromgif($arquivo['tmp_name']),
            default        => throw new RuntimeException(
                'Formato não suportado. Use JPG, PNG, WEBP ou GIF.'
            ),
        };

        if ($origem === false) {
            throw new RuntimeException("Não foi possível ler a imagem '{$arquivo['name']}'.");
        }

        $imagem = self::redimensionar($origem, $info[0], $info[1]);

        $destino = self::diretorio() . '/' . self::nomeUnico($prefixoSku);

        if (!imagejpeg($imagem, $destino, self::QUALIDADE)) {
            imagedestroy($imagem);
            throw new RuntimeException('Falha ao gravar a imagem processada.');
        }

        imagedestroy($imagem);

        return basename($destino);
    }

    /**
     * Reduz para caber em LADO_MAX preservando proporção, e achata sobre
     * fundo branco: PNG com transparência vira preto no JPEG se não fizer isso.
     *
     * @param \GdImage $origem
     */
    private static function redimensionar(\GdImage $origem, int $largura, int $altura): \GdImage
    {
        $escala = min(1.0, self::LADO_MAX / max($largura, $altura));
        $novaLargura = max(1, (int) round($largura * $escala));
        $novaAltura = max(1, (int) round($altura * $escala));

        $destino = imagecreatetruecolor($novaLargura, $novaAltura);
        $branco = imagecolorallocate($destino, 255, 255, 255);
        imagefilledrectangle($destino, 0, 0, $novaLargura, $novaAltura, $branco);

        imagecopyresampled($destino, $origem, 0, 0, 0, 0, $novaLargura, $novaAltura, $largura, $altura);
        imagedestroy($origem);

        return $destino;
    }

    private static function nomeUnico(string $prefixoSku): string
    {
        $base = preg_replace('/[^A-Za-z0-9_-]+/', '-', $prefixoSku) ?? 'produto';
        $base = trim(strtolower($base), '-') ?: 'produto';

        return $base . '-' . bin2hex(random_bytes(6)) . '.jpg';
    }

    private static function diretorio(): string
    {
        $dir = BASE_PATH . '/public/uploads';

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Não foi possível criar o diretório de uploads: {$dir}");
        }

        return $dir;
    }

    private static function mensagemErro(int $codigo): string
    {
        return match ($codigo) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Arquivo maior que o limite do servidor.',
            UPLOAD_ERR_PARTIAL                        => 'O upload foi interrompido no meio.',
            UPLOAD_ERR_NO_TMP_DIR                     => 'Servidor sem diretório temporário.',
            UPLOAD_ERR_CANT_WRITE                     => 'Servidor não conseguiu gravar o arquivo.',
            default                                   => 'Falha no upload do arquivo.',
        };
    }
}
