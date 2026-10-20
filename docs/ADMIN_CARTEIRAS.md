# Administração: carteiras e aprovação sem confirmação de e-mail

## Funcionalidades

Na Administração, **Participantes e carteiras** lista todos os cadastros, 24 por página, com busca por nome, e-mail ou ID e filtro de aprovação. Cada participante mostra os saldos disponíveis, reservados e totais de mR$, BYC e cotas, além da quantidade de NFTs privadas e dos depósitos atuais na piscina. **Ver carteira e extrato** abre os lançamentos individuais, com paginação, data, ativo e referência da operação.

“Depósitos atuais na piscina” conta apenas NFTs que estão em `POOL` e cujo último depositante é o participante. O criador original e os depositantes anteriores não são contados como titulares da custódia atual. NFTs privadas são contadas pelo proprietário atual. Cotas permanecem independentes da propriedade de NFTs.

O botão **Aprovar sem confirmação de e-mail** aparece para cadastros ainda não aprovados e com e-mail pendente. Exige motivo de 5 a 255 caracteres, aceite explícito e senha atual do administrador. A ação registra autor, data, motivo, participante e resultado na auditoria como `admin.approve_without_email`.

O e-mail permanece pendente: a aprovação administrativa não preenche `email_verified_at`. O participante ainda pode usar o link de verificação normalmente. A primeira aprovação concede o crédito inicial configurado e uma NFT. Repetir a aprovação, inclusive com outra chave ou em requisições simultâneas, não repete a concessão. Cadastros com concessão anterior são reaprovados sem alterar seus ativos existentes.

## Acesso e escopo

As consultas e a aprovação excepcional exigem `role=admin` no servidor. O novo GET `admin.wallet` retorna somente os dados da carteira solicitada após conferir esse cargo. Não retorna senha ou hash de senha. As respostas continuam com `Cache-Control: no-store, private`.

O POST excepcional usa as proteções administrativas existentes: sessão, CSRF, senha atual, limite de tentativas, chave de idempotência, transação, gate econômico e limites de participantes/NFTs. O POST comum `admin.approve` continua exigindo e-mail verificado. Parâmetros enviados ao cadastro público não concedem cargo de administrador nem aprovação.

As alterações visuais e os novos controles são administrativos. Cadastro público, autenticação, negociação, depósito, retirada, distribuições, taxas, envio de e-mail e política econômica mantêm seus fluxos. O service worker muda somente a versão do cache estático, para entregar os novos arquivos JS/CSS; sua lógica de atualização e suas regras de cache permanecem iguais.

Não há migração SQL, reset de dados ou configuração privada nesta atualização.

## Atualizar na Superdomínios

1. Faça uma cópia dos seis arquivos abaixo antes da substituição. Guarde-a fora da pasta pública.
2. Baixe `downloads/Piscina_Atualizacao_Admin.zip` desta versão no GitHub e extraia no computador. O ZIP contém somente os seis arquivos, este guia e uma lista SHA-256.
3. No Gerenciador de Arquivos do cPanel, substitua os arquivos preservando os caminhos relativos dentro de `/home/oftalmol/aquino.bsb.br/`:

   - `app/Domain.php`
   - `app/Queries.php`
   - `public/api.php`
   - `public/assets/app.js`
   - `public/assets/app.css`
   - `public/sw.js`

4. Não importe SQL, não rode instalação e não substitua `config/local.php` ou `storage`. Copie o conjunto completo dos seis arquivos na mesma janela de manutenção para evitar versões misturadas.
5. Abra o site e aplique **Atualizar app** quando a nova versão estiver disponível. No navegador, uma recarga com cache desativado também permite conferir a versão nova. Se o primeiro carregamento registrar o worker novo, a atualização pode aparecer na visita seguinte.
6. Entre como administrador e abra a Administração. Busque um participante, confira seus saldos e extrato. Para um cadastro pendente, use a aprovação adequada ao estado do e-mail. Confira a auditoria e a reconciliação.
7. Entre como participante em outra sessão e confirme que a Administração permanece inacessível. Se precisar reverter a atualização, restaure os seis arquivos do backup; o registro da aprovação já realizada permanece no banco.

O pacote é uma atualização sobre a versão que contém o menu PWA da PR #5. Não substitua uma instalação com alterações particulares sem comparar esses seis arquivos.

## API administrativa

- `GET api.php?action=admin&q=...&status=pending|approved&offset=0`: usuários paginados com saldos/custódia, total encontrado e `has_more`, além dos dados administrativos anteriores. `status` vazio mostra todos.
- `GET api.php?action=admin.wallet&user_id=ID&offset=0`: identidade do participante, saldos, custódia e até 24 lançamentos contábeis por página; `offset` avança de 24 em 24.
- `POST api.php?action=admin.approve_without_email`: corpo `{user_id: "ID", confirm_without_email: true, reason: "motivo", password: "senha atual"}`, com CSRF e `Idempotency-Key`. A senha é removida do payload antes do registro econômico e da auditoria.

## Reprodução dos testes

Use apenas ambiente local com PHP 8.3+, extensões do projeto e MySQL 8. O `PISCINA_CONFIG` deve apontar para configuração de teste com `environment=demo` e `dbname=piscina_test`. Nunca aponte os testes para a hospedagem.

1. Execute `php tests/run.php`: a suíte recria o banco **piscina_test** e inclui os cenários anteriores e os novos testes administrativos.
2. Para navegador, recrie exclusivamente o banco de teste vazio. Defina senhas locais em `PISCINA_DEMO_PASSWORD` e `PISCINA_DEMO_ADMIN_PASSWORD`; execute `php tests/admin-fixture.php`.
3. Sirva `public` com PHP, configure `TEST_URL` e execute `node tests/browser.cjs` e `node tests/admin-browser.cjs`. Ambos usam as variáveis de senha acima. Configure `CHROMIUM_PATH` se necessário.
4. Execute `node tests/pwa-menu.cjs` para verificar os fluxos existentes de instalação e atualização, na raiz e em subpasta, com service workers reais e API simulada.

Os testes locais não substituem a conferência dos seis arquivos e das permissões na hospedagem. Nenhuma alteração de produção é executada pelos preparativos desta atualização.
