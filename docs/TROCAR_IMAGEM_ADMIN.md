# Trocar imagem da NFT — somente Administração

## Como usar

1. Entre com sua conta administrativa e abra **Administração → Imagens das NFTs**.
2. Informe o **ID da NFT** — por exemplo, **52** — e clique em **Consultar NFT**.
3. Confira título, criador, custódia e imagem atual. Clique em **Trocar imagem da NFT**.
4. Selecione JPG, PNG ou WebP válido, até **5 MB** e **16 milhões de pixels**. Confira a prévia.
5. Informe o motivo (5–255 caracteres), marque a confirmação e digite sua senha atual.
6. Clique em **Confirmar troca de imagem**. Aguarde o recibo e confira a nova miniatura.

A versão principal é reencodificada para JPEG com lado maior de até 1600 px; a miniatura tem lado maior de até 480 px. A troca mantém ID, título, criador, proprietário, custódia, cotas e saldos.

NFTs reservadas em propostas não podem ter a imagem trocada. Finalize ou cancele a proposta pelas regras existentes e consulte a NFT novamente. Se outra edição, depósito, retirada ou transferência ocorrer depois da consulta, a versão de edição expira e a troca é recusada; os arquivos atuais permanecem associados.

## Auditoria e imagens anteriores

A operação `admin.nft.image_replace` registra o administrador, data, motivo, NFT e referências/hashes das imagens anterior e atual em `audit_events`. Um evento `IMAGE_REPLACED` também aparece na proveniência da NFT. A senha não integra o payload econômico, o hash de idempotência nem a auditoria.

Os arquivos anteriores são preservados em `storage/images` enquanto houver referência na auditoria administrativa. O Cron mantém essa referência e continua removendo os arquivos realmente órfãos. Por isso uma cópia do banco **e de `storage`** continua necessária para recuperar versões após um reset ou restauração do banco.

O envio único do criador permanece igual. O privilégio de substituição pertence exclusivamente ao Admin. Administradores podem também preencher uma imagem ainda pendente, consumindo a associação inicial da NFT; isso fica auditado como intervenção administrativa.

## Proteções

- Sessão e cargo administrativo conferidos no servidor, CSRF, senha atual e limite de tentativas.
- Motivo, confirmação explícita, chave de idempotência e versão de edição obrigatórios.
- Arquivo recebido como upload real, MIME/decodificação/dimensões verificados e reencodificação para JPEG; o cliente não fornece caminhos de armazenamento.
- Troca da associação e auditoria na mesma transação, sob o gate e trava da NFT. Não cria movimentações contábeis nem processa calendário/reservas.
- Duas edições sobre a mesma versão têm um único vencedor. Repetir a mesma requisição retorna o recibo, sem duplicar o evento; alterar conteúdo com a mesma chave é recusado.
- Recuperação de recibo com endpoint administrativo e mesmo autor, para resposta perdida após commit.
- URLs das imagens incluem uma versão opaca, para as telas receberem a miniatura atual sem reutilizar o cache da imagem anterior. O PWA continua sem armazenar HTML privado, API ou imagens de usuários.

## Instalar a atualização na Superdomínios

Base: versão que contém a Administração da PR #6. O pacote não contém SQL, dados, senhas ou configuração privada.

1. Faça backup dos arquivos abaixo, do banco e de `storage`. Guarde os backups fora da pasta pública.
2. Baixe **`downloads/Piscina_Troca_Imagem_Admin.zip`** da versão desta atualização no GitHub e extraia no computador.
3. Pelo Gerenciador de Arquivos do cPanel, atualize o conjunto completo em `/home/oftalmol/aquino.bsb.br/`, preservando os caminhos:

   - `app/AdminImageUpload.php` — novo upload administrativo;
   - `app/NftImage.php` — versões e referências de imagem;
   - `app/Domain.php` — operação administrativa e auditoria;
   - `app/Queries.php` — consulta administrativa e versão opaca das imagens;
   - `app/Upload.php` — limpeza preserva arquivos ligados à auditoria administrativa;
   - `public/api.php` — endpoints administrativos;
   - `public/index.php` — versão nas URLs de imagens do HTML inicial;
   - `public/assets/app.js` — controles administrativos e URLs de imagem versionadas;
   - `public/assets/app.css` — estilos limitados aos novos controles administrativos;
   - `public/sw.js` — somente versão do cache estático.

4. Use uma janela de manutenção para evitar misturar arquivos de versões diferentes. **Não execute instalação, reset ou migração SQL. Não substitua `config/local.php` ou `storage`.**
5. Abra o site e aplique **Atualizar app** para carregar o JS/CSS atualizados. Confirme o novo painel na Administração.
6. Teste primeiro com uma NFT de homologação, conferindo auditoria, miniatura e carteira. Em uma sessão de participante, confirme que a troca administrativa é inacessível.

Cadastro, autenticação, negociação, depósito, retirada, recebimentos, taxas e parâmetros econômicos preservam seus fluxos. Nas telas públicas, a alteração limita-se à versão nas URLs das imagens e ao rótulo do novo evento de proveniência.

## API

- `GET api.php?action=admin.nft&nft_id=52`: consulta administrativa com `edit_version`, além da versão pública opaca `image_version`.
- `POST api.php?action=admin.nft.image_upload`: multipart com `nft_id`, `expected_version` (valor de `edit_version`), `image`, `reason`, `confirm=1` e `password`; headers `X-CSRF-Token` e `Idempotency-Key`.
- `GET api.php?action=admin.nft.image_result&key=CHAVE`: recibo da operação desse administrador. Não recebe senha nem cria novo upload.
- `admin.nft.image_replace` é uma ação interna. O POST JSON direto é bloqueado; use somente o upload administrativo validado.

## Testes locais

Com `environment=demo` e banco **piscina_test**: `php tests/run.php`. A suíte recria exclusivamente esse banco.

Para interface/API reais, recrie o banco local vazio e execute `php tests/admin-fixture.php`, com `PISCINA_DEMO_PASSWORD` e `PISCINA_DEMO_ADMIN_PASSWORD` de teste. Sirva `public`, configure `TEST_URL` e execute `node tests/admin-image-browser.cjs`. Use fixture nova para cada suíte de navegador, evitando interferência de cenário e limites de tentativas. `tests/browser.cjs` e `tests/admin-browser.cjs` cobrem os fluxos anteriores; `tests/pwa-menu.cjs` verifica instalação/atualização na raiz e em subpasta.

Nenhuma implantação, troca de imagem ou alteração de dados na hospedagem é executada pela preparação deste pacote.
