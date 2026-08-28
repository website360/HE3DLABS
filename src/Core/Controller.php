<?php

declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    protected function view(string $template, array $dados = [], string $titulo = ''): string
    {
        return View::render($template, $dados, $titulo);
    }

    protected function redirecionar(string $caminho): string
    {
        header('Location: ' . $caminho);

        return '';
    }

    protected function json(mixed $dados, int $status = 200): string
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        return (string) json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected function naoEncontrado(string $mensagem = 'Registro não encontrado.'): never
    {
        http_response_code(404);
        echo View::render('erro', ['mensagem' => $mensagem], 'Não encontrado');
        exit;
    }
}
