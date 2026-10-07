# Piscina de Liquidez

Aplicação PHP/MySQL para mercado de arte com **moeda exclusivamente simulada**, em português brasileiro. mR$ não possui conversão para dinheiro real. BYC não é Bitcoin. NFTs desta versão são registros únicos internos, sem blockchain.

## O que está implementado

- Cadastro, login, logout, verificação de e-mail, recuperação de senha, sessões privadas e aprovação administrativa com concessão única de mR$ 1.600 e uma NFT.
- Livro contábil por ativo, contas de emissão/tesouraria/reservas, idempotência, auditoria e reconciliação.
- Upload único pelo criador, reencodificado com GD, miniatura e arquivos privados.
- Depósito/redepósito com 10 BYC e uma cota; retirada pública por 11 BYC **ou** mR$ 1.400.
- Anúncios, busca, perfis, propostas, contrapropostas, aprovação por versão, reservas próprias e liquidação bilateral atômica.
- Cortes consistentes, processamento recuperável em lotes, direitos brutos por maiores restos, aceitação explícita e taxa independente.
- Administração, histórico, notificações, feed público autorizado, interface móvel, PWA e página offline sem operações automáticas.

## Requisitos de execução

PHP **8.3+ de 64 bits**, extensões PDO/PDO MySQL, BCMath, GD, mbstring, fileinfo e OpenSSL; MySQL/InnoDB com utf8mb4. Testado localmente em PHP 8.3.6 e MySQL 8.0.46. MariaDB e a conta específica da Superdomínios não foram verificados. HTTPS em produção e SMTP configurado.

**Não há Node, Docker, Composer, Redis, WebSockets ou serviço externo obrigatório em produção.** PHPMailer 6.10.0 e a fonte Inter estão incluídos; não há dependências de CDN na aplicação. Node/Playwright são ferramentas opcionais de teste local.

## Começar em ambiente próprio

1. Crie um banco MySQL vazio e seu usuário. Copie `config/example.php` para `config/local.php` e preencha privadamente SQL, URL, caminho-base, SMTP e armazenamento.
2. Mantenha `environment=production` para uso real. Apenas `public/` pode ser servido pela web.
3. Execute `php bin/migrate.php` ou importe `database/install.sql` no gerenciador de banco.
4. Execute `php bin/create-admin.php`; a senha é recebida por entrada padrão, sem credencial de fábrica. Sem CLI, use o instalador temporário protegido descrito no guia.
5. Entre na administração, confirme a política e o arredondamento, defina o primeiro corte futuro. A periodicidade inicial confirmada é **1440 minutos (um dia)**.
6. Agende `php /CAMINHO_PRIVADO/bin/cron.php` a cada **15 minutos ou mais**, conforme a conta. Não existe processo permanente.
7. Valide HTTPS, SMTP, cadastro/aprovação e PWA pelo endereço final.

O instalador por navegador fica **fechado por padrão**. Configuração incompleta apresenta erro seguro e nunca troca automaticamente para uma demonstração. Não envie senhas pelo chat.

Guia completo: [docs/DEPLOY_SUPERDOMINIOS.md](docs/DEPLOY_SUPERDOMINIOS.md).

## Baixar os arquivos

No repositório GitHub, a pasta `downloads/` contém o **ZIP de produção** e o **ZIP completo**, com hashes SHA-256. Abra o arquivo desejado e clique em **Download raw file**. O ZIP de produção inclui a aplicação, SQL, dependências e documentação; o completo inclui também testes e gerador de demonstração. Ambos excluem configurações privadas e dados locais. Os pacotes não incluem a própria pasta de downloads.

Para baixar a versão atual de todo o código pelo GitHub, use **Code → Download ZIP**. Esse download inclui os testes; siga o guia para servir somente `public/`.

## Demonstração local opcional

Use um banco separado e vazio; configure `environment=demo`, `secure_cookie=false` somente em HTTP local e `mail_transport=file`. Após as migrações:

```sh
# Defina privadamente duas senhas fortes no ambiente local:
# PISCINA_DEMO_ADMIN_PASSWORD e PISCINA_DEMO_PASSWORD
php bin/demo.php --confirm-demo
php -S localhost:8080 -t public
```

Os e-mails locais são `admin@demo.invalid` e `artista1@demo.invalid` até `artista4@demo.invalid`. As senhas vêm das variáveis, sem padrão no código. O script se recusa a usar produção ou banco preenchido. Obras de demonstração são sintéticas, geradas pelo script. A caixa de e-mail local fica em `storage/mail/` e indica **NÃO ENVIADO**. O calendário não começa automaticamente. O pacote de produção não inclui o gerador de demonstração.

## Verificar

```sh
php bin/diagnose.php
php bin/reconcile.php
```

Suíte de integridade: copie a configuração para um arquivo privado `tests/local.php`, use **exclusivamente** `dbname=piscina_test` e `environment=demo`. A suíte **apaga todas as tabelas desse banco de teste**.

```sh
PISCINA_CONFIG=/CAMINHO/tests/local.php php tests/run.php
```

Há 34 cenários com MySQL real, inclusive processos/conexões independentes para concorrência, deadlock e transação que atravessa o corte. O comando usa `proc_open`; isso é necessário apenas nos testes locais.

Jornadas no Chromium: em um banco de demonstração recém-criado, com as duas senhas no ambiente, execute `npm install` e `npm run test:browser`. Configure `TEST_URL`, `TEST_BASE_PATH`, `TEST_ARTIFACTS` e, se necessário, `CHROMIUM_PATH`. A suíte browser modifica somente a demonstração e configura seu primeiro corte de homologação. Não execute contra produção.

## Documentação

- [Regras e transições](docs/REGRAS.md)
- [Decisões e origem das confirmações](docs/DECISOES.md)
- [Arquitetura, precisão e fronteira de corte](docs/ARQUITETURA.md)
- [API](docs/API.md)
- [Implantação](docs/DEPLOY_SUPERDOMINIOS.md)
- [Relatório de verificação](docs/VERIFICACAO.md)
- [Estado e limites](docs/STATUS.md)

Desenvolvimento e testes locais concluídos. Nenhuma publicação externa foi executada. A implantação requer configuração da conta, primeiro corte e validação do ambiente final.
