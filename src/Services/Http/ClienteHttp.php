<?php

declare(strict_types=1);

namespace App\Services\Http;

/**
 * Contrato do cliente HTTP.
 *
 * Existir como interface é o que permite testar os publicadores sem
 * rede: nos testes entra ClienteFalso, com respostas gravadas.
 */
interface ClienteHttp
{
    /**
     * @param array<string,string> $cabecalhos
     * @param string|array|null    $corpo string envia como está;
     *                                    array com 'multipart' faz upload de arquivo
     */
    public function requisitar(
        string $metodo,
        string $url,
        array $cabecalhos = [],
        string|array|null $corpo = null
    ): RespostaHttp;
}
