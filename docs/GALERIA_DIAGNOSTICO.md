# Integração Galeria / Moldura

Base: PR #9 integrada e banner da PR #10 (`d6abf08`). A PR #10 ainda estava
aberta ao iniciar este módulo. A Galeria será proposta sobre essa branch para
preservar o banner e permitir revisão independente. Nenhuma implantação externa.

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
