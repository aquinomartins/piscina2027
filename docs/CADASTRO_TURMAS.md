# Cadastro de turmas e concorrência do limitador

Atualização de 21/10/2026 para a instalação PHP/MySQL da Piscina de Liquidez.

## O que muda

O cadastro passa a combinar três cotas de tentativas em janelas fixas de 15 minutos:

| Escopo | Padrão | Finalidade |
|---|---:|---|
| IP da conexão | 50 | Atender turmas de 15–25 pessoas no mesmo Wi-Fi, com margem para repetições |
| E-mail normalizado, somando as origens | 3 | Limitar repetições do mesmo endereço, inclusive mudando de rede |
| Instalação inteira | 100 | Limitar trabalho e criação de contas/mensagens por várias origens |

São **tentativas**, não vagas garantidas nem número máximo de participantes do jogo. Repetir um e-mail existente também consome tentativas. Formulários com campos inválidos são rejeitados antes das cotas de cadastro. A ordem é IP → e-mail → global: uma chamada bloqueada por IP não cria contador para o novo e-mail nem consome cota global; uma chamada bloqueada por e-mail já consumiu tentativa da origem, mas não consome global.

O IP vem de `REMOTE_ADDR`, preservando o comportamento atual. Cabeçalhos enviados pelo visitante, como `X-Forwarded-For`, não são usados para contornar a proteção. Se houver CDN/proxy na frente do PHP, conferir a configuração confiável de IP no servidor antes da implantação; não confiar em cabeçalhos arbitrários no aplicativo. Um único IP pode gastar sua cota com tentativas abusivas e o teto global pode ser atingido: a mudança é uma proteção básica para o porte atual, não elimina ataques de negação de serviço ou substitui controles do provedor.

O contador usa `INSERT ... ON DUPLICATE KEY UPDATE` para obter diretamente o bloqueio exclusivo e contar atomicamente. A leitura do resultado ocorre na mesma transação. Isso remove a sequência que convertia bloqueios compartilhados de `INSERT IGNORE` em exclusivos de `SELECT FOR UPDATE`, responsável pelos deadlocks reproduzidos nessa tabela. O incremento tem saturação em limite + 1 e não renova a validade de uma janela ativa. O registro é reiniciado quando expira, com tempo UTC do MySQL. A rotina geral de repetição limitada de transações permanece disponível; esta correção não altera os bloqueios contábeis do jogo nem promete eliminar deadlocks de qualquer outra consulta.

Dois cadastros concorrentes com o mesmo e-mail produzem uma conta e um conflito HTTP 409, em vez de expor uma violação de unicidade como HTTP 503. Conta, token e mensagem continuam na mesma transação. As contas nascem pendentes, sem verificação de e-mail ou aprovação automática.

## Instalar pelo cPanel

Use o pacote incremental **`downloads/Piscina_Cadastro_Turmas.zip`**. Ele contém apenas `app/Auth.php`, este guia, o resumo dos testes e um manifesto de hashes. Não inclui configurações privadas, banco, dados, imagens, JavaScript, CSS ou scripts de teste.

1. Baixe uma cópia do arquivo atual `/home/oftalmol/aquino.bsb.br/app/Auth.php` para seu computador.
2. No Gerenciador de arquivos do cPanel, abra **`/home/oftalmol/aquino.bsb.br`**, a pasta que contém `app`, `config`, `public` e `storage`. A raiz pública do site continua sendo a subpasta `public`.
3. Carregue o ZIP e extraia nessa pasta. Confira a árvore antes de substituir: o arquivo novo deve ficar em **`/home/oftalmol/aquino.bsb.br/app/Auth.php`**. Se preferir, extraia em uma pasta temporária privada e substitua somente esse arquivo. Não extraia dentro de `public`.
4. Mantenha a permissão habitual de arquivo PHP (normalmente 0644). Não substitua `config/local.php` nem execute SQL. Os valores padrão funcionam com a configuração privada existente.
5. Aguarde a revalidação do OPcache e abra o site novamente. Se o provedor mantiver OPcache sem revalidação automática, solicite a atualização/reinicialização do PHP pelo painel ou suporte. Não crie uma ferramenta pública para limpar cache.
6. Confira um cadastro novo e o login de uma conta existente. A confirmação por e-mail continua dependendo do Cron e do SMTP já configurados. Em uma turma real, acompanhe HTTP 429/5xx e os logs; não gere cadastros fictícios em massa na produção para testar.

