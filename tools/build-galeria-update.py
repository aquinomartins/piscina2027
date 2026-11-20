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
    diagnostic = ROOT / 'downloads/Piscina_Diagnostico_MercadoPago.zip'
    diagnostic_payload = {
        'app/MercadoPagoTestHttp.php': payload['app/MercadoPagoTestHttp.php'],
        'LEIA_DIAGNOSTICO_MP.txt': (
            'Diagnostico seguro do Mercado Pago - modulo da PR #16\n\n'
            'Este pacote melhora a mensagem de erro. Nao libera pagamentos nem retira a prova do sandbox.\n'
            '1. Guarde uma copia do app/MercadoPagoTestHttp.php instalado.\n'
            '2. Substitua somente esse arquivo na raiz privada /home/oftalmol/aquino.bsb.br/.\n'
            '   Caminho final: /home/oftalmol/aquino.bsb.br/app/MercadoPagoTestHttp.php\n'
            '3. Recarregue Administracao > Pagamentos de teste na Piscina.\n'
            '4. Copie somente o aviso com Etapa, HTTP e Codigo; nao envie senhas ou tokens.\n\n'
            'Nao precisa alterar config/local.php, banco de dados ou storage para este diagnostico.\n'
            'A mensagem nao exibe corpo bruto, chaves, e-mails, IDs privados ou dados de cartao.\n'
        ).encode(),
    }
    diagnostic_payload['SHA256SUMS_DIAGNOSTICO.txt'] = ''.join(
        f'{hashlib.sha256(data).hexdigest()}  {name}\n'
        for name, data in sorted(diagnostic_payload.items())
    ).encode()
    with zipfile.ZipFile(diagnostic, 'w') as archive:
        for name, data in sorted(diagnostic_payload.items()):
            info = zipfile.ZipInfo(name, (1980, 1, 1, 0, 0, 0))
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            archive.writestr(info, data, compress_type=zipfile.ZIP_DEFLATED)
    with zipfile.ZipFile(diagnostic) as archive:
        assert archive.testzip() is None
        assert set(archive.namelist()) == set(diagnostic_payload)
        for name, data in diagnostic_payload.items():
            assert archive.read(name) == data
    (ROOT / 'downloads/DIAGNOSTICO_MP_SHA256SUMS.txt').write_text(
        f'{hashlib.sha256(diagnostic.read_bytes()).hexdigest()}  {diagnostic.name}\n'
    )
    print(f'{payments.name} e overlays compatíveis: {len(RUNTIME)} arquivos de execução, modelo config/example.php, migrações 003/004/005/006/007, guias e hashes conferidos; config/local.php excluído.')
    print(f'{diagnostic.name}: somente app/MercadoPagoTestHttp.php, instruções e hashes.')


if __name__ == '__main__':
    main()
