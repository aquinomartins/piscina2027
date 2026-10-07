# Arquitetura e integridade

## Organização

Monólito modular sem framework de produção. `public/index.php` gera HTML semântico e dados públicos iniciais; `assets/app.js` melhora navegação e implementa revisão/confirmacão assíncrona. Login/cadastro também dispõem de entrada `form.php` para formulários sem JavaScript. Fluxos econômicos guiados dependem de JavaScript, sem delegar autoridade financeira ao cliente.

- `Database`: PDO preparado, UTC, READ COMMITTED, transação com até três tentativas em 1205/1213.
- `Auth`: password_hash/verify, sessões no servidor, CSRF, limites de tentativa e tokens de e-mail/recuperação.
- `Domain`: autorizações, idempotência e transições econômicas.
- `Ledger`: partidas por ativo, carteiras, reservas, emissão e tesourarias.
- `Periods`: corte, snapshots, maiores restos e processamento em lotes.
- `Queries`: leituras públicas/privadas limitadas e paginadas.
- `Upload`: conteúdo real, GD, arquivos privados, associação única e limpeza.
- `Mailer` / `Tasks`: caixa de saída reprocessável e cron finito.

## Precisão

mR$ usa centavos em BIGINT: 1600 mR$ = 160000. BYC usa 100000000 unidades por BYC; cotas usam unidades inteiras. Entradas decimais são strings com ponto, sem separador de milhar. JSON transporta saldos/quantidades como strings. BCMath faz produtos, divisões e restos; JavaScript usa BigInt apenas para apresentação/revisão. O servidor recalcula tudo.

Limite por valor e saldo de carteira: 9000000000000000 unidades mínimas. Contas técnicas de emissão podem ser negativas, representam a contrapartida cumulativa e não são carteiras gastáveis. Transferências não podem misturar ativos. Total bilateral = arredondamento HALF_UP de quantidade mínima × preço em centavos / escala do ativo. A regra acompanha a versão; preço zero/total zero é permitido sem lançamento de pagamento.

## Transação econômica

1. Abrir InnoDB e bloquear `economic_gate` com FOR UPDATE.
2. Consultar idempotência `(actor,action,key)`. Mesmo hash retorna o resultado; hash diferente retorna 409. Arquivos de imagem usam hash do conteúdo e NFT, não nomes temporários.
3. Ler UTC do banco **depois** de adquirir o gate. Esse é o instante de eficácia lógico.
4. Capturar cortes vencidos antes de aplicar a nova mutação, usando somente estado comprometido e ordenado pelo gate.
5. Incrementar sequência; inserir operação. Liberar reservas expiradas relacionadas ao autor.
6. Revalidar autor, recurso, versão/prazo, saldo disponível e reservas. Bloquear linhas relevantes; contas são bloqueadas por ID em ordem.
7. Inserir lançamentos conservativos, atualizar agregados, propriedade e estados. Inserir auditoria/notificação na mesma transação.
8. Salvar resultado e fazer commit. Só então a API responde e o feed pode observar os efeitos.

Uma falha antes do commit desfaz efeitos. Deadlock é tratado por rollback e tentativas limitadas. Estado de falha tratável significa conservar o estado anterior e revisar/repetir a mesma chave; não há liquidação parcial. Rascunho existe apenas no formulário, sem reserva ou negócio persistido até envio.

## Fronteira de corte

Convenção: uma operação com eficácia **estritamente anterior** ao corte pertence ao retrato; igualdade pertence ao período seguinte. O gate é mantido até commit. Uma operação que obtém o gate às 11:59:59 e confirma às 12:00:02 mantém eficácia anterior a 12:00. O fechamento às 12:00 espera seu commit. A próxima operação, mesmo se sua requisição chegou antes das 12:00, recebe eficácia após conseguir o gate e captura o retrato antes de alterar estado.

Assim, nenhum `created_at` atribuído antes de commit é usado sozinho como prova histórica. O snapshot representa a ordem serial e o instante de eficácia documentado. Essa convenção não é a mesma coisa que classificar pela hora física de commit. O teste com trigger de atraso e processos independentes verifica a travessia da fronteira.

Depósitos, retiradas, transferência de cotas, primeira aprovação e alteração de parâmetros passam pelo gate. Cotação/leitura pública não fecha períodos. Reservas de cotas somam à titularidade, sem transferi-la. Cortes atrasados são capturados antes de qualquer operação que mudaria o estado; snapshots publicados nunca são recalculados. Se vários cortes decorreram sem nenhuma mudança econômica, compartilhar o mesmo estado é correto.

