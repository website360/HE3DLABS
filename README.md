# HE 3D Labs — Integrador de Marketplaces

Painel interno para cadastrar produtos uma vez e publicá-los como anúncios
na Shopee e no Mercado Livre.

O fluxo é de mão única: o sistema empurra anúncios. Pedidos e baixa de
estoque continuam sendo acompanhados no painel de cada marketplace.

## O que precisa para rodar

- PHP 8.1 ou superior, com as extensões `pdo_mysql`, `curl`, `gd`, `mbstring` e `openssl`
- MySQL 8 ou MariaDB 10.6
- Acesso a cron jobs
- HTTPS no domínio (as duas plataformas exigem callback de OAuth seguro)

Não há dependências de terceiros. Não existe `composer install`, e o deploy
é cópia de arquivos.

## Instalação

```bash
cp .env.example .env
php bin/chave.php          # gere a APP_KEY e cole no .env
php bin/migrate.php        # cria o banco e as tabelas
php bin/semear.php --email=voce@dominio.com.br --senha='uma senha forte'
```

O `semear` não tem credencial padrão: e-mail e senha são obrigatórios, para
que nenhuma senha de administrador exista no código-fonte.

Acrescente `--demo` para incluir também um catálogo de exemplo.

Ambiente local:

```bash
php -S 127.0.0.1:8090 -t public bin/servidor.php
```

## Deploy no cPanel

1. Envie a pasta do projeto para fora de `public_html` — por exemplo `~/app`.
2. Aponte o **document root** do domínio para `~/app/public`.
   Assim `.env`, `src/` e `migrations/` nem chegam a ficar acessíveis pela web.
   Se o plano não permitir mudar o document root, use o `.htaccess` da raiz,
   que redireciona tudo para `public/` e bloqueia o resto.
3. Crie o banco e o usuário MySQL no cPanel, e preencha o `.env`.
4. Rode `php bin/migrate.php` e `php bin/semear.php` pelo terminal do cPanel.
5. Ative o AutoSSL no domínio.
6. Cadastre os dois cron jobs:

```
*/5 * * * * /usr/local/bin/php /home/USUARIO/app/bin/worker.php >> /home/USUARIO/logs/worker.log 2>&1
0   * * * * /usr/local/bin/php /home/USUARIO/app/bin/tokens.php >> /home/USUARIO/logs/tokens.log 2>&1
```

O primeiro drena a fila de publicação. O segundo renova os tokens antes de
vencerem e apaga registros de log com mais de 30 dias.

## Conectando as contas

As credenciais de aplicação ficam no `.env`, nunca no banco.

**Mercado Livre** — crie o app em
[developers.mercadolivre.com.br](https://developers.mercadolivre.com.br/devcenter).
Preencha `ML_CLIENT_ID`, `ML_CLIENT_SECRET` e `ML_REDIRECT_URI`. A URL de
retorno precisa ser idêntica à cadastrada lá e usar HTTPS. O app precisa do
escopo `offline_access`, senão não vem refresh token.

**Shopee** — crie o app em [open.shopee.com](https://open.shopee.com).
Preencha `SHOPEE_PARTNER_ID`, `SHOPEE_PARTNER_KEY` e `SHOPEE_REDIRECT_URI`.

Com isso pronto, conecte as duas contas na tela **Canais**.

Depois de conectar a Shopee, informe os **ids dos canais de logística** na
mesma tela. Sem pelo menos um, a Shopee recusa a criação de anúncios — é a
causa mais comum de falha em integração nova, e a mensagem de erro dela não
deixa isso claro.

## Como usar

1. **Modelos de produto** — crie um modelo por tipo de peça ("Suporte de mesa")
   e configure a categoria e os atributos obrigatórios nos dois canais. Isso é
   feito uma vez; todo produto do mesmo tipo herda a configuração.
2. **Produtos** — cadastre o produto, defina até dois eixos de variação
   (limite da Shopee), preencha a grade de variações com preço, estoque, peso e
   dimensões, e envie as imagens.
3. **Pré-voo** — a tela do produto mostra, por canal, tudo que ainda impede a
   publicação. O botão só destrava quando a lista fecha.
4. **Publicar** — o clique enfileira. O worker processa a cada 5 minutos, e a
   tela **Fila** permite rodar na hora.

## Estrutura

```
public/       document root: front controller, CSS e uploads
src/
  Core/       roteador, PDO, sessão, autenticação, CSRF
  Dominio/    Produto, Variacao, Eixo, Imagem, Canal, ContextoPublicacao
  Models/     acesso a dados, sem ORM
  Controllers/
  Services/
    Canal/    CanalInterface + implementações Mercado Livre e Shopee
    Fila/     worker
    Http/     cliente cURL, decorator de log, cliente falso para testes
    Imagem/   validação e normalização de upload
views/
bin/          migrate, semear, worker, tokens, chave, servidor
migrations/
tests/
```

A peça que sustenta o desenho é a `CanalInterface`: controllers, fila e telas
só conhecem ela, então acrescentar um terceiro marketplace não toca no núcleo.

Os mapeadores (`Produto` → payload da plataforma) são funções puras, sem
banco nem rede. É onde mora a maior parte dos defeitos de integração, e é o
que fica coberto de testes.

## Testes

```bash
php tests/executar.php
```

Roda contra um banco separado (`DB_NAME` + `_teste`), recriado a cada execução.
Nenhum teste toca a rede: o cliente HTTP fica atrás de uma interface e os
testes injetam respostas gravadas.

A cobertura mira o que quebra de verdade: os mapeadores de payload e a
idempotência do worker — um job interrompido depois de criar o item precisa
retomar sem gerar um segundo anúncio.

## Decisões que valem saber

**Produto simples é um produto com uma variação só.** Não há caminho especial
no código para produtos sem variação.

**Peso e dimensões ficam na variação, não no produto.** Tamanhos diferentes da
mesma peça têm frete diferente, e as duas plataformas cobram por isso.

**Título e preço podem ser próprios por canal.** O Mercado Livre corta em 60
caracteres e a Shopee em 120, e as comissões diferem o bastante para um preço
único não servir aos dois.

**Publicação é assíncrona.** Um produto com 6 variações e 8 fotos gera cerca de
20 chamadas HTTP, o que estoura o tempo de execução típico de cPanel.

**Cada etapa grava antes da próxima.** É o que permite retomar um job
interrompido sem duplicar anúncio.

**Tokens ficam criptografados no banco** com AES-256-GCM. Trocar a `APP_KEY`
torna ilegíveis os tokens já gravados, e as contas precisam ser reconectadas.

**A renovação de token roda dentro de uma trava do MySQL.** O refresh token do
Mercado Livre é de uso único: duas renovações simultâneas queimam um token e
desconectam a conta.
