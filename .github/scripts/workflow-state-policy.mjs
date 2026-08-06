#!/usr/bin/env node

import assert from "node:assert/strict";
import { existsSync, readFileSync } from "node:fs";
import { resolve } from "node:path";
import process from "node:process";
import { fileURLToPath } from "node:url";

const ROOT = resolve(fileURLToPath(new URL("../..", import.meta.url)));
const REGISTRY_PATH = "docs/workflow/pr-correction/states.json";
const STATE_ID = /^[BCDGXP]\d{2}$/;
const DYNAMIC_REGISTERS = new Set(["return_state", "resume_state"]);

export const ACTIVE_RUN_POLICY = Object.freeze({
  P03: "review",
  G01: "publication",
  C12: "publication",
});

export const HOLDING_STATES = new Set(["C05", "B01", "B02", "D02", "X03"]);
export const MAX_RESUME_DEPTH = 8;

export const REQUIRED_STATE_TARGETS = Object.freeze({
  C08: { next: ["C07", "D01", "P03"] },
  P03: { next: ["P03", "C07", "D01", "B01", "X03", "C09"] },
  C09: { next: ["G00", "D01", "D02", "X03"] },
  G00: { next: ["C10", "D01", "D02", "B01"], exits: ["main_process_spec_revision"] },
  C13: { next: ["B01", "C01", "C06", "P01"] },
  X03: {
    next: ["X03", "B01"],
    dynamicNext: ["return_state"],
    exits: ["main_process_no_result_closure", "main_process_materialized_route"],
  },
});

export const REQUIRED_GATES = Object.freeze({
  C10: { kind: "implementation", issuer: "G00", artifact: "implementation-gate.json" },
  C11: { kind: "implementation", issuer: "G00", artifact: "implementation-gate.json" },
  G01: { kind: "implementation", issuer: "G00", artifact: "implementation-gate.json" },
  C12: { kind: "publication", issuer: "G01", artifact: "publication-gate.json" },
});

export const REQUIRED_TRANSITION_PROOFS = Object.freeze({
  G01: {
    C13: { kind: "publication_noop", issuer: "G01", artifact: "no-op-proof.json" },
  },
});

function issue(code, path, message) {
  return { code, path, message };
}

function canonical(value) {
  if (Array.isArray(value)) return `[${value.map(canonical).join(",")}]`;
  if (value !== null && typeof value === "object") {
    return `{${Object.keys(value).sort().map((key) => `${JSON.stringify(key)}:${canonical(value[key])}`).join(",")}}`;
  }
  return JSON.stringify(value);
}

function sameSet(left = [], right = []) {
  return left.length === right.length && canonical([...left].sort()) === canonical([...right].sort());
}

export function activeRunKind(state) {
  return ACTIVE_RUN_POLICY[state] ?? null;
}

export function validateRunBinding(activeCycle) {
  const required = activeRunKind(activeCycle.state);
  if (required === null && activeCycle.activeRunId !== null) {
    return issue("ACTIVE_RUN", "$.activeRunId", `${activeCycle.state} не допускает activeRunId`);
  }
  if (required !== null && (typeof activeCycle.activeRunId !== "string" || activeCycle.activeRunId === "")) {
    return issue("ACTIVE_RUN", "$.activeRunId", `${activeCycle.state} требует ${required}-run`);
  }
  if (HOLDING_STATES.has(activeCycle.state) && activeCycle.activeRunId !== null) {
    return issue("HOLDING_RUN", "$.activeRunId", `${activeCycle.state} является holding-состоянием`);
  }
  return null;
}

export function completedRunForTransition({ sourceState, targetState, runId, entryGateIdentity, terminalEvidenceIdentity, noOp = false }) {
  if (targetState !== "C13" || !((sourceState === "C12" && !noOp) || (sourceState === "G01" && noOp))) return null;
  return {
    kind: noOp ? "publication_noop" : "publication",
    runId,
    entryGateIdentity,
    terminalEvidenceIdentity,
  };
}

export function nextRunPolicy(targetState) {
  if (targetState === "C12") return "restore_exact";
  if (targetState === "P03" || targetState === "G01") return "new";
  return "none";
}

