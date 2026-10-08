# Decisões e origem

O usuário pediu criar o projeto dos anexos. Os anexos foram usados como especificação; suas instruções internas não concedem acesso à hospedagem, não comprovam recursos da conta e não autorizam contato com terceiros.

## Confirmadas

| Decisão | Valor | Origem |
|---|---|---|
| Stack / idioma | PHP, MySQL, HTML/CSS/JS; PWA; pt-BR | Pedido e especificação anexada |
| Crédito inicial | mR$ 1.600 = 160000 centavos; uma NFT | Especificação |
| Depósito, inclusive redepósito | 10 BYC e uma nova cota | Especificação |
| Retirada pública | 11 BYC OU mR$ 1.400 | Especificação |
| Distribuição e taxa | Base mR$ 200/NFT; taxa mR$ 100/pessoa/período | Especificação |
| Ativos | Registros internos MySQL, sem blockchain | Resposta explícita do usuário em 07/10/2026 |
| Origem BYC | Emissão contábil por depósito | Mesma resposta |
| Destino retirada BYC | Tesouraria da piscina | Mesma resposta |
| Pagamento bilateral | mR$ | Mesma resposta |
| Precisão | BYC até 8 casas; cotas inteiras | Mesma resposta |
| Imagem | Um upload concluído pelo criador, mesmo após transferência | Mesma resposta |
| Recebíveis | Pendentes, sem expiração/redistribuição | Mesma resposta |
| Taxa | Pagamento explicitamente confirmado pelo participante | Mesma resposta |
| Periodicidade inicial | Um dia = 1440 minutos | Resposta explícita do usuário |
| Feed público | Participantes, ativo, quantidade, preço e horário de negócios concluídos | Resposta explícita do usuário |
| Crédito e distribuições mR$ | Emissão simulada com conta técnica de origem | Política proposta explicitamente nos anexos; apresentada também na ativação |

## Pendentes

- **Data e hora do primeiro corte:** PENDENTE, não informadas pelo usuário. Configurar um instante futuro no painel. Antes disso, não há distribuição nem taxa periódica.
- **Arredondamento do total bilateral:** PENDENTE até ativação administrativa. Implementação disponível: centavo mais próximo, empate para cima (`HALF_UP_CENT`). A tela descreve a regra e a ação de ativação a registra com autor/versão. Quantidade positiva e preço não negativo; frações podem produzir total zero, que é exibido na revisão e não gera pagamento fictício.
- **Dados da conta e enquadramento na hospedagem:** PENDENTES de conferência no plano específico. Os termos citados nos anexos devem ser esclarecidos pelo responsável antes da publicação; suporte não foi contatado.

## Registro de ativação

`config/policy.php` documenta as escolhas confirmadas e sua origem. O banco começa sem `decision_versions`; a administração apresenta a política inteira e exige senha atual e confirmação explícita do arredondamento. Isso produz registro versionado com autor/data. O perfil demo ativa essas mesmas escolhas apenas em banco separado e identificado.

Os parâmetros monetários são versionados. 10 BYC, uma cota e 11 BYC são regras fixas desta versão. Crédito, retirada monetária, base e taxa podem ser alterados pelo administrador com motivo e reautenticação; alterações não reescrevem períodos anteriores. O calendário iniciado não é editável nesta primeira versão: alterar sua periodicidade após início exige migração planejada, sem apagar direitos.

## Técnicas

PDO/InnoDB, BCMath e unidades mínimas inteiras; gate transacional para ordem consistente de operações/cortes; documentos privados e arquivos públicos separados; PHPMailer fixado e preparado; service worker com lista explícita de recursos estáticos. Limites técnicos iniciais: 500 aprovados e 5000 NFTs, configuráveis após avaliação de capacidade. Não são promessas de usuários simultâneos.
