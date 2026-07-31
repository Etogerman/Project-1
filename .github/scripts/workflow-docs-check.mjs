#!/usr/bin/env node
import assert from "node:assert/strict";
import {
  existsSync,
  readFileSync,
  readdirSync,
  statSync,
} from "node:fs";
import { dirname, relative, resolve } from "node:path";

const ROOT = process.cwd();
const REGISTRY_PATH = "docs/workflow/pr-correction/states.json";
const ROUTER_PATH = "docs/workflow/README.md";
const CONSTITUTION_PATH = "AGENTS.md";
const MAX_CONSTITUTION_LINES = 120;
const MAX_WORKFLOW_DOCUMENT_LINES = 160;
const REQUIRED_SHARED_MODULES = [
  "shared/work-authority.md",
  "shared/spec-and-delivery-gates.md",
  "shared/project-engineering-standards.md",
  "shared/local-integration-gates.md",
  "shared/evidence-gateway.md",
  "pr-correction/README.md",
];
const STATE_ID_PATTERN = /^[BCDGXP]\d{2}$/;
const ALLOWED_DYNAMIC_TARGETS = new Set(["return_state", "resume_state"]);
const ALLOWED_EXITS = new Set([
  "main_process",
  "main_process_stage_1",
  "terminal",
]);

function readJson(path) {
  return JSON.parse(readFileSync(resolve(ROOT, path), "utf8"));
}

function countLines(body) {
  if (body.length === 0) {
    return 0;
  }

  return body.split(/\r?\n/).length - (body.endsWith("\n") ? 1 : 0);
}

function collectMarkdownFiles(directory) {
  const absoluteDirectory = resolve(ROOT, directory);
  const result = [];

  for (const entry of readdirSync(absoluteDirectory)) {
    const absolutePath = resolve(absoluteDirectory, entry);

    if (statSync(absolutePath).isDirectory()) {
      result.push(...collectMarkdownFiles(relative(ROOT, absolutePath)));
    } else if (entry.endsWith(".md")) {
      result.push(relative(ROOT, absolutePath));
    }
  }

  return result;
}

