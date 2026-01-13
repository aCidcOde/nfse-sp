<?php

/**
 * Creates XMLs and Webservices communication
 *
 * Original names of Brazil specific abbreviations have been kept:
 * - CNPJ = Federal Tax Number
 * - CPF = Personal/Individual Taxpayer Registration Number
 * - CCM = Taxpayer Register (for service providers who pay ISS for local town/city hall)
 * - ISS = Service Tax
 *
 * @package   NFePHPaulista
 * @author    Reinaldo Nolasco Sanches <reinaldo@mandic.com.br>
 * @copyright Copyright (c) 2010, Reinaldo Nolasco Sanches
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 * ATUALIZACAO V2: Andre Gomes (x/acidcode).
 */

class NFSeSP
{
    private $cnpjPrestador = ''; // Your CNPJ
    private $ccmPrestador = ''; // Your CCM
    private $passphrase = ''; // Cert passphrase
    private $pkcs12  = '';
    private $certDir; // Dir for .pem certs

    private $privateKey;
    public $certDaysToExpire=0;
    private $publicKey;
    private $X509Certificate;
    private $key;
    private $connectionSoap;
    private $urlXsi = 'http://www.w3.org/2001/XMLSchema-instance';
    private $urlXsd = 'http://www.w3.org/2001/XMLSchema';
    private $urlNfe = 'http://www.prefeitura.sp.gov.br/nfe';
    private $urlDsig = 'http://www.w3.org/2000/09/xmldsig#';
    private $urlCanonMeth = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';
    private $urlSigMeth = 'http://www.w3.org/2000/09/xmldsig#rsa-sha1';
    private $urlTransfMeth_1 = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';
    private $urlTransfMeth_2 = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';
    private $urlDigestMeth = 'http://www.w3.org/2000/09/xmldsig#sha1';
    private $layoutVersion = 1;


    public function __construct($empresa)
    {
        $this->certDir = __DIR__ . '/certificados';
        if(!file_exists($this->certDir.'/'.Canivete::soNumero($empresa['cnpj']).'.pfx')) {
            exit('Certificado ou empresa nao pode gerar NFE');
        }

        //Rotina para utilizar a mesma classe em varias empresas do grupo Emergency
        $this->ccmPrestador = Canivete::soNumero($empresa['ccm']);
        $this->cnpjPrestador = Canivete::soNumero($empresa['cnpj']);
        $this->passphrase = $empresa['senha_certificado'];
        $this->pkcs12 = $this->certDir . '/'.Canivete::soNumero($empresa['cnpj']).'.pfx';

        $this->privateKey = $this->certDir . '/'.Canivete::soNumero($empresa['cnpj']).'_priKEY.pem';
        $this->publicKey = $this->certDir . '/'.Canivete::soNumero($empresa['cnpj']).'_pubKEY.pem';
        $this->key = $this->certDir . '/'.Canivete::soNumero($empresa['cnpj']).'.pem';
        $this->layoutVersion = (int)(getenv('NFS_LAYOUT_VERSION') ?: 2);

        if ($this->loadCert()) {
            error_log(__METHOD__ . ': Certificado OK!');
        } else {
            error_log(__METHOD__ . ': Certificado não OK!');
        }
    }

    private function validateCert($cert)
    {
        $data = openssl_x509_read($cert);
        $certData = openssl_x509_parse($data);

        $certValidDate = gmmktime(0, 0, 0, substr($certData['validTo'], 2, 2), substr($certData['validTo'], 4, 2), substr($certData['validTo'], 0, 2));
        // obtem o timestamp da data de hoje
        $dHoje = gmmktime(0, 0, 0, date("m"), date("d"), date("Y"));
        if ($certValidDate < time()) {
            error_log(__METHOD__ . ': Certificado expirado em ' . date('Y-m-d', $certValidDate));
            return false;
        }
        //diferença em segundos entre os timestamp
        $diferenca = $certValidDate - $dHoje;
        // convertendo para dias
        $diferenca = round($diferenca /(60*60*24), 0);
        //carregando a propriedade
        $this->certDaysToExpire = $diferenca;
        return true;
    }

    private function loadCert()
    {
        $x509CertData = array();
        if (! openssl_pkcs12_read(file_get_contents($this->pkcs12), $x509CertData, $this->passphrase)) {
            error_log(__METHOD__ . ': Certificado não pode ser lido. O arquivo esta corrompido ou em formato invalido.');
            return false;
        }
        $this->X509Certificate = preg_replace("/[\n]/", '', preg_replace('/\-\-\-\-\-[A-Z]+ CERTIFICATE\-\-\-\-\-/', '', $x509CertData['cert']));
        if (! self::validateCert($x509CertData['cert'])) {
            return false;
        }
        if (! is_dir($this->certDir)) {
            if (! mkdir($this->certDir, 0777)) {
                error_log(__METHOD__ . ': Falha ao criar o diretorio ' . $this->certDir);
                return false;
            }
        }
        if (! file_exists($this->privateKey)) {
            if (! file_put_contents($this->privateKey, $x509CertData['pkey'])) {
                error_log(__METHOD__ . ': Falha ao criar o arquivo ' . $this->privateKey);
                return false;
            }
        }
        if (! file_exists($this->publicKey)) {
            if (! file_put_contents($this->publicKey, $x509CertData['cert'])) {
                error_log(__METHOD__ . ': Falha ao criar o arquivo ' . $this->publicKey);
                return false;
            }
        }
        if (! file_exists($this->key)) {
            if (! file_put_contents($this->key, $x509CertData['cert'] . $x509CertData['pkey'])) {
                error_log(__METHOD__ . ': Falha ao criar o arquivo ' . $this->key);
                return false;
            }
        }
        return true;
    }

