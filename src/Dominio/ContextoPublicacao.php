<?php

declare(strict_types=1);

namespace App\Dominio;

/**
 * Tudo que é específico de um canal na hora de montar o payload:
 * categoria escolhida, atributos preenchidos, título e descrição
 * efetivos, preços com markup aplicado e ids remotos das imagens.
 *
 * Montar isso fora do mapeador é o que mantém o mapeador puro.
 */
final class ContextoPublicacao
{
    /**
     * @param array<string,mixed> $atributos      id do atributo => valor
     * @param array<int,float>    $precos         id da variação => preço no canal
     * @param array<int,string>   $imagensRemotas id da imagem => id remoto (Shopee)
     * @param array<string,mixed> $extra          dados avulsos do canal (ex: logística Shopee)
     */
    public function __construct(
        public readonly Canal $canal,
        public readonly string $categoriaId,
        public readonly string $titulo,
        public readonly string $descricao,
        public readonly array $atributos = [],
        public readonly array $precos = [],
        public readonly array $imagensRemotas = [],
        public readonly string $baseUrl = '',
        public readonly array $extra = [],
    ) {
    }

    /** Preço da variação neste canal, caindo no preço base quando não há override. */
    public function precoDe(Variacao $variacao): float
    {
        return $this->precos[$variacao->id] ?? $variacao->preco;
    }

    public function imagemRemota(Imagem $imagem): ?string
    {
        return $this->imagensRemotas[$imagem->id] ?? null;
    }

    public function extra(string $chave, mixed $padrao = null): mixed
    {
        return $this->extra[$chave] ?? $padrao;
    }

    /**
     * Devolve uma cópia com os ids remotos das imagens preenchidos.
     * A Shopee só sabe esses ids depois de enviar cada foto, o que
     * acontece já dentro do publicador.
     *
     * @param array<int,string> $imagensRemotas
     */
    public function comImagensRemotas(array $imagensRemotas): self
    {
        return new self(
            $this->canal,
            $this->categoriaId,
            $this->titulo,
            $this->descricao,
            $this->atributos,
            $this->precos,
            $imagensRemotas,
            $this->baseUrl,
            $this->extra,
        );
    }

    /**
     * Título já cortado no limite do canal. O corte respeita a última
     * palavra inteira, para não terminar no meio de uma.
     */
    public function tituloLimitado(): string
    {
        $limite = $this->canal->limiteTitulo();

        if (mb_strlen($this->titulo) <= $limite) {
            return $this->titulo;
        }

        $cortado = mb_substr($this->titulo, 0, $limite);
        $ultimoEspaco = mb_strrpos($cortado, ' ');

        // Só volta até o espaço se isso não jogar fora mais de 20% do texto.
        if ($ultimoEspaco !== false && $ultimoEspaco > $limite * 0.8) {
            $cortado = mb_substr($cortado, 0, $ultimoEspaco);
        }

        return rtrim($cortado);
    }

    public function descricaoLimitada(): string
    {
        return mb_substr($this->descricao, 0, $this->canal->limiteDescricao());
    }
}
