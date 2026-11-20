# Validação da área de pagamentos de teste

**Nenhum pagamento, OAuth, tokenização de cartão ou Webhook real foi executado
com o Mercado Pago.** Não há credenciais do usuário no ambiente de desenvolvimento.
Consulta à documentação não equivale à homologação de transações.

## Camadas efetivamente exercitadas

| Camada | Ambiente / alcance |
|---|---|
| Domínio e livro | PHP 8.3.36 e MySQL 8.0.46 reais; transporte HTTP local controlado no ponto de integração |
| Concorrência | Conexões/processos PHP independentes no MySQL; um único crédito |
| Falha financeira | Trigger injetada durante `ledger_entries`; rollback integral e nova conciliação |
| Autorização HTTP | Endpoints PHP reais; sessão, role, CSRF, e-mail/senha, flag desligada, crédito interno inacessível |
| UI ativa | JavaScript/CSS reais com respostas de API e componente SDK controlados no Playwright |
| Upgrade | Base 006 real de teste, com associação e prêmio direto pago; 007 reaplicada e dados comparados |
| Mobile | Chromium a 360, 390 e 430 px; desktop a 1440 px; não é teste em dispositivo físico |
| Mercado Pago | Documentação oficial consultada; nenhuma operação de conta realizada |

## Cenários financeiros

`tests/payment-test-domain.php` integra a suíte existente. Cobertura:
configuração desligada/inconsistente; credenciais diretas, prova não financeira
com `live_mode=false` e compatibilidade OAuth estrita, sem confiar apenas no
prefixo; três meios × três destinos; números JSON exatos; ausência de crédito
em pendência/recusa/cancelamento; criação aparentemente aprovada com consulta
pendente; bloqueio de cartão/ambiente errado e divergências de valor/moeda,
referência, coletor, método e metadados; resposta perdida; busca por referência;
chaves repetidas/conflitantes; cancelamento de envio atrasado; concorrência;
assinatura/repetição de Webhook; revogação de permissão; estorno para revisão;
ausência de segredos/CPF/token nos registros; uso do crédito no fluxo de prêmio
já existente. Toda execução confere a conciliação do livro.

O transporte falso está somente em `tests/payment-test-fixtures.php`, injetado
pelo construtor do teste. Não há flag `mock`, seletor de cenário de aprovação ou
provedor simulado habilitável no app distribuído. Os nove casos de crédito,
inclusive Pix aprovado, são **simulações locais do contrato da API**, não
pagamentos aprovados pelo Mercado Pago.

Para reproduzir, use configuração privada com `environment=demo`, banco
**exclusivamente `piscina_test`**, armazenamento separado e as duas senhas de
demonstração privadas no ambiente. A suíte apaga/recria somente esse banco
descartável. Não execute na hospedagem ou com dados da turma.

```sh
PISCINA_CONFIG=/CAMINHO_PRIVADO/teste.php php tests/run.php
# Apenas os casos novos:
PISCINA_CONFIG=/CAMINHO_PRIVADO/teste.php PISCINA_TEST_FILTER='Pagamentos teste:' php tests/run.php
# Validação das rotas do transporte, interrompida antes da rede (exige cURL):
php tests/payment-test-http.php
```

## Navegador e segurança HTTP

Crie outra base vazia, `piscina_payments_ui`, com `environment=demo` e a flag de
pagamento **desativada**. Defina `PISCINA_DEMO_ADMIN_PASSWORD` e
`PISCINA_DEMO_PASSWORD` privadamente (mínimo 12 bytes), e armazenamento próprio:

```sh
PISCINA_CONFIG=/CAMINHO_PRIVADO/browser.php php tests/payment-test-demo.php
PISCINA_CONFIG=/CAMINHO_PRIVADO/browser.php php -S 127.0.0.1:8157 -t public
# Outro terminal, com as mesmas senhas privadas:
TEST_URL=http://127.0.0.1:8157 TEST_ARTIFACTS=/CAMINHO_PRIVADO/resultados node tests/payment-test-browser.cjs
```

A primeira parte chama PHP/MySQL reais para negar usuários comuns, sessão
anônima, CSRF ausente, identidade/senha inválidas e operação desligada. Também
verifica o acesso mobile do Admin e que o app normal não carrega o SDK externo.
A segunda usa interceptação explícita de API/SDK no navegador, exercitando
preparação, resumo, autenticação, estados, erros e comprovantes sem enviar dados
ao Mercado Pago. Capturas são demonstrações da interface com dados controlados:
[confirmação mobile](images/pagamento-teste-confirmacao-mobile.png) e
[comprovante mobile](images/pagamento-teste-comprovante-mobile.png).

`tests/payment-test-upgrade.php`, com a configuração de `piscina_test`, cria
`piscina_payment_upgrade` vazio e recusa banco preenchido. Instala 001–006,
cria contratos/prêmios de teste, compara os dados das 67 tabelas anteriores após
aplicar a 007 duas vezes e verifica ausência de novos créditos e divergências.
Não apaga a base de upgrade ao terminar.

## Homologação pendente

