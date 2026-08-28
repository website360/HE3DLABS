<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use Throwable;

/**
 * Acesso ao MySQL via PDO. Sem ORM: consultas explícitas, sempre
 * preparadas. Uma única conexão por processo.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo === null) {
            $host  = Config::get('DB_HOST', '127.0.0.1');
            $porta = Config::get('DB_PORT', '3306');
            $banco = Config::obrigatorio('DB_NAME');

            self::$pdo = self::criar("mysql:host={$host};port={$porta};dbname={$banco};charset=utf8mb4");
        }

        return self::$pdo;
    }

    /** Conexão sem banco selecionado, usada apenas pelo migrate para criar o schema. */
    public static function connSemBanco(): PDO
    {
        $host  = Config::get('DB_HOST', '127.0.0.1');
        $porta = Config::get('DB_PORT', '3306');

        return self::criar("mysql:host={$host};port={$porta};charset=utf8mb4");
    }

    private static function criar(string $dsn): PDO
    {
        return new PDO(
            $dsn,
            Config::get('DB_USER', 'root'),
            Config::get('DB_PASS', ''),
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function todos(string $sql, array $parametros = []): array
    {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($parametros);

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function um(string $sql, array $parametros = []): ?array
    {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($parametros);
        $linha = $stmt->fetch();

        return $linha === false ? null : $linha;
    }

    public static function valor(string $sql, array $parametros = []): mixed
    {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($parametros);
        $valor = $stmt->fetchColumn();

        return $valor === false ? null : $valor;
    }

    /** @return int linhas afetadas */
    public static function executar(string $sql, array $parametros = []): int
    {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($parametros);

        return $stmt->rowCount();
    }

    public static function inserir(string $sql, array $parametros = []): int
    {
        self::executar($sql, $parametros);

        return (int) self::conn()->lastInsertId();
    }

    /**
     * Executa o callback dentro de uma transação, revertendo em caso de
     * exceção. Suporta aninhamento raso: se já houver transação aberta,
     * apenas executa.
     */
    public static function transacao(callable $callback): mixed
    {
        $pdo = self::conn();

        if ($pdo->inTransaction()) {
            return $callback();
        }

        $pdo->beginTransaction();

        try {
            $resultado = $callback();
            $pdo->commit();

            return $resultado;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Trava nomeada do MySQL. Usada para serializar a renovação de token
     * do Mercado Livre, cujo refresh_token é de uso único: duas
     * renovações simultâneas desconectariam a conta.
     */
    public static function comTrava(string $nome, int $segundos, callable $callback): mixed
    {
        $obtida = (int) self::valor('SELECT GET_LOCK(?, ?)', [$nome, $segundos]);

        if ($obtida !== 1) {
            throw new \RuntimeException("Não foi possível obter a trava '{$nome}' em {$segundos}s.");
        }

        try {
            return $callback();
        } finally {
            self::executar('DO RELEASE_LOCK(?)', [$nome]);
        }
    }
}
