---
name: laravel-boost-context
description: Use Laravel Boost MCP for AB Connector Laravel/Filament/Livewire context. Trigger before Laravel ecosystem code changes, database/model/migration work, Filament actions/tables/forms work, route/config/log diagnostics, version-specific Laravel docs lookup, or local runtime checks when Boost is available.
---

# Laravel Boost Context

Use Laravel Boost as the first context source for Laravel ecosystem facts in this repo. Keep it read-only by default and preserve all AB Connector delivery rules from `AGENTS.md`.

## Workflow

1. Confirm project facts with Boost before reasoning from memory.
   - Use `application-info` at the start of a Laravel/Filament/Livewire task to capture PHP, Laravel, package, and database versions.
   - If Boost MCP is unavailable, state that and fall back to local files and normal commands.

2. Use targeted Boost tools for the task.
   - Database shape: `database-schema`, first in `summary` mode, then with a narrow `filter`.
   - Data inspection: `database-query` with read-only SQL only (`select`, `show`, `explain`, `describe`).
   - Runtime issues: `last-error`, `read-log-entries`, and `browser-logs` before broad log searching.
   - URLs/routes: `get-absolute-url` before guessing generated URLs.
   - Documentation: `search-docs` for Laravel ecosystem packages before web search or memory-based API claims.

3. Keep the scope safe.
   - Do not use Boost to bypass tests, review gates, Spec repo rules, PR checkpoints, staging-first rollout, user-owned merge rules, deploy gates, or smoke checks.
   - Do not run mutating SQL, mutating Tinker code, migrations, seeders, queue/scheduler actions, external integration calls, or config/env changes through Boost unless the user explicitly delegated that specific operation.
   - Treat Bitrix24, Open Lines, Telegram, MAX, queues, scheduler, and production/staging runtime checks as integration/runtime work under `AGENTS.md`.

4. Report Boost-derived facts explicitly.
   - Mention which Boost tool provided the fact when it affects an implementation or recommendation.
   - Separate verified facts from inferences.
   - If a Boost docs search is noisy, refine the query instead of treating the first result as authoritative.

## Local Runtime Notes

This branch configures Boost through `.mcp.json` and `.codex/config.toml` with:

```bash
docker compose exec -T dev php artisan boost:mcp
```

The local `dev` container must mount the current worktree at `/var/www/html`. If Docker reports a path/mount mismatch, fix the local runtime identity before using Boost results for operator acceptance.

## Handoff

At handoff, include:
- whether Boost was used;
- which tools were used;
- whether all Boost operations were read-only;
- any local runtime mismatch or dependency issue discovered while using Boost.
