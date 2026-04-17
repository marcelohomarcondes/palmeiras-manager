@echo off
chcp 65001 >nul
setlocal

cd /d "D:\Projetos\palmeiras_manager"

echo =================================
echo ATUALIZADOR AUTOMATICO GITHUB
echo =================================
echo.

git rev-parse --is-inside-work-tree >nul 2>&1
if errorlevel 1 (
    echo ERRO: pasta atual nao e um repositorio Git.
    pause
    exit /b 1
)

git remote get-url origin >nul 2>&1
if errorlevel 1 (
    echo Configurando remote origin...
    git remote add origin https://github.com/marcelohomarcondes/palmeiras-manager.git
    if errorlevel 1 (
        echo ERRO ao configurar remote origin.
        pause
        exit /b 1
    )
)

if exist ".git\rebase-merge" (
    echo ERRO: ha um rebase em andamento. Resolva ou aborte antes de continuar.
    pause
    exit /b 1
)

if exist ".git\rebase-apply" (
    echo ERRO: ha um rebase em andamento. Resolva ou aborte antes de continuar.
    pause
    exit /b 1
)

echo Garantindo branch main...
git checkout main
if errorlevel 1 (
    echo ERRO ao acessar a branch main.
    pause
    exit /b 1
)

echo.
echo Sincronizando com o servidor...
git pull --rebase origin main
if errorlevel 1 (
    echo ERRO no pull --rebase.
    pause
    exit /b 1
)

echo.
echo Adicionando arquivos...
git add .
if errorlevel 1 (
    echo ERRO ao adicionar arquivos.
    pause
    exit /b 1
)

echo.
echo Verificando alteracoes...
git diff --cached --quiet
if errorlevel 1 (
    echo Realizando commit...
    git commit -m "Update automatico em %date% %time%"
    if errorlevel 1 (
        echo ERRO ao realizar commit.
        pause
        exit /b 1
    )
) else (
    echo Nenhuma alteracao para commit.
)

echo.
echo Enviando para GitHub...
git push origin main
if errorlevel 1 (
    echo ERRO no push.
    pause
    exit /b 1
)

echo.
echo =================================
echo PROCESSO FINALIZADO COM SUCESSO
echo =================================
pause