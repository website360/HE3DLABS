<?php

use App\Core\View;

/** @var int $totalProdutos */
/** @var int $totalVariacoes */
/** @var array<string,int> $anuncios */
/** @var array<string,int> $fila */
/** @var array<string,array<string,mixed>> $conexoes */
/** @var array<int,array<string,mixed>> $comProblema */
?>
<div class="topo">
    <div>
        <p class="eyebrow">Visão geral</p>
        <h1>Painel</h1>
    </div>
    <div class="acoes">
        <a class="botao" href="/produtos/novo">Novo produto</a>
    </div>
</div>
<div class="camadas"></div>

<div class="grade k4" style="margin-top:18px">
    <div class="metrica">
        <div class="valor"><?= $totalProdutos ?></div>
        <div class="rotulo">Produtos</div>
    </div>
    <div class="metrica">
        <div class="valor"><?= $totalVariacoes ?></div>
        <div class="rotulo">Variações</div>
    </div>
    <div class="metrica">
        <div class="valor" style="color:var(--verde)"><?= $anuncios['publicado'] ?></div>
        <div class="rotulo">Anúncios no ar</div>
    </div>
    <div class="metrica">
        <div class="valor" style="color:<?= $anuncios['erro'] > 0 ? 'var(--vermelho)' : 'inherit' ?>">
            <?= $anuncios['erro'] ?>
        </div>
        <div class="rotulo">Com erro</div>
    </div>
</div>

<div class="grade k2">
    <div class="cartao">
        <h2>Conexão dos canais</h2>
        <div class="corpo">
            <?php foreach ($conexoes as $conexao): ?>
                <?php $canal = $conexao['canal']; ?>
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:9px 0">
                    <div>
                        <strong style="font-size:14px"><?= View::e($canal->rotulo()) ?></strong>
                        <?php if ($conexao['conectado']): ?>
                            <div class="discreto mono">
                                loja <?= View::e($conexao['loja'] ?? '—') ?> ·
                                token até <?= View::dataHora($conexao['expira_em']) ?>
                            </div>
                        <?php else: ?>
                            <div class="discreto">Ainda não conectado</div>
                        <?php endif; ?>
                    </div>
                    <?php if ($conexao['conectado']): ?>
                        <span class="selo publicado">conectado</span>
                    <?php else: ?>
                        <a class="botao pequeno" href="/canais">Conectar</a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="cartao">
        <h2>Fila de publicação</h2>
        <div class="corpo">
            <div class="grade k2" style="gap:10px">
                <div class="metrica">
                    <div class="valor" style="font-size:20px"><?= $fila['pendente'] + $fila['processando'] ?></div>
                    <div class="rotulo">Aguardando</div>
                </div>
                <div class="metrica">
                    <div class="valor" style="font-size:20px;color:<?= $fila['erro'] > 0 ? 'var(--vermelho)' : 'inherit' ?>">
                        <?= $fila['erro'] ?>
                    </div>
                    <div class="rotulo">Falharam</div>
                </div>
            </div>
            <div class="acoes" style="margin-top:14px">
                <a class="botao pequeno" href="/fila">Abrir a fila</a>
            </div>
        </div>
    </div>
</div>

<?php if ($comProblema !== []): ?>
    <div class="cartao">
        <h2>Anúncios que precisam de atenção</h2>
        <div class="tabela-rolagem">
            <table>
                <thead>
                <tr>
                    <th>Produto</th>
                    <th>Canal</th>
                    <th>Erro</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($comProblema as $anuncio): ?>
                    <tr>
                        <td>
                            <?= View::e($anuncio['titulo']) ?>
                            <div class="discreto mono"><?= View::e($anuncio['sku_base']) ?></div>
                        </td>
                        <td class="mono"><?= View::e($anuncio['canal']) ?></td>
                        <td class="discreto"><?= View::e(mb_substr((string) $anuncio['ultimo_erro'], 0, 160)) ?></td>
                        <td>
                            <a class="botao pequeno" href="/produtos/<?= (int) $anuncio['produto_id'] ?>">Abrir</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
