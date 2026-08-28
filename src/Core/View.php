<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class View
{
    /** Renderiza um template dentro do layout principal. */
    public static function render(string $template, array $dados = [], string $titulo = ''): string
    {
        $conteudo = self::parcial($template, $dados);

        return self::parcial('layout/principal', [
            'conteudo' => $conteudo,
            'titulo'   => $titulo,
            'flash'    => Flash::consumir(),
        ]);
    }

    /** Renderiza um template solto, sem layout. */
    public static function parcial(string $template, array $dados = []): string
    {
        $arquivo = BASE_PATH . '/views/' . $template . '.php';

        if (!is_file($arquivo)) {
            throw new RuntimeException("View não encontrada: {$template}");
        }

        extract($dados, EXTR_SKIP);
        ob_start();
        require $arquivo;

        return (string) ob_get_clean();
    }

    /** Escape para HTML. Nome curto porque aparece em toda interpolação de view. */
    public static function e(mixed $valor): string
    {
        return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function dinheiro(mixed $valor): string
    {
        return 'R$ ' . number_format((float) $valor, 2, ',', '.');
    }

    public static function dataHora(?string $valor): string
    {
        if ($valor === null || $valor === '') {
            return '—';
        }

        return date('d/m/Y H:i', strtotime($valor));
    }
}
