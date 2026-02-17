@echo off
title Atualizar Projeto GitHub
color 0A

echo ================================
echo   ATUALIZADOR AUTOMATICO GITHUB
echo ================================
echo.

REM Ir para o diretório onde o .bat está localizado
cd /d "%~dp0"

echo Diretorio atual:
cd
echo.

REM Verifica se existe repositorio git
if not exist ".git" (
    echo ERRO: Esta pasta nao eh um repositorio Git.
    pause
    exit /b
)

REM Puxa atualizacoes remotas antes
echo Atualizando repositorio local...
git pull origin main

echo.
echo Adicionando arquivos modificados...
git add .

REM Gera data e hora para commit automatico
for /f "tokens=1-3 delims=/ " %%a in ("%date%") do (
    set dia=%%a
    set mes=%%b
    set ano=%%c
)

for /f "tokens=1-2 delims=: " %%a in ("%time%") do (
    set hora=%%a
    set minuto=%%b
)

set mensagem=Update automatico %date% %time%

echo.
echo Realizando commit...
git commit -m "%mensagem%"

echo.
echo Enviando para GitHub...
git push origin main

echo.
echo ================================
echo     ATUALIZACAO FINALIZADA
echo ================================
echo.
pause
