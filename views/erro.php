<?php

use App\Core\View;

/** @var string $mensagem */
?>
<div class="topo">
    <div>
        <p class="eyebrow">Ops</p>
        <h1>Não foi possível continuar</h1>
    </div>
</div>
<div class="camadas"></div>

<div class="cartao">
    <div class="vazio">
        <p><?= View::e($mensagem) ?></p>
        <a class="botao" href="/">Voltar ao painel</a>
    </div>
</div>
