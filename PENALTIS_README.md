# Sistema de Disputa de Pênaltis - Documentação

## Visão Geral

Este documento descreve a implementação do sistema completo de disputa de pênaltis no projeto Palmeiras Manager.

## Estrutura do Banco de Dados

### Tabela: match_penalties

```sql
CREATE TABLE match_penalties (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER REFERENCES users(id),
  match_id INTEGER NOT NULL REFERENCES matches(id) ON DELETE CASCADE,
  team TEXT NOT NULL CHECK(team IN ('HOME', 'AWAY')),
  player_name TEXT NOT NULL,
  order_number INTEGER NOT NULL,
  scored INTEGER NOT NULL CHECK(scored IN (0, 1)),
  UNIQUE(match_id, team, order_number)
);
```

### Campos:
- **id**: Identificador único da cobrança
- **user_id**: Usuário proprietário do registro
- **match_id**: Referência à partida
- **team**: Time (HOME ou AWAY)
- **player_name**: Nome do batedor
- **order_number**: Ordem de cobrança (1-10)
- **scored**: Se converteu (1) ou perdeu (0)

## Arquivos Modificados

### 1. create_match.php
**Localização**: `/src/pages/create_match.php`

**Alterações:**
- Adicionada função `render_penalties_block()` para renderizar campos de pênaltis
- Adicionada lógica de salvamento de pênaltis no POST
- Adicionada lógica de carregamento de pênaltis no modo de edição
- Campos de pênaltis aparecem para TODAS as partidas (até 10 batedores por time)

**Funcionalidades:**
- Cadastro de até 10 batedores por time
- Seleção via radio buttons: Gol (✓) ou Perdeu (✗)
- Validação automática de dados
- Suporte a morte súbita (cobranças além da 5ª)

### 2. edit_match.php
**Localização**: `/src/pages/edit_match.php`

**Alterações:**
- Nenhuma alteração necessária (reutiliza create_match.php)
- Pênaltis são automaticamente carregados e podem ser editados

### 3. match.php
**Localização**: `/src/pages/match.php`

**Alterações:**
- Adicionadas funções `load_penalties()` e `render_penalties()`
- Placar modificado para exibir formato: `MANDANTE GOLS (GOLS_PENALTI) X (GOLS_PENALTI) GOLS VISITANTE`
- Exibição de pênaltis só aparece se houver dados cadastrados
- Badges coloridos: Verde (✓ Gol) e Vermelho (✗ Perdeu)

**Exemplo de Exibição:**
```
PALMEIRAS 2 (4) X (3) 2 CORINTHIANS
```

### 4. almanaque_players.php
**Localização**: `/src/pages/almanaque_players.php`

**Alterações:**
- Adicionada query separada para calcular gols de pênaltis
- Nova coluna "Gols (Pên)" na tabela de estatísticas
- Gols de pênaltis NÃO afetam a coluna "Gols" normal
- Suporte a ordenação por gols de pênaltis

## Regras de Estatísticas

### ✅ O que está implementado:

1. **Gols de pênaltis são contabilizados SEPARADAMENTE**
   - Tabela `match_penalties` independente
   - Não afetam `match_player_stats.goals_for`
   - Coluna separada no almanaque: "Gols (Pên)"

2. **Clean Sheets NÃO são afetados**
   - Gols de pênaltis não são salvos como `goals_against`
   - Goleiros mantêm clean sheet mesmo sofrendo gols nos pênaltis

3. **Exibição no Almanaque**
   - Coluna "Gols": Gols marcados no tempo normal/prorrogação
   - Coluna "Gols (Pên)": Gols marcados em disputas de pênaltis
   - Ambas podem ser ordenadas independentemente

## Fluxo de Uso

### Cadastrar Pênaltis:

1. Acesse "Criar Partida" ou "Editar Partida"
2. Preencha os dados normais da partida (escalações, gols, etc.)
3. Role até a seção "Pênaltis (até 10)" de cada time
4. Para cada batedor:
   - Digite o nome do jogador
   - Selecione "✓ Gol" ou "✗ Perdeu"
5. Salve a partida

### Visualizar Pênaltis:

1. Acesse a página da partida (match.php)
2. Se houver pênaltis cadastrados:
   - O placar mostrará: `TIME1 X (Y) X (Z) W TIME2`
   - Onde Y e Z são os gols nas cobranças
3. Role até "Disputa de Pênaltis" em cada time
4. Veja a sequência completa de cobranças com badges coloridos

### Ver Estatísticas:

1. Acesse "Almanaque" > "Jogadores"
2. A coluna "Gols (Pên)" mostra gols em disputas de pênaltis
3. A coluna "Gols" mostra apenas gols no tempo normal/prorrogação

## Validações e Restrições

- ✅ Mínimo de 5 batedores recomendado (mas não obrigatório)
- ✅ Máximo de 10 batedores por time
- ✅ Cada batedor deve ter nome E resultado selecionado
- ✅ Ordem automática baseada na sequência (1-10)
- ✅ Apenas uma opção (Gol OU Perdeu) pode ser selecionada

## Tecnologias Utilizadas

- **Backend**: PHP 7.4+
- **Banco de Dados**: SQLite 3
- **Frontend**: Bootstrap 5 (radio buttons e badges)
- **Arquitetura**: MVC simplificado

## Migração

Execute o script de migração localizado em:
```
/database/migrations/20260404_add_match_penalties.sql
```

Ou a tabela já foi criada automaticamente no banco de dados existente.

## Suporte e Manutenção

### Adicionar Mais Batedores:
Altere o loop em `render_penalties_block()`:
```php
for ($i=0; $i<15; $i++) { // Aumentar de 10 para 15
```

### Alterar Validações:
Modifique a seção de salvamento em `create_match.php`:
```php
for ($i=0; $i<10; $i++) {
  $name = trim(postv("pal_pen_name_$i"));
  $result = postv("pal_pen_result_$i");
  if ($name !== '' && $result !== '') {
    // Adicione validações aqui
  }
}
```

## Changelog

### v1.0.0 (2026-04-04)
- ✅ Criação da tabela `match_penalties`
- ✅ Campos de cadastro em create_match.php
- ✅ Edição de pênaltis via edit_match.php
- ✅ Exibição formatada em match.php
- ✅ Estatísticas separadas no almanaque
- ✅ Documentação completa

## Observações Importantes

1. **Os pênaltis só aparecem na visualização se houver dados cadastrados**
   - Similar ao comportamento das substituições
   - Mantém a interface limpa

2. **Gols de pênaltis NÃO contam como gols normais**
   - São estatísticas completamente separadas
   - Isso evita distorcer os números dos artilheiros

3. **Clean sheets preservados**
   - Goleiros não perdem clean sheet por gols sofridos nos pênaltis
   - Estatística preserva o mérito do jogo no tempo regulamentar

4. **Associação por nome**
   - Pênaltis são associados por nome do jogador (string)
   - Não há FK para `players.id` (permite registrar qualquer nome)
   - Match case-insensitive no almanaque

## Testado com Sucesso ✅

- ✅ Criação de tabela no banco de dados
- ✅ Sintaxe PHP validada
- ✅ Estrutura de arquivos preservada
- ✅ Compatibilidade com sistema existente
- ✅ Migração SQL documentada
