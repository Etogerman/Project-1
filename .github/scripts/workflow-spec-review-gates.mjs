#!/usr/bin/env node

import { randomBytes } from "node:crypto";
import {
  closeSync,
  constants as fsConstants,
  existsSync,
  fstatSync,
  fsyncSync,
  lstatSync,
  mkdirSync,
  openSync,
  readFileSync,
  realpathSync,
  renameSync,
  rmdirSync,
  rmSync,
  unlinkSync,
  writeFileSync,
} from "node:fs";
import { basename, dirname, join, resolve, sep } from "node:path";
import { hostname } from "node:os";
import {
  PROCESS_POLICY,
  canonicalJson,
  jcsIdentity,
  readSystemProcessIdentity,
  sha256Bytes,
  startProcessGroup,
  validateSafeInvocation,
} from "./workflow-spec-review.mjs";

const SHA256_PATTERN = /^[0-9a-f]{64}$/;
const OID_PATTERN = /^(?:[0-9a-f]{40}|[0-9a-f]{64})$/;
const EMPTY_DIFF_SHA256 = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855";
const CLASSIFICATION_FACTORS = Object.freeze([
  "publishedSubstantialContractChanged",
  "migrationChanged",
  "secretsChanged",
  "vpsChanged",
  "runtimeStagingOrDeployRequired",
  "externalMutationRequired",
]);
const RUN_STATES = new Set(["P03", "G01", "C12"]);
const REVIEW_RUN_STATE = "P03";
const PUBLICATION_RUN_STATES = new Set(["G01", "C12"]);
const heldOperationLocks = new WeakMap();

function fail(message, code = "GATE_SCHEMA") {
  const error = new Error(message);
  error.code = code;
  throw error;
}

function isObject(value) {
  return value !== null && typeof value === "object" && !Array.isArray(value);
}

function exactKeys(value, expected, label, code = "GATE_SCHEMA") {
  if (!isObject(value)) fail(`${label}: ожидался объект`, code);
  if (canonicalJson(Object.keys(value).sort()) !== canonicalJson([...expected].sort())) fail(`${label}: неверный набор полей`, code);
}

function nonempty(value, label, code = "GATE_SCHEMA") {
  if (typeof value !== "string" || value.trim() === "") fail(`${label}: ожидалась непустая строка`, code);
}

function sha(value, label, code = "GATE_SCHEMA") {
  if (typeof value !== "string" || !SHA256_PATTERN.test(value)) fail(`${label}: ожидался SHA-256`, code);
}

function oid(value, label, code = "GATE_SCHEMA") {
  if (typeof value !== "string" || !OID_PATTERN.test(value)) fail(`${label}: ожидался полный Git OID`, code);
}

function positiveInteger(value, label, code = "GATE_SCHEMA") {
  if (!Number.isInteger(value) || value <= 0) fail(`${label}: ожидалось положительное целое`, code);
}

function remainingPhaseBudget(deadlineAt, label, code) {
  const remaining = deadlineAt - Date.now();
  if (remaining <= 0) fail(`${label}: временной бюджет исчерпан`, code);
  return remaining;
}

function safeId(value, label, code = "GATE_SCHEMA") {
  nonempty(value, label, code);
  if (!/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/.test(value)) fail(`${label}: небезопасный ID`, code);
}

function exactIdentity(value, label, code = "GATE_SCHEMA") {
  sha(value.identity, `${label}.identity`, code);
  if (value.identity !== jcsIdentity(value)) fail(`${label}.identity не совпадает с RFC 8785 JCS`, code);
}

function validateOwner(owner, label = "owner", code = "ACTIVE_CYCLE") {
  exactKeys(owner, ["host", "pid", "pgid", "processStartToken", "operationId"], label, code);
  for (const key of ["host", "processStartToken", "operationId"]) nonempty(owner[key], `${label}.${key}`, code);
  positiveInteger(owner.pid, `${label}.pid`, code);
  positiveInteger(owner.pgid, `${label}.pgid`, code);
  return owner;
}

function canonicalTaskRoot(taskRoot, code = "ACTIVE_CYCLE_LOCK") {
  const lexical = resolve(taskRoot);
  const stat = existsSync(lexical) ? lstatSync(lexical) : null;
  if (!stat?.isDirectory() || stat.isSymbolicLink()) fail("taskRoot должен быть существующим обычным каталогом", code);
  return realpathSync(lexical);
}

function ownerFingerprint(owner) {
  validateOwner(owner, "lock.owner", "ACTIVE_CYCLE_LOCK");
  return sha256Bytes(Buffer.from(canonicalJson(owner)));
}

export async function createOperationOwner(operationId = `operation-${randomBytes(12).toString("hex")}`) {
  nonempty(operationId, "operationId", "ACTIVE_CYCLE_LOCK");
  const identity = await readSystemProcessIdentity(process.pid);
  return {
    host: hostname(),
    pid: identity.pid,
    pgid: identity.pgid,
    processStartToken: identity.processStartToken,
    operationId,
  };
}

export function acquireOperationLock({ taskRoot, owner }) {
  const root = canonicalTaskRoot(taskRoot);
  const fingerprint = ownerFingerprint(owner);
  const path = join(root, ".operation.lock");
  const record = {
    schemaVersion: 1,
    owner: structuredClone(owner),
    ownerFingerprint: fingerprint,
  };
  let fd;
  try {
    fd = openSync(path, fsConstants.O_CREAT | fsConstants.O_EXCL | fsConstants.O_RDWR | (fsConstants.O_NOFOLLOW ?? 0), 0o600);
    writeFileSync(fd, `${JSON.stringify(record, null, 2)}\n`);
    fsyncSync(fd);
  } catch (error) {
    if (fd !== undefined) closeSync(fd);
    if (error?.code === "EEXIST" || error?.code === "ELOOP") fail(".operation.lock уже удерживается или является symlink", "ACTIVE_CYCLE_LOCK_BUSY");
    throw error;
  }
  const stat = fstatSync(fd);
  const token = Object.freeze({ path, ownerFingerprint: fingerprint });
  heldOperationLocks.set(token, { fd, path, root, dev: stat.dev, ino: stat.ino, recordBytes: Buffer.from(`${JSON.stringify(record, null, 2)}\n`), released: false });
  return token;
}

export function assertOperationLockHeld(token, { taskRoot = null, owner = null } = {}) {
  const held = token && heldOperationLocks.get(token);
  if (!held || held.released) fail("требуется реально удерживаемая .operation.lock", "ACTIVE_CYCLE_LOCK");
  if (taskRoot !== null && canonicalTaskRoot(taskRoot) !== held.root) fail("operation lock относится к другому taskRoot", "ACTIVE_CYCLE_LOCK");
  let descriptorStat;
  let pathStat;
  try {
    descriptorStat = fstatSync(held.fd);
    pathStat = lstatSync(held.path);
  } catch {
    fail("operation lock больше не удерживается", "ACTIVE_CYCLE_LOCK");
  }
  if (!pathStat.isFile() || pathStat.isSymbolicLink() || descriptorStat.dev !== pathStat.dev || descriptorStat.ino !== pathStat.ino
    || !readFileSync(held.path).equals(held.recordBytes)) {
    fail("operation lock подменён или больше не соответствует удерживаемому descriptor", "ACTIVE_CYCLE_LOCK");
  }
  if (owner !== null && ownerFingerprint(owner) !== token.ownerFingerprint) fail("владелец operation lock не совпадает", "ACTIVE_CYCLE_LOCK");
  return { root: held.root, path: held.path, ownerFingerprint: token.ownerFingerprint };
}

