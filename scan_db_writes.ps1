<# 
scan_db_writes.ps1
Varre o projeto e gera relatório consolidado sobre:
 - arquivos SQLite (.sqlite/.db) existentes
 - quais possuem tabelas (via sqlite3 .tables)
 - referências sqlite no código
 - comandos que escrevem no DB (INSERT/UPDATE/DELETE/REPLACE)
 - uso de prepare/exec/transações
#>

$ErrorActionPreference = 'SilentlyContinue'

$Root = (Get-Location).Path

$OutDir = Join-Path $Root 'scan_output'
New-Item -ItemType Directory -Force -Path $OutDir | Out-Null

$Timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$OutFile = Join-Path $OutDir ("db_scan_{0}.txt" -f $Timestamp)

function Add-Line([string]$text) {
  Add-Content -Path $OutFile -Value $text -Encoding UTF8
}

function Write-Section([string]$title) {
  Add-Line ''
  Add-Line ('=' * 90)
  Add-Line $title
  Add-Line ('=' * 90)
}

function SafeLine($s) {
  if ($null -eq $s) { return '' }
  return ($s.ToString()).Replace("`r",'').Replace("`n",'')
}

Add-Line ("Projeto: {0}" -f $Root)
Add-Line ("Data: {0}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'))
Add-Line ''

# Coleta arquivos (exclui node_modules/vendor/.git)
Write-Section '0) LISTA DE ARQUIVOS ANALISADOS (PHP)'
$phpFiles = Get-ChildItem -Path $Root -Recurse -File -Filter *.php |
  Where-Object { $_.FullName -notmatch '\\node_modules\\' -and $_.FullName -notmatch '\\vendor\\' -and $_.FullName -notmatch '\\.git\\' } |
  Sort-Object FullName

Add-Line ("Total de arquivos PHP: {0}" -f ($phpFiles.Count))
if ($phpFiles.Count -le 50) {
  foreach ($f in $phpFiles) { Add-Line $f.FullName }
} else {
  Add-Line '(lista omitida: muitos arquivos)'
}

# 1) Listar bancos .sqlite/.db
Write-Section '1) ARQUIVOS DE BANCO ENCONTRADOS (*.sqlite / *.db)'
$dbFiles = Get-ChildItem -Path $Root -Recurse -File -Include *.sqlite,*.db |
  Where-Object { $_.FullName -notmatch '\\node_modules\\' -and $_.FullName -notmatch '\\vendor\\' -and $_.FullName -notmatch '\\.git\\' } |
  Sort-Object FullName

if (-not $dbFiles -or $dbFiles.Count -eq 0) {
  Add-Line 'Nenhum arquivo .sqlite/.db encontrado.'
} else {
  foreach ($f in $dbFiles) {
    Add-Line ("{0}`t{1} bytes`t{2}`t{3}" -f $f.FullName, $f.Length, $f.LastWriteTime.ToString('yyyy-MM-dd HH:mm:ss'), $f.Name)
  }
}

# 2) sqlite3 disponível?
Write-Section '2) SQLITE3 DISPONIVEL?'
$sqlite3 = Get-Command sqlite3 -ErrorAction SilentlyContinue
if ($null -eq $sqlite3) {
  Add-Line 'sqlite3 NAO encontrado no PATH. Pulei a checagem de tabelas (.tables).'
  Add-Line 'Dica: instale sqlite3 e/ou adicione no PATH.'
} else {
  Add-Line ("sqlite3 encontrado em: {0}" -f $sqlite3.Source)

  # 3) tabelas por DB
  Write-Section '3) TABELAS EXISTENTES EM CADA BANCO (sqlite3 <db> .tables)'
  foreach ($f in $dbFiles) {
    Add-Line ''
    Add-Line ("--- DB: {0} ---" -f $f.FullName)
    try {
      $tables = & sqlite3 $f.FullName '.tables' 2>&1
      $tablesLine = SafeLine $tables
      if ([string]::IsNullOrWhiteSpace($tablesLine)) {
        Add-Line '(sem tabelas visíveis ou arquivo vazio/corrompido)'
      } else {
        Add-Line $tablesLine
      }
    } catch {
      Add-Line ("Erro ao rodar sqlite3 .tables: {0}" -f $_.Exception.Message)
    }
  }
}

# 4) Referências sqlite no código (busca em PHP)
Write-Section '4) REFERENCIAS A SQLITE NO CODIGO (sqlite: / .sqlite / .db)'
$refPatterns = @('sqlite:', '.sqlite', '.db')
$refHits = Select-String -Path $phpFiles.FullName -Pattern $refPatterns -SimpleMatch |
  Select-Object Path, LineNumber, Line |
  Sort-Object Path, LineNumber

if (-not $refHits -or $refHits.Count -eq 0) {
  Add-Line 'Nenhuma referencia encontrada.'
} else {
  foreach ($h in $refHits) {
    Add-Line ("{0}:{1}`t{2}" -f $h.Path, $h.LineNumber, (SafeLine $h.Line))
  }
}

# 5) Escritas no banco (INSERT/UPDATE/DELETE/REPLACE)
Write-Section '5) ESCRITAS NO BANCO (INSERT/UPDATE/DELETE/REPLACE) EM PHP'
$writePatterns = @('INSERT INTO', 'UPDATE ', 'DELETE FROM', 'REPLACE INTO')
$writeHits = Select-String -Path $phpFiles.FullName -Pattern $writePatterns -SimpleMatch |
  Select-Object Path, LineNumber, Line |
  Sort-Object Path, LineNumber

if (-not $writeHits -or $writeHits.Count -eq 0) {
  Add-Line 'Nenhum INSERT/UPDATE/DELETE/REPLACE encontrado.'
} else {
  foreach ($h in $writeHits) {
    Add-Line ("{0}:{1}`t{2}" -f $h.Path, $h.LineNumber, (SafeLine $h.Line))
  }
}

# 6) Transações / prepare / exec
Write-Section '6) USO DE TRANSACOES / PREPARE / EXEC'
$txPatterns = @('beginTransaction(', 'commit(', 'rollBack(', '->prepare(', '->exec(')
$txHits = Select-String -Path $phpFiles.FullName -Pattern $txPatterns -SimpleMatch |
  Select-Object Path, LineNumber, Line |
  Sort-Object Path, LineNumber

if (-not $txHits -or $txHits.Count -eq 0) {
  Add-Line 'Nenhum beginTransaction/commit/rollBack/prepare/exec encontrado.'
} else {
  foreach ($h in $txHits) {
    Add-Line ("{0}:{1}`t{2}" -f $h.Path, $h.LineNumber, (SafeLine $h.Line))
  }
}

# 7) Bonus: procurar migrations SQL (em .php/.sql/.txt)
Write-Section '7) BONUS: MIGRATIONS/SQL (CREATE/ALTER/DROP/INDEX)'
$sqlFiles = Get-ChildItem -Path $Root -Recurse -File -Include *.php,*.sql,*.txt |
  Where-Object { $_.FullName -notmatch '\\node_modules\\' -and $_.FullName -notmatch '\\vendor\\' -and $_.FullName -notmatch '\\.git\\' } |
  Sort-Object FullName

$sqlPatterns = @('CREATE TABLE', 'ALTER TABLE', 'DROP TABLE', 'CREATE INDEX')
$sqlHits = Select-String -Path $sqlFiles.FullName -Pattern $sqlPatterns -SimpleMatch |
  Select-Object Path, LineNumber, Line |
  Sort-Object Path, LineNumber

if (-not $sqlHits -or $sqlHits.Count -eq 0) {
  Add-Line 'Nenhuma ocorrencia encontrada.'
} else {
  foreach ($h in $sqlHits) {
    Add-Line ("{0}:{1}`t{2}" -f $h.Path, $h.LineNumber, (SafeLine $h.Line))
  }
}

Write-Section 'FIM'
Add-Line ("Relatorio gerado em: {0}" -f $OutFile)

Write-Host ''
Write-Host 'OK! Relatorio gerado:' -ForegroundColor Green
Write-Host $OutFile -ForegroundColor Green
Write-Host ''
