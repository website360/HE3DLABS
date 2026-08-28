<?php

use App\Core\Csrf;
use App\Core\View;

/** @var string|null $erro */
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entrar — HE 3D Labs</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<div class="tela-login">
    <form class="caixa-login" method="post" action="/login">
        <?= Csrf::campo() ?>

        <p class="eyebrow">HE 3D Labs · Integrador</p>
        <h1>Entrar</h1>

        <?php if ($erro !== null): ?>
            <div class="aviso erro" style="margin-top:16px"><?= View::e($erro) ?></div>
        <?php endif; ?>

        <div class="campo" style="margin-top:18px">
            <label>
                <span>E-mail</span>
                <input type="email" name="email" autocomplete="username" required autofocus>
            </label>
        </div>

        <div class="campo">
            <label>
                <span>Senha</span>
                <input type="password" name="senha" autocomplete="current-password" required>
            </label>
        </div>

        <button class="botao publicar" type="submit">Entrar</button>
    </form>
</div>
</body>
</html>