export function releaseOperationLock(token) {
  const held = token && heldOperationLocks.get(token);
  if (!held || held.released) fail("operation lock уже освобождён или неизвестен", "ACTIVE_CYCLE_LOCK");
  assertOperationLockHeld(token);
  held.released = true;
  closeSync(held.fd);
  const pathStat = lstatSync(held.path);
  if (pathStat.dev !== held.dev || pathStat.ino !== held.ino) fail("operation lock подменён перед освобождением", "ACTIVE_CYCLE_LOCK");
  unlinkSync(held.path);
  return true;
}

export async function withOperationLock(options, callback) {
  const token = acquireOperationLock(options);
  try {
    return await callback(token);
  } finally {
    releaseOperationLock(token);
  }
}

function nonemptyStringArray(value, label, { allowEmpty = false, sorted = false } = {}, code = "GATE_SCHEMA") {
  if (!Array.isArray(value) || (!allowEmpty && value.length === 0)) fail(`${label}: ожидался ${allowEmpty ? "" : "непустой "}массив`, code);
  const seen = new Set();
  for (const [index, item] of value.entries()) {
    nonempty(item, `${label}[${index}]`, code);
    if (seen.has(item)) fail(`${label}: повтор ${item}`, code);
    seen.add(item);
  }
  if (sorted && canonicalJson(value) !== canonicalJson([...value].sort())) fail(`${label}: требуется лексикографический порядок`, code);
}

function safeRelativePath(value, label, code = "GATE_SCHEMA") {
  nonempty(value, label, code);
  if (value.startsWith("/") || value.includes("\\") || value.includes("\0") || value.split("/").some((part) => part === "" || part === "." || part === "..")) {
    fail(`${label}: небезопасный относительный путь`, code);
  }
  return value;
}

function canonicalPublicationRunRoot({ taskRoot, cycleId, revision, publicationRunId, publicationRunRoot }) {
  safeId(cycleId, "cycleId", "PUBLICATION_RUN_PATH");
  positiveInteger(revision, "revision", "PUBLICATION_RUN_PATH");
  safeId(publicationRunId, "publicationRunId", "PUBLICATION_RUN_PATH");
  const lexicalRoot = resolve(taskRoot);
  const rootStat = existsSync(lexicalRoot) ? lstatSync(lexicalRoot) : null;
  if (!rootStat?.isDirectory() || rootStat.isSymbolicLink()) fail("taskRoot не является обычным каталогом", "PUBLICATION_RUN_PATH");
  const root = realpathSync(lexicalRoot);
  const expected = join(root, "cycles", cycleId, `revision-${revision}`, "publication-runs", publicationRunId);
  const supplied = resolve(publicationRunRoot);
  const stat = existsSync(supplied) ? lstatSync(supplied) : null;
  if (!stat?.isDirectory() || stat.isSymbolicLink() || realpathSync(supplied) !== expected) fail("publication run root невалиден, неканоничен или проходит через symlink", "PUBLICATION_RUN_PATH");
  return supplied;
}

function validateEvidence(value, label) {
  exactKeys(value, ["value", "source", "evidenceRefs"], label, "SPEC_CLASSIFICATION");
  if (typeof value.value !== "boolean") fail(`${label}.value должен быть boolean`, "SPEC_CLASSIFICATION");
  nonempty(value.source, `${label}.source`, "SPEC_CLASSIFICATION");
  nonemptyStringArray(value.evidenceRefs, `${label}.evidenceRefs`, {}, "SPEC_CLASSIFICATION");
}

export function validateSpecClassification(value, context = {}) {
  exactKeys(value, ["schemaVersion", "phase", "snapshot", "streamClass", "factors", "scopeAnalyzerSha256", "uncertainty", "decision", "specRevision", "rationale", "identity"], "Spec classification", "SPEC_CLASSIFICATION");
  if (value.schemaVersion !== 1 || !["implementation", "publication"].includes(value.phase)) fail("Spec classification phase/schema невалидны", "SPEC_CLASSIFICATION");
  exactKeys(value.snapshot, ["tzSha256", "base", "head", "treeOid", "changedFilesSha256", "diffSha256"], "Spec classification.snapshot", "SPEC_CLASSIFICATION");
  sha(value.snapshot.tzSha256, "snapshot.tzSha256", "SPEC_CLASSIFICATION");
  oid(value.snapshot.base, "snapshot.base", "SPEC_CLASSIFICATION");
  oid(value.snapshot.treeOid, "snapshot.treeOid", "SPEC_CLASSIFICATION");
  sha(value.snapshot.changedFilesSha256, "snapshot.changedFilesSha256", "SPEC_CLASSIFICATION");
  sha(value.snapshot.diffSha256, "snapshot.diffSha256", "SPEC_CLASSIFICATION");
  if (value.phase === "implementation") oid(value.snapshot.head, "snapshot.head", "SPEC_CLASSIFICATION");
  else if (value.snapshot.head !== null) fail("publication classification требует head=null", "SPEC_CLASSIFICATION");
  exactKeys(value.streamClass, ["value", "source", "evidenceRefs"], "Spec classification.streamClass", "SPEC_CLASSIFICATION");
  if (!["docs_only", "minor", "substantial"].includes(value.streamClass.value)) fail("streamClass.value невалиден", "SPEC_CLASSIFICATION");
  nonempty(value.streamClass.source, "streamClass.source", "SPEC_CLASSIFICATION");
  nonemptyStringArray(value.streamClass.evidenceRefs, "streamClass.evidenceRefs", {}, "SPEC_CLASSIFICATION");
  exactKeys(value.factors, CLASSIFICATION_FACTORS, "Spec classification.factors", "SPEC_CLASSIFICATION");
  for (const name of CLASSIFICATION_FACTORS) validateEvidence(value.factors[name], `factors.${name}`);
  sha(value.scopeAnalyzerSha256, "scopeAnalyzerSha256", "SPEC_CLASSIFICATION");
  if (typeof value.uncertainty !== "boolean" || !["not_required", "pending", "fixed"].includes(value.decision)) fail("uncertainty/decision невалидны", "SPEC_CLASSIFICATION");
  nonempty(value.rationale, "rationale", "SPEC_CLASSIFICATION");
  const anyFactor = CLASSIFICATION_FACTORS.some((name) => value.factors[name].value);
  if (value.decision === "not_required") {
    if (value.uncertainty || anyFactor || !["docs_only", "minor"].includes(value.streamClass.value) || value.specRevision !== null) fail("not_required не доказан", "SPEC_CLASSIFICATION");
  } else if (value.decision === "pending") {
    if (value.specRevision !== null) fail("pending требует specRevision=null", "SPEC_CLASSIFICATION");
  } else {
    oid(value.specRevision, "specRevision", "SPEC_CLASSIFICATION");
  }
  if (context.phase && value.phase !== context.phase) fail("classification относится к другой фазе", "SPEC_CLASSIFICATION");
  if (context.expectedTreeOid && value.snapshot.treeOid !== context.expectedTreeOid) fail("classification относится к другому tree", "SPEC_CLASSIFICATION");
  if (value.phase === "publication" && !context.checksManifest) {
    fail("publication classification требует успешный checks manifest того же tree", "SPEC_CLASSIFICATION");
  }
  if (value.phase === "publication") {
    const checks = validateChecksManifest(context.checksManifest);
    if (checks.expectedTreeOid !== value.snapshot.treeOid || checks.checks.some((check) => check.exitCode !== 0)) fail("publication classification создана не после успешных checks того же tree", "SPEC_CLASSIFICATION");
  }
  exactIdentity(value, "Spec classification", "SPEC_CLASSIFICATION");
  return value;
}

