<?php

use App\Core\Csrf;
use App\Core\View;
use App\Dominio\Canal;

/** @var array<int,array<string,mixed>> $canais */
/** @var string $appUrl */
?>
<div class="topo">
    <div>
        <p class="eyebrow">Configuração</p>
        <h1>Canais</h1>
    </div>
</div>
<div class="camadas"></div>

<div class="cartao">
    <div class="corpo">
        <p class="discreto" style="margin-top:0;max-width:78ch">
            As credenciais de aplicação ficam no arquivo <code class="mono">.env</code>, fora da pasta
            pública — não são editáveis por aqui de propósito. As duas plataformas exigem que a URL de
            retorno seja <strong>HTTPS</strong> e idêntica à cadastrada no portal de desenvolvedor,
            então a conexão só fecha depois que o sistema estiver no domínio com SSL ativo.
        </p>
    </div>
</div>

<?php foreach ($canais as $item): ?>
    <?php
    /** @var Canal $canal */
    $canal = $item['canal'];
    $conta = $item['conta'];
    ?>
    <div class="cartao">
        <h2><?= View::e($canal->rotulo()) ?></h2>
        <div class="corpo">
            <div style="display:flex;flex-wrap:wrap;gap:22px;align-items:flex-start;justify-content:space-between">
                <div style="flex:1 1 320px">
                    <table style="font-size:13px">
                        <tbody>
                        <tr>
                            <th style="width:170px;border:0;padding-left:0">Situação</th>
                            <td style="border:0">
                                <span class="selo <?= $item['conectado'] ? 'publicado' : 'nao_publicado' ?>">
                                    <?= $item['conectado'] ? 'conectado' : 'desconectado' ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th style="border:0;padding-left:0">
                                <?= $canal === Canal::Shopee ? 'Partner ID' : 'Client ID' ?>
                            </th>
                            <td class="mono" style="border:0">
                                <?= View::e($item['credenciais']['id'] ?? 'não definido no .env') ?>
                            </td>
                        </tr>
                        <tr>
                            <th style="border:0;padding-left:0">
                                <?= $canal === Canal::Shopee ? 'Partner Key' : 'Client Secret' ?>
                            </th>
                            <td style="border:0">
                                <?= $item['credenciais']['segredo']
                                    ? '<span class="selo ok">definido</span>'
                                    : '<span class="selo erro">ausente</span>' ?>
                            </td>
                        </tr>
                        <tr>
                            <th style="border:0;padding-left:0">URL de retorno</th>
                            <td class="mono" style="border:0;word-break:break-all">
                                <?= View::e($item['redirect'] ?? '—') ?>
                            </td>
                        </tr>
                        <?php if ($item['conectado']): ?>
                            <tr>
                                <th style="border:0;padding-left:0">Loja</th>
                                <td class="mono" style="border:0"><?= View::e($conta['identificador_loja'] ?? '—') ?></td>
                            </tr>
                            <tr>
                                <th style="border:0;padding-left:0">Token válido até</th>
                                <td class="mono" style="border:0"><?= View::dataHora($conta['expira_em'] ?? null) ?></td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="acoes" style="flex:0 0 auto">
                    <?php if ($item['conectado']): ?>
                        <form method="post" action="/canais/<?= $canal->value ?>/desconectar"
                              onsubmit="return confirm('Desconectar a conta? Os anúncios já publicados continuam no ar, mas o sistema deixa de publicar e atualizar.')">
                            <?= Csrf::campo() ?>
                            <button class="botao perigo" type="submit">Desconectar</button>
                        </form>
                    <?php else: ?>
                        <a class="botao publicar" href="/canais/<?= $canal->value ?>/conectar">
                            Conectar <?= View::e($canal->rotulo()) ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <hr style="border:0;border-top:1px solid var(--linha);margin:18px 0">

            <form method="post" action="/canais/<?= $canal->value ?>/credenciais" class="linha" style="align-items:flex-end">
                <?= Csrf::campo() ?>
                <label class="campo" style="margin:0;flex:0 1 240px">
                    <span>Markup do canal (%)</span>
                    <input type="text" class="num" name="markup"
                           value="<?= number_format((float) ($conta['markup_percentual'] ?? 0), 2, ',', '') ?>">
                    <span class="dica">
                        Aplicado sobre o preço base quando a variação não tem preço próprio neste canal.
                    </span>
                </label>
                <div style="flex:0 0 auto"><button class="botao" type="submit">Salvar markup</button></div>
            </form>

            <?php if ($canal === Canal::Shopee): ?>
                <form method="post" action="/canais/<?= $canal->value ?>/logistica" class="linha"
                      style="align-items:flex-end;margin-top:14px">
                    <?= Csrf::campo() ?>
                    <label class="campo" style="margin:0;flex:1 1 320px">
                        <span>Canais de logística</span>
                        <input type="text" class="mono" name="logisticas"
                               value="<?= View::e(implode(', ', array_map('strval', $conta['extra']['logisticas'] ?? []))) ?>"
                               placeholder="90003, 90005">
                        <span class="dica">
                            Ids separados por vírgula, obtidos em Logística no painel da Shopee.
                            Sem pelo menos um, a Shopee recusa a criação do anúncio — é a causa mais
                            comum de falha em integração nova.
                        </span>
                    </label>
                    <div style="flex:0 0 auto"><button class="botao" type="submit">Salvar logística</button></div>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
