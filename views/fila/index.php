<?php

use App\Core\Csrf;
use App\Core\View;

/** @var array<int,array<string,mixed>> $jobs */
/** @var array<string,int> $resumo */
?>
<div class="topo">
    <div>
        <p class="eyebrow">Publicação</p>
        <h1>Fila</h1>
    </div>
    <div class="acoes">
        <form method="post" action="/fila/processar">
            <?= Csrf::campo() ?>
            <button class="botao publicar" type="submit">Processar agora</button>
        </form>
    </div>
</div>
<div class="camadas"></div>

<div class="grade k4" style="margin-top:18px">
    <div class="metrica">
        <div class="valor"><?= $resumo['pendente'] ?></div>
        <div class="rotulo">Pendentes</div>
    </div>
    <div class="metrica">
        <div class="valor"><?= $resumo['processando'] ?></div>
        <div class="rotulo">Processando</div>
    </div>
    <div class="metrica">
        <div class="valor" style="color:var(--verde)"><?= $resumo['ok'] ?></div>
        <div class="rotulo">Concluídos</div>
    </div>
    <div class="metrica">
        <div class="valor" style="color:<?= $resumo['erro'] > 0 ? 'var(--vermelho)' : 'inherit' ?>">
            <?= $resumo['erro'] ?>
        </div>
        <div class="rotulo">Com erro</div>
    </div>
</div>

<div class="cartao">
    <div class="corpo" style="padding-bottom:0">
        <p class="discreto" style="margin-top:0;max-width:78ch">
            Em produção, o cron roda <code class="mono">bin/worker.php</code> a cada 5 minutos.
            "Processar agora" faz a mesma coisa pelo navegador, útil enquanto o cron não está
            configurado ou quando você não quer esperar.
        </p>
    </div>
</div>

<div class="cartao">
    <?php if ($jobs === []): ?>
        <div class="vazio"><p>A fila está vazia.</p></div>
    <?php else: ?>
        <div class="tabela-rolagem">
            <table>
                <thead>
                <tr>
                    <th>#</th>
                    <th>Produto</th>
                    <th>Canal</th>
                    <th>Ação</th>
                    <th>Situação</th>
                    <th class="num">Tentativas</th>
                    <th>Próxima em</th>
                    <th>Erro</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($jobs as $job): ?>
                    <tr>
                        <td class="mono"><?= (int) $job['id'] ?></td>
                        <td>
                            <a href="/produtos/<?= (int) $job['produto_id'] ?>"><?= View::e($job['titulo']) ?></a>
                            <div class="discreto mono"><?= View::e($job['sku_base']) ?></div>
                        </td>
                        <td class="mono"><?= View::e($job['canal']) ?></td>
                        <td class="discreto"><?= View::e($job['acao']) ?></td>
                        <td><span class="selo <?= View::e($job['status']) ?>"><?= View::e($job['status']) ?></span></td>
                        <td class="num"><?= (int) $job['tentativas'] ?></td>
                        <td class="mono discreto"><?= View::dataHora($job['proxima_tentativa_em']) ?></td>
                        <td class="discreto" style="max-width:320px">
                            <?= View::e(mb_substr((string) ($job['erro'] ?? ''), 0, 200)) ?>
                        </td>
                        <td style="text-align:right">
                            <?php if ($job['status'] === 'erro'): ?>
                                <form method="post" action="/fila/<?= (int) $job['id'] ?>/reenfileirar">
                                    <?= Csrf::campo() ?>
                                    <button class="botao pequeno" type="submit">Tentar de novo</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