export function validateImplementationGate(value, context = {}) {
  for (const key of ["finalManifestBytes", "tzBytes", "classification", "cycleId", "revision", "base", "inputHead"]) {
    if (context[key] === undefined) fail(`implementation gate требует context.${key}`, "IMPLEMENTATION_GATE");
  }
  exactKeys(value, ["schemaVersion", "cycleId", "revision", "reviewFinalManifestSha256", "tzSha256", "base", "inputHead", "implementationSpecClassificationIdentity", "issuedBy", "allowedStates", "identity"], "implementation gate", "IMPLEMENTATION_GATE");
  if (value.schemaVersion !== 1 || value.issuedBy !== "G00" || canonicalJson(value.allowedStates) !== canonicalJson(["C10", "C11", "G01"])) fail("implementation gate policy невалидна", "IMPLEMENTATION_GATE");
  nonempty(value.cycleId, "cycleId", "IMPLEMENTATION_GATE");
  positiveInteger(value.revision, "revision", "IMPLEMENTATION_GATE");
  for (const key of ["reviewFinalManifestSha256", "tzSha256", "implementationSpecClassificationIdentity"]) sha(value[key], key, "IMPLEMENTATION_GATE");
  oid(value.base, "base", "IMPLEMENTATION_GATE");
  oid(value.inputHead, "inputHead", "IMPLEMENTATION_GATE");
  if (context.finalManifestBytes && value.reviewFinalManifestSha256 !== sha256Bytes(context.finalManifestBytes)) fail("implementation gate ссылается на другой final manifest", "IMPLEMENTATION_GATE");
  if (context.tzBytes && value.tzSha256 !== sha256Bytes(context.tzBytes)) fail("implementation gate ссылается на другое ТЗ", "IMPLEMENTATION_GATE");
  if (context.classification) {
    validateSpecClassification(context.classification, { phase: "implementation" });
    if (value.implementationSpecClassificationIdentity !== context.classification.identity) fail("implementation gate не связан с допустимой классификацией", "IMPLEMENTATION_GATE");
  }
  for (const [field, expected] of [
    ["cycleId", context.cycleId],
    ["revision", context.revision],
    ["base", context.base],
    ["inputHead", context.inputHead],
  ]) if (expected !== undefined && value[field] !== expected) fail(`implementation gate: ${field} не совпадает`, "IMPLEMENTATION_GATE");
  exactIdentity(value, "implementation gate", "IMPLEMENTATION_GATE");
  return value;
}

function safeArgv(argv) {
  nonemptyStringArray(argv, "check.argv", {}, "CHECKS_MANIFEST");
  const executable = basename(argv[0]).toLowerCase();
  const forbidden = new Set(["sh", "bash", "zsh", "dash", "csh", "tcsh", "fish", "cmd", "cmd.exe", "powershell", "powershell.exe", "pwsh", "pwsh.exe"]);
  if (forbidden.has(executable)) fail("shell/interpreter check запрещён", "CHECKS_MANIFEST");
  if (executable === "env") {
    const envArgs = argv.slice(1);
    if (envArgs.some((value) => value === "-S" || value.startsWith("--split-string"))) fail("env split-string trampoline запрещён", "CHECKS_MANIFEST");
    let command = null;
    for (let index = 0; index < envArgs.length; index += 1) {
      const value = envArgs[index];
      if (["-u", "--unset", "-C", "--chdir"].includes(value)) {
        index += 1;
        continue;
      }
      if (value === "--") {
        command = envArgs[index + 1] ?? null;
        break;
      }
      if (value.startsWith("-") || /^[A-Za-z_][A-Za-z0-9_]*=/.test(value)) continue;
      command = value;
      break;
    }
    if (command && forbidden.has(basename(command).toLowerCase())) fail("env shell trampoline запрещён", "CHECKS_MANIFEST");
  }
}

export function validateChecksManifest(value) {
  exactKeys(value, ["schemaVersion", "expectedTreeOid", "checks", "identity"], "checks manifest", "CHECKS_MANIFEST");
  if (value.schemaVersion !== 1 || !Array.isArray(value.checks) || value.checks.length === 0) fail("checks manifest пуст или неверной версии", "CHECKS_MANIFEST");
  oid(value.expectedTreeOid, "expectedTreeOid", "CHECKS_MANIFEST");
  const names = new Set();
  for (const [index, check] of value.checks.entries()) {
    exactKeys(check, ["name", "argv", "cwd", "startedAt", "finishedAt", "exitCode", "stdoutSha256", "stderrSha256"], `checks[${index}]`, "CHECKS_MANIFEST");
    nonempty(check.name, `checks[${index}].name`, "CHECKS_MANIFEST");
    if (names.has(check.name)) fail(`повторное имя check ${check.name}`, "CHECKS_MANIFEST");
    names.add(check.name);
    safeArgv(check.argv);
    if (check.cwd !== ".") safeRelativePath(check.cwd, `checks[${index}].cwd`, "CHECKS_MANIFEST");
    const started = Date.parse(check.startedAt);
    const finished = Date.parse(check.finishedAt);
    if (!Number.isFinite(started) || !Number.isFinite(finished) || finished < started || !check.startedAt.endsWith("Z") || !check.finishedAt.endsWith("Z")) fail("время check невалидно", "CHECKS_MANIFEST");
    if (!Number.isInteger(check.exitCode)) fail("exitCode должен быть целым", "CHECKS_MANIFEST");
    sha(check.stdoutSha256, "stdoutSha256", "CHECKS_MANIFEST");
    sha(check.stderrSha256, "stderrSha256", "CHECKS_MANIFEST");
  }
  exactIdentity(value, "checks manifest", "CHECKS_MANIFEST");
  return value;
}

