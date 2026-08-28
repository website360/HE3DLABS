<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Flash;
use App\Core\Request;
use App\Core\View;

final class AutenticacaoController extends Controller
{
    public function formulario(): string
    {
        if (Auth::logado()) {
            return $this->redirecionar('/');
        }

        return View::parcial('auth/login', ['erro' => null]);
    }

    public function entrar(): string
    {
        $email = Request::post('email', '');
        $senha = Request::post('senha', '');

        if ($email === null || $senha === null || !Auth::tentar($email, $senha)) {
            // Mensagem única de propósito: dizer "email não existe" revelaria
            // quais endereços têm conta.
            return View::parcial('auth/login', ['erro' => 'E-mail ou senha incorretos.']);
        }

        return $this->redirecionar('/');
    }

    public function sair(): string
    {
        Auth::sair();
        Flash::sucesso('Sessão encerrada.');

        return $this->redirecionar('/login');
    }
}
