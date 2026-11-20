# Mercado Pago: área administrativa de pagamentos de teste

Acesse **Administração → Pagamentos de teste**. A área é opcional, exclusiva do
Admin e desativada por padrão, inclusive em produção. O restante da Piscina
continua funcionando sem configurá-la. A alteração parte do `main` com a PR #15
já incorporada, incluindo prêmios de associações e navegação Admin mobile.

## Modalidade escolhida e limites

Integração **Checkout Bricks / Card Payment Brick + Payments API v1**:

- Cartões usam o SDK JavaScript oficial v2, com tokenização pelo provedor.
  Não há campos próprios para PAN/CVV, cartões salvos ou armazenamento de token
  de cartão, CPF, número ou código de segurança no banco, logs ou comprovantes.
- Crédito e débito são separados no formulário. O débito só fica disponível se
  os meios ativos da aplicação incluírem essa modalidade. Não há aprovação
  local alternativa quando o provedor não a oferece.
- Pix usa `/v1/payments` com `payment_method_id=pix`, sem redirecionar para
  uma conta real. Nos testes de meios offline do Bricks, a documentação prevê
  estado pendente. Um Pix pendente não gera crédito, mesmo com senha correta.
- Criação é seguida de **GET do pagamento no servidor**. A resposta do navegador
  ou um aviso recebido por Webhook não autoriza crédito por si só.

Essa modalidade preserva os componentes seguros de cartão e a API com
`live_mode` explícito, sem adicionar um checkout externo com credenciais de
produção de conta de teste. Não utiliza Orders API nem suas regras diferentes
para testes de Pix.

