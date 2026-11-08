# Acesso à Administração no celular

O link administrativo existia somente na barra lateral. Em telas de até
700 px, essa barra é ocultada e a navegação inferior não tinha o destino
administrativo. A identificação da permissão estava correta.

A navegação inferior agora inclui **Administração**, com escudo e texto,
somente quando a sessão identifica `role=admin`. O link usa a rota existente
`index.php?view=admin`. A visibilidade é definida no PHP e atualizada pelo
`meta` após login/logout. As autorizações das APIs e o painel permanecem os
existentes, sem alterar suas validações ou as regras do jogo.

Arquivos de aplicação alterados:

- `public/index.php`: item na navegação mobile, condicionado ao administrador.
- `public/assets/app.js`: sincronização da permissão no desktop e no mobile.
- `public/assets/app.css`: distribuição dos seis itens, rótulos inteiros nas
  larguras solicitadas e área mínima de toque de 44 × 44 px.
- `public/sw.js`: nova versão do cache estático para entregar os recursos
  atualizados no PWA, mantendo a ativação explícita.

## Verificação local — 08/11/2026

PHP 8.3/MySQL 8 reais, contas sintéticas e Chromium/Playwright:

- **360, 390 e 430 px:** acesso pelo menu, ícone/texto visíveis, contraste de
  pelo menos 4,5:1, alvos ≥44 × 44 px, sem corte, sobreposição ou rolagem
  horizontal. O item permanece após refresh e navegação nas outras telas.
- **320 e 700 px:** verificação adicional dos limites do layout.
- **1440 px:** acesso pela barra lateral desktop preservado.
- **Sessão:** login administrativo mostra o item sem recarga; logout e login
  como participante o ocultam. Refresh mantém a permissão correta.
- **Servidor:** visitante recebe 401 na API administrativa; participante
  recebe 403 nas consultas e em tentativa de mutação administrativa, com CSRF
  válido. Abrir a URL administrativa como participante não mostra o painel.
- O teste existente de instalação/atualização do PWA passou em seis grupos,
  na raiz e em subpasta (API simulada, service worker real).
- Sintaxe PHP/JavaScript e integridade do diff conferidas.

Foram oito grupos de verificação da navegação/servidor, sem erros JavaScript.
Não houve teste em aparelhos físicos, Safari/iOS ou na hospedagem real.
As verificações de tela usam emulação de largura no Chromium.

## Atualizar a instalação

O pacote atual `downloads/Piscina_Portfolio_Pendencias.zip` já inclui a
correção e o runtime compatível da PR #13. Não é necessário importar SQL.
Preserve a configuração privada e o armazenamento conforme
[MOLDURAS_CARTEIRA.md](MOLDURAS_CARTEIRA.md).

Se a instalação já possui os arquivos da PR #13, os quatro arquivos de
aplicação acima são os únicos necessários para esta correção. Envie recursos
estáticos antes de `index.php` e **`sw.js` por último**. Depois use
**Atualizar app** no PWA e confira o acesso com uma conta administrativa.