export function validatePublicationGate(value, context = {}) {
  for (const key of [
    "implementationGate", "implementationGateContext", "publicationClassification", "checksManifest",
    "checksManifestBytes", "cycleId", "revision", "publishBase", "expectedTreeOid",
    "tzBytes", "validatedDiffBytes", "publishedFiles",
  ]) {
    if (context[key] === undefined) fail(`publication gate требует context.${key}`, "PUBLICATION_GATE");
  }
  exactKeys(value, ["schemaVersion", "cycleId", "revision", "implementationGateIdentity", "tzSha256", "publishBase", "expectedTreeOid", "validatedDiffSha256", "publishedFiles", "implementationSpecClassificationIdentity", "publicationSpecClassificationIdentity", "specStatus", "specRevision", "checksSha256", "issuedBy", "allowedStates", "identity"], "publication gate", "PUBLICATION_GATE");
  if (value.schemaVersion !== 1 || value.issuedBy !== "G01" || canonicalJson(value.allowedStates) !== canonicalJson(["C12"])) fail("publication gate policy невалидна", "PUBLICATION_GATE");
  nonempty(value.cycleId, "cycleId", "PUBLICATION_GATE");
  positiveInteger(value.revision, "revision", "PUBLICATION_GATE");
  for (const key of ["implementationGateIdentity", "tzSha256", "validatedDiffSha256", "implementationSpecClassificationIdentity", "publicationSpecClassificationIdentity", "checksSha256"]) sha(value[key], key, "PUBLICATION_GATE");
  oid(value.publishBase, "publishBase", "PUBLICATION_GATE");
  oid(value.expectedTreeOid, "expectedTreeOid", "PUBLICATION_GATE");
  nonemptyStringArray(value.publishedFiles, "publishedFiles", { sorted: true }, "PUBLICATION_GATE");
  value.publishedFiles.forEach((path, index) => safeRelativePath(path, `publishedFiles[${index}]`, "PUBLICATION_GATE"));
  if (!["not_required", "fixed"].includes(value.specStatus)) fail("publication gate запрещает pending", "PUBLICATION_GATE");
  if (value.specStatus === "not_required" ? value.specRevision !== null : !OID_PATTERN.test(value.specRevision ?? "")) fail("specStatus/specRevision не согласованы", "PUBLICATION_GATE");
  if (context.implementationGate) {
    validateImplementationGate(context.implementationGate, context.implementationGateContext ?? {});
    if (value.implementationGateIdentity !== context.implementationGate.identity
      || value.implementationSpecClassificationIdentity !== context.implementationGate.implementationSpecClassificationIdentity
      || value.tzSha256 !== context.implementationGate.tzSha256) {
      fail("publication gate не связан с implementation gate и его ТЗ", "PUBLICATION_GATE");
    }
  }
  if (context.publicationClassification) {
    validateSpecClassification(context.publicationClassification, { phase: "publication", expectedTreeOid: value.expectedTreeOid, checksManifest: context.checksManifest });
    if (context.publicationClassification.decision === "pending" || value.publicationSpecClassificationIdentity !== context.publicationClassification.identity
      || value.specStatus !== context.publicationClassification.decision || value.specRevision !== context.publicationClassification.specRevision) fail("publication classification не совпадает", "PUBLICATION_GATE");
  }
  if (context.checksManifestBytes) {
    const checks = validateChecksManifest(JSON.parse(context.checksManifestBytes.toString("utf8")));
    if (checks.expectedTreeOid !== value.expectedTreeOid || checks.checks.some((check) => check.exitCode !== 0) || value.checksSha256 !== sha256Bytes(context.checksManifestBytes)) fail("checks не разрешают publication gate", "PUBLICATION_GATE");
  }
  for (const [field, expected] of [
    ["cycleId", context.cycleId],
    ["revision", context.revision],
    ["tzSha256", context.tzBytes ? sha256Bytes(context.tzBytes) : undefined],
    ["publishBase", context.publishBase],
    ["expectedTreeOid", context.expectedTreeOid],
    ["validatedDiffSha256", context.validatedDiffBytes ? sha256Bytes(context.validatedDiffBytes) : undefined],
  ]) if (expected !== undefined && value[field] !== expected) fail(`publication gate: ${field} не совпадает`, "PUBLICATION_GATE");
  if (context.publishedFiles && canonicalJson(value.publishedFiles) !== canonicalJson(context.publishedFiles)) fail("publication gate: publishedFiles не совпадает", "PUBLICATION_GATE");
  exactIdentity(value, "publication gate", "PUBLICATION_GATE");
  return value;
}

export function validateNoOpProof(value, context = {}) {
  for (const key of [
    "implementationGate", "implementationGateContext", "publicationClassification", "checksManifest",
    "checksManifestBytes", "cycleId", "revision", "tzBytes", "publishBase", "remoteHead",
    "publishBaseTreeOid", "publicationGateExists",
  ]) {
    if (context[key] === undefined) fail(`no-op proof требует context.${key}`, "NOOP_PROOF");
  }
  exactKeys(value, ["schemaVersion", "cycleId", "revision", "implementationGateIdentity", "tzSha256", "publishBase", "remoteHead", "remoteTreeOid", "expectedTreeOid", "validatedDiffSha256", "publicationSpecClassificationIdentity", "specStatus", "specRevision", "checksSha256", "mutationRequired", "issuedBy", "allowedStates", "identity"], "no-op proof", "NOOP_PROOF");
  if (value.schemaVersion !== 1 || value.issuedBy !== "G01" || value.mutationRequired !== false || canonicalJson(value.allowedStates) !== canonicalJson(["C13"])) fail("no-op proof policy невалидна", "NOOP_PROOF");
  nonempty(value.cycleId, "cycleId", "NOOP_PROOF");
  positiveInteger(value.revision, "revision", "NOOP_PROOF");
  for (const key of ["implementationGateIdentity", "tzSha256", "publicationSpecClassificationIdentity", "checksSha256"]) sha(value[key], key, "NOOP_PROOF");
  for (const key of ["publishBase", "remoteHead", "remoteTreeOid", "expectedTreeOid"]) oid(value[key], key, "NOOP_PROOF");
  if (value.remoteHead !== value.publishBase || value.remoteTreeOid !== value.expectedTreeOid || value.validatedDiffSha256 !== EMPTY_DIFF_SHA256) fail("no-op identity/diff не доказаны", "NOOP_PROOF");
  if (!["not_required", "fixed"].includes(value.specStatus) || (value.specStatus === "not_required" ? value.specRevision !== null : !OID_PATTERN.test(value.specRevision ?? ""))) fail("no-op Spec status невалиден", "NOOP_PROOF");
  if (context.publicationGateExists) fail("publication gate и no-op proof взаимоисключающи", "NOOP_PROOF");
  if (context.implementationGate) {
    validateImplementationGate(context.implementationGate, context.implementationGateContext ?? {});
    if (value.implementationGateIdentity !== context.implementationGate.identity
      || value.tzSha256 !== context.implementationGate.tzSha256) {
      fail("no-op proof не связан с implementation gate и его ТЗ", "NOOP_PROOF");
    }
  }
  if (context.publicationClassification) {
    validateSpecClassification(context.publicationClassification, { phase: "publication", expectedTreeOid: value.expectedTreeOid, checksManifest: context.checksManifest });
    if (context.publicationClassification.uncertainty !== false || context.publicationClassification.decision === "pending" || value.publicationSpecClassificationIdentity !== context.publicationClassification.identity
      || value.specStatus !== context.publicationClassification.decision || value.specRevision !== context.publicationClassification.specRevision) fail("no-op classification не совпадает", "NOOP_PROOF");
  }
  if (context.checksManifestBytes) {
    const checks = validateChecksManifest(JSON.parse(context.checksManifestBytes.toString("utf8")));
    if (checks.expectedTreeOid !== value.expectedTreeOid || checks.checks.some((check) => check.exitCode !== 0) || value.checksSha256 !== sha256Bytes(context.checksManifestBytes)) fail("no-op checks невалидны", "NOOP_PROOF");
  }
  for (const [field, expected] of [
    ["cycleId", context.cycleId],
    ["revision", context.revision],
    ["tzSha256", context.tzBytes ? sha256Bytes(context.tzBytes) : undefined],
    ["publishBase", context.publishBase],
    ["remoteHead", context.remoteHead],
    ["remoteTreeOid", context.publishBaseTreeOid],
    ["expectedTreeOid", context.publishBaseTreeOid],
  ]) if (expected !== undefined && value[field] !== expected) fail(`no-op proof: ${field} не совпадает`, "NOOP_PROOF");
  exactIdentity(value, "no-op proof", "NOOP_PROOF");
  return value;
}

