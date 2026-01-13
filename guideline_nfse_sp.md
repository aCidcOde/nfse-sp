# Guideline — Integração NFS-e (Prefeitura de São Paulo) via Web Service — Manual v3.3.4 (layout v2)

Este documento transforma o PDF **“Nota Fiscal de Serviços Eletrônica (NFS-e) – Manual de Utilização Web Service – versão 3.3.4”** em um guideline prático para implementação e manutenção da integração no seu projeto.

> Escopo: padrões de comunicação, WSDL/endpoints, assinatura digital, montagem/envio das mensagens XML, métodos síncronos/assíncronos, validação por XSD, tratamento de erros e **layout v2 (Reforma Tributária 2026)**.

---

## 1) Conceitos e objetos principais

- **RPS**: Recibo Provisório de Serviços (documento que você gera no seu sistema e envia para ser convertido em NFS-e).
- **Lote de RPS**: envio em volume (múltiplos RPS na mesma mensagem).
- **NFS-e (NF-e no manual)**: Nota Fiscal de Serviços Eletrônica gerada/registrada no sistema da PMSP.
- **Guia**: guia de recolhimento/ISS (há emissão/consulta via interface assíncrona).
- **Sincrono vs Assíncrono**:
  - **Síncrono**: a resposta retorna na mesma chamada (ou retorna dados do lote).
  - **Assíncrono**: a resposta retorna um **protocolo**, e você consulta o status depois.

---

## 2) Endpoints (WSDL) e versão recomendada

WSDL oficial (host `nfews.prefeitura.sp.gov.br`):
- **Síncrono**: `https://nfews.prefeitura.sp.gov.br/lotenfe.asmx?WSDL`
- **Assíncrono**: `https://nfews.prefeitura.sp.gov.br/lotenfeasync.asmx?WSDL`

No projeto, usamos **WSDL local** para evitar bloqueio por WAF:
- `classes/lib/NFP/wsdl.xml`

---

## 3) Requisitos técnicos (TLS, SOAP e certificados)

### 3.1 TLS (obrigatório)
- O manual aponta erro HTTP **426** quando a versão TLS não é suportada e cita desativação de TLS 1.0/1.1, exigindo **mínimo TLS 1.2**.

Checklist:
- Em servidor Linux: garantir OpenSSL/LibSSL com suporte TLS 1.2+.
- Em PHP/cURL/SoapClient: não forçar versões antigas.

### 3.2 SOAP 1.2 (recomendado)
- Use `SOAP_1_2`.
- Garanta que a extensão `soap` esteja instalada/habilitada (`php -m | grep -i soap`).

### 3.3 Limite de tamanho de mensagem
- O manual indica limite de **500 KB** por mensagem XML de pedido. Mensagens maiores retornam erro de validação.

### 3.4 Certificado digital (ICP-Brasil)
- Certificados aceitos: ICP-Brasil **A1/A3/A4**, contendo o **CNPJ do proprietário**.
- O certificado é exigido, no mínimo, para:
  1) **Assinatura das mensagens XML**
  2) **Autenticação na transmissão** (mTLS) entre seu servidor e a PMSP

### 3.5 Regras críticas do remetente (CPFCNPJRemetente)
- Toda mensagem XML deve conter a tag **`CPFCNPJRemetente`**.
- O CPF/CNPJ informado nessa tag deve ser o mesmo que consta no certificado que está autenticando a transmissão.

Implicação prática:
- Se você enviar mensagem “em nome” de contador/terceiro, o certificado precisa ser do contador/terceiro autorizado, e o `CPFCNPJRemetente` deve refletir isso.

---

## 4) Padrão de chamada dos métodos (muito importante)

O manual define que os métodos de serviço recebem **dois parâmetros**:

```
<NomeDoMetodo>(<VersaoSchema>, <MensagemXML>)
```

- **VersaoSchema**: `Integer` indicando a versão do XSD usada para montar a mensagem.
- **MensagemXML**: `String` contendo o XML do pedido.

### 4.1 Como enviar o XML em `MensagemXML`
Há duas formas comuns:
1) Enviar o XML como string com caracteres especiais tratados (escape).
2) Enviar dentro de **CDATA**:

```xml
<MensagemXML><![CDATA[ ... XML DO PEDIDO AQUI ... ]]></MensagemXML>
```