Para atrasos longos, cron captura no máximo 10 cortes por execução (configurável até 100), persiste esse avanço e retoma depois. Uma mutação econômica não ultrapassa um calendário com mais cortes vencidos que esse limite: exige recuperação primeiro. Isso evita atribuir o estado novo a cortes antigos. Captura usa uma transação limitada a até 500 participantes por período; cálculo/publicação usam lotes de até 100.

## Distribuição recuperável

`SNAPSHOT → PROCESSING → PUBLISHED`. O retrato contém N, Q, cotas por titular, elegibilidade de taxa e versão dos parâmetros. Cada linha processada é marcada na mesma transação que seu direito/obrigação; unicidade impede repetição. Os maiores restos são calculados sobre o retrato completo, com desempate por ID crescente. Quando o último lote termina, verifica-se soma e emite-se mR$ para a reserva do período; publicação é atômica. Direitos/obrigações em processamento não são aceitos nem exibidos como concluídos.

N=0: não há crédito positivo. Q=0: não há divisão/emissão; obrigações continuam. N>0,Q=0 produz evento de inconsistência na auditoria. Aceitar direito transfere reserva → carteira. Pagar taxa transfere carteira disponível → tesouraria. Pendência não aceita não expira. Nenhum débito automático foi implementado.

## Reservas e NFT

Aprovar vendedor move BYC/cotas de carteira a sua conta reservada ou marca sua NFT. Aprovar comprador reserva mR$ dele. Proponente não pode reservar bens da contraparte. Versão nova, cancelamento, recusa e expiração liberam só reservas ACTIVE. Liquidação consome essas reservas, transfere ativos e pagamento, com unicidade de settlement. NFT na piscina tem owner NULL e estado POOL; retirada pública passa para PRIVATE/owner do retirante. Cotas não acompanham essa NFT.

## Imutabilidade e reconciliação

A aplicação não oferece UPDATE/DELETE de lançamentos, eventos ou versões concluídas. Runtime SQL deve ter SELECT/INSERT no livro; migração usa credencial temporária com DDL. Administração não tem editor de saldo/propriedade. Reconciliação sob o gate compara agregados com lançamentos, soma por transação/ativo, negativos indevidos, reservas fungíveis/NFT, custódia e reservas de distribuição com direitos pendentes. DBA com privilégios totais ainda pode alterar o banco: isso não é imutabilidade criptográfica. Correções requerem serviço/migração de compensação auditada, nunca apagar o histórico.

## Arquivos e efeitos externos

Upload grava/reencodifica os arquivos com nomes aleatórios antes da associação SQL. Só depois de existir arquivo disponível a transação consome o direito único. Perdedor de corrida limpa seus arquivos não associados; crash pode deixar órfãos, removidos após 24h pelo cron. Associação já existente não é apagada quando resposta é perdida. SQL não torna filesystem atômico; backup/restauração precisa preservar ambos.

E-mail usa outbox: claim de 5 minutos, envio sem lock contábil, retry em 15 minutos. Crash entre envio e marcação SENT pode reenviar: não se promete entrega externa exatamente uma vez. PHPMailer usa SMTP configurado e TLS com verificação; demo usa arquivo explicitamente identificado.

## PWA, sessão e limites

Service worker só cacheia CSS/JS/fonte/ícones/placeholder/página offline. API/HTML privado/imagens de usuário não entram no cache do SW. HTML/API usam no-store; offline não mantém carteira, não faz Background Sync e não repete confirmações. Resultado desconhecido preserva chave em memória e oferece consulta. Fechar a aba perde essa chave local; é necessário consultar histórico antes de criar outra operação. Sessão e senhas não vão para localStorage.

Logout/troca de conta limpa estado em memória; pageshow revalida identidade ao voltar por bfcache. Recuperação de senha incrementa auth_version e invalida sessões anteriores. Atualização do SW depende de ação do usuário e não recarrega durante operação desconhecida/em curso. URLs relativas/entrada PHP/query string suportam subpasta sem rewrite.

Gate global favorece simplicidade e integridade, mas serializa mutações. Não foi realizado teste de carga nem dimensionamento da Superdomínios. Aumentar limites exige medir consultas, duração dos snapshots/cron, memória, disco e concorrência no plano contratado. Cron é finito, GET_LOCK impede sobreposição e morte de processo libera o lock da conexão. Não há daemon.
