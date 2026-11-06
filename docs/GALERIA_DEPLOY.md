# Implantação da Galeria na Superdomínios

O código foi preparado em branch isolada. Não houve publicação nem execução
de SQL na hospedagem. Use os pacotes desta versão; não reaplique ZIPs antigos
de cadastro, Administração, Danger Zone ou home depois da Galeria.

## Antes da atualização

1. Faça backup consistente do banco e das pastas privadas/públicas da aplicação.
   Preserve `config/local.php`, `storage/images`, sessões e
   `storage/credentials-idempotency.key`; o segredo também protege o
   credenciamento e recibos administrativos. Nenhum desses dados está no ZIP.
2. Escolha uma janela sem operações da turma. A raiz privada conhecida é
   `/home/oftalmol/aquino.bsb.br`; somente seu `public/` é raiz do domínio.
3. O módulo pressupõe a versão administrativa da PR #9. O pacote inclui o
   banner da PR #10 e seus recursos. Não é necessário recriar usuários,
   reiniciar carteiras, reemitir NFTs ou mudar a política da Piscina.
4. Deixe `galeria.enabled` ausente ou `false` durante a instalação. O novo
   módulo não se habilita comercialmente por ter recebido os arquivos.

## Banco — phpMyAdmin

Selecione o banco atual da Piscina e confira `schema_migrations`. Se a PR #9
ainda não foi implantada, aplique primeiro `003_admin_danger.sql` conforme
o guia da Danger Zone. Depois importe **somente**:

```text
database/migrations/004_galeria.sql
```

A migração é aditiva: cria tabelas `galeria_*`, índices e referências aos
usuários/NFTs/contas existentes. Não altera tabelas antigas, saldos ou parâmetros
do jogo. O registro `004_galeria` é inserido ao final.

DDL MySQL tem commits implícitos: não suponha rollback da importação. Se houver
falha, mantenha o módulo desligado, corrija a causa e reaplique o mesmo arquivo
idempotente até concluir. Não marque a versão manualmente e não apague tabelas
para “tentar de novo”. **Não importe `database/install.sql` para atualizar**:
ele é destinado a instalações novas e vazias.

Também é possível usar o migrador existente `php bin/migrate.php`, com a
configuração privada da conta, sem executar fixtures de demonstração.

## Arquivos — Gerenciador de Arquivos do cPanel

1. Extraia `Piscina_Galeria_Molduras.zip` numa pasta temporária privada.
   Confira `SHA256SUMS_GALERIA.txt`. O ZIP contém código e recursos; não contém
   dados, senhas, configurações privadas ou um banco de demonstração.
2. Copie `app/` para a pasta `app/` da raiz privada. Envie primeiro as novas
   classes `Galeria*`, `Molduras`, `MolduraTrades`, `Coalizoes` e
   `ImageReferences`; depois substitua os arquivos integrados existentes.
3. Copie os arquivos de `public/` para o `public/` da instalação, preservando
   as subpastas. Envie os recursos estáticos antes de `index.php`; deixe
   **`sw.js` por último**. Não mova `app` ou `storage` para dentro de `public`.
4. Preserve seu `config/local.php`. Acrescente apenas o novo bloco quando
   for habilitar; não substitua credenciais pelo exemplo do repositório.
5. O PHP precisa continuar podendo gravar no armazenamento privado. Fotografias
   da Galeria usam `storage/galeria`, criada automaticamente com permissão
   privada. Não há URL pública direta para os arquivos de armazenamento.
6. Não configure um segundo cron. A tarefa existente chama a Galeria em lotes
   limitados; comandos financeiros continuam no gate e no livro existentes.

## Verificar e abrir o piloto

Entre com o Admin e confira a Piscina, a Danger Zone e o banner. Abra
**Administração → Gerenciar Galeria**. Habilite a flag privada quando os arquivos
e a migração estiverem completos:

```php
'galeria' => ['enabled' => true],
```

Novas contratações ainda ficam suspensas até o comando do Admin. Prepare os
espaços, pontos, equipamentos verificados, preços, direitos, X cotas por parte,
destinatário e rascunhos dos eventos. Depois abra a operação. Nada disso é
preenchido com valores de fixture em produção.

Para um evento, publique o regulamento, obtenha os acordos das duas partes,
confirme infraestrutura, financie integralmente o prêmio, credencie os eleitores
e prepare as candidaturas antes do início. Não habilite um evento com pagamento
fictício, saldo ausente ou custos sem destinatário.

Em dispositivos com o PWA, use **Atualizar app** quando aparecer. A atualização
é explícita e fica bloqueada durante uma operação em andamento ou de resultado
desconhecido. HTML, API, carteiras, votos e mídia privada não entram no cache
público; só os recursos estáticos indicados no service worker.

Sem CLI, a Administração exibe conciliação da Galeria e a conciliação canônica
existente. Com CLI, execute `php bin/reconcile.php`; todas as listas devem ficar
vazias. Não corrija divergências escrevendo diretamente em `accounts`.

## Suspender ou desativar com segurança

Use **Suspender novas contratações** antes de encerrar o piloto. Isso bloqueia
novas compras/contratos sem bloquear consultas, cancelamentos, devoluções,
apuração ou liquidação de compromissos existentes. A flag privada em `false`
também bloqueia novos negócios, preservando a resolução do histórico.

Confira exposições, prêmios financiados e contas `galeria_hold`/`galeria_prize`.
Conclua prestações e eventos ou aplique a resolução motivada publicada. Não
apague vínculos nem transfira recursos por SQL para “liberar saldo”.

Depois de haver compromissos, mantenha o código instalado e suspenda a entrada
de negócios. Não restaure versões anteriores de Domain/Ledger/Upload sem os
adaptadores da Galeria: os registros e recursos continuariam existindo, mas
os controles e a resolução poderiam ficar inacessíveis. Depois de transações
válidas, prefira correção progressiva a restaurar backup que elimine lançamentos.

O rollback antes de qualquer operação deve restaurar um conjunto de arquivos
compatível; manter tabelas novas vazias é seguro. Não há down migration que
apague contratos, votos ou o livro financeiro.
