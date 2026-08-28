<?php

declare(strict_types=1);

/**
 * Ponto de partida comum a todas as entradas do sistema:
 * o front controller (public/index.php) e os scripts de cron (bin/).
 */

define('BASE_PATH', __DIR__);

spl_autoload_register(static function (string $classe): void {
    $prefixo = 'App\\';
    if (!str_starts_with($classe, $prefixo)) {
        return;
    }
    $relativo = substr($classe, strlen($prefixo));
    $arquivo = BASE_PATH . '/src/' . str_replace('\\', '/', $relativo) . '.php';
    if (is_file($arquivo)) {
        require $arquivo;
    }
});

App\Core\Config::carregar(BASE_PATH . '/.env');

date_default_timezone_set(App\Core\Config::get('APP_TIMEZONE', 'America/Sao_Paulo'));

mb_internal_encoding('UTF-8');