Fontes oficiais consultadas em 17/11/2026:
[Bricks: testes](https://www.mercadopago.com.br/developers/pt/docs/checkout-bricks/integration-test/test-payment-flow),
[Card Payment Brick](https://www.mercadopago.com.br/developers/pt/docs/checkout-bricks/card-payment-brick/default-rendering),
[meios aceitos](https://www.mercadopago.com.br/developers/pt/docs/checkout-bricks/card-payment-brick/advanced-features/configure-payment-methods),
[Pix com Payments](https://www.mercadopago.com.br/developers/pt/docs/checkout-bricks/payment-brick/payment-submission/pix).

## Credenciais e prova do ambiente

Use **Public Key e Access Token de Credenciais de teste** da aplicação
**Checkout Transparente / API Pagamentos**, sem ativar produção. O Access Token
fica somente no arquivo privado do servidor, nunca no navegador ou nos logs.

O fluxo segue o teste de cartões do Bricks: credenciais de teste da conta real,
cartões oficiais e e-mail fictício diferente do vendedor. Não use endereços de
contas `testuser.com` como pagador nesse fluxo. Não é necessário cadastrar
clientes na API Clientes; a dependência adicionada anteriormente foi retirada
após o retorno 150 (usuários incompatíveis) na hospedagem.

### Preparação não é autorização de pagamento

1. O servidor confere o formato TEST, aplicação/coletor e autentica a conta
   com `GET /users/me`. Isso libera **somente preparar e tokenizar o cartão**.
   A resposta ainda informa `environment_verified=false`; não inventa uma
   confirmação de `live_mode` e mantém Pix bloqueado.
2. O cartão é tokenizado pelo Card Payment Brick oficial. PAN/CVV não passam
   pelos campos da aplicação. Após a confirmação com usuário/senha do Admin,
   o servidor consulta `GET /v1/card_tokens/{id}` autenticado.
3. Antes de qualquer POST de pagamento, exige ID correspondente, booleano
   `live_mode=false`, estado `active`, validade futura e cartão oficial de
   teste. Se a API devolver Public Key não nula, ela também deve corresponder;
   o campo não é obrigatório nos modelos atuais do SDK. O acesso ao recurso
   é autenticado com a credencial da aplicação/coletor já conferidos.
4. Só depois desse atestado permite enviar o pagamento. Falta de prova,
   token inválido, recusa da API ou falha de armazenamento bloqueiam o envio.
5. Aprovação na criação não gera saldo: a consulta independente do pagamento
   ainda precisa confirmar ambiente, coletor, valor, moeda, referência, método,
   metadados e captura. O livro continua atômico e idempotente.

Uma prova privada de até **cinco minutos** libera Pix de teste. Ela vem da
consulta do token acima ou de um pagamento de teste do mesmo coletor,
consultado no servidor. O Pix não é liberado apenas por autenticar ou digitar
senha. Se a prova expirar, faça outro teste com cartão ou consulte um pagamento
de teste já existente. Essa exigência inicial é uma proteção deste módulo,
**não uma regra geral do Pix no Mercado Pago**. Sem cartão disponível e sem
prova anterior, o módulo permanece bloqueado.

O atestado em `storage/mercadopago-test-environment.json` usa permissão 0600,
validade curta e fingerprint das chaves. Guarda somente origem da prova,
coletor, fingerprint, expiração e `live_mode=false`: nenhum token de cartão,
PAN, CPF, ID do recurso ou Access Token é persistido nele. Troca de chave,
aplicação ou coletor invalida a prova. Nunca altere esse arquivo manualmente.

Fontes oficiais conferidas em 20/11/2026:
[Bricks: fluxo de teste](https://www.mercadopago.com.br/developers/pt/docs/checkout-bricks/integration-test/test-payment-flow),
[consulta do token no SDK oficial](https://github.com/mercadopago/sdk-php/blob/master/src/MercadoPago/Client/CardToken/CardTokenClient.php),
[metadados documentados do token](https://github.com/mercadopago/sdk-go/blob/master/pkg/cardtoken/response.go),
[credenciais por ambiente](https://www.mercadopago.com.br/developers/pt/docs/checkout-bricks/additional-content/your-integrations/credentials).
A documentação foi consultada; as consultas com as credenciais do usuário
continuam dependendo de homologação na hospedagem.

### Configuração privada

No `config/local.php`, dentro do array retornado, preserve banco/SMTP e use:

```php
'payments_test' => [
    'enabled' => false, // true somente após configurar e instalar os arquivos
    'mode' => 'test',
    'credential_source' => 'test_credentials',
    'public_key' => '', // Public Key TEST-... da tela Credenciais de teste
    'access_token' => '', // segredo TEST-... da mesma aplicação, somente servidor
    'client_id' => '', // Número da aplicação, entre aspas
    'collector_id' => '', // User ID mostrado em Informações gerais, entre aspas
    'max_amount' => '1000.00',
    'payer_email' => 'pagador.teste@example.com', // fictício, diferente do vendedor, sem testuser.com
    'webhook_secret' => '', // assinatura de teste opcional
],
```

No Mercado Pago Developers, abra **Integrações → aplicação → Informações gerais**
para o Número da aplicação e User ID; em **Credenciais de teste**, copie as
chaves completas para os campos de mesmo nome. Não coloque Access Token em
Client Secret nem Public Key em Client ID. Os identificadores devem ser
strings de dígitos. Pode usar `getenv('MP_TEST_ACCESS_TOKEN') ?: ''` se a conta
permitir variável privada. Esta modalidade aceita somente chaves TEST da
Payments API, não credenciais APP_USR de outros produtos ou de produção.

OAuth anterior continua compatível: `credential_source=oauth` usa Client
ID/Secret, solicita `test_token=true` e exige `live_mode=false` na resposta.
Seu fingerprint foi preservado; não há fallback de credenciais diretas
incompletas para OAuth. Resolva operações pendentes antes de trocar o conjunto
de chaves. O cache OAuth anterior permanece privado e separado.

Exige cURL, TLS/CA válidos e saída HTTPS para `api.mercadopago.com` somente
para este módulo. Após preencher, use `enabled=true` e abra a área. O
[diagnóstico CLI](../bin/check-payment-test.php) não cria pagamento e informa
se a área permite preparar o cartão e se o ambiente já foi comprovado:

```sh
php bin/check-payment-test.php
```

`enabled=true` no diagnóstico significa que a preparação está disponível.
`environment_verified=false` mantém envio de Pix e qualquer mutação sem prova
bloqueados no servidor. Não altere flags para simular aprovação.

## Fluxo e destinos

1. Escolha o valor de teste e o destino: **Minha carteira**, **Tesouraria** ou
   **Receitas da Galeria**.
2. Escolha um meio disponível. Para cartão, preencha apenas os dados oficiais
   de teste dentro do componente seguro do Mercado Pago. Pix não pede cartão.
3. Confira o resumo: valor em R$ de teste, crédito experimental em mR$,
   destino e meio. Informe o e-mail e a senha **do Admin desta sessão** e
   marque a confirmação. Alterar condições exige novo resumo e confirmação.
4. O servidor valida a identidade, protege a referência/chave e envia o teste.
   Sua senha não aprova o pagamento no provedor.
5. O servidor consulta o Mercado Pago. Só credita após aprovação capturada,
   `live_mode=false` e correspondência de valor, BRL, referência, coletor,
   método, modalidade e metadados da operação.
6. Confira o estado e o comprovante. Há consultas automáticas limitadas e a
   opção **Consultar no Mercado Pago**. Operações não aprovadas não creditam.

Todos os valores do jogo são fictícios. O mesmo número de centavos de BRL de
**teste** é lançado em MR, identificado como crédito experimental. Isso não
representa compra de moeda, câmbio, recebimento bancário ou saldo resgatável.

Os destinos são as contas canônicas já existentes:

| Escolha | Conta |
|---|---|
| Minha carteira | `U:ID_DO_ADMIN:MR:wallet` |
| Tesouraria | `T:MR:treasury` |
| Receitas da Galeria | `T:MR:galeria_revenue` |

A contrapartida é `T:MR:issuance:mercadopago_test`, no livro contábil atual,
com tipo `PAYMENT_TEST_CREDIT` e referência única. Não insere venda de Moldura
nem duplica o registro de receita. A tela separa o saldo total do destino e o
acumulado de créditos experimentais. Carteira/extrato e conciliação existentes
refletem o lançamento. O crédito pode financiar prêmios e circular no jogo
porque **toda a economia atual usa moeda simulada, sem resgate externo**.

## Aprovação, recusa e pendência com cartões

Consulte sempre a lista atual de
[cartões oficiais de teste](https://www.mercadopago.com.br/developers/pt/docs/checkout-bricks/integration-test/test-cards).
Não use cartões reais. Use o CPF fictício `12345678909`, uma parcela e um e-mail
de teste diferente do vendedor. No nome do titular do componente:

| Nome de teste | Resultado a verificar |
|---|---|
| `APRO` | Aprovação; crédito somente após a consulta válida do servidor |
| `OTHE` | Recusa; nenhum crédito |
| `CONT` | Pendência; nenhum crédito enquanto não houver aprovação do provedor |

A proteção do servidor confere ambiente/Public Key do token e os dados
parciais dos cartões de teste publicados na data acima, sem persistir esses
dados. Se a lista oficial mudar, atualize e homologue a lista de teste em
`MercadoPagoSandbox::card`; não retire a proteção para aceitar cartão real.
A disponibilidade de débito depende da aplicação; se indisponível, a opção
fica desabilitada e nenhum teste de débito é apresentado como realizado.

## Pix: como testar e o que falta no sandbox

Escolha Pix, confira o resumo e autorize. O servidor cria o pagamento **de
teste** e consulta seu estado. O comportamento documentado para meios offline
no Bricks é pendência. **Não há botão local para aprovar Pix** e a confirmação
da senha não libera saldo.

Se a consulta de teste retornar uma imagem PNG de QR Code e/ou código, a tela
os identifica como dados do provedor no sandbox. Eles não são fabricados nem
armazenados pelo app. **Não pague nem escaneie em aplicativo bancário.** Se o
provedor não enviar a imagem, a tela informa a ausência e não desenha um QR
Code fictício de cobrança. O teste não reproduz uma liquidação bancária real.

Não há uso de credenciais de produção para completar essa etapa. Esta versão
não implementa uma aprovação local de Pix nem mistura um mock ao funcionamento
instalado. O caminho de crédito após Pix aprovado foi exercitado somente com
respostas locais controladas, identificadas no relatório de testes.

## Respostas perdidas, cancelamento e notificações

O envio cria uma referência externa e uma chave de idempotência. Duplicatas
recuperam o mesmo registro; consultas não reenviam pagamento. Sem resposta,
o estado fica desconhecido até consultar o recurso ou buscar a referência no
provedor. A ausência imediata em uma busca não significa recusa ou aprovação. Uma resposta
definitiva de rejeição da solicitação, antes de obter ID de pagamento, aparece
como falha de envio; timeout e respostas ambíguas permanecem desconhecidos.

No navegador, somente a chave aleatória da tentativa pendente é preservada
na `sessionStorage`; não há token, senha ou valor financeiro nesse registro.
Depois de recarregar, **Consultar tentativa** recupera o registro. Se o envio
ainda não foi registrado, pode cancelá-lo com e-mail/senha. Uma marca no banco
impede que a mesma requisição atrasada seja enviada depois do cancelamento.
Se o pagamento já existe, a tela mostra seu estado e não presume cancelamento.

Testes pendentes com ID no provedor permitem solicitar cancelamento. O servidor
consulta o estado antes e depois, e respeita as regras do Mercado Pago. Se o
provedor já aprovou, o sistema concilia a aprovação; não declara cancelamento.
Estorno/chargeback após crédito sinaliza **revisão administrativa**, sem débito
automático de recursos já usados no jogo.

Webhook é opcional: configure o tópico **Pagamentos**, no ambiente de teste da
aplicação, e um segredo privado. A URL desta instalação é:

```text
https://aquino.bsb.br/payment-test-webhook.php
```

A assinatura HMAC `x-signature`, `x-request-id`, `data.id` e o timestamp são
verificados. O corpo não autoriza crédito: o servidor consulta o pagamento
com sua própria credencial. Repetições não duplicam o lançamento. Eventos
incompatíveis com a operação ou o coletor são bloqueados.

A entrega de notificações depende do produto/ambiente/permissões do provedor.
As consultas autenticadas do servidor funcionam como alternativa; não dependa
de Webhook sem homologar sua entrega no sandbox. Referência:
[notificações oficiais](https://www.mercadopago.com.br/developers/pt/docs/checkout-api-payments/additional-content/your-integrations/notifications/webhooks).

## Instalação na Superdomínios

1. Faça backup. Preserve `config/local.php`, todo o `storage/` e suas chaves.
   Use uma janela sem operações da turma para enviar o conjunto de arquivos.
2. Baixe [Piscina_Pagamentos_MercadoPago_Teste.zip](../downloads/Piscina_Pagamentos_MercadoPago_Teste.zip)
   usando **Download raw file** no GitHub. Confira
   [PAGAMENTOS_TESTE_SHA256SUMS.txt](../downloads/PAGAMENTOS_TESTE_SHA256SUMS.txt).
3. No phpMyAdmin, selecione o banco atual. Com as migrações até 006 já aplicadas,
   importe **somente `database/migrations/007_pagamentos_teste.sql`**.
   Não importe `database/install.sql` num banco preenchido.
4. O ZIP é um overlay compatível da aplicação atual. Envie `app/`, `bin/` e
   `docs/` para a raiz privada `/home/oftalmol/aquino.bsb.br/`; o conteúdo de
   `public/` vai ao seu `public/`. Não coloque pastas privadas na raiz pública.
   Envie estáticos antes dos PHP integrados e `public/sw.js` por último.
   A pasta `config/` contém **somente o modelo atualizado `example.php`**,
   com `payments_test` desativado. Esse arquivo serve para consulta e pode
   atualizar o modelo na pasta privada; **não o renomeie nem o copie por cima
   de `config/local.php`** da instalação que já funciona. O arquivo privado
   `local.php` e suas credenciais não são incluídos no ZIP.
5. Preserve a configuração existente. Acrescente o bloco privado mostrado
   acima, começando com `enabled=false`. Configure cURL, as credenciais de
   teste da aplicação, coletor e, opcionalmente, Webhook; só então habilite.

   Para configurar pelo cPanel, abra **Gerenciador de arquivos →
   aquino.bsb.br → config → local.php → Editar**. Dentro do `return [...]`,
   acrescente somente o bloco `payments_test` do modelo, preservando banco,
   SMTP e os demais valores. Configure `credential_source=test_credentials`,
   Public Key, Access Token, Número da aplicação e User ID. A ativação é
   `'enabled' => true` **dentro desse bloco**, mantendo `'mode' => 'test'`;
   não é feita no `example.php`.

6. Use **Atualizar app** no PWA. Confira a navegação normal e o painel Admin.
   Homologue os testes com dados fictícios antes de usá-los nos saldos do jogo.
   Nenhum novo cron, Node, Docker, Composer ou processo permanente é necessário.

### Ordem no GitHub e na hospedagem

A correção das credenciais foi acrescentada à PR #16 enquanto ela ainda estava
aberta; não é preciso fazer merge da versão anterior antes. Faça o merge da
PR atualizada e depois envie o pacote completo da atualização ao servidor,
preservando `config/local.php` e `storage/`. Só então configure as chaves e
ative. Se a 007 já estiver instalada, **esta correção não exige nova migração**.

A 007 é reaplicável, só cria três tabelas novas e registra a versão ao final.
Não muda carteiras, contratos, prêmios, cadastros ou saldos anteriores. DDL
MySQL tem commits implícitos: se falhar, corrija e reaplique sem marcar a versão
manualmente. Para desativar, use `enabled=false`; registros históricos permanecem
consultáveis. Depois de usar, mantenha código compatível; não apague lançamentos
nem restaure um banco antigo sobre transações posteriores.

## Atualização após o código 150 no cadastro técnico

O retorno 150 na API Clientes vinha de uma dependência de verificação adicional
do módulo, que não é necessária para o teste com Card Payment Brick. O fluxo
atual usa o token oficial e **não cria nem consulta clientes técnicos**.

Baixe [Piscina_Sandbox_Bricks_MercadoPago.zip](../downloads/Piscina_Sandbox_Bricks_MercadoPago.zip)
e substitua o conjunto de seis arquivos na raiz privada
`/home/oftalmol/aquino.bsb.br/`:

- `app/MercadoPagoSandbox.php`
- `app/MercadoPagoTestHttp.php`
- `app/PaymentTests.php`
- `public/admin-payments.php`
- `public/assets/admin-payments.js`
- `bin/check-payment-test.php`

Guarde backup dos arquivos. Envie o JavaScript primeiro, os PHP de app/bin em
seguida e a página Admin por último; depois recarregue a área. A página usa
versão própria da URL do módulo, sem mudar o Service Worker ou o app normal.
Não há SQL novo, alteração de chaves, Webhooks, banco ou armazenamento.
Preserve `config/local.php` e `storage/`. Não basta trocar apenas um PHP.

Os pacotes com os nomes anteriores de diagnóstico/cadastro, na branch atual,
passaram a conter esse mesmo conjunto para evitar versões misturadas. Links
antigos fixados em commits anteriores continuam sendo pacotes históricos;
use o pacote acima desta revisão. Os overlays completos também foram refeitos.

Se houver recusa, o aviso identifica etapa, status HTTP e códigos conhecidos,
sem corpo bruto, tokens, IDs ou dados pessoais. Causas específicas têm
prioridade sobre erro genérico. Mensagens reconhecidas viram textos fixos
seguros; não se presume erro nas credenciais. Copie somente o aviso sanitizado.

## Arquivos e tabelas

| Grupo | Responsabilidade |
|---|---|
| `app/MercadoPagoSandbox.php`, `MercadoPagoTestHttp.php`, `PaymentTestTransport.php` | Credenciais diretas, prova por token/pagamento, compatibilidade OAuth, transporte TLS e consulta da Payments API |
| `app/PaymentTests.php`, `PaymentTestAuth.php`, `PaymentTestLedger.php` | Orquestração, identidade, validação do provedor e crédito atômico no livro atual |
| `app/Domain.php`, `public/api.php` | Serviço financeiro interno e bloqueio de acesso pela API geral |
| `public/admin-payments.php`, `admin-payments-api.php`, `payment-test-webhook.php` | Área Admin com CSP própria, endpoints privados e notificação verificada |
| `public/assets/admin-payments.js`, `admin-payments.css` | Fluxo responsivo, comprovante e recuperação de requisição |
| `public/assets/app.js`, `app.css`, `public/sw.js` | Link do Admin, legenda do extrato e versão dos estáticos; CSP do app normal preservada |
| `config/example.php`, `bin/check-payment-test.php` | Configuração desativada e diagnóstico sem criar pagamento |
| `database/migrations/007_pagamentos_teste.sql`, `database/install.sql` | Atualização e instalação nova |
| `tests/payment-test-*`, `tests/run.php` | Testes locais de segurança, finanças, migração e UI |

Tabelas novas: `payment_test_intents` (condições, provedor, prova validada e
lançamento), `payment_test_events` (histórico sem dados sensíveis) e
`payment_test_abandoned` (cancelamento de requisição ainda não registrada).
O crédito usa `accounts`, `ledger_transactions`, `ledger_entries`, `operations`
e `audit_events` existentes. A transação final muda o estado local para
aprovado e registra saldo, partidas, operação e evento juntos. Se falhar, fica
aprovado no provedor e **aguardando crédito**, com nenhum lançamento parcial.

## O que foi verificado e o que ainda depende do Mercado Pago

Leia [PAGAMENTOS_TESTE_VALIDACAO.md](PAGAMENTOS_TESTE_VALIDACAO.md). Não foram
fornecidas credenciais neste workspace: **nenhuma transação nem autenticação
OAuth real foi executada no Mercado Pago**. A documentação oficial foi consultada;
a lógica e os cenários foram exercitados com transporte HTTP controlado e
MySQL real. O componente real de cartão e a entrega de Webhooks precisam de
homologação na sua aplicação, assim como a prova por token, débito e Pix
no sandbox; OAuth também depende de homologação se optar pelo caminho antigo.

Esta área não habilita pagamentos reais. Uma futura versão exige desenho
financeiro separado da moeda simulada, autorização explícita para cobranças
reais, regras de conversão/resgate, tratamento de taxas, estornos/chargebacks,
conciliação e auditoria de produção, atendimento, privacidade, revisão de
segurança e homologação dos meios comerciais. Não basta trocar a credencial
ou o valor de `mode` nesta implementação.
