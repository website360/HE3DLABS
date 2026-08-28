# Integrador de Marketplaces — HE 3D Labs

**Data:** 2026-08-28
**Status:** Design aprovado, aguardando plano de implementação

## Objetivo

Um painel interno onde a HE 3D Labs cadastra seus produtos uma única vez e os publica como anúncios na Shopee e no Mercado Livre.

## Escopo

**Dentro do escopo:**

- Cadastro de produtos com e sem variações (cor, tamanho, material)
- Conexão OAuth com uma conta Shopee e uma conta Mercado Livre
- Busca de categorias e atributos obrigatórios nas APIs das duas plataformas
- Modelos de produto reutilizáveis, que fixam categoria e atributos padrão por canal
- Publicação e atualização de anúncios, processadas em fila
- Acompanhamento de status e erro por produto e por canal

**Fora do escopo:**

- Importação ou vinculação de anúncios já existentes. A HE 3D Labs não vende hoje em nenhuma das duas plataformas; todo anúncio nasce pelo sistema.
- Recebimento de pedidos e sincronização automática de estoque. O fluxo é de mão única: o sistema empurra anúncios, e vendas continuam sendo acompanhadas nos painéis de cada marketplace. O estoque cadastrado é enviado no momento da publicação ou da atualização, mas nada retorna das plataformas — uma venda na Shopee não baixa o estoque no sistema.
- Multi-loja. O sistema atende uma empresa, com uma conta de vendedor por plataforma.
- Emissão fiscal, frete próprio, relatórios financeiros.

## Restrições

- Hospedagem cPanel compartilhada, PHP 8.x, MySQL 8 ou MariaDB 10.6, com acesso a cron jobs.
- Deploy precisa ser cópia de arquivos, sem passo de build no servidor.
- HTTPS obrigatório no domínio: as duas plataformas exigem redirect URI segura no OAuth.
- Poucos usuários simultâneos (a equipe interna).

## Critérios de sucesso

1. Cadastrar um produto com variações e publicá-lo nas duas plataformas sem sair do sistema.
2. Publicar um lote de produtos sem estourar timeout do PHP.
3. Quando uma publicação falha, o motivo fica visível na tela sem precisar abrir log de servidor.
4. Uma nova publicação de um mesmo produto nunca gera anúncio duplicado.

## Decisões de arquitetura

### Stack: PHP 8.2 puro estruturado, sem framework

O sistema é um CRUD com dois clientes REST e uma fila. Laravel resolveria migrations, filas e auth de graça, mas cobra o preço inteiro no deploy em cPanel compartilhado — document root em `public/`, `composer install` no servidor ou `vendor/` de dezenas de MB versionado, limites de memória, `queue:work` virando `schedule:run`. Como a escolha do cPanel foi motivada por simplicidade operacional, o framework anularia o próprio motivo da escolha.

O custo dessa decisão é escrever à mão o roteador, a autenticação, as migrations e a validação: aproximadamente 500 linhas de encanamento, escritas uma vez.

Composer entra apenas para o cliente HTTP e o carregamento de `.env`. `vendor/` vai versionado, para que o deploy continue sendo cópia de arquivos.

### Estrutura de diretórios

```
public/              document root do cPanel
  index.php          front controller, único ponto de entrada
  assets/
  uploads/           imagens dos produtos, com URL pública
src/
  Core/              Router, Request, View, Db (PDO), Auth, Csrf, Config
  Controllers/       Produtos, Variacoes, Imagens, Modelos, Canais, Publicacao, Auth
  Models/            acesso a dados via PDO, sem ORM
  Services/
    Canal/
      CanalInterface.php
      ResultadoPublicacao.php
      MercadoLivre/  Client, Oauth, Publicador, Categorias, Mapeador
      Shopee/        Client, Oauth, Publicador, Categorias, Mapeador
    Fila/            Enfileirador, Worker
    Imagem/          redimensionamento e validação
    Http/            HttpClientInterface + implementação real e falsa
  Support/           Crypto, Logger
views/               templates PHP puros
bin/
  worker.php         cron a cada 5 minutos
  tokens.php         cron a cada hora
  migrate.php
migrations/          arquivos .sql numerados
tests/
.env                 credenciais, fora do document root
```

### A abstração central

