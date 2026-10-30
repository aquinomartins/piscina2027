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

O pacote incremental foi gerado sobre `main`, commit `81702ac`, e contém
somente os seis arquivos acima, este guia, suas prévias e hashes. Ele não contém a Danger
Zone do PR #9, que ainda estava aberta ao preparar esta alteração. Se você
já instalou aquele PR ou outras mudanças posteriores em `app.js`, `index.php`
ou `sw.js`, integre as alterações pelo Git antes de gerar um pacote para essa
versão; não substitua esses arquivos com uma versão anterior.

1. Faça uma cópia dos seis arquivos existentes da instalação.
2. Extraia o pacote fora da pasta pública. No Gerenciador de Arquivos,
   copie o conteúdo de `public/` para
   `/home/oftalmol/aquino.bsb.br/public/`, preservando as subpastas.
3. Recarregue `https://aquino.bsb.br/`. Em instalações existentes do PWA,
   use “Atualizar app” quando aparecer, após concluir operações em andamento.
4. Confira a home no computador e no celular, o link “Conhecer a piscina”
   e a volta para “Explorar”. Não é preciso executar SQL ou alterar o cron.

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

A mudança foi preparada para revisão. Não foi publicada na hospedagem.
