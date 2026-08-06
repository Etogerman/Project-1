#!/usr/bin/env node
import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import {
  cpSync,
  existsSync,
  lstatSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  readdirSync,
  rmSync,
  unlinkSync,
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { dirname, extname, relative, resolve, sep } from "node:path";
import { fileURLToPath } from "node:url";
import {
  REQUIRED_GATES,
  REQUIRED_TRANSITION_PROOFS,
  mermaidFor,
  resolveDynamicTarget,
  stateDescription,
  validateTopology,
} from "./workflow-state-policy.mjs";

export { resolveDynamicTarget } from "./workflow-state-policy.mjs";

const DEFAULT_ROOT = process.cwd();
const REGISTRY_PATH = "docs/workflow/pr-correction/states.json";
const ROUTER_PATH = "docs/workflow/README.md";
const CONSTITUTION_PATH = "AGENTS.md";
const LANGUAGE_GIT_OWNER = "docs/workflow/shared/language-and-git-standards.md";
const MAX_CONSTITUTION_LINES = 120;
const MAX_WORKFLOW_DOCUMENT_LINES = 160;
const CANONICAL_REVIEW_PROMPT_SHA256 = "b82d29c1754d92fc0b811bea5b35c345cd4df1f2d814075f8ed2fb6847d20382";
const STATE_ID_PATTERN = /^[BCDGXP]\d{2}$/;
const DYNAMIC_NAME_PATTERN = /^[a-z][a-z0-9_]*$/;
const ALLOWED_DYNAMIC_TARGETS = new Set(["return_state", "resume_state"]);
const REQUIRED_EXTERNAL_EXITS = new Set([
  "main_process",
  "main_process_stage_1",
  "main_process_spec_revision",
  "main_process_materialized_route",
  "main_process_no_result_closure",
]);
const REQUIRED_WORKFLOW_FILES = new Set([
  "docs/workflow/README.md",
  "docs/workflow/pr-correction/README.md",
  "docs/workflow/pr-correction/10-signal-and-qualification.md",
  "docs/workflow/pr-correction/20-correction-spec-and-decisions.md",
  "docs/workflow/pr-correction/25-external-spec-review.md",
  "docs/workflow/pr-correction/27-implementation-entry-gate.md",
  "docs/workflow/pr-correction/30-implementation-and-publication.md",
  "docs/workflow/pr-correction/40-snapshot-review-and-exit.md",
  "docs/workflow/pr-correction/external-spec-review-prompt.md",
  "docs/workflow/shared/evidence-gateway.md",
  "docs/workflow/shared/language-and-git-standards.md",
  "docs/workflow/shared/local-integration-gates.md",
  "docs/workflow/shared/project-engineering-standards.md",
  "docs/workflow/shared/spec-and-delivery-gates.md",
  "docs/workflow/shared/work-authority.md",
]);
const ROOT_KEYS = new Set([
  "schemaVersion",
  "workflow",
  "entryState",
  "transitionCount",
  "externalExits",
  "states",
]);
const STATE_KEYS = new Set([
  "name",
  "actor",
  "document",
  "transitionCount",
  "next",
  "dynamicNext",
  "exits",
  "requiredGate",
  "requiredTransitionProofs",
]);
const EXTERNAL_EXIT_KEYS = new Set(["document", "heading", "purpose", "resumeState"]);
const GATE_KEYS = new Set(["kind", "issuer", "artifact"]);
const TRANSITION_PROOF_KEYS = new Set(["kind", "issuer", "artifact"]);
const COMMON_CONTROLLED_FILES = [
  "AGENTS.md",
  "docs/task-delivery-workflow.md",
  "docs/action-ownership.md",
  ".github/copilot-instructions.md",
];

function issue(code, path, message) {
  return { code, path, message };
}

function formatIssue(value) {
  return `${value.path} [${value.code}]: ${value.message}`;
}

function isPlainObject(value) {
  return value !== null && typeof value === "object" && !Array.isArray(value);
}

function canonicalJson(value) {
  if (Array.isArray(value)) return `[${value.map(canonicalJson).join(",")}]`;
  if (isPlainObject(value)) {
    return `{${Object.keys(value).sort().map((key) => `${JSON.stringify(key)}:${canonicalJson(value[key])}`).join(",")}}`;
  }
  return JSON.stringify(value);
}

function countLines(body) {
  if (body.length === 0) return 0;
  return body.split(/\r?\n/).length - (body.endsWith("\n") ? 1 : 0);
}

function collectFiles(root, directory, predicate = () => true) {
  const absolute = resolve(root, directory);
  if (!existsSync(absolute) || !lstatSync(absolute).isDirectory()) return [];
  const result = [];

  for (const entry of readdirSync(absolute, { withFileTypes: true })) {
    const child = resolve(absolute, entry.name);
    const path = relative(root, child);
    if (entry.isDirectory()) result.push(...collectFiles(root, path, predicate));
    else if (entry.isFile() && predicate(path)) result.push(path);
  }

  return result.sort();
}

function collectMarkdownFiles(root, directory = "docs/workflow") {
  return collectFiles(root, directory, (path) => extname(path) === ".md");
}

function readUtf8(root, path) {
  return readFileSync(resolve(root, path), "utf8");
}

function validateExactKeys(value, allowed, path, errors) {
  for (const key of Object.keys(value)) {
    if (!allowed.has(key)) errors.push(issue("SCHEMA_UNKNOWN_FIELD", `${path}.${key}`, "поле не разрешено"));
  }
}

function requireString(value, path, errors, { nonempty = true } = {}) {
  if (typeof value !== "string") {
    errors.push(issue("SCHEMA_TYPE", path, "ожидалась строка"));
    return false;
  }
  if (nonempty && value.trim() === "") {
    errors.push(issue("SCHEMA_VALUE", path, "строка не должна быть пустой"));
    return false;
  }
  return true;
}

function requirePositiveInteger(value, path, errors) {
  if (!Number.isInteger(value) || value < 1) {
    errors.push(issue("SCHEMA_TYPE", path, "ожидалось положительное целое число"));
    return false;
  }
  return true;
}

function requireStringArray(value, path, errors) {
  if (!Array.isArray(value)) {
    errors.push(issue("SCHEMA_TYPE", path, "ожидался массив строк"));
    return false;
  }
  const seen = new Set();
  value.forEach((item, index) => {
    if (typeof item !== "string" || item.trim() === "") {
      errors.push(issue("SCHEMA_TYPE", `${path}[${index}]`, "ожидалась непустая строка"));
    } else if (seen.has(item)) {
      errors.push(issue("SCHEMA_DUPLICATE", `${path}[${index}]`, `значение ${item} уже объявлено`));
    } else {
      seen.add(item);
    }
  });
  return true;
}

function isSafeRelativeDocumentPath(path, prefix = null) {
  if (typeof path !== "string" || path === "" || path.startsWith("/") || path.includes("\\") || path.includes("\0")) return false;
  const parts = path.split("/");
  if (parts.some((part) => part === "" || part === "." || part === "..")) return false;
  return path.endsWith(".md") && (prefix === null || path.startsWith(prefix));
}

export function validateSchemaShape(registry) {
  const errors = [];
  if (!isPlainObject(registry)) {
    return [issue("SCHEMA_TYPE", "$", "корень должен быть объектом")];
  }

  validateExactKeys(registry, ROOT_KEYS, "$", errors);
  if (registry.schemaVersion !== 2) errors.push(issue("SCHEMA_VALUE", "$.schemaVersion", "ожидалось значение 2"));
  if (registry.workflow !== "pr-correction") errors.push(issue("SCHEMA_VALUE", "$.workflow", "ожидалось pr-correction"));
  requireString(registry.entryState, "$.entryState", errors);
  requirePositiveInteger(registry.transitionCount, "$.transitionCount", errors);

  if (!isPlainObject(registry.externalExits)) {
    errors.push(issue("SCHEMA_TYPE", "$.externalExits", "ожидался объект"));
  } else {
    for (const [name, definition] of Object.entries(registry.externalExits)) {
      const path = `$.externalExits.${name}`;
      if (!isPlainObject(definition)) {
        errors.push(issue("SCHEMA_TYPE", path, "ожидался объект"));
        continue;
      }
      validateExactKeys(definition, EXTERNAL_EXIT_KEYS, path, errors);
      requireString(definition.document, `${path}.document`, errors);
      requireString(definition.heading, `${path}.heading`, errors);
      requireString(definition.purpose, `${path}.purpose`, errors);
      if (Object.hasOwn(definition, "resumeState")) requireString(definition.resumeState, `${path}.resumeState`, errors);
    }
  }

  if (!isPlainObject(registry.states)) {
    errors.push(issue("SCHEMA_TYPE", "$.states", "ожидался объект"));
  } else {
    for (const [id, state] of Object.entries(registry.states)) {
      const path = `$.states.${id}`;
      if (!isPlainObject(state)) {
        errors.push(issue("SCHEMA_TYPE", path, "ожидался объект"));
        continue;
      }
      validateExactKeys(state, STATE_KEYS, path, errors);
      requireString(state.name, `${path}.name`, errors);
      requireString(state.actor, `${path}.actor`, errors);
      requireString(state.document, `${path}.document`, errors);
      requirePositiveInteger(state.transitionCount, `${path}.transitionCount`, errors);
      requireStringArray(state.next, `${path}.next`, errors);
      if (Object.hasOwn(state, "dynamicNext")) requireStringArray(state.dynamicNext, `${path}.dynamicNext`, errors);
      if (Object.hasOwn(state, "exits")) requireStringArray(state.exits, `${path}.exits`, errors);
      if (Object.hasOwn(state, "requiredGate")) {
        if (!isPlainObject(state.requiredGate)) {
          errors.push(issue("SCHEMA_TYPE", `${path}.requiredGate`, "ожидался объект"));
        } else {
          validateExactKeys(state.requiredGate, GATE_KEYS, `${path}.requiredGate`, errors);
          requireString(state.requiredGate.kind, `${path}.requiredGate.kind`, errors);
          requireString(state.requiredGate.issuer, `${path}.requiredGate.issuer`, errors);
          requireString(state.requiredGate.artifact, `${path}.requiredGate.artifact`, errors);
        }
      }
      if (Object.hasOwn(state, "requiredTransitionProofs")) {
        if (!isPlainObject(state.requiredTransitionProofs)) {
          errors.push(issue("SCHEMA_TYPE", `${path}.requiredTransitionProofs`, "ожидался объект"));
        } else {
          for (const [target, proof] of Object.entries(state.requiredTransitionProofs)) {
            const proofPath = `${path}.requiredTransitionProofs.${target}`;
            if (!isPlainObject(proof)) {
              errors.push(issue("SCHEMA_TYPE", proofPath, "ожидался объект"));
              continue;
            }
            validateExactKeys(proof, TRANSITION_PROOF_KEYS, proofPath, errors);
            requireString(proof.kind, `${proofPath}.kind`, errors);
            requireString(proof.issuer, `${proofPath}.issuer`, errors);
            requireString(proof.artifact, `${proofPath}.artifact`, errors);
          }
        }
      }
    }
  }

  return errors;
}

function linesOutsideFences(body) {
  const result = [];
  let fence = null;
  for (const [index, line] of body.split(/\r?\n/).entries()) {
    const marker = line.match(/^\s*(`{3,}|~{3,})/);
    if (marker) {
      const kind = marker[1][0];
      if (fence === null) fence = kind;
      else if (fence === kind) fence = null;
      continue;
    }
    if (fence === null) result.push({ index, line });
  }
  return result;
}

function parseTargetCell(cell) {
  const match = cell.trim().match(/^`([BCDGXP]\d{2}|DYNAMIC:[a-z][a-z0-9_]*|EXIT:[a-z][a-z0-9_]*)`$/);
  if (!match) return null;
  const raw = match[1];
  if (raw.startsWith("DYNAMIC:")) return { kind: "dynamic", value: raw.slice(8) };
  if (raw.startsWith("EXIT:")) return { kind: "exit", value: raw.slice(5) };
  return { kind: "static", value: raw };
}

export function extractStateTransitions(document, id, name = null) {
  const headingPrefix = `## \`${id}\` —`;
  const lines = linesOutsideFences(document);
  const headingPositions = lines.flatMap(({ line }, index) => line.startsWith(headingPrefix) ? [index] : []);
  if (headingPositions.length === 0) return { found: false, rows: [], errors: [] };
  const startPosition = headingPositions[0];
  const heading = lines[startPosition].line;
  const expected = name === null ? null : `${headingPrefix} ${name}`;
  const errors = [];
  if (headingPositions.length !== 1) errors.push(issue("STATE_HEADING", id, "заголовок состояния должен встречаться ровно один раз"));
  if (expected !== null && heading !== expected) {
    errors.push(issue("STATE_HEADING", id, `ожидался заголовок ${expected}`));
  }
  const rows = [];
  const transitionRows = new Set();
  for (let position = startPosition + 1; position < lines.length; position += 1) {
    const { index, line } = lines[position];
    if (/^##\s/.test(line)) break;
    if (!/^\|\s*\d+\s*\|/.test(line)) continue;
    const cells = line.split("|");
    const targetCell = cells[3] ?? "";
    const target = parseTargetCell(targetCell);
    if (target === null) {
      errors.push(issue("MARKDOWN_TARGET", `${id}:line:${index + 1}`, "колонка перехода должна содержать ровно один допустимый target"));
    } else {
      const condition = (cells[2] ?? "").trim().replace(/\s+/g, " ");
      const actor = (cells[4] ?? "").trim().replace(/\s+/g, " ");
      const key = `${condition}\u0000${target.kind}:${target.value}\u0000${actor}`;
      if (transitionRows.has(key)) {
        errors.push(issue("MARKDOWN_DUPLICATE_TARGET", `${id}:line:${index + 1}`, `переход с тем же условием и target ${target.value} уже объявлен`));
      }
      transitionRows.add(key);
    }
    rows.push({ line: index + 1, target });
  }
  return { found: true, rows, errors };
}

function extractStatePolicyMarkers(document, id) {
  const rawLines = document.split(/\r?\n/);
  const headingPattern = /^## `([BCDGXP][0-9]{2})` — .+$/;
  const headingIndexes = rawLines.flatMap((line, index) => headingPattern.test(line) && line.startsWith(`## \`${id}\` — `) ? [index] : []);
  if (headingIndexes.length !== 1) return { gateMarkers: [], proofMarkers: [], errors: [] };
  const start = headingIndexes[0];
  let end = rawLines.length;
  for (let index = start + 1; index < rawLines.length; index += 1) {
    if (headingPattern.test(rawLines[index])) {
      end = index;
      break;
    }
  }
  const section = rawLines.slice(start, end);
  const actorIndexes = section.flatMap((line, index) => line.startsWith("Исполнитель:") ? [index] : []);
  const gateMarkers = section.flatMap((line, index) => line.startsWith("Обязательный шлюз:") ? [{ line, index }] : []);
  const proofMarkers = section.flatMap((line, index) => line.startsWith("Обязательное доказательство перехода:") ? [{ line, index }] : []);
  const errors = [];
  if ((gateMarkers.length > 0 || proofMarkers.length > 0) && actorIndexes.length !== 1) {
    errors.push(issue("STATE_POLICY_POSITION", id, "для машинного маркера нужен ровно один Исполнитель"));
  }
  return { gateMarkers, proofMarkers, actorIndex: actorIndexes[0], errors };
}

function sameExactObject(actual, expected) {
  return canonicalJson(actual) === canonicalJson(expected);
}

function validateStatePolicies(registry, fileExists, readDocument, errors) {
  for (const [id, state] of Object.entries(registry.states)) {
    const expectedGate = REQUIRED_GATES[id] ?? null;
    if (expectedGate === null) {
      if (Object.hasOwn(state, "requiredGate")) errors.push(issue("GATE_ALLOWLIST", `$.states.${id}.requiredGate`, "requiredGate разрешён только защищённым состояниям"));
    } else if (!sameExactObject(state.requiredGate, expectedGate)) {
      errors.push(issue("GATE_ALLOWLIST", `$.states.${id}.requiredGate`, "обязательный шлюз отсутствует или изменён"));
    }

    const expectedProofs = REQUIRED_TRANSITION_PROOFS[id] ?? null;
    if (expectedProofs === null) {
      if (Object.hasOwn(state, "requiredTransitionProofs")) errors.push(issue("TRANSITION_PROOF_ALLOWLIST", `$.states.${id}.requiredTransitionProofs`, "requiredTransitionProofs разрешён только G01"));
    } else if (!sameExactObject(state.requiredTransitionProofs, expectedProofs)) {
      errors.push(issue("TRANSITION_PROOF_ALLOWLIST", `$.states.${id}.requiredTransitionProofs`, "обязательное доказательство перехода отсутствует или изменено"));
    }

    if (!isSafeRelativeDocumentPath(state.document, "docs/workflow/") || !fileExists(state.document)) continue;
    const markers = extractStatePolicyMarkers(readDocument(state.document), id);
    errors.push(...markers.errors.map((value) => ({ ...value, path: `${state.document}:${value.path}` })));
    const expectedGateLine = expectedGate === null ? null : `Обязательный шлюз: \`${expectedGate.kind} / ${expectedGate.issuer} / ${expectedGate.artifact}\`.`;
    if (expectedGateLine === null) {
      if (markers.gateMarkers.length > 0) errors.push(issue("GATE_MARKER", `${state.document}#${id}`, "маркер шлюза запрещён у незащищённого состояния"));
    } else if (markers.gateMarkers.length !== 1 || markers.gateMarkers[0].line !== expectedGateLine || markers.gateMarkers[0].index !== markers.actorIndex + 1) {
      errors.push(issue("GATE_MARKER", `${state.document}#${id}`, `ожидалась следующая строка после Исполнитель: ${expectedGateLine}`));
    }

    const expectedProof = expectedProofs?.C13 ?? null;
    const expectedProofLine = expectedProof === null ? null : `Обязательное доказательство перехода: \`G01 -> C13 / ${expectedProof.kind} / ${expectedProof.issuer} / ${expectedProof.artifact}\`.`;
    if (expectedProofLine === null) {
      if (markers.proofMarkers.length > 0) errors.push(issue("TRANSITION_PROOF_MARKER", `${state.document}#${id}`, "маркер доказательства перехода запрещён у этого состояния"));
    } else {
      if (!state.next.includes("C13")) errors.push(issue("TRANSITION_PROOF_TARGET", `$.states.${id}.next`, "защищённый переход G01 -> C13 отсутствует"));
      if (markers.proofMarkers.length !== 1 || markers.proofMarkers[0].line !== expectedProofLine || markers.proofMarkers[0].index !== markers.actorIndex + 2) {
        errors.push(issue("TRANSITION_PROOF_MARKER", `${state.document}#${id}`, `ожидалась строка сразу после обязательного шлюза: ${expectedProofLine}`));
      }
    }
  }
}

function setDifference(left, right) {
  return [...left].filter((value) => !right.has(value));
}

function equalSet(left, right) {
  return left.size === right.size && setDifference(left, right).length === 0;
}

function canonicalHeading(line) {
  const match = line.match(/^(#{1,6})[\t ]+(.+?)\s*$/);
  if (!match) return null;
  const text = match[2].replace(/[\t ]+#+[\t ]*$/, "").trim().replace(/[\t ]+/g, " ");
  return `${match[1]} ${text}`;
}

function documentHasHeading(body, expected) {
  const canonicalExpected = canonicalHeading(expected);
  if (canonicalExpected === null) return false;
  return linesOutsideFences(body).some(({ line }) => canonicalHeading(line) === canonicalExpected);
}

function validateSet(label, expectedValues, actualValues, path, errors) {
  const expected = new Set(expectedValues);
  const actual = new Set(actualValues);
  if (equalSet(expected, actual)) return;
  const missing = setDifference(expected, actual);
  const extra = setDifference(actual, expected);
  errors.push(issue("TRANSITION_SET", path, `${label}: отсутствуют [${missing.join(", ")}], лишние [${extra.join(", ")}]`));
}

function validateExactStateTargets(registry, id, expected, errors) {
  const state = registry.states[id];
  if (!state) {
    errors.push(issue("SEMANTIC_STATE", `$.states.${id}`, "обязательное состояние отсутствует"));
    return;
  }
  validateSet("статические переходы", expected.next ?? [], state.next, `$.states.${id}.next`, errors);
  validateSet("динамические переходы", expected.dynamicNext ?? [], state.dynamicNext ?? [], `$.states.${id}.dynamicNext`, errors);
  validateSet("внешние выходы", expected.exits ?? [], state.exits ?? [], `$.states.${id}.exits`, errors);
}

export function validateRegistry(registry, adapters = {}) {
  const errors = validateSchemaShape(registry);
  if (errors.length > 0) return errors;
  const fileExists = adapters.fileExists ?? ((path) => existsSync(resolve(DEFAULT_ROOT, path)));
  const readDocument = adapters.readDocument ?? ((path) => readFileSync(resolve(DEFAULT_ROOT, path), "utf8"));
  const states = registry.states;

  if (!Object.hasOwn(states, registry.entryState)) errors.push(issue("ENTRY_STATE", "$.entryState", `состояние ${registry.entryState} отсутствует`));
  const sum = Object.values(states).reduce((total, state) => total + state.transitionCount, 0);
  if (sum !== registry.transitionCount) errors.push(issue("TRANSITION_COUNT", "$.transitionCount", `объявлено ${registry.transitionCount}, сумма состояний ${sum}`));

  const rootExitNames = new Set(Object.keys(registry.externalExits));
  if (!equalSet(rootExitNames, REQUIRED_EXTERNAL_EXITS)) {
    errors.push(issue("EXTERNAL_EXIT_SET", "$.externalExits", `обязателен точный набор: ${[...REQUIRED_EXTERNAL_EXITS].join(", ")}`));
  }
  const usedExits = new Set();

  for (const [name, definition] of Object.entries(registry.externalExits)) {
    const path = `$.externalExits.${name}`;
    if (!isSafeRelativeDocumentPath(definition.document)) {
      errors.push(issue("EXTERNAL_EXIT_DOCUMENT", `${path}.document`, "document должен быть безопасным относительным Markdown-путём"));
    } else if (!fileExists(definition.document)) {
      errors.push(issue("EXTERNAL_EXIT_DOCUMENT", `${path}.document`, `файл ${definition.document} не найден`));
    } else if (!documentHasHeading(readDocument(definition.document), definition.heading)) {
      errors.push(issue("EXTERNAL_EXIT_HEADING", `${path}.heading`, `физический ATX-heading ${definition.heading} не найден`));
    }
    if (name === "main_process_spec_revision" && definition.resumeState !== "G00") {
      errors.push(issue("SPEC_RESUME_STATE", `${path}.resumeState`, "разрешено только G00"));
    }
    if (name !== "main_process_spec_revision" && Object.hasOwn(definition, "resumeState")) {
      errors.push(issue("EXTERNAL_EXIT_RESUME", `${path}.resumeState`, "resumeState разрешён только для main_process_spec_revision"));
    }
  }

  for (const [id, state] of Object.entries(states)) {
    const statePath = `$.states.${id}`;
    if (!STATE_ID_PATTERN.test(id)) errors.push(issue("STATE_ID", statePath, "недопустимый ID состояния"));
    if (!isSafeRelativeDocumentPath(state.document, "docs/workflow/") || !fileExists(state.document)) {
      errors.push(issue("STATE_DOCUMENT", `${statePath}.document`, `документ ${state.document} не найден в docs/workflow`));
    } else {
      const parsed = extractStateTransitions(readDocument(state.document), id, state.name);
      errors.push(...parsed.errors.map((value) => ({ ...value, path: `${state.document}:${value.path}` })));
      if (!parsed.found) {
        errors.push(issue("STATE_HEADING", state.document, `заголовок состояния ${id} не найден`));
      } else {
        if (parsed.rows.length !== state.transitionCount) {
          errors.push(issue("TRANSITION_COUNT", `${statePath}.transitionCount`, `в Markdown ${parsed.rows.length}, в JSON ${state.transitionCount}`));
        }
        const validRows = parsed.rows.filter((row) => row.target !== null);
        validateSet("статические переходы", state.next, validRows.filter((row) => row.target.kind === "static").map((row) => row.target.value), `${state.document}#${id}`, errors);
        validateSet("динамические переходы", state.dynamicNext ?? [], validRows.filter((row) => row.target.kind === "dynamic").map((row) => row.target.value), `${state.document}#${id}`, errors);
        validateSet("внешние выходы", state.exits ?? [], validRows.filter((row) => row.target.kind === "exit").map((row) => row.target.value), `${state.document}#${id}`, errors);
      }
    }

    for (const target of state.next) {
      if (!STATE_ID_PATTERN.test(target) || !Object.hasOwn(states, target)) errors.push(issue("UNKNOWN_STATE_TARGET", `${statePath}.next`, `неизвестное состояние ${target}`));
    }
    for (const target of state.dynamicNext ?? []) {
      if (!DYNAMIC_NAME_PATTERN.test(target) || !ALLOWED_DYNAMIC_TARGETS.has(target)) errors.push(issue("UNKNOWN_DYNAMIC_TARGET", `${statePath}.dynamicNext`, `неизвестный регистр ${target}`));
    }
    for (const target of state.exits ?? []) {
      usedExits.add(target);
      if (!rootExitNames.has(target)) errors.push(issue("UNKNOWN_EXTERNAL_EXIT", `${statePath}.exits`, `выход ${target} не объявлен в корне`));
    }
    if (state.next.length === 0 && (state.dynamicNext ?? []).length === 0 && (state.exits ?? []).length === 0) {
      errors.push(issue("DEAD_END", statePath, "состояние не имеет перехода или выхода"));
    }
  }

  for (const name of rootExitNames) {
    if (!usedExits.has(name)) errors.push(issue("UNUSED_EXTERNAL_EXIT", `$.externalExits.${name}`, "выход не используется состояниями"));
  }

  if (Object.hasOwn(states, registry.entryState)) {
    const reachable = new Set([registry.entryState]);
    const queue = [registry.entryState];
    while (queue.length > 0) {
      const current = queue.shift();
      for (const target of states[current].next) {
        if (Object.hasOwn(states, target) && !reachable.has(target)) {
          reachable.add(target);
          queue.push(target);
        }
      }
    }
    for (const id of Object.keys(states)) {
      if (!reachable.has(id)) errors.push(issue("UNREACHABLE_STATE", `$.states.${id}`, `состояние недостижимо из ${registry.entryState}`));
    }
  }

  errors.push(...validateTopology(registry));
  for (const forbidden of ["C09", "G00", "C10"]) {
    if (states.D01?.next.includes(forbidden)) errors.push(issue("D01_BYPASS", "$.states.D01.next", `переход в ${forbidden} запрещён`));
  }
  if ((states.D01?.dynamicNext ?? []).length > 0 || (states.D01?.exits ?? []).length > 0) {
    errors.push(issue("D01_BYPASS", "$.states.D01", "динамический или внешний обход нового ТЗ запрещён"));
  }
  if (states.C09?.next.includes("C10")) errors.push(issue("C09_BYPASS", "$.states.C09.next", "прямой переход в C10 запрещён"));
  if (states.D02?.next.includes("C10")) errors.push(issue("D02_BYPASS", "$.states.D02.next", "прямой переход в C10 запрещён"));
  if (states.X03?.exits.includes("terminal")) errors.push(issue("X03_TERMINAL", "$.states.X03.exits", "terminal-выход запрещён"));

  validateStatePolicies(registry, fileExists, readDocument, errors);

  return errors;
}

function extractMarkdownLinks(body) {
  const links = [];
  for (const { line } of linesOutsideFences(body)) {
    for (const match of line.matchAll(/\[[^\]]*\]\(([^)]+)\)/g)) {
      let raw = match[1].trim();
      if (raw.startsWith("<") && raw.includes(">")) raw = raw.slice(1, raw.indexOf(">"));
      else raw = raw.split(/[\t ]+(?=["'])/)[0];
      links.push(raw);
    }
  }
  return links;
}

function resolveLocalLink(root, source, raw) {
  if (/^(?:https?:|mailto:)/i.test(raw) || raw.startsWith("#")) return null;
  let pathPart;
  try {
    pathPart = decodeURIComponent(raw.split("#")[0]);
  } catch {
    return { outside: true, path: raw };
  }
  if (!pathPart) return null;
  const absolute = resolve(root, dirname(source), pathPart);
  const normalizedRoot = resolve(root) + sep;
  if (absolute !== resolve(root) && !absolute.startsWith(normalizedRoot)) return { outside: true, path: absolute };
  return { outside: false, path: relative(root, absolute) };
}

function controlledTextFiles(root, workflowFiles) {
  const skills = collectFiles(root, ".agents/skills", (path) => path.endsWith("/SKILL.md"));
  return [...new Set([...COMMON_CONTROLLED_FILES, ...skills, ...workflowFiles])]
    .filter((path) => existsSync(resolve(root, path)) && lstatSync(resolve(root, path)).isFile())
    .sort();
}

function withoutFencesAndLinkDestinations(body) {
  return linesOutsideFences(body)
    .map(({ line }) => line.replace(/(\[[^\]]*\])\([^)]+\)/g, "$1"))
    .join("\n");
}

function validateLanguageOwnership(root, files) {
  const errors = [];
  for (const path of files) {
    if (path === LANGUAGE_GIT_OWNER) continue;
    const body = withoutFencesAndLinkDestinations(readUtf8(root, path));
    if (/`codex\/`/.test(body)) errors.push(issue("LANGUAGE_GIT_OWNER", path, "защищённая сигнатура префикса ветки находится вне файла-владельца"));
    if (/ASCII[- ]транслитерац/iu.test(body)) errors.push(issue("LANGUAGE_GIT_OWNER", path, "защищённая сигнатура транслитерации находится вне файла-владельца"));
    for (const [index, line] of body.split(/\r?\n/).entries()) {
      if (/кириллиц/iu.test(line) && /ветк/iu.test(line)) errors.push(issue("LANGUAGE_GIT_OWNER", `${path}:${index + 1}`, "защищённое правило кириллицы в ветке находится вне файла-владельца"));
    }
  }
  return errors;
}

function parseRouteChains(body) {
  const chains = [];
  const pattern = /\b[BCDGXP]\d{2}(?:\s*(?:→|->)\s*[BCDGXP]\d{2}){2,}/g;
  for (const match of body.matchAll(pattern)) chains.push(match[0].match(/[BCDGXP]\d{2}/g));
  return chains;
}

function validateRouteCopies(root, files, registry) {
  const errors = [];
  for (const path of files) {
    const body = readUtf8(root, path).replace(/(\[[^\]]*\])\([^)]+\)/g, "$1");
    for (const chain of parseRouteChains(body)) {
      let owned = true;
      for (let index = 0; index < chain.length - 1; index += 1) {
        const source = registry.states[chain[index]];
        if (!source || source.document !== path || !source.next.includes(chain[index + 1])) owned = false;
      }
      if (!owned) errors.push(issue("ROUTE_COPY", path, `направленный маршрут ${chain.join(" → ")} скопирован вне канонического модуля`));
    }
  }
  return errors;
}

export function validateRepositoryAt(root = DEFAULT_ROOT) {
  const errors = [];
  const registryAbsolute = resolve(root, REGISTRY_PATH);
  if (!existsSync(registryAbsolute)) return [issue("REGISTRY_MISSING", REGISTRY_PATH, "реестр не найден")];
  let registry;
  try {
    registry = JSON.parse(readFileSync(registryAbsolute, "utf8"));
  } catch {
    return [issue("REGISTRY_JSON", REGISTRY_PATH, "невалидный JSON")];
  }

  errors.push(...validateRegistry(registry, {
    fileExists: (path) => existsSync(resolve(root, path)),
    readDocument: (path) => readUtf8(root, path),
  }));

  const workflowFiles = collectMarkdownFiles(root);
  const allLinkSources = [CONSTITUTION_PATH, ...workflowFiles].filter((path) => existsSync(resolve(root, path)));
  const edges = new Map(allLinkSources.map((path) => [path, []]));
  for (const source of allLinkSources) {
    for (const raw of extractMarkdownLinks(readUtf8(root, source))) {
      const target = resolveLocalLink(root, source, raw);
      if (target === null) continue;
      if (target.outside || !existsSync(resolve(root, target.path))) {
        errors.push(issue("BROKEN_LINK", source, `локальная ссылка ${raw} не найдена`));
      } else if (target.path.endsWith(".md")) {
        edges.get(source).push(target.path);
      }
    }
  }

  const constitutionWorkflowTargets = new Set();
  for (const raw of extractMarkdownLinks(readUtf8(root, CONSTITUTION_PATH))) {
    const target = resolveLocalLink(root, CONSTITUTION_PATH, raw);
    if (target !== null && !target.outside && target.path.startsWith("docs/workflow/") && target.path.endsWith(".md")) {
      constitutionWorkflowTargets.add(target.path);
    }
  }
  if (!equalSet(constitutionWorkflowTargets, new Set([ROUTER_PATH]))) {
    errors.push(issue("WORKFLOW_ROOT", CONSTITUTION_PATH, `единственная точка входа в docs/workflow должна вести в ${ROUTER_PATH}`));
  }
  const routerTargets = new Set();
  for (const raw of extractMarkdownLinks(readUtf8(root, ROUTER_PATH))) {
    const target = resolveLocalLink(root, ROUTER_PATH, raw);
    if (target !== null && !target.outside) routerTargets.add(target.path);
  }
  if (!routerTargets.has(LANGUAGE_GIT_OWNER)) {
    errors.push(issue("ROUTER_PROFILE", ROUTER_PATH, `обязательный профиль должен прямо ссылаться на ${LANGUAGE_GIT_OWNER}`));
  }

  const reachableDocuments = new Set([ROUTER_PATH]);
  const documentQueue = [ROUTER_PATH];
  while (documentQueue.length > 0) {
    const current = documentQueue.shift();
    for (const target of edges.get(current) ?? []) {
      if (!reachableDocuments.has(target)) {
        reachableDocuments.add(target);
        documentQueue.push(target);
      }
    }
  }
  for (const path of workflowFiles) {
    if (!reachableDocuments.has(path)) errors.push(issue("ORPHAN_DOCUMENT", path, `документ недостижим из ${CONSTITUTION_PATH}`));
  }

  for (const required of REQUIRED_WORKFLOW_FILES) {
    if (!existsSync(resolve(root, required))) errors.push(issue("REQUIRED_MODULE", required, "обязательный модуль отсутствует"));
  }
  const promptPath = "docs/workflow/pr-correction/external-spec-review-prompt.md";
  if (existsSync(resolve(root, promptPath))) {
    const promptHash = createHash("sha256").update(readFileSync(resolve(root, promptPath))).digest("hex");
    if (promptHash !== CANONICAL_REVIEW_PROMPT_SHA256) errors.push(issue("PROMPT_CONTENT", promptPath, "канонический prompt изменён без отдельного согласования"));
  }
  const obsolete = "docs/workflow/pr-correction/20-delta-spec-and-decisions.md";
  if (existsSync(resolve(root, obsolete))) errors.push(issue("OBSOLETE_MODULE", obsolete, "старый модуль должен быть удалён"));

  const constitution = readUtf8(root, CONSTITUTION_PATH);
  if (countLines(constitution) > MAX_CONSTITUTION_LINES) errors.push(issue("LINE_LIMIT", CONSTITUTION_PATH, `максимум ${MAX_CONSTITUTION_LINES} строк`));
  for (const path of workflowFiles) {
    if (countLines(readUtf8(root, path)) > MAX_WORKFLOW_DOCUMENT_LINES) errors.push(issue("LINE_LIMIT", path, `максимум ${MAX_WORKFLOW_DOCUMENT_LINES} строк`));
  }

  const controlled = controlledTextFiles(root, workflowFiles);
  for (const path of controlled) {
    if (/delta-ТЗ/iu.test(readUtf8(root, path))) errors.push(issue("OBSOLETE_TERM", path, "термин delta-ТЗ запрещён"));
  }
  errors.push(...validateLanguageOwnership(root, controlled));
  errors.push(...validateRouteCopies(root, controlled, registry));

  return errors;
}

function exactMarkerRange(buffer) {
  const startToken = Buffer.from("<!-- IMPLEMENTATION-CONTRACT START -->");
  const endToken = Buffer.from("<!-- IMPLEMENTATION-CONTRACT END -->");
  const starts = [];
  const ends = [];
  for (let index = buffer.indexOf(startToken); index !== -1; index = buffer.indexOf(startToken, index + 1)) starts.push(index);
  for (let index = buffer.indexOf(endToken); index !== -1; index = buffer.indexOf(endToken, index + 1)) ends.push(index);
  if (starts.length !== 1 || ends.length !== 1) return { ok: false, reason: "канонические маркеры должны встречаться ровно по одному разу" };
  const startLineIsExact = (starts[0] === 0 || buffer[starts[0] - 1] === 0x0a)
    && buffer[starts[0] + startToken.length] === 0x0a;
  const endAfter = ends[0] + endToken.length;
  const endLineIsExact = ends[0] > 0 && buffer[ends[0] - 1] === 0x0a
    && (endAfter === buffer.length || buffer[endAfter] === 0x0a);
  if (!startLineIsExact || !endLineIsExact) return { ok: false, reason: "канонические маркеры должны занимать отдельные LF-строки" };
  const start = starts[0] + startToken.length + 1;
  const end = ends[0] - 1;
  if (end < start) return { ok: false, reason: "маркеры расположены в неверном порядке" };
  return { ok: true, bytes: buffer.subarray(start, end) };
}

export function evaluateImplementationGate(input, adapters = {}) {
  if (input?.scopeOrRiskChanged) return { target: "D01", reason: "scope_or_risk_changed" };
  if (!input?.evidenceComplete) return { target: "B01", reason: "evidence_missing", returnState: "G00" };
  if (!input?.permissionGranted) return { target: "D02", reason: "permission_required", resumeState: "G00" };
  if (!input?.requiresSpec) return { target: "C10", reason: "ordinary_fix" };
  if (!input.specRepo || !input.specDoc || !input.specRevision) {
    if (!input?.specWriteAllowed) return { target: "D02", reason: "spec_permission_required", resumeState: "G00" };
    return { target: "EXIT:main_process_spec_revision", reason: "spec_missing" };
  }
  const readSpec = adapters.readSpec;
  const changed = adapters.specRevisionChangesDocument;
  if (typeof readSpec !== "function" || typeof changed !== "function") return { target: "B01", reason: "spec_access_missing", returnState: "G00" };
  let revisionChangesDocument;
  let specBytes;
  try {
    revisionChangesDocument = changed(input.specRevision, input.specDoc);
    if (revisionChangesDocument) specBytes = readSpec(input.specRevision, input.specDoc);
  } catch {
    return { target: "B01", reason: "spec_access_failed", returnState: "G00" };
  }
  if (!revisionChangesDocument) {
    if (!input?.specWriteAllowed) return { target: "D02", reason: "spec_permission_required", resumeState: "G00" };
    return { target: "EXIT:main_process_spec_revision", reason: "spec_revision_does_not_change_document" };
  }
  const tzRange = exactMarkerRange(Buffer.from(input.tzBytes ?? []));
  const specRange = exactMarkerRange(Buffer.from(specBytes));
  if (!tzRange.ok || !specRange.ok || !tzRange.bytes.equals(specRange.bytes)) {
    if (!input?.specWriteAllowed) return { target: "D02", reason: "spec_permission_required", resumeState: "G00" };
    return { target: "EXIT:main_process_spec_revision", reason: "spec_contract_mismatch" };
  }
  return { target: "C10", reason: "spec_verified" };
}

function hasCode(errors, code) {
  return errors.some((value) => value.code === code);
}

function selfTest() {
  const root = DEFAULT_ROOT;
  const repositoryErrors = validateRepositoryAt(root);
  assert.deepEqual(repositoryErrors, [], repositoryErrors.map(formatIssue).join("\n"));
  const registry = JSON.parse(readUtf8(root, REGISTRY_PATH));
  const adapters = {
    fileExists: (path) => existsSync(resolve(root, path)),
    readDocument: (path) => readUtf8(root, path),
  };

  assert(hasCode(validateSchemaShape(null), "SCHEMA_TYPE"));
  for (const [path, mutate] of [
    ["root", (copy) => { copy.extra = true; }],
    ["state", (copy) => { copy.states.C01.extra = true; }],
    ["exit", (copy) => { copy.externalExits.main_process.extra = true; }],
  ]) {
    const copy = structuredClone(registry);
    mutate(copy);
    const shapeErrors = validateSchemaShape(copy);
    assert(hasCode(shapeErrors, "SCHEMA_UNKNOWN_FIELD"), path);
    const expectedPath = { root: "$.extra", state: "$.states.C01.extra", exit: "$.externalExits.main_process.extra" }[path];
    assert(shapeErrors.some((value) => value.code === "SCHEMA_UNKNOWN_FIELD" && value.path === expectedPath), path);
  }
  for (const mutate of [
    (copy) => { copy.states = []; },
    (copy) => { copy.externalExits = []; },
    (copy) => { copy.states.C01 = "bad"; },
    (copy) => { copy.states.C01.next = 123; },
    (copy) => { copy.states.B01.dynamicNext = 123; },
    (copy) => { copy.states.X01.exits = 123; },
  ]) {
    const copy = structuredClone(registry);
    mutate(copy);
    assert(hasCode(validateSchemaShape(copy), "SCHEMA_TYPE"));
  }

  const c08Bypass = structuredClone(registry);
  c08Bypass.states.C08.next.push("C09");
  assert(hasCode(validateRegistry(c08Bypass, adapters), "TRANSITION_SET"));
  const missingStatic = structuredClone(registry);
  missingStatic.states.C08.next = missingStatic.states.C08.next.filter((target) => target !== "C07");
  assert(hasCode(validateRegistry(missingStatic, adapters), "TRANSITION_SET"));
  const missingDynamic = structuredClone(registry);
  missingDynamic.states.B01.dynamicNext = [];
  assert(hasCode(validateRegistry(missingDynamic, adapters), "TRANSITION_SET"));
  const extraDynamic = structuredClone(registry);
  extraDynamic.states.C01.dynamicNext = ["return_state"];
  assert(hasCode(validateRegistry(extraDynamic, adapters), "TRANSITION_SET"));
  const missingExternal = structuredClone(registry);
  missingExternal.states.G00.exits = [];
  assert(hasCode(validateRegistry(missingExternal, adapters), "TRANSITION_SET"));
  const extraExternal = structuredClone(registry);
  extraExternal.states.G00.exits.push("main_process");
  assert(hasCode(validateRegistry(extraExternal, adapters), "TRANSITION_SET"));
  const d01Bypass = structuredClone(registry);
  d01Bypass.states.D01.next.push("C09");
  assert(hasCode(validateRegistry(d01Bypass, adapters), "D01_BYPASS"));
  const d01DynamicBypass = structuredClone(registry);
  d01DynamicBypass.states.D01.dynamicNext = ["resume_state"];
  assert(hasCode(validateRegistry(d01DynamicBypass, adapters), "D01_BYPASS"));
  const c09Bypass = structuredClone(registry);
  c09Bypass.states.C09.next.push("C10");
  assert(hasCode(validateRegistry(c09Bypass, adapters), "C09_BYPASS"));
  const d02Bypass = structuredClone(registry);
  d02Bypass.states.D02.next.push("C10");
  assert(hasCode(validateRegistry(d02Bypass, adapters), "D02_BYPASS"));
  const x03Terminal = structuredClone(registry);
  x03Terminal.states.X03.exits = ["terminal"];
  assert(hasCode(validateRegistry(x03Terminal, adapters), "X03_TERMINAL"));
  const missingExit = structuredClone(registry);
  delete missingExit.externalExits.main_process;
  assert(hasCode(validateRegistry(missingExit, adapters), "EXTERNAL_EXIT_SET"));
  for (const exitName of REQUIRED_EXTERNAL_EXITS) {
    const missingRequiredExit = structuredClone(registry);
    delete missingRequiredExit.externalExits[exitName];
    assert(hasCode(validateRegistry(missingRequiredExit, adapters), "EXTERNAL_EXIT_SET"), exitName);
  }
  const extraExit = structuredClone(registry);
  extraExit.externalExits.extra = { document: "docs/task-delivery-workflow.md", heading: "## Этап 12. Контрольные точки", purpose: "test" };
  assert(hasCode(validateRegistry(extraExit, adapters), "EXTERNAL_EXIT_SET"));
  const unknownRootReference = structuredClone(registry);
  unknownRootReference.states.X01.exits = ["missing_root_exit"];
  assert(hasCode(validateRegistry(unknownRootReference, adapters), "UNKNOWN_EXTERNAL_EXIT"));
  const unusedRootExit = structuredClone(registry);
  unusedRootExit.externalExits.extra = { document: "docs/task-delivery-workflow.md", heading: "## Этап 12. Контрольные точки", purpose: "unused" };
  assert(hasCode(validateRegistry(unusedRootExit, adapters), "UNUSED_EXTERNAL_EXIT"));
  const wrongResume = structuredClone(registry);
  wrongResume.externalExits.main_process_spec_revision.resumeState = "C10";
  assert(hasCode(validateRegistry(wrongResume, adapters), "SPEC_RESUME_STATE"));
  for (const field of ["document", "heading", "purpose"]) {
    const missingField = structuredClone(registry);
    delete missingField.externalExits.main_process[field];
    assert(hasCode(validateSchemaShape(missingField), "SCHEMA_TYPE"), field);
  }
  const missingExitDocument = structuredClone(registry);
  missingExitDocument.externalExits.main_process.document = "docs/missing.md";
  assert(hasCode(validateRegistry(missingExitDocument, adapters), "EXTERNAL_EXIT_DOCUMENT"));
  const escapedExitDocument = structuredClone(registry);
  escapedExitDocument.externalExits.main_process.document = "docs/../AGENTS.md";
  assert(hasCode(validateRegistry(escapedExitDocument, adapters), "EXTERNAL_EXIT_DOCUMENT"));
  const escapedStateDocument = structuredClone(registry);
  escapedStateDocument.states.C01.document = "docs/workflow/../../AGENTS.md";
  assert(hasCode(validateRegistry(escapedStateDocument, adapters), "STATE_DOCUMENT"));

  assert.equal(resolveDynamicTarget("B01", "return_state", { return_state: "C02" }, registry.states).state, "C02");
  assert.equal(resolveDynamicTarget("B01", "return_state", {}, registry.states).state, "B01");
  assert.equal(resolveDynamicTarget("B01", "return_state", { return_state: 2 }, registry.states).state, "B01");
  assert.equal(resolveDynamicTarget("B01", "return_state", { return_state: "C99" }, registry.states).state, "B01");
  assert.equal(resolveDynamicTarget("D02", "resume_state", { resume_state: "C10" }, registry.states).state, "D02");

  const contract = Buffer.from("meta\n<!-- IMPLEMENTATION-CONTRACT START -->\nbody\n<!-- IMPLEMENTATION-CONTRACT END -->\ntail\n");
  let specReads = 0;
  assert.equal(evaluateImplementationGate({ permissionGranted: false, evidenceComplete: false, scopeOrRiskChanged: true }).target, "D01");
  assert.equal(evaluateImplementationGate({ permissionGranted: false, evidenceComplete: false, scopeOrRiskChanged: false }).target, "B01");
  assert.equal(evaluateImplementationGate({ permissionGranted: false, evidenceComplete: true, scopeOrRiskChanged: false }).target, "D02");
  assert.equal(evaluateImplementationGate({ permissionGranted: true, evidenceComplete: true, requiresSpec: false }, { readSpec: () => { specReads += 1; } }).target, "C10");
  assert.equal(specReads, 0);
  assert.equal(evaluateImplementationGate({ permissionGranted: true, evidenceComplete: true, requiresSpec: true, specWriteAllowed: false }).target, "D02");
  assert.equal(evaluateImplementationGate({ permissionGranted: true, evidenceComplete: true, requiresSpec: true, specWriteAllowed: true }).target, "EXIT:main_process_spec_revision");
  assert.equal(evaluateImplementationGate({ permissionGranted: true, evidenceComplete: true, requiresSpec: true, specWriteAllowed: true, specRepo: "repo", specDoc: "doc", specRevision: "sha", tzBytes: contract }, { readSpec: () => contract, specRevisionChangesDocument: () => true }).target, "C10");
  assert.equal(evaluateImplementationGate({ permissionGranted: true, evidenceComplete: true, requiresSpec: true, specWriteAllowed: false, specRepo: "repo", specDoc: "doc", specRevision: "sha", tzBytes: contract }, { readSpec: () => contract, specRevisionChangesDocument: () => true }).target, "C10");
  assert.equal(evaluateImplementationGate({ permissionGranted: true, evidenceComplete: true, requiresSpec: true, specWriteAllowed: true, specRepo: "repo", specDoc: "doc", specRevision: "sha", tzBytes: contract }, { readSpec: () => Buffer.from(contract.toString().replace("body", "changed")), specRevisionChangesDocument: () => true }).target, "EXIT:main_process_spec_revision");
  assert.equal(evaluateImplementationGate({ permissionGranted: true, evidenceComplete: true, requiresSpec: true, specWriteAllowed: false, specRepo: "repo", specDoc: "doc", specRevision: "sha", tzBytes: contract }, { readSpec: () => Buffer.from(contract.toString().replace("body", "changed")), specRevisionChangesDocument: () => true }).target, "D02");
  const metadataOnly = Buffer.from("different metadata\n<!-- IMPLEMENTATION-CONTRACT START -->\nbody\n<!-- IMPLEMENTATION-CONTRACT END -->\ndifferent tail\n");
  assert.equal(evaluateImplementationGate({ permissionGranted: true, evidenceComplete: true, requiresSpec: true, specWriteAllowed: true, specRepo: "repo", specDoc: "doc", specRevision: "sha", tzBytes: contract }, { readSpec: () => metadataOnly, specRevisionChangesDocument: () => true }).target, "C10");
  for (const invalidContract of [
    Buffer.from("body without markers\n"),
    Buffer.from("prefix<!-- IMPLEMENTATION-CONTRACT START -->\nbody\n<!-- IMPLEMENTATION-CONTRACT END -->\n"),
    Buffer.from("<!-- IMPLEMENTATION-CONTRACT START -->\nbody\n<!-- IMPLEMENTATION-CONTRACT END -->suffix\n"),
    Buffer.from("<!-- IMPLEMENTATION-CONTRACT START -->\nbody\n<!-- IMPLEMENTATION-CONTRACT START -->\nbody\n<!-- IMPLEMENTATION-CONTRACT END -->\n"),
    Buffer.from("<!-- IMPLEMENTATION-CONTRACT START -->\nbody\n<!-- IMPLEMENTATION-CONTRACT END -->\n<!-- IMPLEMENTATION-CONTRACT END -->\n"),
  ]) {
    assert.equal(evaluateImplementationGate({ permissionGranted: true, evidenceComplete: true, requiresSpec: true, specWriteAllowed: true, specRepo: "repo", specDoc: "doc", specRevision: "sha", tzBytes: contract }, { readSpec: () => invalidContract, specRevisionChangesDocument: () => true }).target, "EXIT:main_process_spec_revision");
  }
  assert.equal(evaluateImplementationGate({ permissionGranted: true, evidenceComplete: true, requiresSpec: true, specWriteAllowed: true, specRepo: "repo", specDoc: "other", specRevision: "sha", tzBytes: contract }, { readSpec: () => contract, specRevisionChangesDocument: (revision, path) => revision === "sha" && path === "doc" }).target, "EXIT:main_process_spec_revision");
  assert.equal(evaluateImplementationGate({ permissionGranted: true, evidenceComplete: true, requiresSpec: true, specWriteAllowed: true, specRepo: "repo", specDoc: "doc", specRevision: "other", tzBytes: contract }, { readSpec: () => contract, specRevisionChangesDocument: (revision, path) => revision === "sha" && path === "doc" }).target, "EXIT:main_process_spec_revision");
  assert.equal(evaluateImplementationGate({ permissionGranted: true, evidenceComplete: true, requiresSpec: true, specWriteAllowed: true, specRepo: "repo", specDoc: "doc", specRevision: "sha", tzBytes: contract }, { readSpec: () => contract, specRevisionChangesDocument: () => false }).target, "EXIT:main_process_spec_revision");
  assert.equal(evaluateImplementationGate({ permissionGranted: true, evidenceComplete: true, requiresSpec: true, specWriteAllowed: false, specRepo: "repo", specDoc: "doc", specRevision: "sha", tzBytes: contract }, { readSpec: () => contract, specRevisionChangesDocument: () => false }).target, "D02");
  assert.equal(evaluateImplementationGate({ permissionGranted: true, evidenceComplete: true, requiresSpec: true, specWriteAllowed: true, specRepo: "repo", specDoc: "doc", specRevision: "sha", tzBytes: contract }, { readSpec: () => { throw new Error("unavailable"); }, specRevisionChangesDocument: () => true }).target, "B01");

  assert.deepEqual(parseRouteChains("C01 → C02"), []);
  assert.deepEqual(parseRouteChains("C01, C02, C03"), []);
  assert.equal(parseRouteChains("C01 → C02 → C03").length, 1);
  assert(!/delta-ТЗ/iu.test(controlledTextFiles(root, collectMarkdownFiles(root)).map((path) => readUtf8(root, path)).join("\n")));
  assert.equal(countLines(Array.from({ length: 120 }, () => "x").join("\n") + "\n"), 120);
  assert.equal(countLines(Array.from({ length: 160 }, () => "x").join("\n") + "\n"), 160);

  const temporary = mkdtempSync(resolve(tmpdir(), "workflow-docs-check-"));
  const pristine = resolve(temporary, "pristine");
  mkdirSync(pristine);
  for (const path of [
    "AGENTS.md",
    "docs",
    ".github/copilot-instructions.md",
    ".agents/skills",
  ]) {
    const source = resolve(root, path);
    const destination = resolve(pristine, path);
    mkdirSync(dirname(destination), { recursive: true });
    cpSync(source, destination, { recursive: true });
  }
  let fixtureNumber = 0;
  const fixture = (mutate, code, { expectPass = false } = {}) => {
    fixtureNumber += 1;
    const destination = resolve(temporary, `fixture-${fixtureNumber}`);
    cpSync(pristine, destination, { recursive: true });
    mutate(destination);
    const fixtureErrors = validateRepositoryAt(destination);
    if (expectPass) assert.deepEqual(fixtureErrors, [], fixtureErrors.map(formatIssue).join("\n"));
    else assert(hasCode(fixtureErrors, code), `${code}\n${fixtureErrors.map(formatIssue).join("\n")}`);
  };
  const append = (fixtureRoot, path, text) => writeFileSync(resolve(fixtureRoot, path), `${readUtf8(fixtureRoot, path)}${text}`);

  fixture((fixtureRoot) => writeFileSync(resolve(fixtureRoot, "docs/workflow/shared/orphan.md"), "# Сирота\n"), "ORPHAN_DOCUMENT");
  fixture((fixtureRoot) => {
    writeFileSync(resolve(fixtureRoot, "docs/workflow/shared/second-root.md"), "# Второй корень\n");
    append(fixtureRoot, "AGENTS.md", "\n[Второй корень](docs/workflow/shared/second-root.md)\n");
  }, "WORKFLOW_ROOT");
  fixture((fixtureRoot) => writeFileSync(resolve(fixtureRoot, "AGENTS.md"), readUtf8(fixtureRoot, "AGENTS.md").replace("(docs/workflow/README.md)", "(docs/workflow/missing.md)")), "BROKEN_LINK");
  fixture((fixtureRoot) => append(fixtureRoot, "docs/workflow/README.md", "\n[Сломанная ссылка](shared/missing.md)\n"), "BROKEN_LINK");
  fixture((fixtureRoot) => append(fixtureRoot, "docs/workflow/README.md", "\n[Некорректная ссылка](shared/%ZZ.md)\n"), "BROKEN_LINK");
  fixture((fixtureRoot) => unlinkSync(resolve(fixtureRoot, LANGUAGE_GIT_OWNER)), "REQUIRED_MODULE");
  fixture((fixtureRoot) => {
    const router = readUtf8(fixtureRoot, ROUTER_PATH).replaceAll("(shared/language-and-git-standards.md)", "(shared/project-engineering-standards.md)");
    writeFileSync(resolve(fixtureRoot, ROUTER_PATH), router);
  }, "ROUTER_PROFILE");
  fixture((fixtureRoot) => unlinkSync(resolve(fixtureRoot, "docs/workflow/pr-correction/25-external-spec-review.md")), "REQUIRED_MODULE");
  fixture((fixtureRoot) => {
    const router = readUtf8(fixtureRoot, ROUTER_PATH).replace("(pr-correction/25-external-spec-review.md)", "(pr-correction/20-correction-spec-and-decisions.md)");
    writeFileSync(resolve(fixtureRoot, ROUTER_PATH), router);
  }, "ORPHAN_DOCUMENT");
  fixture((fixtureRoot) => writeFileSync(resolve(fixtureRoot, "docs/workflow/pr-correction/20-delta-spec-and-decisions.md"), "# Старый файл\n"), "OBSOLETE_MODULE");
  fixture((fixtureRoot) => append(fixtureRoot, "docs/workflow/pr-correction/external-spec-review-prompt.md", "\nЛишняя инструкция.\n"), "PROMPT_CONTENT");
  fixture((fixtureRoot) => append(fixtureRoot, "AGENTS.md", "\nC01 → C02 → C03\n"), "ROUTE_COPY");
  fixture((fixtureRoot) => append(fixtureRoot, "docs/task-delivery-workflow.md", "\nC01 → C02 → C03\n"), "ROUTE_COPY");
  fixture((fixtureRoot) => append(fixtureRoot, "docs/action-ownership.md", "\nC01 → C02 → C03\n"), "ROUTE_COPY");
  fixture((fixtureRoot) => append(fixtureRoot, ".agents/skills/ab-pr-ci-review/SKILL.md", "\nC01 → C02 → C03\n"), "ROUTE_COPY");
  fixture((fixtureRoot) => append(fixtureRoot, ".github/copilot-instructions.md", "\nC01 → C02 → C03\n"), "ROUTE_COPY");
  fixture((fixtureRoot) => append(fixtureRoot, "docs/workflow/README.md", "\nC01 → C02 → C03\n"), "ROUTE_COPY");
  for (const path of [
    "AGENTS.md",
    "docs/task-delivery-workflow.md",
    "docs/action-ownership.md",
    ".agents/skills/ab-pr-ci-review/SKILL.md",
    ".github/copilot-instructions.md",
    "docs/workflow/README.md",
  ]) {
    fixture((fixtureRoot) => append(fixtureRoot, path, "\nОдиночный C01 и ненаправленные C02, C03; допустимая пара C01 → C02.\n"), null, { expectPass: true });
  }
  fixture((fixtureRoot) => append(fixtureRoot, "AGENTS.md", "\ndelta-ТЗ\n"), "OBSOLETE_TERM");
  for (const signature of ["`codex/`", "ASCII-транслитерация", "Кириллица в имени ветки запрещена"]) {
    fixture((fixtureRoot) => append(fixtureRoot, "AGENTS.md", `\n${signature}\n`), "LANGUAGE_GIT_OWNER");
    fixture((fixtureRoot) => append(fixtureRoot, "docs/workflow/shared/project-engineering-standards.md", `\n${signature}\n`), "LANGUAGE_GIT_OWNER");
  }
  fixture((fixtureRoot) => {
    const body = readUtf8(fixtureRoot, "AGENTS.md").trimEnd().split("\n");
    while (body.length < 120) body.push("Допустимая строка.");
    writeFileSync(resolve(fixtureRoot, "AGENTS.md"), `${body.join("\n")}\n`);
  }, null, { expectPass: true });
  fixture((fixtureRoot) => {
    const body = readUtf8(fixtureRoot, "AGENTS.md").trimEnd().split("\n");
    while (body.length < 121) body.push("Лишняя строка.");
    writeFileSync(resolve(fixtureRoot, "AGENTS.md"), `${body.join("\n")}\n`);
  }, "LINE_LIMIT");
  fixture((fixtureRoot) => {
    const body = readUtf8(fixtureRoot, "AGENTS.md").trimEnd().split("\n");
    while (body.length < 120) body.push("Допустимая строка.");
    body.push("<!-- line-limit: allow -->");
    writeFileSync(resolve(fixtureRoot, "AGENTS.md"), `${body.join("\n")}\n`);
  }, "LINE_LIMIT");
  fixture((fixtureRoot) => {
    const path = "docs/workflow/pr-correction/25-external-spec-review.md";
    const body = readUtf8(fixtureRoot, path).trimEnd().split("\n");
    while (body.length < 160) body.push("Допустимая строка.");
    writeFileSync(resolve(fixtureRoot, path), `${body.join("\n")}\n`);
  }, null, { expectPass: true });
  fixture((fixtureRoot) => {
    const path = "docs/workflow/pr-correction/25-external-spec-review.md";
    const body = readUtf8(fixtureRoot, path).trimEnd().split("\n");
    while (body.length < 161) body.push("Лишняя строка.");
    writeFileSync(resolve(fixtureRoot, path), `${body.join("\n")}\n`);
  }, "LINE_LIMIT");

  const malformedMarkdown = adapters.readDocument("docs/workflow/pr-correction/20-correction-spec-and-decisions.md").replace("| 3 | Авторское ревью пройдено | `P03` |", "| 3 | Авторское ревью пройдено | `P03` и `C09` |");
  assert(hasCode(validateRegistry(registry, { ...adapters, readDocument: (path) => path.endsWith("20-correction-spec-and-decisions.md") ? malformedMarkdown : adapters.readDocument(path) }), "MARKDOWN_TARGET"));
  const missingMarkdown = adapters.readDocument("docs/workflow/pr-correction/20-correction-spec-and-decisions.md").replace("| 3 | Авторское ревью пройдено | `P03` |", "| 3 | Авторское ревью пройдено |  |");
  assert(hasCode(validateRegistry(registry, { ...adapters, readDocument: (path) => path.endsWith("20-correction-spec-and-decisions.md") ? missingMarkdown : adapters.readDocument(path) }), "MARKDOWN_TARGET"));
  const unknownMarkdown = adapters.readDocument("docs/workflow/pr-correction/20-correction-spec-and-decisions.md").replace("| 3 | Авторское ревью пройдено | `P03` |", "| 3 | Авторское ревью пройдено | `C99` |");
  assert(hasCode(validateRegistry(registry, { ...adapters, readDocument: (path) => path.endsWith("20-correction-spec-and-decisions.md") ? unknownMarkdown : adapters.readDocument(path) }), "TRANSITION_SET"));
  const duplicateHeadingMarkdown = `${adapters.readDocument("docs/workflow/pr-correction/20-correction-spec-and-decisions.md")}\n## \`C08\` — Авторское ревью ТЗ исправления\n`;
  assert(hasCode(validateRegistry(registry, { ...adapters, readDocument: (path) => path.endsWith("20-correction-spec-and-decisions.md") ? duplicateHeadingMarkdown : adapters.readDocument(path) }), "STATE_HEADING"));
  const duplicateTargetRegistry = structuredClone(registry);
  duplicateTargetRegistry.states.C08.transitionCount += 1;
  duplicateTargetRegistry.transitionCount += 1;
  const duplicateTargetMarkdown = adapters.readDocument("docs/workflow/pr-correction/20-correction-spec-and-decisions.md")
    .replace("| 3 | Авторское ревью пройдено | `P03` | Агент |", "| 3 | Авторское ревью пройдено | `P03` | Агент |\n| 4 | Авторское ревью пройдено | `P03` | Агент |");
  assert(hasCode(validateRegistry(duplicateTargetRegistry, { ...adapters, readDocument: (path) => path.endsWith("20-correction-spec-and-decisions.md") ? duplicateTargetMarkdown : adapters.readDocument(path) }), "MARKDOWN_DUPLICATE_TARGET"));
  const badCount = structuredClone(registry);
  badCount.states.C01.transitionCount = 2;
  assert(hasCode(validateRegistry(badCount, adapters), "TRANSITION_COUNT"));
  const badRootCount = structuredClone(registry);
  badRootCount.transitionCount += 1;
  assert(hasCode(validateRegistry(badRootCount, adapters), "TRANSITION_COUNT"));
  const missingGate = structuredClone(registry);
  delete missingGate.states.C10.requiredGate;
  const c10WithoutMarker = adapters.readDocument("docs/workflow/pr-correction/30-implementation-and-publication.md")
    .replace("Обязательный шлюз: `implementation / G00 / implementation-gate.json`.\n", "");
  assert(hasCode(validateRegistry(missingGate, { ...adapters, readDocument: (path) => path.endsWith("30-implementation-and-publication.md") ? c10WithoutMarker : adapters.readDocument(path) }), "GATE_ALLOWLIST"));
  const weakenedGate = structuredClone(registry);
  weakenedGate.states.C11.requiredGate.issuer = "C09";
  const c11WeakenedMarker = adapters.readDocument("docs/workflow/pr-correction/30-implementation-and-publication.md")
    .replace("Обязательный шлюз: `implementation / G00 / implementation-gate.json`.", "Обязательный шлюз: `implementation / C09 / implementation-gate.json`.");
  assert(hasCode(validateRegistry(weakenedGate, { ...adapters, readDocument: (path) => path.endsWith("30-implementation-and-publication.md") ? c11WeakenedMarker : adapters.readDocument(path) }), "GATE_ALLOWLIST"));
  const markerMoved = adapters.readDocument("docs/workflow/pr-correction/30-implementation-and-publication.md")
    .replace("Исполнитель: агент.\nОбязательный шлюз: `implementation / G00 / implementation-gate.json`.", "Исполнитель: агент.\n\nОбязательный шлюз: `implementation / G00 / implementation-gate.json`.");
  assert(hasCode(validateRegistry(registry, { ...adapters, readDocument: (path) => path.endsWith("30-implementation-and-publication.md") ? markerMoved : adapters.readDocument(path) }), "GATE_MARKER"));
  const foreignGate = structuredClone(registry);
  foreignGate.states.C09.requiredGate = { kind: "implementation", issuer: "G00", artifact: "implementation-gate.json" };
  assert(hasCode(validateRegistry(foreignGate, adapters), "GATE_ALLOWLIST"));
  const missingProof = structuredClone(registry);
  delete missingProof.states.G01.requiredTransitionProofs;
  const g01WithoutProofMarker = adapters.readDocument("docs/workflow/pr-correction/30-implementation-and-publication.md")
    .replace("Обязательное доказательство перехода: `G01 -> C13 / publication_noop / G01 / no-op-proof.json`.\n", "");
  assert(hasCode(validateRegistry(missingProof, { ...adapters, readDocument: (path) => path.endsWith("30-implementation-and-publication.md") ? g01WithoutProofMarker : adapters.readDocument(path) }), "TRANSITION_PROOF_ALLOWLIST"));
  const wrongProof = structuredClone(registry);
  wrongProof.states.G01.requiredTransitionProofs.C13.artifact = "publication-gate.json";
  assert(hasCode(validateRegistry(wrongProof, adapters), "TRANSITION_PROOF_ALLOWLIST"));
  const proofOnWrongState = structuredClone(registry);
  proofOnWrongState.states.C12.requiredTransitionProofs = { C13: { kind: "publication_noop", issuer: "G01", artifact: "no-op-proof.json" } };
  assert(hasCode(validateRegistry(proofOnWrongState, adapters), "TRANSITION_PROOF_ALLOWLIST"));
  const missingProofTarget = structuredClone(registry);
  missingProofTarget.states.G01.next = missingProofTarget.states.G01.next.filter((target) => target !== "C13");
  assert(hasCode(validateRegistry(missingProofTarget, adapters), "TRANSITION_PROOF_TARGET"));
  const unreachable = structuredClone(registry);
  unreachable.states.C01.next = [];
  assert(hasCode(validateRegistry(unreachable, adapters), "UNREACHABLE_STATE"));
  const missingClosure = structuredClone(registry);
  missingClosure.states.X03.exits = ["main_process_no_result_closure"];
  assert(hasCode(validateRegistry(missingClosure, adapters), "TRANSITION_SET"));
  const missingHeading = structuredClone(registry);
  missingHeading.externalExits.main_process.heading = "## Несуществующий заголовок";
  assert(hasCode(validateRegistry(missingHeading, adapters), "EXTERNAL_EXIT_HEADING"));
  const renamedHeading = structuredClone(registry);
  renamedHeading.externalExits.main_process.heading = "## Этап 12. Контрольная точка";
  assert(hasCode(validateRegistry(renamedHeading, adapters), "EXTERNAL_EXIT_HEADING"));
  const fencedHeading = structuredClone(registry);
  fencedHeading.externalExits.main_process.document = "docs/workflow/pr-correction/fenced-heading-fixture.md";
  assert(hasCode(validateRegistry(fencedHeading, {
    ...adapters,
    fileExists: (path) => path === fencedHeading.externalExits.main_process.document || adapters.fileExists(path),
    readDocument: (path) => path === fencedHeading.externalExits.main_process.document ? "```markdown\n## Этап 12. Контрольные точки\n```\n" : adapters.readDocument(path),
  }), "EXTERNAL_EXIT_HEADING"));
  const semanticC08Bypass = structuredClone(registry);
  semanticC08Bypass.states.C08.next.push("C09");
  semanticC08Bypass.states.C08.transitionCount += 1;
  semanticC08Bypass.transitionCount += 1;
  const c08DocumentWithBypass = adapters.readDocument("docs/workflow/pr-correction/20-correction-spec-and-decisions.md").replace("| 3 | Авторское ревью пройдено | `P03` |", "| 3 | Авторское ревью пройдено | `P03` |\n| 4 | Обход внешнего review | `C09` |");
  const semanticErrors = validateRegistry(semanticC08Bypass, { ...adapters, readDocument: (path) => path.endsWith("20-correction-spec-and-decisions.md") ? c08DocumentWithBypass : adapters.readDocument(path) });
  assert(semanticErrors.some((value) => value.code === "TRANSITION_SET" && value.path === "$.states.C08.next"));
  const g00WithoutSpecExit = structuredClone(registry);
  g00WithoutSpecExit.states.G00.exits = [];
  g00WithoutSpecExit.states.G00.transitionCount -= 1;
  g00WithoutSpecExit.transitionCount -= 1;
  const g00DocumentWithoutSpecExit = adapters.readDocument("docs/workflow/pr-correction/27-implementation-entry-gate.md")
    .replace(/^\| 5 \|.*\n/m, "");
  const g00MissingExitErrors = validateRegistry(g00WithoutSpecExit, { ...adapters, readDocument: (path) => path.endsWith("27-implementation-entry-gate.md") ? g00DocumentWithoutSpecExit : adapters.readDocument(path) });
  assert(g00MissingExitErrors.some((value) => value.code === "TRANSITION_SET" && value.path === "$.states.G00.exits"));
  const g00ExitChangedToCode = structuredClone(g00WithoutSpecExit);
  g00ExitChangedToCode.states.G00.transitionCount += 1;
  g00ExitChangedToCode.transitionCount += 1;
  const g00DocumentChangedToCode = adapters.readDocument("docs/workflow/pr-correction/27-implementation-entry-gate.md")
    .replace("`EXIT:main_process_spec_revision`", "`C10`");
  const g00CodeErrors = validateRegistry(g00ExitChangedToCode, { ...adapters, readDocument: (path) => path.endsWith("27-implementation-entry-gate.md") ? g00DocumentChangedToCode : adapters.readDocument(path) });
  assert(g00CodeErrors.some((value) => value.code === "TRANSITION_SET" && value.path === "$.states.G00.exits"));
  assert(documentHasHeading("```\n## Этап 12. Контрольные точки\n```\n", "## Этап 12. Контрольные точки") === false);
  assert(documentHasHeading("##   Этап 12. Контрольные точки   ###\n", "## Этап 12. Контрольные точки"));

  rmSync(temporary, { recursive: true, force: true });

  console.log("workflow-docs-check self-test passed");
}

function readRegistry(root = DEFAULT_ROOT) {
  return JSON.parse(readUtf8(root, REGISTRY_PATH));
}

function printState(id) {
  console.log(stateDescription(readRegistry(), id));
}

function printMermaid() {
  console.log(mermaidFor(readRegistry()));
}

function failOnErrors(errors) {
  for (const value of errors) console.error(`Error: ${formatIssue(value)}`);
  if (errors.length > 0) process.exitCode = 1;
}

function main(argv) {
  if (argv.includes("--self-test")) selfTest();
  const needsRepositoryCheck = argv.includes("--check") || argv.includes("--state") || argv.includes("--mermaid");
  if (needsRepositoryCheck) {
    const errors = validateRepositoryAt();
    failOnErrors(errors);
    if (errors.length > 0) return;
    if (argv.includes("--check")) console.log("workflow docs check passed");
  }
  const stateIndex = argv.indexOf("--state");
  if (stateIndex !== -1) {
    const id = argv[stateIndex + 1];
    if (!id) throw new Error("После --state нужен ID состояния");
    printState(id);
  }
  if (argv.includes("--mermaid")) printMermaid();
}

if (resolve(process.argv[1] ?? "") === fileURLToPath(import.meta.url)) {
  try {
    main(process.argv.slice(2));
  } catch (error) {
    console.error(`Error: workflow-docs-check [UNEXPECTED]: ${error instanceof Error ? error.message : "неизвестная ошибка"}`);
    process.exitCode = 1;
  }
}
