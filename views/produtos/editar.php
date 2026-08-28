<?php

use App\Core\Csrf;
use App\Core\View;
use App\Dominio\Canal;

/** @var array<string,mixed> $produto */
/** @var \App\Dominio\Produto|null $dominio */
/** @var array<int,array<string,mixed>> $eixos */
/** @var array<int,array<string,mixed>> $variacoes */
/** @var array<int,array<string,mixed>> $valores */
/** @var array<int,array<string,mixed>> $imagens */
/** @var array<int,array<string,mixed>> $modelos */
/** @var array<string,array<string,mixed>> $anuncios */
/** @var array<string,array<int,string>> $diagnostico */

$produtoId = (int) $produto['id'];

// valores[variacao_id][eixo_id] = valor
$mapaValores = [];
foreach ($valores as $valor) {
    $mapaValores[(int) $valor['variacao_id']][(int) $valor['eixo_id']] = (string) $valor['valor'];
}
?>
<div class="topo">
    <div>
        <p class="eyebrow">Produto · <?= View::e($produto['sku_base']) ?></p>
        <h1><?= View::e($produto['titulo']) ?></h1>
    </div>
    <div class="acoes">
        <a class="botao" href="/produtos">Voltar</a>
        <form method="post" action="/produtos/<?= $produtoId ?>/excluir"
              onsubmit="return confirm('Excluir este produto? Variações, imagens e vínculos de anúncio vão junto. Anúncios já publicados continuam no ar nas plataformas.')">
            <?= Csrf::campo() ?>
            <button class="botao perigo" type="submit">Excluir</button>
        </form>
    </div>
</div>
<div class="camadas"></div>

<!-- ---------------------------------------------------------------
     Assinatura da interface: o pré-voo.
     Mesma lógica de uma checagem pré-impressão — a lista precisa
     fechar antes de a publicação destravar.
     --------------------------------------------------------------- -->
<section style="margin-top:18px">
    <p class="eyebrow" style="margin-bottom:10px">Pré-voo por canal</p>

    <?php foreach (Canal::todos() as $canal): ?>
        <?php
        $problemas = $diagnostico[$canal->value] ?? [];
        $liberado = $problemas === [];
        $anuncio = $anuncios[$canal->value] ?? null;
        $statusAnuncio = $anuncio['status'] ?? 'nao_publicado';
        ?>
        <article class="prevoo" style="--marca-canal: <?= View::e($canal->cor()) ?>">
            <header>
                <h3><?= View::e($canal->rotulo()) ?></h3>
                <div class="acoes">
                    <span class="selo <?= View::e($statusAnuncio) ?>">
                        <?= View::e(str_replace('_', ' ', (string) $statusAnuncio)) ?>
                    </span>

                    <a class="botao pequeno" href="/produtos/<?= $produtoId ?>/canal/<?= $canal->value ?>">
                        Título e preço
                    </a>

                    <?php if (($anuncio['url'] ?? null) !== null): ?>
                        <a class="botao pequeno" href="<?= View::e($anuncio['url']) ?>" target="_blank" rel="noopener">
                            Ver anúncio
                        </a>
                    <?php endif; ?>

                    <?php if ($liberado): ?>
                        <form method="post" action="/produtos/<?= $produtoId ?>/publicar/<?= $canal->value ?>">
                            <?= Csrf::campo() ?>
                            <button class="botao publicar pequeno" type="submit">
                                <?= $statusAnuncio === 'publicado' ? 'Republicar' : 'Publicar' ?>
                            </button>
                        </form>
                    <?php else: ?>
                        <button class="botao pequeno" type="button" aria-disabled="true" disabled
                                title="Resolva os itens abaixo para liberar a publicação">
                            Publicar
                        </button>
                    <?php endif; ?>
                </div>
            </header>

            <div class="trilha <?= $liberado ? '' : 'bloqueada' ?>">
                <div class="preenchida" style="width:100%"></div>
            </div>

            <?php if ($liberado): ?>
                <p class="liberado">Tudo conferido. Este produto pode ir <?= View::e($canal->com('para')) ?>.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($problemas as $problema): ?>
                        <li><?= View::e($problema) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if (($anuncio['ultimo_erro'] ?? null) !== null): ?>
                <div style="padding:0 14px 14px">
                    <div class="aviso erro" style="margin:0">
                        Última resposta da plataforma: <?= View::e($anuncio['ultimo_erro']) ?>
                    </div>
                </div>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</section>

