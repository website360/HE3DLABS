<?php

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\View;

/** @var string $conteudo */
/** @var string $titulo */
/** @var array{tipo:string,mensagem:string}|null $flash */

$caminho = Request::caminho();
$atual = static function (string $prefixo) use ($caminho): string {
    $ativo = $prefixo === '/'
        ? $caminho === '/'
        : str_starts_with($caminho, $prefixo);

    return $ativo ? ' aria-current="page"' : '';
};

$usuario = Auth::usuario();
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($titulo !== '' ? $titulo . ' — HE 3D Labs' : 'HE 3D Labs') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<div class="app">
    <nav class="lateral">
        <div class="marca">
            <strong>HE 3D Labs</strong>
            <span>Integrador</span>
        </div>

        <ul class="menu">
            <li><a href="/"<?= $atual('/') ?>>Painel</a></li>
            <li><a href="/produtos"<?= $atual('/produtos') ?>>Produtos</a></li>
            <li><a href="/modelos"<?= $atual('/modelos') ?>>Modelos de produto</a></li>
            <li><a href="/canais"<?= $atual('/canais') ?>>Canais</a></li>
            <li><a href="/fila"<?= $atual('/fila') ?>>Fila</a></li>
            <li><a href="/logs"<?= $atual('/logs') ?>>Log das APIs</a></li>
        </ul>

        <?php if ($usuario !== null): ?>
            <div class="rodape-lateral">
                <div style="margin-bottom:8px"><?= View::e($usuario['nome']) ?></div>
                <form method="post" action="/sair">
                    <?= Csrf::campo() ?>
                    <button class="botao pequeno" type="submit">Sair</button>
                </form>
            </div>
        <?php endif; ?>
    </nav>

    <main class="conteudo">
        <?php if ($flash !== null): ?>
            <div class="aviso <?= View::e($flash['tipo'] === 'aviso' ? 'aviso-' : $flash['tipo']) ?>">
                <?= View::e($flash['mensagem']) ?>
            </div>
        <?php endif; ?>

        <?= $conteudo ?>
    </main>
</div>
</body>
</html>
