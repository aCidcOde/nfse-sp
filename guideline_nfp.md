# Guideline — Atualização NFS-e SP (layout v2) no projeto atual

Este guideline foca **apenas nos 2 arquivos que você mostrou**:

1) **Cliente SOAP** (criação do `SoapClient` + WSDL + parâmetros)  
2) **Value Objects** (`NFeRPS` e `ContractorRPS`)

> Observação: a migração completa para layout v2 também exige ajustes em **geração do XML**, **validação XSD** e principalmente **assinatura do RPS v2**. Este documento prepara o projeto para isso, mantendo retrocompatibilidade com v1.

---

## 0) Objetivo e estrategia

### Objetivo
- Padronizar o projeto para **layout v2** (CBS/IBS), mantendo possibilidade de fallback via `NFS_LAYOUT_VERSION`.
- Atualizar transporte (SOAP) e VOs para suportar os campos do v2.

### Estratégia (recomendada)
- **Não “quebrar” o que já funciona**: manter os campos v1 e **adicionar** campos v2.
- Introduzir um **mapeamento** interno (ex.: `RPS->toArray()` / `RPS->normalizeForSignature($layoutVersion)`), em vez de espalhar `if ($layout==2)` por todo o código.

---

## 1) Arquivo 1 — Cliente SOAP (SoapClient / WSDL / TLS)

### 1.1 Pré-requisito obrigatório
Garanta que o PHP tenha a extensão **SOAP** habilitada (senão aparece `Class 'SoapClient' not found`):
- macOS (Homebrew): `brew install php && php -m | grep -i soap`
- Debian/Ubuntu: `sudo apt-get install php-soap`
- Docker: instale `php-soap` e habilite no `php.ini` (ou `docker-php-ext-install soap`).

> Sem isso, nenhum ajuste de layout vai funcionar.

---

### 1.2 Legado sem ENV (PHP 7.4)
Este projeto roda em **PHP 7.4** e nao usa ENV para o certificado. Mantemos os valores **hardcoded**.

---

### 1.3 WSDL local (obrigatorio)
O WSDL remoto pode bloquear com WAF/403. O projeto usa **WSDL local**:

- `classes/lib/NFP/wsdl.xml`

Endpoints oficiais (nfews):
- Sync: `https://nfews.prefeitura.sp.gov.br/lotenfe.asmx`
- Async: `https://nfews.prefeitura.sp.gov.br/lotenfeasync.asmx`

### 1.4 Defaults atuais
- `NFS_LAYOUT_VERSION=2` (padrao; pode ser sobrescrito por ENV)
- `NFS_MODE=sync`

---

### 1.4 Parametros SOAP recomendados (producao vs dev)
Hoje você tem `verifypeer=false` e `verifyhost=false`. Isso facilita debug mas **enfraquece segurança**.

Sugestão:

- Em **dev/homolog**: pode manter `verifypeer=false`, `verifyhost=false`.
- Em **produção**: **ligar** ambos e garantir CA/cert chain corretos.

Exemplo (projeto atual):

```php
$params = [
  'local_cert' => 'classes/lib/NFP/certificados/20812466000107.pem',
  'passphrase' => 'TOPARQ2025',
  'connection_timeout' => 300,
  'encoding' => 'UTF-8',
  'soap_version' => SOAP_1_2,
  'trace' => true,
  'keep_alive' => false,
  'cache_wsdl' => WSDL_CACHE_NONE,
];
```

> `verifyhost=2` é o comportamento mais comum quando habilitado.

---

### 1.5 Padronizar logging de request/response
Como você já usa `trace => true`, crie um helper para logar em caso de erro:

- `__getLastRequest()`
- `__getLastResponse()`

Regras:
- Logar **somente** em erro.
- Remover dados sensíveis do log se necessário (CPF/CNPJ).

---

### 1.6 Logging de request/response
- `trace => true`
- Logar somente em erro (`__getLastRequest()` / `__getLastResponse()`).

---

## 2) Arquivo 2 — Value Objects (NFeRPS / ContractorRPS)

### 2.1 Problema atual (layout v2)
O layout v2 substitui o uso de `valorServicos` (v1) na assinatura e em partes do payload por valores de cobrança.

