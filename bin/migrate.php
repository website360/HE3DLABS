<?php

declare(strict_types=1);

/**
 * Aplica as migrations pendentes.
 *
 *   php bin/migrate.php
 *
 * Cria o banco caso ainda não exista, registra cada arquivo aplicado em
 * `migracoes` e nunca reaplica o que já rodou.
 */

require __DIR__ . '/../bootstrap.php';

use App\Core\Config;
use App\Core\Db;

$banco = Config::obrigatorio('DB_NAME');

// 1. Garante o banco.
$semBanco = Db::connSemBanco();
$semBanco->exec(
    "CREATE DATABASE IF NOT EXISTS `{$banco}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
);
echo "Banco `{$banco}` disponível.\n";

// 2. Garante a tabela de controle.
Db::conn()->exec(
    'CREATE TABLE IF NOT EXISTS migracoes (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        arquivo     VARCHAR(190) NOT NULL,
        aplicada_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_migracoes_arquivo (arquivo)
    ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci'
);

$aplicadas = array_column(Db::todos('SELECT arquivo FROM migracoes'), 'arquivo');

$arquivos = glob(BASE_PATH . '/migrations/*.sql') ?: [];
sort($arquivos);

$total = 0;

foreach ($arquivos as $caminho) {
    $nome = basename($caminho);

    if (in_array($nome, $aplicadas, true)) {
        continue;
    }

    $sql = file_get_contents($caminho);
    if ($sql === false) {
        throw new RuntimeException("Não foi possível ler {$nome}.");
    }

    // Remove as linhas de comentário ANTES de dividir: um statement
    // precedido de comentário começaria com "--" e seria descartado inteiro.
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';

    // O schema não usa procedures nem triggers, então quebrar no ponto
    // e vírgula ao fim da linha é seguro.
    $comandos = array_values(array_filter(
        array_map('trim', preg_split('/;\s*\R/', $sql) ?: []),
        static fn (string $c): bool => $c !== ''
    ));

    // Sem transação: DDL no MySQL provoca commit implícito, de modo que
    // envolver em BEGIN/COMMIT daria uma garantia que não existe. Se um
    // comando falhar, o arquivo não é marcado como aplicado e o erro
    // aponta qual comando quebrou.
    foreach ($comandos as $indice => $comando) {
        try {
            Db::conn()->exec($comando);
        } catch (PDOException $e) {
            $numero = $indice + 1;
            $trecho = substr(preg_replace('/\s+/', ' ', $comando) ?? '', 0, 120);

            throw new RuntimeException(
                "Falha em {$nome}, comando #{$numero} ({$trecho}...): " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    Db::executar('INSERT INTO migracoes (arquivo) VALUES (?)', [$nome]);

    echo "Aplicada: {$nome}\n";
    $total++;
}

echo $total === 0
    ? "Nenhuma migration pendente.\n"
    : "{$total} migration(s) aplicada(s).\n";
