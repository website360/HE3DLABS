<?php

use App\Core\Csrf;
use App\Core\View;
use App\Dominio\Canal;

/** @var array<int,array<string,mixed>> $canais */
/** @var string $appUrl */

$tutoriais = [
    'mercadolivre' => [
        'portal' => 'https://developers.mercadolivre.com.br/devcenter',
        'docs'   => 'https://developers.mercadolivre.com.br/pt_br/autenticacao-e-autorizacao',
        'rotulos' => ['id' => 'App ID (Client ID)', 'segredo' => 'Secret Key (Client Secret)'],
        'passos' => [
            'Entre em developers.mercadolivre.com.br com a MESMA conta que vende os produtos.',
            'Vá em "Suas integrações" → "Criar aplicação".',
            'Preencha nome e descrição. Em "URI de redirect", cole exatamente a URL de retorno mostrada abaixo.',
            'Em "Escopos", marque read, write e offline_access. Sem o offline_access não vem refresh token, e a conexão cai a cada 6 horas.',
            'Em "Tópicos", pode deixar tudo desmarcado: este sistema não recebe notificações.',
            'Salve. A tela mostra o App ID e a Secret Key — cole os dois aqui embaixo.',
        ],
    ],
    'shopee' => [
        'portal' => 'https://open.shopee.com',
        'docs'   => 'https://open.shopee.com/documents',
        'rotulos' => ['id' => 'Partner ID', 'segredo' => 'Partner Key'],
        'passos' => [
            'Entre em open.shopee.com e cadastre-se como parceiro. Esse cadastro passa por aprovação da Shopee e não é imediato.',
            'No console, vá em "App Management" → "Create App".',
            'Preencha os dados do app e, em "Redirect URL", cole exatamente a URL de retorno mostrada abaixo.',
            'Peça as permissões de produto (Product / Item) — são as que este sistema usa para publicar.',
            'Depois de aprovado, o app mostra Partner ID e Partner Key. Existem dois pares: um de teste (sandbox) e um de produção (live).',
            'Cole o par aqui embaixo e escolha o ambiente correspondente no campo abaixo.',
        ],
    ],
];
?>
<div class="topo">
    <div>
        <p class="eyebrow">Configuração</p>
        <h1>Canais</h1>
    </div>
</div>
<div class="camadas"></div>

