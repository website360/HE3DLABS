<?php

declare(strict_types=1);

namespace App\Services\Fila;

use App\Core\Config;
use App\Dominio\Canal;
use App\Models\Anuncios;
use App\Models\Fila;
use App\Models\Produtos;
use App\Services\Canal\ErroPublicacao;
use App\Services\Canal\Fabrica;
use App\Services\Canal\MontadorContexto;
use App\Services\Http\ClienteHttp;
use Throwable;

/**
 * Drena a fila de publicação.
 *
 * Cada job é reservado com SKIP LOCKED, de modo que duas execuções
 * sobrepostas do cron nunca peguem o mesmo — cenário real quando uma
 * publicação demora mais que o intervalo de 5 minutos.
 */
final class Worker
{
    public function __construct(
        private readonly ?ClienteHttp $http = null,
    ) {
    }

    /** @return array{processados:int,ok:int,erro:int,mensagens:array<int,string>} */
    public function processarLote(?int $maximo = null): array
    {
        $maximo ??= (int) Config::get('FILA_JOBS_POR_EXECUCAO', '10');

        Fila::destravarOrfaos();

        $resumo = ['processados' => 0, 'ok' => 0, 'erro' => 0, 'mensagens' => []];

        while ($resumo['processados'] < $maximo) {
            $job = Fila::reservar();

            if ($job === null) {
                break;
            }

            $resumo['processados']++;
            $mensagem = $this->processar($job);

            if (str_starts_with($mensagem, 'OK')) {
                $resumo['ok']++;
            } else {
                $resumo['erro']++;
            }

            $resumo['mensagens'][] = $mensagem;
        }

        return $resumo;
    }

    /** @param array<string,mixed> $job */
    public function processar(array $job): string
    {
        $jobId = (int) $job['id'];
        $anuncioId = (int) $job['anuncio_id'];
        $tentativas = (int) $job['tentativas'];

        try {
            $anuncio = Anuncios::porId($anuncioId);

            if ($anuncio === null) {
                Fila::falhar($jobId, 'Anúncio não existe mais.', false, $tentativas);

                return "ERRO job {$jobId}: anúncio removido";
            }

            $canal = Canal::from((string) $anuncio['canal']);
            $produto = Produtos::montar((int) $anuncio['produto_id']);

            if ($produto === null) {
                Fila::falhar($jobId, 'Produto não existe mais.', false, $tentativas);
                Anuncios::marcarErro($anuncioId, 'Produto removido.');

                return "ERRO job {$jobId}: produto removido";
            }

            $contexto = MontadorContexto::montar($produto, $canal);
            $publicador = Fabrica::para($canal, $this->http);

            $idRemoto = $anuncio['id_remoto'] ?? null;

            $resultado = (is_string($idRemoto) && $idRemoto !== '')
                ? $publicador->atualizar($produto, $contexto, $anuncioId, $idRemoto)
                : $publicador->publicar($produto, $contexto, $anuncioId);

            Anuncios::marcarPublicado($anuncioId, $resultado->idRemoto, $resultado->url);
            Fila::concluir($jobId, $resultado->resposta);

            return "OK job {$jobId}: {$canal->rotulo()} anúncio {$resultado->idRemoto}";
        } catch (ErroPublicacao $e) {
            $descricao = $e->descricaoCompleta();

            Fila::falhar($jobId, $descricao, $e->transitorio, $tentativas);

            // Enquanto ainda há retentativa pela frente, o anúncio segue
            // "na fila": marcar erro agora assustaria à toa.
            if (!$e->transitorio || $tentativas >= (int) Config::get('FILA_MAX_TENTATIVAS', '5')) {
                Anuncios::marcarErro($anuncioId, $descricao);
            }

            return "ERRO job {$jobId}: {$descricao}";
        } catch (Throwable $e) {
            // Falha inesperada: tratada como transitória, porque pode ser
            // um problema de ambiente e não do payload.
            $mensagem = get_class($e) . ': ' . $e->getMessage();

            Fila::falhar($jobId, $mensagem, true, $tentativas);

            if ($tentativas >= (int) Config::get('FILA_MAX_TENTATIVAS', '5')) {
                Anuncios::marcarErro($anuncioId, $mensagem);
            }

            return "ERRO job {$jobId}: {$mensagem}";
        }
    }
}