function extractStateTransitions(document, id) {
  const heading = `## \`${id}\` —`;
  const start = document.indexOf(heading);

  if (start === -1) {
    return { count: 0, next: [] };
  }

  const rest = document.slice(start + heading.length);
  const nextHeadingIndex = rest.search(/\n## `(?:[BCDGXP]\d{2})` —/);
  const section = nextHeadingIndex === -1 ? rest : rest.slice(0, nextHeadingIndex);
  const transitionRows = section
    .split("\n")
    .filter((line) => /^\|\s*\d+\s*\|/.test(line));
  const next = new Set();

  for (const row of transitionRows) {
    const cells = row.split("|");
    const targetCell = cells[3] || "";

    for (const match of targetCell.matchAll(/`([BCDGXP]\d{2})`/g)) {
      next.add(match[1]);
    }
  }

  return { count: transitionRows.length, next: [...next] };
}

function validateRegistry(registry, adapters = {}) {
  const errors = [];
  const fileExists = adapters.fileExists || ((path) => existsSync(resolve(ROOT, path)));
  const readDocument =
    adapters.readDocument || ((path) => readFileSync(resolve(ROOT, path), "utf8"));

  if (registry.schemaVersion !== 1) {
    errors.push("schemaVersion должен быть равен 1");
  }

  if (registry.workflow !== "pr-correction") {
    errors.push("workflow должен быть равен pr-correction");
  }

  if (!registry.states || typeof registry.states !== "object") {
    errors.push("states должен быть объектом");
    return errors;
  }

  const states = registry.states;
  const stateIds = Object.keys(states);
  const declaredTransitionCount = stateIds.reduce(
    (total, id) => total + (Number.isInteger(states[id].transitionCount) ? states[id].transitionCount : 0),
    0,
  );

  if (registry.transitionCount !== declaredTransitionCount) {
    errors.push(
      `transitionCount workflow равен ${registry.transitionCount}, сумма состояний — ${declaredTransitionCount}`,
    );
  }

  if (!states[registry.entryState]) {
    errors.push(`entryState ${registry.entryState} отсутствует в states`);
  }

  for (const [id, state] of Object.entries(states)) {
    if (!STATE_ID_PATTERN.test(id)) {
      errors.push(`недопустимый ID состояния: ${id}`);
    }

    if (!state.name || !state.actor || !state.document) {
      errors.push(`${id}: обязательны name, actor и document`);
      continue;
    }

    if (!state.document.startsWith("docs/workflow/")) {
      errors.push(`${id}: document должен находиться в docs/workflow`);
    } else if (!fileExists(state.document)) {
      errors.push(`${id}: документ не найден: ${state.document}`);
    } else {
      const heading = `## \`${id}\` — ${state.name}`;
      const document = readDocument(state.document);

      if (!document.includes(heading)) {
        errors.push(`${id}: в ${state.document} нет заголовка ${heading}`);
      } else {
        const transitions = extractStateTransitions(document, id);

        if (!Number.isInteger(state.transitionCount) || state.transitionCount < 1) {
          errors.push(`${id}: transitionCount должен быть положительным целым числом`);
        } else if (transitions.count !== state.transitionCount) {
          errors.push(
            `${id}: в документе ${transitions.count} переходов вместо ${state.transitionCount}`,
          );
        }

        const expectedNext = new Set(state.next || []);
        const documentedNext = new Set(transitions.next);

        for (const target of expectedNext) {
          if (!documentedNext.has(target)) {
            errors.push(`${id}: переход в ${target} есть в реестре, но отсутствует в модуле`);
          }
        }

        for (const target of documentedNext) {
          if (!expectedNext.has(target)) {
            errors.push(`${id}: переход в ${target} есть в модуле, но отсутствует в реестре`);
          }
        }
      }
    }

    if (!Array.isArray(state.next)) {
      errors.push(`${id}: next должен быть массивом`);
      continue;
    }

    for (const target of state.next) {
      if (!states[target]) {
        errors.push(`${id}: неизвестное следующее состояние ${target}`);
      }
    }

    for (const target of state.dynamicNext || []) {
      if (!ALLOWED_DYNAMIC_TARGETS.has(target)) {
        errors.push(`${id}: неизвестный динамический переход ${target}`);
      }
    }

    for (const exit of state.exits || []) {
      if (!ALLOWED_EXITS.has(exit)) {
        errors.push(`${id}: неизвестный выход ${exit}`);
      }
    }

    if (
      state.next.length === 0 &&
      (state.dynamicNext || []).length === 0 &&
      (state.exits || []).length === 0
    ) {
      errors.push(`${id}: состояние не имеет перехода или выхода`);
    }
  }

  if (states[registry.entryState]) {
    const reachable = new Set([registry.entryState]);
    const queue = [registry.entryState];

    while (queue.length > 0) {
      const current = queue.shift();

      if (!states[current]) {
        continue;
      }

      for (const target of states[current].next || []) {
        if (!reachable.has(target)) {
          reachable.add(target);
          queue.push(target);
        }
      }
    }

    for (const id of stateIds) {
      if (!reachable.has(id)) {
        errors.push(`${id}: состояние недостижимо из ${registry.entryState}`);
      }
    }
  }

  return errors;
}

function validateMarkdownLinks(files) {
  const errors = [];
  const linkPattern = /\[[^\]]*\]\(([^)]+)\)/g;

  for (const file of files) {
    const body = readFileSync(resolve(ROOT, file), "utf8");

    for (const match of body.matchAll(linkPattern)) {
      const rawTarget = match[1].trim().split(/\s+"/)[0];

      if (
        rawTarget.startsWith("http://") ||
        rawTarget.startsWith("https://") ||
        rawTarget.startsWith("mailto:") ||
        rawTarget.startsWith("#")
      ) {
        continue;
      }

      const targetPath = rawTarget.split("#")[0];

      if (!targetPath) {
        continue;
      }

      const resolvedTarget = resolve(ROOT, dirname(file), targetPath);

      if (!existsSync(resolvedTarget)) {
        errors.push(`${file}: не найдена локальная ссылка ${rawTarget}`);
      }
    }
  }

  return errors;
}

function validateProgressiveLoading(registry) {
  const errors = [];
  const constitution = readFileSync(resolve(ROOT, CONSTITUTION_PATH), "utf8");
  const constitutionLines = countLines(constitution);

  if (constitutionLines > MAX_CONSTITUTION_LINES) {
    errors.push(
      `${CONSTITUTION_PATH}: ${constitutionLines} строк, максимум ${MAX_CONSTITUTION_LINES}`,
    );
  }

  const workflowDocuments = collectMarkdownFiles("docs/workflow");

  for (const document of workflowDocuments) {
    const lines = countLines(readFileSync(resolve(ROOT, document), "utf8"));

    if (lines > MAX_WORKFLOW_DOCUMENT_LINES) {
      errors.push(
        `${document}: ${lines} строк, максимум ${MAX_WORKFLOW_DOCUMENT_LINES}`,
      );
    }
  }

  const router = readFileSync(resolve(ROOT, ROUTER_PATH), "utf8");
  const stateModules = [
    ...new Set(
      Object.values(registry.states).map((state) =>
        relative(dirname(resolve(ROOT, ROUTER_PATH)), resolve(ROOT, state.document)),
      ),
    ),
  ];

  for (const target of [...REQUIRED_SHARED_MODULES, ...stateModules]) {
    if (!router.includes(`(${target})`)) {
      errors.push(`${ROUTER_PATH}: отсутствует маршрут к ${target}`);
    }
  }

  return errors;
}

