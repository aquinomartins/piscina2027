# Galeria — Molduras no modo simples

O fluxo padrão é **criar → vender → comprar → negociar uma associação**.
Compra da Moldura e associação com uma NFT são operações separadas. A moeda
continua sendo mR$ ou BYC simulados, no livro e nas carteiras existentes.

## Admin: criar e disponibilizar

Entre em **Administração → Gerenciar Galeria → Criar Moldura**. Informe apenas
um nome. Descrição e imagem são opcionais; o código é gerado automaticamente.
Escolha uma das opções:

- **Guardar sem anunciar:** cria o ativo e permite anunciá-lo depois.
- **Colocar à venda para todos:** informe preço e moeda.
- **Oferecer a um usuário:** escolha o participante pelo nome e informe preço.

A confirmação administrativa usa a senha atual e registra autoria e horário.
A criação e a oferta podem ser concluídas na mesma transação. Nenhuma oferta
debita o destinatário. Ele precisa entrar, revisar o preço e confirmar a compra.
A venda usa o saldo canônico e entrega a propriedade junto com o pagamento.

Na área administrativa, cada Moldura mostra separadamente seu proprietário,
anúncio público/exclusivo, preço e associação. É possível alterar ou cancelar
uma oferta ainda não concluída. A ação **Adicionar imagem** é opcional.

A Moldura simples representa um ativo no aplicativo; não inclui equipamento
físico nem direitos autorais da NFT. Não exige dimensões, autorização de ponto,
espaço, datas de exposição ou verificação física para ser vendida.

## Estudante: comprar e revender

A Galeria tem três áreas principais:

- **Molduras à venda:** catálogo e preço de ofertas públicas.
- **Minhas Molduras:** seus ativos, ofertas exclusivas recebidas e acordos.
- **Propostas:** associações enviadas/recebidas e negociações de compra/revenda.

Em uma oferta, abra **Comprar Moldura**, confira preço e condições e confirme
**Pagar e receber Moldura**. Oferta exclusiva só pode ser comprada pela pessoa
escolhida. Outro usuário não recebe o preço nem o botão de compra dessa oferta.
Saldo insuficiente, preço alterado ou compra concorrente não geram entrega ou
cobrança parcial. Recibos e cliques repetidos não duplicam a compra.

O proprietário pode **Colocar à venda** ou negociar o preço com outro comprador.
Essa negociação tem versões, contrapropostas e dois aceites. Uma proposta de
preço vale sete dias, sem exigir preenchimento de calendário. O anúncio pode
ficar sem vencimento; o vendedor pode cancelá-lo.

## Associar uma NFT, inclusive de outra pessoa

O dono da Moldura abre **Propor associação** e escolhe uma NFT pelo título,
nome do proprietário ou número. Pode escolher uma obra de outra pessoa.
O dono de uma NFT também pode iniciar: **Propor associação**, escolher uma
Moldura disponível e selecionar sua própria NFT. Não precisa comprar a Moldura
nem pedir aprovação rotineira do Admin para propor uma parceria.

A seleção verifica o detentor atual, imagem, disponibilidade e incompatibilidades
com outras associações/contratos. NFT depositada na Piscina pública ou reservada
em outra negociação não está disponível para novo vínculo.

Quando os dois ativos são do mesmo usuário, a interface apresenta **vinculação
direta**. Há uma confirmação do próprio titular, sem inventar outra pessoa ou
exigir um segundo aceite da mesma conta.

## Percentuais livres, com confirmação da versão

Os campos **Percentual da Moldura** e **Percentual da NFT** aceitam 0% a 100%,
com até duas casas decimais. Ao alterar um, a tela sugere o complemento no outro.
O servidor exige soma exata de 100% e usa pontos-base inteiros, sem float.

O nome de cada participante e sua porcentagem aparecem no formulário, na revisão,
na contraproposta e no acordo ativo. O destinatário pode aceitar, recusar ou
contrapropor. O proponente pode cancelar uma proposta pendente.