    public function start()
    {
        //versão do SOAP
        $soapver = SOAP_1_2;
        $isProd = getenv('APP_ENV') === 'production';
        $mode = getenv('NFS_MODE') ?: 'sync';
        $endpointSync = 'https://nfews.prefeitura.sp.gov.br/lotenfe.asmx';
        $endpointAsync = 'https://nfews.prefeitura.sp.gov.br/lotenfeasync.asmx';
        $endpoint = $mode === 'async' ? $endpointAsync : $endpointSync;
        $wsdlPath = __DIR__ . '/wsdl.xml';
        $sslOptions = array(
            'verify_peer' => $isProd ? true : false,
            'verify_peer_name' => $isProd ? true : false,
            'allow_self_signed' => $isProd ? false : true,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
        );
        $params = array(
            'local_cert' => $this->key,
            'passphrase' => $this->passphrase,
            'connection_timeout' => 300,
            'encoding'      => 'UTF-8',
            'verifypeer'    => $isProd ? true : false,
            'verifyhost'    => $isProd ? 2 : false,
            'soap_version'  => $soapver,
            'trace'         => true,
            'keep_alive'    => false,
            'cache_wsdl'    => WSDL_CACHE_NONE,
            'location'      => $endpoint,
            'uri'           => $this->urlNfe,
            'stream_context' => stream_context_create(array('ssl' => $sslOptions)),
        );
        try {
            if (is_readable($wsdlPath)) {
                $this->connectionSoap = new SoapClient($wsdlPath, $params);
            } else {
                $this->connectionSoap = new SoapClient(null, $params);
            }
        } catch (SoapFault $e) {

            echo "<pre>";
            error_log('Exception: ' . $e->getMessage());
            echo "erro de conexão soap. Tente novamente mais tarde !<br>\n";
            echo $e->getMessage();
            return false;
        }
    }

    private function send($operation, $xmlDoc)
    {

        self::start();
        $this->signXML($xmlDoc);

        $params = array(
            'VersaoSchema' => $this->layoutVersion,
            'MensagemXML' => $xmlDoc->saveXML()
        );
        try {
            if (empty($this->connectionSoap)) {
                throw new SoapFault('SOAP', 'SOAP client nao inicializado.');
            }
            $result = $this->connectionSoap->$operation($params);
        } catch (SoapFault $e) {
            if (!empty($this->connectionSoap)) {
                error_log('LastRequest: ' . $this->connectionSoap->__getLastRequest());
                error_log('LastResponse: ' . $this->connectionSoap->__getLastResponse());
            }
            error_log('Exception: ' . $e->getMessage());
            return $e->getMessage();
        }
        return new SimpleXMLElement($result->RetornoXML);
    }

    private function createXML($operation)
    {
        $xmlDoc = new DOMDocument('1.0', 'UTF-8');
        $xmlDoc->preservWhiteSpace = false;
        $xmlDoc->formatOutput = false;
        $data = '<?xml version="1.0" encoding="UTF-8"?><Pedido' . $operation . ' xmlns:xsd="' . $this->urlXsd .'" xmlns="' . $this->urlNfe . '" xmlns:xsi="' . $this->urlXsi . '"></Pedido' . $operation . '>';
        $xmlDoc->loadXML(str_replace(array("\r\n", "\n", "\r"), '', $data), LIBXML_NOBLANKS | LIBXML_NOEMPTYTAG);
        $root = $xmlDoc->documentElement;
        $header = $xmlDoc->createElementNS('', 'Cabecalho');
        $root->appendChild($header);
        $header->setAttribute('Versao', $this->layoutVersion);
        $cnpjSender = $xmlDoc->createElement('CPFCNPJRemetente');
        $cnpjSender->appendChild($xmlDoc->createElement('CNPJ', $this->cnpjPrestador));
        $header->appendChild($cnpjSender);

        return $xmlDoc;
    }

    private function createXMLp1($operation)
    {
        $xmlDoc = new DOMDocument('1.0', 'UTF-8');
        $xmlDoc->preservWhiteSpace = false;
        $xmlDoc->formatOutput = false;
        $data = '<?xml version="1.0" encoding="UTF-8"?><Pedido'.$operation.' xmlns="' . $this->urlNfe . '" xmlns:xsi="' . $this->urlXsi . '"></Pedido' . $operation . '>';
        $xmlDoc->loadXML(str_replace(array("\r\n", "\n", "\r"), '', $data), LIBXML_NOBLANKS | LIBXML_NOEMPTYTAG);
        $root = $xmlDoc->documentElement;
        $header = $xmlDoc->createElementNS('', 'Cabecalho');
        $root->appendChild($header);
        $header->setAttribute('Versao', $this->layoutVersion);
        $cnpjSender = $xmlDoc->createElement('CPFCNPJRemetente');
        $cnpjSender->appendChild($xmlDoc->createElement('CNPJ', $this->cnpjPrestador));
        $header->appendChild($cnpjSender);
        return $xmlDoc;
    }

