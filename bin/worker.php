<?php

declare(strict_types=1);

// Drena a fila de publicação.
//
// Cron sugerido, a cada 5 minutos:
//   */5 * * * * /usr/local/bin/php /home/USUARIO/app/bin/worker.php >> /home/USUARIO/logs/worker.log 2>&1

require __DIR__ . '/../bootstrap.php';

use App\Services\Fila\Worker;

$inicio = microtime(true);

$resumo = (new Worker())->processarLote();

$duracao = round(microtime(true) - $inicio, 2);
$agora = date('Y-m-d H:i:s');

if ($resumo['processados'] === 0) {
    echo "[{$agora}] fila vazia\n";
    exit(0);
}

echo "[{$agora}] {$resumo['processados']} job(s) em {$duracao}s — "
    . "{$resumo['ok']} ok, {$resumo['erro']} com erro\n";

foreach ($resumo['mensagens'] as $mensagem) {
    echo "  {$mensagem}\n";
}

// Código de saída diferente de zero deixa a falha visível no log do cron.
exit($resumo['erro'] > 0 ? 1 : 0);
