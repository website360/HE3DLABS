<?php

use App\Core\Csrf;
use App\Core\View;

/** @var array<int,array<string,mixed>> $modelos */
?>
<div class="topo">
    <div>
        <p class="eyebrow">Catálogo</p>
        <h1>Novo produto</h1>
    </div>
    <div class="acoes"><a class="botao" href="/produtos">Voltar</a></div>
</div>
<div class="camadas"></div>

<form method="post" action="/produtos">
    <?= Csrf::campo() ?>

    <div class="cartao">
        <h2>Identificação</h2>
        <div class="corpo">
            <div class="linha">
                <label class="campo">
                    <span>SKU base</span>
                    <input type="text" name="sku_base" class="mono" required
                           placeholder="HE3D-SUPFONE" autofocus>
                    <span class="dica">Identificador interno. Também vira o SKU da primeira variação.</span>
                </label>
                <label class="campo">
                    <span>Marca</span>
                    <input type="text" name="marca" value="HE 3D Labs">
                </label>
            </div>

            <label class="campo">
                <span>Título</span>
                <input type="text" name="titulo" required maxlength="200"
                       placeholder="Suporte de Fone de Ouvido para Mesa em PLA">
                <span class="dica">
                    Este é o título base. Cada canal pode ter o seu próprio depois —
                    o Mercado Livre corta em 60 caracteres e a Shopee em 120.
                </span>
            </label>

            <label class="campo">
                <span>Descrição</span>
                <textarea name="descricao" placeholder="Do que é feito, como usar, o que vai na caixa."></textarea>
            </label>

            <label class="campo">
                <span>Modelo de produto</span>
                <select name="modelo_id">
                    <option value="">Sem modelo</option>
                    <?php foreach ($modelos as $modelo): ?>
                        <option value="<?= (int) $modelo['id'] ?>"><?= View::e($modelo['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="dica">
                    O modelo define a categoria e os atributos nos dois canais.
                    Sem ele, o produto não pode ser publicado.
                </span>
            </label>
        </div>
    </div>

    <div class="acoes" style="margin-top:18px">
        <button class="botao publicar" type="submit">Criar produto</button>
        <a class="botao" href="/produtos">Cancelar</a>
    </div>
</form>
