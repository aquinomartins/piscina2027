# Diagnóstico de SMTP sem envio

Quando `outbox` fica em `RETRY`, o registro de transporte é genérico. Use
`bin/diagnose-mail.php` para testar configuração, conexão, TLS e autenticação
com a mesma biblioteca e configuração SMTP da aplicação.

O script é exclusivo de CLI. Mantenha-o em `bin/`, fora da raiz pública.
Ele não acessa o banco, altera filas, envia mensagens, nem valida a aceitação
de remetentes/destinatários ou a entrega na caixa postal. Não desativa a
verificação do certificado e não imprime senha, usuário, respostas brutas
do servidor nem tokens. O host aparece no resultado.

Execute usando o mesmo PHP CLI e ambiente do cron, por exemplo:

```sh
/CAMINHO/PHP /CAMINHO/PROJETO/bin/diagnose-mail.php
```

Sem terminal, substitua temporariamente somente o comando do cron de 15 minutos:

```sh
/CAMINHO/PHP /CAMINHO/PROJETO/bin/diagnose-mail.php > /CAMINHO/PRIVADO/piscina-smtp.log 2>&1
```

Depois de coletar uma execução, restaure `bin/cron.php` no agendador. O teste
nunca substitui o processamento da fila.

| reason | Ação |
|---|---|
| SMTP_FIELDS_EMPTY | Preencher os campos listados em missing_fields no config/local.php efetivamente usado pelo cron. |
| SMTP_CONFIGURATION_INVALID | Conferir transporte smtp, remetente, porta e encryption ssl/tls. |
| APP_CONFIGURATION_OR_RUNTIME_FAILED | Executar bin/diagnose.php com esse PHP e conferir configuração, extensões e sintaxe. |
| SMTP_CONNECTION_FAILED | Conferir host/porta e signals para DNS, conexão recusada ou certificado. |
| SMTP_TLS_FAILED | Conferir STARTTLS/porta e certificado; não desativar validação TLS. |
| SMTP_GREETING_FAILED | Conferir se o endereço/porta corresponde a um servidor SMTP. |
| SMTP_AUTH_FAILED | Conferir conta de e-mail, senha e autenticação permitida; código 535 indica recusa de autenticação. |
| SMTP_CONNECTION_AND_AUTH_OK | Conexão e autenticação passaram; retomar cron.php e conferir aceitação do envio e entrega. |

O código de saída é 0 para sucesso e 1 para falha. Avisos de socket/TLS são
convertidos em rótulos; o resultado não é um transcript completo do SMTP.
Para uma conta que usa SMTP sem autenticação ou um método externo de OAuth,
este teste de usuário/senha não valida o fluxo específico.