    private function resolveTomadorIndicador(ContractorRPS $contractor)
    {
        if (!empty($contractor->indicadorDocumento)) {
            return (int)$contractor->indicadorDocumento;
        }
        return (int)$contractor->resolveIndicador();
    }

    private function resolveTomadorDocAssinatura(NFeRPS $rps)
    {
        $contractor = $rps->contractorRPS;
        if ($this->layoutVersion !== 2) {
            return preg_replace('/\D+/', '', (string)$contractor->cnpjTomador);
        }
        $indicador = $this->resolveTomadorIndicador($contractor);
        if ($indicador === 1 || $indicador === 2) {
            return preg_replace('/\D+/', '', (string)$contractor->cnpjTomador);
        }
        return str_repeat('0', 14);
    }

    private function signXML(&$xmlDoc)
    {
        $root = $xmlDoc->documentElement;
        // DigestValue is a base64 sha1 hash with root tag content without Signature tag
        $digestValue = base64_encode(hash('sha1', $root->C14N(false, false, null, null), true));
        $signature = $xmlDoc->createElementNS($this->urlDsig, 'Signature');
        $root->appendChild($signature);
        $signedInfo = $xmlDoc->createElement('SignedInfo');
        $signature->appendChild($signedInfo);
        $newNode = $xmlDoc->createElement('CanonicalizationMethod');
        $signedInfo->appendChild($newNode);
        $newNode->setAttribute('Algorithm', $this->urlCanonMeth);
        $newNode = $xmlDoc->createElement('SignatureMethod');
        $signedInfo->appendChild($newNode);
        $newNode->setAttribute('Algorithm', $this->urlSigMeth);
        $reference = $xmlDoc->createElement('Reference');
        $signedInfo->appendChild($reference);
        $reference->setAttribute('URI', '');
        $transforms = $xmlDoc->createElement('Transforms');
        $reference->appendChild($transforms);
        $newNode = $xmlDoc->createElement('Transform');
        $transforms->appendChild($newNode);
        $newNode->setAttribute('Algorithm', $this->urlTransfMeth_1);
        $newNode = $xmlDoc->createElement('Transform');
        $transforms->appendChild($newNode);
        $newNode->setAttribute('Algorithm', $this->urlTransfMeth_2);
        $newNode = $xmlDoc->createElement('DigestMethod');
        $reference->appendChild($newNode);
        $newNode->setAttribute('Algorithm', $this->urlDigestMeth);
        $newNode = $xmlDoc->createElement('DigestValue', $digestValue);
        $reference->appendChild($newNode);
        // SignedInfo Canonicalization (Canonical XML)
        $signedInfoC14n = $signedInfo->C14N(false, false, null, null);
        // SignatureValue is a base64 SignedInfo tag content
        $signatureValue = '';
        $pkeyId = openssl_get_privatekey(file_get_contents($this->privateKey));
        openssl_sign($signedInfoC14n, $signatureValue, $pkeyId);
        $newNode = $xmlDoc->createElement('SignatureValue', base64_encode($signatureValue));
        $signature->appendChild($newNode);
        $keyInfo = $xmlDoc->createElement('KeyInfo');
        $signature->appendChild($keyInfo);
        $x509Data = $xmlDoc->createElement('X509Data');
        $keyInfo->appendChild($x509Data);
        $newNode = $xmlDoc->createElement('X509Certificate', $this->X509Certificate);
        $x509Data->appendChild($newNode);
        openssl_free_key($pkeyId);
    }

    private function signRPS(NFeRPS $rps, &$rpsNode)
    {
        $rps->normalizeForLayout($this->layoutVersion);
        $prestadorIM = $rps->getPrestadorIM($this->layoutVersion);
        $valorBaseAssinatura = $rps->valorServicos;
        if ($this->layoutVersion === 2) {
            $valorBaseAssinatura = $rps->valorInicialCobrado ?: $rps->valorFinalCobrado;
        }
        $docAssinatura = $this->resolveTomadorDocAssinatura($rps);
        $indicadorAssinatura = ($this->layoutVersion === 2)
            ? $this->resolveTomadorIndicador($rps->contractorRPS)
            : (($rps->contractorRPS->type == 'F') ? 1 : 2);
        $content = sprintf('%s', $prestadorIM).
            sprintf('%-5s', $rps->serie).
            sprintf('%012s', $rps->numero).
            str_replace("-", "", $rps->dataEmissao).
            $rps->tributacao .
            $rps->status .
            (($rps->comISSRetido) ? 'S' : 'N') .
            sprintf('%015s', str_replace(array('.', ','), '', number_format($valorBaseAssinatura, 2))).
            sprintf('%015s', str_replace(array('.', ','), '', number_format($rps->valorDeducoes, 2))).
            sprintf('%05s', $rps->codigoServico) .
            $indicadorAssinatura .
            sprintf('%014s', $docAssinatura);

        $signatureValue = '';
        $pkeyId = openssl_get_privatekey(file_get_contents($this->privateKey));
        openssl_sign($content, $signatureValue, $pkeyId, OPENSSL_ALGO_SHA1);
        openssl_free_key($pkeyId);
        $rpsNode->appendChild(new DOMElement('Assinatura', base64_encode($signatureValue)));
    }

