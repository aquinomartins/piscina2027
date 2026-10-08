# Implantação na Superdomínios

O pacote foi implementado e verificado **localmente**. Não foi enviado à Superdomínios; não houve acesso à conta nem contato com suporte. As informações de recursos e termos abaixo vieram dos anexos; não comprovam o plano individual.

## 1. Conferir a conta e o enquadramento

Registre painel real (os anexos citam DirectAdmin, não pressupor cPanel), domínio/diretório público, engine/versão SQL, seletor PHP, executável CLI, SMTP, quotas de disco/memória/tempo/upload, permissões e agendador.

Requisitos: PHP 8.3+ 64 bits, PDO MySQL, BCMath, GD, mbstring, fileinfo, OpenSSL; MySQL/InnoDB/utf8mb4. O teste local usou MySQL 8.0.46. Se for MariaDB, registre a diferença e execute os cenários em homologação nesse motor antes de declarar compatibilidade. Configuração de time_zone='+00:00' não depende de tabelas de fuso do banco.

Referências fornecidas: [Hospedagem PHP](https://superdominios.org/melhor-hospedagem-php/) e [Termos](https://superdominios.org/regras/). Os anexos citam restrições a daemons, cron inferior a 15 minutos, backups na conta, bancos de imagens e categorias como escrow/investimentos. O responsável deve esclarecer o enquadramento da galeria e da simulação antes de publicar. Não presumir autorização ou proibição específica.

## 2. Banco e privilégios

Crie banco vazio e usuário dedicado apenas a ele. Importe `database/install.sql` pelo gerenciador do painel ou use `php bin/migrate.php` com configuração privada.

DDL MySQL tem commits implícitos. Cada migração cria tabelas/seeds de maneira idempotente e grava a versão ao final. Uma interrupção pode deixar DDL parcial; repetir a migração completa conclui estruturas ausentes sem resetar saldos/versões existentes. Para futuras mudanças de coluna, use novo arquivo/versionamento, nunca reimporte por cima um esquema alterado esperando rollback completo.

Use credencial de instalação com DDL apenas durante migração; depois configure usuário runtime sem CREATE/DROP/ALTER. Quando a conta permitir granularidade: SELECT/INSERT nas tabelas de histórico; UPDATE apenas nas tabelas mutáveis; DELETE apenas em rate_limits. Não conceda UPDATE/DELETE em ledger_entries, ledger_transactions, parameter_versions, decision_versions, nft_events, audit_events, proposal_versions ou initial_grants. O painel pode limitar a granularidade; registre o que foi efetivamente concedido. A aplicação não oferece edição livre do livro.

## 3. Separar público e privado

Layout preferido:

```text
piscina/
  public/        ← document root do domínio/subdomínio
  app/ config/ database/ bin/ vendor/ storage/ docs/
```

Se o plano fixar `public_html`, envie **somente o conteúdo de public/** para esse diretório (ou `public_html/piscina` para subpasta). Instale o restante em diretório realmente **fora de public_html**, permitido pela conta. Não colocar `private/` dentro da web como suposta proteção.

Edite **apenas** `public/bootstrap.php` para usar o caminho absoluto privado obtido no painel:

```php
$privateRoot = '/CAMINHO_REAL_FORA_DA_WEB/piscina-private';
```

Também é possível configurar `PISCINA_ROOT` no ambiente PHP se o plano suportar. Todos os pontos de entrada usam o mesmo bootstrap. Não invente caminho `/home/usuario` nem suponha que um relativo aponta fora da web. Teste URLs que tentariam alcançar config, SQL, logs, vendor, testes e uploads privados: devem ser inacessíveis.

Não precisa de Composer/npm no servidor: PHPMailer 6.10.0 já está incluído e autoload local é fornecido. O pacote de produção omite testes e gerador demo. Não envie a configuração/dados locais.

## 4. Configuração privada

Copie `config/example.php` → `config/local.php` na parte privada. Preencha sem enviar segredos pelo chat:

- environment=production; secure_cookie=true.
- url = origem HTTPS (`https://seu-dominio`), **sem o caminho-base**.
- base_path = vazio na raiz ou `/piscina` na subpasta, sem barra final.
- database.dsn/user/password; charset=utf8mb4.
- storage/session_path absolutos em diretório privado persistente e gravável pelo PHP.
- timezone=America/Sao_Paulo.
- SMTP: host, porta, TLS, usuário, senha, remetente e nome.
- mail_transport=smtp; nunca file em produção.
- Limites técnicos de upload/participantes/NFTs/lote/duração; aumentar só depois de medir.

Exemplo de produção usa placeholders sem senha. Não há fallback de banco. Permissões sugeridas, conforme o usuário/grupo do PHP: diretórios privados 700/750, config 600/640, arquivos públicos 644 e diretórios públicos 755. Não use 777. Faça o PHP registrar erros em log privado; display_errors deve permanecer desligado.

## 5. Administrador inicial sem credencial padrão

Com CLI: `php bin/create-admin.php` pede e-mail/nome/senha pela entrada padrão; terminal interativo oculta senha. É criada uma conta administrativa verificada; ela não recebe ativos nem paga taxa até também ser aprovada como participante.

Sem CLI:

1. Gere privadamente um segredo temporário aleatório de pelo menos 32 bytes. Guarde só seu SHA-256 em `installation.token_hash`.
2. Defina `installation.enabled=true` e `installation.expires_at` ISO UTC próxima (ex.: dentro de uma hora). O instalador exige HTTPS em produção.
3. Abra `setup.php`; envie o segredo **pelo formulário POST**, nunca na URL, junto de e-mail/nome/senha escolhida.
4. Ele aplica migrações, cria o administrador e grava `storage/install.lock` com exclusão atômica. A existência desse arquivo fecha o instalador mesmo se a configuração continuar enabled.
5. Desative enabled, apague o token_hash e remova `setup.php` da parte pública.

Em falha após criar o lock, ele permanece fechado. Examine privadamente banco/log; só remova lock para retomar depois de comprovar que não há administrador inicial. Não deixe uma página de instalação aberta indefinidamente.

## 6. Ativar decisões e calendário

Entre na administração com a conta criada. A tela apresenta as escolhas confirmadas pelo usuário e o arredondamento HALF_UP_CENT; confirme com senha atual. Configure o **primeiro corte futuro** e periodicidade **1440 minutos (1 dia)**. Campos datetime-local usam horário do navegador, indicado no rótulo; os instantes são convertidos a UTC, e resultados aparecem no fuso de São Paulo. Verifique o fuso do dispositivo ao configurar.

Antes de configurar primeiro corte, nenhuma distribuição/taxa periódica é gerada. O calendário iniciado é imutável nesta versão; mudanças posteriores exigem migração revisada. Valores monetários continuam configuráveis/versionados com motivo e autoria. Ativação não é aprovação de hospedagem nem publicação blockchain.

## 7. HTTPS, roteamento e arquivos da PWA

Habilite certificado SSL válido e redirecionamento HTTP→HTTPS no painel efetivo. A aplicação não carrega CDN ou conteúdo misto. Não confie em header X-Forwarded-Proto enviado por qualquer cliente para provar HTTPS; configure o servidor/proxy real para disponibilizar HTTPS de maneira confiável.

Links usam `index.php?view=...` e `api.php?action=...`; não dependem de reescrita. `.htaccess` só funciona se o servidor o interpretar. Ele desativa listagem e configura MIME do manifesto; se Options causar erro 500, ajuste conforme a documentação real do servidor sem tornar arquivos privados públicos.

`manifest.webmanifest` e URLs internas relativas respeitam raiz/subpasta. A página também usa `manifest.php` para Content-Type application/manifest+json sem depender de MIME do servidor. Sirva `sw.js` como JavaScript no mesmo caminho/escopo, com no-cache. Verifique MIME e rede pelo navegador final. Não existe APK nem publicação em loja.

## 8. Cron finito, no mínimo 15 minutos

Obtenha o caminho real do executável PHP CLI e do diretório privado. Exemplo **com placeholders**, não copiar literalmente:

```cron
*/15 * * * * /CAMINHO_REAL/PHP /CAMINHO_PRIVADO/bin/cron.php
```

O intervalo deve ser permitido pela conta e nunca inferior a 15 minutos. Saída/log do agendador não podem ficar em URL pública. Cron captura cortes, processa lotes, expira reservas/anúncios, envia outbox e limpa imagens órfãs após 24h. GET_LOCK impede sobreposição; queda de processo libera lock e a próxima execução retoma registros não concluídos. Não depende de aba aberta nem de visitas públicas.

Se não houver CLI e o scheduler enviar headers HTTPS, habilite **opcionalmente** `http_tasks` e use POST `task.php` com bearer em Authorization, nonce novo e timestamp Unix (contrato em API.md). Só hash do token fica na configuração. Nunca passe segredo na URL, nem exponha chamada GET pública. Se o agendador não suportar headers, use CLI/provider ou execução manual autenticada pelo painel; não contorne a proteção. Token de tarefa é diferente do segredo temporário do instalador.

## 9. Homologar pelo domínio final

- Abrir links diretos/refresh na raiz ou subpasta; verificar 320/360/390/412 px.
- Cadastrar, receber **e-mail SMTP real**, verificar e aprovar; recuperação/logout/troca de conta.
- Depositar, retirar por cada modalidade, redepositar, publicar/propor/revisar/aprovar/cancelar.
- Testar upload válido/inválido e falta de permissões/disco; verificar criador/bytes após transferência.
- Configurar corte; confirmar snapshot/lotes, receber bruto e quitar taxa; insuficiência deve manter pendência.
- Executar cron, verificar último sucesso/falhas, reconciliação e idempotência.
- Conferir manifesto, MIME, escopo/cache; instalar e abrir standalone em Android/iPhone reais.
- Testar logout, voltar histórico, offline e atualização sem carteira privada em cache.

Não foram efetuados envio SMTP real, instalação em dispositivo físico, carga ou testes no servidor da Superdomínios nesta entrega. As verificações locais estão em VERIFICACAO.md.

## 10. Atualização, cópia externa e restauração

1. Faça cópia consistente do banco e imagens/configuração persistentes para armazenamento **fora da conta de hospedagem**, conforme termos citados. Segredos exigem acesso restrito.
2. Pause novas operações durante atualização/restauração pelo controle de acesso/manutenção real do painel. Não altere parâmetros como substituto de manutenção.
3. Envie código/dependências de produção; preserve `config/local.php`, imagens, sessões e histórico. Nunca envie dados demo sobre produção.
4. Aplique novas migrações com credencial temporária e registro de versão. Não altere retroativamente o arquivo 001.
5. Incremente versão do cache SW quando mudar assets. O usuário ativa atualização quando não houver operação em curso; mantenha contratos frontend/API compatíveis durante transição.
6. Reexecute diagnose/reconcile/cron e uma jornada de homologação; reabra acesso.
7. Para restauração, use cópia externa consistente SQL+imagens. Reconcilie referências/arquivos e contabilidade antes de reabrir. Nenhum rollback de código por si só desfaz lançamentos econômicos; correções usam compensações auditadas.

## Passos externos ainda não executados

Conta/painel/versões/extensões/permissões, enquadramento do projeto no plano, banco e credenciais reais, transferência dos arquivos, domínio/SSL/SMTP/cron, primeiro corte, testes de homologação e instalação real. Nenhuma senha foi solicitada pelo chat e nenhuma publicação é alegada.
