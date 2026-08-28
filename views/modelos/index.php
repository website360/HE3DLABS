<?php

use App\Core\Csrf;
use App\Core\View;

/** @var array<int,array<string,mixed>> $modelos */
?>
<div class="topo">
    <div>
        <p class="eyebrow">Configuração</p>
        <h1>Modelos de produto</h1>
    </div>
</div>
<div class="camadas"></div>

<div class="cartao">
    <div class="corpo">
        <p class="discreto" style="margin-top:0;max-width:70ch">
            Um modelo guarda a categoria e os atributos obrigatórios de cada plataforma para um tipo
            de peça. Configure "Suporte de fone" uma vez e todo produto desse tipo já nasce pronto
            para publicar, em vez de exigir o preenchimento de quinze campos a cada cadastro.
        </p>
        <form method="post" action="/modelos" class="linha" style="align-items:flex-end;margin-top:14px">
            <?= Csrf::campo() ?>
            <label class="campo" style="margin:0">
                <span>Novo modelo</span>
                <input type="text" name="nome" placeholder="Suporte de fone" required>
            </label>
            <div style="flex:0 0 auto"><button class="botao" type="submit">Criar</button></div>
        </form>
    </div>
</div>

<div class="cartao">
    <?php if ($modelos === []): ?>
        <div class="vazio"><p>Nenhum modelo ainda. Crie o primeiro acima.</p></div>
    <?php else: ?>
        <div class="tabela-rolagem">
            <table>
                <thead>
                <tr>
                    <th>Modelo</th>
                    <th class="num">Produtos</th>
                    <th class="num">Canais configurados</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($modelos as $modelo): ?>
                    <tr>
                        <td><a href="/modelos/<?= (int) $modelo['id'] ?>"><?= View::e($modelo['nome']) ?></a></td>
                        <td class="num"><?= (int) $modelo['total_produtos'] ?></td>
                        <td class="num">
                            <?php if ((int) $modelo['canais_configurados'] === 2): ?>
                                <span class="selo ok">2 de 2</span>
                            <?php else: ?>
                                <span class="selo nao_publicado"><?= (int) $modelo['canais_configurados'] ?> de 2</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right">
                            <a class="botao pequeno" href="/modelos/<?= (int) $modelo['id'] ?>">Configurar</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
