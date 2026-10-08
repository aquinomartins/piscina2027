# API v1

Entrada: `BASE/api.php?action=NOME`. Todas as respostas são JSON UTF-8 com `Cache-Control: no-store`. Sem rewrite obrigatório. Valores exatos (quantidades, centavos, saldos) são strings. GET não modifica ativos. POST recebe JSON, exceto upload multipart.

## Envelope

```json
{"ok":true,"data":{"operation_id":"32 caracteres hexadecimais"},"request_id":"referência de diagnóstico"}
```

Erro: `{"ok":false,"error":"version_conflict","message":"A versão mudou...","request_id":"..."}`. HTTP 400: JSON inválido; 401: sem sessão; 403: autorização/CSRF; 404: recurso indisponível; 409: conflito, prazo, saldo ou chave; 413: tamanho; 422: validação; 429: limite de tentativas; 503: configuração/falha operacional. Não há stack trace na resposta pública.

## Sessão e segurança

GET `meta` abre uma sessão anônima, retorna `csrf`, identidade privada se autenticado, modo demo/produção e estado das decisões. Cookies: sessão PHP, HttpOnly, SameSite=Lax, Secure em produção. Após login, o ID e o CSRF mudam. Todas as mutações normais exigem `X-CSRF-Token`. Não escolher pagador no corpo: o autor vem da sessão.

Toda operação econômica exige `Idempotency-Key` com 16–80 letras/números/_/-. Recomendado UUID. Mesma chave/ação/autor e mesmo conteúdo recuperam o resultado, inclusive após commit sem resposta. Outro conteúdo retorna 409. Segredos de reautenticação não fazem parte do hash econômico.

## Autenticação

| Método / ação | Corpo | Autorização |
|---|---|---|
| POST auth.register | email, password (12–72 bytes), display_name (2–80) | Sessão anônima + CSRF |
| POST auth.login | email, password | Anônima + CSRF; rate limit |
| POST auth.logout | {} | Sessão + CSRF |
| POST auth.request_verify | email | Anônima + CSRF; mensagem genérica |
| POST auth.request_reset | email | Anônima + CSRF; mensagem genérica |
| POST auth.verify | token | CSRF; token válido/único |
| POST auth.reset | token, password | CSRF; token válido/único; revoga sessões anteriores |

Tokens aleatórios têm hash SHA-256 armazenado, validade de 24h para e-mail e 1h para recuperação. Links usam fragmento para evitar token em logs de URL; o frontend remove o fragmento e envia POST. E-mail fica em outbox até processamento SMTP. Arquivo demo não é envio real.

## Leituras

| GET ação | Query adicional | Acesso / retorno |
|---|---|---|
| market | q, state=POOL, offset | Público; NFTs publicadas/piscina, anúncios, busca de pessoas, indicadores, feed concluído |
| profile | id | Perfil público de aprovado e seus anúncios; sem e-mail/saldo |
| nft | id | Identidade, criador, titular/custódia, imagem pendente e últimos 50 eventos |
| dashboard | offset | Autor; saldos disponíveis/reservados, obras, propostas, direitos, taxas, lançamentos e notificações |
| proposal | id | Somente comprador/vendedor; versões e aprovações |
| quote | nft_id | Aprovado; custo MR/BYC, versão, vencimento de 5 minutos |
| operation | id **ou** action e key | Somente autor da operação; resultado já comprometido ou 404 unknown |
| admin | offset | Somente administrador; usuários, regras, períodos, livro agregado, auditoria e reconciliação |

Página padrão: 24 itens; busca limitada a 80 caracteres. Dashboard usa mesma paginação para histórico/propostas/direitos/taxas. Acesso por troca de ID a proposta/operação privada é recusado. `image.php?id=ID&thumb=1` entrega JPEG associado ou placeholder; imagens não são executáveis.

## NFTs