---

## 5) Implementacao atual no projeto (Emergency)

- **WSDL local**: `classes/lib/NFP/wsdl.xml`
- **Endpoints**: `https://nfews.prefeitura.sp.gov.br/lotenfe.asmx` e `.../lotenfeasync.asmx`
- **SOAP 1.2** e TLS 1.2
- **Layout default**: `NFS_LAYOUT_VERSION=2` (pode ser sobrescrito por ENV)
- **Certificados**: `classes/lib/NFP/certificados` (path relativo ao arquivo da classe)

Campos obrigatorios no v2:
- `ValorInicialCobrado` **ou** `ValorFinalCobrado`
- `NBS`
- `cLocPrestacao` (ou `cPaisPrestacao`)
- `IBSCBS/valores/trib/gIBSCBS/cClassTrib`
- `IBSCBS/cIndOp`

---

## 6) Schemas (XSD): downloads e governança de versão

Toda mudança de layout implica atualização de XSD. O manual fornece links para baixar versões.

### 5.1 Pacotes de schemas (links citados no manual)
- **v01-0** (legado):  
  `https://nfpaulistana.prefeitura.sp.gov.br/arquivos/schemas-v01-0.zip`
- **v01-1** (mais recente para modelo “clássico”):  
  `https://nfpaulistana.prefeitura.sp.gov.br/arquivos/schemas-v01-1.zip`
- **Assíncrono v01-1**:  
  `https://nfpaulistana.prefeitura.sp.gov.br/arquivos/schemas-assincrono-v01-1.zip`
- **Reforma Tributária 2026 (layout v2)** — “schemas-reformatributaria”:  
  `https://notadomilhao.sf.prefeitura.sp.gov.br/wp-content/uploads/2025/11/schemas-reformatributaria-v02-3.zip`

### 5.2 Regra operacional
- Sempre **valide o XML** contra o XSD da versão (`VersaoSchema`) antes de transmitir.
- Mantenha os XSD versionados no repositório do projeto (ou em storage interno) e amarre por configuração.

---

## 7) Métodos e serviços disponíveis

### 6.1 Síncronos (lote/consulta/cancelamento)
Principais interfaces (descrição funcional):

- **Envio de RPS** (uso mais direto, menor volume)
- **Envio de Lote de RPS** (alto volume)
- **Teste de Envio de Lote de RPS** (validações sem gerar NFS-e)
- **Consulta de NF-e** (consulta específica)
- **Consulta de NF-e Recebidas** (tomadores/intermediários/prestadores)
- **Consulta de NF-e Emitidas** (prestador)
- **Consulta de Lote**
- **Consulta Informações do Lote**
- **Cancelamento de NF-e**
- **Consulta de CNPJ**

> Observação: o manual detalha cada método na seção “Serviços e Métodos Síncronos”.

### 6.2 Assíncronos (alto volume, protocolo e acompanhamento)
Principais interfaces:

- **Envio de Lote de RPS – Assíncrono**
- **Consulta Situação Lote Assíncrono**
- **Teste Envio de Lote de RPS – Assíncrono**
- **Emissão de Guia – Assíncrono**
- **Consulta Situação da Emissão de Guia – Assíncrona**
- **Consulta de Guia**

Fluxo típico:
1) Envia lote (async) → recebe **protocolo**
2) Consulta situação (com protocolo) até “processado”
3) Quando processado, consulta informações/lote conforme necessário

---

## 8) Assinatura digital: onde mais quebra integração

### 7.1 Assinatura de mensagem XML (geral)
- Mensagens XML devem ser assinadas digitalmente com certificado ICP-Brasil conforme regras do manual.
- O manual define quais mensagens podem ser assinadas por contribuinte, contador cadastrado ou usuário autorizado.

### 7.2 Assinatura do RPS (diferença v1 vs v2)
O manual traz quadros específicos de **“Campos para assinatura do RPS – versão 1.0”** e **“versão 2.0”**.

Impacto prático da migração v1 → v2:
- **O conjunto de campos e a regra de composição da string de assinatura mudam.**
- A v2 introduz indicadores/documentos (CPF/CNPJ/NIF/não informado) e troca o uso de `ValorServicos` por valores de cobrança em partes do fluxo.