Enviar uma proposta registra o aceite do autor naquela versão. Uma contraproposta
inicia outra versão e invalida os aceites anteriores para fins de ativação.
Somente o aceite dos mesmos termos produz o acordo. Propostas pendentes não
reservam o ativo; a disponibilidade é revalidada ao concluir.

A formação da associação simples custa **zero cotas, zero mR$ e zero BYC**.
Não debita, reserva ou bloqueia saldo, mesmo com cotas disponíveis iguais a zero.
Não há pagamento opcional de associação nesta versão. A compra da Moldura
continua sendo paga conforme a oferta.

A base registrada para a divisão são **prêmios de eventos expressamente
contratados**, conforme suas regras. A associação simples não promete prêmio,
não cria receita e não participa automaticamente de um evento avançado.
Os percentuais não incidem na venda da NFT ou da Moldura. Ingressar posteriormente
em um evento exige os novos termos e consentimentos aplicáveis, sem alterar o
acordo simples ou seus beneficiários silenciosamente.

## Venda, encerramento e histórico

Propriedade da Moldura, situação do anúncio e situação da associação são
independentes. É possível anunciar enquanto há associação; a transferência
aguarda o encerramento com a anuência das partes. A compra não apaga um acordo
nem substitui seus participantes.

Em **Ver acordo → Solicitar encerramento**, uma parte confirma e a outra recebe
um aviso para confirmar também. Entre ativos do mesmo dono, uma confirmação
basta. O histórico, os percentuais e os recibos anteriores permanecem.

A negociação nativa da NFT, depósito e troca de imagem continuam funcionando.
Se alterarem a autoridade ou a imagem aceita, a associação fica **suspensa**,
com seus termos preservados. As partes podem encerrá-la. Excepcionalmente, o
Admin pode resolver uma associação suspensa com senha e motivo auditado; isso
não é uma aprovação rotineira nem um editor de percentuais ou saldos.

## Recursos originais em stand-by

No painel do Admin, abra a área discreta **Recursos avançados → Configurar
recursos**. Após a migração 005, estas opções começam desativadas para novas
operações avançadas:

| Disponibilidade | O que libera quando habilitada |
|---|---|
| Exposições com espaço e agendamento | Novas propostas pelo fluxo físico original |
| Eventos, premiação e votação | Criação de novos eventos no fluxo original |
| Cobrança de cotas | Uso de políticas com X positivo em novos contratos avançados |

Disponível **não significa obrigatório**. Nenhuma opção passa a cobrar cotas ou
exigir local/horário em uma associação simples. Uma política física pode ter
X = 0; X positivo exige a opção correspondente e o consentimento nesse contrato.
Para um evento físico completo, prepare os locais/políticas e habilite os
recursos relacionados. Não se convertem associações simples automaticamente.

Os controles ficam em `galeria_features`, separados dos termos versionados dos
acordos. O módulo ainda respeita a flag privada `galeria.enabled` e a suspensão
de novas operações. Contratos, propostas, reservas, pagamentos, eventos e cotas
anteriores continuam consultáveis e operacionais conforme seus termos, mesmo
com as novas opções em stand-by. Não há devolução ou recobrança automática.

O estudante só vê entrada discreta para contratos anteriores/recursos opcionais
quando existe histórico ou disponibilidade habilitada. Campos avançados não
aparecem no fluxo simples. O painel original continua acessível nessa área.
Guia preservado: [GALERIA_AVANCADA.md](GALERIA_AVANCADA.md).

## Instalação e limites

Quem já instalou a 004 aplica **005_molduras_simples.sql**, sem reinstalar o
banco ou reiniciar usuários. Não há dados, datas, espaços ou pontos fictícios.
Datas internas de criação, aceite e pagamento continuam registradas.

[Instalação na Superdomínios](GALERIA_DEPLOY.md) ·
[Testes e prévias](GALERIA_TESTES.md).

As verificações físicas e os eventos permanecem no fluxo avançado. Nenhum
pagamento de prêmio é criado por formar uma associação simples. Não houve
alteração da produção ou teste de carga da hospedagem nesta entrega.