export function validatePendingPublicationBlock(value) {
  exactKeys(value, ["state", "reason", "owner", "unblockEvent", "return_state"], "pending publication blocker", "PENDING_BLOCKER");
  if (value.state !== "B01" || value.return_state !== "G01") fail("pending должен вести только в B01 с return_state=G01", "PENDING_BLOCKER");
  for (const key of ["reason", "owner", "unblockEvent"]) nonempty(value[key], key, "PENDING_BLOCKER");
  if (!/Spec revision/iu.test(value.reason) || !/Spec revision/iu.test(value.unblockEvent)) fail("pending blocker должен назвать отсутствующую и проверяемую Spec revision", "PENDING_BLOCKER");
  return value;
}

export function validateActiveCycle(value, { states, taskRoot = null } = {}) {
  exactKeys(value, ["schemaVersion", "cycleId", "revision", "state", "activeRunId", "owner", "lockPath", "identity"], "active cycle", "ACTIVE_CYCLE");
  if (value.schemaVersion !== 1) fail("active cycle schemaVersion должен быть 1", "ACTIVE_CYCLE");
  safeId(value.cycleId, "cycleId", "ACTIVE_CYCLE");
  positiveInteger(value.revision, "revision", "ACTIVE_CYCLE");
  nonempty(value.state, "state", "ACTIVE_CYCLE");
  if (states && !Object.hasOwn(states, value.state)) fail("active cycle содержит неизвестное состояние", "ACTIVE_CYCLE");
  if (value.activeRunId !== null) safeId(value.activeRunId, "activeRunId", "ACTIVE_CYCLE");
  const requiresRun = RUN_STATES.has(value.state);
  if (requiresRun !== (typeof value.activeRunId === "string" && value.activeRunId !== "")) fail("state/activeRunId не согласованы", "ACTIVE_CYCLE");
  validateOwner(value.owner, "active cycle.owner", "ACTIVE_CYCLE");
  if (value.lockPath !== ".operation.lock") fail("lockPath должен быть .operation.lock", "ACTIVE_CYCLE");
  if (taskRoot && requiresRun) {
    const physicalTaskRoot = realpathSync(resolve(taskRoot));
    const runPath = value.state === REVIEW_RUN_STATE
      ? join(physicalTaskRoot, "runs", value.activeRunId)
      : join(physicalTaskRoot, "cycles", value.cycleId, `revision-${value.revision}`, "publication-runs", value.activeRunId);
    const stat = existsSync(runPath) ? lstatSync(runPath) : null;
    if (!stat?.isDirectory() || stat.isSymbolicLink() || realpathSync(runPath) !== runPath) fail("activeRunId не указывает на канонический каталог", "ACTIVE_CYCLE");
  }
  exactIdentity(value, "active cycle", "ACTIVE_CYCLE");
  return value;
}

function validatePolicyEvidence(value, expected, label, code) {
  exactKeys(value, ["kind", "issuer", "artifact", "identity"], label, code);
  for (const key of ["kind", "issuer", "artifact"]) {
    if (value[key] !== expected[key]) fail(`${label}.${key} не совпадает с машинной policy`, code);
  }
  sha(value.identity, `${label}.identity`, code);
  return value;
}

function readStoredPolicyArtifact({ root, current, nextState, nextRunId, policy, reference, label, code }) {
  validatePolicyEvidence(reference, policy, label, code);
  const cycleSegments = ["cycles", current.cycleId, `revision-${current.revision}`];
  const runId = current.activeRunId ?? nextRunId;
  const segments = policy.kind === "implementation"
    ? cycleSegments
    : [...cycleSegments, "publication-runs", runId];
  let directory = root;
  for (const segment of segments) {
    safeId(segment, `${label}.path`, code);
    directory = join(directory, segment);
    const directoryStat = existsSync(directory) ? lstatSync(directory) : null;
    if (!directoryStat?.isDirectory() || directoryStat.isSymbolicLink() || realpathSync(directory) !== directory) {
      fail(`${label}: канонический каталог artifact отсутствует или небезопасен`, code);
    }
  }
  const artifactPath = join(directory, policy.artifact);
  const artifactStat = existsSync(artifactPath) ? lstatSync(artifactPath) : null;
  if (!artifactStat?.isFile() || artifactStat.isSymbolicLink()) fail(`${label}: artifact отсутствует или небезопасен`, code);
  let artifact;
  try {
    artifact = JSON.parse(readFileSync(artifactPath, "utf8"));
  } catch {
    fail(`${label}: artifact содержит невалидный JSON`, code);
  }

  const common = () => {
    if (artifact.schemaVersion !== 1 || artifact.cycleId !== current.cycleId || artifact.revision !== current.revision
      || artifact.issuedBy !== policy.issuer || artifact.identity !== reference.identity) {
      fail(`${label}: artifact относится к другому cycle/revision/issuer/identity`, code);
    }
    exactIdentity(artifact, label, code);
  };
  if (policy.kind === "implementation") {
    exactKeys(artifact, ["schemaVersion", "cycleId", "revision", "reviewFinalManifestSha256", "tzSha256", "base", "inputHead", "implementationSpecClassificationIdentity", "issuedBy", "allowedStates", "identity"], label, code);
    common();
    if (canonicalJson(artifact.allowedStates) !== canonicalJson(["C10", "C11", "G01"]) || !artifact.allowedStates.includes(nextState)) fail(`${label}: implementation gate не разрешает состояние`, code);
    for (const key of ["reviewFinalManifestSha256", "tzSha256", "implementationSpecClassificationIdentity"]) sha(artifact[key], `${label}.${key}`, code);
    oid(artifact.base, `${label}.base`, code);
    oid(artifact.inputHead, `${label}.inputHead`, code);
  } else if (policy.kind === "publication") {
    exactKeys(artifact, ["schemaVersion", "cycleId", "revision", "implementationGateIdentity", "tzSha256", "publishBase", "expectedTreeOid", "validatedDiffSha256", "publishedFiles", "implementationSpecClassificationIdentity", "publicationSpecClassificationIdentity", "specStatus", "specRevision", "checksSha256", "issuedBy", "allowedStates", "identity"], label, code);
    common();
    if (canonicalJson(artifact.allowedStates) !== canonicalJson(["C12"]) || nextState !== "C12") fail(`${label}: publication gate не разрешает состояние`, code);
    for (const key of ["implementationGateIdentity", "tzSha256", "validatedDiffSha256", "implementationSpecClassificationIdentity", "publicationSpecClassificationIdentity", "checksSha256"]) sha(artifact[key], `${label}.${key}`, code);
    oid(artifact.publishBase, `${label}.publishBase`, code);
    oid(artifact.expectedTreeOid, `${label}.expectedTreeOid`, code);
    nonemptyStringArray(artifact.publishedFiles, `${label}.publishedFiles`, { sorted: true }, code);
    artifact.publishedFiles.forEach((path, index) => safeRelativePath(path, `${label}.publishedFiles[${index}]`, code));
    if (!["not_required", "fixed"].includes(artifact.specStatus)
      || (artifact.specStatus === "not_required" ? artifact.specRevision !== null : !OID_PATTERN.test(artifact.specRevision ?? ""))) {
      fail(`${label}: publication Spec status невалиден`, code);
    }
  } else if (policy.kind === "publication_noop") {
    exactKeys(artifact, ["schemaVersion", "cycleId", "revision", "implementationGateIdentity", "tzSha256", "publishBase", "remoteHead", "remoteTreeOid", "expectedTreeOid", "validatedDiffSha256", "publicationSpecClassificationIdentity", "specStatus", "specRevision", "checksSha256", "mutationRequired", "issuedBy", "allowedStates", "identity"], label, code);
    common();
    if (canonicalJson(artifact.allowedStates) !== canonicalJson(["C13"]) || nextState !== "C13" || artifact.mutationRequired !== false
      || artifact.remoteHead !== artifact.publishBase || artifact.remoteTreeOid !== artifact.expectedTreeOid
      || artifact.validatedDiffSha256 !== EMPTY_DIFF_SHA256) {
      fail(`${label}: no-op proof не доказывает безопасный переход`, code);
    }
    for (const key of ["implementationGateIdentity", "tzSha256", "publicationSpecClassificationIdentity", "checksSha256"]) sha(artifact[key], `${label}.${key}`, code);
    for (const key of ["publishBase", "remoteHead", "remoteTreeOid", "expectedTreeOid"]) oid(artifact[key], `${label}.${key}`, code);
    if (!["not_required", "fixed"].includes(artifact.specStatus)
      || (artifact.specStatus === "not_required" ? artifact.specRevision !== null : !OID_PATTERN.test(artifact.specRevision ?? ""))) {
      fail(`${label}: no-op Spec status невалиден`, code);
    }
  } else {
    fail(`${label}: неизвестный kind policy`, code);
  }
  return artifact;
}