    private function insertRPS(NFeRPS $rps, &$xmlDoc)
    {
        $rpsNode = $xmlDoc->createElementNS('', 'RPS');
        $xmlDoc->documentElement->appendChild($rpsNode);
        $this->signRPS($rps, $rpsNode);
        $rpsKey = $xmlDoc->createElement('ChaveRPS'); // 1-1
        $rpsKey->appendChild($xmlDoc->createElement('InscricaoPrestador', $rps->getPrestadorIM($this->layoutVersion))); // 1-1
        $rpsKey->appendChild($xmlDoc->createElement('SerieRPS', $rps->serie)); // 1-1 DHC AAAAA / alog AAAAB
        $rpsKey->appendChild($xmlDoc->createElement('NumeroRPS', $rps->numero)); // 1-1
        $rpsNode->appendChild($rpsKey);
        /* RPS ­ Recibo Provisório de Serviços
         * RPS-M ­ Recibo Provisório de Serviços proveniente de Nota Fiscal Conjugada (Mista)
        * RPS-C ­ Cupom */
        $rpsNode->appendChild($xmlDoc->createElement('TipoRPS', $rps->type)); // 1-1
        $rpsNode->appendChild($xmlDoc->createElement('DataEmissao', $rps->dataEmissao)); // 1-1
        /* N ­ Normal
        * C ­ Cancelada
        * E ­ Extraviada */
        $rpsNode->appendChild($xmlDoc->createElement('StatusRPS', $rps->status)); // 1-1
        /* T - Tributação no município de São Paulo
         * F - Tributação fora do município de São Paulo
         * I ­- Isento
         * J - ISS Suspenso por Decisão Judicial */
        $rpsNode->appendChild($xmlDoc->createElement('TributacaoRPS', $rps->tributacao)); // 1-1
        if ($this->layoutVersion === 1) {
            $rpsNode->appendChild($xmlDoc->createElement('ValorServicos', sprintf("%s", $rps->valorServicos))); // 1-1
        }
        $rpsNode->appendChild($xmlDoc->createElement('ValorDeducoes', sprintf("%s", $rps->valorDeducoes))); // 1-1

        $rpsNode->appendChild($xmlDoc->createElement('ValorPIS', sprintf("%s", $rps->ValorPIS))); // 1-1
        $rpsNode->appendChild($xmlDoc->createElement('ValorCOFINS', sprintf("%s", $rps->ValorCOFINS))); // 1-1
        $rpsNode->appendChild($xmlDoc->createElement('ValorINSS', sprintf("%s", $rps->ValorINSS))); // 1-1
        $rpsNode->appendChild($xmlDoc->createElement('ValorIR', sprintf("%s", $rps->ValorIR))); // 1-1
        $rpsNode->appendChild($xmlDoc->createElement('ValorCSLL', sprintf("%s", $rps->ValorCSLL))); // 1-1

        $rpsNode->appendChild($xmlDoc->createElement('CodigoServico', $rps->codigoServico)); // 1-1
        $rpsNode->appendChild($xmlDoc->createElement('AliquotaServicos', $rps->aliquotaServicos)); // 1-1
        $rpsNode->appendChild($xmlDoc->createElement('ISSRetido', (($rps->comISSRetido) ? 'true' : 'false'))); // 1-1
        $cnpj = $xmlDoc->createElement('CPFCNPJTomador'); // 0-1
        if ($this->layoutVersion === 2) {
            $indicador = $this->resolveTomadorIndicador($rps->contractorRPS);
            if ($indicador === 1) {
                $cnpj->appendChild($xmlDoc->createElement('CPF', sprintf('%011s', $rps->contractorRPS->cnpjTomador)));
            } elseif ($indicador === 2) {
                $cnpj->appendChild($xmlDoc->createElement('CNPJ', sprintf('%014s', $rps->contractorRPS->cnpjTomador)));
            } elseif ($indicador === 4 && !empty($rps->contractorRPS->nif)) {
                $cnpj->appendChild($xmlDoc->createElement('NIF', $rps->contractorRPS->nif));
            } else {
                $cnpj->appendChild($xmlDoc->createElement('NaoNIF', (int)$rps->contractorRPS->naoNif));
            }
        } else {
            if ($rps->contractorRPS->type == "F") {
                $cnpj->appendChild($xmlDoc->createElement('CPF', sprintf('%011s', $rps->contractorRPS->cnpjTomador)));
            } else {
                $cnpj->appendChild($xmlDoc->createElement('CNPJ', sprintf('%014s', $rps->contractorRPS->cnpjTomador)));
            }
        }
        $rpsNode->appendChild($cnpj);
        if ($rps->contractorRPS->ccmTomador <> "") {
            $rpsNode->appendChild($xmlDoc->createElement('InscricaoMunicipalTomador', $rps->contractorRPS->ccmTomador)); // 0-1
        }
        $rpsNode->appendChild($xmlDoc->createElement('RazaoSocialTomador', $rps->contractorRPS->name)); // 0-1
        $address = $xmlDoc->createElement('EnderecoTomador'); // 0-1
        $address->appendChild($xmlDoc->createElement('TipoLogradouro', $rps->contractorRPS->tipoEndereco));
        $address->appendChild($xmlDoc->createElement('Logradouro', $rps->contractorRPS->endereco));
        $address->appendChild($xmlDoc->createElement('NumeroEndereco', $rps->contractorRPS->enderecoNumero));
        if (trim($rps->contractorRPS->complemento) != "") {
            $address->appendChild($xmlDoc->createElement('ComplementoEndereco', $rps->contractorRPS->complemento));
        }
        $address->appendChild($xmlDoc->createElement('Bairro', $rps->contractorRPS->bairro));
        $address->appendChild($xmlDoc->createElement('Cidade', $rps->contractorRPS->cidade));
        $address->appendChild($xmlDoc->createElement('UF', $rps->contractorRPS->estado));
        $address->appendChild($xmlDoc->createElement('CEP', $rps->contractorRPS->cep));
        $rpsNode->appendChild($address);
        $rpsNode->appendChild($xmlDoc->createElement('EmailTomador', $rps->contractorRPS->email)); // 0-1
        if (!empty($rps->intermediarioCpfCnpj)) {
            $rpsNode->appendChild($xmlDoc->createElement('CPFCNPJIntermediario', $rps->intermediarioCpfCnpj));
        }
        if (!empty($rps->intermediarioInscricaoMunicipal)) {
            $rpsNode->appendChild($xmlDoc->createElement('InscricaoMunicipalIntermediario', $rps->intermediarioInscricaoMunicipal));
        }
        $rpsNode->appendChild($xmlDoc->createElement('Discriminacao', $rps->discriminacao)); // 1-1

        $rpsNode->appendChild($xmlDoc->createElement('ValorCargaTributaria', sprintf("%s", $rps->ValorCargaTributaria))); // 1-1
        $rpsNode->appendChild($xmlDoc->createElement('PercentualCargaTributaria', sprintf("%s", $rps->PercentualCargaTributaria))); // 1-1

        if ($this->layoutVersion === 2) {
            if (!empty($rps->valorInicialCobrado) || empty($rps->valorFinalCobrado)) {
                $rpsNode->appendChild($xmlDoc->createElement('ValorInicialCobrado', sprintf("%s", $rps->valorInicialCobrado)));
            } else {
                $rpsNode->appendChild($xmlDoc->createElement('ValorFinalCobrado', sprintf("%s", $rps->valorFinalCobrado)));
            }
            $rpsNode->appendChild($xmlDoc->createElement('ValorIPI', sprintf("%s", $rps->valorIPI)));
            $rpsNode->appendChild($xmlDoc->createElement('ExigibilidadeSuspensa', (int)$rps->exigibilidadeSuspensa));
            $rpsNode->appendChild($xmlDoc->createElement('PagamentoParceladoAntecipado', (int)$rps->pagamentoParceladoAntecipado));

            if (empty($rps->nbs)) {
                throw new Exception('NBS obrigatorio no layout v2.');
            }
            $rpsNode->appendChild($xmlDoc->createElement('NBS', $rps->nbs));

            $locPrestacao = $rps->cLocPrestacao ?: $rps->contractorRPS->cidade;
            if (empty($locPrestacao) && empty($rps->cPaisPrestacao)) {
                throw new Exception('Local de prestacao obrigatorio no layout v2.');
            }
            if (!empty($locPrestacao)) {
                $rpsNode->appendChild($xmlDoc->createElement('cLocPrestacao', $locPrestacao));
            } else {
                $rpsNode->appendChild($xmlDoc->createElement('cPaisPrestacao', $rps->cPaisPrestacao));
            }

            if (empty($rps->ibsCIndOp) || empty($rps->ibsCClassTrib)) {
                throw new Exception('IBSCBS obrigatorio no layout v2 (cIndOp e cClassTrib).');
            }
            $ibsNode = $xmlDoc->createElement('IBSCBS');
            $ibsNode->appendChild($xmlDoc->createElement('finNFSe', (int)$rps->ibsFinNFSe));
            $ibsNode->appendChild($xmlDoc->createElement('indFinal', (int)$rps->ibsIndFinal));
            $ibsNode->appendChild($xmlDoc->createElement('cIndOp', $rps->ibsCIndOp));
            if (!empty($rps->ibsTpOper)) {
                $ibsNode->appendChild($xmlDoc->createElement('tpOper', (int)$rps->ibsTpOper));
            }
            if (!empty($rps->ibsTpEnteGov)) {
                $ibsNode->appendChild($xmlDoc->createElement('tpEnteGov', (int)$rps->ibsTpEnteGov));
            }
            $ibsNode->appendChild($xmlDoc->createElement('indDest', (int)$rps->ibsIndDest));
            if (!empty($rps->ibsDestDocumento) && !empty($rps->ibsDestNome)) {
                $destNode = $xmlDoc->createElement('dest');
                $destDoc = preg_replace('/\D+/', '', (string)$rps->ibsDestDocumento);
                $destLen = strlen($destDoc);
                if ($destLen === 11) {
                    $destNode->appendChild($xmlDoc->createElement('CPF', $destDoc));
                } elseif ($destLen === 14) {
                    $destNode->appendChild($xmlDoc->createElement('CNPJ', $destDoc));
                } else {
                    $destNode->appendChild($xmlDoc->createElement('NIF', $rps->ibsDestDocumento));
                }
                $destNode->appendChild($xmlDoc->createElement('xNome', $rps->ibsDestNome));
                if (!empty($rps->ibsDestEmail)) {
                    $destNode->appendChild($xmlDoc->createElement('email', $rps->ibsDestEmail));
                }
                $ibsNode->appendChild($destNode);
            }
            $valoresNode = $xmlDoc->createElement('valores');
            $tribNode = $xmlDoc->createElement('trib');
            $gIbsNode = $xmlDoc->createElement('gIBSCBS');
            $gIbsNode->appendChild($xmlDoc->createElement('cClassTrib', $rps->ibsCClassTrib));
            $tribNode->appendChild($gIbsNode);
            $valoresNode->appendChild($tribNode);
            $ibsNode->appendChild($valoresNode);
            $rpsNode->appendChild($ibsNode);
        }

    }

