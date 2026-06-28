@echo off
:: Simple launcher for the Windows tray client from a checkout folder.
:: Assumes Python is on PATH and dependencies are installed.
setlocal
pushd "%~dp0"
python -m pip install -r requirements.txt -q
python -m client.main
popd
