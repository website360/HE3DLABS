<?php

use App\Core\Csrf;
use App\Core\View;
use App\Dominio\Canal;

/** @var array<string,mixed> $modelo */
/** @var array<string,array<string,mixed>> $configuracoes */
/** @var array<string,array<int,array<string,mixed>>> $atributos */
/** @var array<string,bool> $conectados */

$modeloId = (int) $modelo['id'];
?>
<div class="topo">
    <div>
        <p class="eyebrow">Modelo de produto</p>
        <h1><?= View::e($modelo['nome']) ?></h1>
    </div>
    <div class="acoes">
        <a class="botao" href="/modelos">Voltar</a>
        <form method="post" action="/modelos/<?= $modeloId ?>/excluir"
              onsubmit="return confirm('Excluir este modelo? Os produtos que o usam ficam sem categoria e não poderão ser publicados até receberem outro.')">
            <?= Csrf::campo() ?>
            <button class="botao perigo" type="submit">Excluir</button>
        </form>
    </div>
</div>
<div class="camadas"></div>

<form method="post" action="/modelos/<?= $modeloId ?>">
    <?= Csrf::campo() ?>

    <div class="cartao">
        <h2>Nome</h2>
        <div class="corpo">
            <label class="campo" style="margin:0;max-width:420px">
                <span>Nome do modelo</span>
                <input type="text" name="nome" value="<?= View::e($modelo['nome']) ?>" required>
            </label>
        </div>
    </div>

    <?php foreach (Canal::todos() as $canal): ?>
        <?php
        $config = $configuracoes[$canal->value] ?? null;
        $lista = $atributos[$canal->value] ?? [];
        $preenchidos = is_array($config['atributos'] ?? null) ? $config['atributos'] : [];
        $eixosMapeados = is_array($preenchidos['eixos'] ?? null) ? $preenchidos['eixos'] : [];
        ?>
        <div class="cartao">
            <h2><?= View::e($canal->rotulo()) ?></h2>
            <div class="corpo">
                <?php if (!($conectados[$canal->value] ?? false)): ?>
                    <div class="aviso aviso-">
                        A conta <?= View::e($canal->com('de')) ?> não está conectada, então a busca de
                        categorias e atributos não funciona ainda.
                        <a href="/canais">Conectar em Canais</a>.
                    </div>
                <?php endif; ?>

                <div class="linha">
                    <label class="campo">
                        <span>Buscar categoria</span>
                        <input type="search" class="busca-categoria" data-canal="<?= $canal->value ?>"
                               data-modelo="<?= $modeloId ?>" placeholder="digite ao menos 2 letras">
                        <span class="dica">A lista vem da própria plataforma e fica em cache local.</span>
                    </label>
                    <label class="campo">
                        <span>Categoria escolhida</span>
                        <input type="text" class="mono" name="categoria_<?= $canal->value ?>"
                               id="categoria-<?= $canal->value ?>"
                               value="<?= View::e($config['categoria_id_remota'] ?? '') ?>"
                               placeholder="id da categoria">
                        <span class="dica" id="categoria-nome-<?= $canal->value ?>">
                            <?= View::e($config['categoria_nome'] ?? 'Nenhuma categoria definida.') ?>
                        </span>
                    </label>
                </div>

                <div class="resultado-categoria" id="resultado-<?= $canal->value ?>"></div>

                <?php if ($lista !== []): ?>
                    <p class="eyebrow" style="margin:18px 0 8px">Atributos da categoria</p>
                    <div class="linha">
                        <?php foreach ($lista as $atributo): ?>
                            <label class="campo" style="flex:1 1 260px">
                                <span>
                                    <?= View::e($atributo['nome']) ?>
                                    <?= ($atributo['obrigatorio'] ?? false) ? ' *' : '' ?>
                                </span>
                                <?php if (($atributo['valores'] ?? []) !== []): ?>
                                    <select name="atributo_<?= $canal->value ?>[<?= View::e($atributo['id']) ?>]">
                                        <option value="">—</option>
                                        <?php foreach ($atributo['valores'] as $valor): ?>
                                            <option value="<?= View::e($valor['nome']) ?>"
                                                <?= ($preenchidos[$atributo['id']] ?? '') === $valor['nome'] ? 'selected' : '' ?>>
                                                <?= View::e($valor['nome']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input type="text" name="atributo_<?= $canal->value ?>[<?= View::e($atributo['id']) ?>]"
                                           value="<?= View::e($preenchidos[$atributo['id']] ?? '') ?>">
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="discreto">
                        Escolha uma categoria e salve para o sistema carregar os atributos obrigatórios dela.
                    </p>
                <?php endif; ?>

                <?php if ($canal === Canal::MercadoLivre): ?>
                    <p class="eyebrow" style="margin:18px 0 8px">Mapeamento de eixos</p>
                    <p class="discreto" style="margin-top:0;max-width:70ch">
                        O Mercado Livre identifica variações por id de atributo, não por texto livre.
                        "Cor", "Tamanho" e "Material" já são reconhecidos automaticamente; qualquer
                        outro nome precisa ser mapeado aqui, senão a publicação é recusada.
                    </p>
                    <div class="linha">
                        <?php foreach (['Cor' => 'COLOR', 'Tamanho' => 'SIZE', 'Material' => 'MATERIAL'] as $nomeEixo => $exemplo): ?>
                            <label class="campo" style="flex:1 1 200px">
                                <span><?= View::e($nomeEixo) ?></span>
                                <input type="text" class="mono"
                                       name="eixo_<?= $canal->value ?>[<?= View::e($nomeEixo) ?>]"
                                       value="<?= View::e($eixosMapeados[$nomeEixo] ?? '') ?>"
                                       placeholder="<?= View::e($exemplo) ?>">
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="acoes" style="margin-top:18px">
        <button class="botao publicar" type="submit">Salvar modelo</button>
    </div>
</form>

<script>
// Busca de categorias: consulta o cache local primeiro e cai na API do
// canal só quando não há nada guardado.
document.querySelectorAll('.busca-categoria').forEach(function (campo) {
    var espera;

    campo.addEventListener('input', function () {
        clearTimeout(espera);
        var termo = campo.value.trim();
        var canal = campo.dataset.canal;
        var alvo = document.getElementById('resultado-' + canal);

        if (termo.length < 2) {
            alvo.innerHTML = '';
            return;
        }

        espera = setTimeout(function () {
            alvo.innerHTML = '<p class="discreto">Procurando…</p>';

            fetch('/modelos/' + campo.dataset.modelo + '/categorias?canal=' + canal
                  + '&termo=' + encodeURIComponent(termo))
                .then(function (r) { return r.json(); })
                .then(function (dados) {
                    if (dados.erro) {
                        alvo.innerHTML = '<div class="aviso erro">' + dados.erro + '</div>';
                        return;
                    }
                    if (!dados.categorias.length) {
                        alvo.innerHTML = '<p class="discreto">Nada encontrado para “' + termo + '”.</p>';
                        return;
                    }

                    var html = '<div class="tabela-rolagem"><table><tbody>';
                    dados.categorias.forEach(function (c) {
                        html += '<tr><td class="mono">' + c.id + '</td><td>' + (c.caminho || c.nome)
                             + '</td><td style="text-align:right"><button type="button" class="botao pequeno" '
                             + 'data-id="' + c.id + '" data-nome="' + (c.nome || '').replace(/"/g, '&quot;')
                             + '">Usar</button></td></tr>';
                    });
                    html += '</tbody></table></div>';
                    alvo.innerHTML = html;

                    alvo.querySelectorAll('button[data-id]').forEach(function (botao) {
                        botao.addEventListener('click', function () {
                            document.getElementById('categoria-' + canal).value = botao.dataset.id;
                            document.getElementById('categoria-nome-' + canal).textContent = botao.dataset.nome;
                            alvo.innerHTML = '';
                            campo.value = '';
                        });
                    });
                })
                .catch(function () {
                    alvo.innerHTML = '<div class="aviso erro">Não foi possível consultar as categorias.</div>';
                });
        }, 350);
    });
});
</script>
