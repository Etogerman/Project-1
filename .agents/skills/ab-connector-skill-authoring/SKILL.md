---
name: ab-connector-skill-authoring
description: Create or update AB Connector repo skills under .agents/skills. Use when the user asks to design, add, change, review, or decide whether to create a Codex skill for AB Connector workflows, project process, delivery checks, release gates, spec sync, local MVP handoff, or agent collaboration.
---

# AB Connector Skill Authoring

Use this skill to design focused repo-scoped skills for AB Connector. Keep the work aligned with `AGENTS.md` and `docs/task-delivery-workflow.md`.

## Workflow

1. Classify the request.
   - If the user asks for analysis, a plan, review, or skill text only, work read-only.
   - If the user asks to create or update skill files, treat it as a policy/docs-only stream unless the requested skill adds scripts that change runtime behavior.
   - Do not edit files without explicit user command and a clear scope.

2. Decide whether a skill is the right surface.
   - Use `AGENTS.md` for durable repo-wide rules that must apply to every task.
   - Use a skill for repeatable workflows with triggers, steps, checks, or routing.
   - Use docs when the content is reference material rather than an invocation workflow.
   - Use MCP/connectors when the workflow needs live external systems.
   - Use a plugin only when the workflow should be distributed as an installable bundle.

3. Define the skill contract before writing files.
   - Skill name: lowercase hyphen-case, under 64 characters.
   - Location: `.agents/skills/<skill-name>/SKILL.md`.
   - Scope: one job per skill.
   - Triggers: include all trigger conditions in the YAML `description`.
   - Anti-scope: state what the skill must not do in the body.
   - Inputs and outputs: name the expected source docs, checks, and final answer shape.

4. Keep the skill short.
   - Do not copy large sections from `AGENTS.md` or `docs/task-delivery-workflow.md`.
   - Route to canonical docs instead: tell Codex which local files and sections to read.
   - Prefer imperative steps.
   - Add scripts only when deterministic repeated automation is needed.
   - Add references only when the skill needs non-obvious detail that should not live in `SKILL.md`.

5. Preserve AB Connector delivery rules.
   - New code/runtime streams are staging-first after local MVP and operator decision.
   - Docs-only/policy-only skills may use the docs-only path from clean `origin/main`.
   - Skills must not create shortcuts around Spec repo, Spec doc, Spec revision, PR checkpoints, CI, ready, merge, deploy, or smoke gates.
   - PR handoff keeps `merge` user-performed; Codex verifies before/after and permits cleanup only after both closure checkpoints.
   - Closure basis: accepted production result/risk for code/release; verified merged result for docs/process. Then preserve `Issue Closure` -> applicable `Spec Closure` -> cleanup and the exact records from `docs/task-delivery-workflow.md`.
   - `не требуется` gives `Issue Closure: not_required`; `#NNN` needs the exact merged-PR record. Closing references block pre-merge; `left_open` is non-blocking after cleanup. Close/reopen is user-performed.
   - If a skill could touch Bitrix24, Open Lines, Telegram, MAX, queues, scheduler, env, config, or runtime, classify it as code/runtime unless proven otherwise.

6. Write the files.
   - Prefer initializing with the standard skill creator script when creating a new skill.
   - Create only required files: usually `SKILL.md` and optionally `agents/openai.yaml`.
   - Do not add README, changelog, quick reference, or installation docs inside the skill folder.
   - Fix `agents/openai.yaml` so `interface.default_prompt` explicitly mentions `$<skill-name>`.

7. Validate.
   - Run the skill validation script if available.
   - Check the changed scope internally with git status or a diff summary.
   - Run `git diff --check`.
   - Search for leftover placeholder markers before handoff.
   - Verify that the skill does not permit cleanup before `Issue Closure` and applicable `Spec Closure`.
   - End with an author self-check: scope, triggers, anti-scope, docs-only status, and next step.

## Output

When proposing a skill without writing files, return:
- skill name;
- location;
- trigger description;
- full `SKILL.md` text;
- whether `agents/openai.yaml` is useful now;
- recommended next action.

When writing files, report:
- concise user-facing status without file lists by default;
- whether changes are local, committed, pushed, or in a PR;
- whether a follow-up is needed;
- validation results;
- next docs-only process step.

Show detailed file lists, full diffs, or technical scope breakdowns only when
the user asks, when there is risk or ambiguity, or when the user cannot choose
the next step without those details.