Então a mudança **não é apagar** `valorServicos` (porque v1 existe), mas:
- manter `valorServicos` para v1
- adicionar campos v2 e garantir que o restante do sistema use corretamente conforme a versão.

---

### 2.2 Alterações recomendadas em `NFeRPS`

#### 2.2.1 Adicionar campos v2 (sem quebrar v1)
Adicionar no `NFeRPS`:

- `public $valorInicialCobrado = 0;`
- `public $valorFinalCobrado = 0;`

Campos de intermediário (mesmo que você comece com `null`):
- `public $intermediarioIndicador = null;`  // 1 CPF / 2 CNPJ / 3 Não informado / 4 NIF
- `public $intermediarioCpfCnpj = null;`
- `public $intermediarioInscricaoMunicipal = null;`

Campos para suportar NIF (se seu fluxo tiver tomador estrangeiro):
- `public $tomadorIndicador = null;` // 1 CPF / 2 CNPJ / 3 Não informado / 4 NIF
- `public $tomadorNif = null;`

> Você não precisa popular tudo agora; mas os VOs precisam “aguentar” o modelo v2.

#### 2.2.2 Padronizar IM (CCM) com tamanho variável
A IM do prestador muda de regra no layout v2 (normalmente 12 dígitos, com zeros à esquerda).

Crie um método utilitário no `NFeRPS`:

- `public function getPrestadorIM(int $layoutVersion): string`
  - v1: pad com 8
  - v2: pad com 12

Isso evita espalhar padding pelo projeto.

#### 2.2.3 Método de normalização por versão (essencial)
Adicionar no `NFeRPS`:

- `public function normalizeForLayout(int $layoutVersion): void`
  - Se layout=2 e `valorInicialCobrado/valorFinalCobrado` estiverem zerados, derive a partir de `valorServicos` se fizer sentido no seu negócio.
  - Se layout=1, manter comportamento atual.

---

### 2.3 Alterações recomendadas em `ContractorRPS`

Hoje você tem:
- `type` = 'C' ou 'F' (CNPJ/CPF)

No layout v2 existe o conceito de “indicador CPF/CNPJ/NIF/não informado”.

Sugestão:
- manter `type` por compatibilidade
- adicionar:
  - `public $indicadorDocumento = null;` // 1 CPF / 2 CNPJ / 3 não informado / 4 NIF
  - `public $nif = null;`

E criar um método:
- `public function resolveIndicador(): int`
  - Se `cnpjTomador` tiver 11 → CPF (1)
  - Se tiver 14 → CNPJ (2)
  - Se tiver NIF preenchido → (4)
  - Senão → (3)

---

## 3) Checklist de mudancas (aplicar agora)

### Arquivo SOAP
- [ ] manter SOAP 1.2
- [ ] estruturar logging de request/response em erro
- [ ] parar de dar `exit()` em biblioteca; lançar/retornar erro tratável

### Arquivo VO
- [ ] adicionar campos v2 (`valorInicialCobrado`, `valorFinalCobrado`, indicadores)
- [ ] adicionar métodos de normalização e padding de IM por versão
- [ ] manter compatibilidade total com v1

---

## 4) Proximos passos (fora destes 2 arquivos, mas inevitaveis)

1) **Gerador de XML**: `Cabecalho->Versao = 2` quando layout v2 estiver ativo.  
2) **Validação XSD**: escolher XSD correto por versão e validar antes de transmitir.  
3) **Assinatura do RPS v2**: a string/campos mudam no layout v2; isso é o que mais quebra integração.  
4) **Suite de testes**:
   - gerar um RPS simples
   - validar XSD
   - assinar (v1 vs v2)
   - enviar em ambiente de teste/homolog
   - comparar mensagens/erros.

---

## 5) Entrega esperada após aplicar este guideline

Ao final dessas alterações:
- O projeto conseguirá instanciar o SOAP client de forma estável e configurável.
- Os VOs estarão prontos para carregar os dados necessários do layout v2, sem quebrar a integração atual.
- Você estará apto a implementar o “core” da migração: XML v2 + assinatura v2.

---

---

Se você me enviar agora **o arquivo que gera o XML** e **o trecho que monta a assinatura do RPS**, eu devolvo um guideline 2 (com foco exclusivo em: assinatura v2, mapeamento e validação XSD), já acoplado ao que foi definido aqui.
