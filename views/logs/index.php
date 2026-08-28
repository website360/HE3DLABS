<?php

use App\Core\View;

/** @var array<int,array<string,mixed>> $registros */
/** @var string $canal */
?>
<div class="topo">
    <div>
        <p class="eyebrow">Diagnóstico</p>
        <h1>Log das APIs</h1>
    </div>
</div>
<div class="camadas"></div>

<div class="cartao">
    <div class="corpo">
        <p class="discreto" style="margin-top:0;max-width:78ch">
            Toda requisição enviada às plataformas, com corpo de ida e volta. Quando um marketplace
            recusa um anúncio com mensagem vaga, é aqui que se descobre o que foi realmente enviado.
            Registros com mais de 30 dias são apagados pelo cron.
        </p>
        <form method="get" action="/logs" class="linha" style="align-items:flex-end;margin-top:14px">
            <label class="campo" style="margin:0;flex:0 1 240px">
                <span>Canal</span>
                <select name="canal">
                    <option value="">Todos</option>
                    <option value="mercadolivre" <?= $canal === 'mercadolivre' ? 'selected' : '' ?>>Mercado Livre</option>
                    <option value="shopee" <?= $canal === 'shopee' ? 'selected' : '' ?>>Shopee</option>
                </select>
            </label>
            <div style="flex:0 0 auto"><button class="botao" type="submit">Filtrar</button></div>
        </form>
    </div>
</div>

<div class="cartao">
    <?php if ($registros === []): ?>
        <div class="vazio">
            <p>Nada registrado ainda. O log começa a encher na primeira chamada às plataformas.</p>
        </div>
    <?php else: ?>
        <div class="tabela-rolagem">
            <table>
                <thead>
                <tr>
                    <th>Quando</th>
                    <th>Canal</th>
                    <th>Requisição</th>
                    <th class="num">Status</th>
                    <th class="num">Tempo</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($registros as $registro): ?>
                    <?php
                    $status = $registro['http_status'] === null ? null : (int) $registro['http_status'];
                    $classe = $status === null ? 'erro' : ($status < 300 ? 'ok' : 'erro');
                    ?>
                    <tr>
                        <td class="mono discreto"><?= View::dataHora($registro['criado_em']) ?></td>
                        <td class="mono"><?= View::e($registro['canal']) ?></td>
                        <td class="mono" style="max-width:420px;word-break:break-all">
                            <?= View::e($registro['metodo']) ?>
                            <?= View::e(mb_substr((string) $registro['endpoint'], 0, 110)) ?>
                        </td>
                        <td class="num">
                            <span class="selo <?= $classe ?>"><?= $status ?? 'rede' ?></span>
                        </td>
                        <td class="num discreto"><?= (int) $registro['duracao_ms'] ?> ms</td>
                        <td style="text-align:right">
                            <a class="botao pequeno" href="/logs/<?= (int) $registro['id'] ?>">Detalhe</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