Recomendação de implementação:
- Implementar duas rotinas:
  - `assinarRpsV1($rps)`
  - `assinarRpsV2($rps)`
- Selecionar por flag (`NFS_LAYOUT_VERSION=1|2`).

---

## 9) Layout v2 (Reforma Tributária 2026): como abordar sem risco

### 8.1 O que muda (alto nível)
- Há um pacote de schemas específico “Reforma Tributária 2026” (v02-3).
- O manual cita mudanças e remoções de campos do layout v1 em direção ao layout v2.

### 9.2 Estratégia recomendada
1) Manter WSDL oficial (nfews) e SOAP 1.2.
2) Garantir `VersaoSchema=2` e `Cabecalho Versao=2`.
3) Validar XML no XSD v02-3 antes de transmitir.
4) Rodar “Teste de Envio” (sync/async) antes de emitir em produção.

---

## 10) Tratamento de erros e alertas

### 9.1 Tipos de erro
O manual apresenta:
- **Erros HTTP** (ex.: TLS incompatível → 426)
- **Erros de schema/validação**
- **Erros de negócio** (ex.: duplicidade de RPS)
- **Alertas** (ex.: aviso sobre atividade não cadastrada, CPF inválido etc.)

### 9.2 Boas práticas
- Sempre registrar:
  - método chamado
  - `VersaoSchema`
  - request XML (com redaction de dados sensíveis)
  - response XML
- Em erro, diferenciar:
  - falha de transporte (TLS, SOAP)
  - falha de schema (XSD)
  - falha de regra de negócio (códigos/tabela)

---

## 11) Arquivos de exemplo (úteis para homologação)

Links citados no manual:
- Exemplos XML v01-0: `https://nfpaulistana.prefeitura.sp.gov.br/arquivos/exemplos-xml-v01-0.zip`
- Exemplos XML v01-1: `https://nfpaulistana.prefeitura.sp.gov.br/arquivos/exemplos-xml-v01-1.zip`
- Exemplos assíncronos v01-1: `https://nfpaulistana.prefeitura.sp.gov.br/arquivos/exemplos-assincrono-v01-1.zip`
- “Arquivos de exemplo” (pacote recente citado):  
  `https://notadomilhao.sf.prefeitura.sp.gov.br/wp-content/uploads/2025/11/Arquivos-de-exemplo.zip`

---

## 12) Checklist de implementação (produção)

### Transporte / Infra
- [ ] TLS 1.2+ funcionando no servidor
- [ ] PHP com extensão `soap` habilitada
- [ ] Certificado ICP-Brasil instalado e acessível no runtime
- [ ] `CPFCNPJRemetente` consistente com o certificado de transmissão

### Código
- [ ] Cliente SOAP usando `classes/lib/NFP/wsdl.xml`
- [ ] Montagem do XML v2 (`VersaoSchema=2`, `Cabecalho Versao=2`)
- [ ] Validação XSD v02-3 antes de enviar
- [ ] Assinatura do RPS v2 aplicada
- [ ] Parse e tratamento de retorno (erros/alertas) com logs úteis

### Operação
- [ ] Rodar “Teste de Envio” para validar integração (sem gerar NFS-e)
- [ ] Homologar cenários: RPS único, lote, cancelamento, consultas
- [ ] Monitorar códigos de erro mais comuns e criar alertas internos

---

---

## 13) Observacao final (para seu caso especifico)
O projeto ja usa o endpoint `nfews.prefeitura.sp.gov.br` e o layout v2. O maior “gargalo tecnico” normalmente e:
- **habilitar SOAP no PHP do servidor**
- **ajustar assinatura do RPS** (v2 muda a composição)
- **validar XSD correto por versão**

Se você quiser, o próximo guideline pode ser “Guideline 2 — Assinatura do RPS v2 e geração do XML v2”, focado diretamente no seu código existente (gerador de XML + assinatura).

---

## 14) Parametros v2 por empresa (Emergency)

### TOP Arquitetura (C00005)
- `codigo_empresa`: C00005
- `cIndOp`: 100301
- `cClassTrib`: 000001
- `NBS`: 114031000 (sem pontos)

### RAA (C00007)
- `codigo_empresa`: C00007
- `cIndOp`: 100301
- `cClassTrib`: 200052
- `NBS`: 113012000 (sem pontos)
