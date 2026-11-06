# Verificação da integração de Molduras

Validação local em 06/11/2026: PHP 8.3.36, MySQL 8.0.46 e Chromium/Playwright.
Bancos `piscina_test` descartáveis, contas sintéticas e armazenamento separado.
Nenhum acesso de escrita à hospedagem ou ao banco de produção.

## Resultados

| Verificação | Resultado |
|---|---|
| Suíte geral de domínio, incluindo regras financeiras, concorrência, Administração e Galeria | 128 cenários, 0 falhas |
| Integração final das carteiras e Pendências | 15 cenários, 0 falhas |
| Nova interface, API/PHP/MySQL reais, raiz | 13 grupos aprovados |
| Nova interface, API/PHP/MySQL reais, `/piscina` | 13 grupos aprovados |
| Regressão do modo simples da Galeria | 12 grupos aprovados |
| Regressão da Galeria avançada | 12 grupos aprovados |
| Regressão do app nativo | 17 verificações aprovadas |
| Instalação/atualização real do service worker na raiz e em subpasta | 6 grupos aprovados |
| Sintaxe PHP, JavaScript e integridade do diff | Sem erros |

A suíte geral e a execução focada se sobrepõem. Os números não devem ser
somados como cenários distintos. A execução focada final também inclui o caso
adicional de oferta privada enviada pelo proprietário. Os logs aprovados estão
em [RESULTADOS_CARTEIRA.txt](RESULTADOS_CARTEIRA.txt).

## O que os testes exercitam

- Oferta exclusiva não concede propriedade nem debita o destinatário antes da
  compra. Ofertas enviadas aguardam a decisão do comprador, sem inflar as
  tarefas do vendedor, que pode cancelar pelo mesmo protocolo existente.
- Proposta enviada aguarda o outro participante; recebida exige resposta.
  Contraproposta troca o responsável e só pode ser aceita com a versão e os
  percentuais exibidos. IDs iguais em fontes diferentes não colidem.
- Contadores abrangem todas as páginas. Foram verificadas 27 propostas em
  duas páginas, sem repetições, e combinações de oferta, associação e revenda.
- Aceite associa ativos de pessoas diferentes sem cobrança de cotas, espaço,
  data ou horário. Percentuais aparecem por nome e por papel nos dois ativos.
  Relações com ativos de terceiros não adicionam patrimônio.
- Recusa, cancelamento, expiração e encerramento deixam a lista aberta e
  preservam o histórico. Acordo aceito não expira com o prazo de sua proposta.
- Pedido de encerramento conta uma vez para cada participante e somente a
  outra parte precisa confirmar. Contratos avançados conservam os X originais
  e o cancelamento bilateral não devolve cotas antes do segundo aceite.
- Venda durante proposta muda o proprietário canônico, remove o ativo do
  portfólio anterior e impede aceitar a associação desatualizada. Um terceiro
  não passa a ver a negociação privada anterior.
- API ignora tentativa de trocar a identidade pela query. Leituras não
  criam contratos, propostas, lançamentos ou notificações e continuam
  disponíveis com novas contratações em stand-by.
- Perda simulada da resposta de compra após commit recupera o recibo e a
  mensagem confirmada, sem repetir pagamento. Erro de validação preserva os
  campos; cancelamento concorrente impede aceite e preserva a confirmação
  para revisão, com mensagem explícita de operação não concluída.
- A preparação do mesmo formulário fora e dentro da Galeria recarrega os
  termos sem apresentar janelas intermediárias. Os testes aguardam o formulário
  visível antes de confirmar, sem remover a exigência de consentimento.
- Nenhuma página privada, imagem de NFT/Moldura ou resposta de API entra no
  cache do PWA. Há atualização explícita do service worker, com o bloqueio já
  existente para operações pendentes ou de resultado desconhecido.

Responsividade conferida em **320, 360, 390, 412, 700, 701, 768, 1024 e 1440 px**,
sem rolagem horizontal nas três telas da nova integração. As prévias abaixo
usam contas e imagens sintéticas:

- [Moldura própria com NFT de outro participante](images/carteira-moldura-mobile.png).
- [NFT própria associada à Moldura de outro participante](images/carteira-nft-mobile.png).
- [Pendência recebida com nomes, percentuais e ações](images/carteira-pendencias-mobile.png).

## Reproduzir em desenvolvimento

Os testes de domínio apagam e recriam exclusivamente o banco `piscina_test`.
Use apenas um ambiente local isolado com `environment=demo`; não a hospedagem.
O arquivo privado definido por `PISCINA_CONFIG` deve apontar para esse banco.

```sh
php tests/run.php
PISCINA_TEST_FILTER='Carteira:' php tests/run.php
```

Para os testes de navegador, prepare outro banco demo vazio, migre e defina
privadamente `PISCINA_DEMO_ADMIN_PASSWORD` e `PISCINA_DEMO_PASSWORD`. O gerador
recusa banco preenchido e não o apaga:

```sh
php tests/molduras-simples-demo.php > /tmp/molduras-fixture.json
GALERIA_FIXTURE=/tmp/molduras-fixture.json TEST_URL=http://127.0.0.1:8148 \
  node tests/molduras-carteira-browser.cjs
```

Defina `TEST_BASE_PATH=/piscina` para testar subpasta e `TEST_ARTIFACTS` para o
diretório de evidências. Playwright/Node são ferramentas de desenvolvimento;
nada disso é necessário no servidor compartilhado.

## Limites

Os resultados são locais, sem medir a capacidade do plano da Superdomínios.
Não foi feito envio SMTP real nem teste em aparelhos físicos ou instalação
standalone nesta entrega. Os testes não substituem a conferência de HTTPS,
caminhos e permissões após a implantação. Não houve alteração de schema e não
foi necessário novo teste de migração: as migrações 004/005 são pré-requisitos.
Atualizações entre pessoas/dispositivos aparecem na próxima consulta/navegação;
não há canal novo de notificações nem sincronização em tempo real.
