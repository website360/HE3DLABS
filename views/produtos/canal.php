<?php

use App\Core\Csrf;
use App\Core\View;

/** @var array<string,mixed> $produto */
/** @var \App\Dominio\Canal $canal */
/** @var array<string,mixed>|null $conteudo */
/** @var array<int,array<string,mixed>> $variacoes */
/** @var array<int,float> $precos */

$produtoId = (int) $produto['id'];
?>
<div class="topo">
    <div>
        <p class="eyebrow"><?= View::e($produto['sku_base']) ?> · <?= View::e($canal->rotulo()) ?></p>
        <h1>Conteúdo e preço <?= View::e($canal->com('em')) ?></h1>
    </div>
    <div class="acoes">
        <a class="botao" href="/produtos/<?= $produtoId ?>">Voltar ao produto</a>
    </div>
</div>
<div class="camadas"></div>

<form method="post" action="/produtos/<?= $produtoId ?>/canal/<?= $canal->value ?>">
    <?= Csrf::campo() ?>

    <div class="cartao">
        <h2>Texto do anúncio</h2>
        <div class="corpo">
            <label class="campo">
                <span>Título <?= View::e($canal->com('em')) ?></span>
                <input type="text" name="titulo" maxlength="200"
                       value="<?= View::e($conteudo['titulo'] ?? '') ?>"
                       placeholder="<?= View::e($produto['titulo']) ?>">
                <span class="dica">
                    Limite de <?= $canal->limiteTitulo() ?> caracteres neste canal.
                    Em branco, usa o título base — e ele será cortado se passar do limite.
                </span>
            </label>

            <label class="campo">
                <span>Descrição <?= View::e($canal->com('em')) ?></span>
                <textarea name="descricao" placeholder="Em branco, usa a descrição base."><?= View::e($conteudo['descricao'] ?? '') ?></textarea>
            </label>
        </div>
    </div>

    <div class="cartao">
        <h2>Preço por variação</h2>
        <div class="corpo" style="padding-bottom:0">
            <p class="discreto" style="margin-top:0">
                Em branco, o sistema usa o preço base com o markup configurado para este canal
                em <a href="/canais">Canais</a>. As comissões das duas plataformas são bem diferentes.
            </p>
        </div>
        <div class="tabela-rolagem">
            <table>
                <thead>
                <tr>
                    <th>Variação</th>
                    <th class="num">Preço base</th>
                    <th class="num">Preço neste canal</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($variacoes as $variacao): ?>
                    <?php $vid = (int) $variacao['id']; ?>
                    <tr>
                        <td class="mono"><?= View::e($variacao['sku']) ?></td>
                        <td class="num"><?= View::dinheiro($variacao['preco']) ?></td>
                        <td style="width:180px">
                            <input type="text" class="num" name="preco[<?= $vid ?>]"
                                   value="<?= isset($precos[$vid]) ? number_format($precos[$vid], 2, ',', '') : '' ?>"
                                   placeholder="usar o padrão">
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="corpo" style="border-top:1px solid var(--linha)">
            <button class="botao publicar" type="submit">Salvar</button>
        </div>
    </div>
</form>