export function transitionActiveCycle({
  current,
  expectedIdentity,
  nextState,
  nextRunId,
  states,
  operationLock,
  taskRoot = null,
  owner = null,
  resumeEvidence = null,
  expectedResumeEvidence = null,
  gateEvidence = null,
  transitionProof = null,
}) {
  const held = assertOperationLockHeld(operationLock, { taskRoot, owner });
  const effectiveTaskRoot = held.root;
  validateActiveCycle(current, { states, taskRoot: effectiveTaskRoot });
  if (current.identity !== expectedIdentity) fail("active cycle identity изменилась", "ACTIVE_CYCLE_CAS");
  if (!states || !Object.hasOwn(states, nextState)) fail("целевое состояние неизвестно", "ACTIVE_CYCLE_CAS");
  const direct = Array.isArray(states[current.state].next) && states[current.state].next.includes(nextState);
  const dynamicNames = new Set(states[current.state].dynamicNext ?? []);
  const dynamic = isObject(resumeEvidence)
    && ((dynamicNames.has("return_state") && resumeEvidence.returnState === nextState)
      || (dynamicNames.has("resume_state") && resumeEvidence.resumeState === nextState));
  if (!direct && !dynamic) fail(`переход ${current.state} -> ${nextState} не разрешён`, "ACTIVE_CYCLE_CAS");
  const requiredGate = states[nextState].requiredGate;
  let storedRequiredGate = null;
  if (requiredGate) {
    storedRequiredGate = readStoredPolicyArtifact({
      root: effectiveTaskRoot,
      current,
      nextState,
      nextRunId,
      policy: requiredGate,
      reference: gateEvidence,
      label: `${nextState}.requiredGate`,
      code: "ACTIVE_CYCLE_GATE",
    });
  } else if (gateEvidence !== null) fail("gate передан для незащищённого перехода", "ACTIVE_CYCLE_GATE");
  const requiredProof = states[current.state].requiredTransitionProofs?.[nextState];
  if (requiredProof) {
    readStoredPolicyArtifact({
      root: effectiveTaskRoot,
      current,
      nextState,
      nextRunId,
      policy: requiredProof,
      reference: transitionProof,
      label: `${current.state}->${nextState}.requiredTransitionProof`,
      code: "ACTIVE_CYCLE_PROOF",
    });
  }
  if (!requiredProof && transitionProof !== null) fail("доказательство передано для незащищённого перехода", "ACTIVE_CYCLE_PROOF");
  const enteringReview = nextState === "P03";
  const enteringPublication = nextState === "G01";
  const preservingReview = current.state === "P03" && nextState === "P03";
  const preservingPublication = current.state === "G01" && nextState === "C12";
  const restoringC12 = ["D02", "B01"].includes(current.state) && nextState === "C12";
  let activeRunId = null;
  if (preservingReview || preservingPublication) activeRunId = current.activeRunId;
  else if (enteringReview || enteringPublication) {
    nonempty(nextRunId, "nextRunId", "ACTIVE_CYCLE_CAS");
    activeRunId = nextRunId;
  } else if (restoringC12) {
    if (!isObject(resumeEvidence) || !isObject(expectedResumeEvidence)
      || canonicalJson(resumeEvidence) !== canonicalJson(expectedResumeEvidence)
      || resumeEvidence.publicationRunId !== nextRunId
      || resumeEvidence.resumeState !== "C12"
      || typeof resumeEvidence.gateIdentity !== "string" || !SHA256_PATTERN.test(resumeEvidence.gateIdentity)
      || resumeEvidence.gateIdentity !== storedRequiredGate?.identity) {
      fail("возврат в C12 требует точные сохранённые run/gate evidence", "ACTIVE_CYCLE_CAS");
    }
    activeRunId = nextRunId;
  }
  const next = {
    ...structuredClone(current),
    state: nextState,
    activeRunId,
    owner: structuredClone(owner ?? current.owner),
    identity: "",
  };
  next.identity = jcsIdentity(next);
  validateActiveCycle(next, { states, taskRoot: effectiveTaskRoot });
  return next;
}

