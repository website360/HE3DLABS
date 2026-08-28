<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Db;
use App\Dominio\Canal;
use App\Models\Anuncios;
use App\Models\Contas;
use App\Models\Fila;
use App\Models\Imagens;
use App\Models\Modelos;
use App\Models\Produtos;
use App\Services\Fila\Worker;
use App\Services\Http\ClienteFalso;
use Testes\Afirma;

// Credenciais de fachada: o ClienteFalso nunca sai para a rede, mas o
// cliente da Shopee exige que elas existam para montar a assinatura.
Config::definir('SHOPEE_PARTNER_ID', '1000');
Config::definir('SHOPEE_PARTNER_KEY', 'chave-de-teste');
Config::definir('SHOPEE_REDIRECT_URI', 'https://exemplo.com.br/callback');
Config::definir('APP_URL', 'https://exemplo.com.br');

/** Zera o banco de teste e devolve um produto pronto para publicar na Shopee. */
$preparar = static function (): array {
    foreach (['fila_publicacao', 'anuncio_variacoes', 'anuncios', 'imagens_canal', 'imagens',
              'variacao_valores', 'variacoes', 'eixos_variacao', 'produtos', 'modelo_canal',
              'modelos_produto', 'contas_canal', 'log_api'] as $tabela) {
        Db::executar("DELETE FROM {$tabela}");
    }

    Contas::salvarTokens(Canal::Shopee, 'token-abc', 'refresh-abc', 14400, '55555');

    $modeloId = Modelos::criar('Suporte de mesa');
    Modelos::salvarConfiguracao(
        $modeloId,
        Canal::Shopee,
        '100639',
        'Organizadores',
        ['logisticas' => [90003]]
    );

    $produtoId = Produtos::criar([
        'sku_base'  => 'HE3D-SUP',
        'titulo'    => 'Suporte de fone',
        'descricao' => 'Impresso em PLA.',
        'marca'     => 'HE 3D Labs',
        'modelo_id' => $modeloId,
        'status'    => 'pronto',
    ]);

    Produtos::definirEixos($produtoId, ['Cor']);
    $eixoId = (int) Produtos::eixos($produtoId)[0]['id'];

    foreach ([['PRETO', 'Preto'], ['BRANCO', 'Branco']] as [$sufixo, $cor]) {
        $variacaoId = Produtos::criarVariacao($produtoId, [
            'sku'            => "HE3D-SUP-{$sufixo}",
            'preco'          => 79.90,
            'estoque'        => 10,
            'peso_g'         => 210,
            'comprimento_cm' => 24,
            'largura_cm'     => 12,
            'altura_cm'      => 10,
        ]);
        Produtos::definirValorDeVariacao($variacaoId, $eixoId, $cor);
    }

    // O upload real exige um arquivo em disco.
    $arquivo = 'teste-' . bin2hex(random_bytes(4)) . '.jpg';
    $imagem = imagecreatetruecolor(60, 60);
    imagejpeg($imagem, BASE_PATH . '/public/uploads/' . $arquivo, 70);
    imagedestroy($imagem);

    Imagens::criar($produtoId, $arquivo);

    $anuncio = Anuncios::garantir($produtoId, Canal::Shopee);
    Fila::enfileirar((int) $anuncio['id']);

    return ['produtoId' => $produtoId, 'anuncioId' => (int) $anuncio['id'], 'arquivo' => $arquivo];
};

/** Cliente falso com o caminho feliz completo da Shopee. */
$clienteFeliz = static function (): ClienteFalso {
    return (new ClienteFalso())
        ->responder('/api/v2/media_space/upload_image', 200, [
            'error'    => '',
            'response' => ['image_info' => ['image_id' => 'img-remota-1']],
        ])
        ->responder('/api/v2/product/add_item', 200, [
            'error'    => '',
            'response' => ['item_id' => 998877],
        ])
        ->responder('/api/v2/product/init_tier_variation', 200, [
            'error'    => '',
            'response' => ['model' => [['model_id' => 111], ['model_id' => 222]]],
        ])
        ->responder('/api/v2/product/update_item', 200, [
            'error'    => '',
            'response' => ['item_id' => 998877],
        ]);
};

