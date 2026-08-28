<?php

declare(strict_types=1);

namespace App\Services\Canal;

use App\Dominio\Canal;
use App\Dominio\Produto;
use App\Models\Categorias;
use App\Models\Contas;
use App\Models\Modelos;
use App\Models\Produtos;

/**
 * Pré-voo: verifica o que dá para verificar sem chamar a API.
 *
 * Rodar isto antes de enfileirar transforma um erro que só apareceria
 * minutos depois, vindo da plataforma em inglês, num aviso imediato na
 * tela — e economiza chamada contra o rate limit.
 */
final class Validador
{
    /** @return array<int,string> lista de problemas; vazia significa pronto para publicar */
    public static function verificar(Produto $produto, Canal $canal): array
    {
        $problemas = [];

        if (!Contas::conectada($canal)) {
            $problemas[] = "A conta {$canal->com('de')} não está conectada.";
        }

        $linha = Produtos::buscar($produto->id);
        $modeloId = $linha['modelo_id'] ?? null;

        $configuracao = $modeloId !== null
            ? Modelos::configuracao((int) $modeloId, $canal)
            : null;

        if ($configuracao === null) {
            $problemas[] = "Sem categoria configurada para {$canal->rotulo()}. "
                . 'Associe o produto a um modelo que tenha esse canal configurado.';
        }

        $problemas = array_merge($problemas, self::verificarConteudo($produto, $canal));
        $problemas = array_merge($problemas, self::verificarVariacoes($produto, $canal));

        if ($produto->todasImagens() === []) {
            $problemas[] = 'O produto não tem nenhuma imagem.';
        }

        if ($configuracao !== null) {
            $problemas = array_merge(
                $problemas,
                self::verificarAtributos($canal, (string) $configuracao['categoria_id_remota'], $configuracao)
            );
        }

        if ($canal === Canal::Shopee) {
            $problemas = array_merge($problemas, self::verificarShopee($configuracao));
        }

        return $problemas;
    }

    /** @return array<int,string> */
    private static function verificarConteudo(Produto $produto, Canal $canal): array
    {
        $problemas = [];

        if (trim($produto->titulo) === '') {
            $problemas[] = 'O produto está sem título.';
        }

        if (trim($produto->descricao) === '') {
            $problemas[] = 'O produto está sem descrição.';
        }

        // Não é erro: o título é cortado automaticamente. Mas cortar sem
        // avisar produz anúncios com nome truncado sem que ninguém perceba.
        if (mb_strlen($produto->titulo) > $canal->limiteTitulo()) {
            $problemas[] = sprintf(
                'O título tem %d caracteres e o limite %s é %d. '
                . 'Defina um título específico para este canal, senão ele será cortado.',
                mb_strlen($produto->titulo),
                $canal->com('de'),
                $canal->limiteTitulo()
            );
        }

        return $problemas;
    }

    /** @return array<int,string> */
    private static function verificarVariacoes(Produto $produto, Canal $canal): array
    {
        $problemas = [];
        $ativas = array_filter($produto->variacoes, static fn ($v): bool => $v->ativo);

        if ($ativas === []) {
            $problemas[] = 'O produto não tem nenhuma variação ativa.';

            return $problemas;
        }

        if (count($produto->eixos) > 2) {
            $problemas[] = 'A Shopee aceita no máximo 2 eixos de variação, e este produto tem '
                . count($produto->eixos) . '.';
        }

        foreach ($ativas as $variacao) {
            if ($variacao->pesoG <= 0) {
                $problemas[] = "A variação {$variacao->sku} está sem peso. "
                    . 'As duas plataformas usam o peso para cotar frete.';
            }

            if ($variacao->comprimentoCm <= 0 || $variacao->larguraCm <= 0 || $variacao->alturaCm <= 0) {
                $problemas[] = "A variação {$variacao->sku} está sem dimensões da embalagem.";
            }

            if ($variacao->preco <= 0) {
                $problemas[] = "A variação {$variacao->sku} está com preço zerado.";
            }

            foreach ($produto->eixos as $eixo) {
                $valor = $variacao->valorDoEixo($eixo->nome);

                if ($valor === null || $valor === '') {
                    $problemas[] = "A variação {$variacao->sku} não tem valor para o eixo '{$eixo->nome}'.";
                }
            }
        }

        // Só o Mercado Livre precisa traduzir o nome do eixo para um id
        // de atributo; a Shopee aceita o nome livre.
        if ($canal === Canal::MercadoLivre && $produto->temVariacoes()) {
            foreach ($produto->eixos as $eixo) {
                try {
                    MercadoLivre\Mapeador::idDoEixo($eixo->nome);
                } catch (ErroPublicacao $e) {
                    $problemas[] = $e->getMessage();
                }
            }
        }

        return $problemas;
    }

    /**
     * @param array<string,mixed> $configuracao
     * @return array<int,string>
     */
    private static function verificarAtributos(Canal $canal, string $categoriaId, array $configuracao): array
    {
        $obrigatorios = Categorias::atributosObrigatorios($canal, $categoriaId);

        if ($obrigatorios === []) {
            return []; // cache ainda não carregado: a API dirá na publicação
        }

        $preenchidos = is_array($configuracao['atributos'] ?? null) ? $configuracao['atributos'] : [];
        $problemas = [];

        foreach ($obrigatorios as $atributo) {
            $id = (string) $atributo['id'];

            if (($preenchidos[$id] ?? '') === '') {
                $problemas[] = sprintf(
                    "O atributo obrigatório '%s' não está preenchido para %s.",
                    $atributo['nome'] ?? $id,
                    $canal->rotulo()
                );
            }
        }

        return $problemas;
    }

    /**
     * @param array<string,mixed>|null $configuracao
     * @return array<int,string>
     */
    private static function verificarShopee(?array $configuracao): array
    {
        $conta = Contas::buscar(Canal::Shopee);

        $logisticasNaConta = $conta['extra']['logisticas'] ?? [];
        $logisticasNoModelo = $configuracao['atributos']['logisticas'] ?? [];

        if ((is_array($logisticasNaConta) && $logisticasNaConta !== [])
            || (is_array($logisticasNoModelo) && $logisticasNoModelo !== [])
        ) {
            return [];
        }

        return [
            'Nenhum canal de logística habilitado para a Shopee. É obrigatório em logistic_info '
            . 'e é a causa mais comum de recusa na criação do anúncio.',
        ];
    }
}
