# Integração Galeria / Moldura

Base: PR #9 integrada, banner da PR #10 e Galeria original da PR #11.
A PR #11 foi incorporada à branch `feat/home-ilustrada`; a PR #10 permanece
aberta para `main`. A simplificação usa essa árvore de código, idêntica à base
validada da Galeria, em branch própria. Nenhuma implantação externa.

PHP 8.3, PDO MySQL/InnoDB, BCMath, GD, JS sem framework. Toda mutação econômica
existente passa por `Domain::operation`, transação com repetição limitada e
`economic_gate` antes de ler propriedade/saldo. `Ledger::move` conserva unidades,
grava partidas dobradas e impede gasto de conta não emissora sem fundos.
`Periods` captura todos os saldos SHARE associados a um usuário, inclusive
reservas/custódia, antes da mutação seguinte. Não se altera a fórmula de cortes.

Integrações realizadas:

- Migração 004 aditiva: espaços/pontos, Molduras e versões, alocações temporais,
  titulares, ofertas/vendas, políticas, propostas/versões/aceites, exposições,
  eventos/candidaturas, credenciais/votos, resultados e vínculos contábeis.
- `Galeria` despacha operações dentro do gate existente; serviços separados
  cuidam de catálogo, acordos, eventos, consultas e ranking.
- Cotas: transferência para custódia operacional canônica SHARE do próprio
  participante (`galeria_hold`), preservando seus direitos nos cortes. Captura
  somente após prestação confirmada; destinatário é usuário canônico aprovado,
  explicitamente configurado. Devolução referencia o acordo e vai à carteira
  ou ao fundo de custódia quando a conta tiver sido encerrada. Sem emissão,
  queima ou cotas paralelas. A carteira agrega esse bloqueio ao saldo reservado.
- Prêmio: MR ou BYC existentes, de tesouraria ou carteira do próprio financiador,
  integralmente separados em conta operacional por evento antes de votar.
- Mudanças antigas de custódia/imagem continuam permitidas. Um gancho mínimo
  reconcilia acordos afetados e suspende a votação; não bloqueia a Piscina.
  Versões de mídia referenciadas por exposições devem permanecer preservadas.
- API/sessão/CSRF/reauth Admin e idempotência atuais; novo JS/CSS isolados,
  navegação Galeria e painel Admin; apenas recursos estáticos no PWA.
- Cron limitado e comandos Admin compartilham as mesmas transições.

Ativação comercial pendente por padrão: preço, X, destinatário, autorização
física e financiamento devem ser publicados pelo Admin. Exemplos pertencem
somente às fixtures. Desligar novas contratações preserva consultas e resolução
dos contratos existentes. A flag privada começa falsa em produção.

Linha de base e resultados efetivamente executados estão em
[GALERIA_TESTES.md](GALERIA_TESTES.md), incluindo regressão e corridas com
conexões independentes. `Auth.php` e `Periods.php` permanecem idênticos à base.

## Simplificação de 06/11/2026

A exigência de agenda/local estava nos formulários de `galeria.js`, em
`Coalizoes::terms`/`Molduras::operational` e nos campos NOT NULL dos acordos
físicos da 004. Retirar somente os inputs não resolveria a obrigatoriedade.
O fluxo de associação original já verificava as duas partes no servidor, mas
a seleção de NFTs da interface dificultava iniciar uma parceria com terceiros.

O modo padrão agora usa `MolduraLinks`, com versões, dois aceites (ou confirmação
direta de mesmo dono), gate e idempotência canônicos. Tabelas próprias da 005
representam o novo tipo de contrato sem forjar espaços, datas ou políticas.
Não há nova carteira. Compra e revenda reutilizam `Molduras`, `MolduraTrades`,
ofertas/vendas e `GaleriaFinance` existentes. Oferta exclusiva aproveita o
`buyer_id` já presente; o único ajuste em coluna anterior é permitir vencimento
nulo nas ofertas. Nenhuma linha histórica é convertida.

As opções de `galeria_features` começam indisponíveis para novos contratos
avançados, mas não reescrevem propostas/contratos anteriores. Disponibilizar um
recurso não o torna obrigatório no protocolo simples, cujos termos gravam
modalidade, versão de regras, percentuais e ausência de cobrança. O frontend
`molduras-simples.js` isola a experiência principal; o painel original permanece
na área opcional. O PWA continua com atualização explícita e somente estáticos
no cache. `Auth.php` e `Periods.php` permanecem sem alterações nesta simplificação.
