<?php

// Imagens padrão do Fleetbase servidas pelo console, e não pelo bucket S3 dele (App\Support\Entregas\ImagensPadrao,
// middleware TrocarImagensDoFleetbase, saving() de motoboy/local/veículo e a migration 2026_10_25_130000).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/imagens-padrao.php

function config($chave = null, $padrao = null)
{
    return $chave === 'fleetbase.console.host' ? ($GLOBALS['console'] ?? $padrao) : $padrao;
}

require '/repo/api/app/Support/Entregas/ImagensPadrao.php';

use App\Support\Entregas\ImagensPadrao;

$falhas = 0;
function confere(bool $ok, string $descricao): void
{
    if (!$ok) {
        $GLOBALS['falhas']++;
    }
    echo ($ok ? 'PASSA ' : 'FALHA ') . $descricao . PHP_EOL;
}

$GLOBALS['console'] = 'https://entregas.restaurantepro.com.br';
$local              = 'https://entregas.restaurantepro.com.br/images/entregas/padrao/';

echo '== Os três jeitos de o Fleetbase escrever o bucket' . PHP_EOL;
confere(ImagensPadrao::trocar('https://flb-assets.s3-ap-southeast-1.amazonaws.com/static/driver-icons/moto-driver.png') === $local . 'static/driver-icons/moto-driver.png', 'virtual host com "-" (avatar do motoboy gravado no banco)');
confere(ImagensPadrao::trocar('https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/image-file-icon.png') === $local . 'static/image-file-icon.png', 'virtual host com "." (logo da empresa sem imagem)');
confere(ImagensPadrao::trocar('https://s3.ap-southeast-1.amazonaws.com/flb-assets/static/no-avatar.png') === $local . 'static/no-avatar.png', 'bucket no caminho (usuário sem foto)');

echo '== JSON com as barras escapadas' . PHP_EOL;
$json = json_encode(['avatar_url' => 'https://s3.ap-southeast-1.amazonaws.com/flb-assets/static/no-avatar.png', 'photo_url' => 'https://flb-assets.s3-ap-southeast-1.amazonaws.com/static/place-icons/basic-building.png']);
$novo = ImagensPadrao::trocar($json);
confere(!str_contains($novo, 'amazonaws'), 'nenhuma URL do bucket sobra (' . $novo . ')');
confere(json_decode($novo, true) === ['avatar_url' => $local . 'static/no-avatar.png', 'photo_url' => $local . 'static/place-icons/basic-building.png'], 'o JSON continua válido e com as URLs locais');

echo '== O que não é imagem padrão fica como está' . PHP_EOL;
$outro = 'https://flb-assets.s3-ap-southeast-1.amazonaws.com/integrated-vendors/lalamove.png';
confere(ImagensPadrao::trocar($outro) === $outro, 'caminho do bucket sem cópia local');
$foto = 'https://s3.sa-east-1.amazonaws.com/meu-bucket/uploads/foto.png';
confere(ImagensPadrao::trocar($foto) === $foto, 'foto enviada para outro bucket');
confere(ImagensPadrao::trocar('{"a":1}') === '{"a":1}', 'texto sem amazonaws não muda');

echo '== Console com barra no fim ou sem esquema' . PHP_EOL;
$GLOBALS['console'] = 'entregas.restaurantepro.com.br/';
confere(ImagensPadrao::console() === 'https://entregas.restaurantepro.com.br', 'console normalizado (' . ImagensPadrao::console() . ')');

echo '== saving(): só troca o atributo quando é imagem do bucket' . PHP_EOL;
$GLOBALS['console'] = 'https://entregas.restaurantepro.com.br';
class ModeloFalso
{
    public array $atributos;

    public function __construct(array $atributos)
    {
        $this->atributos = $atributos;
    }

    public function getAttributes(): array
    {
        return $this->atributos;
    }

    public function setAttribute($chave, $valor): void
    {
        $this->atributos[$chave] = $valor;
    }
}
$motoboy = new ModeloFalso(['avatar_url' => 'https://flb-assets.s3-ap-southeast-1.amazonaws.com/static/driver-icons/moto-driver.png']);
ImagensPadrao::corrigirAtributo($motoboy);
confere($motoboy->atributos['avatar_url'] === $local . 'static/driver-icons/moto-driver.png', 'avatar do motoboy vira o local');
$comFoto = new ModeloFalso(['avatar_url' => '6f1c7a1e-0000-4000-8000-000000000000']);
ImagensPadrao::corrigirAtributo($comFoto);
confere($comFoto->atributos['avatar_url'] === '6f1c7a1e-0000-4000-8000-000000000000', 'avatar personalizado (uuid do arquivo) fica');

echo '== Cada imagem da lista existe no console' . PHP_EOL;
foreach (ImagensPadrao::ARQUIVOS as $arquivo) {
    confere(is_file('/repo/console/public/images/entregas/padrao/' . $arquivo), $arquivo);
}

echo PHP_EOL . 'FALHAS: ' . $falhas . PHP_EOL;
