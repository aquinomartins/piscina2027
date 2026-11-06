# Atualização de Molduras simples na Superdomínios

Esta atualização complementa a Galeria da migração 004. Não houve implantação
ou SQL na hospedagem. O código, as carteiras e os contratos anteriores foram
preservados em testes isolados; siga esta ordem para atualizar.

## 1. Antes de instalar

1. Faça backup do banco e dos arquivos privados/públicos. Preserve
   `config/local.php`, `storage`, sessões e `storage/credentials-idempotency.key`.
2. Entre como Admin e pause novas contratações/operações na Galeria. Use uma
   janela sem compras/aceites da turma para evitar mistura de arquivos.
3. A raiz privada conhecida é `/home/oftalmol/aquino.bsb.br`. Somente o seu
   `public/` é raiz do domínio. Não extraia as pastas privadas dentro de `public`.
4. A base inclui a Danger Zone, o banner e a Galeria original da PR #11, já
   incorporada à branch da PR #10. A simplificação é uma nova PR sobre essa
   base; não há merge automático ou mudança na produção.

## 2. Banco: para quem já instalou a 004

No **cPanel → phpMyAdmin**, selecione o banco atual e confira
`schema_migrations`. Com `004_galeria` já presente, importe **somente**:

```text
database/migrations/005_molduras_simples.sql
```

A 005 cria tabelas de configuração e associação simples (`galeria_features`,
`galeria_link_proposals`, `galeria_link_versions`, `galeria_link_acceptances`,
`galeria_links`). Ela também permite `NULL` em `galeria_offers.expires_at` para
anúncios sem prazo. Não coloca datas/locais fictícios e não muda os vencimentos
das ofertas existentes, carteiras, percentuais ou contratos anteriores.

DDL MySQL tem commits implícitos. O arquivo é reaplicável e registra sua versão
somente ao final. Se a importação falhar, mantenha a operação pausada, corrija a
causa e reaplique; não marque a versão manualmente nem apague tabelas.
Não use `database/install.sql` num banco preenchido e não reinstale a 004.

Para uma instalação antiga sem Galeria, siga as migrações ainda ausentes em
ordem, incluindo 003/004. O pacote completo contém o migrador
`php bin/migrate.php`. Instalação nova usa um banco vazio.

## 3. Arquivos pelo Gerenciador do cPanel

1. Baixe **Piscina_Molduras_Simples.zip** ou o pacote atual
   **Piscina_Galeria_Molduras.zip**. Ambos contêm o mesmo runtime compatível.
   Confira os arquivos de hashes. Não reaplique overlays de versões antigas.
2. Extraia numa pasta temporária privada e copie `app/` para o `app/` da raiz
   privada. Envie primeiro `MolduraLinks.php` e `MolduraSimpleQueries.php`,
   depois substitua as classes integradas da atualização.
3. Copie o conteúdo de `public/` para o `public/` da instalação. Envie recursos
   estáticos antes dos arquivos PHP. **`sw.js` vai por último.** O módulo novo
   `assets/molduras-simples.js` precisa estar presente antes de atualizar o PWA.
4. Preserve `config/local.php`, todos os dados e o armazenamento. O ZIP não
   contém essas informações nem precisa de credenciais de fábrica.
5. Não configure outro cron: o atual cuida também das propostas vencidas.
   Não há Node, Redis ou processo permanente em produção.

## 4. Conferir o modo simples

Se a Galeria já estava habilitada, mantenha o bloco privado existente:

```php
'galeria' => ['enabled' => true],
```

Nenhuma nova edição da configuração privada é obrigatória. A 005 cria as opções
avançadas em **stand-by**, sem mudar os termos dos contratos antigos ou sua
suspensão de novas operações.

1. No PWA, use **Atualizar app** quando o aviso aparecer. A atualização continua
   explícita e bloqueada durante operações pendentes/de resultado desconhecido.
2. Abra **Administração → Gerenciar Galeria**. A Galeria padrão mostra
   **Molduras à venda**, **Minhas Molduras** e **Propostas**; Admin também vê
   **Administração**.
3. Teste **Criar Moldura → Guardar sem anunciar** apenas com o nome. Depois
   publique uma oferta ou ofereça a um participante aprovado.
4. Confirme que escolher um destinatário não debita sua carteira. O pagamento
   ocorre somente quando ele confirma a compra. Oferta exclusiva não aparece
   com preço para terceiros.
5. Abra novas operações no painel quando a conferência estiver concluída.
6. Uma associação simples precisa de propriedade/disponibilidade e dos aceites
   dos percentuais. Não precisa de espaço, horário, política de X ou saldo de
   cotas. Ativos do mesmo dono usam vinculação direta.

Não teste com movimentações artificiais nas carteiras dos estudantes. Use
contas próprias de homologação para validar o ambiente final e siga o guia
[GALERIA.md](GALERIA.md) para os fluxos.

## 5. Reativar funções gradualmente

No painel da Galeria, abra a seção discreta **Recursos avançados → Configurar
recursos**. Marque somente o que quer disponibilizar: exposições físicas,
criação de eventos e/ou políticas avançadas com cobrança de cotas. Confirme com
a senha do Admin. As alterações ficam auditadas em `galeria_features`.

Disponibilizar não torna obrigatório. As associações simples continuam com
zero cobrança e sem agendamento. Prepare os locais/políticas antes de usar
exposições físicas; eventos avançados precisam de seu regulamento,
infraestrutura e financiamento originais. X positivo só pode ser usado em novo
contrato avançado quando essa opção estiver disponível e aceita pelas partes.

Contratos e propostas antigas continuam no **Painel e contratos anteriores**.
Desativar opções futuras não cancela, recalcula, devolve nem cobra novamente
seus valores. Para usar outro conjunto de regras, faça uma nova negociação e
obtenha os aceites, respeitando os compromissos vigentes.

## 6. Suspender e conferir

**Pausar novas operações** bloqueia novos negócios sem impedir consultas,
recusas, cancelamentos e resolução dos acordos existentes. A flag privada
`false` tem o mesmo cuidado com a resolução. Não apague tabelas/lançamentos.

Com CLI, `php bin/reconcile.php` deve produzir listas vazias. Sem CLI, use a
conciliação administrativa existente. Não corrija divergências escrevendo
saldos diretamente em `accounts`.

Depois de haver operações na 005, mantenha código compatível para resolver os
acordos. Prefira correção progressiva; restaurar arquivos antigos pode ocultar
as novas associações, e restaurar banco antigo elimina transações posteriores.
O rollback anterior a qualquer nova operação deve restaurar um conjunto
consistente de código/dados. Não há down migration destrutiva.
