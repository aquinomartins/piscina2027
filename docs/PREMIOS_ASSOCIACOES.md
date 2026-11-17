# Prêmios diretos para associações Moldura + NFT

O Admin pode criar e financiar um prêmio, selecionar uma associação existente e
confirmar a divisão calculada pelo servidor. Nenhum valor individual é digitado.
As unidades disponíveis são **mR$ simulado** (MR, centavos) e **BYC** (oito casas).
Não há pagamento em reais, Bitcoin, conversão externa ou emissão de saldo.

## Como usar

1. Entre em **Administração → Prêmios de associações**. O acesso usa o painel e
   as permissões atuais, inclusive pelo menu administrativo mobile.
2. Selecione um prêmio já cadastrado ou use **Criar prêmio**: nome, unidade e
   valor total. Não há datas, local, votação ou número mínimo de associações.
3. Use **Financiar prêmio**. Escolha **Minha carteira**, **Tesouraria** ou
   **Receitas da Galeria**. A tela mostra o saldo disponível de cada origem.
   Confirme com sua senha atual. O valor inteiro passa à conta reservada do
   prêmio; insuficiência impede a operação.
4. Use **Escolher associação** e busque por Moldura, NFT ou participante.
   São oferecidas associações simples ativas e contratos avançados vigentes.
   Disponibilidade, titularidade e permissões são revalidadas na confirmação.
5. Se aparecer **Solicitar aditivo**, solicite com sua senha. Cada proprietário
   abre suas **Pendências → Ver aditivo de prêmios** e aceita ou recusa.
   Aceitar não movimenta saldo nem cobra cotas. O Admin não substitui os
   aceites. Se os dois ativos pertencem à mesma pessoa, basta um aceite real.
6. Com o aditivo confirmado, reabra o prêmio, escolha a associação e clique em
   **Revisar destinação**. Confira Moldura, NFT, nomes dos beneficiários,
   total/unidade, percentuais, créditos e versão contratual.
7. Marque a confirmação, informe sua senha atual e use **Premiar associação**.
   As duas parcelas são creditadas juntas e o prêmio passa a pago/destinado.
   O painel exibe o recibo e a data. Cada participante vê seu crédito no
   **Histórico → Premiações de associações**, além do extrato financeiro usual.

Exemplo: prêmio de mR$ 100,00, Moldura 30% e NFT 70%: créditos de mR$ 30,00 e
mR$ 70,00. Para mR$ 0,01 com a mesma proporção, NFT recebe zero e Moldura
recebe o centavo residual. Essa regra é explícita no aditivo e na prévia.

## Por que existe o aditivo

Os contratos simples anteriores limitam a divisão a “prêmios de eventos
expressamente contratados”. A pedido do usuário, a nova modalidade é
**prêmio direto do Admin**, autorizada por **aditivo aceito pelas partes**.
Os contratos antigos e seus percentuais permanecem intactos. Percentuais de
venda ou de outra operação não autorizam uma premiação direta.

O aditivo usa os dois percentuais expressos no contrato original, somando
100%, e autoriza sucessivos prêmios diretos para a mesma associação válida.
Ele não promete prêmio, altera propriedade ou autoriza débito de participante.
A recusa preserva o contrato e os saldos. Uma nova solicitação após a recusa
exige novos aceites; os aceites anteriores não são reaproveitados.

Contrato ausente, ilegível, com percentuais inconsistentes, associação
suspensa/encerrada, usuário inelegível, mudança de proprietário ou de imagem
impedem a distribuição. O Admin deve resolver a associação pelo fluxo atual.
Uma mudança contratual exige nova autorização aplicável; a função não tenta
inventar percentuais nem migrar consentimentos antigos silenciosamente.

## Financiamento e integridade

- Prêmios diretos reutilizam `galeria_events` com `mode=DIRECT`, a conta
  `T:MR:galeria_prize:ID` ou `T:BYC:galeria_prize:ID`, o livro de partidas
  dobradas e o mecanismo transacional/idempotente existente.
- Criação não emite saldo nem debita a origem. Financiamento transfere o valor
  inteiro de uma conta existente. Emissão (`issuance`), reservas de estudantes
  e fundos de sanções/custódia não são fontes oferecidas.
- Cancelar um prêmio ainda não pago devolve o financiamento à origem
  registrada. Um prêmio pago não pode ser cancelado nem destinado novamente.
  Para corrigir título/valor antes de pagar, cancele e crie outro prêmio.
- O servidor calcula em inteiros com BCMath. A parcela da NFT é truncada na
  menor unidade e o resíduo vai à Moldura. Mesmo beneficiário recebe somente
  a soma total; parcela zero é registrada sem lançamento financeiro zero.
- Cada prêmio tem uma única premiação. Chaves idempotentes, unicidade no
  banco e o bloqueio econômico existente protegem cliques repetidos e
  requisições simultâneas. A prévia é vinculada por hash às condições atuais.
- Falha em qualquer crédito desfaz os dois créditos, o registro, notificações
  e auditoria da tentativa. A interface permite consultar o recibo de uma
  operação cuja resposta se perdeu e repetir a mesma operação/chave.
