<?php

declare(strict_types=1);

namespace App\Services\Canal\MercadoLivre;

use App\Core\Config;
use App\Dominio\Canal;
use App\Dominio\ContextoPublicacao;
use App\Dominio\Produto;
use App\Models\Anuncios;
use App\Models\Categorias;
use App\Models\Contas;
use App\Services\Canal\CanalInterface;
use App\Services\Canal\ErroPublicacao;
use App\Services\Canal\ResultadoPublicacao;
use App\Services\Http\RespostaHttp;

final class Publicador implements CanalInterface
{
    public function __construct(
        private readonly Cliente $cliente,
    ) {
    }

    public function canal(): Canal
    {
        return Canal::MercadoLivre;
    }

    public function conectado(): bool
    {
        return Contas::conectada(Canal::MercadoLivre);
    }

    // ------------------------------------------------------------------
    // Categorias e atributos
    // ------------------------------------------------------------------

    public function buscarCategorias(string $termo): array
    {
        $site = Config::get('ML_SITE_ID', 'MLB');

        $resposta = $this->cliente->getPublico(
            "/sites/{$site}/domain_discovery/search",
            ['q' => $termo, 'limit' => 20]
        );

        if (!$resposta->sucesso()) {
            throw $this->erro($resposta, 'categorias');
        }

        $categorias = [];

        foreach ($resposta->json() as $sugestao) {
            if (!is_array($sugestao) || !isset($sugestao['category_id'])) {
                continue;
            }

            $id = (string) $sugestao['category_id'];
            $nome = (string) ($sugestao['category_name'] ?? $id);

            Categorias::salvar(Canal::MercadoLivre, $id, $nome, $nome);

            $categorias[] = ['id' => $id, 'nome' => $nome, 'caminho' => $nome];
        }

        return $categorias;
    }

    public function atributosDaCategoria(string $categoriaId): array
    {
        if (!Categorias::expirada(Canal::MercadoLivre, $categoriaId)) {
            $cache = Categorias::buscar(Canal::MercadoLivre, $categoriaId);

            if ($cache !== null && $cache['atributos'] !== []) {
                return $cache['atributos'];
            }
        }

        $resposta = $this->cliente->getPublico("/categories/{$categoriaId}/attributes");

        if (!$resposta->sucesso()) {
            throw $this->erro($resposta, 'atributos');
        }

        $atributos = [];

        foreach ($resposta->json() as $bruto) {
            if (!is_array($bruto) || !isset($bruto['id'])) {
                continue;
            }

            $tags = is_array($bruto['tags'] ?? null) ? $bruto['tags'] : [];

            $atributos[] = [
                'id'          => (string) $bruto['id'],
                'nome'        => (string) ($bruto['name'] ?? $bruto['id']),
                'obrigatorio' => ($tags['required'] ?? false) === true,
                'tipo'        => (string) ($bruto['value_type'] ?? 'string'),
                'valores'     => array_map(
                    static fn (array $v): array => [
                        'id'   => (string) ($v['id'] ?? ''),
                        'nome' => (string) ($v['name'] ?? ''),
                    ],
                    array_filter(
                        is_array($bruto['values'] ?? null) ? $bruto['values'] : [],
                        'is_array'
                    )
                ),
            ];
        }

        $nomeCategoria = Categorias::buscar(Canal::MercadoLivre, $categoriaId)['nome'] ?? $categoriaId;
        Categorias::salvar(Canal::MercadoLivre, $categoriaId, (string) $nomeCategoria, '', $atributos);

        return $atributos;
    }

    // ------------------------------------------------------------------
    // Publicação
    // ------------------------------------------------------------------

    /**
     * Cria o anúncio em duas etapas, cada uma gravando seu resultado
     * antes da seguinte. Se o job cair depois de criar o item, a retomada
     * encontra o id_remoto já gravado e vai direto para a descrição, em
     * vez de criar um segundo anúncio.
     */
    public function publicar(Produto $produto, ContextoPublicacao $contexto, int $anuncioId): ResultadoPublicacao
    {
        $anuncio = Anuncios::porId($anuncioId);
        $jaCriado = $anuncio['id_remoto'] ?? null;

        if (is_string($jaCriado) && $jaCriado !== '') {
            // Etapa 1 já concluída numa tentativa anterior.
            return $this->atualizar($produto, $contexto, $anuncioId, $jaCriado);
        }

        $payload = Mapeador::montarItem($produto, $contexto);
        $resposta = $this->cliente->post('/items', $payload);

        if (!$resposta->sucesso()) {
            throw $this->erro($resposta, 'criar item');
        }

        $corpo = $resposta->json();
        $idRemoto = (string) ($corpo['id'] ?? '');

        if ($idRemoto === '') {
            throw ErroPublicacao::permanente(
                'O Mercado Livre não devolveu o id do item.',
                'criar item'
            );
        }

        $url = isset($corpo['permalink']) ? (string) $corpo['permalink'] : null;

        // Grava antes de seguir: é o que torna a retomada segura.
        Anuncios::marcarPublicado($anuncioId, $idRemoto, $url);

        $variacoes = $this->registrarVariacoes($produto, $anuncioId, $corpo);

        $this->enviarDescricao($idRemoto, $contexto);

        return new ResultadoPublicacao($idRemoto, $url, $variacoes, $corpo);
    }

