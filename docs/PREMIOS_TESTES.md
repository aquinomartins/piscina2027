# Validação dos prêmios de associações

Ambiente isolado: PHP 8.3.36, MySQL 8.0.46/InnoDB, Chromium via Playwright.
Todos os dados são sintéticos. Não houve SQL, envio de e-mails ou alterações
na conta da Superdomínios. Os testes de navegador usam PHP e MySQL reais;
os testes adicionais do menu PWA usam a API simulada já existente.

## Cobertura do servidor

`tests/premios-domain.php` integra a suíte `tests/run.php`: criação direta,
financiamento com contas existentes, aditivo bilateral, autorização, ausência
de cobrança, percentuais inválidos/ausentes, moeda, arredondamento, BYC com oito
casas, parcela zero, mesmo titular, recusa, contrato alterado, associação
suspensa, histórico imutável e preservação de contrato avançado/votação.

Os testes usam conexões/processos independentes para disputar o mesmo prêmio
com o mesmo destino ou destinos diferentes. Há também injeção de falha por
trigger no segundo crédito: o primeiro crédito e todos os registros da
tentativa são desfeitos; uma repetição válida pode concluir uma única vez.
Cada caso verifica a conciliação contábil.

Reprodução: use uma configuração privada com `environment=demo` e
**exclusivamente `dbname=piscina_test`**. Defina privadamente
`PISCINA_DEMO_ADMIN_PASSWORD` e `PISCINA_DEMO_PASSWORD` (pelo menos 12 bytes).
A suíte apaga/recria as tabelas desse banco descartável.

```sh
PISCINA_CONFIG=/CAMINHO_PRIVADO/teste.php php tests/run.php
```

`tests/premios-upgrade.php`, com a mesma configuração de teste, cria a base
separada `piscina_prize_upgrade` e exige que esteja vazia. Ele instala somente
001–005, cria usuários/carteiras, NFTs, Molduras, associação simples ativa,
acordo avançado e prêmio de votação financiado. Aplica a 006 duas vezes e
compara os hashes dos dados de todas as 64 tabelas anteriores, preservando
valores, contratos, estados e o modo VOTING. Não apaga o banco ao terminar.

```sh
PISCINA_CONFIG=/CAMINHO_PRIVADO/teste.php php tests/premios-upgrade.php
```

## Navegador e celular

Use outra configuração privada, ainda `environment=demo`, mas com o banco
**`piscina_prize_ui` vazio** e armazenamento separado. O gerador se recusa a
usar banco preenchido e não remove dados. As senhas vêm das duas variáveis
privadas acima; não há senha no repositório.

```sh
PISCINA_CONFIG=/CAMINHO_PRIVADO/browser.php php tests/premios-demo.php > /CAMINHO_PRIVADO/fixture.json
# Inicie o servidor com a mesma configuração:
PISCINA_CONFIG=/CAMINHO_PRIVADO/browser.php php -S 127.0.0.1:8155 -t public
# Em outro terminal, com as duas senhas no ambiente:
TEST_URL=http://127.0.0.1:8155 GALERIA_FIXTURE=/CAMINHO_PRIVADO/fixture.json TEST_ARTIFACTS=/CAMINHO_PRIVADO/resultados node tests/premios-browser.cjs
```

A jornada inclui criação/financiamento pela interface, recuperação de erro de
saldo com campos preservados, aditivo nas Pendências de cada parte, prévia
30/70, pagamento, créditos e histórico privado. Intercepta a rede antes de
enviar e depois de efetivar o pagamento: consulta/repete com a mesma chave,
sem duplicar. Consulta também as APIs sem sessão/permissão/CSRF/senha, e
confere o cache do PWA e a ausência de erros JavaScript.

As telas de aditivo e confirmação são verificadas a 360, 390 e 430 px, além
de 1440 px: sem rolagem horizontal da página ou diálogo, com controles novos
de pelo menos 44 × 44 px. Os screenshots são gerados em `TEST_ARTIFACTS`.

## Limites

MySQL da hospedagem, MariaDB, Safari/iOS e dispositivos físicos não foram
exercitados. A largura mobile foi simulada no Chromium. A conta compartilhada,
seus limites de CPU/conexões e a entrega SMTP precisam de validação no ambiente
final. Esta alteração não instala novos processos permanentes ou tarefas cron.

Resultados efetivos: [RESULTADOS_PREMIOS.txt](RESULTADOS_PREMIOS.txt).
