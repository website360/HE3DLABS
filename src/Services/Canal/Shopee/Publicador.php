<?php

declare(strict_types=1);

namespace App\Services\Canal\Shopee;

use App\Dominio\Canal;
use App\Dominio\ContextoPublicacao;
use App\Dominio\Produto;
use App\Models\Anuncios;
use App\Models\Categorias;
use App\Models\Contas;
use App\Models\Imagens;
use App\Services\Canal\CanalInterface;
use App\Services\Canal\ErroPublicacao;
use App\Services\Canal\ResultadoPublicacao;

final class Publicador implements CanalInterface
{
    public function __construct(
        private readonly Cliente $cliente,
    ) {
    }

    public function canal(): Canal
    {
        return Canal::Shopee;
    }

    public function conectado(): bool
    {
        return Contas::conectada(Canal::Shopee);
    }

    // ------------------------------------------------------------------
    // Categorias e atributos
    // ------------------------------------------------------------------

    /**
     * A Shopee devolve a árvore inteira numa chamada só. Guardamos tudo
     * em cache e filtramos localmente, em vez de bater na API a cada letra.
     */
    public function buscarCategorias(string $termo): array
    {
        $cacheadas = Categorias::procurar(Canal::Shopee, $termo);

        if ($cacheadas === []) {
            $this->sincronizarCategorias();
            $cacheadas = Categorias::procurar(Canal::Shopee, $termo);
        }

        return array_map(
            static fn (array $c): array => [
                'id'      => (string) $c['categoria_id'],
                'nome'    => (string) $c['nome'],
                'caminho' => (string) $c['caminho'],
            ],
            $cacheadas
        );
    }

    public function sincronizarCategorias(): int
    {
        $resposta = $this->cliente->get('/api/v2/product/get_category', ['language' => 'pt-br']);
        $conteudo = $this->cliente->extrair($resposta, 'categorias');

        $lista = is_array($conteudo['category_list'] ?? null) ? $conteudo['category_list'] : [];

        // Indexa por id para reconstruir o caminho completo da árvore.
        $porId = [];
        foreach ($lista as $categoria) {
            if (is_array($categoria) && isset($categoria['category_id'])) {
                $porId[(string) $categoria['category_id']] = $categoria;
            }
        }

        $total = 0;

        foreach ($porId as $id => $categoria) {
            $nome = (string) ($categoria['display_category_name']
                ?? $categoria['original_category_name']
                ?? $id);

            Categorias::salvar(Canal::Shopee, (string) $id, $nome, $this->caminho($porId, (string) $id));
            $total++;
        }

        return $total;
    }

    /** @param array<string,array<string,mixed>> $porId */
    private function caminho(array $porId, string $id, int $profundidade = 0): string
    {
        if ($profundidade > 10 || !isset($porId[$id])) {
            return '';
        }

        $categoria = $porId[$id];
        $nome = (string) ($categoria['display_category_name']
            ?? $categoria['original_category_name']
            ?? $id);

        $pai = (string) ($categoria['parent_category_id'] ?? '0');

        if ($pai === '0' || $pai === '' || $pai === $id) {
            return $nome;
        }

        $caminhoPai = $this->caminho($porId, $pai, $profundidade + 1);

        return $caminhoPai === '' ? $nome : "{$caminhoPai} > {$nome}";
    }

    public function atributosDaCategoria(string $categoriaId): array
    {
        if (!Categorias::expirada(Canal::Shopee, $categoriaId)) {
            $cache = Categorias::buscar(Canal::Shopee, $categoriaId);

            if ($cache !== null && $cache['atributos'] !== []) {
                return $cache['atributos'];
            }
        }

        $resposta = $this->cliente->get('/api/v2/product/get_attributes', [
            'category_id' => (int) $categoriaId,
            'language'    => 'pt-br',
        ]);

        $conteudo = $this->cliente->extrair($resposta, 'atributos');
        $lista = is_array($conteudo['attribute_list'] ?? null) ? $conteudo['attribute_list'] : [];

        $atributos = [];

        foreach ($lista as $bruto) {
            if (!is_array($bruto) || !isset($bruto['attribute_id'])) {
                continue;
            }

            $atributos[] = [
                'id'          => (string) $bruto['attribute_id'],
                'nome'        => (string) ($bruto['original_attribute_name'] ?? $bruto['attribute_id']),
                'obrigatorio' => ($bruto['is_mandatory'] ?? false) === true,
                'tipo'        => (string) ($bruto['input_type'] ?? 'TEXT_FILED'),
                'valores'     => array_map(
                    static fn (array $v): array => [
                        'id'   => (string) ($v['value_id'] ?? ''),
                        'nome' => (string) ($v['original_value_name'] ?? ''),
                    ],
                    array_filter(
                        is_array($bruto['attribute_value_list'] ?? null) ? $bruto['attribute_value_list'] : [],
                        'is_array'
                    )
                ),
            ];
        }

        $cache = Categorias::buscar(Canal::Shopee, $categoriaId);
        Categorias::salvar(
            Canal::Shopee,
            $categoriaId,
            (string) ($cache['nome'] ?? $categoriaId),
            (string) ($cache['caminho'] ?? ''),
            $atributos
        );

        return $atributos;
    }

    // ------------------------------------------------------------------
    // Publicação
    // ------------------------------------------------------------------

