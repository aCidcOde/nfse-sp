<?php

/**
 * Value Object class for RPS
 *
 * @package   NFePHPaulista
 * @author Reinaldo Nolasco Sanches <reinaldo@mandic.com.br>
 * @copyright Copyright (c) 2010, Reinaldo Nolasco Sanches
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 * ATUALIZACAO V2: Andre Gomes (x/acidcode).
 */
class NFeRPS
{
    public $CCM; // CCM do prestador
    public $serie;
    public $numero;

    /* RPS ­ Recibo Provisório de Serviços
     * RPS-M ­ Recibo Provisório de Serviços proveniente de Nota Fiscal Conjugada (Mista)
     * RPS-C ­ Cupom */
    public $type = 'RPS';

    public $dataEmissao;

    /* N ­ Normal
     * C ­ Cancelada
     * E ­ Extraviada */
    public $status = 'N';

    /* T - Tributação no município de São Paulo
     * F - Tributação fora do município de São Paulo
     * I ­- Isento
     * J - ISS Suspenso por Decisão Judicial */
    public $tributacao = 'I'; // I have problem with F and J options

    public $valorServicos = 0;
    public $valorDeducoes = 0;

    public $ValorPIS = 0;
    public $ValorCOFINS = 0;
    public $ValorINSS = 0;
    public $ValorIR = 0;
    public $ValorCSLL = 0;

    // Campos layout v2 (mantem compatibilidade com v1)
    public $valorInicialCobrado = 0;
    public $valorFinalCobrado = 0;

    public $intermediarioIndicador = null; // 1 CPF / 2 CNPJ / 3 Nao informado / 4 NIF
    public $intermediarioCpfCnpj = null;
    public $intermediarioInscricaoMunicipal = null;

    public $tomadorIndicador = null; // 1 CPF / 2 CNPJ / 3 Nao informado / 4 NIF
    public $tomadorNif = null;
    public $tomadorNaoNif = 0; // 0 nao informado / 1 dispensado / 2 nao exigencia

    public $valorMulta = 0;
    public $valorJuros = 0;
    public $valorIPI = 0;
    public $exigibilidadeSuspensa = 0; // 0 nao / 1 sim
    public $pagamentoParceladoAntecipado = 0; // 0 nao / 1 sim
    public $ncm = null;
    public $nbs = null;
    public $cLocPrestacao = null;
    public $cPaisPrestacao = null;

    public $ibsFinNFSe = 0;
    public $ibsIndFinal = 0;
    public $ibsCIndOp = null;
    public $ibsTpOper = null;
    public $ibsTpEnteGov = null;
    public $ibsIndDest = 1;
    public $ibsCClassTrib = null;
    public $ibsDestDocumento = null;
    public $ibsDestNome = null;
    public $ibsDestEmail = null;

    public $PercentualCargaTributaria = 0;
    public $ValorCargaTributaria = 0;

    public $codigoServico;
    public $aliquotaServicos; //Alíquota dos Serviços

    public $comISSRetido = false; // ISS retido

    public $contractorRPS; // new ContractorRPS

    public $discriminacao;   // Discriminação dos serviços

    public function getPrestadorIM($layoutVersion)
    {
        $ccm = (string)$this->CCM;
        if ((int)$layoutVersion === 2) {
            return sprintf('%012s', $ccm);
        }
        return sprintf('%08s', $ccm);
    }

    public function normalizeForLayout($layoutVersion)
    {
        if ((int)$layoutVersion !== 2) {
            return;
        }
        if ($this->valorInicialCobrado == 0 && $this->valorFinalCobrado == 0) {
            $this->valorInicialCobrado = $this->valorServicos;
            $this->valorFinalCobrado = $this->valorServicos;
        }
    }
}

/**
 * Value Object class for Contractor
 *
 * @author Reinaldo Nolasco Sanches <reinaldo@mandic.com>
 */
class ContractorRPS
{
    public $cnpjTomador; // CPF/CNPJ
    public $ccmTomador; // CCM

    public $type = 'C'; // C = Corporate (CNPJ), F = Personal (CPF)

    public $indicadorDocumento = null; // 1 CPF / 2 CNPJ / 3 nao informado / 4 NIF
    public $nif = null;
    public $naoNif = 0;

    public $name;

    public $tipoEndereco;
    public $endereco;
    public $enderecoNumero;
    public $complemento;
    public $bairro;
    public $cidade;
    public $estado;
    public $cep;

    public $email;
    public $email2;

    public function resolveIndicador()
    {
        if (!empty($this->nif)) {
            return 4;
        }
        $doc = preg_replace('/\D+/', '', (string)$this->cnpjTomador);
        $len = strlen($doc);
        if ($len === 11) {
            return 1;
        }
        if ($len === 14) {
            return 2;
        }
        return 3;
    }
}