    public function atualizar(
        Produto $produto,
        ContextoPublicacao $contexto,
        int $anuncioId,
        string $idRemoto
    ): ResultadoPublicacao {
        $payload = Mapeador::montarItem($produto, $contexto);

        // Categoria e modo de compra não podem mudar em item publicado.
        unset($payload['category_id'], $payload['buying_mode'], $payload['listing_type_id']);

        $resposta = $this->cliente->put("/items/{$idRemoto}", $payload);

        if (!$resposta->sucesso()) {
            throw $this->erro($resposta, 'atualizar item');
        }

        $corpo = $resposta->json();
        $url = isset($corpo['permalink']) ? (string) $corpo['permalink'] : null;

        $variacoes = $this->registrarVariacoes($produto, $anuncioId, $corpo);

        $this->enviarDescricao($idRemoto, $contexto, true);

        return new ResultadoPublicacao($idRemoto, $url, $variacoes, $corpo);
    }

    /**
     * A descrição é um recurso à parte, criado depois do item.
     * Em item já existente o verbo é PUT.
     */
    private function enviarDescricao(string $idRemoto, ContextoPublicacao $contexto, bool $atualizando = false): void
    {
        $payload = Mapeador::montarDescricao($contexto);

        $resposta = $atualizando
            ? $this->cliente->put("/items/{$idRemoto}/description", $payload)
            : $this->cliente->post("/items/{$idRemoto}/description", $payload);

        // Ao criar, a descrição pode já existir de uma tentativa anterior;
        // nesse caso o PUT resolve e não é motivo para falhar o job.
        if (!$resposta->sucesso() && !$atualizando) {
            $retentativa = $this->cliente->put("/items/{$idRemoto}/description", $payload);

            if (!$retentativa->sucesso()) {
                throw $this->erro($retentativa, 'descricao');
            }

            return;
        }

        if (!$resposta->sucesso()) {
            throw $this->erro($resposta, 'descricao');
        }
    }

    /**
     * Casa as variações devolvidas pela plataforma com as locais, pelo
     * SELLER_SKU. Sem esse vínculo não há como atualizar preço e estoque
     * de uma variação específica depois.
     *
     * @param array<string,mixed> $corpo
     * @return array<int,string>
     */
    private function registrarVariacoes(Produto $produto, int $anuncioId, array $corpo): array
    {
        $remotas = is_array($corpo['variations'] ?? null) ? $corpo['variations'] : [];

        if ($remotas === []) {
            return [];
        }

        // SKU local => id da variação local
        $porSku = [];
        foreach ($produto->variacoes as $variacao) {
            $porSku[$variacao->sku] = $variacao->id;
        }

        $mapa = [];

        foreach ($remotas as $remota) {
            if (!is_array($remota)) {
                continue;
            }

            $sku = null;

            foreach (($remota['attributes'] ?? []) as $atributo) {
                if (is_array($atributo) && ($atributo['id'] ?? '') === 'SELLER_SKU') {
                    $sku = (string) ($atributo['value_name'] ?? '');
                    break;
                }
            }

            if ($sku === null || !isset($porSku[$sku])) {
                continue;
            }

            $variacaoId = $porSku[$sku];
            $idRemoto = (string) ($remota['id'] ?? '');

            if ($idRemoto !== '') {
                Anuncios::registrarVariacao($anuncioId, $variacaoId, $idRemoto);
                $mapa[$variacaoId] = $idRemoto;
            }
        }

        return $mapa;
    }

    private function erro(RespostaHttp $resposta, string $etapa): ErroPublicacao
    {
        return new ErroPublicacao($resposta->resumoErro(), $resposta->transitorio(), $etapa);
    }
}
