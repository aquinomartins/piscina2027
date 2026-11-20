"""Overlay de atualização Galeria/Molduras, sem configuração privada ou dados."""
from pathlib import Path
import hashlib
import zipfile
import shutil

ROOT = Path(__file__).resolve().parents[1]
CONFIG_MODELS = ('config/example.php',)
RUNTIME = sorted(
    [str(p.relative_to(ROOT)) for p in (ROOT / 'app').glob('*.php')]
    + [str(p.relative_to(ROOT)) for p in (ROOT / 'public/assets').iterdir() if p.is_file()]
    + [str(p.relative_to(ROOT)) for p in (ROOT / 'public/icons').iterdir() if p.is_file()]
    + ['public/' + name for name in (
        'bootstrap.php', 'api.php', 'index.php', 'image.php', 'galeria-media.php',
        'form.php', 'manifest.php', 'sw.js', 'offline.html',
        'admin-payments.php', 'admin-payments-api.php', 'payment-test-webhook.php',
    )]
    + ['bin/check-payment-test.php']
)
DOCS = (
    'docs/PAGAMENTOS_MERCADOPAGO_TESTE.md', 'docs/PAGAMENTOS_TESTE_VALIDACAO.md',
    'docs/RESULTADOS_PAGAMENTOS_TESTE.txt',
    'docs/images/pagamento-teste-confirmacao-mobile.png',
    'docs/images/pagamento-teste-comprovante-mobile.png',
    'docs/PREMIOS_ASSOCIACOES.md', 'docs/PREMIOS_TESTES.md',
    'docs/RESULTADOS_PREMIOS.txt', 'docs/images/premios-registro-mobile.png',
    'docs/images/premios-registro-desktop.png',
    'docs/ADMIN_MOBILE.md',
    'docs/MOLDURAS_CARTEIRA.md', 'docs/MOLDURAS_CARTEIRA_TESTES.md',
    'docs/RESULTADOS_CARTEIRA.txt', 'docs/images/carteira-moldura-mobile.png',
    'docs/images/carteira-nft-mobile.png', 'docs/images/carteira-pendencias-mobile.png',
    'docs/GALERIA.md', 'docs/GALERIA_DEPLOY.md', 'docs/GALERIA_TESTES.md',
    'docs/GALERIA_DIAGNOSTICO.md', 'docs/DANGER_ZONE.md',
    'docs/RESULTADOS_GALERIA.txt', 'docs/images/galeria-catalogo-mobile.png',
    'docs/images/galeria-admin-desktop.png', 'docs/images/galeria-evento-mobile.png',
    'docs/GALERIA_AVANCADA.md', 'docs/images/molduras-simples-mobile.png',
    'docs/images/molduras-acordo-mobile.png', 'docs/images/molduras-admin-desktop.png',
)


def mini_package(name, sums, manifest, payload):
    payload = dict(payload)
    payload[manifest] = ''.join(
        f'{hashlib.sha256(data).hexdigest()}  {path}\n'
        for path, data in sorted(payload.items())
    ).encode()
    target = ROOT / 'downloads' / name
    with zipfile.ZipFile(target, 'w') as archive:
        for path, data in sorted(payload.items()):
            info = zipfile.ZipInfo(path, (1980, 1, 1, 0, 0, 0))
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            archive.writestr(info, data, compress_type=zipfile.ZIP_DEFLATED)
    with zipfile.ZipFile(target) as archive:
        assert archive.testzip() is None
        assert set(archive.namelist()) == set(payload)
        for path, data in payload.items():
            assert archive.read(path) == data
    (ROOT / 'downloads' / sums).write_text(
        f'{hashlib.sha256(target.read_bytes()).hexdigest()}  {target.name}\n'
    )