Os contadores existentes são preservados. Uma origem que já ultrapassou 50 tentativas, ou um limite de login já atingido, pode permanecer bloqueada até o fim de sua janela. Não é necessário limpar `rate_limits`, reiniciar o jogo ou alterar saldos.

Para reverter o código, restaure a cópia de `app/Auth.php` e revalide o OPcache. Não apague contas ou contadores: as contas criadas durante a atualização são dados válidos. O código antigo volta a aplicar a cota antiga de cinco tentativas por IP, e os contadores adicionais são ignorados por ele.

## Configuração opcional

O bloco abaixo pode ser adicionado ao array retornado por `config/local.php`, sem alterar suas credenciais ou outras configurações:

```php
'registration_limits' => [
    'per_ip' => 50,
    'per_email' => 3,
    'global' => 100,
],
```

Omitir o bloco ou uma chave utiliza os padrões acima. Os valores precisam ser inteiros positivos; os máximos aceitos são 500 por IP, 10 por e-mail e 1.000 globais. Zero não desativa a proteção: configurações inválidas são recusadas. Ajuste somente após dimensionar a turma e os recursos; aumentar cotas não aumenta a CPU ou a capacidade de envio de e-mails. As janelas continuam em 900 segundos, contadas a partir da primeira tentativa da respectiva cota.

## Proteções preservadas

- Login: dez tentativas por combinação IP + e-mail em 15 minutos.
- Reenvio/recuperação de token: quatro tentativas por combinação IP + e-mail em 15 minutos.
- Confirmação de senha em ações sensíveis: dez tentativas por usuário em 15 minutos.
- CSRF, sessão, hash de senha, tokens de uso único e aprovação do participante mantêm seu fluxo.
- Movimentações financeiras, NFTs, cotas, BYC e auditoria não foram modificados.

## Testes reproduzíveis

As suítes PHP recusam ambientes diferentes de `demo` ou banco diferente de `piscina_test`. **Não executar na hospedagem de produção.**

```sh
PISCINA_CONFIG=/caminho/privado/config-de-teste.php php tests/auth-limits.php
PISCINA_CONFIG=/caminho/privado/config-de-teste.php php tests/run.php
```

`auth-limits.php` verifica janelas, saturação, expiração, compatibilidade com contadores antigos, concorrência de 32 conexões na mesma chave nova/existente/expirada, turma de 25, limites por origem/e-mail/global e e-mail duplicado. Limpa apenas suas fixtures de usuários pendentes, tokens e outbox, além dos contadores desse banco de teste. `tests/run.php` apaga e recria o banco de teste inteiro para verificar as regras do jogo.

Para a integração HTTP, preparar banco de teste com 50 endereços `turma-http-0..49@local.invalid` ainda não cadastrados e contadores limpos; servir a API em ambiente demo local com múltiplos workers, usando esse banco. O cliente recusa URLs públicas:

```sh
TEST_URL=http://127.0.0.1:8131 TEST_OUTPUT=/tmp/auth-http.json node tests/auth-http.cjs
```

Ele executa duas rajadas de 25 cadastros no mesmo IP, confere a rejeição das tentativas 51–55 (inclusive com cabeçalho de proxy inventado), login posterior, contas pendentes sem crédito, proteção contra acesso ao jogo, CSRF, logout e limites de senha/recuperação. O teste não envia SMTP; as mensagens ficam na outbox do banco descartável. Os resultados da execução local desta atualização estão em `docs/RESULTADOS_CADASTRO_TURMAS.txt`.