```php
interface CanalInterface {
    public function buscarCategorias(string $termo): array;
    public function atributosDaCategoria(string $categoriaId): array;
    public function publicar(Produto $p): ResultadoPublicacao;
    public function atualizar(Anuncio $a, Produto $p): ResultadoPublicacao;
}
```

Controllers, fila e telas conhecem apenas essa interface. Shopee e Mercado Livre são duas implementações, e um terceiro canal futuro não altera o núcleo.

### Publicação assíncrona, por fila

Um produto com 6 variações e 8 fotos gera cerca de 20 chamadas HTTP: a Shopee exige upload prévio de cada imagem na media space, e o Mercado Livre separa item, descrição e variações em requisições distintas. Isso estoura o `max_execution_time` típico de cPanel.

O clique em "Publicar" grava na fila e retorna imediatamente. Um cron a cada 5 minutos drena a fila e a tela reflete o progresso.

## Modelo de dados

Princípio unificador: **produto simples é um produto com uma variação só.** Não há caminho especial no código para produtos sem variação.

### Catálogo

| Tabela | Campos principais |
|---|---|
| `produtos` | sku_base, titulo, descricao, marca, modelo_id, status, timestamps |
| `eixos_variacao` | produto_id, nome, ordem — **máximo 2 por produto** |
| `variacoes` | produto_id, sku, preco, estoque, peso_g, comprimento_cm, largura_cm, altura_cm, gtin, ativo |
| `variacao_valores` | variacao_id, eixo_id, valor |
| `imagens` | produto_id, variacao_id (nulo = foto do produto), arquivo, ordem |
| `imagens_canal` | imagem_id, canal, id_remoto |

O limite de dois eixos de variação vem da Shopee, que aceita no máximo duas *tier variations*. O sistema impõe esse limite no cadastro para que o produto nunca seja publicável em um canal e não no outro.

Peso e dimensões ficam na variação, não no produto: tamanhos diferentes de uma mesma peça impressa têm frete diferente, e as duas plataformas calculam por isso.

`imagens_canal` existe para cachear o `image_id` devolvido pela media space da Shopee, evitando reenviar a mesma foto a cada republicação.

### Conteúdo e preço por canal

| Tabela | Campos principais |
|---|---|
| `produto_canal_conteudo` | produto_id, canal, titulo, descricao — nulos herdam do produto |
| `precos_canal` | variacao_id, canal, preco — nulo usa o preço base com o markup do canal |

O título do Mercado Livre é limitado a 60 caracteres e o da Shopee a 120, então na prática existirão dois títulos. As comissões também diferem o bastante (Mercado Livre por volta de 11–16%, Shopee de 14–20%) para que um preço único não sirva aos dois canais.

### Modelos de produto

| Tabela | Campos principais |
|---|---|
| `modelos_produto` | nome |
| `modelo_canal` | modelo_id, canal, categoria_id_remota, categoria_nome, atributos_json |

Configurado uma vez por tipo de peça, faz todo produto novo nascer com categoria e atributos preenchidos nos dois canais. É o que impede que cada publicação vire preenchimento manual de quinze campos.

### Publicação

| Tabela | Campos principais |
|---|---|
| `anuncios` | produto_id, canal, id_remoto, status, url, ultimo_erro, publicado_em — único por (produto, canal) |
| `anuncio_variacoes` | anuncio_id, variacao_id, id_remoto |
| `fila_publicacao` | anuncio_id, acao, status, tentativas, proxima_tentativa_em, payload_json, resposta_json, erro |

`status` de `anuncios`: `nao_publicado`, `na_fila`, `publicado`, `erro`.
`acao` de `fila_publicacao`: `criar`, `atualizar`.
`status` de `fila_publicacao`: `pendente`, `processando`, `ok`, `erro`.

`anuncio_variacoes` guarda o `model_id` da Shopee e o id de variação do Mercado Livre, necessários para atualizar o anúncio depois.

### Infraestrutura

| Tabela | Finalidade |
|---|---|
| `contas_canal` | credenciais e tokens, criptografados com AES-256-GCM e chave no `.env` |
| `cache_categorias` | árvore e atributos de cada plataforma, com validade, para não bater no rate limit |
| `log_api` | requisição e resposta de toda chamada, retenção de 30 dias |
| `usuarios` | login com `password_hash` |

## Fluxo de publicação

