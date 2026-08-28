<?php

declare(strict_types=1);

namespace App\Services\Http;

use RuntimeException;

/**
 * Cliente para testes: devolve respostas previamente registradas e guarda
 * o que foi chamado, permitindo verificar publicadores sem tocar a rede.
 */
final class ClienteFalso implements ClienteHttp
{
    /** @var array<int,array{padrao:string,resposta:RespostaHttp,usos:int,maximo:int}> */
    private array $respostas = [];

    /** @var array<int,array{metodo:string,url:string,corpo:string|array|null}> */
    public array $chamadas = [];

    /**
     * Registra uma resposta para URLs que contenham $padrao.
     *
     * @param int $vezes quantas chamadas essa resposta atende (0 = ilimitado)
     */
    public function responder(
        string $padrao,
        int $status,
        array|string $corpo,
        int $vezes = 0
    ): self {
        $this->respostas[] = [
            'padrao'   => $padrao,
            'resposta' => new RespostaHttp(
                $status,
                is_array($corpo) ? (string) json_encode($corpo, JSON_UNESCAPED_UNICODE) : $corpo,
                1
            ),
            'usos'   => 0,
            'maximo' => $vezes,
        ];

        return $this;
    }

    public function responderFalhaDeRede(string $padrao, string $erro = 'timeout'): self
    {
        $this->respostas[] = [
            'padrao'   => $padrao,
            'resposta' => new RespostaHttp(0, '', 1, $erro),
            'usos'     => 0,
            'maximo'   => 0,
        ];

        return $this;
    }

    public function requisitar(
        string $metodo,
        string $url,
        array $cabecalhos = [],
        string|array|null $corpo = null
    ): RespostaHttp {
        $this->chamadas[] = ['metodo' => $metodo, 'url' => $url, 'corpo' => $corpo];

        foreach ($this->respostas as $indice => $registro) {
            if (!str_contains($url, $registro['padrao'])) {
                continue;
            }

            if ($registro['maximo'] > 0 && $registro['usos'] >= $registro['maximo']) {
                continue;
            }

            $this->respostas[$indice]['usos']++;

            return $registro['resposta'];
        }

        throw new RuntimeException("ClienteFalso não tem resposta registrada para: {$metodo} {$url}");
    }

    public function contarChamadas(string $padrao): int
    {
        return count(array_filter(
            $this->chamadas,
            static fn (array $c): bool => str_contains($c['url'], $padrao)
        ));
    }

    /** @return array<string,mixed>|null corpo JSON da n-ésima chamada que casa com o padrão */
    public function corpoDaChamada(string $padrao, int $ocorrencia = 0): ?array
    {
        $encontradas = array_values(array_filter(
            $this->chamadas,
            static fn (array $c): bool => str_contains($c['url'], $padrao)
        ));

        $chamada = $encontradas[$ocorrencia] ?? null;

        if ($chamada === null) {
            return null;
        }

        $corpo = $chamada['corpo'];

        if (is_array($corpo)) {
            return $corpo;
        }

        if (is_string($corpo)) {
            $decodificado = json_decode($corpo, true);

            return is_array($decodificado) ? $decodificado : null;
        }

        return null;
    }
}
