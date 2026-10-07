# Dependências preparadas

PHPMailer **6.10.0**, arquivo de tag `https://codeload.github.com/PHPMailer/PHPMailer/zip/refs/tags/v6.10.0`. Fontes src/ e LICENSE incluídos, LGPL 2.1. Autoload local em app/bootstrap.php; nenhum Composer no servidor necessário. composer.json fixa a mesma versão.

Inter variável **@fontsource-variable/inter 5.2.6**, arquivo latino WOFF2 servido localmente; licença OFL em public/assets/inter-license.txt. Fontes/JS/CSS não são carregados de CDN em produção.

Playwright 1.62.1 é apenas dependência opcional de desenvolvimento/teste, excluída do pacote de produção. Não existem integrações de blockchain ou pagamentos reais.
