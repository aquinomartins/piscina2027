# Home ilustrada

A ilustração fornecida foi adaptada para o banner de “Explorar mercado”.
O texto e o link “Conhecer a piscina” continuam em HTML. No desktop, a arte
ocupa o fundo, com uma transição verde para preservar a leitura. Até 950 px,
texto e imagem ficam empilhados. A página da Piscina e as demais telas
mantêm sua apresentação anterior.

![Banner no desktop](images/home-desktop.webp)

![Banner no celular](images/home-mobile.webp)

## Escopo e arquivos

São seis arquivos públicos: `index.php`, `assets/app.js`, `sw.js`,
`assets/home-hero.css`, `assets/piscina-home-1280.webp` e
`assets/piscina-home-2125.webp`. Não há alteração de banco, configuração,
autenticação ou regras do jogo. O CSS novo é limitado à classe `hero-home`.

A imagem usa WebP em duas resoluções: 140.172 e 292.620 bytes. `picture` e
`srcset` selecionam a imagem; há dimensões explícitas e prioridade de
carregamento para o banner. A ilustração é decorativa e não duplica a leitura
do texto por leitores de tela. O link tem pelo menos 44 px de altura.

O service worker utiliza um cache novo e inclui os três novos recursos
estáticos. A atualização continua pelo fluxo existente de “Atualizar app”,
sem forçar uma recarga durante operações.

## Instalar no cPanel

Esta versão usa como base a `main`, commit `0030beb`, que contém o merge
da PR #9. Preserva a Danger Zone, a troca de imagem com auditoria, a
recuperação de senha e o módulo administrativo no cache do PWA.

`Piscina_Home_Ilustrada.zip` atualiza somente os seis arquivos públicos
acima, com este guia, suas prévias e hashes. **Pressupõe que a PR #9 já
esteja implantada na hospedagem, inclusive a migração 003.** O merge no
GitHub não instala os arquivos nem a migração no cPanel.

Se a hospedagem ainda não recebeu a PR #9, use o pacote atualizado
`Piscina_Danger_Zone.zip`, que agora inclui a home ilustrada e todas as
dependências administrativas. Siga primeiro a ordem de implantação de
[DANGER_ZONE.md](DANGER_ZONE.md), com a migração 003 antes dos arquivos.
Não use o ZIP anterior da home gerado sobre `81702ac`.

1. Faça uma cópia dos seis arquivos existentes da instalação.
2. Extraia o pacote fora da pasta pública. No Gerenciador de Arquivos,
   copie o conteúdo de `public/` para
   `/home/oftalmol/aquino.bsb.br/public/`, preservando as subpastas.
3. Recarregue `https://aquino.bsb.br/`. Em instalações existentes do PWA,
   use “Atualizar app” quando aparecer, após concluir operações em andamento.
4. Confira a home no computador e no celular, o link “Conhecer a piscina”,
   a volta para “Explorar” e os controles da Danger Zone na Administração.
   Para quem já implantou a PR #9, esta alteração visual não exige SQL nem
   alteração do cron.

Envie os recursos antes de `index.php` e deixe `sw.js` por último, para que
o novo cache encontre todos os arquivos. Não altere `config/local.php`.
Para reverter somente esta alteração visual, restaure os arquivos salvos
em conjunto, inclusive o service worker.

## Verificação local

- PHP 8.3: `public/index.php` aprovado na verificação de sintaxe.
- JavaScript: `app.js` e `sw.js` aprovados em `node --check`.
- Chromium com o frontend real e API simulada, sem acessar dados de produção:
  320, 360, 390, 412, 700, 701, 768, 950, 1024, 1280, 1440 e 1920 px,
  na raiz e em `/piscina`. Imagem carregada, sem rolagem horizontal,
  link visível e tocável, navegação de ida e volta preservada.
- Suíte existente de PWA: seis grupos aprovados, incluindo instalação,
  atualização real do service worker, aviso na home, menu do usuário e
  proteção contra recarga durante operação pendente.
- Base integrada com a PR #9: sete grupos da jornada de troca de imagem e
  oito grupos da jornada da Danger Zone aprovados com PHP/MySQL reais em
  demonstração descartável. Incluem permissões, auditoria, multa, banimento,
  redefinição de senha, recuperação de recibo e custódia. O backend e o
  módulo `admin-danger.js` permanecem iguais aos da PR #9.

A mudança foi preparada para revisão. Não foi publicada na hospedagem.
