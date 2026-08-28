<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Roteador de tabela simples. Padrões aceitam segmentos nomeados
 * no formato {nome}, que chegam ao controller como argumentos.
 */
final class Router
{
    /** @var array<int,array{metodo:string,padrao:string,acao:array{0:class-string,1:string}}> */
    private array $rotas = [];

    public function get(string $padrao, array $acao): void
    {
        $this->adicionar('GET', $padrao, $acao);
    }

    public function post(string $padrao, array $acao): void
    {
        $this->adicionar('POST', $padrao, $acao);
    }

    private function adicionar(string $metodo, string $padrao, array $acao): void
    {
        $this->rotas[] = ['metodo' => $metodo, 'padrao' => $padrao, 'acao' => $acao];
    }

    /**
     * @return array{acao:array{0:class-string,1:string},params:array<string,string>}|null
     */
    public function resolver(string $metodo, string $caminho): ?array
    {
        foreach ($this->rotas as $rota) {
            if ($rota['metodo'] !== $metodo) {
                continue;
            }

            $regex = '#^' . preg_replace(
                '#\\\\\{([a-zA-Z_]+)\\\\\}#',
                '(?P<$1>[^/]+)',
                preg_quote($rota['padrao'], '#')
            ) . '$#';

            if (preg_match($regex, $caminho, $encontrados) === 1) {
                $params = array_filter(
                    $encontrados,
                    static fn ($chave) => is_string($chave),
                    ARRAY_FILTER_USE_KEY
                );

                return ['acao' => $rota['acao'], 'params' => $params];
            }
        }

        return null;
    }
}
