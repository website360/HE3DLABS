<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Flash;
use App\Core\Request;
use App\Dominio\Canal;
use App\Models\Categorias;
use App\Models\Contas;
use App\Models\Modelos;
use App\Services\Canal\Fabrica;
use Throwable;

final class ModelosController extends Controller
{
    public function index(): string
    {
        return $this->view('modelos/index', [
            'modelos' => Modelos::listar(),
        ], 'Modelos de produto');
    }

    public function criar(): string
    {
        $nome = Request::post('nome');

        if ($nome === null) {
            Flash::erro('Informe o nome do modelo.');

            return $this->redirecionar('/modelos');
        }

        try {
            $id = Modelos::criar($nome);
        } catch (Throwable) {
            Flash::erro("Já existe um modelo chamado '{$nome}'.");

            return $this->redirecionar('/modelos');
        }

        return $this->redirecionar("/modelos/{$id}");
    }

    public function editar(string $id): string
    {
        $modeloId = (int) $id;
        $modelo = Modelos::buscar($modeloId);

        if ($modelo === null) {
            $this->naoEncontrado('Modelo não encontrado.');
        }

        $configuracoes = Modelos::configuracoes($modeloId);

        // Atributos vêm do cache local; a busca na API acontece quando o
        // usuário escolhe a categoria.
        $atributos = [];
        foreach (Canal::todos() as $canal) {
            $categoriaId = $configuracoes[$canal->value]['categoria_id_remota'] ?? null;

            $atributos[$canal->value] = $categoriaId !== null
                ? (Categorias::buscar($canal, (string) $categoriaId)['atributos'] ?? [])
                : [];
        }

        $conectados = [];
        foreach (Canal::todos() as $canal) {
            $conectados[$canal->value] = Contas::conectada($canal);
        }

        return $this->view('modelos/editar', [
            'modelo'        => $modelo,
            'configuracoes' => $configuracoes,
            'atributos'     => $atributos,
            'conectados'    => $conectados,
        ], $modelo['nome']);
    }

    public function salvar(string $id): string
    {
        $modeloId = (int) $id;

        $nome = Request::post('nome');
        if ($nome !== null) {
            Modelos::renomear($modeloId, $nome);
        }

        foreach (Canal::todos() as $canal) {
            $categoriaId = Request::post("categoria_{$canal->value}");

            if ($categoriaId === null) {
                continue;
            }

            $atributos = [];

            foreach (Request::postArray("atributo_{$canal->value}") as $chave => $valor) {
                $texto = is_string($valor) ? trim($valor) : '';

                if ($texto !== '') {
                    $atributos[(string) $chave] = $texto;
                }
            }

            // Mapeamento de eixo para atributo do canal, quando informado.
            $eixos = [];
            foreach (Request::postArray("eixo_{$canal->value}") as $nomeEixo => $idAtributo) {
                $texto = is_string($idAtributo) ? trim($idAtributo) : '';

                if ($texto !== '') {
                    $eixos[(string) $nomeEixo] = $texto;
                }
            }

            if ($eixos !== []) {
                $atributos['eixos'] = $eixos;
            }

            $cache = Categorias::buscar($canal, $categoriaId);

            Modelos::salvarConfiguracao(
                $modeloId,
                $canal,
                $categoriaId,
                (string) ($cache['nome'] ?? $categoriaId),
                $atributos
            );
        }

        Flash::sucesso('Modelo salvo.');

        return $this->redirecionar("/modelos/{$modeloId}");
    }

    public function excluir(string $id): string
    {
        Modelos::excluir((int) $id);
        Flash::sucesso('Modelo excluído. Os produtos que o usavam ficaram sem modelo.');

        return $this->redirecionar('/modelos');
    }

    /** Busca de categorias, consumida pela tela via fetch. */
    public function buscarCategorias(string $id): string
    {
        $canal = Canal::tryFrom((string) Request::query('canal', ''));
        $termo = Request::query('termo', '');

        if ($canal === null || $termo === null || mb_strlen($termo) < 2) {
            return $this->json(['categorias' => []]);
        }

        // Cache primeiro: evita bater na API a cada tecla digitada.
        $cacheadas = Categorias::procurar($canal, $termo, 15);

        if ($cacheadas !== []) {
            return $this->json([
                'categorias' => array_map(
                    static fn (array $c): array => [
                        'id'      => (string) $c['categoria_id'],
                        'nome'    => (string) $c['nome'],
                        'caminho' => (string) $c['caminho'],
                    ],
                    $cacheadas
                ),
                'origem' => 'cache',
            ]);
        }

        if (!Contas::conectada($canal)) {
            return $this->json([
                'categorias' => [],
                'erro'       => "A conta {$canal->com('de')} não está conectada, "
                    . 'então não dá para consultar as categorias dela.',
            ]);
        }

        try {
            return $this->json([
                'categorias' => Fabrica::para($canal)->buscarCategorias($termo),
                'origem'     => 'api',
            ]);
        } catch (Throwable $e) {
            return $this->json(['categorias' => [], 'erro' => $e->getMessage()], 200);
        }
    }

    /** Atributos da categoria escolhida, para montar o formulário dinâmico. */
    public function atributos(string $id): string
    {
        $canal = Canal::tryFrom((string) Request::query('canal', ''));
        $categoriaId = Request::query('categoria');

        if ($canal === null || $categoriaId === null) {
            return $this->json(['atributos' => []]);
        }

        if (!Contas::conectada($canal)) {
            $cache = Categorias::buscar($canal, $categoriaId);

            return $this->json([
                'atributos' => $cache['atributos'] ?? [],
                'origem'    => 'cache',
            ]);
        }

        try {
            return $this->json([
                'atributos' => Fabrica::para($canal)->atributosDaCategoria($categoriaId),
                'origem'    => 'api',
            ]);
        } catch (Throwable $e) {
            return $this->json(['atributos' => [], 'erro' => $e->getMessage()]);
        }
    }
}