    /**
     * Send a RPS to replace for NF-e
     *
     * @param NFeRPS $rps
     */
    public function sendRPS(NFeRPS $rps)
    {
        $operation = 'EnvioRPS';
        $xmlDoc = $this->createXML($operation);
        $this->insertRPS($rps, $xmlDoc);
        $returnXmlDoc = $this->send($operation, $xmlDoc);
        return $returnXmlDoc;
    }



    /**
     * Send a batch of RPSs to replace for NF-e
     *
     * @param array $rangeDate ('start' => start date of RPSs, 'end' => end date of RPSs)
     * @param array $valorTotal ('servicos' => total value of RPSs, 'deducoes' => total deductions on values of RPSs)
     * @param array $rps Collection of NFeRPS
     */
    public function sendRPSBatch($rangeDate, $valorTotal, $rps)
    {
        $operation = 'EnvioLoteRPS';
        $xmlDoc = $this->createXML($operation);
        $header = $xmlDoc->documentElement->getElementsByTagName('Cabecalho')->item(0);
        $header->appendChild($xmlDoc->createElement('transacao', 'false'));
        $header->appendChild($xmlDoc->createElement('dtInicio', $rangeDate['inicio']));
        $header->appendChild($xmlDoc->createElement('dtFim', $rangeDate['fim']));
        $header->appendChild($xmlDoc->createElement('QtdRPS', count($rps)));
        $header->appendChild($xmlDoc->createElement('ValorTotalServicos', $valorTotal['servicos']));
        $header->appendChild($xmlDoc->createElement('ValorTotalDeducoes', $valorTotal['deducoes']));
        foreach ($rps as $item) {
            $this->insertRPS($item, $xmlDoc);
        }
        return $this->send($operation, $xmlDoc);
    }