function validateRepository() {
  const errors = [];

  if (!existsSync(resolve(ROOT, ROUTER_PATH))) {
    errors.push(`не найден маршрутизатор ${ROUTER_PATH}`);
  }

  if (!existsSync(resolve(ROOT, REGISTRY_PATH))) {
    errors.push(`не найден реестр ${REGISTRY_PATH}`);
    return errors;
  }

  const registry = readJson(REGISTRY_PATH);
  errors.push(...validateRegistry(registry));
  errors.push(...validateMarkdownLinks(collectMarkdownFiles("docs/workflow")));
  errors.push(...validateProgressiveLoading(registry));

  return errors;
}

function selfTest() {
  const validRegistry = {
    schemaVersion: 1,
    workflow: "pr-correction",
    entryState: "C01",
    transitionCount: 2,
    states: {
      C01: {
        name: "Вход",
        actor: "Агент",
        document: "docs/workflow/test.md",
        transitionCount: 1,
        next: ["X01"],
      },
      X01: {
        name: "Выход",
        actor: "Пользователь",
        document: "docs/workflow/test.md",
        transitionCount: 1,
        next: [],
        exits: ["terminal"],
      },
    },
  };
  const adapters = {
    fileExists: () => true,
    readDocument: () => [
      "## `C01` — Вход",
      "| № | Результат | Следующее состояние | Исполнитель |",
      "|---:|---|---|---|",
      "| 1 | Готово | `X01` | Агент |",
      "## `X01` — Выход",
      "| № | Результат | Следующее состояние | Исполнитель |",
      "|---:|---|---|---|",
      "| 1 | Готово | Завершение | Пользователь |",
    ].join("\n"),
  };

  assert.deepEqual(validateRegistry(validRegistry, adapters), []);

  const invalidRegistry = structuredClone(validRegistry);
  invalidRegistry.states.C01.next = ["C99"];
  assert.match(
    validateRegistry(invalidRegistry, adapters).join("\n"),
    /неизвестное следующее состояние C99/,
  );

  const missingHeadingRegistry = structuredClone(validRegistry);
  assert.match(
    validateRegistry(missingHeadingRegistry, {
      fileExists: () => true,
      readDocument: () => "## Другой заголовок\n",
    }).join("\n"),
    /нет заголовка/,
  );

  console.log("workflow-docs-check self-test passed");
}

function printState(id) {
  const registry = readJson(REGISTRY_PATH);
  const state = registry.states[id];

  if (!state) {
    throw new Error(`Неизвестное состояние ${id}`);
  }

  console.log(`Состояние: ${id} — ${state.name}`);
  console.log(`Исполнитель: ${state.actor}`);
  console.log(`Документ: ${state.document}`);
  console.log(`Следующие состояния: ${state.next.join(", ") || "нет"}`);

  if (state.dynamicNext?.length) {
    console.log(`Динамический возврат: ${state.dynamicNext.join(", ")}`);
  }

  if (state.exits?.length) {
    console.log(`Выходы из цикла: ${state.exits.join(", ")}`);
  }
}

function printMermaid() {
  const registry = readJson(REGISTRY_PATH);

  console.log("flowchart TD");

  for (const [id, state] of Object.entries(registry.states)) {
    const label = `${id} ${state.name}`.replaceAll('"', "'");
    console.log(`    ${id}["${label}"]`);
  }

  for (const [id, state] of Object.entries(registry.states)) {
    for (const target of state.next) {
      console.log(`    ${id} --> ${target}`);
    }

    for (const target of state.dynamicNext || []) {
      const nodeId = `DYNAMIC_${id}_${target}`;
      console.log(`    ${nodeId}["${target}"]`);
      console.log(`    ${id} -.-> ${nodeId}`);
    }

    for (const exit of state.exits || []) {
      const nodeId = `EXIT_${exit}`;
      console.log(`    ${nodeId}["${exit}"]`);
      console.log(`    ${id} --> ${nodeId}`);
    }
  }
}

function failOnErrors(errors) {
  if (errors.length === 0) {
    return;
  }

  for (const error of errors) {
    console.error(`Error: ${error}`);
  }

  process.exitCode = 1;
}

if (process.argv.includes("--self-test")) {
  selfTest();
}

if (process.argv.includes("--check")) {
  const errors = validateRepository();
  failOnErrors(errors);

  if (errors.length === 0) {
    console.log("workflow docs check passed");
  }
}

const stateFlagIndex = process.argv.indexOf("--state");

if (stateFlagIndex !== -1) {
  const errors = validateRepository();
  failOnErrors(errors);

  if (errors.length === 0) {
    const id = process.argv[stateFlagIndex + 1];

    if (!id) {
      throw new Error("После --state нужен ID состояния");
    }

    printState(id);
  }
}

if (process.argv.includes("--mermaid")) {
  const errors = validateRepository();
  failOnErrors(errors);

  if (errors.length === 0) {
    printMermaid();
  }
}
