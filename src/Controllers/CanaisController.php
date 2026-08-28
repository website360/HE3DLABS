<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Controller;
use App\Core\Db;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Sessao;
use App\Dominio\Canal;
use App\Models\Contas;
use App\Services\Canal\MercadoLivre\Oauth as OauthML;
use App\Services\Canal\Shopee\Oauth as OauthShopee;
use App\Services\Http\ClienteComLog;
use App\Services\Http\ClienteCurl;
use Throwable;

final class CanaisController extends Controller
{
    public function index(): string
    {
        $canais = [];

        foreach (Canal::todos() as $canal) {
            $conta = Contas::buscar($canal);

            $canais[] = [
                'canal'      => $canal,
                'conta'      => $conta,
                'conectado'  => Contas::conectada($canal),
                'credenciais' => $this->credenciaisDoEnv($canal),
                'redirect'   => $this->redirectUri($canal),
            ];
        }

        return $this->view('canais/index', [
            'canais'  => $canais,
            'appUrl'  => Config::get('APP_URL', ''),
        ], 'Canais');
    }

    public function salvarCredenciais(string $canal): string
    {
        $canalEnum = $this->canal($canal);

        Contas::salvarMarkup($canalEnum, (float) (Request::postDecimal('markup', 0.0) ?? 0.0));
        Flash::sucesso("Configuração {$canalEnum->com('de')} salva.");

        return $this->redirecionar('/canais');
    }

    public function salvarLogistica(string $canal): string
    {
        $canalEnum = $this->canal($canal);

        $ids = array_values(array_filter(array_map(
            static fn ($v): int => (int) $v,
            preg_split('/[\s,]+/', (string) Request::post('logisticas', '')) ?: []
        )));

        $conta = Contas::garantir($canalEnum);
        $extra = $conta['extra'];
        $extra['logisticas'] = $ids;

        Db::executar(
            'UPDATE contas_canal SET extra_json = ? WHERE canal = ?',
            [json_encode($extra, JSON_UNESCAPED_UNICODE), $canalEnum->value]
        );

        Flash::sucesso(
            $ids === []
                ? 'Nenhum canal de logística definido. A Shopee vai recusar a criação de anúncios.'
                : count($ids) . ' canal(is) de logística salvo(s).'
        );

        return $this->redirecionar('/canais');
    }

    /** Envia o usuário para a tela de autorização da plataforma. */
    public function conectar(string $canal): string
    {
        $canalEnum = $this->canal($canal);

        try {
            $url = match ($canalEnum) {
                Canal::MercadoLivre => OauthML::urlAutorizacao(),
                Canal::Shopee       => OauthShopee::urlAutorizacao(),
            };
        } catch (Throwable $e) {
            Flash::erro('Faltam credenciais no .env: ' . $e->getMessage());

            return $this->redirecionar('/canais');
        }

        return $this->redirecionar($url);
    }

    /**
     * Retorno da plataforma após o usuário autorizar.
     *
     * É rota pública porque quem chama é a plataforma, redirecionando o
     * navegador — não há sessão garantida no momento da volta.
     */
    public function callback(string $canal): string
    {
        $canalEnum = $this->canal($canal);
        $http = new ClienteComLog(new ClienteCurl(), $canalEnum);

        try {
            if ($canalEnum === Canal::MercadoLivre) {
                $this->callbackMercadoLivre($http);
            } else {
                $this->callbackShopee($http);
            }

            Flash::sucesso("{$canalEnum->rotulo()} conectado.");
        } catch (Throwable $e) {
            Flash::erro("Falha ao conectar {$canalEnum->rotulo()}: " . $e->getMessage());
        }

        return $this->redirecionar('/canais');
    }

    private function callbackMercadoLivre(ClienteComLog $http): void
    {
        Sessao::iniciar();

        $codigo = Request::query('code');
        $estado = Request::query('state');

        if ($codigo === null) {
            throw new \RuntimeException(
                'A plataforma não devolveu o código de autorização. ' . (string) Request::query('error', '')
            );
        }

        $estadoEsperado = $_SESSION['ml_state'] ?? null;

        if (!is_string($estadoEsperado) || !is_string($estado) || !hash_equals($estadoEsperado, $estado)) {
            throw new \RuntimeException('Parâmetro state não confere. Refaça a conexão.');
        }

        $verificador = $_SESSION['ml_code_verifier'] ?? null;

        if (!is_string($verificador)) {
            throw new \RuntimeException('Sessão expirou durante a autorização. Refaça a conexão.');
        }

        (new OauthML($http))->trocarCodigo($codigo, $verificador);

        unset($_SESSION['ml_state'], $_SESSION['ml_code_verifier']);
    }

    private function callbackShopee(ClienteComLog $http): void
    {
        $codigo = Request::query('code');
        $shopId = Request::query('shop_id');

        if ($codigo === null || $shopId === null) {
            throw new \RuntimeException('A Shopee não devolveu code e shop_id.');
        }

        (new OauthShopee($http))->trocarCodigo($codigo, $shopId);
    }

    public function desconectar(string $canal): string
    {
        $canalEnum = $this->canal($canal);
        Contas::desconectar($canalEnum);

        Flash::sucesso("{$canalEnum->rotulo()} desconectado. Os anúncios já publicados continuam no ar.");

        return $this->redirecionar('/canais');
    }

    private function canal(string $valor): Canal
    {
        $canal = Canal::tryFrom($valor);

        if ($canal === null) {
            $this->naoEncontrado('Canal desconhecido.');
        }

        return $canal;
    }

    /** @return array{id:?string,segredo:bool} */
    private function credenciaisDoEnv(Canal $canal): array
    {
        return match ($canal) {
            Canal::MercadoLivre => [
                'id'      => Config::get('ML_CLIENT_ID'),
                'segredo' => Config::get('ML_CLIENT_SECRET') !== null,
            ],
            Canal::Shopee => [
                'id'      => Config::get('SHOPEE_PARTNER_ID'),
                'segredo' => Config::get('SHOPEE_PARTNER_KEY') !== null,
            ],
        };
    }

    private function redirectUri(Canal $canal): ?string
    {
        return match ($canal) {
            Canal::MercadoLivre => Config::get('ML_REDIRECT_URI'),
            Canal::Shopee       => Config::get('SHOPEE_REDIRECT_URI'),
        };
    }
}
