<?php

use App\Core\View;

/** @var array<string,mixed> $registro */

$formatar = static function (?string $texto): string {
    if ($texto === null || $texto === '') {
        return '(vazio)';
    }

    $json = json_decode($texto, true);

    return $json === null
        ? $texto
        : (string) json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};
?>
<div class="topo">
    <div>
        <p class="eyebrow">Log · <?= View::e($registro['canal']) ?></p>
        <h1>Requisição #<?= (int) $registro['id'] ?></h1>
    </div>
    <div class="acoes"><a class="botao" href="/logs">Voltar</a></div>
</div>
<div class="camadas"></div>

<div class="cartao">
    <div class="corpo">
        <table style="font-size:13px">
            <tbody>
            <tr>
                <th style="width:150px;border:0;padding-left:0">Quando</th>
                <td class="mono" style="border:0"><?= View::dataHora($registro['criado_em']) ?></td>
            </tr>
            <tr>
                <th style="border:0;padding-left:0">Endpoint</th>
                <td class="mono" style="border:0;word-break:break-all">
                    <?= View::e($registro['metodo']) ?> <?= View::e($registro['endpoint']) ?>
                </td>
            </tr>
            <tr>
                <th style="border:0;padding-left:0">Status</th>
                <td style="border:0"><?= $registro['http_status'] ?? 'falha de rede' ?></td>
            </tr>
            <tr>
                <th style="border:0;padding-left:0">Duração</th>
                <td class="mono" style="border:0"><?= (int) $registro['duracao_ms'] ?> ms</td>
            </tr>
            <?php if ($registro['erro'] !== null): ?>
                <tr>
                    <th style="border:0;padding-left:0">Erro</th>
                    <td style="border:0;color:var(--vermelho)"><?= View::e($registro['erro']) ?></td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="cartao">
    <h2>Enviado</h2>
    <div class="corpo"><pre class="bloco"><?= View::e($formatar($registro['requisicao'])) ?></pre></div>
</div>

<div class="cartao">
    <h2>Recebido</h2>
    <div class="corpo"><pre class="bloco"><?= View::e($formatar($registro['resposta'])) ?></pre></div>
</div>
