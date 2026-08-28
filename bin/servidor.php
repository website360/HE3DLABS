<?php

declare(strict_types=1);

// Router do servidor embutido do PHP, só para desenvolvimento.
//
//   php -S 127.0.0.1:8090 -t public bin/servidor.php
//
// Em produção quem faz este papel é o mod_rewrite do .htaccess: arquivo
// existente é servido direto, o resto vai para o front controller.

$caminho = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$caminho = is_string($caminho) ? $caminho : '/';

$arquivo = __DIR__ . '/../public' . $caminho;

// Devolver false faz o servidor embutido servir o arquivo estático.
if ($caminho !== '/' && is_file($arquivo)) {
    return false;
}

require __DIR__ . '/../public/index.php';