Depois de instalar/configurar em ambiente próprio: validar Public Key/Access
Token TEST, `/users/me` e cadastro técnico na API Clientes com `live_mode=false`
e coletor esperado; validar OAuth se mantiver o caminho antigo; Card Payment Brick real com cartões
oficiais APRO/OTHE/CONT; disponibilidade real de débito; comportamento do Pix,
QR retornado e persistência de pendência; consulta por referência; Webhook de
teste quando entregue; TLS/cURL e limites da Superdomínios; PWA e dispositivo
físico/Safari. Não há aprovação de Pix local como substituta dessa homologação.

Resultados brutos: [RESULTADOS_PAGAMENTOS_TESTE.txt](RESULTADOS_PAGAMENTOS_TESTE.txt).

## Revisão do pacote em 20/11/2026

O ZIP de atualização anterior excluía toda a pasta `config/`, embora o modelo
atualizado estivesse no repositório e nos pacotes completos. O gerador passou
a incluir explicitamente `config/example.php`, com `payments_test` desativado
e credenciais vazias. Somente esse modelo público é permitido na pasta
`config/` do overlay; `config/local.php` continua excluído.

A verificação desta revisão abrange o conteúdo do modelo, a presença do bloco
novo, a exclusão dos arquivos privados, CRC e hashes dos arquivos/pacotes e a
comparação dos arquivos de execução com o ZIP anterior. A aplicação e a lógica
de pagamentos não foram modificadas nesta correção de empacotamento. Não houve
nova transação ou homologação com o Mercado Pago.

## Credenciais diretas de teste — 20/11/2026

A tela real da aplicação criada pelo usuário fornece Public Key e Access Token
de teste. O módulo agora lê esse par de `config/local.php`, sem exigir Client
Secret e sem ativar produção. Mantém a lógica financeira e os endpoints Admin.

A prova anterior à cobrança autentica a conta, confirma o vínculo documentado
do token com aplicação/usuário e exige booleano `live_mode=false` no cadastro
técnico da API Clientes e em sua leitura independente. O cadastro tem somente
um e-mail técnico fictício e descrição; não é pagamento, não tem cartão e não
gera saldo. Cache privado por cinco minutos não guarda as chaves. Clientes
existentes são reutilizados; erros ambíguos buscam o recurso sem novo POST.
Falha/permissão insuficiente/resposta sem atestado bloqueiam a área.

Dez cenários adicionais cobrem bootstrap sem OAuth, campos/chaves incompatíveis,
coletor/país, `live_mode` inválido, leitura divergente, busca malformada, falta
de permissão, resposta perdida, cache/expiração/rotação e preservação do
fingerprint OAuth. Os 24 cenários financeiros existentes passaram a usar
credenciais diretas por padrão; os testes OAuth continuam explícitos.

`tests/payment-test-http.php` chama o transporte de produção com interceptação
somente antes de `curl_init`, em processo CLI separado. Exercita 11 rotas
válidas, host HTTPS fixo e nove rotas/métodos/consultas proibidos: 20 verificações,
sem abrir conexão. Não há interceptação nem alternativa simulada no runtime.

**Nenhuma credencial do usuário foi usada para testar.** As consultas ao
Mercado Pago e a criação do cliente técnico ainda precisam ser homologadas
com as permissões da aplicação criada. A documentação foi consultada; todos
os resultados do provedor nesta revisão foram simulados localmente.

Resultados desta revisão: **183 cenários completos sem falhas**, incluindo os
34 grupos de pagamentos; 20 verificações de rotas/host sem conexão; oito grupos
de navegador concluídos, com 360/390/430/1440 px e ausência de erros JavaScript.
O diagnóstico financeiro focado de 34 grupos também passou antes da regressão.
PHP 8.3.36 e MySQL 8.0.46 locais. SQL e lógica financeira existentes não foram
alterados nesta revisão; nenhum SQL foi executado na hospedagem.

## Diagnóstico de recusa — 20/11/2026

Um aviso real do Admin mostrou recusa genérica do provedor durante a
verificação inicial, sem intenção/pagamento registrado. A imagem não indica
qual endpoint foi recusado ou o status original; não atribuímos a causa às
chaves nem removemos a prova de ambiente.

O transporte agora devolve somente etapa fixa, status HTTP e código de uma
lista fechada. Nunca inclui o corpo/message do provedor, cabeçalhos, query
com e-mail, IDs de recursos ou dados de cartão. HTTP 400/401/403/404/405/406/422
continuam `test_provider_rejected`; falhas de rede, 429, 5xx e respostas
ambíguas continuam `test_provider_unavailable`. JSON inválido continua
`test_provider_invalid`. Assim, a classificação usada no fluxo financeiro
permanece preservada.

Verificados localmente **20 casos de rota/host e 26 casos adicionais** do
transporte real, com cURL interceptado somente no processo CLI de teste:
etapas distintas, TLS/redirects, códigos seguros, corpos com segredos
injetados, corpo inválido, status e resposta válida. Zero conexões externas.
A validação desta revisão abrange o transporte HTTP. A regressão financeira
completa de 183 cenários e as verificações de navegador foram executadas na
revisão anterior das credenciais. A lógica financeira, configuração, esquema
e prova de sandbox permaneceram iguais nesta revisão de diagnóstico.

Apenas `app/MercadoPagoTestHttp.php` mudou no runtime. O pacote mínimo de
diagnóstico e os pacotes atuais foram regenerados. A causa da recusa da conta
aguarda o aviso sanitizado da hospedagem; não há homologação externa nesta
revisão e não foram utilizadas credenciais do usuário.
