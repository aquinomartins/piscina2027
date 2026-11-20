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

O caminho padrão aceita **Public Key e Access Token de Credenciais de teste**
da aplicação **Checkout Transparente / API Pagamentos**, como na tela atual do
Mercado Pago. Não exige ativar produção nem preencher Client Secret. Copie
as duas chaves da **mesma aplicação**, somente no arquivo privado do servidor.
Não existe formulário para enviar Access Token pelo navegador.

O prefixo `TEST-` é uma barreira inicial, **não é a prova do ambiente**:

1. O token precisa corresponder ao Número da aplicação e ao User ID configurados,
   conforme o formato documentado de tokens da Payments API.
2. O servidor autentica `GET /users/me` e exige o coletor esperado e país `MLB`.
3. Busca um cadastro técnico fictício na API Clientes; se ainda não existir,
   cria **somente esse cadastro, sem cartão e sem cobrança**, com e-mail
   `test_payer_1@testuser.com`, dentro do formato sandbox indicado pela API.
   O POST envia **somente `email`**. Se o cadastro já existir, apenas consulta
   e confirma o estado; não modifica o cadastro nem seus cartões/metadados.
4. Exige `live_mode === false` no cadastro e confirma os mesmos dados por
   `GET /v1/customers/{id}` antes de permitir qualquer envio de pagamento.

Se a API Clientes não estiver disponível, faltar permissão ou não retornar
explicitamente o booleano `false`, o módulo fica **bloqueado**. Não retira a
verificação nem cria uma cobrança para descobrir o ambiente. O diagnóstico
pode criar esse cliente técnico, mas não cria intenção/pagamento/saldo.
O cliente técnico é reutilizado; resposta perdida é recuperada pela busca,
sem repetir o POST. Não há dados pessoais nem cartão nesse cadastro.

Uma Public Key incompatível também impede o cartão: antes do POST de pagamento,
o servidor confere o token gerado pelo Brick, `live_mode=false`, a Public Key
esperada e o cartão oficial de teste. Cada pagamento ainda exige consulta
independente e todas as conferências financeiras já descritas neste guia.
O caminho da Payments API aceita somente chaves `TEST-`; tokens `APP_USR` de
outras modalidades não são aceitos como alternativa.

