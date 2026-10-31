# Galeria e Molduras — guia do módulo

A Galeria funciona dentro da Piscina, com os mesmos usuários, sessões, carteiras,
NFTs e cron. Moldura é um ativo individual: não é uma nova moeda e não é um
token em blockchain. mR$ continua exclusivamente simulado. Não há pagamentos
reais, conversão de cotas, AMM, leilão ou promessa de rendimento.

## Acesso e configuração inicial

Depois de instalar a migração 004 e os arquivos, acrescente ao **seu**
`config/local.php`, preservando todas as credenciais existentes:

```php
'galeria' => ['enabled' => true],
```

Sem esse bloco, a Galeria fica desabilitada para novos negócios. A configuração
de exemplo contém `false`. A entrada **Galeria** aparece no menu desktop e
mobile quando habilitada, para o Admin ou quando já existe histórico de acordos.
O Admin também entra por **Administração → Gerenciar Galeria**.

Na primeira instalação, novas contratações permanecem suspensas no banco.
O Admin pode preparar espaços, equipamentos, preços primários, políticas e
rascunhos antes de abrir a operação. Não são inseridos exemplos de produção.

## Preparar o catálogo — Admin

1. Em **Galeria → Administração → Espaços e pontos**, cadastre um espaço:
   nome, endereço público adequado e responsável. Não publique dados pessoais
   desnecessários de alunos.
2. Cadastre os pontos: posição, início/fim da autorização, horários, dias da
   semana e condições. Horários do ponto e formulários usam Brasília;
   dados persistidos usam UTC. `24:00` fecha no início do dia seguinte.
3. Cadastre uma Moldura: código público único, nome, tipo, dimensões/unidade,
   equipamento/estado, descrição, instalação, direitos e entrega. Série,
   resolução e formatos são opcionais. Metadados têm esquema 1 e versões.
4. Use **Alocar ponto** para associá-la a uma autorização temporal. O mesmo
   ponto não recebe dois equipamentos ao mesmo tempo. Uma mudança futura só
   passa se não invalidar contratos e alocações anteriores.
5. **Verificar** confirma equipamento e autorização. A confirmação é uma
   responsabilidade humana do Admin, registrada com senha, motivo e autoria.
   A Moldura recebe seu ID de Autoridade permanente na criação.
6. Cadastre fotografias por **Fotografia**. Até oito JPG/PNG/WebP por Moldura,
   conforme suporte GD do servidor e limites de upload existentes; arquivos
   são decodificados, reduzidos e regravados em JPEG, sem URLs externas.
7. Publique uma **Oferta primária**: preço, MR ou BYC, validade e direitos.
   Informe expressamente se a propriedade física do equipamento está incluída.
   Operar no app, ocupar um ponto e possuir o equipamento são direitos distintos.
8. Publique uma política por espaço/tipo: X cotas inteiras por parte e ID de
   um usuário canônico aprovado destinatário. Confira o nome pelo botão da tela.
   Uma política nova nunca reescreve acordos anteriores.
9. Abra novas contratações somente após revisar essas condições. Sem preço,
   política, autorização, saldo ou financiamento válidos, o servidor rejeita
   a operação pertinente; não há marcação administrativa fictícia de “pago”.

Admin pode editar cadastro sem contratos vigentes, agendar manutenção futura,
arquivar ativos emitidos e excluir apenas rascunhos sem vínculos. Mudanças
que invalidariam reservas ou exposições são bloqueadas. Arquivamento preserva
titularidade, vendas, exposições e resultados.

Cada alteração administrativa pede a senha atual e um motivo. A confirmação
da Galeria bloqueia dez falhas por usuário em 15 minutos; senhas corretas não
consomem esse orçamento. O login e a confirmação administrativa antiga mantêm
seus próprios limites. Senhas não ficam em recibos, auditoria ou armazenamento
do navegador.

## Aquisição, revenda e negociação livre

No catálogo, abra uma Moldura e revise localização, dimensões, condições e
direitos. Ao confirmar a aquisição, saldo, oferta, versão, preço, vendedor e
disponibilidade são conferidos novamente no gate financeiro. Pagamento e
titularidade acontecem juntos. O ID de Autoridade não muda.

Na oferta primária, o dinheiro existente vai à conta canônica de receitas da
Galeria. Na revenda, vai ao titular vendedor. Não há emissão de moeda.

O titular pode anunciar um preço de revenda ou iniciar uma negociação com
comprador identificado. O interessado também pode propor um preço livre.
**Negociar aquisição / revenda** usa propostas, versões imutáveis, contrapropostas
e dois aceites. Enviar uma versão registra o aceite do autor; a outra pessoa
precisa aceitar os mesmos termos. No segundo aceite, a compra ocorre de forma
atômica. Propostas pendentes não reservam o ativo nem cobram saldo.

Mudanças físicas, de autorização ou de direitos cancelam ofertas antigas. Uma
negociação pendente guarda as condições consultadas e exige outra versão se
elas mudarem; a contraparte precisa consentir novamente. Manutenções futuras
aparecem no detalhe da Moldura e na revisão da negociação.