<div class="cartao">
    <div class="corpo">
        <p class="discreto" style="margin-top:0;max-width:80ch">
            Credenciais salvas aqui ficam criptografadas no banco. Se preferir configurá-las pelo
            arquivo <code class="mono">.env</code>, também funciona — o que estiver preenchido nesta
            tela tem prioridade.
        </p>
        <?php if (!str_starts_with($appUrl, 'https://')): ?>
            <div class="aviso aviso-" style="margin:14px 0 0">
                <strong>A conexão não vai fechar enquanto o sistema estiver em
                <code class="mono"><?= View::e($appUrl ?: 'http://localhost') ?></code>.</strong>
                As duas plataformas exigem que a URL de retorno seja HTTPS, e nenhuma delas aceita
                <code class="mono">localhost</code>. Você pode preencher e salvar as credenciais
                agora, mas o botão de conectar só funciona depois que o sistema estiver no domínio
                com SSL ativo — daí é só ajustar <code class="mono">APP_URL</code> no
                <code class="mono">.env</code>.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php foreach ($canais as $item): ?>
    <?php
    /** @var Canal $canal */
    $canal = $item['canal'];
    $conta = $item['conta'];
    $guia = $tutoriais[$canal->value];
    ?>
    <div class="cartao">
        <h2><?= View::e($canal->rotulo()) ?></h2>
        <div class="corpo">

            <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:18px">
                <span class="selo <?= $item['conectado'] ? 'publicado' : 'nao_publicado' ?>">
                    <?= $item['conectado'] ? 'conta conectada' : 'conta não conectada' ?>
                </span>
                <span class="selo <?= $item['completo'] ? 'ok' : 'erro' ?>">
                    <?= $item['completo'] ? 'credenciais preenchidas' : 'faltam credenciais' ?>
                </span>

                <span style="flex:1"></span>

                <?php if ($item['conectado']): ?>
                    <form method="post" action="/canais/<?= $canal->value ?>/desconectar"
                          onsubmit="return confirm('Desconectar a conta? Os anúncios já publicados continuam no ar, mas o sistema deixa de publicar e atualizar.')">
                        <?= Csrf::campo() ?>
                        <button class="botao perigo" type="submit">Desconectar</button>
                    </form>
                <?php elseif ($item['completo']): ?>
                    <a class="botao publicar" href="/canais/<?= $canal->value ?>/conectar">
                        Conectar <?= View::e($canal->rotulo()) ?>
                    </a>
                <?php else: ?>
                    <button class="botao" type="button" disabled aria-disabled="true"
                            title="Preencha as credenciais abaixo primeiro">
                        Conectar <?= View::e($canal->rotulo()) ?>
                    </button>
                <?php endif; ?>
            </div>

            <?php if ($item['conectado']): ?>
                <table style="font-size:13px;margin-bottom:18px">
                    <tbody>
                    <tr>
                        <th style="width:170px;border:0;padding-left:0">Loja</th>
                        <td class="mono" style="border:0"><?= View::e($conta['identificador_loja'] ?? '—') ?></td>
                    </tr>
                    <tr>
                        <th style="border:0;padding-left:0">Token válido até</th>
                        <td class="mono" style="border:0"><?= View::dataHora($conta['expira_em'] ?? null) ?></td>
                    </tr>
                    </tbody>
                </table>
            <?php endif; ?>

            <!-- Tutorial ------------------------------------------------ -->
            <details <?= $item['completo'] ? '' : 'open' ?>
                     style="border:1px solid var(--linha);border-radius:var(--raio);margin-bottom:18px">
                <summary style="padding:11px 14px;cursor:pointer;font-weight:500;font-size:14px">
                    Como obter as credenciais <?= View::e($canal->com('de')) ?>
                </summary>
                <div style="padding:0 14px 14px">
                    <p style="margin-top:0">
                        <a href="<?= View::e($guia['portal']) ?>" target="_blank" rel="noopener">
                            Abrir o portal de desenvolvedor
                        </a>
                        ·
                        <a href="<?= View::e($guia['docs']) ?>" target="_blank" rel="noopener">
                            Documentação de autenticação
                        </a>
                    </p>
                    <ol style="margin:0;padding-left:20px;font-size:13.5px;line-height:1.65;color:var(--tinta-2)">
                        <?php foreach ($guia['passos'] as $passo): ?>
                            <li style="margin-bottom:5px"><?= View::e($passo) ?></li>
                        <?php endforeach; ?>
                    </ol>
                </div>
            </details>

            <!-- URL de retorno ------------------------------------------ -->
            <label class="campo">
                <span>URL de retorno — cole esta no portal</span>
                <input type="text" class="mono" readonly value="<?= View::e($item['redirect']) ?>"
                       onclick="this.select()" style="background:var(--plate)">
                <span class="dica">
                    Precisa ser idêntica à cadastrada no portal, caractere por caractere. O sistema
                    monta a partir da <code class="mono">APP_URL</code> do <code class="mono">.env</code>.
                </span>
            </label>

            <!-- Credenciais --------------------------------------------- -->
            <form method="post" action="/canais/<?= $canal->value ?>/credenciais">
                <?= Csrf::campo() ?>

                <div class="linha">
                    <label class="campo">
                        <span><?= View::e($guia['rotulos']['id']) ?></span>
                        <input type="text" class="mono" name="client_id"
                               value="<?= View::e($item['clientId'] ?? '') ?>"
                               placeholder="<?= $canal === Canal::Shopee ? '1000123' : '1234567890123456' ?>">
                    </label>

                    <label class="campo">
                        <span><?= View::e($guia['rotulos']['segredo']) ?></span>
                        <input type="password" class="mono" name="client_secret" autocomplete="new-password"
                               placeholder="<?= $item['temSegredo'] ? '•••••••• (já salvo)' : 'cole aqui' ?>">
                        <span class="dica">
                            <?= $item['temSegredo']
                                ? 'Já existe um segredo salvo. Deixe em branco para mantê-lo.'
                                : 'Fica criptografado no banco e nunca é exibido de volta.' ?>
                        </span>
                    </label>
                </div>

                <div class="linha">
                    <?php if ($canal === Canal::Shopee): ?>
                        <label class="campo">
                            <span>Ambiente</span>
                            <select name="host">
                                <option value="https://partner.shopeemobile.com"
                                    <?= $item['host'] === 'https://partner.shopeemobile.com' ? 'selected' : '' ?>>
                                    Produção
                                </option>
                                <option value="https://partner.test-stable.shopeemobile.com"
                                    <?= $item['host'] === 'https://partner.test-stable.shopeemobile.com' ? 'selected' : '' ?>>
                                    Sandbox (teste)
                                </option>
                            </select>
                            <span class="dica">O par de credenciais de teste só funciona no sandbox, e vice-versa.</span>
                        </label>
                    <?php endif; ?>

                    <label class="campo">
                        <span>Markup do canal (%)</span>
                        <input type="text" class="num" name="markup"
                               value="<?= number_format((float) ($conta['markup_percentual'] ?? 0), 2, ',', '') ?>">
                        <span class="dica">
                            Aplicado sobre o preço base quando a variação não tem preço próprio neste canal.
                        </span>
                    </label>
                </div>

                <?php if (($item['noEnv']['id'] ?? null) !== null): ?>
                    <p class="discreto" style="font-size:12px">
                        Há credenciais no <code class="mono">.env</code> para este canal. Elas são usadas
                        somente quando os campos acima estão vazios.
                    </p>
                <?php endif; ?>

                <button class="botao publicar" type="submit">Salvar credenciais</button>
            </form>

            <?php if ($canal === Canal::Shopee): ?>
                <hr style="border:0;border-top:1px solid var(--linha);margin:18px 0">

                <form method="post" action="/canais/<?= $canal->value ?>/logistica">
                    <?= Csrf::campo() ?>
                    <label class="campo" style="max-width:520px">
                        <span>Canais de logística</span>
                        <input type="text" class="mono" name="logisticas"
                               value="<?= View::e(implode(', ', array_map('strval', $conta['extra']['logisticas'] ?? []))) ?>"
                               placeholder="90003, 90005">
                        <span class="dica">
                            Ids separados por vírgula, obtidos em Logística no painel de vendedor da Shopee.
                            Sem pelo menos um, a Shopee recusa a criação do anúncio — é a causa mais comum
                            de falha em integração nova, e a mensagem de erro dela não deixa isso claro.
                        </span>
                    </label>
                    <button class="botao" type="submit">Salvar logística</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
