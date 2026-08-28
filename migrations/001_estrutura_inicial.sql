-- Estrutura inicial do integrador de marketplaces.
-- Princípio: produto simples é um produto com uma variação só.

CREATE TABLE usuarios (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome       VARCHAR(120) NOT NULL,
    email      VARCHAR(190) NOT NULL,
    senha_hash VARCHAR(255) NOT NULL,
    ativo      TINYINT(1) NOT NULL DEFAULT 1,
    criado_em  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_usuarios_email (email)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Modelos de produto: fixam categoria e atributos padrão por canal,
-- para que produtos do mesmo tipo não sejam reconfigurados um a um.
CREATE TABLE modelos_produto (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome      VARCHAR(120) NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_modelos_nome (nome)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE modelo_canal (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    modelo_id           INT UNSIGNED NOT NULL,
    canal               ENUM('mercadolivre', 'shopee') NOT NULL,
    categoria_id_remota VARCHAR(60) NOT NULL,
    categoria_nome      VARCHAR(255) NOT NULL DEFAULT '',
    atributos_json      JSON NULL,
    UNIQUE KEY uk_modelo_canal (modelo_id, canal),
    CONSTRAINT fk_modelo_canal_modelo FOREIGN KEY (modelo_id)
        REFERENCES modelos_produto (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE produtos (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sku_base      VARCHAR(60) NOT NULL,
    titulo        VARCHAR(200) NOT NULL,
    descricao     TEXT NULL,
    marca         VARCHAR(120) NULL,
    modelo_id     INT UNSIGNED NULL,
    status        ENUM('rascunho', 'pronto') NOT NULL DEFAULT 'rascunho',
    criado_em     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_produtos_sku (sku_base),
    KEY idx_produtos_modelo (modelo_id),
    CONSTRAINT fk_produtos_modelo FOREIGN KEY (modelo_id)
        REFERENCES modelos_produto (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Máximo de 2 eixos por produto: limite das tier variations da Shopee.
-- Imposto aqui para que nenhum produto fique publicável num canal e travado no outro.
CREATE TABLE eixos_variacao (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    produto_id INT UNSIGNED NOT NULL,
    nome       VARCHAR(60) NOT NULL,
    ordem      TINYINT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY uk_eixo_ordem (produto_id, ordem),
    CONSTRAINT ck_eixo_ordem CHECK (ordem BETWEEN 1 AND 2),
    CONSTRAINT fk_eixos_produto FOREIGN KEY (produto_id)
        REFERENCES produtos (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Peso e dimensões ficam aqui, não no produto: tamanhos diferentes da
-- mesma peça têm frete diferente, e as duas plataformas cobram por isso.
CREATE TABLE variacoes (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    produto_id     INT UNSIGNED NOT NULL,
    sku            VARCHAR(60) NOT NULL,
    preco          DECIMAL(10, 2) NOT NULL DEFAULT 0,
    estoque        INT NOT NULL DEFAULT 0,
    peso_g         INT UNSIGNED NOT NULL DEFAULT 0,
    comprimento_cm DECIMAL(6, 2) NOT NULL DEFAULT 0,
    largura_cm     DECIMAL(6, 2) NOT NULL DEFAULT 0,
    altura_cm      DECIMAL(6, 2) NOT NULL DEFAULT 0,
    gtin           VARCHAR(20) NULL,
    ativo          TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uk_variacoes_sku (sku),
    KEY idx_variacoes_produto (produto_id),
    CONSTRAINT fk_variacoes_produto FOREIGN KEY (produto_id)
        REFERENCES produtos (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE variacao_valores (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    variacao_id INT UNSIGNED NOT NULL,
    eixo_id     INT UNSIGNED NOT NULL,
    valor       VARCHAR(80) NOT NULL,
    UNIQUE KEY uk_variacao_eixo (variacao_id, eixo_id),
    KEY idx_valores_eixo (eixo_id),
    CONSTRAINT fk_valores_variacao FOREIGN KEY (variacao_id)
        REFERENCES variacoes (id) ON DELETE CASCADE,
    CONSTRAINT fk_valores_eixo FOREIGN KEY (eixo_id)
        REFERENCES eixos_variacao (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE imagens (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    produto_id  INT UNSIGNED NOT NULL,
    variacao_id INT UNSIGNED NULL,
    arquivo     VARCHAR(255) NOT NULL,
    ordem       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    criado_em   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_imagens_produto (produto_id, ordem),
    KEY idx_imagens_variacao (variacao_id),
    CONSTRAINT fk_imagens_produto FOREIGN KEY (produto_id)
        REFERENCES produtos (id) ON DELETE CASCADE,
    CONSTRAINT fk_imagens_variacao FOREIGN KEY (variacao_id)
        REFERENCES variacoes (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Cacheia o image_id devolvido pela media space da Shopee, para não
-- reenviar a mesma foto a cada republicação.
CREATE TABLE imagens_canal (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    imagem_id  INT UNSIGNED NOT NULL,
    canal      ENUM('mercadolivre', 'shopee') NOT NULL,
    id_remoto  VARCHAR(190) NOT NULL,
    enviado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_imagem_canal (imagem_id, canal),
    CONSTRAINT fk_imagens_canal_imagem FOREIGN KEY (imagem_id)
        REFERENCES imagens (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Título do Mercado Livre tem limite de 60 caracteres; o da Shopee, 120.
-- Na prática sempre existirão dois títulos.
CREATE TABLE produto_canal_conteudo (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    produto_id INT UNSIGNED NOT NULL,
    canal      ENUM('mercadolivre', 'shopee') NOT NULL,
    titulo     VARCHAR(200) NULL,
    descricao  TEXT NULL,
    UNIQUE KEY uk_conteudo_produto_canal (produto_id, canal),
    CONSTRAINT fk_conteudo_produto FOREIGN KEY (produto_id)
        REFERENCES produtos (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE precos_canal (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    variacao_id INT UNSIGNED NOT NULL,
    canal       ENUM('mercadolivre', 'shopee') NOT NULL,
    preco       DECIMAL(10, 2) NOT NULL,
    UNIQUE KEY uk_preco_variacao_canal (variacao_id, canal),
    CONSTRAINT fk_precos_variacao FOREIGN KEY (variacao_id)
        REFERENCES variacoes (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE anuncios (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    produto_id    INT UNSIGNED NOT NULL,
    canal         ENUM('mercadolivre', 'shopee') NOT NULL,
    id_remoto     VARCHAR(60) NULL,
    status        ENUM('nao_publicado', 'na_fila', 'publicado', 'erro') NOT NULL DEFAULT 'nao_publicado',
    url           VARCHAR(400) NULL,
    ultimo_erro   TEXT NULL,
    publicado_em  DATETIME NULL,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_anuncio_produto_canal (produto_id, canal),
    KEY idx_anuncios_status (status),
    CONSTRAINT fk_anuncios_produto FOREIGN KEY (produto_id)
        REFERENCES produtos (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Guarda o model_id da Shopee e o id de variação do Mercado Livre,
-- necessários para atualizar o anúncio depois de criado.
CREATE TABLE anuncio_variacoes (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    anuncio_id  INT UNSIGNED NOT NULL,
    variacao_id INT UNSIGNED NOT NULL,
    id_remoto   VARCHAR(60) NULL,
    UNIQUE KEY uk_anuncio_variacao (anuncio_id, variacao_id),
    CONSTRAINT fk_av_anuncio FOREIGN KEY (anuncio_id)
        REFERENCES anuncios (id) ON DELETE CASCADE,
    CONSTRAINT fk_av_variacao FOREIGN KEY (variacao_id)
        REFERENCES variacoes (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE fila_publicacao (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    anuncio_id           INT UNSIGNED NOT NULL,
    acao                 ENUM('criar', 'atualizar') NOT NULL,
    status               ENUM('pendente', 'processando', 'ok', 'erro') NOT NULL DEFAULT 'pendente',
    tentativas           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    proxima_tentativa_em DATETIME NOT NULL,
    payload_json         JSON NULL,
    resposta_json        JSON NULL,
    erro                 TEXT NULL,
    criado_em            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_fila_busca (status, proxima_tentativa_em),
    KEY idx_fila_anuncio (anuncio_id),
    CONSTRAINT fk_fila_anuncio FOREIGN KEY (anuncio_id)
        REFERENCES anuncios (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Segredos e tokens ficam criptografados (AES-256-GCM, chave no .env).
CREATE TABLE contas_canal (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    canal              ENUM('mercadolivre', 'shopee') NOT NULL,
    client_id          VARCHAR(190) NULL,
    client_secret      TEXT NULL,
    access_token       TEXT NULL,
    refresh_token      TEXT NULL,
    expira_em          DATETIME NULL,
    identificador_loja VARCHAR(60) NULL,
    markup_percentual  DECIMAL(5, 2) NOT NULL DEFAULT 0,
    extra_json         JSON NULL,
    conectado_em       DATETIME NULL,
    atualizado_em      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_contas_canal (canal)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE cache_categorias (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    canal          ENUM('mercadolivre', 'shopee') NOT NULL,
    categoria_id   VARCHAR(60) NOT NULL,
    nome           VARCHAR(255) NOT NULL,
    caminho        VARCHAR(600) NOT NULL DEFAULT '',
    atributos_json JSON NULL,
    atualizado_em  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cache_canal_categoria (canal, categoria_id),
    KEY idx_cache_nome (canal, nome)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Quando um marketplace recusar um anúncio com erro críptico,
-- é este log que resolve o problema.
CREATE TABLE log_api (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    canal       ENUM('mercadolivre', 'shopee') NOT NULL,
    metodo      VARCHAR(10) NOT NULL,
    endpoint    VARCHAR(500) NOT NULL,
    http_status SMALLINT UNSIGNED NULL,
    requisicao  MEDIUMTEXT NULL,
    resposta    MEDIUMTEXT NULL,
    duracao_ms  INT UNSIGNED NULL,
    erro        VARCHAR(500) NULL,
    criado_em   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_log_canal_data (canal, criado_em)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