    /**
     * Send a batch of RPSs to replace for NF-e for test only
     *
     * @param array $rangeDate ('start' => start date of RPSs, 'end' => end date of RPSs)
     * @param array $valorTotal ('servicos' => total value of RPSs, 'deducoes' => total deductions on values of RPSs)
     * @param array $rps Collection of NFeRPS
     */
    public function sendRPSBatchTest($rangeDate, $valorTotal, $rps)
    {
        $operation = 'EnvioLoteRPS';
        $xmlDoc = $this->createXML($operation);
        $header = $xmlDoc->documentElement->getElementsByTagName('Cabecalho')->item(0);
        $header->appendChild($xmlDoc->createElement('transacao', 'false'));
        $header->appendChild($xmlDoc->createElement('dtInicio', $rangeDate['inicio']));
        $header->appendChild($xmlDoc->createElement('dtFim', $rangeDate['fim']));
        $header->appendChild($xmlDoc->createElement('QtdRPS', count($rps)));
        $header->appendChild($xmlDoc->createElement('ValorTotalServicos', $valorTotal['servicos']));
        $header->appendChild($xmlDoc->createElement('ValorTotalDeducoes', $valorTotal['deducoes']));
        foreach ($rps as $item) {
            $this->insertRPS($item, $xmlDoc);
        }
        //    $docxml = $xmlDoc->saveXML();
        //    echo "xml gerado[<br>\n";
        //    print_r($docxml);
        //    echo "]<br>\n";
        //    exit();
        $return = $this->send('TesteEnvioLoteRPS', $xmlDoc);
        $xmlDoc->formatOutput = true;
        error_log(__METHOD__ . ': ' . $xmlDoc->saveXML());
        return $return;
    }

    /**
     *
     * @param array $nfe Array of NFe numbers
     */
    public function cancelNFe($nfeNumbers)
    {
        $operation = 'CancelamentoNFe';
        $xmlDoc = $this->createXML($operation);
        $root = $xmlDoc->documentElement;
        $header = $root->getElementsByTagName('Cabecalho')->item(0);
        $header->appendChild($xmlDoc->createElement('transacao', 'false'));
        foreach ($nfeNumbers as $nfeNumber) {
            $detail = $xmlDoc->createElementNS('', 'Detalhe');
            $root->appendChild($detail);
            $nfeKey = $xmlDoc->createElement('ChaveNFe'); // 1-1
            $nfeKey->appendChild($xmlDoc->createElement('InscricaoPrestador', $this->ccmPrestador)); // 1-1
            $nfeKey->appendChild($xmlDoc->createElement('NumeroNFe', $nfeNumber)); // 1-1
            $detail->appendChild($nfeKey);
            $content = sprintf('%08s', $this->ccmPrestador) .
                sprintf('%012s', $nfeNumber);
            $signatureValue = '';
            $digestValue = base64_encode(hash('sha1', $content, true));
            $pkeyId = openssl_get_privatekey(file_get_contents($this->privateKey));
            //      openssl_sign($digestValue, $signatureValue, $pkeyId);
            openssl_sign($content, $signatureValue, $pkeyId, OPENSSL_ALGO_SHA1);
            openssl_free_key($pkeyId);
            $detail->appendChild(new DOMElement('AssinaturaCancelamento', base64_encode($signatureValue)));
        }
        $docxml = $xmlDoc->saveXML();
        return $this->send($operation, $xmlDoc);
    }

