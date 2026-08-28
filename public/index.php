<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Controllers\AutenticacaoController;
use App\Controllers\CanaisController;
use App\Controllers\FilaController;
use App\Controllers\LogsController;
use App\Controllers\ModelosController;
use App\Controllers\PainelController;
use App\Controllers\ProdutosController;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Router;
use App\Core\View;

if (Config::bool('APP_DEBUG')) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

$router = new Router();

// Autenticação
$router->get('/login', [AutenticacaoController::class, 'formulario']);
$router->post('/login', [AutenticacaoController::class, 'entrar']);
$router->post('/sair', [AutenticacaoController::class, 'sair']);

// Painel
$router->get('/', [PainelController::class, 'index']);

// Produtos
$router->get('/produtos', [ProdutosController::class, 'index']);
$router->get('/produtos/novo', [ProdutosController::class, 'novo']);
$router->post('/produtos', [ProdutosController::class, 'criar']);
$router->get('/produtos/{id}', [ProdutosController::class, 'editar']);
$router->post('/produtos/{id}', [ProdutosController::class, 'atualizar']);
$router->post('/produtos/{id}/excluir', [ProdutosController::class, 'excluir']);
$router->post('/produtos/{id}/eixos', [ProdutosController::class, 'salvarEixos']);
$router->post('/produtos/{id}/variacoes', [ProdutosController::class, 'salvarVariacoes']);
$router->post('/produtos/{id}/imagens', [ProdutosController::class, 'enviarImagens']);
$router->post('/imagens/{id}/excluir', [ProdutosController::class, 'excluirImagem']);
$router->get('/produtos/{id}/canal/{canal}', [ProdutosController::class, 'canal']);
$router->post('/produtos/{id}/canal/{canal}', [ProdutosController::class, 'salvarCanal']);
$router->post('/produtos/{id}/publicar/{canal}', [ProdutosController::class, 'publicar']);

// Modelos de produto
$router->get('/modelos', [ModelosController::class, 'index']);
$router->post('/modelos', [ModelosController::class, 'criar']);
$router->get('/modelos/{id}', [ModelosController::class, 'editar']);
$router->post('/modelos/{id}', [ModelosController::class, 'salvar']);
$router->post('/modelos/{id}/excluir', [ModelosController::class, 'excluir']);
$router->get('/modelos/{id}/categorias', [ModelosController::class, 'buscarCategorias']);
$router->get('/modelos/{id}/atributos', [ModelosController::class, 'atributos']);

// Canais
$router->get('/canais', [CanaisController::class, 'index']);
$router->post('/canais/{canal}/credenciais', [CanaisController::class, 'salvarCredenciais']);
$router->get('/canais/{canal}/conectar', [CanaisController::class, 'conectar']);
$router->get('/canais/{canal}/callback', [CanaisController::class, 'callback']);
$router->post('/canais/{canal}/desconectar', [CanaisController::class, 'desconectar']);
$router->post('/canais/{canal}/logistica', [CanaisController::class, 'salvarLogistica']);

// Fila
$router->get('/fila', [FilaController::class, 'index']);
$router->post('/fila/processar', [FilaController::class, 'processar']);
$router->post('/fila/{id}/reenfileirar', [FilaController::class, 'reenfileirar']);

// Logs
$router->get('/logs', [LogsController::class, 'index']);
$router->get('/logs/{id}', [LogsController::class, 'detalhe']);

$rota = $router->resolver(Request::metodo(), Request::caminho());

if ($rota === null) {
    http_response_code(404);
    echo View::render('erro', ['mensagem' => 'Página não encontrada.'], 'Não encontrado');
    exit;
}

[$classe, $metodo] = $rota['acao'];

// Toda rota exige sessão, menos o login e os callbacks de OAuth — que
// chegam de fora, redirecionados pela plataforma.
$publicas = [
    [AutenticacaoController::class, 'formulario'],
    [AutenticacaoController::class, 'entrar'],
    [CanaisController::class, 'callback'],
];

if (!in_array([$classe, $metodo], $publicas, true)) {
    Auth::exigir();
}

if (Request::metodo() === 'POST') {
    Csrf::exigir();
}

try {
    echo (new $classe())->{$metodo}(...array_values($rota['params']));
} catch (Throwable $e) {
    if (Config::bool('APP_DEBUG')) {
        throw $e;
    }

    http_response_code(500);
    error_log((string) $e);
    echo View::render(
        'erro',
        ['mensagem' => 'Algo deu errado ao processar a requisição.'],
        'Erro'
    );
}
