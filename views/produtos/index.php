<?php

use App\Core\View;

/** @var array<int,array<string,mixed>> $produtos */
/** @var string $busca */
/** @var string $status */
?>
<div class="topo">
    <div>
        <p class="eyebrow">Catálogo</p>
        <h1>Produtos</h1>
    </div>
    <div class="acoes">
        <a class="botao publicar" href="/produtos/novo">Novo produto</a>
    </div>
</div>
<div class="camadas"></div>

<div class="cartao">
    <div class="corpo">
        <form method="get" action="/produtos" class="linha" style="align-items:flex-end">
            <label class="campo" style="margin:0">
                <span>Buscar</span>
                <input type="search" name="busca" value="<?= View::e($busca) ?>" placeholder="título ou SKU">
            </label>
            <label class="campo" style="margin:0;flex:0 1 190px">
                <span>Situação</span>
                <select name="status">
                    <option value="">Todas</option>
                    <option value="rascunho" <?= $status === 'rascunho' ? 'selected' : '' ?>>Rascunho</option>
                    <option value="pronto" <?= $status === 'pronto' ? 'selected' : '' ?>>Pronto</option>
                </select>
            </label>
            <div style="flex:0 0 auto">
                <button class="botao" type="submit">Filtrar</button>
            </div>
        </form>
    </div>
</div>

<div class="cartao">
    <?php if ($produtos === []): ?>
        <div class="vazio">
            <p>Nenhum produto cadastrado ainda.</p>
            <a class="botao publicar" href="/produtos/novo">Cadastrar o primeiro</a>
        </div>
    <?php else: ?>
        <div class="tabela-rolagem">
            <table>
                <thead>
                <tr>
                    <th></th>
                    <th>Produto</th>
                    <th>Modelo</th>
                    <th class="num">Variações</th>
                    <th class="num">Estoque</th>
                    <th class="num">A partir de</th>
                    <th>Situação</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($produtos as $produto): ?>
                    <tr>
                        <td style="width:52px">
                            <?php if ($produto['capa'] !== null): ?>
                                <img class="capa" src="/uploads/<?= View::e($produto['capa']) ?>" alt="">
                            <?php else: ?>
                                <div class="capa"></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="/produtos/<?= (int) $produto['id'] ?>"><?= View::e($produto['titulo']) ?></a>
                            <div class="discreto mono"><?= View::e($produto['sku_base']) ?></div>
                        </td>
                        <td class="discreto"><?= View::e($produto['modelo_nome'] ?? '—') ?></td>
                        <td class="num"><?= (int) $produto['total_variacoes'] ?></td>
                        <td class="num"><?= (int) $produto['estoque_total'] ?></td>
                        <td class="num"><?= $produto['preco_min'] !== null ? View::dinheiro($produto['preco_min']) : '—' ?></td>
                        <td>
                            <span class="selo <?= $produto['status'] === 'pronto' ? 'ok' : 'nao_publicado' ?>">
                                <?= View::e($produto['status']) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