    public function queryNFe($nfeNumber, $rpsNumber, $rpsSerie)
    {
        $operation = 'ConsultaNFe';
        $xmlDoc = $this->createXMLp1($operation);
        $root = $xmlDoc->documentElement;
        if ($nfeNumber > 0) {
            $detailNfe = $xmlDoc->createElementNS('', 'Detalhe');
            $root->appendChild($detailNfe);
            $nfeKey = $xmlDoc->createElement('ChaveNFe'); // 1-1
            $nfeKey->appendChild($xmlDoc->createElement('InscricaoPrestador', $this->ccmPrestador)); // 1-1
            $nfeKey->appendChild($xmlDoc->createElement('NumeroNFe', $nfeNumber)); // 1-1
            $detailNfe->appendChild($nfeKey);
        }
        if ($rpsNumber > 0) {
            //$detailRps = $xmlDoc->createElement('Detalhe');
            $detailRps = $xmlDoc->createElementNS('', 'Detalhe');
            $root->appendChild($detailRps);
            $rpsKey = $xmlDoc->createElement('ChaveRPS'); // 1-1
            $rpsKey->appendChild($xmlDoc->createElement('InscricaoPrestador', $this->ccmPrestador)); // 1-1
            $rpsKey->appendChild($xmlDoc->createElement('SerieRPS', $rpsSerie)); // 1-1 DHC AAAAA / alog AAAAB
            $rpsKey->appendChild($xmlDoc->createElement('NumeroRPS', $rpsNumber)); // 1-1
            $detailRps->appendChild($rpsKey);
        }
        return $this->send($operation, $xmlDoc);
    }

    /**
     * queryNFeReceived and queryNFeIssued have the same XML request model
     *
     * @param string $cnpj
     * @param string $ccm
     * @param string $startDate YYYY-MM-DD
     * @param string $endDate YYYY-MM-DD
     */
    private function queryNFeWithDateRange($cnpj, $ccm, $startDate, $endDate)
    {
        $operation = 'ConsultaNFePeriodo';
        $xmlDoc = $this->createXML($operation);
        $header = $xmlDoc->documentElement->getElementsByTagName('Cabecalho')->item(0);
        $cnpjTaxpayer = $xmlDoc->createElement('CPFCNPJ');
        $cnpjTaxpayer->appendChild($xmlDoc->createElement('CNPJ', $cnpj));
        $header->appendChild($cnpjTaxpayer);
        $ccmTaxpayer = $xmlDoc->createElement('Inscricao', $ccm);
        $header->appendChild($ccmTaxpayer);
        $startDateNode = $xmlDoc->createElement('dtInicio', $startDate);
        $header->appendChild($startDateNode);
        $endDateNode = $xmlDoc->createElement('dtFim', $endDate);
        $header->appendChild($endDateNode);
        $pageNumber = $xmlDoc->createElement('NumeroPagina', 1);
        $header->appendChild($pageNumber);
        return $xmlDoc;
    }

    /**
     * Query NF-e's that CNPJ/CCM company received from other companies
     *
     * @param string $cnpj
     * @param string $ccm
     * @param string $startDate YYYY-MM-DD
     * @param string $endDate YYYY-MM-DD
     */
    public function queryNFeReceived($cnpj, $ccm, $startDate, $endDate)
    {
        $operation = 'ConsultaNFeRecebidas';
        $xmlDoc = $this->queryNFeWithDateRange($cnpj, $ccm, $startDate, $endDate);
        return $this->send($operation, $xmlDoc);
    }

    /**
     * Query NF-e's that CNPJ/CCM company issued to other companies
     *
     * @param string $cnpj
     * @param string $ccm
     * @param string $startDate YYYY-MM-DD
     * @param string $endDate YYYY-MM-DD
     */
    public function queryNFeIssued($cnpj, $ccm, $startDate, $endDate)
    {
        $operation = 'ConsultaNFeEmitidas';
        $xmlDoc = $this->queryNFeWithDateRange($cnpj, $ccm, $startDate, $endDate);
        return $this->send($operation, $xmlDoc);
    }

    public function queryBatch($batchNumber)
    {
        $operation = 'ConsultaLote';
        $xmlDoc = $this->createXML($operation);
        $header = $xmlDoc->documentElement->getElementsByTagName('Cabecalho')->item(0);
        $header->appendChild($xmlDoc->createElement('NumeroLote', $batchNumber));
        return $this->send($operation, $xmlDoc);
    }


    /**
     * If $batchNumber param is null, last match info will be returned
     *
     * @param integer $batchNumber
     */
    public function queryBatchInfo($batchNumber = null)
    {
        $operation = 'InformacoesLote';
        $xmlDoc = $this->createXML($operation);
        $header = $xmlDoc->documentElement->getElementsByTagName('Cabecalho')->item(0);
        $header->appendChild($xmlDoc->createElement('InscricaoPrestador', $this->ccmPrestador));
        if ($batchNumber) {
            $header->appendChild($xmlDoc->createElement('NumeroLote', $batchNumber));
        }
        return $this->send($operation, $xmlDoc);
    }

