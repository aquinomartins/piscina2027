# Mecanismo

Mercado com preços e contrapartes escolhidos por participantes aprovados. Administração aprova cadastros e autoriza emissão, sem aprovar negócios particulares. mR$ é moeda simulada, sem conversão para dinheiro real; BYC não é Bitcoin. NFTs são registros únicos internos quando esse perfil for confirmado.

Aprovar inicialmente credita 160000 centavos e uma NFT uma vez. Depósito transfere custódia à piscina, emite 1000000000 unidades BYC e uma cota. Retirada pública transfere NFT e cobra 1100000000 unidades BYC OU 140000 centavos; cota anterior permanece independente. Redepósito concede novamente a recompensa. Um ciclo pela mesma pessoa usando BYC consome 1 BYC líquido e cria uma cota; usando mR$ consome 1400 mR$ e cria 10 BYC e uma cota. Emissões de cotas diluem participações futuras e não prometem lucro.

Propostas reservam somente bens da parte que explicitamente aprova a versão. Segunda aprovação liquida entrega e pagamento em uma transação, sem taxa de negociação. Nova versão libera reservas e invalida aprovações; cancelamento, recusa e expiração também liberam. NFT na piscina não pode ser vendida bilateralmente.

Distribuição D=N×base. Participação D×q/Q, incluindo cotas reservadas, e maiores restos com desempate por ID crescente. Direitos são do titular no corte e não acompanham vendas posteriores. Aceitação converte recebível em saldo; taxa independente por aprovado no corte, inclusive sem cotas. Taxa só é paga por confirmação e saldo disponível; insuficiência mantém dívida. Sem cotas, registra-se período sem distribuição; ausência de distribuição não remove taxa.

| Operação | Estado/condições | Resultado atômico |
|---|---|---|
| Aprovação | Admin reautenticado, e-mail verificado | aprovado, concessão única, NFT inicial, auditoria |
| Depósito | proprietário aprovado, NFT livre | NA_PISCINA, BYC e cota emitidos, evento |
| Retirada | aprovado, NFT disponível, termos vigentes | pagamento único à tesouraria, propriedade privada |
| Proposta | partes aprovadas, termos exatos | AGUARDANDO → PARCIAL → CONCLUIDA |
| Revisão | parte, versão atual, prazo vigente | versão +1; reservas/aprovações anteriores liberadas |
| Cancelar/recusar/expirar | operação ainda aberta | estado terminal; recursos liberados uma vez |
| Receber | direito publicado e do autor | reserva de distribuição → carteira |
| Quitar | obrigação do autor e saldo livre | carteira → tesouraria administrativa |

Lançamentos concluídos são imutáveis. Correções devem usar compensações auditadas, sem editor livre de saldo ou propriedade.
