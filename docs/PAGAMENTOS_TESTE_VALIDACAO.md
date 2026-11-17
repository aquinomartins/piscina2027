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
configuração desligada/inconsistente; prova OAuth estrita, sem confiar no
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

Depois de instalar/configurar em ambiente próprio: validar OAuth com
`live_mode=false` e coletor esperado; Card Payment Brick real com cartões
oficiais APRO/OTHE/CONT; disponibilidade real de débito; comportamento do Pix,
QR retornado e persistência de pendência; consulta por referência; Webhook de
teste quando entregue; TLS/cURL e limites da Superdomínios; PWA e dispositivo
físico/Safari. Não há aprovação de Pix local como substituta dessa homologação.

Resultados brutos: [RESULTADOS_PAGAMENTOS_TESTE.txt](RESULTADOS_PAGAMENTOS_TESTE.txt).
