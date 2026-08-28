<?php

declare(strict_types=1);

// Cria o usuário inicial e, opcionalmente, dados de demonstração.
//
//   php bin/semear.php                     -> só o usuário
//   php bin/semear.php --demo              -> usuário + catálogo de exemplo
//   php bin/semear.php --email=a@b.c --senha=segredo

require __DIR__ . '/../bootstrap.php';

use App\Core\Db;
use App\Dominio\Canal;
use App\Models\Imagens;
use App\Models\Modelos;
use App\Models\Produtos;

$opcoes = getopt('', ['demo', 'email::', 'senha::', 'nome::']);

$email = $opcoes['email'] ?? null;
$senha = $opcoes['senha'] ?? null;
$nome  = $opcoes['nome'] ?? 'Equipe HE 3D Labs';

// Sem padrão embutido: uma senha fixa no código-fonte é uma credencial
// de administrador publicada junto com o repositório.
if (!is_string($email) || $email === '' || !is_string($senha) || $senha === '') {
    fwrite(STDERR, <<<TEXTO
    Informe e-mail e senha do usuário inicial:

      php bin/semear.php --email=voce@dominio.com.br --senha='uma senha forte'

    Acrescente --demo para incluir também um catálogo de exemplo.

    TEXTO);
    exit(1);
}

if (strlen($senha) < 8) {
    fwrite(STDERR, "A senha precisa ter ao menos 8 caracteres.\n");
    exit(1);
}

// ------------------------------------------------------------------
// Usuário
// ------------------------------------------------------------------
$existente = Db::um('SELECT id FROM usuarios WHERE email = ?', [$email]);

if ($existente === null) {
    Db::executar(
        'INSERT INTO usuarios (nome, email, senha_hash) VALUES (?, ?, ?)',
        [$nome, $email, password_hash($senha, PASSWORD_DEFAULT)]
    );
    echo "Usuário criado: {$email}\n";
} else {
    Db::executar(
        'UPDATE usuarios SET senha_hash = ?, nome = ?, ativo = 1 WHERE email = ?',
        [password_hash($senha, PASSWORD_DEFAULT), $nome, $email]
    );
    echo "Usuário atualizado: {$email}\n";
}

if (!isset($opcoes['demo'])) {
    echo "Pronto. Use --demo para incluir um catálogo de exemplo.\n";
    exit(0);
}

// ------------------------------------------------------------------
// Catálogo de demonstração
// ------------------------------------------------------------------
if ((int) Db::valor('SELECT COUNT(*) FROM produtos') > 0) {
    echo "Já existem produtos; a demonstração não foi recriada.\n";
    exit(0);
}

$modeloId = Modelos::criar('Suporte de mesa');

// Categorias fictícias: sem conta conectada não há como buscar as reais,
// mas isso deixa o fluxo completo navegável.
Modelos::salvarConfiguracao(
    $modeloId,
    Canal::MercadoLivre,
    'MLB264188',
    'Casa, Móveis e Decoração > Organização',
    ['BRAND' => 'HE 3D Labs', 'eixos' => ['Cor' => 'COLOR']]
);

Modelos::salvarConfiguracao(
    $modeloId,
    Canal::Shopee,
    '100639',
    'Casa e Decoração > Organizadores',
    ['logisticas' => [90003]]
);

echo "Modelo de produto criado.\n";

/** Gera uma imagem de exemplo, para as telas não ficarem vazias. */
$gerarImagem = static function (string $rotulo, string $hex): string {
    $largura = 900;
    $altura = 900;
    $imagem = imagecreatetruecolor($largura, $altura);

    [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x') ?: [200, 200, 200];
    imagefilledrectangle($imagem, 0, 0, $largura, $altura, imagecolorallocate($imagem, $r, $g, $b));

    // Linhas horizontais imitando as camadas de uma peça impressa em FDM.
    $linha = imagecolorallocatealpha($imagem, 0, 0, 0, 108);
    for ($y = 0; $y < $altura; $y += 6) {
        imagefilledrectangle($imagem, 0, $y, $largura, $y + 1, $linha);
    }

    $texto = imagecolorallocate($imagem, 255, 255, 255);
    imagestring($imagem, 5, 40, $altura - 60, $rotulo, $texto);

    $arquivo = 'demo-' . bin2hex(random_bytes(5)) . '.jpg';
    imagejpeg($imagem, BASE_PATH . '/public/uploads/' . $arquivo, 88);
    imagedestroy($imagem);

    return $arquivo;
};

// Produto com variações de cor.
$produtoId = Produtos::criar([
    'sku_base'  => 'HE3D-SUPFONE',
    'titulo'    => 'Suporte de Fone de Ouvido para Mesa em PLA - Headset Gamer',
    'descricao' => "Suporte de mesa para headset, impresso em PLA com 3 paredes e 30% de preenchimento.\n\n"
        . "Base emborrachada, não risca a mesa.\nAltura útil de 24 cm, comporta headsets grandes.\n\n"
        . 'Peça impressa sob demanda pela HE 3D Labs.',
    'marca'     => 'HE 3D Labs',
    'modelo_id' => $modeloId,
    'status'    => 'pronto',
]);

Produtos::definirEixos($produtoId, ['Cor']);
$eixoCor = (int) Produtos::eixos($produtoId)[0]['id'];

$cores = [
    ['Preto',  '#22262b', 'PRETO'],
    ['Branco', '#c8ccd2', 'BRANCO'],
    ['Laranja', '#f25c05', 'LARANJA'],
];

foreach ($cores as [$cor, $hex, $sufixo]) {
    $variacaoId = Produtos::criarVariacao($produtoId, [
        'sku'            => "HE3D-SUPFONE-{$sufixo}",
        'preco'          => 79.90,
        'estoque'        => 12,
        'peso_g'         => 210,
        'comprimento_cm' => 24,
        'largura_cm'     => 12,
        'altura_cm'      => 10,
    ]);

    Produtos::definirValorDeVariacao($variacaoId, $eixoCor, $cor);
    Imagens::criar($produtoId, $gerarImagem("SUPORTE {$sufixo}", $hex), $variacaoId);
}

Imagens::criar($produtoId, $gerarImagem('SUPORTE DE FONE', '#3a4048'));

// Produto simples: uma variação só, sem eixos.
$simplesId = Produtos::criar([
    'sku_base'  => 'HE3D-ORGCABO',
    'titulo'    => 'Organizador de Cabos de Mesa em PETG - Kit com 4',
    'descricao' => "Kit com 4 clipes organizadores de cabo, impressos em PETG.\n\n"
        . 'Fita dupla face inclusa. Comporta cabos de até 6 mm.',
    'marca'     => 'HE 3D Labs',
    'modelo_id' => $modeloId,
    'status'    => 'pronto',
]);

Produtos::criarVariacao($simplesId, [
    'sku'            => 'HE3D-ORGCABO',
    'preco'          => 24.90,
    'estoque'        => 40,
    'peso_g'         => 45,
    'comprimento_cm' => 12,
    'largura_cm'     => 8,
    'altura_cm'      => 3,
]);

Imagens::criar($simplesId, $gerarImagem('ORGANIZADOR DE CABOS', '#0b7285'));

echo "Catálogo de demonstração criado: 2 produtos, 4 variações, 5 imagens.\n";
echo "\nEntre com {$email} e a senha que você informou.\n";
