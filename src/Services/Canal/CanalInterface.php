<?php

declare(strict_types=1);

namespace App\Services\Canal;

use App\Dominio\Canal;
use App\Dominio\ContextoPublicacao;
use App\Dominio\Produto;

/**
 * O contrato que o resto do sistema conhece.
 *
 * Controllers, fila e telas falam apenas com esta interface, de modo que
 * acrescentar um terceiro marketplace no futuro não toque no núcleo.
 */
interface CanalInterface
{
    public function canal(): Canal;

    /** A conta está conectada e com token utilizável? */
    public function conectado(): bool;

    /**
     * Busca categorias por termo, já gravando no cache local.
     *
     * @return array<int,array{id:string,nome:string,caminho:string}>
     */
    public function buscarCategorias(string $termo): array;

    /**
     * Atributos que a plataforma exige para a categoria.
     *
     * @return array<int,array{id:string,nome:string,obrigatorio:bool,tipo:string,valores:array}>
     */
    public function atributosDaCategoria(string $categoriaId): array;

    /**
     * Cria o anúncio. Precisa ser idempotente por etapa: um job
     * interrompido no meio e retomado não pode gerar um segundo anúncio.
     */
    public function publicar(Produto $produto, ContextoPublicacao $contexto, int $anuncioId): ResultadoPublicacao;

    /** Atualiza um anúncio já existente. */
    public function atualizar(
        Produto $produto,
        ContextoPublicacao $contexto,
        int $anuncioId,
        string $idRemoto
    ): ResultadoPublicacao;
}