export function pushResumeFrame(stack, frame) {
  if (!Array.isArray(stack)) throw new Error("resumeContexts должен быть массивом");
  if (stack.length >= MAX_RESUME_DEPTH) return { ok: false, state: "B01", reason: "resume_depth_exceeded", stack };
  if (HOLDING_STATES.has(frame.sourceState) && stack.at(-1)?.holdingState === frame.sourceState) {
    return { ok: true, stack: [...stack.slice(0, -1), frame], replaced: true };
  }
  return { ok: true, stack: [...stack, frame], replaced: false };
}

export function resumeFromTop(stack, { targetState, sourceContextIdentity, gateIdentity = null }) {
  if (!Array.isArray(stack) || stack.length === 0) return { ok: false, reason: "resume_frame_missing" };
  const frame = stack.at(-1);
  if (frame.targetState !== targetState || frame.sourceContextIdentity !== sourceContextIdentity) {
    return { ok: false, reason: "resume_frame_mismatch", stack };
  }
  const policy = nextRunPolicy(targetState);
  if (policy === "restore_exact" && (frame.gateIdentity !== gateIdentity || frame.savedRunId === null)) {
    return { ok: false, reason: "resume_gate_mismatch", stack };
  }
  return {
    ok: true,
    policy,
    restoredRunId: policy === "restore_exact" ? frame.savedRunId : null,
    stack: stack.slice(0, -1),
  };
}

export function sealOutcome(findings) {
  if (!Array.isArray(findings)) throw new Error("findings должен быть массивом");
  if (findings.some((finding) => finding.disposition === "technical_gap")) return "C07";
  if (findings.some((finding) => finding.disposition === "user_decision")) return "D01";
  return "P03";
}

export function qualificationOutcome(dispositions) {
  const values = Array.isArray(dispositions) ? dispositions : [];
  if (values.some((value) => value === "unresolved")) return "B01";
  if (values.some((value) => value === "confirmed_scope_or_risk")) return "D01";
  if (values.some((value) => value === "confirmed_current_scope")) return "C07";
  return "C09";
}

export function transitionTargetForOperation(operationKind, currentState, facts, registry) {
  let target;
  if (operationKind === "open_cycle") {
    if (currentState !== null) throw new Error("open_cycle не принимает caller source state");
    target = "C02";
  } else if (operationKind === "register_signal") {
    if (currentState === "B01" && facts?.stable === false && facts?.holdingEvidenceUpdate === true) target = "B01";
    else {
      if (!["C01", "C02"].includes(currentState)) throw new Error("register_signal допустим только из C01/C02 или как обновление B01");
      target = facts?.stable === true ? "C02" : "B01";
    }
  } else if (operationKind === "qualify_review") {
    if (currentState !== "P03") throw new Error("qualify_review допустим только из P03");
    target = qualificationOutcome(facts?.dispositions);
  } else if (operationKind === "seal_author_review") {
    if (currentState !== "C08") throw new Error("seal_author_review допустим только из C08");
    target = sealOutcome(facts?.findings);
  } else if (operationKind === "enter_publication_boundary") {
    if (currentState !== "C11") throw new Error("enter_publication_boundary допустим только из C11");
    target = "G01";
  } else if (operationKind === "refresh_snapshot") {
    if (currentState !== "C13" || facts?.changed !== true) throw new Error("refresh_snapshot требует изменившийся снимок C13");
    target = "C01";
  } else if (operationKind === "record_no_result_closure") {
    if (currentState !== "X03" || facts?.exitName !== "main_process_no_result_closure" || facts?.closureValid !== true) {
      throw new Error("no-result closure требует доказанный выход X03");
    }
    target = "X03";
  } else if (operationKind === "transition_state" && facts?.reason === "operation_conflict") {
    if (currentState === null) throw new Error("operation conflict требует существующий active cycle");
    target = "B01";
  } else {
    throw new Error(`operation kind ${operationKind} не имеет канонического target resolver-а`);
  }
  const evidenceOnlyUpdate = operationKind === "register_signal" && currentState === "B01" && target === "B01" && facts?.holdingEvidenceUpdate === true;
  const recoveryBlock = operationKind === "transition_state" && facts?.reason === "operation_conflict" && target === "B01";
  if (registry && currentState !== null && !evidenceOnlyUpdate && !recoveryBlock && !(registry.states[currentState]?.next ?? []).includes(target)) {
    throw new Error(`policy запретила ${currentState}→${target}`);
  }
  return target;
}