<!-- Identificação -->
<form method="post" action="/produtos/<?= $produtoId ?>">
    <?= Csrf::campo() ?>
    <div class="cartao">
        <h2>Identificação</h2>
        <div class="corpo">
            <div class="linha">
                <label class="campo">
                    <span>SKU base</span>
                    <input type="text" name="sku_base" class="mono" value="<?= View::e($produto['sku_base']) ?>" required>
                </label>
                <label class="campo">
                    <span>Marca</span>
                    <input type="text" name="marca" value="<?= View::e($produto['marca'] ?? '') ?>">
                </label>
                <label class="campo">
                    <span>Situação</span>
                    <select name="status">
                        <option value="rascunho" <?= $produto['status'] === 'rascunho' ? 'selected' : '' ?>>Rascunho</option>
                        <option value="pronto" <?= $produto['status'] === 'pronto' ? 'selected' : '' ?>>Pronto</option>
                    </select>
                </label>
            </div>

            <label class="campo">
                <span>Título base</span>
                <input type="text" name="titulo" value="<?= View::e($produto['titulo']) ?>" required maxlength="200">
            </label>

            <label class="campo">
                <span>Descrição</span>
                <textarea name="descricao"><?= View::e($produto['descricao'] ?? '') ?></textarea>
            </label>

            <label class="campo">
                <span>Modelo de produto</span>
                <select name="modelo_id">
                    <option value="">Sem modelo</option>
                    <?php foreach ($modelos as $modelo): ?>
                        <option value="<?= (int) $modelo['id'] ?>"
                            <?= (int) ($produto['modelo_id'] ?? 0) === (int) $modelo['id'] ? 'selected' : '' ?>>
                            <?= View::e($modelo['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <button class="botao" type="submit">Salvar produto</button>
        </div>
    </div>
</form>

<!-- Eixos -->
<form method="post" action="/produtos/<?= $produtoId ?>/eixos">
    <?= Csrf::campo() ?>
    <div class="cartao">
        <h2>Eixos de variação</h2>
        <div class="corpo">
            <p class="discreto" style="margin-top:0">
                No máximo dois, limite da Shopee. Deixe os dois em branco para um produto simples.
                Trocar um eixo apaga os valores já preenchidos nas variações.
            </p>
            <div class="linha">
                <label class="campo">
                    <span>Primeiro eixo</span>
                    <input type="text" name="eixos[]" placeholder="Cor"
                           value="<?= View::e($eixos[0]['nome'] ?? '') ?>">
                </label>
                <label class="campo">
                    <span>Segundo eixo</span>
                    <input type="text" name="eixos[]" placeholder="Tamanho"
                           value="<?= View::e($eixos[1]['nome'] ?? '') ?>">
                </label>
            </div>
            <button class="botao" type="submit">Salvar eixos</button>
        </div>
    </div>
</form>

<!-- Variações -->
<form method="post" action="/produtos/<?= $produtoId ?>/variacoes">
    <?= Csrf::campo() ?>
    <div class="cartao">
        <h2>Variações</h2>
        <div class="tabela-rolagem">
            <table>
                <thead>
                <tr>
                    <th>SKU</th>
                    <?php foreach ($eixos as $eixo): ?>
                        <th><?= View::e($eixo['nome']) ?></th>
                    <?php endforeach; ?>
                    <th class="num">Preço</th>
                    <th class="num">Estoque</th>
                    <th class="num">Peso (g)</th>
                    <th class="num">C × L × A (cm)</th>
                    <th>GTIN</th>
                    <th>Ativa</th>
                    <th>Excluir</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($variacoes as $variacao): ?>
                    <?php $vid = (int) $variacao['id']; ?>
                    <tr>
                        <td><input type="text" name="variacao[<?= $vid ?>][sku]"
                                   value="<?= View::e($variacao['sku']) ?>" style="min-width:150px"></td>

                        <?php foreach ($eixos as $eixo): ?>
                            <td><input type="text" style="min-width:110px"
                                       name="variacao[<?= $vid ?>][valores][<?= (int) $eixo['id'] ?>]"
                                       value="<?= View::e($mapaValores[$vid][(int) $eixo['id']] ?? '') ?>"></td>
                        <?php endforeach; ?>

                        <td><input type="text" name="variacao[<?= $vid ?>][preco]" style="min-width:92px"
                                   value="<?= number_format((float) $variacao['preco'], 2, ',', '') ?>"></td>
                        <td><input type="number" name="variacao[<?= $vid ?>][estoque]" style="min-width:76px"
                                   value="<?= (int) $variacao['estoque'] ?>"></td>
                        <td><input type="number" name="variacao[<?= $vid ?>][peso_g]" style="min-width:80px"
                                   value="<?= (int) $variacao['peso_g'] ?>"></td>
                        <td style="white-space:nowrap">
                            <input type="text" name="variacao[<?= $vid ?>][comprimento_cm]" style="width:62px;display:inline"
                                   value="<?= rtrim(rtrim(number_format((float) $variacao['comprimento_cm'], 2, ',', ''), '0'), ',') ?>">
                            <input type="text" name="variacao[<?= $vid ?>][largura_cm]" style="width:62px;display:inline"
                                   value="<?= rtrim(rtrim(number_format((float) $variacao['largura_cm'], 2, ',', ''), '0'), ',') ?>">
                            <input type="text" name="variacao[<?= $vid ?>][altura_cm]" style="width:62px;display:inline"
                                   value="<?= rtrim(rtrim(number_format((float) $variacao['altura_cm'], 2, ',', ''), '0'), ',') ?>">
                        </td>
                        <td><input type="text" name="variacao[<?= $vid ?>][gtin]" style="min-width:120px"
                                   value="<?= View::e($variacao['gtin'] ?? '') ?>"></td>
                        <td style="text-align:center">
                            <input type="checkbox" name="variacao[<?= $vid ?>][ativo]" value="1"
                                <?= (int) $variacao['ativo'] === 1 ? 'checked' : '' ?>>
                        </td>
                        <td style="text-align:center">
                            <input type="checkbox" name="variacao[<?= $vid ?>][excluir]" value="1">
                        </td>
                    </tr>
                <?php endforeach; ?>

                <!-- Linha em branco para acrescentar uma variação -->
                <tr style="background:#fbfcfd">
                    <td><input type="text" name="variacao[novo1][sku]" placeholder="novo SKU" style="min-width:150px"></td>
                    <?php foreach ($eixos as $eixo): ?>
                        <td><input type="text" style="min-width:110px"
                                   name="variacao[novo1][valores][<?= (int) $eixo['id'] ?>]"
                                   placeholder="<?= View::e($eixo['nome']) ?>"></td>
                    <?php endforeach; ?>
                    <td><input type="text" name="variacao[novo1][preco]" style="min-width:92px" placeholder="0,00"></td>
                    <td><input type="number" name="variacao[novo1][estoque]" style="min-width:76px" placeholder="0"></td>
                    <td><input type="number" name="variacao[novo1][peso_g]" style="min-width:80px" placeholder="0"></td>
                    <td style="white-space:nowrap">
                        <input type="text" name="variacao[novo1][comprimento_cm]" style="width:62px;display:inline" placeholder="C">
                        <input type="text" name="variacao[novo1][largura_cm]" style="width:62px;display:inline" placeholder="L">
                        <input type="text" name="variacao[novo1][altura_cm]" style="width:62px;display:inline" placeholder="A">
                    </td>
                    <td><input type="text" name="variacao[novo1][gtin]" style="min-width:120px"></td>
                    <td style="text-align:center"><input type="checkbox" name="variacao[novo1][ativo]" value="1" checked></td>
                    <td></td>
                </tr>
                </tbody>
            </table>
        </div>
        <div class="corpo" style="border-top:1px solid var(--linha)">
            <button class="botao" type="submit">Salvar variações</button>
        </div>
    </div>
</form>

<!-- Imagens -->
<div class="cartao">
    <h2>Imagens</h2>
    <div class="corpo">
        <?php if ($imagens === []): ?>
            <p class="discreto" style="margin-top:0">
                Nenhuma imagem ainda. As duas plataformas exigem pelo menos uma.
            </p>
        <?php else: ?>
            <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:18px">
                <?php foreach ($imagens as $imagem): ?>
                    <figure style="margin:0;text-align:center">
                        <img src="/uploads/<?= View::e($imagem['arquivo']) ?>" alt=""
                             style="width:104px;height:104px;object-fit:cover;border-radius:var(--raio);border:1px solid var(--linha)">
                        <form method="post" action="/imagens/<?= (int) $imagem['id'] ?>/excluir" style="margin-top:6px">
                            <?= Csrf::campo() ?>
                            <button class="botao pequeno perigo" type="submit">Remover</button>
                        </form>
                    </figure>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="/produtos/<?= $produtoId ?>/imagens" enctype="multipart/form-data">
            <?= Csrf::campo() ?>
            <label class="campo">
                <span>Enviar imagens</span>
                <input type="file" name="imagens[]" multiple accept="image/*">
                <span class="dica">
                    JPG, PNG ou WEBP até 10 MB. São convertidas para JPEG e reduzidas a 1600 px.
                </span>
            </label>
            <button class="botao" type="submit">Enviar</button>
        </form>
    </div>
</div>
