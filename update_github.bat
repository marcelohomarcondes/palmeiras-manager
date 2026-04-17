@echo off
setlocal enabledelayedexpansion

title Atualizar Projeto GitHub
color 0A

echo ================================
echo ATUALIZADOR AUTOMATICO GITHUB
echo ================================
echo.

REM Ir para o diretorio onde o .bat esta localizado
cd /d "%~dp0"

REM Verifica se existe repositorio git
if not exist ".git" (
    echo ERRO: Esta pasta nao eh um repositorio Git.
    pause
    exit /b
)

echo Adicionando arquivos...
git add -A

REM Criando uma mensagem de commit simples e garantida
set data_hora=%date% %time%
set mensagem=Update automatico em !data_hora!

echo.
echo Realizando commit...
REM O uso de !mensagem! com delayedexpansion garante que o valor seja lido corretamente
git commit -m "!mensagem!"

if errorlevel 1 (
    echo.
    echo AVISO: Nada para commitar ou erro no commit.
)

echo.
echo Sincronizando com o servidor (Pull)...
git pull origin main

echo.
echo Enviando para GitHub (Push)...
git push origin main

echo.
echo ================================
echo PROCESSO FINALIZADO
echo ================================
echo.
pause