function ensurePlainDirectoryChain(root, segments) {
  let cursor = root;
  for (const segment of segments) {
    safeId(segment, "directory segment", "ACTIVE_CYCLE_PATH");
    cursor = join(cursor, segment);
    if (!existsSync(cursor)) mkdirSync(cursor, { recursive: false });
    const stat = lstatSync(cursor);
    if (!stat.isDirectory() || stat.isSymbolicLink() || realpathSync(cursor) !== cursor) {
      fail(`${cursor}: каталог цикла неканоничен или проходит через symlink`, "ACTIVE_CYCLE_PATH");
    }
  }
  return cursor;
}

function prepareTransitionRunDirectory({ root, current, nextState, nextRunId }) {
  if (nextState === "P03" && current.state !== "P03") {
    safeId(nextRunId, "nextRunId", "ACTIVE_CYCLE_PATH");
    const parent = ensurePlainDirectoryChain(root, ["runs"]);
    const destination = join(parent, nextRunId);
    if (existsSync(destination)) fail("review run ID уже использован", "ACTIVE_CYCLE_PATH");
    mkdirSync(destination, { recursive: false });
    return destination;
  }
  if (nextState === "G01" && current.state !== "G01") {
    safeId(nextRunId, "nextRunId", "ACTIVE_CYCLE_PATH");
    const parent = ensurePlainDirectoryChain(root, ["cycles", current.cycleId, `revision-${current.revision}`, "publication-runs"]);
    const destination = join(parent, nextRunId);
    if (existsSync(destination)) fail("publication run ID уже использован", "ACTIVE_CYCLE_PATH");
    mkdirSync(destination, { recursive: false });
    return destination;
  }
  return null;
}

function replaceJsonAtomically(path, value) {
  const destination = resolve(path);
  const parent = dirname(destination);
  const pending = join(parent, `.${basename(destination)}.pending-${process.pid}-${randomBytes(4).toString("hex")}`);
  let fd;
  try {
    fd = openSync(pending, fsConstants.O_CREAT | fsConstants.O_EXCL | fsConstants.O_WRONLY | (fsConstants.O_NOFOLLOW ?? 0), 0o600);
    writeFileSync(fd, `${JSON.stringify(value, null, 2)}\n`);
    fsyncSync(fd);
    closeSync(fd);
    fd = undefined;
    renameSync(pending, destination);
    const directoryFd = openSync(parent, fsConstants.O_RDONLY);
    try {
      fsyncSync(directoryFd);
    } finally {
      closeSync(directoryFd);
    }
  } catch (error) {
    if (fd !== undefined) closeSync(fd);
    rmSync(pending, { force: true });
    throw error;
  }
}

export async function compareAndSetActiveCycle({
  taskRoot,
  owner,
  expectedIdentity,
  nextState,
  nextRunId,
  states,
  resumeEvidence = null,
  expectedResumeEvidence = null,
  gateEvidence = null,
  transitionProof = null,
}) {
  const root = canonicalTaskRoot(taskRoot);
  return withOperationLock({ taskRoot: root, owner }, async (operationLock) => {
    const activeCyclePath = join(root, "active-cycle.json");
    const stat = existsSync(activeCyclePath) ? lstatSync(activeCyclePath) : null;
    if (!stat?.isFile() || stat.isSymbolicLink()) fail("active-cycle.json отсутствует или не является обычным файлом", "ACTIVE_CYCLE_CAS");
    let current;
    try {
      current = JSON.parse(readFileSync(activeCyclePath, "utf8"));
    } catch {
      fail("active-cycle.json содержит невалидный JSON", "ACTIVE_CYCLE_CAS");
    }
    validateActiveCycle(current, { states, taskRoot: root });
    if (current.identity !== expectedIdentity) fail("active cycle identity изменилась", "ACTIVE_CYCLE_CAS");
    let createdRunDirectory = null;
    try {
      createdRunDirectory = prepareTransitionRunDirectory({ root, current, nextState, nextRunId });
      const next = transitionActiveCycle({
        current,
        expectedIdentity,
        nextState,
        nextRunId,
        states,
        operationLock,
        taskRoot: root,
        owner,
        resumeEvidence,
        expectedResumeEvidence,
        gateEvidence,
        transitionProof,
      });
      const latest = JSON.parse(readFileSync(activeCyclePath, "utf8"));
      if (latest.identity !== expectedIdentity || jcsIdentity(latest) !== latest.identity) fail("active-cycle.json изменён до compare-and-set", "ACTIVE_CYCLE_CAS");
      replaceJsonAtomically(activeCyclePath, next);
      return next;
    } catch (error) {
      if (createdRunDirectory !== null) {
        try {
          rmdirSync(createdRunDirectory);
        } catch {
          // Каталог с артефактами не удаляется автоматически.
        }
      }
      throw error;
    }
  });
}

function parseStatusPaths(bytes) {
  const tokens = bytes.toString("utf8").split("\0").filter(Boolean);
  const paths = [];
  for (let index = 0; index < tokens.length; index += 1) {
    const row = tokens[index];
    if (row.length < 4 || row[2] !== " ") fail("git status --porcelain -z имеет неизвестный формат", "TREE_SEAL");
    const status = row.slice(0, 2);
    paths.push(row.slice(3));
    if (/[RC]/.test(status)) {
      index += 1;
      if (index >= tokens.length) fail("rename status не содержит второй путь", "TREE_SEAL");
      paths.push(tokens[index]);
    }
  }
  return [...new Set(paths)].sort();
}

async function runBounded(command, args, options) {
  validateSafeInvocation(command, args, { shell: false, env: options.env });
  const group = startProcessGroup(command, args, {
    cwd: options.cwd,
    env: options.env,
    timeoutMs: options.timeoutMs,
    maxOutputBytes: PROCESS_POLICY.maxOutputBytesPerProcess,
    terminateGraceMs: PROCESS_POLICY.terminateGraceSeconds * 1_000,
    killGraceAfterMs: PROCESS_POLICY.killGraceSeconds * 1_000,
    finalDrainMs: PROCESS_POLICY.finalDrainSeconds * 1_000,
  });
  const result = await group.done;
  if (result.error || result.timedOut || result.cancelled || result.outputExceeded || result.identityMismatch
    || result.residualProcessDetected || !result.drainComplete || result.code !== 0) {
    fail(`${command} ${args[0] ?? ""} завершился ошибкой: ${result.stderr.toString("utf8")}`, "BOUNDED_COMMAND");
  }
  return result;
}

