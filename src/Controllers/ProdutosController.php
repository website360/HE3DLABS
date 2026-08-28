<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Db;
use App\Core\Flash;
use App\Core\Request;
use App\Dominio\Canal;
use App\Models\Anuncios;
use App\Models\Fila;
use App\Models\Imagens;
use App\Models\Modelos;
use App\Models\Produtos;
use App\Services\Canal\Validador;
use App\Services\Imagem\Uploader;
use Throwable;

final class ProdutosController extends Controller
{
    public function index(): string
    {
        return $this->view('produtos/index', [
            'produtos' => Produtos::listar(Request::query('busca'), Request::query('status')),
            'busca'    => Request::query('busca', ''),
            'status'   => Request::query('status', ''),
        ], 'Produtos');
    }

    public function novo(): string
    {
        return $this->view('produtos/novo', [
            'modelos' => Modelos::listar(),
        ], 'Novo produto');
    }

    public function criar(): string
    {
        $sku = Request::post('sku_base');
        $titulo = Request::post('titulo');

        if ($sku === null || $titulo === null) {
            Flash::erro('SKU e título são obrigatórios.');

            return $this->redirecionar('/produtos/novo');
        }

        if (Produtos::existeSku($sku)) {
            Flash::erro("Já existe um produto com o SKU {$sku}.");

            return $this->redirecionar('/produtos/novo');
        }

        $id = Db::transacao(static function () use ($sku, $titulo): int {
            $id = Produtos::criar([
                'sku_base'  => $sku,
                'titulo'    => $titulo,
                'descricao' => Request::post('descricao'),
                'marca'     => Request::post('marca'),
                'modelo_id' => Request::postInt('modelo_id'),
            ]);

            // Todo produto nasce com uma variação: produto simples é o
            // caso de uma variação só, e assim não há caminho especial.
            Produtos::criarVariacao($id, ['sku' => $sku]);

            return $id;
        });

        Flash::sucesso('Produto criado. Agora preencha variações e imagens.');

        return $this->redirecionar("/produtos/{$id}");
    }

    public function editar(string $id): string
    {
        $produtoId = (int) $id;
        $produto = Produtos::buscar($produtoId);

        if ($produto === null) {
            $this->naoEncontrado('Produto não encontrado.');
        }

        $dominio = Produtos::montar($produtoId);
        $anuncios = Anuncios::doProduto($produtoId);

        // Diagnóstico por canal, calculado sem tocar nas APIs.
        $diagnostico = [];
        foreach (Canal::todos() as $canal) {
            $diagnostico[$canal->value] = $dominio === null
                ? []
                : Validador::verificar($dominio, $canal);
        }

        return $this->view('produtos/editar', [
            'produto'     => $produto,
            'dominio'     => $dominio,
            'eixos'       => Produtos::eixos($produtoId),
            'variacoes'   => Produtos::variacoes($produtoId),
            'valores'     => Produtos::valoresDeVariacao($produtoId),
            'imagens'     => Produtos::imagens($produtoId),
            'modelos'     => Modelos::listar(),
            'anuncios'    => $anuncios,
            'diagnostico' => $diagnostico,
        ], $produto['titulo']);
    }

    public function atualizar(string $id): string
    {
        $produtoId = (int) $id;
        $sku = Request::post('sku_base');
        $titulo = Request::post('titulo');

        if ($sku === null || $titulo === null) {
            Flash::erro('SKU e título são obrigatórios.');

            return $this->redirecionar("/produtos/{$produtoId}");
        }

        if (Produtos::existeSku($sku, $produtoId)) {
            Flash::erro("Já existe outro produto com o SKU {$sku}.");

            return $this->redirecionar("/produtos/{$produtoId}");
        }

        Produtos::atualizar($produtoId, [
            'sku_base'  => $sku,
            'titulo'    => $titulo,
            'descricao' => Request::post('descricao'),
            'marca'     => Request::post('marca'),
            'modelo_id' => Request::postInt('modelo_id'),
            'status'    => Request::post('status', 'rascunho'),
        ]);

        Flash::sucesso('Produto salvo.');

        return $this->redirecionar("/produtos/{$produtoId}");
    }

    public function excluir(string $id): string
    {
        Produtos::excluir((int) $id);
        Flash::sucesso('Produto excluído.');

        return $this->redirecionar('/produtos');
    }

    // ------------------------------------------------------------------
    // Eixos e variações
    // ------------------------------------------------------------------

