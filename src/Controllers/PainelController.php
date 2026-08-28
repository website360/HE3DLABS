<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Db;
use App\Dominio\Canal;
use App\Models\Anuncios;
use App\Models\Contas;
use App\Models\Fila;

final class PainelController extends Controller
{
    public function index(): string
    {
        $conexoes = [];

        foreach (Canal::todos() as $canal) {
            $conta = Contas::buscar($canal);

            $conexoes[$canal->value] = [
                'canal'     => $canal,
                'conectado' => Contas::conectada($canal),
                'expira_em' => $conta['expira_em'] ?? null,
                'loja'      => $conta['identificador_loja'] ?? null,
            ];
        }

        return $this->view('painel', [
            'totalProdutos'  => (int) Db::valor('SELECT COUNT(*) FROM produtos'),
            'totalVariacoes' => (int) Db::valor('SELECT COUNT(*) FROM variacoes'),
            'anuncios'       => Anuncios::resumo(),
            'fila'           => Fila::resumo(),
            'conexoes'       => $conexoes,
            'comProblema'    => array_slice(Anuncios::comProblema(), 0, 5),
        ], 'Painel');
    }
}
