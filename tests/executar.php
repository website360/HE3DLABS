<?php

declare(strict_types=1);

// Executa a suíte.
//
//   php tests/executar.php
//
// Os testes de mapeamento são puros. Os de integração usam um banco
// separado (DB_NAME + "_teste"), recriado do zero a cada execução, para
// nunca tocar nos dados de trabalho.

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/Afirma.php';

use App\Core\Config;
use App\Core\Db;

$bancoTeste = Config::obrigatorio('DB_NAME') . '_teste';
Config::definir('DB_NAME', $bancoTeste);

$semBanco = Db::connSemBanco();
$semBanco->exec("DROP DATABASE IF EXISTS `{$bancoTeste}`");
$semBanco->exec("CREATE DATABASE `{$bancoTeste}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

foreach (glob(BASE_PATH . '/migrations/*.sql') ?: [] as $arquivo) {
    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($arquivo)) ?? '';

    foreach (preg_split('/;\s*\R/', $sql) ?: [] as $comando) {
        $comando = trim($comando);

        if ($comando !== '') {
            Db::conn()->exec($comando);
        }
    }
}

$arquivos = glob(__DIR__ . '/*/*.php') ?: [];
sort($arquivos);

$total = 0;
$falhas = [];

foreach ($arquivos as $arquivo) {
    $casos = require $arquivo;

    if (!is_array($casos)) {
        continue;
    }

    $grupo = basename(dirname($arquivo)) . '/' . basename($arquivo, '.php');
    echo "\n\033[1m{$grupo}\033[0m\n";

    foreach ($casos as $nome => $caso) {
        $total++;

        try {
            $caso();
            echo "  \033[32m✓\033[0m {$nome}\n";
        } catch (Throwable $e) {
            $falhas[] = "{$grupo} › {$nome}\n     " . $e->getMessage();
            echo "  \033[31m✗\033[0m {$nome}\n";
        }
    }
}

echo "\n" . str_repeat('─', 60) . "\n";

if ($falhas === []) {
    echo "\033[32m{$total} teste(s), tudo passou.\033[0m\n";
    exit(0);
}

echo "\033[31m" . count($falhas) . " de {$total} teste(s) falharam:\033[0m\n\n";

foreach ($falhas as $falha) {
    echo "  {$falha}\n\n";
}

exit(1);