export async function sealExpectedTree({ repo, publishBase, publishedFiles, taskRoot, cycleId, revision, publicationRunId, publicationRunRoot }) {
  oid(publishBase, "publishBase", "TREE_SEAL");
  nonemptyStringArray(publishedFiles, "publishedFiles", { allowEmpty: true, sorted: true }, "TREE_SEAL");
  publishedFiles.forEach((path, index) => safeRelativePath(path, `publishedFiles[${index}]`, "TREE_SEAL"));
  const repository = resolve(repo);
  const runRoot = canonicalPublicationRunRoot({ taskRoot, cycleId, revision, publicationRunId, publicationRunRoot });
  const phaseDeadlineAt = Date.now() + PROCESS_POLICY.snapshotCommandSeconds * 1_000;
  const budget = () => remainingPhaseBudget(phaseDeadlineAt, "запечатывание tree", "TREE_SEAL");
  const status = await runBounded("git", ["-C", repository, "status", "--porcelain=v1", "-z", "--untracked-files=all"], { cwd: repository, env: process.env, timeoutMs: budget() });
  const actualPaths = parseStatusPaths(status.stdout);
  if (canonicalJson(actualPaths) !== canonicalJson(publishedFiles)) fail(`чужие или пропущенные изменения: ${actualPaths.join(", ")}`, "TREE_SEAL");
  const indexPath = join(runRoot, `publication-index-${randomBytes(6).toString("hex")}`);
  const env = { ...process.env, GIT_INDEX_FILE: indexPath };
  await runBounded("git", ["-C", repository, "read-tree", publishBase], { cwd: repository, env, timeoutMs: budget() });
  if (publishedFiles.length > 0) await runBounded("git", ["-C", repository, "add", "-A", "--", ...publishedFiles], { cwd: repository, env, timeoutMs: budget() });
  const tree = await runBounded("git", ["-C", repository, "write-tree"], { cwd: repository, env, timeoutMs: budget() });
  const expectedTreeOid = tree.stdout.toString("utf8").trim();
  oid(expectedTreeOid, "expectedTreeOid", "TREE_SEAL");
  const diff = await runBounded("git", ["-C", repository, "diff", "--binary", "--full-index", "--no-ext-diff", publishBase, expectedTreeOid, "--"], { cwd: repository, env, timeoutMs: budget() });
  const names = await runBounded("git", ["-C", repository, "diff", "--name-only", "-z", "--no-ext-diff", publishBase, expectedTreeOid, "--"], { cwd: repository, env, timeoutMs: budget() });
  const sealedPaths = names.stdout.toString("utf8").split("\0").filter(Boolean).sort();
  if (canonicalJson(sealedPaths) !== canonicalJson(publishedFiles)) fail("запечатанный tree не совпадает с publishedFiles", "TREE_SEAL");
  return { indexPath, expectedTreeOid, diffBytes: diff.stdout, validatedDiffSha256: sha256Bytes(diff.stdout) };
}

export async function runExactTreeChecks({ repo, expectedTreeOid, taskRoot, cycleId, revision, publicationRunId, publicationRunRoot, checks }) {
  oid(expectedTreeOid, "expectedTreeOid", "EXACT_TREE_CHECK");
  if (!Array.isArray(checks) || checks.length === 0) fail("список checks пуст", "EXACT_TREE_CHECK");
  const runRoot = canonicalPublicationRunRoot({ taskRoot, cycleId, revision, publicationRunId, publicationRunRoot });
  const checkout = join(runRoot, `validation-${randomBytes(6).toString("hex")}`);
  mkdirSync(checkout, { recursive: false });
  const indexPath = join(runRoot, `validation-index-${randomBytes(6).toString("hex")}`);
  const env = { ...process.env, GIT_INDEX_FILE: indexPath };
  const materializationDeadlineAt = Date.now() + PROCESS_POLICY.snapshotCommandSeconds * 1_000;
  const materializationBudget = () => remainingPhaseBudget(materializationDeadlineAt, "материализация exact tree", "EXACT_TREE_CHECK");
  await runBounded("git", ["-C", resolve(repo), "read-tree", expectedTreeOid], { cwd: resolve(repo), env, timeoutMs: materializationBudget() });
  await runBounded("git", ["-C", resolve(repo), "checkout-index", "--all", `--prefix=${checkout}${sep}`], { cwd: resolve(repo), env, timeoutMs: materializationBudget() });
  const rows = [];
  const checksDeadlineAt = Date.now() + PROCESS_POLICY.clientSeconds * 1_000;
  try {
    for (const check of checks) {
      exactKeys(check, ["name", "argv", "cwd"], "check input", "EXACT_TREE_CHECK");
      nonempty(check.name, "check.name", "EXACT_TREE_CHECK");
      safeArgv(check.argv);
      const cwdRelative = check.cwd === "." ? "." : safeRelativePath(check.cwd, "check.cwd", "EXACT_TREE_CHECK");
      const cwd = resolve(checkout, cwdRelative);
      if (cwd !== checkout && !cwd.startsWith(`${checkout}${sep}`)) fail("check cwd выходит за checkout", "EXACT_TREE_CHECK");
      const cwdStat = existsSync(cwd) ? lstatSync(cwd) : null;
      const physicalCheckout = realpathSync(checkout);
      const physicalCwd = cwdStat?.isDirectory() && !cwdStat.isSymbolicLink() ? realpathSync(cwd) : null;
      if (physicalCwd === null || (physicalCwd !== physicalCheckout && !physicalCwd.startsWith(`${physicalCheckout}${sep}`))) {
        fail("check cwd отсутствует, является symlink или физически выходит за checkout", "EXACT_TREE_CHECK");
      }
      const startedAt = new Date().toISOString();
      const result = await startProcessGroup(check.argv[0], check.argv.slice(1), {
        cwd,
        env: process.env,
        timeoutMs: remainingPhaseBudget(checksDeadlineAt, "проверки exact tree", "EXACT_TREE_CHECK"),
        maxOutputBytes: PROCESS_POLICY.maxOutputBytesPerProcess,
        terminateGraceMs: PROCESS_POLICY.terminateGraceSeconds * 1_000,
        killGraceAfterMs: PROCESS_POLICY.killGraceSeconds * 1_000,
        finalDrainMs: PROCESS_POLICY.finalDrainSeconds * 1_000,
      }).done;
      const finishedAt = new Date().toISOString();
      rows.push({
        name: check.name,
        argv: check.argv,
        cwd: cwdRelative,
        startedAt,
        finishedAt,
        exitCode: Number.isInteger(result.code) ? result.code : 1,
        stdoutSha256: sha256Bytes(result.stdout),
        stderrSha256: sha256Bytes(result.stderr),
      });
      if (result.error || result.timedOut || result.cancelled || result.outputExceeded || result.identityMismatch
        || result.residualProcessDetected || !result.drainComplete || result.code !== 0) break;
    }
  } finally {
    rmSync(checkout, { recursive: true, force: true });
  }
  const manifest = { schemaVersion: 1, expectedTreeOid, checks: rows, identity: "" };
  manifest.identity = jcsIdentity(manifest);
  validateChecksManifest(manifest);
  return manifest;
}

export function writeArtifactAtomically(path, value) {
  const destination = resolve(path);
  if (existsSync(destination)) fail(`${destination}: артефакт уже существует`, "ARTIFACT_EXISTS");
  mkdirSync(dirname(destination), { recursive: true });
  const pending = join(dirname(destination), `.${basename(destination)}.pending-${process.pid}-${randomBytes(4).toString("hex")}`);
  try {
    writeFileSync(pending, `${JSON.stringify(value, null, 2)}\n`, { flag: "wx" });
    renameSync(pending, destination);
  } catch (error) {
    rmSync(pending, { force: true });
    throw error;
  }
  return destination;
}

export function buildIdentityArtifact(value) {
  const copy = structuredClone(value);
  copy.identity = "";
  copy.identity = jcsIdentity(copy);
  return copy;
}

export const gateConstants = Object.freeze({
  EMPTY_DIFF_SHA256,
  CLASSIFICATION_FACTORS,
});