return [
    'publicação completa cria o anúncio e vincula as variações' => static function () use ($preparar, $clienteFeliz): void {
        $dados = $preparar();
        $http = $clienteFeliz();

        $resumo = (new Worker($http))->processarLote();

        Afirma::igual(1, $resumo['ok'], 'o job deveria concluir com sucesso: ' . implode(' | ', $resumo['mensagens']));

        $anuncio = Anuncios::porId($dados['anuncioId']);
        Afirma::igual('998877', $anuncio['id_remoto'], 'o item_id da Shopee deve ficar gravado');
        Afirma::igual('publicado', $anuncio['status'], 'o anúncio deve ficar publicado');

        Afirma::igual(
            2,
            count(Anuncios::variacoesRemotas($dados['anuncioId'])),
            'as duas variações devem receber model_id'
        );
    },

    'republicar não cria um segundo anúncio' => static function () use ($preparar, $clienteFeliz): void {
        $dados = $preparar();

        (new Worker($clienteFeliz()))->processarLote();

        // Segunda rodada: o usuário clicou em republicar.
        Fila::enfileirar($dados['anuncioId'], 'atualizar');

        $segundoHttp = $clienteFeliz();
        (new Worker($segundoHttp))->processarLote();

        Afirma::igual(
            0,
            $segundoHttp->contarChamadas('/api/v2/product/add_item'),
            'com item_id já gravado, a segunda passada deve atualizar, nunca criar de novo'
        );
        Afirma::igual(
            1,
            $segundoHttp->contarChamadas('/api/v2/product/update_item'),
            'a segunda passada deve chamar update_item uma vez'
        );
    },

    'job interrompido depois de criar o item retoma sem duplicar' => static function () use ($preparar, $clienteFeliz): void {
        $dados = $preparar();

        // Primeira tentativa: o item é criado, mas a matriz de variações falha.
        $primeiro = (new ClienteFalso())
            ->responder('/api/v2/media_space/upload_image', 200, [
                'error' => '', 'response' => ['image_info' => ['image_id' => 'img-remota-1']],
            ])
            ->responder('/api/v2/product/add_item', 200, ['error' => '', 'response' => ['item_id' => 998877]])
            ->responder('/api/v2/product/init_tier_variation', 500, ['error' => 'error_server', 'message' => 'instável']);

        (new Worker($primeiro))->processarLote();

        $anuncio = Anuncios::porId($dados['anuncioId']);
        Afirma::igual('998877', $anuncio['id_remoto'], 'o item_id precisa ser gravado antes da etapa seguinte');
        Afirma::igual(
            0,
            count(Anuncios::variacoesRemotas($dados['anuncioId'])),
            'a etapa de variações falhou, então nada deve estar vinculado ainda'
        );

        // O retry volta a ficar disponível: antecipamos o agendamento.
        Db::executar("UPDATE fila_publicacao SET status = 'pendente', proxima_tentativa_em = NOW()");

        $segundo = $clienteFeliz();
        (new Worker($segundo))->processarLote();

        Afirma::igual(
            0,
            $segundo->contarChamadas('/api/v2/product/add_item'),
            'a retomada NÃO pode criar um segundo anúncio na Shopee'
        );
        Afirma::igual(
            2,
            count(Anuncios::variacoesRemotas($dados['anuncioId'])),
            'a retomada deve concluir a etapa que faltava'
        );
    },

    'imagem já enviada não sobe de novo' => static function () use ($preparar, $clienteFeliz): void {
        $dados = $preparar();

        (new Worker($clienteFeliz()))->processarLote();

        Fila::enfileirar($dados['anuncioId'], 'atualizar');
        $segundoHttp = $clienteFeliz();
        (new Worker($segundoHttp))->processarLote();

        Afirma::igual(
            0,
            $segundoHttp->contarChamadas('/api/v2/media_space/upload_image'),
            'o image_id fica em cache; reenviar a mesma foto é desperdício de chamada'
        );
    },

    'erro de validação não fica retentando' => static function () use ($preparar): void {
        $dados = $preparar();

        $http = (new ClienteFalso())
            ->responder('/api/v2/media_space/upload_image', 200, [
                'error' => '', 'response' => ['image_info' => ['image_id' => 'img-remota-1']],
            ])
            ->responder('/api/v2/product/add_item', 200, [
                'error'   => 'error_param',
                'message' => 'category_id inválido',
            ]);

        (new Worker($http))->processarLote();

        $job = Db::um('SELECT * FROM fila_publicacao WHERE anuncio_id = ?', [$dados['anuncioId']]);

        Afirma::igual('erro', $job['status'], 'erro de validação deve falhar de uma vez');
        Afirma::contem('category_id inválido', (string) $job['erro'], 'a mensagem da plataforma deve ser preservada');

        $anuncio = Anuncios::porId($dados['anuncioId']);
        Afirma::igual('erro', $anuncio['status'], 'o anúncio deve aparecer com erro na tela');
    },

    'falha transitória volta para a fila com espera crescente' => static function () use ($preparar): void {
        $dados = $preparar();

        $http = (new ClienteFalso())
            ->responder('/api/v2/media_space/upload_image', 200, [
                'error' => '', 'response' => ['image_info' => ['image_id' => 'img-remota-1']],
            ])
            ->responder('/api/v2/product/add_item', 503, ['error' => 'error_server', 'message' => 'indisponível']);

        (new Worker($http))->processarLote();

        $job = Db::um('SELECT * FROM fila_publicacao WHERE anuncio_id = ?', [$dados['anuncioId']]);

        Afirma::igual('pendente', $job['status'], 'erro 5xx deve voltar para a fila');
        Afirma::igual(1, (int) $job['tentativas'], 'a tentativa deve ser contabilizada');
        Afirma::verdadeiro(
            strtotime((string) $job['proxima_tentativa_em']) > time() + 30,
            'a próxima tentativa deve ser agendada para o futuro'
        );
    },

    'espera cresce de 1 para 5 e 25 minutos' => static function (): void {
        Afirma::igual(60, Fila::esperaSegundos(1), 'primeira retentativa em 1 minuto');
        Afirma::igual(300, Fila::esperaSegundos(2), 'segunda em 5 minutos');
        Afirma::igual(1500, Fila::esperaSegundos(3), 'terceira em 25 minutos');
    },

    'clicar publicar duas vezes não gera dois jobs' => static function () use ($preparar): void {
        $dados = $preparar();

        Fila::enfileirar($dados['anuncioId']);
        Fila::enfileirar($dados['anuncioId']);

        $total = (int) Db::valor(
            "SELECT COUNT(*) FROM fila_publicacao WHERE anuncio_id = ? AND status IN ('pendente','processando')",
            [$dados['anuncioId']]
        );

        Afirma::igual(1, $total, 'cliques repetidos devem reaproveitar o job pendente');
    },
];
