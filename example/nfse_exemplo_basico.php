<?php
/**
 * Exemplo basico de emissao (valores ficticios).
 * Nao altera dados do sistema, apenas monta e envia um RPS.
 */

require_once __DIR__ . '/../../../../www/config.php';
require_once __DIR__ . '/../../../../www/Autoload.php';
require_once __DIR__ . '/../../../../classes/lib/PDOConfig.php';
require_once __DIR__ . '/../NFSeSP.class.php';
require_once __DIR__ . '/../NFeRPS.class.php';

$empresa = array(
    'cnpj' => '28702214000129',
    'ccm' => '58070419',
    'senha_certificado' => 'RAASA25',
);

$nfse = new NFSeSP($empresa);
$rps = new NFeRPS();

$rps->CCM = '58070419';
$rps->serie = 'A';
$rps->numero = '99999';
$rps->dataEmissao = date('Y-m-d');
$rps->valorServicos = '26000.00';
$rps->valorInicialCobrado = $rps->valorServicos;
$rps->valorDeducoes = 0;
$rps->ValorINSS = 0;
$rps->ValorIR = '390.00';
$rps->ValorPIS = '169.00';
$rps->ValorCOFINS = '780.00';
$rps->ValorCSLL = '260.00';
$rps->aliquotaServicos = 0.00;
$rps->codigoServico = '03380';
$rps->tributacao = 'T';
$rps->discriminacao = 'SERVICO FICTICIO PARA DEMO';
$rps->nbs = '113012000';
$rps->ibsCIndOp = '100301';
$rps->ibsCClassTrib = '200052';
$rps->cLocPrestacao = '3550308';

$rps->contractorRPS = new ContractorRPS();
$rps->contractorRPS->cnpjTomador = '06322120000191';
$rps->contractorRPS->ccmTomador = '';
$rps->contractorRPS->type = 'C';
$rps->contractorRPS->name = 'CLIENTE FICTICIO LTDA';
$rps->contractorRPS->tipoEndereco = 'R';
$rps->contractorRPS->endereco = 'RUA TESTE';
$rps->contractorRPS->enderecoNumero = '100';
$rps->contractorRPS->complemento = '';
$rps->contractorRPS->bairro = 'CENTRO';
$rps->contractorRPS->cidade = '3550308';
$rps->contractorRPS->estado = 'SP';
$rps->contractorRPS->cep = '01001000';
$rps->contractorRPS->email = 'contato@exemplo.com';

$ret = $nfse->sendRPS($rps);
print_r($ret);