export function resolveDynamicTarget(currentState, registerName, registers, states) {
  const blocker = (reason) => ({ ok: false, state: currentState, blocker: reason, owner: "владелец регистра workflow" });
  if (!DYNAMIC_REGISTERS.has(registerName)) return blocker(`регистр ${registerName} не разрешён`);
  if (registers === null || typeof registers !== "object" || Array.isArray(registers) || !Object.hasOwn(registers, registerName)) return blocker(`регистр ${registerName} отсутствует`);
  const target = registers[registerName];
  if (typeof target !== "string" || !Object.hasOwn(states, target)) return blocker(`регистр ${registerName} содержит неизвестное состояние ${target}`);
  if (currentState === "D02" && target === "C10") return blocker("D02 не может возобновить цикл прямо в C10");
  return { ok: true, state: target, target };
}

export function validateTopology(registry) {
  const errors = [];
  const states = registry?.states;
  if (states === null || typeof states !== "object" || Array.isArray(states)) return [issue("POLICY_SCHEMA", "$.states", "ожидался объект состояний")];
  const exitNames = new Set(Object.keys(registry.externalExits ?? {}));
  const usedExits = new Set();
  let count = 0;
  for (const [id, state] of Object.entries(states)) {
    if (!STATE_ID.test(id)) errors.push(issue("STATE_ID", `$.states.${id}`, "недопустимый ID"));
    count += state.transitionCount;
    for (const target of state.next ?? []) if (!Object.hasOwn(states, target)) errors.push(issue("UNKNOWN_STATE_TARGET", `$.states.${id}.next`, target));
    for (const name of state.dynamicNext ?? []) if (!DYNAMIC_REGISTERS.has(name)) errors.push(issue("UNKNOWN_DYNAMIC_TARGET", `$.states.${id}.dynamicNext`, name));
    for (const name of state.exits ?? []) {
      usedExits.add(name);
      if (!exitNames.has(name)) errors.push(issue("UNKNOWN_EXTERNAL_EXIT", `$.states.${id}.exits`, name));
    }
    const gate = REQUIRED_GATES[id] ?? null;
    if (canonical(state.requiredGate ?? null) !== canonical(gate)) errors.push(issue("GATE_POLICY", `$.states.${id}.requiredGate`, "обязательный шлюз изменён"));
    const proofs = REQUIRED_TRANSITION_PROOFS[id] ?? null;
    if (canonical(state.requiredTransitionProofs ?? null) !== canonical(proofs)) errors.push(issue("PROOF_POLICY", `$.states.${id}.requiredTransitionProofs`, "доказательство перехода изменено"));
  }
  if (count !== registry.transitionCount) errors.push(issue("TRANSITION_COUNT", "$.transitionCount", `объявлено ${registry.transitionCount}, сумма ${count}`));
  for (const [id, expected] of Object.entries(REQUIRED_STATE_TARGETS)) {
    const state = states[id];
    if (!state) errors.push(issue("REQUIRED_STATE", `$.states.${id}`, "состояние отсутствует"));
    else for (const key of ["next", "dynamicNext", "exits"]) if (!sameSet(state[key] ?? [], expected[key] ?? [])) errors.push(issue("TRANSITION_SET", `$.states.${id}.${key}`, "канонический набор переходов изменён"));
  }
  if (states.C13?.next.includes("C12")) errors.push(issue("C13_BYPASS", "$.states.C13.next", "C13→C12 запрещён; нужен C13→C01"));
  if (states.X03?.exits.includes("terminal")) errors.push(issue("X03_TERMINAL", "$.states.X03.exits", "terminal-выход запрещён"));
  if (Object.hasOwn(states, registry.entryState)) {
    const reachable = new Set([registry.entryState]);
    const queue = [registry.entryState];
    while (queue.length) for (const target of states[queue.shift()].next ?? []) if (!reachable.has(target)) { reachable.add(target); queue.push(target); }
    for (const id of Object.keys(states)) if (!reachable.has(id)) errors.push(issue("UNREACHABLE_STATE", `$.states.${id}`, "состояние недостижимо"));
  }
  for (const name of exitNames) if (!usedExits.has(name)) errors.push(issue("UNUSED_EXTERNAL_EXIT", `$.externalExits.${name}`, "выход не используется"));
  return errors;
}

