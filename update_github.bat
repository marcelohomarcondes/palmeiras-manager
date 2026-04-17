@echo off
chcp 65001 >nul
setlocal

cd /d "D:\Projetos\palmeiras_manager"

echo =================================
echo ATUALIZADOR AUTOMATICO GITHUB
echo =================================

echo.
echo Verificando remoto origin...
git remote get-url origin >nul 2>&1
if errorlevel 1 (
    echo Remote origin nao encontrado. Configurando...
    git remote add origin https://github.com/marcelohomarcondes/palmeiras-manager.git
)

echo.
echo Verificando branch atual...
for /f %%i in ('git branch --show-current') do set CURRENT_BRANCH=%%i

if "%CURRENT_BRANCH%"=="" (
    echo Nao foi possivel identificar a branch atual.
    pause
    exit /b 1
)

if /i not "%CURRENT_BRANCH%"=="main" (
    echo Branch atual: %CURRENT_BRANCH%
    echo Renomeando branch local para main...
    git branch -M main
    if errorlevel 1 (
        echo Erro ao renomear a branch para main.
        pause
        exit /b 1
    )
)

echo.
echo Adicionando arquivos...
git add .
if errorlevel 1 (
    echo Erro ao adicionar arquivos.
    pause
    exit /b 1
)

echo.
echo Realizando commit...
git commit -m "Update automatico em %date% %time%"
if errorlevel 1 (
    echo Nenhuma alteracao para commit ou ocorreu erro no commit.
)

echo.
echo Sincronizando com o servidor (Pull)...
git pull origin main --rebase
if errorlevel 1 (
    echo Erro no pull. Verifique conflitos ou autenticacao.
    pause
    exit /b 1
)

echo.
echo Enviando para GitHub (Push)...
git push -u origin main
if errorlevel 1 (
    echo Erro no push. Verifique autenticacao/permissao no GitHub.
    pause
    exit /b 1
)

echo.
echo =================================
echo PROCESSO FINALIZADO COM SUCESSO
echo =================================
pause