- `galeria_prize_consents` guarda termos/hash e contrato de origem;
  `galeria_prize_acceptances` guarda o aceite individual; e
  `galeria_prize_awards` guarda total, moeda, beneficiários, percentuais,
  parcelas, data, Admin, operação, prêmio, aditivo e cópia do contrato usado.
  Os valores históricos não são recalculados por alterações futuras.

As rotas e regulamentos dos prêmios de votação continuam com `mode=VOTING`.
Um prêmio de votação não pode ser convertido em destinação direta. As opções
avançadas em stand-by continuam com seu funcionamento anterior.

## Atualizar na Superdomínios

Esta atualização parte do `main` que já inclui os merges das PRs #10, #12,
#13 e #14. O pacote preserva a integração das carteiras e a entrada Admin mobile.
Não houve acesso ou alteração na hospedagem de produção.

1. Faça backup de banco e arquivos. Atualize numa janela sem operações da
   turma. Preserve `config/local.php`, todo o `storage/` e as chaves privadas.
2. Baixe **[Piscina_Premios_Associacoes.zip](../downloads/Piscina_Premios_Associacoes.zip)**
   na branch/PR desta atualização, usando **Download raw file** no GitHub.
   Confira [o hash do pacote](../downloads/PREMIOS_SHA256SUMS.txt).
3. Em **cPanel → phpMyAdmin**, selecione `oftalmol_piscina2027`. Confira que
   `schema_migrations` já contém `004_galeria` e `005_molduras_simples`.
   Se faltar alguma, aplique as migrações ausentes em ordem.
4. Importe **somente** `database/migrations/006_premios_associacoes.sql`.
   Ela acrescenta o modo dos prêmios e as três tabelas acima, sem emitir
   recursos, criar aditivos ou reescrever contratos/valores anteriores.
   Não importe `database/install.sql` num banco preenchido.
5. Extraia o ZIP numa pasta temporária privada. Copie `app/` para
   `/home/oftalmol/aquino.bsb.br/app/`, incluindo as quatro novas classes.
   Copie o conteúdo de `public/` para
   `/home/oftalmol/aquino.bsb.br/public/`. Envie os arquivos estáticos antes
   dos PHP integrados e **`public/sw.js` por último**. As pastas `app/`,
   `database/` e `docs/` ficam fora da raiz pública.
6. Mantenha a configuração privada existente da Galeria habilitada
   (`'galeria' => ['enabled' => true]`). Não há novo segredo, serviço, cron,
   Node ou edição de configuração obrigatória para os prêmios.
7. Abra o app e use **Atualizar app** quando o aviso aparecer. Confira
   Administração → Prêmios de associações; consulte também um Portfólio,
   as Pendências e a navegação mobile. Na conciliação administrativa, as
   listas de divergências devem estar vazias.
8. Valide primeiro numa homologação com contas sintéticas: solicitação e
   dois aceites do aditivo, financiamento, prévia e um único pagamento.
   Só então use premiações reais da turma.

DDL MySQL faz commits implícitos. A migração é reaplicável e registra a versão
somente ao final. Se falhar, mantenha a manutenção, corrija a causa e reaplique;
não marque a versão manualmente nem apague tabelas. Depois de registrar novos
aditivos/prêmios, mantenha código compatível com a 006. Restaurar um banco
antigo pode eliminar transações posteriores; não há down migration destrutiva.

## Verificação

[Cenários, reprodução e limites dos testes](PREMIOS_TESTES.md).

Registros de teste: [mobile](images/premios-registro-mobile.png) e
[desktop](images/premios-registro-desktop.png).

## Arquivos da implementação

| Arquivo / grupo | Alteração |
|---|---|
| `app/GaleriaPremios.php` | Criação, financiamento existente, prévia, pagamento, cancelamento e histórico |
| `app/PrizeAssociations.php` | Leitura/validação dos contratos simples e avançados canônicos |
| `app/PrizeConsents.php` | Aditivo e aceites individuais; preserva contrato original |
| `app/PrizeAllocation.php` | Cálculo inteiro exato e regra determinística do resíduo |
| `app/Galeria.php`, `GaleriaEventos.php`, `GaleriaQueries.php` | Integração às operações existentes e separação de votação/destinação direta |
| `app/ParticipantActivity.php`, `MolduraWallet.php`, `MolduraLinks.php` | Aditivos nas Pendências, vínculo ao contrato e créditos históricos próprios |
| `database/migrations/006_premios_associacoes.sql`, `database/install.sql` | Atualização reaplicável e instalação nova |
| `public/api.php` | Rotas com autorização, CSRF, senha e idempotência existentes |
| `public/assets/galeria-premios.js`, `galeria.css` | Formulários, prévia e confirmação responsivos |
| `public/assets/app.js`, `molduras-carteira.js`, `molduras-simples.js` | Entrada no Admin e consultas/aceites junto aos ativos atuais |
| `public/sw.js` | Cache somente de estáticos, incluindo o novo módulo |
| `tests/premios-*`, `tests/run.php` | Jornadas reais de domínio, migração e navegador |
| `tools/build-galeria-update.py`, `downloads/`, guias | Pacotes compatíveis, hashes, instalação e resultados |
