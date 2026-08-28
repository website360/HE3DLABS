<?php

declare(strict_types=1);

// Renova os tokens de acesso antes que vençam e faz a limpeza do log.
//
// O access_token do Mercado Livre dura 6 horas e o da Shopee 4, então uma
// execução por hora dá margem folgada. A renovação em si é protegida por
// trava, porque o refresh_token do Mercado Livre é de uso único.
//
// Cron sugerido, de hora em hora:
//   0 * * * * /usr/local/bin/php /home/USUARIO/app/bin/tokens.php >> /home/USUARIO/logs/tokens.log 2>&1

require __DIR__ . '/../bootstrap.php';

use App\Dominio\Canal;
use App\Models\Contas;
use App\Models\LogApi;
use App\Services\Canal\MercadoLivre\Oauth as OauthML;
use App\Services\Canal\Shopee\Oauth as OauthShopee;
use App\Services\Http\ClienteComLog;
use App\Services\Http\ClienteCurl;

$agora = date('Y-m-d H:i:s');
$falhou = false;

foreach (Canal::todos() as $canal) {
    if (!Contas::conectada($canal)) {
        echo "[{$agora}] {$canal->rotulo()}: não conectado, ignorando\n";
        continue;
    }

    if (!Contas::precisaRenovar($canal)) {
        echo "[{$agora}] {$canal->rotulo()}: token ainda válido\n";
        continue;
    }

    $http = new ClienteComLog(new ClienteCurl(), $canal);

    try {
        match ($canal) {
            Canal::MercadoLivre => (new OauthML($http))->renovar(),
            Canal::Shopee       => (new OauthShopee($http))->renovar(),
        };

        echo "[{$agora}] {$canal->rotulo()}: token renovado\n";
    } catch (Throwable $e) {
        $falhou = true;
        echo "[{$agora}] {$canal->rotulo()}: FALHA — {$e->getMessage()}\n";
    }
}

$removidos = LogApi::limpar(30);

if ($removidos > 0) {
    echo "[{$agora}] {$removidos} registro(s) de log removido(s) pela retenção de 30 dias\n";
}

exit($falhou ? 1 : 0);