Referências:
[API Clientes: cadastro e `live_mode`](https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-api-payments/customers/create-customer/post),
[consulta de credenciais e `/users/me`](https://www.mercadopago.com.br/developers/pt/docs/smartapps/resources/credentials),
[contrato da API OAuth](https://www.mercadopago.com.br/developers/pt/reference/authentication/oauth/_oauth_token/post),
[credenciais por ambiente](https://www.mercadopago.com.br/developers/pt/docs/checkout-bricks/additional-content/your-integrations/credentials).

Documentação conferida também em 20/11/2026. As chamadas com as credenciais
da sua aplicação precisam ser homologadas; consulte o relatório de validação.

No **arquivo privado `config/local.php`**, acrescente um único bloco ao array
retornado. Preencha os segredos somente no servidor, fora de `public/` e do Git:

```php
'payments_test' => [
    'enabled' => false, // mude para true somente após configurar os dados abaixo
    'mode' => 'test',  // nenhum modo de produção é implementado nesta área
    'credential_source' => 'test_credentials',
    'public_key' => '', // Public Key TEST-... da tela Credenciais de teste
    'access_token' => '', // Access Token TEST-... da mesma tela; segredo exclusivo do servidor
    'client_id' => '', // Número da aplicação, entre aspas
    'collector_id' => '', // User ID mostrado em Informações gerais da aplicação, entre aspas
    'max_amount' => '1000.00', // limite técnico por teste; decimal com ponto
    'payer_email' => 'pagador.teste@example.com', // e-mail fictício diferente do vendedor
    'webhook_secret' => '', // opcional; segredo das notificações de teste
],
```

No Mercado Pago Developers, abra **Integrações → sua aplicação → Informações
gerais**: o **Número da aplicação** corresponde a `client_id` e o **User ID**
a `collector_id`. Ambos são strings de dígitos, entre aspas. Em **Credenciais
de teste**, copie a Public Key e o Access Token completos para os campos de
mesmo nome. Não coloque Access Token em `client_secret`, nem Public Key em
`client_id`; não use o identificador de um usuário da Piscina.

Pode usar `getenv('MP_TEST_ACCESS_TOKEN') ?: ''` no lugar do valor se a conta
permitir variáveis de ambiente privadas. Nunca coloque credenciais em arquivos
JavaScript, URLs, prints de tela, tickets públicos ou no ZIP.

O Access Token fica somente em `config/local.php` ou na variável privada.
O atestado é guardado por até cinco minutos em
`storage/mercadopago-test-environment.json`, com permissão 0600 e sem copiar
as chaves para o cache. Um lock privado evita cadastros simultâneos no mesmo
servidor. Troca de chave, aplicação ou coletor invalida o atestado. Preserve
o caráter privado do `storage/`. Somente a Public Key é entregue ao Brick.

Instalações OAuth anteriores continuam compatíveis: `credential_source=oauth`
usa Client ID/Client Secret, solicita `test_token=true` e exige `live_mode=false`
e o coletor na resposta. O formato do fingerprint anterior foi preservado.
Se `credential_source` estiver ausente, a presença de `public_key` ou
`access_token` seleciona o caminho direto; sem esses campos, mantém OAuth.
Não existe fallback para OAuth quando credenciais diretas estão incompletas.
Resolver operações pendentes antes de trocar o conjunto de credenciais evita
bloqueá-las pela proteção de fingerprint. O cache OAuth anterior continua
privado em `storage/mercadopago-test-credentials.json`, com permissão 0600.

Exige cURL com TLS/CA válidos e saída HTTPS para `api.mercadopago.com`. Se faltar
cURL ou a comunicação/prova do ambiente falhar, o módulo não libera operações.
Essa extensão adicional não se torna requisito para as telas normais do app.

Após preencher, mude `enabled` para `true` e abra a área. O diagnóstico não cria
pagamentos. Com Terminal/SSH, também pode usar:

```sh
php bin/check-payment-test.php
```

Se a verificação falhar, confira a mensagem administrativa, a aplicação e as
permissões da API Clientes no provedor. A prova do ambiente, o componente real
e as permissões ainda precisam ser homologados com suas credenciais de teste.

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

## Diagnóstico de recusa do provedor

Uma recusa na verificação inicial não comprova erro nas chaves: algumas APIs
podem estar indisponíveis ou não autorizadas para a credencial/produto.
O aviso agora identifica **Etapa**, **HTTP** original do provedor e **Código**
seguro, por exemplo `autenticação da conta (/users/me)`, HTTP 403.
Não devolve mensagem/corpo bruto, consultas com e-mail, IDs, tokens ou dados de
cartão. Códigos são uma lista fechada; valores desconhecidos ficam como
`não informado`. O tipo local de erro permanece igual para não transformar
uma resposta incerta de pagamento em recusa definitiva.

Quem já instalou a versão de credenciais diretas pode usar
[Piscina_Diagnostico_MercadoPago.zip](../downloads/Piscina_Diagnostico_MercadoPago.zip):
guarde uma cópia e substitua **somente**
`/home/oftalmol/aquino.bsb.br/app/MercadoPagoTestHttp.php`. Recarregue a área
e compartilhe somente o aviso sanitizado. O pacote tem o arquivo, instruções
e hashes, sem configuração privada. Não exige SQL, mudanças nas chaves ou
ativação de produção. A recusa continua bloqueando o módulo; o diagnóstico
serve para identificar a causa com a resposta real da conta do usuário.

### Ajuste do cadastro técnico após HTTP 400

O aviso da hospedagem identificou HTTP 400 na criação do cadastro técnico;
autenticação e busca haviam passado. `bad_request` sozinho não identifica o
campo recusado. O POST foi reduzido a `{"email":"test_payer_1@testuser.com"}`,
sem descrição opcional e sem o identificador longo gerado anteriormente.
O endereço atende ao formato `test_payer_[0-9]{1,10}@testuser.com` indicado
no erro 128 da [referência da API Clientes](https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-api-payments/customers/create-customer/post).
Essa alteração de compatibilidade ainda precisa ser validada na conta.

Quem já instalou o módulo pode aplicar
[Piscina_Correcao_Cadastro_MercadoPago.zip](../downloads/Piscina_Correcao_Cadastro_MercadoPago.zip),
substituindo somente `app/MercadoPagoSandbox.php` e `app/MercadoPagoTestHttp.php`
na raiz privada. Não há SQL novo nem mudança de chaves/configuração. O
diagnóstico prioriza códigos específicos em `cause`, `causes` ou `errors`
antes do genérico `bad_request` e traduz algumas frases conhecidas em textos
fixos seguros; mensagens recebidas e dados sensíveis nunca são devolvidos.
As exigências de coletor, `live_mode=false`, cartão de teste, consulta do
pagamento e crédito atômico continuam obrigatórias.

## Arquivos e tabelas

| Grupo | Responsabilidade |
|---|---|
| `app/MercadoPagoSandbox.php`, `MercadoPagoTestHttp.php`, `PaymentTestTransport.php` | Credenciais diretas de teste, prova na API Clientes, compatibilidade OAuth, transporte TLS e consulta da Payments API |
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
homologação na sua aplicação, assim como a prova na API Clientes, débito e Pix
no sandbox; OAuth também depende de homologação se optar pelo caminho antigo.

Esta área não habilita pagamentos reais. Uma futura versão exige desenho
financeiro separado da moeda simulada, autorização explícita para cobranças
reais, regras de conversão/resgate, tratamento de taxas, estornos/chargebacks,
conciliação e auditoria de produção, atendimento, privacidade, revisão de
segurança e homologação dos meios comerciais. Não basta trocar a credencial
ou o valor de `mode` nesta implementação.