É possível divulgar interesse de venda durante uma exposição, mas a transferência
fica bloqueada enquanto houver contrato/reserva em execução ou pendente de
resolução. Quem compra recebe o ativo e seu histórico; os beneficiários dos
prêmios anteriores continuam sendo as pessoas originais.

## Propor e negociar uma exposição

Uma coalizão envolve dois usuários distintos: titular da Moldura e detentor
autorizado da NFT. O usuário sem Moldura pode iniciar pela sua NFT. O titular
pode identificar uma NFT de outra pessoa pelo ID. Autoexposição não está incluída.

Abra a Moldura e **Propor uma exposição**. Selecione NFT privada com imagem,
política, período, obrigações, evento opcional e percentuais. Os percentuais
aceitam duas casas, inclusive 0%/100%, e precisam somar exatamente 100%.
O servidor usa 10.000 pontos-base, sem float.

A tela de revisão mostra as duas partes, o local, o intervalo, X para cada
parte, total 2X, destinatário, política, licença e divisão eventual do prêmio.
A prévia tem hash dos termos; alterações de autoridade ou mídia exigem nova
revisão. Enviar proposta/contraproposta registra o consentimento do autor
naquela versão. **Minhas Molduras e acordos** permite comparar versões,
aceitar ou recusar. Aceites antigos não autorizam uma versão nova.

O segundo aceite revalida tudo, incluindo saldos e agenda, e guarda as cotas
das duas pessoas na mesma transação. Se falhar, nenhuma parte é cobrada e
nenhuma reserva é criada. Uma proposta pendente não ocupa agenda. Propostas
têm prazo de até sete dias; uma exposição pode durar até 31 dias.

Intervalos são semiabertos `[início,fim)`. Não se sobrepõem Moldura, ponto
físico ou NFT. NFT na Piscina pública é inelegível para um novo acordo; o
antigo depositante não licencia em nome do detentor atual. A retirada normal
por MR/BYC continua funcionando e as cotas continuam independentes da NFT.

Possuir a NFT não prova direitos autorais: cada parte declara a autorização
de exposição e de preservação do registro histórico. O Admin não substitui
o aceite de nenhuma delas nem altera percentuais silenciosamente.

## Cotas, capturas e devoluções

A reserva nativa antiga está vinculada por FK a propostas de NFT/BYC/SHARE.
Para a Galeria, a modalidade é **custódia operacional no ledger canônico**:

- X sai da carteira de cada parte para sua própria conta SHARE `galeria_hold`.
- Essa conta compõe o saldo reservado visível e não pode ser gasta pelos
  fluxos antigos, que só gastam a carteira disponível.
- Como permanece vinculada ao mesmo `user_id`, suas cotas continuam no Q
  e nos direitos do usuário, conforme a fórmula atual de `Periods`.
- Após prestação confirmada, a captura transfere X de cada parte ao destinatário
  aceito. Em eventos, aguarda também o resultado definitivo/liquidação. O
  destinatário passa a participar dos próximos cortes como qualquer titular.
- Não há queima, emissão, conversão ou segunda espécie de cota.

Recusa, expiração ou falha no aceite: não cobrar. Cancelamento consensual antes
do início: duas confirmações e devolução integral. Evento sem mínimo ou
infraestrutura inviável: cancelamento e devolução dos recursos guardados.
Interrupção: suspensão, incidente e resolução motivada sob a regra de devolução
integral aceita; o Admin não inventa descontos ou multas. Exposição cumprida
sem vitória não implica devolução da participação.

Depósito, venda ou alteração administrativa de mídia na Piscina continuam
permitidos. Se afetarem uma exposição ainda vigente, ela é suspensa, assim
como a votação do seu evento. A mídia congelada e os votos não são remapeados.
Retomada exige a autoridade original no prazo e os mesmos termos; caso contrário,
cancele. O prazo de resolução é 24 horas para exposição isolada e o publicado
no evento. O cron cancela compromissos suspensos que esgotarem o prazo.

Se um participante for excluído pela Danger Zone, suas Molduras vão ao registro
de custódia para avaliação, ficam suspensas e não são reemitidas automaticamente.
Devoluções/prêmios devidos a uma conta encerrada vão à custódia canônica dessa
origem, preservando rastreabilidade e direitos, sem reativar o usuário.

## Organizar um evento — Admin

1. Crie o rascunho: título, capacidade (padrão quatro), janela de votação,
   revisão (padrão 24 horas), resolução, unidade e valor líquido do prêmio e
   regulamento. Mínimo igual à capacidade; duas a 32 exposições.
2. Edite o rascunho se necessário. Após **Publicar inscrições**, o regulamento,
   horários, capacidade, percentuais aceitos e prêmio não são alterados.
3. As coalizões selecionam esse evento ao negociar. Precisam cobrir toda a
   votação. Cada Moldura participa uma vez; a ativação inscreve a candidatura.
4. Financie integralmente o prêmio de uma origem existente: receitas da Galeria,
   tesouraria MR/BYC ou carteira do próprio Admin. Não há débito de terceiro,
   emissão de prêmio ou financiamento a partir das cotas.