export function stateDescription(registry, id) {
  const state = registry.states[id];
  if (!state) throw new Error(`Неизвестное состояние ${id}`);
  const lines = [
    `Состояние: ${id} — ${state.name}`,
    `Исполнитель: ${state.actor}`,
    `Документ: ${state.document}`,
    `Следующие состояния: ${(state.next ?? []).join(", ") || "нет"}`,
  ];
  if (state.dynamicNext?.length) lines.push(`Динамический возврат: ${state.dynamicNext.join(", ")}`);
  if (state.exits?.length) lines.push(`Выходы из цикла: ${state.exits.join(", ")}`);
  if (state.requiredGate) lines.push(`Обязательный шлюз: ${state.requiredGate.kind} / ${state.requiredGate.issuer} / ${state.requiredGate.artifact}`);
  return lines.join("\n");
}

export function mermaidFor(registry) {
  const lines = ["flowchart TD"];
  for (const [id, state] of Object.entries(registry.states)) {
    lines.push(`    ${id}[\"${id}: ${state.name.replaceAll('"', "'")}\"]`);
    for (const target of state.next ?? []) lines.push(`    ${id} --> ${target}`);
    for (const target of state.dynamicNext ?? []) lines.push(`    ${id} -. \"${target}\" .-> DYNAMIC_${id}_${target}[\"DYNAMIC:${target}\"]`);
    for (const target of state.exits ?? []) lines.push(`    ${id} --> EXIT_${target}[\"EXIT:${target}\"]`);
  }
  return lines.join("\n");
}

export function readRegistry(root = ROOT) {
  const path = resolve(root, REGISTRY_PATH);
  if (!existsSync(path)) throw new Error(`Не найден ${REGISTRY_PATH}`);
  return JSON.parse(readFileSync(path, "utf8"));
}

function selfTest() {
  const registry = readRegistry();
  assert.deepEqual(validateTopology(registry), []);
  assert.equal(activeRunKind("C13"), null);
  assert.equal(activeRunKind("C12"), "publication");
  assert.equal(completedRunForTransition({ sourceState: "C12", targetState: "C13", runId: "r", entryGateIdentity: "g", terminalEvidenceIdentity: "e" }).kind, "publication");
  assert.equal(nextRunPolicy("P03"), "new");
  assert.equal(nextRunPolicy("G01"), "new");
  assert.equal(nextRunPolicy("C12"), "restore_exact");
  assert.equal(transitionTargetForOperation("open_cycle", null, {}, registry), "C02");
  assert.equal(transitionTargetForOperation("register_signal", "B01", { stable: false, holdingEvidenceUpdate: true }, registry), "B01");
  assert.throws(() => transitionTargetForOperation("register_signal", "B01", { stable: false }, registry));
  assert.equal(transitionTargetForOperation("qualify_review", "P03", { dispositions: ["confirmed_current_scope"] }, registry), "C07");
  assert.equal(transitionTargetForOperation("enter_publication_boundary", "C11", {}, registry), "G01");
  assert.equal(transitionTargetForOperation("record_no_result_closure", "X03", { exitName: "main_process_no_result_closure", closureValid: true }, registry), "X03");
  assert.throws(() => transitionTargetForOperation("record_no_result_closure", "X03", { exitName: "main_process_no_result_closure", closureValid: false }, registry));
  assert.equal(transitionTargetForOperation("refresh_snapshot", "C13", { changed: true }, registry), "C01");
  assert.equal(transitionTargetForOperation("transition_state", "C07", { reason: "operation_conflict" }, registry), "B01");
  assert.throws(() => transitionTargetForOperation("transition_state", null, { reason: "operation_conflict" }, registry));
  assert.throws(() => transitionTargetForOperation("transition_state", "C07", { reason: "caller_selected" }, registry));
  const stack = Array.from({ length: MAX_RESUME_DEPTH }, (_, index) => ({ frameId: String(index) }));
  assert.equal(pushResumeFrame(stack, { sourceState: "C10" }).ok, false);
  const bad = structuredClone(registry);
  bad.states.C13.next = bad.states.C13.next.map((value) => value === "C01" ? "C12" : value);
  assert(validateTopology(bad).some((value) => value.code === "C13_BYPASS"));
}

function main(argv) {
  if (argv.includes("--self-test")) selfTest();
  if (argv.includes("--check")) {
    const errors = validateTopology(readRegistry());
    if (errors.length) throw new Error(errors.map((value) => `${value.code} ${value.path}: ${value.message}`).join("\n"));
  }
  const stateIndex = argv.indexOf("--state");
  if (stateIndex !== -1) console.log(stateDescription(readRegistry(), argv[stateIndex + 1]));
  if (argv.includes("--mermaid")) console.log(mermaidFor(readRegistry()));
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) main(process.argv.slice(2));
