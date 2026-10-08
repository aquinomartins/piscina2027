# Estado da entrega — 07/10/2026

## Implementado e verificado localmente

- Monólito PHP/HTML/CSS/JS e MySQL/InnoDB, configuração por ambiente e subpasta.
- Autenticação, verificação/recuperação, aprovação/grant únicos, autorização e reautenticação administrativa.
- Livro exato, emissão, tesourarias, reservas, idempotência, auditoria e reconciliação.
- NFT inicial/adicional autorizada, imagem única privada/reencodificada, proveniência.
- Depósito/redepósito e retirada pública alternativa.
- Anúncios, busca, perfis, versões de proposta, aprovações, reservas, liquidação, recusa/cancelamento/expiração.
- Corte ordenado, snapshots históricos, maiores restos, lotes retomáveis, direitos brutos e taxa separada.
- Cron CLI, tarefa HTTP opcional protegida, outbox, limpeza e instalação inicial fechada por padrão.
- Interface móvel, painel/pendências/histórico/administração, PWA estática segura e offline.
- SQL/migrações e documentação, dependências preparadas e pacotes sem dados/segredos locais.

34 cenários de integridade passaram em PHP 8.3.6 + MySQL 8.0.46, incluindo processos independentes/deadlock/corte. Jornadas Chromium e larguras 320/360/390/412/1440 passaram; subpasta, offline, cache, instalador e cron protegido também verificados. Ver VERIFICACAO.md para comandos/evidências/limites.

## Configuração final pendente

Dados reais de SQL/SMTP/URL/HTTPS/armazenamento, criação do administrador da conta, revisão/ativação da política e do arredondamento, **primeiro corte futuro**, cron e homologação da hospedagem. Periodicidade inicial diária e publicidade do feed foram confirmadas pelo usuário.

## Limites desta versão

Ativos internos sem blockchain. Gate serializa mutações. Limites iniciais 500 aprovados/5000 NFTs; carga/capacidade da conta não medida. Calendário iniciado não pode ser editado sem migração. Rascunhos são formulários locais; jornadas econômicas requerem JavaScript. A chave de uma resposta desconhecida permanece em memória enquanto a página fica aberta; após fechar, consultar histórico antes de novo envio.

Arquivos e SQL não são atomicamente comprometidos juntos; associação/limpeza seguem a estratégia documentada. E-mail outbox pode reenviar após crash, sem promessa de entrega externa exatamente uma vez. Livro não tem editor de saldos nem ferramenta geral de correções: compensações futuras precisam de operação auditada específica.

Nenhuma publicação, envio SMTP real, teste de carga, dispositivo físico ou instalação real standalone foi efetuado. Compatibilidade MariaDB, recursos da conta e enquadramento nos termos do provedor precisam de validação externa.

## Acesso aos arquivos

O repositório contém o código-fonte e, em `downloads/`, os ZIPs completo e de produção com hashes SHA-256. No GitHub, abra o ZIP e clique em **Download raw file**. A aplicação ainda requer instalação e configuração na hospedagem; a disponibilização do código no GitHub não publica a aplicação web.
