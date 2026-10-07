# Relatório de verificação — 07/10/2026

## Ambiente observado

Ambiente local Linux Debian 13, com runtimes portáteis preparados em diretório de trabalho: **PHP 8.3.6 de 64 bits**, **MySQL 8.0.46-0ubuntu0.24.04.4 real**, tabelas **InnoDB**, conexão PDO/utf8mb4. MySQL por socket local; bancos distintos para demonstração, suíte destrutiva e instalador. GD, BCMath, mbstring, PDO MySQL, fileinfo e OpenSSL disponíveis.

Não houve uso de SQLite ou mocks para comprovar bloqueios. Não se trocou MySQL por MariaDB. Não houve acesso à conta de hospedagem.

## Comandos e resultados

| Categoria | Comando efetivamente usado | Resultado |
|---|---|---|
| Diagnóstico | `/workspace/work/php bin/diagnose.php` | PHP/extensões/engine/utf8mb4 observados; todas as tabelas InnoDB |
| Sintaxe PHP | `find app public config bin tests -name '*.php' -print0 \| xargs -0 -n1 /workspace/work/php -l` | Todos os arquivos verificados sem erro de sintaxe |
| Sintaxe JS | `node --check public/assets/app.js`, `node --check public/sw.js`, `node --check tests/browser.cjs` | Aprovado |
| Domínio/integridade | `PISCINA_CONFIG=/workspace/piscina2027/tests/local.php /workspace/work/php tests/run.php` | **34 cenários, 0 falhas**; saída integral em RESULTADOS_DOMINIO.txt |
| Navegador | `CHROMIUM_PATH=/usr/bin/chromium node tests/browser.cjs`, com senhas locais em variáveis | 17 verificações/jornadas aprovadas; sem erros JS não tratados |
| Subpasta | `node /workspace/work/subfolder-check.js` | Links/refresh, manifesto, escopo /piscina/, viewport 360 e offline aprovados |
| Reconciliação | `/workspace/work/php bin/reconcile.php` | balances/unbalanced/negative/reserves/custody/nftReserves/distribution vazios |
| Cron | `/workspace/work/php bin/cron.php` | SUCCESS; repetição sem efeitos duplicados; execução observada 0,006s sem pendências |
| Cron HTTP | Requisições POST locais ao task.php com bearer/nonce/timestamp | Sem bearer 403; válido 200; replay 409; timestamp vencido 409 |
| Instalador web | GET/POST locais ao setup.php em banco separado | Formulário 200; token errado 403; instalação 200; depois fechado persistentemente 404 |

O wrapper local usa o binário PHP preparado e php.ini privado do ambiente; ele não integra o pacote de produção. Com PHP/extensões instalados normalmente, substituir `/workspace/work/php` por `php`.

## Integridade coberta

Aprovação concorrente única, idempotência e conflito de conteúdo; depósitos repetidos/redépósitos; propriedade e cota independentes; insuficiência BYC; retirada mR$ correta; duas retiradas concorrentes com um único pagador; venda BYC/cota/NFT sem emissão na liquidação; versões, reservas próprias e liberação; revisão/cancelamento/aprovação concorrentes; dois gastos incompatíveis; expiração antes do cron; bruto mR$240/taxa100/líquido140; participante sem cotas; snapshot anterior à venda; 6667/6667/6666; N=0/Q=0; parâmetros imutáveis nos direitos; precisão/limites; autorização; lotes interrompidos; períodos atrasados; corrupção detectada; transação que atravessa o corte; imagem concorrente e falha de associação; token único/expiração/reset/sessão; anúncios; preço/total zero; deadlock real e retries; atraso longo recuperado por lotes; reservas vencidas liberadas antes de novo gasto.

Concorrência usa `proc_open` com processos independentes, cada um com conexão PDO própria e barreira de início. No corte, trigger de teste atrasa a atualização da NFT por 2s enquanto o gate permanece adquirido. Deadlock usa duas conexões que bloqueiam linhas em ordens opostas; pelo menos uma tentativa é repetida e a soma final conserva os dois incrementos, sem duplicação.

## Navegador e PWA

Chromium automatizado, não dispositivo físico. Emulações: 320, 360, 390, 412 e 1440 px; largura de scroll igual à viewport em todas. Screenshots locais foram inspecionados. Jornada: login → carteira → proposta guiada → reserva → logout/volta de histórico → contraparte aprova → liquidação → retirada pública → administração → mint/calendário/tarefa → upload real → aceitação de direito → pagamento de taxa → inspeção de caches → offline/reconexão.

Cache observado contém apenas a lista de CSS/JS/fonte/ícones/placeholder/offline. Não contém index.php, api.php ou imagens de usuário. Offline após carteira autenticada retorna página neutra; nenhuma operação automática. Primeiro controllerchange do SW não recarrega a página indevidamente; atualização posterior é explícita. Manifesto/ícones/start_url/escopo verificados também em /piscina/.

**Não** se declarou instalação real em Android/iPhone, abertura standalone física ou teste em Safari. HTTPS e cookies Secure dependem de homologação final; local HTTP usou exclusivamente perfil demo.

## Limites das evidências

- Não houve SMTP real. Tokens/outbox foram testados; transporte local é arquivo identificado, sem envio externo.
- Não houve carga, benchmark de usuários simultâneos, disco cheio físico, queda de energia nem compatibilidade do servidor/painel real.
- MariaDB não foi testado; as regras .htaccess não foram verificadas em Apache/LiteSpeed do provedor.
- A suíte confirma invariantes e corridas citadas, não constitui auditoria formal de segurança ou demonstração de propriedades econômicas.
- SQL e filesystem têm limites distintos; foram testados upload/associação/concorrência e falta de arquivo, sem afirmar atomicidade externa.
- Publicação na Superdomínios não executada. Conta, enquadramento, DNS/SSL/SMTP/cron e primeiro corte exigem configuração final.

## Entrega

ZIPs gerados de lista de arquivos permitidos, excluindo configuração real, sessões, imagens/dados locais, bancos, backups, .git e runtimes de trabalho. Produção exclui também testes/gerador demo/Node. Verificação do ZIP deve confirmar ausência de segredos/dados e integridade antes de transferi-lo.

Os ZIPs completo e de produção são disponibilizados na pasta `downloads/` do repositório, acompanhados dos hashes SHA-256. O pacote de produção contém apenas os arquivos necessários à aplicação e à sua instalação, sem testes nem gerador de demonstração.
