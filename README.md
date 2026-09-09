# ManyContext

Gestão de projetos, tarefas e documentos em PHP e SQLite, com interface web e servidor MCP para integração com agentes. Sem framework ou etapa de build.

## Recursos

- Projetos com membros, status e prioridades configuráveis, lista e Kanban.
- Nome de exibição editável nas configurações. `Grupo/Projeto` agrupa a sidebar; o identificador original (`name`) permanece estável nas URLs, API, MCP e relações do banco.
- Tarefas com descrição Markdown, responsáveis, tags sugeridas enquanto você digita, prazo, grupo, anexos e comentários.
- Visão geral da tarefa reúne conteúdo, metadados, checklist, anexos e atividade. A aba **Sub-tasks** permite adicionar, editar, ordenar, concluir e remover itens; clique em **Salvar** para persistir o checklist.
- Menu de contexto com botão direito na lista ou Kanban: abrir tarefa, definir status/mover para etapa, prioridade, grupo e prazo.
- Documentos e pastas com busca e compartilhamento por link público.
- Dashboard e recursos opcionais de IA via AIVAX.

## Requisitos

PHP 8.1 ou superior, SQLite com FTS5 e extensões `pdo_sqlite`, `mbstring`, `curl`, `fileinfo` e `zip`. Em produção, use uma versão de PHP ainda suportada, HTTPS e PHP-FPM.

## Execução local

```sh
git clone https://github.com/CypherPotato/manycontext.git
cd manycontext
cp config.ini.example config.ini
```

Edite `config.ini`: defina um PIN próprio com seis dígitos e uma chave MCP longa e aleatória para cada usuário. O exemplo não contém um PIN válido. Se já houver configuração, preserve-a; não execute a cópia por cima dela.

```sh
php -S localhost:8080 -t public public/index.php
```

Abra http://localhost:8080. O servidor embutido é apenas para desenvolvimento. O processo PHP precisa escrever em `storage/`. O banco e suas migrações aditivas são criados automaticamente.

### Configuração

| Variável | Uso |
| --- | --- |
| `MANYCONTEXT_CONFIG` | Caminho alternativo para o arquivo INI; padrão: `config.ini` na raiz. |
| `MANYCONTEXT_AUTH_USERS` | Lista JSON de usuários, substitui as seções `[auth.N]`. |
| `AIVAX_API_KEY` | Chave opcional para IA; sobrepõe `[aivax].api_key`. |
| `AIVAX_MODEL` | Modelo opcional; padrão `@inception/mercury-2`. |
| `APP_TIMEZONE` | Fuso horário; padrão `America/Sao_Paulo`. |

Nunca publique `config.ini`, `storage/`, chaves ou backups. A ausência de chave AIVAX desabilita chamadas de IA, sem impedir a gestão básica. Links públicos de documentos dão acesso a quem possui o link; compartilhe com cuidado.

## API e MCP

A API `/api/projects` usa sessão autenticada por PIN. O endpoint `/mcp` usa a chave individual em `Authorization: Bearer <chave>` e oferece descoberta de ferramentas com `tools/list`. As permissões de projeto são aplicadas em ambos.

Renomear um projeto, sem mudar seu ID:

```http
PUT /api/projects/ID_ORIGINAL
Content-Type: application/json

{"displayName":"Equipe/Produto"}
```

Criar ou atualizar uma tarefa aceita `subTasks` na API e nas ferramentas MCP de tarefas:

```json
{
  "title": "Preparar entrega",
  "subTasks": [
    {"title": "Revisar", "description": "Conferir critérios de aceite", "order": 0, "completed": false}
  ]
}
```

A lista é ordenada por `order`. Atualizações que omitem `subTasks` preservam o checklist; enviar `[]` o limpa. O título de cada item é obrigatório; `order` é inteiro não negativo e `completed` é booleano. Limite: 500 itens.

O módulo Agent Logs não integra esta versão: interface, rotas, ferramentas MCP e código foram removidos. A tabela legada de bancos existentes fica inerte para preservar dados históricos; instalações novas não a criam. O histórico de atividades e comentários das tarefas permanece disponível.

## Validação

```sh
php tests/run.php
```

A suíte inicia um servidor local com configuração e SQLite temporários, sem chave de IA, e verifica API, autenticação, MCP, documentos, tarefas e migrações. Não usa o banco de produção.

## Produção e auto-pull

Configure o document root exclusivamente como `public/`, nunca a raiz do repositório. Negue acesso HTTP a arquivos ocultos e mantenha configuração, dados e metadados Git fora da área pública.

O script `scripts/auto-pull.sh` recebe o caminho da instalação. Ele usa lock, recusa alterações locais em arquivos rastreados, busca `origin/main` e aplica apenas fast-forward. Não executa reset, clean ou migrações destrutivas. Execute com usuário dedicado ao deploy, não o usuário do PHP, e mantenha o código sem permissão de escrita para o PHP.

```sh
bash scripts/auto-pull.sh /caminho/da/instalacao
```

Agende por timer systemd ou cron. Faça backup consistente do SQLite, anexos e configuração antes da primeira publicação ou de migrações. O auto-pull não substitui backups. Como o checkout atualiza arquivos no lugar, pode haver uma janela breve com versões misturadas; use releases atômicas caso sua disponibilidade exija eliminar essa janela.

## Estrutura

- `public/`: entrada HTTP, interface e login.
- `src/`: persistência, autenticação, API, MCP e cliente de IA.
- `tests/`: testes de integração com dados isolados.
- `scripts/`: atualização de código em produção.

## Contribuição

Envie alterações focadas por pull request e execute a suíte antes de publicar. Não inclua credenciais nem dados reais em testes. Este repositório não define uma licença de redistribuição.