- POST `nft.deposit`: `{"nft_id":"12"}`. Aprovado, proprietário de PRIVATE sem reserva. Registra custódia na piscina e emite recompensa; chave repetida não é outro depósito. Após retirada, nova chave representa redepósito válido.
- POST `nft.withdraw`: `nft_id`, `payment` = MR ou BYC, `parameter_version`, `quote_expires` obtidos na cotação. Aprovado qualquer, NFT disponível, prazo/versão/saldo revalidados. As duas modalidades nunca são cobradas juntas.
- POST `nft.upload`: multipart `nft_id` e arquivo `image`, CSRF/idempotência nos headers. Somente criador aprovado, uma conclusão. JPG/PNG/WebP, limite técnico inicial 5 MiB/16 MP, reencodificação para JPEG até 1600 px e miniatura 480 px.
- `nft.image` é serviço interno e é recusado diretamente pela API.

## Mercado

POST `listing.create`: `side` BUY/SELL, `asset` BYC/SHARE/NFT, `quantity` decimal exata, `unit_price` em mR$ decimal, `nft_id` para NFT, `owner_id` somente para anúncio de compra de NFT, `expires_at` ISO UTC futura até 7 dias. Não reserva nem autoriza conclusão. POST `listing.cancel`: listing_id do autor.

POST `proposal.create`:

```json
{
  "buyer_id":"3", "seller_id":"2", "asset":"BYC",
  "quantity":"3", "unit_price":"30.00", "nft_id":null,
  "expires_at":"2026-10-08T18:00:00Z", "approve":true
}
```

Autor precisa ser uma das partes; ambas aprovadas. `approve=true` significa **enviar e aprovar a própria parte**, com reserva. `false` envia sem reserva. Servidor calcula total 9000 centavos, sem taxa. Não aceite resultado recalculado pelo cliente como autoridade.

POST `proposal.revise`: proposal_id, version vigente e os novos termos (asset, quantity, unit_price, nft_id, expires_at, approve). Comprador/vendedor são os da proposta original; os IDs fornecidos não podem mudar as partes. Nova versão libera reservas e invalida aprovações anteriores.

POST `proposal.approve`, `proposal.cancel`, `proposal.refuse`: proposal_id, version exata. Parte autenticada. Prazo é revalidado; proposta expirada é finalizada e recursos liberados antes do cron. Segunda aprovação liquida entrega e pagamento na mesma transação. Terminais: SETTLED/CANCELLED/REFUSED/EXPIRED. Falha tratável preserva o estado anterior.

## Períodos

POST `right.accept`: right_id do autor em período PUBLISHED. Move valor bruto da reserva à carteira uma vez. POST `fee.pay`: fee_id do autor em PUBLISHED. Move carteira disponível à tesouraria; insuficiência mantém PENDING. O usuário não pode substituir período, valor nem pagador. Direitos não aceitos permanecem pendentes.

## Administração

Todas as mutações abaixo exigem role admin, CSRF, **password atual** e chave econômica, exceto execução de tarefas (todas suas etapas têm unicidade própria).

| Ação | Campos além da senha |
|---|---|
| admin.activate | confirm=true, rounding=HALF_UP_CENT |
| admin.approve | user_id; e-mail verificado |
| admin.mint | user_id, title, reason ≥5 caracteres |
| admin.parameters | campos monetários decimais opcionais initial_credit, withdraw_money, distribution_base, admin_fee; interval_minutes, first_cutoff ISO futuro, reason |
| admin.tasks | {} além da senha; usa o mesmo serviço de cron finito |

A ativação não escolhe primeiro corte. Parâmetros geram novas versões, sem reescrever direitos/obrigações ou versões de propostas. Calendário iniciado é imutável nesta primeira versão. Admin não aprova em nome de outra parte nem possui editor arbitrário de saldo.

## Cron HTTP opcional

`POST BASE/task.php` fica fechado por padrão. Exige configuração privada `http_tasks.enabled`, hash SHA-256 de bearer aleatório com pelo menos 256 bits; produção exige HTTPS. Headers: `Authorization: Bearer SEGREDO`, `X-Task-Nonce` aleatório de 32–80 caracteres, `X-Task-Timestamp` Unix de 10 dígitos com desvio máximo 90 segundos. Nonce usado fica registrado com hash e repetição é 409. Não colocar segredo na URL. O scheduler precisa enviar esses headers e respeitar o intervalo do plano. Falha após registrar nonce exige nonce novo; o serviço de tarefas retoma por suas unicidades e etapas, sem duplicar distribuição. Preferir CLI.