    public function salvarEixos(string $id): string
    {
        $produtoId = (int) $id;

        $nomes = array_map(
            static fn ($v): string => is_string($v) ? trim($v) : '',
            Request::postArray('eixos')
        );

        try {
            Produtos::definirEixos($produtoId, $nomes);
            Flash::sucesso('Eixos de variação atualizados. Revise os valores de cada variação.');
        } catch (Throwable $e) {
            Flash::erro($e->getMessage());
        }

        return $this->redirecionar("/produtos/{$produtoId}");
    }

    /**
     * Salva a grade inteira de uma vez: cria as novas, atualiza as
     * existentes, remove as marcadas.
     */
    public function salvarVariacoes(string $id): string
    {
        $produtoId = (int) $id;
        $linhas = Request::postArray('variacao');
        $eixos = Produtos::eixos($produtoId);

        try {
            Db::transacao(static function () use ($linhas, $produtoId, $eixos): void {
                foreach ($linhas as $chave => $dados) {
                    if (!is_array($dados)) {
                        continue;
                    }

                    $sku = trim((string) ($dados['sku'] ?? ''));

                    if ($sku === '') {
                        continue;
                    }

                    $campos = [
                        'sku'            => $sku,
                        'preco'          => self::decimal($dados['preco'] ?? '0'),
                        'estoque'        => (int) ($dados['estoque'] ?? 0),
                        'peso_g'         => (int) ($dados['peso_g'] ?? 0),
                        'comprimento_cm' => self::decimal($dados['comprimento_cm'] ?? '0'),
                        'largura_cm'     => self::decimal($dados['largura_cm'] ?? '0'),
                        'altura_cm'      => self::decimal($dados['altura_cm'] ?? '0'),
                        'gtin'           => trim((string) ($dados['gtin'] ?? '')) ?: null,
                        'ativo'          => isset($dados['ativo']) ? 1 : 0,
                    ];

                    $existente = str_starts_with((string) $chave, 'novo') ? null : (int) $chave;

                    if (!empty($dados['excluir']) && $existente !== null) {
                        Produtos::excluirVariacao($existente);
                        continue;
                    }

                    $variacaoId = $existente ?? Produtos::criarVariacao($produtoId, $campos);

                    if ($existente !== null) {
                        Produtos::atualizarVariacao($existente, $campos);
                    }

                    foreach ($eixos as $eixo) {
                        $valor = trim((string) ($dados['valores'][$eixo['id']] ?? ''));

                        if ($valor !== '') {
                            Produtos::definirValorDeVariacao($variacaoId, (int) $eixo['id'], $valor);
                        }
                    }
                }
            });

            Flash::sucesso('Variações salvas.');
        } catch (Throwable $e) {
            Flash::erro('Não foi possível salvar: ' . $e->getMessage());
        }

        return $this->redirecionar("/produtos/{$produtoId}");
    }

    // ------------------------------------------------------------------
    // Imagens
    // ------------------------------------------------------------------

    public function enviarImagens(string $id): string
    {
        $produtoId = (int) $id;
        $produto = Produtos::buscar($produtoId);

        if ($produto === null) {
            $this->naoEncontrado('Produto não encontrado.');
        }

        $arquivos = Request::arquivos('imagens');

        if ($arquivos === []) {
            Flash::aviso('Nenhum arquivo selecionado.');

            return $this->redirecionar("/produtos/{$produtoId}");
        }

        $enviadas = 0;
        $erros = [];

        foreach ($arquivos as $arquivo) {
            try {
                $nome = Uploader::salvar($arquivo, (string) $produto['sku_base']);
                Imagens::criar($produtoId, $nome, Request::postInt('variacao_id'));
                $enviadas++;
            } catch (Throwable $e) {
                $erros[] = $e->getMessage();
            }
        }

        if ($enviadas > 0) {
            Flash::sucesso("{$enviadas} imagem(ns) enviada(s).");
        }

        if ($erros !== []) {
            Flash::erro(implode(' ', array_unique($erros)));
        }

        return $this->redirecionar("/produtos/{$produtoId}");
    }

    public function excluirImagem(string $id): string
    {
        $imagem = Imagens::buscar((int) $id);

        if ($imagem === null) {
            $this->naoEncontrado('Imagem não encontrada.');
        }

        Imagens::excluir((int) $id);
        Flash::sucesso('Imagem removida.');

        return $this->redirecionar('/produtos/' . (int) $imagem['produto_id']);
    }

    // ------------------------------------------------------------------
    // Conteúdo e preço por canal
    // ------------------------------------------------------------------