    /**
     * Três etapas, cada uma gravando antes da próxima:
     *   1. envia as imagens para a media space (cacheando cada image_id)
     *   2. cria o item
     *   3. cria a matriz de variações
     *
     * Se o job cair entre a 2 e a 3, a retomada encontra o item_id já
     * gravado e vai direto para as variações, sem criar um segundo anúncio.
     */
    public function publicar(Produto $produto, ContextoPublicacao $contexto, int $anuncioId): ResultadoPublicacao
    {
        $contexto = $this->enviarImagens($produto, $contexto);

        $anuncio = Anuncios::porId($anuncioId);
        $jaCriado = $anuncio['id_remoto'] ?? null;

        if (is_string($jaCriado) && $jaCriado !== '') {
            return $this->atualizar($produto, $contexto, $anuncioId, $jaCriado);
        }

        $payload = Mapeador::montarItem($produto, $contexto);
        $conteudo = $this->cliente->extrair(
            $this->cliente->post('/api/v2/product/add_item', $payload),
            'criar item'
        );

        $itemId = (string) ($conteudo['item_id'] ?? '');

        if ($itemId === '') {
            throw ErroPublicacao::permanente('A Shopee não devolveu o item_id.', 'criar item');
        }

        Anuncios::marcarPublicado($anuncioId, $itemId, $this->urlDoAnuncio($itemId));

        $variacoes = $produto->temVariacoes()
            ? $this->criarVariacoes($produto, $contexto, $anuncioId, $itemId)
            : [];

        return new ResultadoPublicacao($itemId, $this->urlDoAnuncio($itemId), $variacoes, $conteudo);
    }

    public function atualizar(
        Produto $produto,
        ContextoPublicacao $contexto,
        int $anuncioId,
        string $idRemoto
    ): ResultadoPublicacao {
        $contexto = $this->enviarImagens($produto, $contexto);

        $payload = Mapeador::montarItem($produto, $contexto);
        $payload['item_id'] = (int) $idRemoto;

        // Categoria e logística não são alteráveis por update_item.
        unset($payload['category_id'], $payload['logistic_info']);

        $conteudo = $this->cliente->extrair(
            $this->cliente->post('/api/v2/product/update_item', $payload),
            'atualizar item'
        );

        $variacoes = [];

        if ($produto->temVariacoes() && Anuncios::variacoesRemotas($anuncioId) === []) {
            // Item existe mas a matriz de variações nunca foi criada:
            // é o caso de um job que caiu entre as etapas 2 e 3.
            $variacoes = $this->criarVariacoes($produto, $contexto, $anuncioId, $idRemoto);
        }

        return new ResultadoPublicacao($idRemoto, $this->urlDoAnuncio($idRemoto), $variacoes, $conteudo);
    }

    /**
     * Envia à media space apenas as imagens que ainda não têm image_id
     * gravado, e devolve o contexto com todos os ids preenchidos.
     */
    private function enviarImagens(Produto $produto, ContextoPublicacao $contexto): ContextoPublicacao
    {
        $conhecidos = Imagens::idsRemotosDoProduto($produto->id, Canal::Shopee);

        foreach ($produto->todasImagens() as $imagem) {
            if (isset($conhecidos[$imagem->id])) {
                continue;
            }

            $conteudo = $this->cliente->extrair(
                $this->cliente->enviarArquivo(
                    '/api/v2/media_space/upload_image',
                    'image',
                    $imagem->caminhoLocal()
                ),
                'imagens'
            );

            $idRemoto = $this->extrairImageId($conteudo);

            if ($idRemoto === null) {
                throw ErroPublicacao::permanente(
                    "A Shopee não devolveu image_id para {$imagem->arquivo}.",
                    'imagens'
                );
            }

            // Grava já: uma republicação não reenvia a mesma foto.
            Imagens::registrarIdRemoto($imagem->id, Canal::Shopee, $idRemoto);
            $conhecidos[$imagem->id] = $idRemoto;
        }

        return $contexto->comImagensRemotas($conhecidos);
    }

    /** @param array<string,mixed> $conteudo */
    private function extrairImageId(array $conteudo): ?string
    {
        if (isset($conteudo['image_info']['image_id'])) {
            return (string) $conteudo['image_info']['image_id'];
        }

        $lista = $conteudo['image_info_list'] ?? null;

        if (is_array($lista) && isset($lista[0]['image_info']['image_id'])) {
            return (string) $lista[0]['image_info']['image_id'];
        }

        return null;
    }

    /** @return array<int,string> */
    private function criarVariacoes(
        Produto $produto,
        ContextoPublicacao $contexto,
        int $anuncioId,
        string $itemId
    ): array {
        $payload = Mapeador::montarVariacoes($produto, $contexto);
        $payload['item_id'] = (int) $itemId;

        $conteudo = $this->cliente->extrair(
            $this->cliente->post('/api/v2/product/init_tier_variation', $payload),
            'variacoes'
        );

        $modelosRemotos = is_array($conteudo['model'] ?? null) ? $conteudo['model'] : [];

        // A resposta vem na mesma ordem do envio, então casamos por posição.
        $ativas = array_values(array_filter(
            $produto->variacoes,
            static fn ($v): bool => $v->ativo
        ));

        $mapa = [];

        foreach ($modelosRemotos as $indice => $modelo) {
            if (!is_array($modelo) || !isset($ativas[$indice])) {
                continue;
            }

            $modelId = (string) ($modelo['model_id'] ?? '');

            if ($modelId === '') {
                continue;
            }

            $variacaoId = $ativas[$indice]->id;
            Anuncios::registrarVariacao($anuncioId, $variacaoId, $modelId);
            $mapa[$variacaoId] = $modelId;
        }

        return $mapa;
    }

    private function urlDoAnuncio(string $itemId): string
    {
        return "https://shopee.com.br/product/0/{$itemId}";
    }
}
