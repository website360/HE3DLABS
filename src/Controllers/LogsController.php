<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Models\LogApi;

final class LogsController extends Controller
{
    public function index(): string
    {
        return $this->view('logs/index', [
            'registros' => LogApi::listar(Request::query('canal'), 100),
            'canal'     => Request::query('canal', ''),
        ], 'Log das APIs');
    }

    public function detalhe(string $id): string
    {
        $registro = LogApi::buscar((int) $id);

        if ($registro === null) {
            $this->naoEncontrado('Registro não encontrado.');
        }

        return $this->view('logs/detalhe', [
            'registro' => $registro,
        ], 'Requisição #' . (int) $id);
    }
}