    /**
     * Returns CCM for given CNPJ
     *
     * @param string $cnpj
     */
    public function queryCNPJ($cnpj)
    {
        $operation = 'ConsultaCNPJ';
        $xmlDoc = $this->createXMLp1($operation);
        $root = $xmlDoc->documentElement;
        $cnpjTaxpayer = $xmlDoc->createElementNS('', 'CNPJContribuinte');
        if (strlen($cnpj) == 11) {
            $cnpjTaxpayer->appendChild($xmlDoc->createElement('CPF', (string) sprintf('%011s', $cnpj)));
        } else {
            $cnpjTaxpayer->appendChild($xmlDoc->createElement('CNPJ', (string) sprintf('%014s', $cnpj)));
        }
        $root->appendChild($cnpjTaxpayer);
        $docxml = $xmlDoc->saveXML();
        if ($return = $this->send($operation, $xmlDoc)) {
            if ($return->Detalhe->InscricaoMunicipal <> "") {
                return $return->Detalhe->InscricaoMunicipal;
            } else {
                if ($return->Alerta->Codigo <> "") {
                    return $return->Alerta->Descricao;
                } else {
                    return false;
                }
            }
        } else {
            return false;
        }
    }

    /**
     * Create a line with RPS description for batch file
     *
     * @param unknown_type $rps
     * @param unknown_type $body
     */
    private function insertTextRPS(NFeRPS $rps, &$body)
    {
        if ($rps->valorServicos > 0) {
            $line = "2" .
                sprintf("%-5s", $rps->type) .
                sprintf("%-5s", $rps->serie) .
                sprintf('%012s', $rps->numero) .
                str_replace("-", "", $rps->dataEmissao) .
                $rps->tributacao .
                sprintf('%015s', str_replace('.', '', sprintf('%.2f', $rps->valorServicos))) .
                sprintf('%015s', str_replace('.', '', sprintf('%.2f', $rps->valorDeducoes))) .
                sprintf('%05s', $rps->codigoServico) .
                sprintf('%04s', str_replace('.', '', $rps->aliquotaServicos)) .
                (($rps->comISSRetido) ? '1' : '2') .
                (($rps->contractorRPS->type == 'F') ? '1' : '2') .
                sprintf('%014s', $rps->contractorRPS->cnpjTomador) .
                sprintf('%08s', $rps->contractorRPS->ccmTomador) .
                sprintf('%012s', '') .
                sprintf('%-75s', mb_convert_encoding($rps->contractorRPS->name, 'ISO-8859-1', 'UTF-8')) .
                sprintf('%3s', (($rps->contractorRPS->tipoEndereco == 'R') ? 'Rua' : '')) .
                sprintf('%-50s', mb_convert_encoding($rps->contractorRPS->endereco, 'ISO-8859-1', 'UTF-8')) .
                sprintf('%-10s', $rps->contractorRPS->enderecoNumero) .
                sprintf('%-30s', mb_convert_encoding($rps->contractorRPS->complemento, 'ISO-8859-1', 'UTF-8')) .
                sprintf('%-30s', mb_convert_encoding($rps->contractorRPS->bairro, 'ISO-8859-1', 'UTF-8')) .
                sprintf('%-50s', mb_convert_encoding($rps->contractorRPS->cidade, 'ISO-8859-1', 'UTF-8')) .
                sprintf('%-2s', $rps->contractorRPS->estado) .
                sprintf('%08s', $rps->contractorRPS->cep) .
                sprintf('%-75s', $rps->contractorRPS->email) .
                str_replace("\n", '|', mb_convert_encoding($rps->discriminacao, 'ISO-8859-1', 'UTF-8'));
            $body .= $line . chr(13) . chr(10);
        }
    }

    /**
     * Create a batch file with NF-e text layout
     *
     * @param unknown_type $rangeDate
     * @param unknown_type $valorTotal
     * @param unknown_type $rps
     */
    public function textFile($rangeDate, $valorTotal, $rps)
    {
        $file = '';
        $header = "1" .
            "001" .
            $this->ccmPrestador .
            date("Ymd", $rangeDate['inicio']) .
            date("Ymd", $rangeDate['fim']) .
            chr(13) . chr(10);
        $body = '';
        foreach ($rps as $item) {
            $this->insertTextRPS($item, $body);
        }
        $footer = "9" .
            sprintf("%07s", count($rps)) .
            sprintf("%015s", str_replace('.', '', sprintf('%.2f', $valorTotal['servicos']))) .
            sprintf("%015s", str_replace('.', '', sprintf('%.2f', $valorTotal['deducoes']))) .
            chr(13) . chr(10);
        $rpsDir = '/patch/for/rps/batch/file';
        $rpsFileName = date("Y-m-d_Hi") . '.txt';
        $rpsFullPath = $rpsDir . '/' . $rpsFileName;
        if (! is_dir($rpsDir)) {
            if (! mkdir($rpsDir, 0777)) {

            }
        }
        if (! file_put_contents($rpsFullPath, $header . $body . $footer)) {
            error_log(__METHOD__ . ': Cannot create rps file ' . $rpsFullPath);
            return false;
        }
        return $rpsFullPath;
    }
}
