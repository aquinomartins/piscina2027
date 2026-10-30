# Danger Zone — Administração da turma

Implementação para PHP 8.3+ / MySQL 8, Superdomínios com cPanel. A interface nova aparece somente na Administração. As permissões são verificadas no servidor, incluindo dentro do bloqueio das operações. Nenhuma alteração foi aplicada à hospedagem durante o desenvolvimento.

## Ações

- **Multar em mR$:** valor positivo em centavos exatos, somente da carteira disponível para a conta técnica `fines`. Saldo insuficiente recusa a multa inteira; não usa reservas, não cria dívida e não emite moeda. A tela apresenta saldo, prévia e destino; o ledger registra débito e crédito.
- **Banir temporariamente:** duração em minutos, até um ano; término apresentado em São Paulo e armazenado em UTC. Bloqueia login e operações autenticadas, incrementa a versão das sessões e cancela propostas/anúncios abertos, liberando reservas das partes. Não altera patrimônio, aprovação, verificação de e-mail ou regras dos períodos. A data é conferida nas requisições; a expiração não depende do Cron. Retomar o acesso exige novo login. Desbanimento antecipado também exige senha, motivo e confirmação.
- **Excluir definitivamente:** exige digitar `EXCLUIR ID`, além da senha e motivo. Encerra a participação sem opção de reativação no painel; preserva referências históricas, autoria e negociações concluídas. Invalida sessões e tokens. O cadastro e o e-mail são mantidos como registro histórico e para impedir novo cadastro do mesmo endereço. Não há DELETE em cascata.
- **Trocar imagem da NFT:** integra a implementação do PR #7, com validação, senha, motivo, confirmação, auditoria, controle de versão e cache. A consulta e o controle ficam dentro da Danger Zone, sem duplicação na seção anterior. O envio único do criador permanece com as mesmas permissões.
- **Redefinir senha:** o Admin informa sua própria senha, motivo e a nova senha do participante duas vezes. Não usa e-mail e não revela a senha anterior. Mantém a política de 12–72 bytes e PASSWORD_DEFAULT, invalida sessões e tokens de recuperação antigos. Não aprova, verifica e-mail, desbane ou reativa conta encerrada.

As sanções e a redefinição atendem somente contas com papel `participant`. Contas administrativas, incluindo o próprio operador, ficam protegidas. Todas as alterações exigem CSRF, reautenticação e confirmação. Os limites de autenticação e cadastro de turmas do PR #8 foram preservados.

## Matriz da exclusão e fundo

| Situação | Tratamento |
|---|---|
| mR$, BYC e cotas disponíveis | Transferência pelo ledger para conta `custody` do ativo, individualizada por participante de origem |
| Recursos reservados | Propostas abertas são canceladas e reservas de todas as partes são liberadas; depois o saldo do excluído vai à custódia |
| NFT privada atualmente pertencente ao excluído | Estado `CUSTODY`, sem proprietário jogável; registro em `admin_nft_custody` com origem, operação e data |
| NFT criada pelo excluído, mas pertencente a terceiro | Permanece com o titular atual |
| NFT de outro criador atualmente pertencente ao excluído | Vai à custódia; autoria é preservada |
| NFT já na Piscina | Permanece na Piscina; não é confiscada por autoria ou histórico de depósito |
| Negócio concluído | Permanece concluído e com seus registros |
| Direito ou taxa pendente, publicado ou em processamento | Permanece atribuído à origem, sem aceitação, pagamento, perdão ou redistribuição automáticos |
| Cotas e períodos futuros | Mantêm o cálculo atual, com direitos e taxas pendentes para avaliação, conforme decisão confirmada pelo responsável |

O **Fundo de Custódia para Avaliação** é separado da tesouraria, de `issuance` e de `fines`. A conta usa propósito `custody`, com referência ao usuário de origem para rastreabilidade. Essa referência é também necessária para os cálculos existentes de Q e dos snapshots: não significa que o fundo seja uma conta de jogador. `Ledger::balances()` considera somente `wallet` e `reserved`; valores em custódia não podem ser negociados.

