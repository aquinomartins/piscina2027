# Molduras no Portfólio e nas Pendências

Esta atualização integra a Galeria aos locais já usados pelos participantes.
Não há nova migração: uma instalação que já possui a **004 e a 005** recebe
somente arquivos. Não é preciso criar outro cron nem alterar a configuração
privada. Uma instalação que ainda tem apenas a 004 deve aplicar a 005 conforme
[GALERIA_DEPLOY.md](GALERIA_DEPLOY.md) antes de receber esse runtime.

## O que aparece em cada lugar

**Portfólio → Minhas Molduras:** somente os ativos cujo proprietário atual é
o usuário autenticado. O cartão separa venda e associação: anúncio/preço,
NFT associada, proprietário real, percentuais e acesso ao acordo. Uma proposta
em andamento não vira associação. Uma oferta dirigida só vira patrimônio
depois da compra confirmada. A imagem da Moldura é opcional.

**Portfólio → Minhas obras:** a NFT própria mostra a Moldura relacionada e
seu proprietário, situação do acordo e percentuais por pessoa e por papel.
A Moldura de outra pessoa não entra na lista de Molduras próprias. A NFT de
outra pessoa também não vira uma obra do dono da Moldura. O acesso legado
para terminar a imagem de uma obra criada e já transferida fica em uma seção
separada, expressamente fora do patrimônio atual.

**Pendências → Negociações e associações:** ofertas exclusivas recebidas,
compra/revenda de Molduras, propostas e contrapropostas de associação, pedidos
de encerramento e confirmações de cancelamento dos contratos avançados.
As negociações nativas de NFT, BYC e cotas continuam nessa mesma lista.

| Situação | Apresentação e contador |
|---|---|
| Falta o aceite/resposta do usuário | Sua ação necessária |
| A versão atual já foi confirmada por ele | Aguardando resposta da pessoa identificada |
| Contraproposta enviada | A responsabilidade muda segundo os aceites da nova versão |
| Proposta desatualizada | Aviso e opção de encerrar; o atalho de aceitar fica indisponível |
| Compra, aceite, recusa, cancelamento ou expiração | Sai das abertas; permanece no Histórico |

O número no menu e no sino conta **ações necessárias**, incluindo recebíveis e
taxas pendentes já publicados. Não inclui negociações que aguardam outra
pessoa, avisos antigos nem notificações já mostradas. Os totais abrangem todas
as páginas; cada página de negociações tem até 24 registros. IDs iguais em
tabelas diferentes não se confundem. A compra concluída por uma negociação
de preço aparece uma vez, sem repetir sua oferta interna no histórico.

## Operar sem sair do contexto

1. Abra a Moldura própria no Portfólio e escolha **Propor associação**, ou
   use essa opção na NFT própria e selecione uma Moldura de outro participante.
2. Escolha os ativos elegíveis, negocie os percentuais e revise os nomes,
   papéis e condições. A soma permanece 100%.
3. Após o envio, o recibo informa quem deve responder. A outra pessoa pode
   aceitar, fazer contraproposta ou recusar pelas Pendências.
4. O aceite exige confirmar os termos exibidos da versão atual. Depois do
   segundo aceite, a associação aparece junto aos ativos dos participantes.
5. Para encerrar uma associação entre pessoas diferentes, um proprietário
   faz o pedido no acordo e o outro confirma pelas Pendências. Os percentuais
   e o histórico permanecem registrados.

Os botões abrem os mesmos formulários e chamam as mesmas operações da Galeria.
Não há uma segunda compra, uma segunda associação ou uma cópia do contrato.
Anunciar uma Moldura associada continua separado de vendê-la: a transferência
precisa respeitar o encerramento dos compromissos vigentes.

## Comunicação e proteção

Confirmações de sucesso vêm do recibo confirmado pelo servidor, inclusive
quando uma resposta de rede se perde após o commit. Resultado desconhecido
continua com **Consultar resultado**, preservando a chave e bloqueando novas
condições até a resolução. Não é apresentado como falha financeira definitiva.
Erros de validação/autorização preservam os campos e a confirmação para revisão.

Os dados são relidos depois de cada operação e ao abrir as telas. Contadores
também são consultados ao navegar nas outras seções autenticadas. Esta entrega
não adiciona atualização em tempo real entre dispositivos: abra novamente a
tela para receber uma resposta que outra pessoa acabou de dar.

Leituras privadas usam a identidade da sessão e um retrato consistente do
MySQL, sem adquirir o bloqueio global das operações econômicas. Nenhuma
consulta altera saldo, propriedade, contrato, prazo ou estado de proposta.
O estado de expiração pode ser apresentado antes de o cron atualizar a linha;
as rotinas atuais continuam responsáveis pelas liberações e registros.

Novas associações simples continuam sem cobrança obrigatória, agenda, espaço
ou pontos. Contratos avançados mantêm cotas, percentuais e regras originais,
mesmo com recursos futuros em stand-by. O cancelamento avançado segue bilateral
e só devolve cotas quando o protocolo original confirmar a devolução.

## Instalar na Superdomínios

1. Faça backup dos arquivos e do banco. Use uma janela sem operações para
   evitar servir arquivos de versões diferentes durante a cópia.
2. Baixe `downloads/Piscina_Portfolio_Pendencias.zip` e extraia em pasta
   temporária privada. O pacote contém o runtime compatível da Galeria e
   os guias; preserve `config/local.php` e todo o `storage/`.
3. Copie `app/` para `/home/oftalmol/aquino.bsb.br/app/`. Envie primeiro as novas
   classes `ParticipantActivity.php`, `MolduraWallet.php` e
   `GaleriaCommunication.php`; depois substitua as classes integradas.
4. Copie `public/assets/` e os outros arquivos de `public/` para o `public/`
   existente. Os novos `molduras-carteira.js` e `molduras-carteira.css` precisam
   estar presentes antes de substituir `app.js` e `index.php`.
   **Envie `sw.js` por último.**
5. Com 004/005 já aplicadas, **não importe SQL para esta integração**. Os SQLs
   incluídos no pacote atendem apenas instalações anteriores; não são reset.
6. Use **Atualizar app** no PWA quando o aviso aparecer. Verifique com duas
   contas de homologação: oferta dirigida, compra, associação, contraproposta,
   aceite, portfólios, encerramento e histórico. Não ajuste saldos manualmente.

O pacote não traz senhas, configuração privada, armazenamento ou dados de
estudantes. Pacotes antigos de outras atualizações podem restaurar arquivos
incompatíveis; use sempre os pacotes atuais juntos. Não houve publicação no
servidor de produção.

## Verificação reproduzível

Veja [MOLDURAS_CARTEIRA_TESTES.md](MOLDURAS_CARTEIRA_TESTES.md) para resultados,
cenários, prévias e limites dos testes locais.
