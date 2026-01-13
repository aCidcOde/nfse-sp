# NFS-e SP (layout v2) - Nota Fiscal de Serviço

Atualizado para layout v2 por Andre Gomes (x/acidcode).



## O que este modulo faz
- Emite RPS/NFS-e para a Prefeitura de Sao Paulo via SOAP.
- Suporta layout v2 (CBS/IBS) com WSDL local.

## Arquivos principais
- `NFSeSP.class.php`: cliente SOAP, assinatura e envio.
- `NFeRPS.class.php`: VOs do RPS e dados v2.
- `wsdl.xml`: WSDL local baixado manualmente.
- `schemas-reformatributaria-v02-3/`: XSD do layout v2.

## Endpoints
- Sync: `https://nfews.prefeitura.sp.gov.br/lotenfe.asmx`
- Async: `https://nfews.prefeitura.sp.gov.br/lotenfeasync.asmx`

## Certificados
- Diretorio: `classes/lib/NFP/certificados`
- Certificados devem existir em `.pfx` e derivados `.pem`.

## Layout
- Default: `NFS_LAYOUT_VERSION=2`
- Obrigatorios v2: `NBS`, `cIndOp`, `cClassTrib`, `ValorInicialCobrado`/`ValorFinalCobrado`.