def main():
    files = RUNTIME + list(CONFIG_MODELS) + list(DOCS) + [
        'database/migrations/003_admin_danger.sql',
        'database/migrations/004_galeria.sql',
        'database/migrations/005_molduras_simples.sql',
        'database/migrations/006_premios_associacoes.sql',
        'database/migrations/007_pagamentos_teste.sql',
    ]
    assert len(files) == len(set(files))
    assert all(not p.startswith(('storage/', 'tests/')) for p in files)
    assert all(not p.startswith('config/') or p in CONFIG_MODELS for p in files)
    assert 'config/local.php' not in files
    payload = {p: (ROOT / p).read_bytes() for p in files}
    assert b'galeria.js' in payload['public/sw.js']
    assert b'admin-danger.js' in payload['public/sw.js']
    assert b'qr-generator.js' in payload['public/sw.js']
    assert b'molduras-simples.js' in payload['public/sw.js']
    assert b'molduras-carteira.js' in payload['public/sw.js']
    assert b'molduras-carteira.css' in payload['public/sw.js']
    assert b'galeria-premios.js' in payload['public/sw.js']
    payload['SHA256SUMS_GALERIA.txt'] = ''.join(
        f'{hashlib.sha256(data).hexdigest()}  {name}\n'
        for name, data in sorted(payload.items())
    ).encode()
    target = ROOT / 'downloads/Piscina_Galeria_Molduras.zip'
    target.parent.mkdir(exist_ok=True)
    with zipfile.ZipFile(target, 'w') as archive:
        for name, data in sorted(payload.items()):
            info = zipfile.ZipInfo(name, (1980, 1, 1, 0, 0, 0))
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            archive.writestr(info, data, compress_type=zipfile.ZIP_DEFLATED)
    with zipfile.ZipFile(target) as archive:
        assert archive.testzip() is None
        assert len(archive.namelist()) == len(payload)
        for name, data in payload.items():
            assert archive.read(name) == data
    (ROOT / 'downloads/GALERIA_SHA256SUMS.txt').write_text(
        f'{hashlib.sha256(target.read_bytes()).hexdigest()}  {target.name}\n'
    )
    simple = ROOT / 'downloads/Piscina_Molduras_Simples.zip'
    shutil.copyfile(target, simple)
    (ROOT / 'downloads/MOLDURAS_SIMPLES_SHA256SUMS.txt').write_text(
        f'{hashlib.sha256(simple.read_bytes()).hexdigest()}  {simple.name}\n'
    )
    wallet = ROOT / 'downloads/Piscina_Portfolio_Pendencias.zip'
    shutil.copyfile(target, wallet)
    (ROOT / 'downloads/CARTEIRA_SHA256SUMS.txt').write_text(
        f'{hashlib.sha256(wallet.read_bytes()).hexdigest()}  {wallet.name}\n'
    )
    prizes = ROOT / 'downloads/Piscina_Premios_Associacoes.zip'
    shutil.copyfile(target, prizes)
    (ROOT / 'downloads/PREMIOS_SHA256SUMS.txt').write_text(
        f'{hashlib.sha256(prizes.read_bytes()).hexdigest()}  {prizes.name}\n'
    )
    payments = ROOT / 'downloads/Piscina_Pagamentos_MercadoPago_Teste.zip'
    shutil.copyfile(target, payments)
    (ROOT / 'downloads/PAGAMENTOS_TESTE_SHA256SUMS.txt').write_text(
        f'{hashlib.sha256(payments.read_bytes()).hexdigest()}  {payments.name}\n'
    )
    sandbox_files = (
        'app/MercadoPagoSandbox.php', 'app/MercadoPagoTestHttp.php',
        'app/PaymentTests.php', 'public/admin-payments.php',
        'public/assets/admin-payments.js', 'bin/check-payment-test.php',
    )
    sandbox_payload = {path: payload[path] for path in sandbox_files}
    sandbox_payload['LEIA_SANDBOX_MP.txt'] = (
        'Fluxo nativo de teste do Checkout Bricks - modulo da PR #16\n\n'
        'O erro 150 vinha de uma verificacao adicional pela API Clientes.\n'
        'Esta versao elimina essa dependencia. Atualize os SEIS arquivos juntos.\n'
        'Requer a PR #16 instalada, incluindo a migracao 007 ja aplicada.\n\n'
        '1. Guarde uma copia dos seis arquivos instalados.\n'
        '2. Extraia o pacote na raiz privada /home/oftalmol/aquino.bsb.br/,\n'
        '   mantendo as pastas app/, public/ e bin/. Nao extraia tudo dentro de public/.\n'
        '3. No envio manual, atualize primeiro public/assets/admin-payments.js,\n'
        '   depois os PHP de app/ e bin/, e public/admin-payments.php por ultimo.\n'
        '4. Recarregue Administracao > Pagamentos de teste. Prepare primeiro um\n'
        '   cartao de credito oficial de teste; use APRO para testar aprovacao.\n'
        '   Use e-mail ficticio diferente do vendedor, sem contas testuser.com.\n'
        '5. Confirme com e-mail e senha do ADMIN DO APP. O servidor consulta\n'
        '   o token e exige live_mode=false, validade e cartao oficial antes do envio.\n'
        '6. Pix fica bloqueado ate essa prova, valida por ate cinco minutos,\n'
        '   ou consulta de pagamento de teste existente do mesmo coletor.\n'
        '   Essa prova inicial e uma protecao do modulo. Pendencia nao gera credito.\n\n'
        'Nao precisa mudar config/local.php, credenciais, Webhook, SQL ou storage.\n'
        'Nao ative producao nem crie contas Mercado Pago para contornar o erro.\n'
        'Nao use cartoes reais. Nao altere o arquivo privado de prova manualmente.\n'
        'Credito exige aprovacao confirmada em consulta independente do pagamento.\n'
        'Provedor/SDK simulados somente nos testes locais; homologar na hospedagem.\n'
        'Em nova recusa, compartilhe somente o aviso sanitizado, sem segredos.\n'
    ).encode()
    sandbox_name = 'Piscina_Sandbox_Bricks_MercadoPago.zip'
    mini_package(sandbox_name, 'SANDBOX_MP_SHA256SUMS.txt', 'SHA256SUMS_SANDBOX_MP.txt', sandbox_payload)
    # Nomes antigos passam a entregar o mesmo conjunto completo, sem misturar dependencias.
    for alias, sums in (
        ('Piscina_Diagnostico_MercadoPago.zip', 'DIAGNOSTICO_MP_SHA256SUMS.txt'),
        ('Piscina_Correcao_Cadastro_MercadoPago.zip', 'CADASTRO_MP_SHA256SUMS.txt'),
    ):
        target_alias = ROOT / 'downloads' / alias
        shutil.copyfile(ROOT / 'downloads' / sandbox_name, target_alias)
        (ROOT / 'downloads' / sums).write_text(
            f'{hashlib.sha256(target_alias.read_bytes()).hexdigest()}  {alias}\n'
        )
    print(f'{payments.name} e overlays compatíveis: {len(RUNTIME)} arquivos de execução, modelo config/example.php, migrações 003/004/005/006/007, guias e hashes conferidos; config/local.php excluído.')
    print('Sandbox Bricks: seis arquivos do módulo, instruções e hashes; nomes antigos entregam o mesmo conjunto completo.')


if __name__ == '__main__':
    main()