Se houver reserva inconsistente que não possa ser liberada pelos negócios abertos, a exclusão é recusada integralmente para reconciliação; não encerra a conta deixando parte de seus ativos fora do fundo.

O estado original de aprovação é preservado como informação econômica/histórica; o encerramento definitivo fica em `admin_restrictions.deleted_at`. Assim o motor de períodos mantém os mesmos critérios de direitos e taxas confirmados pelo responsável. Perfis e contagem de participantes ativos excluem os encerrados; aprová-los ou operarem novamente é bloqueado. Banidos também mantêm os critérios atuais de períodos.

Os registros não são apagados nem liquidados automaticamente. O painel consulta o fundo por participante de origem, mostra saldos, NFTs e obrigações pendentes. A tela pagina NFTs em lotes de 100 e mostra o histórico administrativo recente. Não há botões de retirada, venda ou redistribuição do fundo nesta entrega. NFTs em custódia não aparecem em consulta pública, e sua imagem passa a exigir sessão administrativa com resposta sem cache. Cópias de imagens já obtidas enquanto eram públicas não podem ser removidas dos dispositivos de terceiros.

## Recuperação pública de senha

Foi reproduzido em navegador um defeito específico no código anterior: a URL `#reset=TOKEN` era lida apenas em `init()`. Um link aberto na mesma página já carregada mudava o fragmento, mas não abria o formulário. A comparação com o JavaScript anterior confirmou abertura na carga inicial e ausência de abertura na página já carregada. A versão nova trata `hashchange`, atualiza o CSRF e abre o formulário após a navegação inicial.

O teste usa token real da outbox do banco descartável, confirma que abrir o link não o consome, redefinição por POST, senha anterior inválida, tokens expirados/usados/inválidos e navegador anônimo. O fragmento é removido da barra depois de reconhecido e não é enviado ao servidor em query string. Não foram removidos CSRF, validade ou uso único.

Essa reprodução não confirma a configuração privada ou o certificado do domínio de produção. Na instalação, conferir `config/local.php`: `url` deve conter o endereço HTTPS canônico que realmente abre o site; `base_path` deve corresponder à instalação. As variantes com e sem `www` devem redirecionar para o mesmo endereço preservando o fragmento. Não alterar credenciais ou substituir o arquivo privado por um exemplo. O envio continua dependendo do Cron/SMTP existentes.

## Credenciais, auditoria e recibos

O Admin possui um endpoint específico de redefinição. A nova senha não entra na auditoria genérica do Domain. Auditorias guardam ator, alvo, motivo, datas, ação, resultado e referências contábeis. Recibos não contêm senhas ou hashes de senha.

Para vincular a chave de idempotência à credencial sem gravar um verificador rápido no banco, a aplicação mantém um segredo aleatório separado em **`storage/credentials-idempotency.key`**, com permissão 0600 e bloqueio de arquivo na inicialização. O segredo é criado automaticamente no armazenamento privado quando necessário; não altera a configuração privada. Não está nos pacotes. Proteja e inclua esse arquivo nos backups privados; não o publique, não o copie para `public` e não o apague ao atualizar. Uma chave ausente ou inválida pode impedir a repetição de recibos antigos; consultar um resultado já gravado continua sendo possível pela sessão administrativa autorizada.

Após timeout, use “Consultar resultado”. O reenvio mantém a mesma chave e as condições originais; senhas precisam ser informadas novamente, pois não ficam retidas no estado de recuperação. A operação é executada uma única vez. O recebimento de um resultado antigo não significa que sanções posteriores tenham sido desfeitas.

## Instalação incremental no cPanel

Use `downloads/Piscina_Danger_Zone.zip`. É uma atualização, não uma reinstalação. Integra a troca de imagem mesmo quando ela ainda não foi incorporada à main. Não contém banco exportado, credenciais, `config/local.php`, sessões, imagens de alunos ou scripts de teste.

