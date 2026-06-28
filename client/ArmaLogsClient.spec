# -*- mode: python ; coding: utf-8 -*-
# PyInstaller spec for ArmaLogs client.
# Run from the client directory:
#   .venv\Scripts\python -m PyInstaller ArmaLogsClient.spec

block_cipher = None

a = Analysis(
    ['ArmaLogsClient.py'],
    pathex=[],
    binaries=[],
    datas=[('config.json.example', '.')],
    hiddenimports=[
        'client.main',
        'client.config',
        'client.setup_dialog',
        'client.uploader',
        'client.watcher',
        'client.updater',
        'client.mod_count',
        'packaging',
        'packaging.version',
    ],
    hookspath=[],
    hooksconfig={},
    runtime_hooks=[],
    excludes=[],
    win_no_prefer_redirects=False,
    win_private_assemblies=False,
    cipher=block_cipher,
    noarchive=False,
)

pyz = PYZ(a.pure, a.zipped_data, cipher=block_cipher)

exe = EXE(
    pyz,
    a.scripts,
    a.binaries,
    a.zipfiles,
    a.datas,
    [],
    name='ArmaLogsClient',
    debug=False,
    bootloader_ignore_signals=False,
    strip=False,
    upx=True,
    upx_exclude=[],
    runtime_tmpdir=None,
    console=False,
    disable_windowed_traceback=False,
    target_arch=None,
    codesign_identity=None,
    entitlements_file=None,
)
