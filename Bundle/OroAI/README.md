# GenakerOroAIBundle

AI assistant for OroCommerce. Adds a chat input to the admin header **and** an addable
dashboard widget (both share one JS implementation) that connect to a configurable LLM
backend, with tool use (SQL queries, entity lookup, schema inspection, config reading) and a
RAG knowledge base backed by Redis Stack.

![Oro AI Assistant — System Configuration](docs/configuration.png)

---

## Features

- **Multi-provider LLM** — OpenAI, Anthropic Claude, Google Gemini; switch via env var or admin UI
- **Model selection** — dropdown in System Configuration populated from `Resources/config/ai_models.yml`; no code change needed to add new models
- **Tool use** — the agent can query the database, inspect entity metadata, look up routes, read logs, and more
- **Custom instructions** — free-form text prepended ahead of the built-in system prompt on every call, for house style, terminology, or extra guardrails — no code change needed
- **Research sub-agent** — delegates open-ended, multi-step investigations to a separate agent with its own tool loop and step budget, returning one synthesized answer instead of consuming the main conversation's iteration budget; opt-in, disabled by default
- **RAG (Retrieval-Augmented Generation)** — semantic search over docs, DB schema, system config, and admin menu; answers are grounded in real OroCommerce data
- **Chat UI** — always-visible input in the admin header; on send the panel slides open below the header and the input relocates inside the panel for a native chat experience
- **Dashboard widget** — the same chat experience as an addable OroCommerce dashboard widget ("ORO AI Assistant"), sharing one JS file with the header chat (`oroai-chat.js`'s `initOroAiChat({idPrefix, mode})` factory) instead of maintaining two implementations
- **Resolution harness** — optional outer retry loop that evaluates each reply and retries with a different tool/approach when the first attempt is incomplete, instead of returning a shallow answer (see [HARNESS.md](HARNESS.md))
- **Extensible by convention, not by editing this bundle** — any bundle can add its own RAG docs, general agent guidelines, or tools without touching `GenakerOroAIBundle`'s code (see [Extending the agent](#extending-the-agent) below)

---

## Quick start

### 1. Configure the provider

Add to `.env-app.local`:

```dotenv
###> OroAI / Gemini config ###
OROAI_PROVIDER=gemini
OROAI_API_KEY=<your-gemini-key>
OROAI_MODEL=gemini-2.0-flash
OROAI_EMBEDDING_API_KEY=<your-gemini-key>
OROAI_REDIS_URL=redis://redis_search:6379
###< OroAI / Gemini config ###
```

Supported values for `OROAI_PROVIDER`: `gemini`, `openai`, `anthropic`.

### 2. Start the Redis Stack container

RediSearch (vector search) requires the Redis Stack image — the plain `redis` service does not have it:

```bash
docker-compose up -d redis_search
```

### 3. Build the RAG index

```bash
php bin/console genaker:oroai:rag:reindex --provider=docs --provider=config
```

### 4. Verify RAG is working

```bash
php bin/console genaker:oroai:rag:test "application URL" --top=3
```

### 5. Clear Symfony cache

```bash
php bin/console cache:clear
```

---

## Configuration

### Environment variables

Env vars take priority over admin UI settings. Symfony Dotenv sets `$_SERVER`/`$_ENV` only — `getenv()` returns false for vars loaded from `.env-app.local`.

| Variable | Default | Description |
|----------|---------|-------------|
| `OROAI_PROVIDER` | `openai` | LLM provider: `openai`, `gemini`, `anthropic` |
| `OROAI_API_KEY` | — | API key for the LLM provider |
| `OROAI_MODEL` | provider default | Model name — overrides the admin UI dropdown |
| `OROAI_CUSTOM_INSTRUCTIONS` | — (empty) | Text prepended ahead of the built-in system prompt on every call |
| `OROAI_EMBEDDING_API_KEY` | falls back to `OROAI_API_KEY` | Separate key for embedding calls |
| `OROAI_REDIS_URL` | `redis://redis_search:6379` | Redis Stack URL for the RAG vector index |

### Admin UI

Go to **System → Configuration → General Setup → Oro AI Assistant** to set the provider, API key, model, temperature, and toggle individual tools — no deployment needed.

### Custom instructions

Add house style, terminology, or extra guardrails without touching code. Set it via **System → Configuration → General Setup → Oro AI Assistant → Custom Instructions**, or with an env var (which takes priority over the admin UI, same as the other settings):

```dotenv
OROAI_CUSTOM_INSTRUCTIONS="Always refer to customers as \"accounts\". Never suggest deleting records — recommend disabling them instead."
```

It's sent as its own system message, prepended ahead of the bundle's built-in system prompt, so it takes the highest priority on every call — before tool-use guidelines and any RAG-retrieved context. Leave it empty to use only the built-in prompt (the default).

### Research sub-agent

For open-ended questions that need cross-checking several tools or tables — "explain how shipping rates are calculated end to end", not "what is order #42" — the main agent can delegate to a **research sub-agent**: a second, independent tool-calling loop with its own step budget, invoked as an ordinary tool (`research`) and returning one synthesized answer instead of a raw trace. This mirrors how Claude Code's own `Agent` tool keeps a main conversation's context clean by handing open-ended exploration to a sub-agent rather than letting it consume the main loop's iteration budget.

It's **disabled by default** — unlike every other tool, one call spawns a whole extra multi-step LLM loop, so it's opt-in:

| Setting | Where | Default | Description |
|---------|-------|---------|-------------|
| `genaker_oro_ai.tool_research_enabled` | System Configuration → Enabled Tools → **Research Sub-Agent** | `false` | Master switch — when off, the `research` tool isn't even offered to the main agent |
| `genaker_oro_ai.research_max_iterations` | System Configuration → AI Assistant Settings → **Research Sub-Agent Max Steps** | `8` | The sub-agent's own step budget, separate from (and typically higher than) `Max Tool Iterations` |

Two things worth knowing if you're extending this:
- The sub-agent's tool list always **excludes `research` itself**, so it can't recursively delegate to another copy of itself.
- `ResearchSubAgent` builds its internal `ToolRegistry` lazily on first use, not in its constructor — it's built from the same `genaker_oroai.tool` tagged iterator that `ResearchTool` itself is tagged into, so eagerly consuming it during construction would be a circular self-reference (`ResearchTool` → `ResearchSubAgent` → tagged tools → `ResearchTool`...). See `ResearchSubAgent`'s class doc for the full explanation.

### Model list

Available models are defined in [`Resources/config/ai_models.yml`](Resources/config/ai_models.yml). To add a model, append an entry under the appropriate provider group:

```yaml
models:
  gemini:
    - { label: 'Gemini 2.0 Flash (15 RPM free)', value: 'gemini-2.0-flash' }
    - { label: 'Gemini 2.5 Flash (10 RPM free)', value: 'gemini-2.5-flash' }
    - { label: 'Gemini 2.5 Pro', value: 'gemini-2.5-pro' }
  openai:
    - { label: 'GPT-4o', value: 'gpt-4o' }
    ...
```

Run `cache:clear` after editing the file.

---

## Chat UI behaviour

1. **Collapsed** — compact input + send button visible in the header search row
2. **First send** — panel slides open below the header; input relocates inside the panel below the message history
3. **Minimize / Clear / Escape / click-outside** — panel closes; input returns to the header
4. **Focus** — input is auto-focused when the panel opens and again after each AI response so you can keep typing without clicking
5. **Loading state** — while waiting for a reply (and before any tool-use checklist step arrives), a randomly rotating status word drawn from 100 B2B/OroCommerce-themed verbs ("Ordering…", "Quoting…", "Reconciling…", "Palletizing…", ...) cycles every 1.4s in place of a static "Thinking…" label, in the style of Claude Code's own CLI spinner

---

## Dashboard widget

The exact same assistant is also addable as a standard OroCommerce dashboard widget — go to
**Dashboard → Configure → Add Widget → ORO AI Assistant**. It's gated by the same
`genaker_oroai_chat` ACL as the header chat and talks to the same
`/admin/oroai/chat/message` / `/admin/oroai/chat/progress` endpoints, so conversations,
tool-use checklist, and token usage display all behave identically to the header — it's a
different mount point (`mode: 'inline'` vs. the header's `mode: 'panel'`), not a different
implementation.

For the general mechanics of how any Oro dashboard widget is wired — the route-exposure trap,
the `.widget-content` response contract, `WidgetConfigs` wiring for the title bar, seeding a
widget into the default dashboard layout — see
**[DASHBOARD_WIDGET_GUIDE.md](DASHBOARD_WIDGET_GUIDE.md)**, written from the real issues hit
building this specific widget.

---

## Extending the agent

Three things can be added by any bundle in the codebase, purely by convention — no edit to
`GenakerOroAIBundle` itself required:

| To add | Drop this | Picked up by |
|---|---|---|
| A new tool the agent can call | A class implementing `AiToolInterface`, tagged `genaker_oroai.tool` in `services.yml` | `ToolRegistry` — its own `ToolDefinition::description` is also its "when to use me" guidance, rendered into the system prompt automatically |
| A cross-cutting behavioral rule (not about one tool) | An entry under `oro_ai.guidelines` in your bundle's own `Resources/config/oro/oro_ai_guidelines.yml` | `GuidelineProvider`, which merges that key across every registered bundle |
| Documentation the agent can search (`doc_search`) | A Markdown file in your bundle's own `Resources/rag/` | `DocFilesRagProvider`, then `php bin/console genaker:oroai:rag:reindex --provider=docs` |

All three use the same underlying mechanism — Oro's `CumulativeResourceManager`, the same
singleton behind cross-bundle `Resources/config/oro/*.yml` and `Resources/views` loading —
scanning every registered bundle for a file at a known relative path rather than a hardcoded
list. See `GuidelineProvider`/`DocFilesRagProvider` for the reference implementation if adding
a fourth extension point on the same pattern.

---

## Directory structure

```
GenakerOroAIBundle/
├── Agent/              # OroAiAgent — orchestrates tools and RAG context
│                       # ResolutionHarness — optional outer retry/evaluate loop
│                       # GuidelineProvider — merges oro_ai_guidelines.yml across bundles
│                       # ResearchSubAgent — independent loop for delegated deep-dives
│                       # ChatProgressStore — live "what is it doing" checklist backing store
├── Command/            # Console commands (rag:reindex, rag:test)
├── Controller/         # ChatController — handles AJAX chat + progress-polling + dashboard widget requests
├── DependencyInjection/
├── Form/Type/          # AiModelChoiceType — builds model dropdown from ai_models.yml
├── Llm/                # LLM clients (OpenAI, Gemini, Anthropic) + registry
├── Rag/                # Embedding clients, RediSearchRagStore, providers
│   ├── Provider/       # DocFiles, Schema, Menu, SystemConfig providers
│   └── Contract/       # RagProviderInterface
├── Resources/
│   ├── config/
│   │   ├── ai_models.yml           # model list for the admin UI dropdown
│   │   ├── oro/
│   │   │   ├── dashboards.yml      # registers the ORO AI Assistant dashboard widget
│   │   │   └── oro_ai_guidelines.yml  # this bundle's own general-guideline contribution
│   │   └── services.yml
│   ├── public/js/      # oroai-chat.js — shared chat UI, initOroAiChat({idPrefix, mode})
│   ├── rag/            # Markdown knowledge-base files indexed by docs provider
│   └── views/
│       ├── Chat/       # chatBar.html.twig — header widget
│       └── Widget/     # aiChat.html.twig — dashboard widget
├── Service/            # OroAiConfig — reads env vars and system config
├── Tools/              # SQL, schema, entity, route, log, config, translation, research tools
├── RAG.md              # RAG technical reference
├── HARNESS.md          # Resolution harness technical reference
├── DASHBOARD_WIDGET_GUIDE.md  # General guide to building any Oro dashboard widget
└── EXAMPLES.md         # Use-case examples
```

---

## Resolution Harness deep-dive

See **[HARNESS.md](HARNESS.md)** for:

- Full loop diagram showing all three evaluator outcomes
- Context enrichment — how "tools already tried" prevents redundant retries
- Memory system — how resolved answers feed back into RAG
- Cost model — LLM call budget per request at various settings
- Configuration reference and when-to-enable guidance
- Full `HarnessInterface` and `HarnessResult` API

---

## RAG deep-dive

See **[RAG.md](RAG.md)** for:

- Embedding models, dimensions, and storage format
- Cosine similarity algorithm and score interpretation table
- How to tune top-K, similarity thresholds, and chunk size
- HNSW index parameters and brute-force fallback
- Switching between Gemini and OpenAI embeddings
- Adding a custom RAG provider
- Full unit test and integration test examples

---

## CLI reference

| Command | Description |
|---------|-------------|
| `genaker:oroai:rag:reindex` | Rebuild the vector index from all (or selected) providers |
| `genaker:oroai:rag:test <query>` | Search the index and show scores — useful for debugging relevance |

```bash
# Reindex only config and docs
php bin/console genaker:oroai:rag:reindex --provider=config --provider=docs

# List all registered providers
php bin/console genaker:oroai:rag:reindex --list

# Drop index and rebuild from scratch (required after switching embedding model)
php bin/console genaker:oroai:rag:reindex --clear

# Test a query — shows cosine distance, similarity %, and matched text
php bin/console genaker:oroai:rag:test "checkout configuration" --top=5
php bin/console genaker:oroai:rag:test "checkout configuration" -k 1 --full
```

---

## Running tests

```bash
# Unit tests (no containers needed)
bin/phpunit -c phpunit-dev.xml src/Genaker/Bundle/OroAI/Tests/Unit

# Integration tests (requires live redis_search container)
INTEGRATION_TESTS_ENABLED=1 bin/phpunit -c phpunit-dev.xml --filter RagStoreIntegrationTest
```

## Rate limits (Gemini free tier)

| Model | RPM | RPD |
|-------|-----|-----|
| `gemini-2.0-flash` | 15 | 1 500 |
| `gemini-2.5-flash` | 10 | 500 |
| `gemini-2.5-pro` | 5 | 25 |

Upgrade to a paid API key or switch to `gemini-2.0-flash` to reduce 429 errors.
