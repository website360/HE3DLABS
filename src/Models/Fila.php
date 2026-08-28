<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Db;

final class Fila
{
    /**
     * Enfileira a publicação de um anúncio.
     *
     * Se já houver job pendente para o mesmo anúncio, apenas antecipa a
     * execução em vez de criar um segundo: clicar "Publicar" três vezes
     * não pode gerar três anúncios.
     */
    public static function enfileirar(int $anuncioId, string $acao = 'criar'): int
    {
        $pendente = Db::um(
            "SELECT id FROM fila_publicacao
              WHERE anuncio_id = ? AND status IN ('pendente', 'processando')
              ORDER BY id DESC LIMIT 1",
            [$anuncioId]
        );

        if ($pendente !== null) {
            Db::executar(
                'UPDATE fila_publicacao SET proxima_tentativa_em = NOW() WHERE id = ? AND status = ?',
                [$pendente['id'], 'pendente']
            );

            return (int) $pendente['id'];
        }

        return Db::inserir(
            'INSERT INTO fila_publicacao (anuncio_id, acao, status, proxima_tentativa_em)
             VALUES (?, ?, ?, NOW())',
            [$anuncioId, $acao, 'pendente']
        );
    }

    /**
     * Reserva o próximo job disponível.
     *
     * SKIP LOCKED garante que duas execuções sobrepostas do cron nunca
     * peguem o mesmo job — cenário real quando uma publicação demora mais
     * que o intervalo de 5 minutos.
     *
     * @return array<string,mixed>|null
     */
    public static function reservar(): ?array
    {
        return Db::transacao(static function (): ?array {
            $job = Db::um(
                "SELECT * FROM fila_publicacao
                  WHERE status = 'pendente' AND proxima_tentativa_em <= NOW()
                  ORDER BY proxima_tentativa_em, id
                  LIMIT 1
                  FOR UPDATE SKIP LOCKED"
            );

            if ($job === null) {
                return null;
            }

            Db::executar(
                "UPDATE fila_publicacao
                    SET status = 'processando', tentativas = tentativas + 1
                  WHERE id = ?",
                [$job['id']]
            );

            $job['tentativas'] = (int) $job['tentativas'] + 1;

            return $job;
        });
    }

    public static function concluir(int $id, array $resposta = []): void
    {
        Db::executar(
            "UPDATE fila_publicacao
                SET status = 'ok', erro = NULL, resposta_json = ?
              WHERE id = ?",
            [json_encode($resposta, JSON_UNESCAPED_UNICODE), $id]
        );
    }

    /**
     * Registra a falha. Erros transitórios voltam para a fila com espera
     * crescente; erros de validação falham de uma vez, porque retentar
     * não muda a resposta da plataforma.
     */
    public static function falhar(int $id, string $erro, bool $transitorio, int $tentativas): void
    {
        $maximo = (int) Config::get('FILA_MAX_TENTATIVAS', '5');
        $podeRetentar = $transitorio && $tentativas < $maximo;

        if (!$podeRetentar) {
            Db::executar(
                "UPDATE fila_publicacao SET status = 'erro', erro = ? WHERE id = ?",
                [mb_substr($erro, 0, 4000), $id]
            );

            return;
        }

        Db::executar(
            "UPDATE fila_publicacao
                SET status = 'pendente', erro = ?, proxima_tentativa_em = DATE_ADD(NOW(), INTERVAL ? SECOND)
              WHERE id = ?",
            [mb_substr($erro, 0, 4000), self::esperaSegundos($tentativas), $id]
        );
    }

    /** 1 min, 5 min, 25 min, 125 min... */
    public static function esperaSegundos(int $tentativas): int
    {
        return (int) (60 * (5 ** max(0, $tentativas - 1)));
    }

    public static function registrarPayload(int $id, array $payload): void
    {
        Db::executar(
            'UPDATE fila_publicacao SET payload_json = ? WHERE id = ?',
            [json_encode($payload, JSON_UNESCAPED_UNICODE), $id]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function listar(int $limite = 50): array
    {
        return Db::todos(
            'SELECT f.*, a.canal, a.produto_id, p.titulo, p.sku_base
               FROM fila_publicacao f
               JOIN anuncios a ON a.id = f.anuncio_id
               JOIN produtos p ON p.id = a.produto_id
              ORDER BY f.id DESC
              LIMIT ' . max(1, $limite)
        );
    }

    /** @return array<string,int> */
    public static function resumo(): array
    {
        $linhas = Db::todos('SELECT status, COUNT(*) AS total FROM fila_publicacao GROUP BY status');

        $resumo = ['pendente' => 0, 'processando' => 0, 'ok' => 0, 'erro' => 0];

        foreach ($linhas as $linha) {
            $resumo[(string) $linha['status']] = (int) $linha['total'];
        }

        return $resumo;
    }

    /** Devolve à fila jobs presos em "processando" (worker morto no meio). */
    public static function destravarOrfaos(int $minutos = 30): int
    {
        return Db::executar(
            "UPDATE fila_publicacao
                SET status = 'pendente', proxima_tentativa_em = NOW()
              WHERE status = 'processando'
                AND atualizado_em < DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$minutos]
        );
    }
}