    public function canal(string $id, string $canal): string
    {
        $produtoId = (int) $id;
        $canalEnum = Canal::tryFrom($canal);

        if ($canalEnum === null) {
            $this->naoEncontrado('Canal desconhecido.');
        }

        $produto = Produtos::buscar($produtoId);

        if ($produto === null) {
            $this->naoEncontrado('Produto não encontrado.');
        }

        $conteudo = Db::um(
            'SELECT * FROM produto_canal_conteudo WHERE produto_id = ? AND canal = ?',
            [$produtoId, $canalEnum->value]
        );

        $precos = [];
        foreach (Db::todos(
            'SELECT pc.variacao_id, pc.preco
               FROM precos_canal pc
               JOIN variacoes v ON v.id = pc.variacao_id
              WHERE v.produto_id = ? AND pc.canal = ?',
            [$produtoId, $canalEnum->value]
        ) as $linha) {
            $precos[(int) $linha['variacao_id']] = (float) $linha['preco'];
        }

        return $this->view('produtos/canal', [
            'produto'   => $produto,
            'canal'     => $canalEnum,
            'conteudo'  => $conteudo,
            'variacoes' => Produtos::variacoes($produtoId),
            'precos'    => $precos,
        ], $produto['titulo'] . ' — ' . $canalEnum->rotulo());
    }

    public function salvarCanal(string $id, string $canal): string
    {
        $produtoId = (int) $id;
        $canalEnum = Canal::tryFrom($canal);

        if ($canalEnum === null) {
            $this->naoEncontrado('Canal desconhecido.');
        }

        Db::executar(
            'INSERT INTO produto_canal_conteudo (produto_id, canal, titulo, descricao)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE titulo = VALUES(titulo), descricao = VALUES(descricao)',
            [$produtoId, $canalEnum->value, Request::post('titulo'), Request::post('descricao')]
        );

        foreach (Request::postArray('preco') as $variacaoId => $valor) {
            $texto = trim((string) $valor);
            $variacaoId = (int) $variacaoId;

            if ($texto === '') {
                Db::executar(
                    'DELETE FROM precos_canal WHERE variacao_id = ? AND canal = ?',
                    [$variacaoId, $canalEnum->value]
                );
                continue;
            }

            Db::executar(
                'INSERT INTO precos_canal (variacao_id, canal, preco)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE preco = VALUES(preco)',
                [$variacaoId, $canalEnum->value, self::decimal($texto)]
            );
        }

        Flash::sucesso("Conteúdo {$canalEnum->com('de')} salvo.");

        return $this->redirecionar("/produtos/{$produtoId}/canal/{$canalEnum->value}");
    }

    // ------------------------------------------------------------------
    // Publicação
    // ------------------------------------------------------------------

    /**
     * Valida antes de enfileirar: um erro de preenchimento aparece na
     * tela na hora, em vez de voltar da plataforma minutos depois.
     */
    public function publicar(string $id, string $canal): string
    {
        $produtoId = (int) $id;
        $canalEnum = Canal::tryFrom($canal);

        if ($canalEnum === null) {
            $this->naoEncontrado('Canal desconhecido.');
        }

        $produto = Produtos::montar($produtoId);

        if ($produto === null) {
            $this->naoEncontrado('Produto não encontrado.');
        }

        $problemas = Validador::verificar($produto, $canalEnum);

        if ($problemas !== []) {
            Flash::erro(
                'Não dá para publicar ainda: ' . implode(' ', array_slice($problemas, 0, 3))
                . (count($problemas) > 3 ? ' (e mais ' . (count($problemas) - 3) . ')' : '')
            );

            return $this->redirecionar("/produtos/{$produtoId}");
        }

        $anuncio = Anuncios::garantir($produtoId, $canalEnum);
        $acao = ($anuncio['id_remoto'] ?? null) !== null ? 'atualizar' : 'criar';

        Fila::enfileirar((int) $anuncio['id'], $acao);
        Anuncios::marcarNaFila((int) $anuncio['id']);

        Flash::sucesso(
            "Publicação {$canalEnum->com('em')} entrou na fila. "
            . 'O worker processa a cada 5 minutos; em Fila você pode rodar agora.'
        );

        return $this->redirecionar("/produtos/{$produtoId}");
    }

    /** Aceita "1.234,56" e "1234.56", como o usuário digita. */
    private static function decimal(mixed $valor): float
    {
        $texto = trim((string) $valor);

        if ($texto === '') {
            return 0.0;
        }

        if (str_contains($texto, ',')) {
            $texto = str_replace(['.', ','], ['', '.'], $texto);
        }

        return is_numeric($texto) ? (float) $texto : 0.0;
    }
}