1. **Pré-voo, síncrono.** Antes de enfileirar, o sistema valida contra os atributos obrigatórios da categoria já em cache: título dentro do limite do canal, peso e dimensões preenchidos, GTIN quando a categoria exige, ao menos uma imagem. O erro aparece na tela sem gastar chamada de API.
2. **Um job por canal.** Publicar nos dois cria dois jobs independentes. A falha em um canal não impede o outro.
3. **Worker.** Pega um job por vez com `SELECT ... FOR UPDATE SKIP LOCKED`, de modo que execuções sobrepostas do cron nunca processem o mesmo job.
4. **Etapas idempotentes.** Cada passo grava seu resultado antes do seguinte: imagem enviada guarda o `image_id`, item criado guarda o `id_remoto`. Um job interrompido retoma de onde parou em vez de criar um segundo anúncio. Este é o requisito mais importante do worker.
5. **Retry exponencial.** 1 minuto, 5 minutos, 25 minutos, no máximo 5 tentativas, e apenas para erros transitórios (429, 5xx, timeout). Erros de validação 4xx falham definitivamente e aparecem na tela.

## Particularidades das APIs

### Mercado Livre

- `access_token` válido por 6 horas.
- O `refresh_token` é de uso único: cada renovação devolve um novo par e invalida o anterior. Duas renovações concorrentes desconectam a conta. A renovação roda dentro de um `GET_LOCK` do MySQL e grava o novo par na mesma transação.
- O scope precisa incluir `offline_access`, sem o qual não vem refresh token.
- Título limitado a 60 caracteres.
- A descrição é uma chamada separada, `POST /items/{id}/description`, após a criação do item.
- Categorias sugeridas por `/sites/MLB/domain_discovery/search`; atributos por `/categories/{id}/attributes`, com os obrigatórios marcados em `tags.required`.

### Shopee

- Cada requisição carrega assinatura HMAC-SHA256 sobre `partner_id + caminho + timestamp + access_token + shop_id`. Um relógio de servidor com mais de 5 minutos de desvio faz tudo falhar com erro de assinatura, sem mensagem que indique a causa.
- `access_token` válido por 4 horas; refresh token, por 30 dias.
- Imagens precisam ser enviadas para `/media_space/upload_image` antes do anúncio, que referencia `image_id` em vez de URL.
- `logistic_info` é obrigatório em `add_item`. Sem ao menos um canal de logística habilitado, a criação falha — é a causa mais comum de erro em integração nova.
- A marca precisa vir de `get_brand_list`; texto livre é recusado.
- No máximo 2 *tier variations* por item.

### Comum às duas

Redirect URI em HTTPS. O AutoSSL do cPanel precisa estar ativo no domínio antes de conectar as contas.

## Crons

```
*/5 * * * *  /usr/local/bin/php /home/USUARIO/app/bin/worker.php
0   * * * *  /usr/local/bin/php /home/USUARIO/app/bin/tokens.php
```

## Tratamento de erros

Toda requisição é registrada em `log_api` com corpo de ida e volta. Na tela do produto, cada canal exibe um badge — *Não publicado*, *Na fila*, *Publicado*, *Erro* — e o erro traz a mensagem traduzida com acesso à resposta crua. Falhas permanentes permanecem visíveis até correção e republicação.

## Estratégia de testes

O cliente HTTP fica atrás de `HttpClientInterface`, então nenhum teste automatizado toca a rede.

- **Mapeadores** (`Produto → payload Mercado Livre`, `Produto → payload Shopee`): funções puras cobertas por PHPUnit. Concentram a maior parte dos defeitos de integração e são as mais baratas de testar.
- **Clientes de API**: implementação falsa devolvendo JSON de respostas reais gravadas, incluindo erros, cobrindo 429, 400 e token expirado.
- **Worker**: teste explícito de que retomar um job interrompido não duplica anúncio.
- **Ponta a ponta**: usuários de teste do Mercado Livre (`/users/test_user`) e conta de teste da Shopee, executados manualmente antes de cada ida a produção.

Não haverá testes de interface. Para um painel interno de poucos usuários, o custo não se justifica.

## Ordem sugerida de implementação

1. Núcleo (roteador, PDO, migrations, autenticação) e CRUD de produtos com variações e imagens
2. Conexão OAuth das duas contas, com renovação de token por cron
3. Cache de categorias e atributos, e modelos de produto
4. Mapeadores e publicação em fila para o Mercado Livre
5. Mapeadores e publicação em fila para a Shopee
6. Telas de status, erro e republicação

Cada bloco é entregável e testável isoladamente.
