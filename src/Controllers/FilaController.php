<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Db;
use App\Core\Flash;
use App\Models\Fila;
use App\Services\Fila\Worker;
use Throwable;

final class FilaController extends Controller
{
    public function index(): string
    {
        return $this->view('fila/index', [
            'jobs'   => Fila::listar(50),
            'resumo' => Fila::resumo(),
        ], 'Fila de publicação');
    }

    /**
     * Roda o worker pelo navegador.
     *
     * Existe para desenvolvimento e para o caso de a hospedagem não ter
     * cron. Em produção com cron configurado, este botão é só um atalho
     * para não esperar os 5 minutos.
     */
    public function processar(): string
    {
        try {
            $resumo = (new Worker())->processarLote();

            if ($resumo['processados'] === 0) {
                Flash::aviso('Não havia nada pendente na fila.');
            } else {
                Flash::sucesso(sprintf(
                    '%d job(s) processado(s): %d com sucesso, %d com erro.',
                    $resumo['processados'],
                    $resumo['ok'],
                    $resumo['erro']
                ));
            }
        } catch (Throwable $e) {
            Flash::erro('O worker falhou: ' . $e->getMessage());
        }

        return $this->redirecionar('/fila');
    }

    public function reenfileirar(string $id): string
    {
        $job = Db::um('SELECT * FROM fila_publicacao WHERE id = ?', [(int) $id]);

        if ($job === null) {
            $this->naoEncontrado('Job não encontrado.');
        }

        // Zera as tentativas: o usuário está reenviando de propósito,
        // presumivelmente depois de corrigir o que causou a falha.
        Db::executar(
            "UPDATE fila_publicacao
                SET status = 'pendente', tentativas = 0, erro = NULL, proxima_tentativa_em = NOW()
              WHERE id = ?",
            [(int) $id]
        );

        Flash::sucesso('Job devolvido à fila.');

        return $this->redirecionar('/fila');
    }
}