1. Faça backup consistente do banco e dos arquivos privados/públicos. Escolha uma janela sem atividades da turma; não aplique sanções durante uma atualização parcial.
2. Extraia o pacote em uma pasta temporária privada para conferir a árvore e os hashes. A raiz da aplicação é `/home/oftalmol/aquino.bsb.br`, que contém `app`, `database`, `public`, `config` e `storage`. A raiz pública continua sendo `public`.
3. **Antes de substituir os arquivos PHP**, no phpMyAdmin selecione o banco correto e importe somente `database/migrations/003_admin_danger.sql` do pacote. Essa migração cria duas tabelas, não apaga dados nem altera saldos. É idempotente: pode ser reaplicada se necessário. Não importe `database/install.sql` para atualizar uma instalação existente.
4. Copie todas as pastas/arquivos de execução fornecidos para a raiz da aplicação, respeitando os caminhos. Não mova `app` ou `storage` para dentro de `public`. O manifesto do pacote contém a relação exata.
5. Preserve o armazenamento e a configuração privada. O PHP deve continuar podendo gravar em `storage`, e somente o usuário da aplicação deve acessar seus arquivos sensíveis.
6. Revalide o OPcache se necessário pelo painel/suporte. No PWA já instalado, use **Atualizar app** para carregar o JavaScript/CSS novos e o cache estático v6. Atualização não deve ser forçada durante uma operação pendente.
7. Faça a conferência de permissões com uma conta de participante e o Admin; confirme a Danger Zone e teste a recuperação de senha de uma conta controlada. A validação funcional com sanções deve ocorrer primeiro em homologação, nunca em contas reais sem intenção de aplicar a sanção.

### Ordem e arquivos

A migração vem primeiro porque os novos controles consultam as novas tabelas. Em seguida, instalar o conjunto completo:

- `app/Auth.php`, `Domain.php`, `Ledger.php`, `Queries.php`, `Restrictions.php`, `AdminDanger.php`, `AdminCredentials.php`, `CredentialKey.php`.
- Integração de imagem: `app/Upload.php`, `AdminImageUpload.php`, `NftImage.php`.
- `public/api.php`, `public/image.php`.
- `public/assets/app.js`, `public/assets/admin-danger.js`, `public/assets/app.css`, `public/sw.js`.

Nenhuma edição de `config/local.php` é necessária para habilitar as ações. O pacote também contém este guia e o relatório de testes.

Depois desta atualização, não reaplique os pacotes antigos de cadastro, carteiras administrativas ou troca de imagem isolada: eles contêm versões anteriores de Auth/Domain. Use o pacote Danger Zone ou os pacotes completos atuais. Os geradores dos pacotes antigos passam a recusar reconstruções incompatíveis nessa versão.

## Reversão

Antes de qualquer sanção ou alteração de senha, é possível retornar ao conjunto anterior de arquivos, mantendo as novas tabelas vazias. A migração não precisa ser desfeita.

**Depois de aplicar sanções, não restaure isoladamente o Auth/Domain antigos.** Eles não conhecem as restrições novas e poderiam permitir acesso a contas encerradas. Código antigo também não representa corretamente contas de custódia. Reverter arquivos não desfaz multa, cancelamento, senha ou transferência.

Se uma reversão for necessária após uso, suspenda as gravações e faça nova cópia do estado atual. Prefira uma correção compatível que mantenha as restrições e o ledger. Uma restauração de backup exige conciliar também todas as movimentações ocorridas depois dele, sem perder dados de outros alunos. Não há script automático para apagar o fundo, reativar excluídos, perdoar taxas ou desfazer sanções. Não elimine tabelas com registros ou o segredo de idempotência.

## Verificação

Consulte `docs/RESULTADOS_DANGER_ZONE.txt` para resultados executados e limitações. Os testes usam exclusivamente `piscina_test`, ambiente demo e credenciais fictícias. Foram preservados os testes de contabilidade, cadastro de turmas, imagem e PWA, além de testes novos de sanções, rollback, custódia, concorrência e recuperação.

`tests/danger-domain.php` integra `tests/run.php`. `tests/danger-browser.cjs` verifica a interface/API real em localhost. O helper `tests/danger-fixture.php` exige CLI, demo, banco piscina_test e endereço `.local.invalid`; serve somente para obter/expirar tokens de teste sem criar endpoints públicos.