5. Confirme fisicamente a infraestrutura de cada exposição.
6. **Preparar** exige capacidade completa, candidaturas/autoridades válidas,
   infraestrutura confirmada e prêmio financiado. Congela obra, versão de mídia,
   créditos, acordo, espaço, regras, percentuais e prêmio.
7. Credencie eleitores aprovados. Confira identidade fora do app e informe
   uma referência única por pessoa/evento. Ela é guardada por HMAC com segredo
   privado, não em texto no banco/auditoria. Não use IP como identidade.
8. **Abrir votação** somente dentro da janela publicada. Voto gratuito,
   peso 1, uma conta credenciada por evento. Uma credencial não passa para
   outra conta. Partes interessadas em qualquer candidatura válida não votam.
9. Depois do fim, **Encerrar e apurar** fecha o conjunto e inicia a revisão.
   O cron também encerra votações vencidas. Nenhum voto tardio entra, mesmo
   que a tarefa agendada ainda não tenha executado.
10. Durante a revisão, o Admin pode invalidar voto por ID e motivo; voto original,
    responsável e versão de resultado permanecem. A lista administrativa não
    divulga associação pessoa/escolha. Parciais não são públicas durante votação.
11. Confirme a prestação efetiva das exposições, depois do fim. **Finalizar**
    exige a revisão encerrada e todas as prestações confirmadas. Antes do
    pagamento, uma reabertura motivada usa outra janela da duração aceita.
12. **Liquidar** distribui o prêmio e captura as cotas uma única vez. Repetir
    o comando retorna o estado/recibo, sem outro pagamento. Resultados finais
    e recibos ficam acessíveis aos participantes.

Os QR das Molduras são gerados localmente, sem serviço externo. Seu link
permanente abre o evento associado; os QR das quatro Molduras levam ao mesmo
evento. QR não é senha, credenciamento nem comprovação de presença física.
Uma imagem já vista/copiada não pode ser recolhida dos aparelhos: suspensão
impede novos acessos da Galeria à mídia afetada, sem prometer apagamento remoto.

## Apuração e divisão exata

Mais votos válidos vence. Empatadas dividem reconhecimento e prêmio. Primeiro
divide-se entre candidaturas vencedoras, com resíduos mínimos em ordem estável
do ID da candidatura. Depois, para cada prêmio D:

```text
parte da NFT = floor(D × pontos_base_NFT / 10000)
parte da Moldura = D − parte da NFT
```

Eventual unidade mínima interna fica com a Moldura. MR usa centavos; BYC usa
oito casas. A soma é exatamente o prêmio financiado. Sem votos, não há vencedor
e o prêmio volta à origem; a participação é capturada somente se a prestação
foi cumprida e confirmada. Nenhuma taxa oculta reduz o prêmio anunciado.

Depois de pago, não há edição de resultado ou débito silencioso. Use **Registrar
incidente / conciliação** para documentar o caso; correção financeira posterior
requer conciliação explícita separada, não um botão que apague os lançamentos.

## Ranking, extrato e indicadores

Ranking v1: vitórias em resultados definitivos vigentes, classificação densa
(`1,1,2`), vitórias compartilhadas, participações concluídas e taxa de vitória.
Sem observações, não há taxa fictícia. Sem votos, a participação válida entra
no denominador sem criar vitória. Canceladas/invalidadas não entram.

Filtros de período usam encerramento efetivo; espaço usa o registrado na
exposição. Local atual e histórico não mudam o mérito de eventos anteriores.
Não há desempate por soma global de votos, público ou peso oculto.

O extrato da Galeria referencia operações e partidas do livro existente.
O Admin consulta propostas, negociações, exposições, vendas, políticas,
incidentes, auditoria e conciliação; cada usuário vê somente sua área privada.

Ocupação usa horas de exposição concluída/confirmada sobre horas operacionais
registradas. A verificação/alocação grava disponibilidade prospectiva com ponto,
espaço e horários versionados. Alterações encerram o registro anterior;
manutenção futura é descontada. Não se inventa disponibilidade anterior à
medição. Sem pelo menos um minuto oferecido registrado no recorte, mostra
“não disponível”. O padrão é 30 dias; a consulta aceita até 366 dias. Valores
recebidos são separados por unidade, e receita não é rotulada como lucro.

## Limites desta versão

Credenciamento do Admin limita contas, não comprova automaticamente uma pessoa
física única. Verificação de equipamento, ponto, direitos autorais e prestação
depende de confirmação humana auditada. Não há sensores ou integração externa.

Coalizões têm duas partes; não há autoexposição, venda com transferência de
obrigações em curso, comissão sobre venda da NFT, pontuação ponderada, dinheiro
real ou contratos inteligentes. Até 50 propostas pendentes por pessoa em cada
tipo de negociação e 100 versões por proposta; a agenda aceita exposições de
até 31 dias. Cadastros e tabelas principais são paginados.

Instalação e desativação: [GALERIA_DEPLOY.md](GALERIA_DEPLOY.md).
Resultados executados: [GALERIA_TESTES.md](GALERIA_TESTES.md).
