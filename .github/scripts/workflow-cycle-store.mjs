#!/usr/bin/env node

import assert from "node:assert/strict";
import { createHash, randomBytes } from "node:crypto";
import {
  closeSync,
  constants as fsConstants,
  existsSync,
  fsyncSync,
  lstatSync,
  mkdtempSync,
  mkdirSync,
  openSync,
  readFileSync,
  readdirSync,
  realpathSync,
  renameSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import { dirname, join, relative, resolve, sep } from "node:path";
import { tmpdir } from "node:os";
import process from "node:process";
import { fileURLToPath } from "node:url";
import {
  acquireOperationLock,
  assertOperationLockHeld,
  buildIdentityArtifact,
  createOperationOwner,
  gateConstants,
  releaseOperationLock,
  transitionActiveCycle,
  validateActiveCycle,
  validateImplementationGate,
  validatePublicationRunOpen,
} from "./workflow-spec-review-gates.mjs";
import {
  CANONICAL_REVIEW_PROMPT_SHA256,
  PROCESS_POLICY,
  RESPONSE_MARKERS,
  buildQualification,
  buildReviewFinalArtifacts,
  canonicalJson,
  jcsIdentity,
  prepareQualificationArtifacts,
  sha256Bytes,
  validateLaunchIntent,
  validateReviewManifest,
  validateReviewRunOpen,
  verifyFinalDirectory,
} from "./workflow-spec-review.mjs";
import { pushResumeFrame, readRegistry, transitionTargetForOperation } from "./workflow-state-policy.mjs";

const SELF_PATH = fileURLToPath(import.meta.url);
const SHA256 = /^[0-9a-f]{64}$/;
const OID = /^(?:[0-9a-f]{40}|[0-9a-f]{64})$/;
const SAFE_ID = /^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/;
const STATE_ID = /^[BCDGXP]\d{2}$/;
const ACTIONS = new Set(["commit", "push"]);
const SUCCESSFUL_CHECK_CONCLUSION = "success";
const OPERATION_KINDS = new Set([
  "open_cycle", "register_signal", "record_decision", "open_revision",
  "write_revision_draft", "seal_author_review", "seal_and_open_review",
  "record_review_started", "record_review_result", "qualify_review",
  "record_spec_classification", "issue_implementation_gate",
  "enter_publication_boundary", "seal_expected_tree",
  "record_exact_tree_checks", "issue_publication_gate",
  "issue_publication_proof", "record_external_action", "transition_state",
  "quarantine_recovery",
]);

function fail(message, code = "CYCLE_SCHEMA", details = null) {
  const error = new Error(message);
  error.code = code;
  if (details !== null) Object.assign(error, details);
  throw error;
}

function isObject(value) {
  return value !== null && typeof value === "object" && !Array.isArray(value);
}

function exactKeys(value, expected, label, code = "CYCLE_SCHEMA") {
  if (!isObject(value)) fail(`${label}: ожидался объект`, code);
  const actual = Object.keys(value).sort();
  const wanted = [...expected].sort();
  if (canonicalJson(actual) !== canonicalJson(wanted)) fail(`${label}: неверный набор полей`, code);
}

function text(value, label, code = "CYCLE_SCHEMA") {
  if (typeof value !== "string" || value.trim() === "") fail(`${label}: ожидалась непустая строка`, code);
  return value;
}

function nullableText(value, label, code = "CYCLE_SCHEMA") {
  if (value !== null) text(value, label, code);
}

function sha(value, label, code = "CYCLE_SCHEMA") {
  if (typeof value !== "string" || !SHA256.test(value)) fail(`${label}: ожидался SHA-256`, code);
}

function nullableSha(value, label, code = "CYCLE_SCHEMA") {
  if (value !== null) sha(value, label, code);
}

function oid(value, label, code = "CYCLE_SCHEMA") {
  if (typeof value !== "string" || !OID.test(value)) fail(`${label}: ожидался полный Git OID`, code);
}

function nullableOid(value, label, code = "CYCLE_SCHEMA") {
  if (value !== null) oid(value, label, code);
}

function id(value, label, code = "CYCLE_SCHEMA") {
  if (typeof value !== "string" || !SAFE_ID.test(value)) fail(`${label}: недопустимый идентификатор`, code);
}

function integer(value, label, { minimum = 0 } = {}, code = "CYCLE_SCHEMA") {
  if (!Number.isInteger(value) || value < minimum) fail(`${label}: ожидалось целое >= ${minimum}`, code);
}

function iso(value, label, code = "CYCLE_SCHEMA") {
  if (typeof value !== "string" || !value.endsWith("Z") || !Number.isFinite(Date.parse(value))) fail(`${label}: ожидалось ISO UTC время`, code);
}

function exactIdentity(value, label, code = "CYCLE_SCHEMA") {
  sha(value.identity, `${label}.identity`, code);
  if (value.identity !== jcsIdentity(value)) fail(`${label}.identity не совпадает с JCS`, code);
}

function exactSortedStrings(value, label, { allowEmpty = true } = {}, code = "CYCLE_SCHEMA") {
  if (!Array.isArray(value) || (!allowEmpty && value.length === 0)) fail(`${label}: ожидался массив строк`, code);
  const sorted = [...value].sort();
  if (value.some((item) => typeof item !== "string" || item === "") || new Set(value).size !== value.length || canonicalJson(value) !== canonicalJson(sorted)) {
    fail(`${label}: массив должен быть отсортирован и уникален`, code);
  }
}

function exactNullable(value, validator, label, code) {
  if (value !== null) validator(value, label, code);
}

function validateOwner(value, label = "owner", code = "OWNER") {
  exactKeys(value, ["schemaVersion", "host", "bootIdentity", "pid", "pgid", "processStartToken", "operationId", "identity"], label, code);
  if (value.schemaVersion !== 1) fail(`${label}.schemaVersion должен быть 1`, code);
  text(value.host, `${label}.host`, code);
  sha(value.bootIdentity, `${label}.bootIdentity`, code);
  integer(value.pid, `${label}.pid`, { minimum: 1 }, code);
  integer(value.pgid, `${label}.pgid`, { minimum: 1 }, code);
  text(value.processStartToken, `${label}.processStartToken`, code);
  text(value.operationId, `${label}.operationId`, code);
  exactIdentity(value, label, code);
  return value;
}

function validateIdentityHint(value, label = "identityHint", code = "DECISION") {
  exactKeys(value, ["kind", "stableSubject", "identity"], label, code);
  text(value.kind, `${label}.kind`, code);
  text(value.stableSubject, `${label}.stableSubject`, code);
  if (/token|cookie|secret|password|bearer|oauth|@/i.test(value.stableSubject)) fail(`${label} содержит credential-подобные данные`, code);
  exactIdentity(value, label, code);
  return value;
}

export function identityOf(value) {
  return sha256Bytes(Buffer.from(canonicalJson(value)));
}

export function taskRootIdentity(taskRoot) {
  return identityOf(canonicalTaskRoot(taskRoot));
}

export function repositoryIdentity({ repositoryFullName, repositoryRealPath }) {
  text(repositoryFullName, "repositoryFullName", "REPOSITORY_IDENTITY");
  const physical = realpathSync(resolve(repositoryRealPath));
  return identityOf({ repositoryFullName, repositoryRealPath: physical });
}

function canonicalTaskRoot(taskRoot) {
  const lexical = resolve(taskRoot);
  const stat = existsSync(lexical) ? lstatSync(lexical) : null;
  if (!stat?.isDirectory() || stat.isSymbolicLink()) fail("task-root должен быть обычным каталогом", "PATH_SCOPE");
  return realpathSync(lexical);
}

function safeRelativePath(path, label = "path", code = "PATH_SCOPE") {
  if (typeof path !== "string" || path === "" || path.startsWith("/") || path.split("/").some((part) => part === "" || part === "." || part === "..")) {
    fail(`${label}: недопустимый относительный путь`, code);
  }
  return path;
}

function contained(root, path, label = "path") {
  const absolute = resolve(path);
  if (absolute !== root && !absolute.startsWith(root + sep)) fail(`${label} выходит за task-root`, "PATH_SCOPE");
  const parentParts = relative(root, dirname(absolute)).split(sep).filter(Boolean);
  let cursor = root;
  for (const part of parentParts) {
    cursor = join(cursor, part);
    if (!existsSync(cursor)) break;
    const stat = lstatSync(cursor);
    if (!stat.isDirectory() || stat.isSymbolicLink() || realpathSync(cursor) !== cursor) {
      fail(`${label} проходит через неканонический каталог`, "PATH_SCOPE");
    }
  }
  return absolute;
}

function fsyncDirectory(path) {
  const fd = openSync(path, fsConstants.O_RDONLY);
  try { fsyncSync(fd); } finally { closeSync(fd); }
}

function writeNoReplace(path, bytes, mode = 0o600) {
  mkdirSync(dirname(path), { recursive: true });
  let fd;
  try {
    fd = openSync(path, fsConstants.O_CREAT | fsConstants.O_EXCL | fsConstants.O_WRONLY | (fsConstants.O_NOFOLLOW ?? 0), mode);
    writeFileSync(fd, bytes);
    fsyncSync(fd);
    closeSync(fd);
    fd = undefined;
    fsyncDirectory(dirname(path));
  } catch (error) {
    if (fd !== undefined) closeSync(fd);
    throw error;
  }
}

function fileSha(path) {
  const stat = lstatSync(path);
  if (!stat.isFile() || stat.isSymbolicLink()) fail(`${path}: ожидался regular file`, "ARTIFACT_TYPE");
  return sha256Bytes(readFileSync(path));
}

function fsyncFile(path) {
  const fd = openSync(path, fsConstants.O_RDONLY | (fsConstants.O_NOFOLLOW ?? 0));
  try { fsyncSync(fd); } finally { closeSync(fd); }
}

export function validateReviewSnapshot(value) {
  exactKeys(value, ["schemaVersion", "sourceKind", "repositoryFullName", "pullRequestNumber", "baseOid", "inputHeadOid", "inputTreeOid", "titleSha256", "bodySha256", "providerRevision", "identity"], "review snapshot", "REVIEW_SNAPSHOT");
  if (value.schemaVersion !== 1 || !["pull_request", "commit"].includes(value.sourceKind)) fail("review snapshot sourceKind невалиден", "REVIEW_SNAPSHOT");
  text(value.repositoryFullName, "repositoryFullName", "REVIEW_SNAPSHOT");
  if (value.sourceKind === "pull_request") integer(value.pullRequestNumber, "pullRequestNumber", { minimum: 1 }, "REVIEW_SNAPSHOT");
  else if (value.pullRequestNumber !== null) fail("commit snapshot требует pullRequestNumber=null", "REVIEW_SNAPSHOT");
  for (const key of ["baseOid", "inputHeadOid", "inputTreeOid"]) oid(value[key], key, "REVIEW_SNAPSHOT");
  for (const key of ["titleSha256", "bodySha256"]) nullableSha(value[key], key, "REVIEW_SNAPSHOT");
  text(String(value.providerRevision), "providerRevision", "REVIEW_SNAPSHOT");
  exactIdentity(value, "review snapshot", "REVIEW_SNAPSHOT");
  return value;
}

export function validateSourceContext(value) {
  exactKeys(value, ["schemaVersion", "sourceKind", "repositoryFullName", "repositoryIdentity", "repositoryRealPath", "pullRequestNumber", "baseOid", "inputHeadOid", "inputTreeOid", "reviewSnapshotIdentity", "identity"], "source context", "SOURCE_CONTEXT");
  if (value.schemaVersion !== 1 || !["pull_request", "commit"].includes(value.sourceKind)) fail("source context sourceKind невалиден", "SOURCE_CONTEXT");
  text(value.repositoryFullName, "repositoryFullName", "SOURCE_CONTEXT");
  sha(value.repositoryIdentity, "repositoryIdentity", "SOURCE_CONTEXT");
  if (!value.repositoryRealPath.startsWith("/") || resolve(value.repositoryRealPath) !== value.repositoryRealPath) fail("repositoryRealPath не абсолютный", "SOURCE_CONTEXT");
  const repositoryStat = existsSync(value.repositoryRealPath) ? lstatSync(value.repositoryRealPath) : null;
  if (!repositoryStat?.isDirectory() || repositoryStat.isSymbolicLink() || realpathSync(value.repositoryRealPath) !== value.repositoryRealPath) {
    fail("repositoryRealPath не указывает на канонический каталог", "SOURCE_CONTEXT");
  }
  if (value.repositoryIdentity !== repositoryIdentity({ repositoryFullName: value.repositoryFullName, repositoryRealPath: value.repositoryRealPath })) {
    fail("repositoryIdentity не выведена из repositoryFullName/realpath", "SOURCE_CONTEXT");
  }
  if (value.sourceKind === "pull_request") integer(value.pullRequestNumber, "pullRequestNumber", { minimum: 1 }, "SOURCE_CONTEXT");
  else if (value.pullRequestNumber !== null) fail("commit context требует pullRequestNumber=null", "SOURCE_CONTEXT");
  for (const key of ["baseOid", "inputHeadOid", "inputTreeOid"]) oid(value[key], key, "SOURCE_CONTEXT");
  sha(value.reviewSnapshotIdentity, "reviewSnapshotIdentity", "SOURCE_CONTEXT");
  exactIdentity(value, "source context", "SOURCE_CONTEXT");
  return value;
}

function validateCheck(value, label = "check") {
  exactKeys(value, ["name", "appSlug", "checkRunId", "required", "status", "conclusion"], label, "CI_SNAPSHOT");
  text(value.name, `${label}.name`, "CI_SNAPSHOT");
  text(value.appSlug, `${label}.appSlug`, "CI_SNAPSHOT");
  text(String(value.checkRunId), `${label}.checkRunId`, "CI_SNAPSHOT");
  if (typeof value.required !== "boolean") fail(`${label}.required должен быть boolean`, "CI_SNAPSHOT");
  text(value.status, `${label}.status`, "CI_SNAPSHOT");
  nullableText(value.conclusion, `${label}.conclusion`, "CI_SNAPSHOT");
  return value;
}

export function validateCiSnapshot(value) {
  exactKeys(value, ["schemaVersion", "repositoryFullName", "pullRequestNumber", "headOid", "requirementsIdentity", "applicableChecks", "aggregateStatus", "providerRevision", "capturedAt", "identity"], "CI snapshot", "CI_SNAPSHOT");
  if (value.schemaVersion !== 1) fail("CI snapshot schemaVersion должен быть 1", "CI_SNAPSHOT");
  text(value.repositoryFullName, "repositoryFullName", "CI_SNAPSHOT");
  integer(value.pullRequestNumber, "pullRequestNumber", { minimum: 1 }, "CI_SNAPSHOT");
  oid(value.headOid, "headOid", "CI_SNAPSHOT");
  sha(value.requirementsIdentity, "requirementsIdentity", "CI_SNAPSHOT");
  if (!Array.isArray(value.applicableChecks)) fail("applicableChecks должен быть массивом", "CI_SNAPSHOT");
  value.applicableChecks.forEach((check, index) => validateCheck(check, `applicableChecks[${index}]`));
  const sorted = [...value.applicableChecks].sort((left, right) => canonicalJson([left.name, left.appSlug, String(left.checkRunId)]).localeCompare(canonicalJson([right.name, right.appSlug, String(right.checkRunId)])));
  if (canonicalJson(value.applicableChecks) !== canonicalJson(sorted)) fail("applicableChecks должен быть отсортирован", "CI_SNAPSHOT");
  const required = value.applicableChecks.filter((check) => check.required);
  const success = required.every((check) => check.status === "completed" && check.conclusion === SUCCESSFUL_CHECK_CONCLUSION);
  if (!["success", "blocked"].includes(value.aggregateStatus) || (value.aggregateStatus === "success") !== success) fail("aggregateStatus не совпадает с required checks", "CI_SNAPSHOT");
  text(String(value.providerRevision), "providerRevision", "CI_SNAPSHOT");
  iso(value.capturedAt, "capturedAt", "CI_SNAPSHOT");
  exactIdentity(value, "CI snapshot", "CI_SNAPSHOT");
  return value;
}

export function validateDelegationSnapshot(value) {
  exactKeys(value, ["schemaVersion", "repositoryFullName", "pullRequestNumber", "headOid", "delegationId", "expectedReviewerIds", "completedReviewerIds", "unresolvedThreadIds", "reviewStatus", "providerRevision", "capturedAt", "identity"], "delegation snapshot", "DELEGATION_SNAPSHOT");
  if (value.schemaVersion !== 1) fail("delegation schemaVersion должен быть 1", "DELEGATION_SNAPSHOT");
  text(value.repositoryFullName, "repositoryFullName", "DELEGATION_SNAPSHOT");
  integer(value.pullRequestNumber, "pullRequestNumber", { minimum: 1 }, "DELEGATION_SNAPSHOT");
  oid(value.headOid, "headOid", "DELEGATION_SNAPSHOT");
  id(value.delegationId, "delegationId", "DELEGATION_SNAPSHOT");
  for (const key of ["expectedReviewerIds", "completedReviewerIds", "unresolvedThreadIds"]) exactSortedStrings(value[key], key, {}, "DELEGATION_SNAPSHOT");
  if (value.completedReviewerIds.some((reviewer) => !value.expectedReviewerIds.includes(reviewer))) fail("completedReviewerIds содержит незапрошенного reviewer-а", "DELEGATION_SNAPSHOT");
  text(value.reviewStatus, "reviewStatus", "DELEGATION_SNAPSHOT");
  text(String(value.providerRevision), "providerRevision", "DELEGATION_SNAPSHOT");
  iso(value.capturedAt, "capturedAt", "DELEGATION_SNAPSHOT");
  exactIdentity(value, "delegation snapshot", "DELEGATION_SNAPSHOT");
  return value;
}

export function validateMutableSnapshotEvidence({ reviewSnapshot, ciSnapshot = null, delegationSnapshot = null }) {
  validateReviewSnapshot(reviewSnapshot);
  if (ciSnapshot !== null) {
    validateCiSnapshot(ciSnapshot);
    if (reviewSnapshot.sourceKind !== "pull_request"
      || ciSnapshot.repositoryFullName !== reviewSnapshot.repositoryFullName
      || ciSnapshot.pullRequestNumber !== reviewSnapshot.pullRequestNumber
      || ciSnapshot.headOid !== reviewSnapshot.inputHeadOid
      || ciSnapshot.aggregateStatus !== "success") {
      fail("CI snapshot не доказывает exact head review snapshot", "MUTABLE_SNAPSHOT_EVIDENCE");
    }
  }
  if (delegationSnapshot !== null) {
    validateDelegationSnapshot(delegationSnapshot);
    const completed = delegationSnapshot.expectedReviewerIds.length > 0
      && canonicalJson(delegationSnapshot.expectedReviewerIds) === canonicalJson(delegationSnapshot.completedReviewerIds);
    if (reviewSnapshot.sourceKind !== "pull_request"
      || delegationSnapshot.repositoryFullName !== reviewSnapshot.repositoryFullName
      || delegationSnapshot.pullRequestNumber !== reviewSnapshot.pullRequestNumber
      || delegationSnapshot.headOid !== reviewSnapshot.inputHeadOid
      || delegationSnapshot.unresolvedThreadIds.length !== 0
      || !completed
      || !["completed", "approved"].includes(delegationSnapshot.reviewStatus)) {
      fail("delegation snapshot не доказывает завершённую проверку exact head", "MUTABLE_SNAPSHOT_EVIDENCE");
    }
  }
  return {
    exactHeadOid: reviewSnapshot.inputHeadOid,
    ci: ciSnapshot === null ? "not_captured" : "success",
    delegation: delegationSnapshot === null ? "not_captured" : "completed",
  };
}

export function validateSignalEvidence(value) {
  exactKeys(value, ["schemaVersion", "signalId", "eventKind", "sourceState", "observedState", "repositoryFullName", "pullRequestNumber", "baseCandidates", "inputHeadOid", "inputTreeOid", "checkRunId", "reviewDelegationId", "reviewSnapshotIdentity", "ciSnapshotIdentity", "delegationSnapshotIdentity", "providerRevision", "observedAt", "payloadSha256", "identity"], "signal evidence", "SIGNAL_EVIDENCE");
  if (value.schemaVersion !== 1) fail("signal evidence schemaVersion должен быть 1", "SIGNAL_EVIDENCE");
  id(value.signalId, "signalId", "SIGNAL_EVIDENCE");
  text(value.eventKind, "eventKind", "SIGNAL_EVIDENCE");
  if (!STATE_ID.test(value.sourceState) || !STATE_ID.test(value.observedState)) fail("signal state невалиден", "SIGNAL_EVIDENCE");
  text(value.repositoryFullName, "repositoryFullName", "SIGNAL_EVIDENCE");
  if (value.pullRequestNumber !== null) integer(value.pullRequestNumber, "pullRequestNumber", { minimum: 1 }, "SIGNAL_EVIDENCE");
  exactSortedStrings(value.baseCandidates, "baseCandidates", { allowEmpty: false }, "SIGNAL_EVIDENCE");
  value.baseCandidates.forEach((value, index) => oid(value, `baseCandidates[${index}]`, "SIGNAL_EVIDENCE"));
  for (const key of ["inputHeadOid", "inputTreeOid"]) oid(value[key], key, "SIGNAL_EVIDENCE");
  for (const key of ["checkRunId", "reviewDelegationId"]) nullableText(value[key], key, "SIGNAL_EVIDENCE");
  for (const key of ["reviewSnapshotIdentity", "ciSnapshotIdentity", "delegationSnapshotIdentity"]) nullableSha(value[key], key, "SIGNAL_EVIDENCE");
  text(String(value.providerRevision), "providerRevision", "SIGNAL_EVIDENCE");
  iso(value.observedAt, "observedAt", "SIGNAL_EVIDENCE");
  sha(value.payloadSha256, "payloadSha256", "SIGNAL_EVIDENCE");
  exactIdentity(value, "signal evidence", "SIGNAL_EVIDENCE");
  return value;
}

export function validateSignalContext(value) {
  exactKeys(value, ["schemaVersion", "signalId", "kind", "sourceState", "originCheckState", "reviewSnapshotIdentity", "evidenceIdentity", "sourceContextIdentity", "identity"], "signal context", "SIGNAL_CONTEXT");
  if (value.schemaVersion !== 1) fail("signal context schemaVersion должен быть 1", "SIGNAL_CONTEXT");
  id(value.signalId, "signalId", "SIGNAL_CONTEXT");
  text(value.kind, "kind", "SIGNAL_CONTEXT");
  if (!STATE_ID.test(value.sourceState)) fail("sourceState невалиден", "SIGNAL_CONTEXT");
  nullableText(value.originCheckState, "originCheckState", "SIGNAL_CONTEXT");
  for (const key of ["reviewSnapshotIdentity", "evidenceIdentity", "sourceContextIdentity"]) sha(value[key], key, "SIGNAL_CONTEXT");
  exactIdentity(value, "signal context", "SIGNAL_CONTEXT");
  return value;
}

export function validateSignalLedger(value) {
  exactKeys(value, ["schemaVersion", "cycleId", "signalId", "signalContext", "signalEvidence", "reviewSnapshot", "ciSnapshot", "delegationSnapshot", "sourceContextIdentity", "registeredAt", "identity"], "signal ledger", "SIGNAL_LEDGER");
  if (value.schemaVersion !== 1) fail("signal ledger schemaVersion должен быть 1", "SIGNAL_LEDGER");
  id(value.cycleId, "cycleId", "SIGNAL_LEDGER");
  id(value.signalId, "signalId", "SIGNAL_LEDGER");
  validateSignalContext(value.signalContext);
  validateSignalEvidence(value.signalEvidence);
  validateReviewSnapshot(value.reviewSnapshot);
  exactNullable(value.ciSnapshot, validateCiSnapshot, "ciSnapshot", "SIGNAL_LEDGER");
  exactNullable(value.delegationSnapshot, validateDelegationSnapshot, "delegationSnapshot", "SIGNAL_LEDGER");
  sha(value.sourceContextIdentity, "sourceContextIdentity", "SIGNAL_LEDGER");
  if (value.signalId !== value.signalContext.signalId || value.signalId !== value.signalEvidence.signalId
    || value.signalEvidence.reviewSnapshotIdentity !== value.reviewSnapshot.identity
    || value.signalContext.reviewSnapshotIdentity !== value.reviewSnapshot.identity
    || value.signalContext.sourceContextIdentity !== value.sourceContextIdentity
    || value.signalEvidence.ciSnapshotIdentity !== (value.ciSnapshot?.identity ?? null)
    || value.signalEvidence.delegationSnapshotIdentity !== (value.delegationSnapshot?.identity ?? null)) fail("signal ledger смешивает разные снимки", "SIGNAL_LEDGER");
  iso(value.registeredAt, "registeredAt", "SIGNAL_LEDGER");
  exactIdentity(value, "signal ledger", "SIGNAL_LEDGER");
  return value;
}

export function validateDecision(value) {
  exactKeys(value, ["schemaVersion", "cycleId", "decisionId", "decisionState", "decisionKind", "requestedAction", "answer", "authorizedActions", "sourceContextIdentity", "revision", "evidenceRef", "evidenceSha256", "decidedBy", "recordedBy", "decidedAt", "identity"], "decision", "DECISION");
  if (value.schemaVersion !== 1) fail("decision schemaVersion должен быть 1", "DECISION");
  id(value.cycleId, "cycleId", "DECISION");
  id(value.decisionId, "decisionId", "DECISION");
  if (!STATE_ID.test(value.decisionState)) fail("decisionState невалиден", "DECISION");
  text(value.decisionKind, "decisionKind", "DECISION");
  text(value.requestedAction, "requestedAction", "DECISION");
  text(value.answer, "answer", "DECISION");
  exactSortedStrings(value.authorizedActions, "authorizedActions", {}, "DECISION");
  if (value.authorizedActions.some((action) => !ACTIONS.has(action))) fail("authorizedActions содержит неизвестное действие", "DECISION");
  sha(value.sourceContextIdentity, "sourceContextIdentity", "DECISION");
  integer(value.revision, "revision", { minimum: 0 }, "DECISION");
  text(value.evidenceRef, "evidenceRef", "DECISION");
  sha(value.evidenceSha256, "evidenceSha256", "DECISION");
  exactKeys(value.decidedBy, ["kind", "identityHint"], "decidedBy", "DECISION");
  if (value.decidedBy.kind !== "user") fail("decidedBy.kind должен быть user", "DECISION");
  validateIdentityHint(value.decidedBy.identityHint, "decidedBy.identityHint", "DECISION");
  validateOwner(value.recordedBy, "recordedBy", "DECISION");
  iso(value.decidedAt, "decidedAt", "DECISION");
  exactIdentity(value, "decision", "DECISION");
  return value;
}

export function validateRevisionOpen(value) {
  exactKeys(value, ["schemaVersion", "cycleId", "revision", "previousRevision", "sourceState", "signalIdentity", "sourceContext", "openedBy", "identity"], "revision open", "REVISION_OPEN");
  if (value.schemaVersion !== 1) fail("revision open schemaVersion должен быть 1", "REVISION_OPEN");
  id(value.cycleId, "cycleId", "REVISION_OPEN");
  integer(value.revision, "revision", { minimum: 1 }, "REVISION_OPEN");
  integer(value.previousRevision, "previousRevision", { minimum: 0 }, "REVISION_OPEN");
  if (value.revision !== value.previousRevision + 1) fail("revision должна увеличиваться ровно на один", "REVISION_OPEN");
  if (!STATE_ID.test(value.sourceState)) fail("sourceState невалиден", "REVISION_OPEN");
  sha(value.signalIdentity, "signalIdentity", "REVISION_OPEN");
  validateSourceContext(value.sourceContext);
  validateOwner(value.openedBy, "openedBy", "REVISION_OPEN");
  exactIdentity(value, "revision open", "REVISION_OPEN");
  return value;
}

function validateAuthorFinding(value, label = "author finding") {
  exactKeys(value, ["id", "priority", "disposition", "summary", "evidenceRefs"], label, "AUTHOR_REVIEW_RESULT");
  id(value.id, `${label}.id`, "AUTHOR_REVIEW_RESULT");
  if (!['P0', 'P1', 'P2', 'P3'].includes(value.priority)) fail(`${label}.priority невалиден`, "AUTHOR_REVIEW_RESULT");
  const allowed = value.priority === "P3"
    ? ["non_blocking", "rejected"]
    : value.priority === "P2"
      ? ["technical_gap", "user_decision", "non_blocking", "rejected"]
      : ["technical_gap", "user_decision"];
  if (!allowed.includes(value.disposition)) fail(`${label}.disposition не разрешён для ${value.priority}`, "AUTHOR_REVIEW_RESULT");
  text(value.summary, `${label}.summary`, "AUTHOR_REVIEW_RESULT");
  exactSortedStrings(value.evidenceRefs, `${label}.evidenceRefs`, { allowEmpty: false }, "AUTHOR_REVIEW_RESULT");
  return value;
}

export function validateAuthorReviewResult(value) {
  exactKeys(value, ["schemaVersion", "cycleId", "revision", "tzSha256", "authorReviewSha256", "findings", "outcomeState", "reviewedBy", "reviewedAt", "identity"], "author review result", "AUTHOR_REVIEW_RESULT");
  if (value.schemaVersion !== 1) fail("author review result schemaVersion должен быть 1", "AUTHOR_REVIEW_RESULT");
  id(value.cycleId, "cycleId", "AUTHOR_REVIEW_RESULT");
  integer(value.revision, "revision", { minimum: 1 }, "AUTHOR_REVIEW_RESULT");
  sha(value.tzSha256, "tzSha256", "AUTHOR_REVIEW_RESULT");
  sha(value.authorReviewSha256, "authorReviewSha256", "AUTHOR_REVIEW_RESULT");
  if (!Array.isArray(value.findings)) fail("findings должен быть массивом", "AUTHOR_REVIEW_RESULT");
  value.findings.forEach((finding, index) => validateAuthorFinding(finding, `findings[${index}]`));
  if (new Set(value.findings.map((finding) => finding.id)).size !== value.findings.length) fail("findings содержит повторный id", "AUTHOR_REVIEW_RESULT");
  const expectedOutcome = value.findings.some((finding) => finding.disposition === "technical_gap")
    ? "C07"
    : value.findings.some((finding) => finding.disposition === "user_decision") ? "D01" : "P03";
  if (value.outcomeState !== expectedOutcome) fail("outcomeState не выведен из findings", "AUTHOR_REVIEW_RESULT");
  validateOwner(value.reviewedBy, "reviewedBy", "AUTHOR_REVIEW_RESULT");
  iso(value.reviewedAt, "reviewedAt", "AUTHOR_REVIEW_RESULT");
  exactIdentity(value, "author review result", "AUTHOR_REVIEW_RESULT");
  return value;
}

export function validateRevisionSeal(value) {
  exactKeys(value, ["schemaVersion", "cycleId", "revision", "revisionOpenIdentity", "tzSha256", "authorReviewSha256", "authorReviewResultIdentity", "sourceContextIdentity", "outcomeState", "sealedBy", "sealedAt", "identity"], "revision seal", "REVISION_SEAL");
  if (value.schemaVersion !== 1) fail("revision seal schemaVersion должен быть 1", "REVISION_SEAL");
  id(value.cycleId, "cycleId", "REVISION_SEAL");
  integer(value.revision, "revision", { minimum: 1 }, "REVISION_SEAL");
  for (const key of ["revisionOpenIdentity", "tzSha256", "authorReviewSha256", "authorReviewResultIdentity", "sourceContextIdentity"]) sha(value[key], key, "REVISION_SEAL");
  if (!['C07', 'D01', 'P03'].includes(value.outcomeState)) fail("revision seal outcomeState невалиден", "REVISION_SEAL");
  validateOwner(value.sealedBy, "sealedBy", "REVISION_SEAL");
  iso(value.sealedAt, "sealedAt", "REVISION_SEAL");
  exactIdentity(value, "revision seal", "REVISION_SEAL");
  return value;
}

export function validateExecutionCeiling(value, { decision = null } = {}) {
  exactKeys(value, ["schemaVersion", "cycleId", "revision", "authorizedActions", "validUntilState", "decisionIdentity", "identity"], "execution ceiling", "EXECUTION_CEILING");
  if (value.schemaVersion !== 1) fail("execution ceiling schemaVersion должен быть 1", "EXECUTION_CEILING");
  id(value.cycleId, "cycleId", "EXECUTION_CEILING");
  integer(value.revision, "revision", { minimum: 1 }, "EXECUTION_CEILING");
  exactSortedStrings(value.authorizedActions, "authorizedActions", {}, "EXECUTION_CEILING");
  if (value.authorizedActions.some((action) => !ACTIONS.has(action))) fail("execution ceiling содержит неизвестное действие", "EXECUTION_CEILING");
  if (!STATE_ID.test(value.validUntilState)) fail("validUntilState невалиден", "EXECUTION_CEILING");
  sha(value.decisionIdentity, "decisionIdentity", "EXECUTION_CEILING");
  if (decision) {
    validateDecision(decision);
    if (value.decisionIdentity !== decision.identity || value.cycleId !== decision.cycleId || value.revision !== decision.revision
      || canonicalJson(value.authorizedActions) !== canonicalJson(decision.authorizedActions)) fail("execution ceiling не выведен из решения", "EXECUTION_CEILING");
  }
  exactIdentity(value, "execution ceiling", "EXECUTION_CEILING");
  return value;
}

export function executionCeilingFromDecision(decision, validUntilState) {
  validateDecision(decision);
  return buildIdentityArtifact({
    schemaVersion: 1,
    cycleId: decision.cycleId,
    revision: decision.revision,
    authorizedActions: [...decision.authorizedActions],
    validUntilState,
    decisionIdentity: decision.identity,
  });
}

export function validateActionIntent(value) {
  exactKeys(value, ["schemaVersion", "cycleId", "revision", "publicationRunId", "actionId", "actionKind", "repositoryIdentity", "worktreeIdentity", "expectedHeadOid", "treeOid", "parentOid", "messageSha256", "authorIdentity", "committerIdentity", "authorTimestamp", "committerTimestamp", "remoteName", "targetRef", "expectedRemoteOid", "intendedResultOid", "publicationGateIdentity", "executionCeilingIdentity", "commandIdentity", "createdBy", "createdAt", "identity"], "action intent", "ACTION_INTENT");
  if (value.schemaVersion !== 1) fail("action intent schemaVersion должен быть 1", "ACTION_INTENT");
  id(value.cycleId, "cycleId", "ACTION_INTENT");
  integer(value.revision, "revision", { minimum: 1 }, "ACTION_INTENT");
  id(value.publicationRunId, "publicationRunId", "ACTION_INTENT");
  id(value.actionId, "actionId", "ACTION_INTENT");
  if (!ACTIONS.has(value.actionKind)) fail("actionKind невалиден", "ACTION_INTENT");
  for (const key of ["repositoryIdentity", "worktreeIdentity", "publicationGateIdentity", "executionCeilingIdentity", "commandIdentity"]) sha(value[key], key, "ACTION_INTENT");
  for (const key of ["expectedHeadOid", "treeOid", "parentOid", "expectedRemoteOid", "intendedResultOid"]) nullableOid(value[key], key, "ACTION_INTENT");
  for (const key of ["messageSha256", "authorIdentity", "committerIdentity"]) nullableSha(value[key], key, "ACTION_INTENT");
  for (const key of ["authorTimestamp", "committerTimestamp"]) if (value[key] !== null) iso(value[key], key, "ACTION_INTENT");
  for (const key of ["remoteName", "targetRef"]) nullableText(value[key], key, "ACTION_INTENT");
  validateOwner(value.createdBy, "createdBy", "ACTION_INTENT");
  iso(value.createdAt, "createdAt", "ACTION_INTENT");
  if (value.actionKind === "commit") {
    for (const key of ["expectedHeadOid", "treeOid", "parentOid", "messageSha256", "authorIdentity", "committerIdentity", "authorTimestamp", "committerTimestamp", "intendedResultOid"]) if (value[key] === null) fail(`commit требует ${key}`, "ACTION_INTENT");
    if (["remoteName", "targetRef", "expectedRemoteOid"].some((key) => value[key] !== null)) fail("commit содержит push-поля", "ACTION_INTENT");
    if (value.expectedHeadOid !== value.parentOid) fail("commit parent должен совпадать с ожидаемым HEAD", "ACTION_INTENT");
  } else {
    for (const key of ["expectedHeadOid", "remoteName", "targetRef", "expectedRemoteOid", "intendedResultOid"]) if (value[key] === null) fail(`push требует ${key}`, "ACTION_INTENT");
    if (["treeOid", "parentOid", "messageSha256", "authorIdentity", "committerIdentity", "authorTimestamp", "committerTimestamp"].some((key) => value[key] !== null)) fail("push содержит commit-поля", "ACTION_INTENT");
    if (value.expectedHeadOid !== value.intendedResultOid) fail("push разрешён только для ожидаемого локального HEAD", "ACTION_INTENT");
  }
  exactIdentity(value, "action intent", "ACTION_INTENT");
  return value;
}

export function validateActionResult(value) {
  exactKeys(value, ["schemaVersion", "actionIntentIdentity", "actionKind", "exitCode", "localHeadOid", "remoteOid", "startedAt", "finishedAt", "outcome", "identity"], "action result", "ACTION_RESULT");
  if (value.schemaVersion !== 1 || !ACTIONS.has(value.actionKind)) fail("action result policy невалидна", "ACTION_RESULT");
  sha(value.actionIntentIdentity, "actionIntentIdentity", "ACTION_RESULT");
  integer(value.exitCode, "exitCode", { minimum: 0 }, "ACTION_RESULT");
  nullableOid(value.localHeadOid, "localHeadOid", "ACTION_RESULT");
  nullableOid(value.remoteOid, "remoteOid", "ACTION_RESULT");
  iso(value.startedAt, "startedAt", "ACTION_RESULT");
  iso(value.finishedAt, "finishedAt", "ACTION_RESULT");
  if (!["success", "blocked", "failed"].includes(value.outcome)) fail("action result outcome невалиден", "ACTION_RESULT");
  if (value.outcome === "success" && value.exitCode !== 0) fail("успешное действие требует exitCode=0", "ACTION_RESULT");
  exactIdentity(value, "action result", "ACTION_RESULT");
  return value;
}

export function recoverCommitAction(intent, observed) {
  validateActionIntent(intent);
  if (intent.actionKind !== "commit") fail("ожидался commit intent", "ACTION_RECOVERY");
  exactKeys(observed, ["headOid", "treeOid", "parentOid", "messageSha256"], "observed commit", "ACTION_RECOVERY");
  for (const key of ["headOid", "treeOid", "parentOid"]) oid(observed[key], key, "ACTION_RECOVERY");
  sha(observed.messageSha256, "messageSha256", "ACTION_RECOVERY");
  if (observed.headOid === intent.expectedHeadOid) return "retry";
  if (observed.headOid === intent.intendedResultOid && observed.treeOid === intent.treeOid && observed.parentOid === intent.parentOid && observed.messageSha256 === intent.messageSha256) return "success";
  return "blocked";
}

export function recoverPushAction(intent, remoteOid) {
  validateActionIntent(intent);
  if (intent.actionKind !== "push") fail("ожидался push intent", "ACTION_RECOVERY");
  oid(remoteOid, "remoteOid", "ACTION_RECOVERY");
  if (remoteOid === intent.intendedResultOid) return "success";
  if (remoteOid === intent.expectedRemoteOid) return "retry";
  return "blocked";
}

function validatePlannedArtifact(value, label = "planned artifact") {
  exactKeys(value, ["path", "pendingPath", "kind", "mode", "expectedOldSha256", "newSha256"], label, "OPERATION_INTENT");
  safeRelativePath(value.path, `${label}.path`, "OPERATION_INTENT");
  safeRelativePath(value.pendingPath, `${label}.pendingPath`, "OPERATION_INTENT");
  text(value.kind, `${label}.kind`, "OPERATION_INTENT");
  integer(value.mode, `${label}.mode`, { minimum: 0 }, "OPERATION_INTENT");
  nullableSha(value.expectedOldSha256, `${label}.expectedOldSha256`, "OPERATION_INTENT");
  sha(value.newSha256, `${label}.newSha256`, "OPERATION_INTENT");
  return value;
}

export function validateOperationIntent(value) {
  exactKeys(value, ["schemaVersion", "operationId", "kind", "cycleId", "revision", "sourceState", "targetState", "owner", "expectedActiveCycleIdentity", "nextActiveCycleIdentity", "plannedArtifacts", "startedAt", "identity"], "operation intent", "OPERATION_INTENT");
  if (value.schemaVersion !== 1 || !OPERATION_KINDS.has(value.kind)) fail("operation kind не разрешён", "OPERATION_INTENT");
  id(value.operationId, "operationId", "OPERATION_INTENT");
  id(value.cycleId, "cycleId", "OPERATION_INTENT");
  integer(value.revision, "revision", { minimum: 0 }, "OPERATION_INTENT");
  validateOwner(value.owner, "owner", "OPERATION_INTENT");
  if (value.kind === "open_cycle") {
    if (value.revision !== 0 || value.sourceState !== null || value.targetState !== "C02" || value.expectedActiveCycleIdentity !== null) fail("open_cycle intent невалиден", "OPERATION_INTENT");
  } else {
    if (!STATE_ID.test(value.sourceState) || !STATE_ID.test(value.targetState)) fail("operation states невалидны", "OPERATION_INTENT");
    sha(value.expectedActiveCycleIdentity, "expectedActiveCycleIdentity", "OPERATION_INTENT");
  }
  sha(value.nextActiveCycleIdentity, "nextActiveCycleIdentity", "OPERATION_INTENT");
  if (!Array.isArray(value.plannedArtifacts) || value.plannedArtifacts.length === 0) fail("plannedArtifacts пуст", "OPERATION_INTENT");
  value.plannedArtifacts.forEach((artifact, index) => validatePlannedArtifact(artifact, `plannedArtifacts[${index}]`));
  if (new Set(value.plannedArtifacts.map((artifact) => artifact.path)).size !== value.plannedArtifacts.length) fail("plannedArtifacts содержит повторный path", "OPERATION_INTENT");
  if (value.kind !== "open_cycle") {
    const states = readRegistry().states;
    const direct = (states[value.sourceState]?.next ?? []).includes(value.targetState);
    const recoveryBlock = value.kind === "transition_state" && value.targetState === "B01"
      && value.plannedArtifacts.some((artifact) => artifact.path.includes("/quarantine-blocks/"));
    const holdingEvidenceUpdate = value.kind === "register_signal" && value.sourceState === "B01" && value.targetState === "B01";
    if (!direct && !recoveryBlock && !holdingEvidenceUpdate) fail(`operation intent содержит запрещённый переход ${value.sourceState}→${value.targetState}`, "OPERATION_INTENT");
  }
  iso(value.startedAt, "startedAt", "OPERATION_INTENT");
  exactIdentity(value, "operation intent", "OPERATION_INTENT");
  return value;
}

export function validateQuarantineBlock(value) {
  exactKeys(value, ["schemaVersion", "operationId", "operationIntentIdentity", "cycleId", "revision", "sourceState", "blockedState", "conflictPath", "quarantinedPath", "preservedIntentPath", "preservedPendingPath", "owner", "blockedAt", "identity"], "quarantine block", "QUARANTINE_BLOCK");
  if (value.schemaVersion !== 1 || value.blockedState !== "B01") fail("quarantine block должен фиксировать B01", "QUARANTINE_BLOCK");
  id(value.operationId, "operationId", "QUARANTINE_BLOCK");
  sha(value.operationIntentIdentity, "operationIntentIdentity", "QUARANTINE_BLOCK");
  id(value.cycleId, "cycleId", "QUARANTINE_BLOCK");
  integer(value.revision, "revision", { minimum: 0 }, "QUARANTINE_BLOCK");
  if (!STATE_ID.test(value.sourceState)) fail("sourceState невалиден", "QUARANTINE_BLOCK");
  for (const key of ["conflictPath", "preservedIntentPath"]) safeRelativePath(value[key], key, "QUARANTINE_BLOCK");
  for (const key of ["quarantinedPath", "preservedPendingPath"]) {
    if (value[key] !== null) safeRelativePath(value[key], key, "QUARANTINE_BLOCK");
  }
  validateOwner(value.owner, "owner", "QUARANTINE_BLOCK");
  iso(value.blockedAt, "blockedAt", "QUARANTINE_BLOCK");
  exactIdentity(value, "quarantine block", "QUARANTINE_BLOCK");
  return value;
}

export function buildOperationPlan({ taskRoot, operationId, kind, cycleId, revision, sourceState, targetState, owner, operationLock, expectedActiveCycleIdentity, nextActiveCycle, artifacts }) {
  const root = canonicalTaskRoot(taskRoot);
  assertOperationLockHeld(operationLock, { taskRoot: root, owner });
  id(operationId, "operationId", "OPERATION_INTENT");
  validateOwner(owner, "owner", "OPERATION_INTENT");
  const pendingRoot = `.pending/${operationId}`;
  const entries = Object.entries(artifacts).map(([path, bytes]) => {
    safeRelativePath(path, "artifact path", "OPERATION_INTENT");
    const buffer = Buffer.isBuffer(bytes) ? bytes : Buffer.from(bytes);
    const destination = contained(root, join(root, path));
    const old = existsSync(destination) ? fileSha(destination) : null;
    return {
      value: buffer,
      plan: { path, pendingPath: `${pendingRoot}/${path}`, kind: path === "active-cycle.json" ? "active_cycle" : "artifact", mode: 384, expectedOldSha256: old, newSha256: sha256Bytes(buffer) },
    };
  });
  if (!Object.hasOwn(artifacts, "active-cycle.json")) fail("transaction обязана устанавливать active-cycle.json", "OPERATION_INTENT");
  const nextBytes = Buffer.isBuffer(artifacts["active-cycle.json"]) ? artifacts["active-cycle.json"] : Buffer.from(artifacts["active-cycle.json"]);
  const parsedNext = JSON.parse(nextBytes.toString("utf8"));
  if (parsedNext.identity !== nextActiveCycle.identity || parsedNext.identity !== jcsIdentity(parsedNext)) fail("next active cycle bytes не совпадают", "OPERATION_INTENT");
  const intent = buildIdentityArtifact({
    schemaVersion: 1,
    operationId,
    kind,
    cycleId,
    revision,
    sourceState,
    targetState,
    owner,
    expectedActiveCycleIdentity,
    nextActiveCycleIdentity: nextActiveCycle.identity,
    plannedArtifacts: entries.map((entry) => entry.plan),
    startedAt: new Date().toISOString(),
  });
  validateOperationIntent(intent);
  return { root, intent, entries };
}

function quarantineConflict(root, operationId, path) {
  const source = contained(root, join(root, path));
  const quarantineRoot = join(root, ".quarantine");
  mkdirSync(quarantineRoot, { recursive: true });
  const quarantineStat = lstatSync(quarantineRoot);
  if (!quarantineStat.isDirectory() || quarantineStat.isSymbolicLink() || realpathSync(quarantineRoot) !== quarantineRoot) {
    fail(".quarantine не является каноническим каталогом", "OPERATION_QUARANTINE");
  }
  const destination = join(quarantineRoot, `${operationId}-${path.replaceAll("/", "_")}-${randomBytes(4).toString("hex")}`);
  renameSync(source, destination);
  fsyncDirectory(dirname(source));
  fsyncDirectory(quarantineRoot);
  return relative(root, destination);
}

export function installOperationPlan(plan, { operationLock, owner, crashAfter = null, resume = false } = {}) {
  const { root, intent, entries } = plan;
  validateOperationIntent(intent);
  const assertHeld = () => assertOperationLockHeld(operationLock, { taskRoot: root, owner });
  assertHeld();
  const intentPath = join(root, ".operation-intent.json");
  if (existsSync(intentPath)) {
    if (!resume) fail("другая operation intent уже существует", "OPERATION_BUSY");
    const stat = lstatSync(intentPath);
    if (!stat.isFile() || stat.isSymbolicLink()) fail("operation intent имеет небезопасный тип", "OPERATION_QUARANTINE");
    let stored;
    try { stored = JSON.parse(readFileSync(intentPath, "utf8")); } catch { fail("operation intent повреждён", "OPERATION_UNKNOWN"); }
    validateOperationIntent(stored);
    if (stored.identity !== intent.identity) fail("recovery относится к другой operation intent", "OPERATION_BUSY");
  } else if (resume) {
    fail("recovery не нашёл operation intent", "OPERATION_UNKNOWN");
  }
  const pendingRoot = join(root, ".pending", intent.operationId);
  mkdirSync(pendingRoot, { recursive: true });
  let installed = 0;
  if (!resume) {
    for (const entry of entries) {
      assertHeld();
      const pending = contained(root, join(root, entry.plan.pendingPath));
      writeNoReplace(pending, entry.value, entry.plan.mode);
    }
    assertHeld();
    writeNoReplace(intentPath, Buffer.from(`${JSON.stringify(intent, null, 2)}\n`));
  }
  const ordered = [...entries].sort((left, right) => {
    if (left.plan.kind === "active_cycle") return 1;
    if (right.plan.kind === "active_cycle") return -1;
    const depth = left.plan.path.split("/").length - right.plan.path.split("/").length;
    return depth || left.plan.path.localeCompare(right.plan.path);
  });
  for (const entry of ordered) {
    assertHeld();
    const destination = contained(root, join(root, entry.plan.path));
    const pending = contained(root, join(root, entry.plan.pendingPath));
    mkdirSync(dirname(destination), { recursive: true });
    if (existsSync(destination)) {
      const stat = lstatSync(destination);
      if (!stat.isFile() || stat.isSymbolicLink()) {
        const quarantined = quarantineConflict(root, intent.operationId, entry.plan.path);
        fail(`конфликтующий объект перемещён в ${quarantined}`, "OPERATION_QUARANTINE", { conflictPath: entry.plan.path, quarantinedPath: quarantined });
      }
      const actual = fileSha(destination);
      if (actual === entry.plan.newSha256) {
        rmSync(pending, { force: true });
      } else if (actual !== entry.plan.expectedOldSha256) {
        const quarantined = quarantineConflict(root, intent.operationId, entry.plan.path);
        fail(`конфликтующий артефакт перемещён в ${quarantined}`, "OPERATION_QUARANTINE", { conflictPath: entry.plan.path, quarantinedPath: quarantined });
      } else {
        if (!existsSync(pending) || fileSha(pending) !== entry.plan.newSha256) fail("pending артефакт отсутствует или изменён", "OPERATION_QUARANTINE");
        renameSync(pending, destination);
      }
    } else {
      if (!existsSync(pending) || fileSha(pending) !== entry.plan.newSha256) fail("pending артефакт отсутствует или изменён", "OPERATION_QUARANTINE");
      renameSync(pending, destination);
    }
    fsyncFile(destination);
    fsyncDirectory(dirname(destination));
    installed += 1;
    if (crashAfter === installed) fail("инъекция падения self-test", "OPERATION_CRASH");
  }
  assertHeld();
  for (const entry of entries) if (!existsSync(join(root, entry.plan.path)) || fileSha(join(root, entry.plan.path)) !== entry.plan.newSha256) fail("операция установлена не полностью", "OPERATION_VERIFY");
  assertHeld();
  rmSync(intentPath);
  fsyncDirectory(root);
  assertHeld();
  rmSync(pendingRoot, { recursive: true, force: true });
  return { reused: false, identity: intent.identity };
}

export function recoverOperation(taskRoot, { operationLock, owner } = {}) {
  const root = canonicalTaskRoot(taskRoot);
  const assertHeld = () => assertOperationLockHeld(operationLock, { taskRoot: root, owner });
  assertHeld();
  const intentPath = join(root, ".operation-intent.json");
  if (!existsSync(intentPath)) return { status: "none" };
  const intent = readRegularJson(intentPath, "operation intent", "OPERATION_UNKNOWN");
  validateOperationIntent(intent);
  const entries = intent.plannedArtifacts.map((plan) => {
    const pendingPath = join(root, plan.pendingPath);
    return {
      plan,
      value: existsSync(pendingPath) ? readRegularBytes(pendingPath, `pending ${plan.pendingPath}`, "OPERATION_QUARANTINE") : null,
    };
  });
  const activePath = join(root, "active-cycle.json");
  const activeIdentity = existsSync(activePath) ? readRegularJson(activePath, "active-cycle.json", "OPERATION_QUARANTINE").identity : null;
  if (![intent.expectedActiveCycleIdentity, intent.nextActiveCycleIdentity].includes(activeIdentity)) fail("active cycle не совпадает ни с expected, ни с next", "OPERATION_QUARANTINE");
  for (const entry of entries) {
    const destination = join(root, entry.plan.path);
    if (existsSync(destination) && fileSha(destination) === entry.plan.newSha256) continue;
    if (entry.value === null || sha256Bytes(entry.value) !== entry.plan.newSha256) fail("pending артефакт отсутствует или изменён", "OPERATION_QUARANTINE");
  }
  if (activeIdentity === intent.nextActiveCycleIdentity && entries.every((entry) => existsSync(join(root, entry.plan.path)) && fileSha(join(root, entry.plan.path)) === entry.plan.newSha256)) {
    assertHeld();
    rmSync(intentPath);
    fsyncDirectory(root);
    assertHeld();
    rmSync(join(root, ".pending", intent.operationId), { recursive: true, force: true });
    return { status: "reused", identity: intent.identity };
  }
  return installOperationPlan(
    {
      root,
      intent,
      entries: entries.map((entry) => ({
        ...entry,
        value: entry.value ?? readRegularBytes(join(root, entry.plan.path), `installed ${entry.plan.path}`, "OPERATION_QUARANTINE"),
      })),
    },
    { operationLock, owner, resume: true },
  );
}

function jsonBytes(value) {
  return Buffer.from(`${JSON.stringify(value, null, 2)}\n`);
}

function readRegularJson(path, label, code = "CYCLE_SCHEMA") {
  const stat = existsSync(path) ? lstatSync(path) : null;
  if (!stat?.isFile() || stat.isSymbolicLink()) fail(`${label}: ожидался regular JSON-файл`, code);
  try {
    return JSON.parse(readFileSync(path, "utf8"));
  } catch {
    fail(`${label}: невалидный JSON`, code);
  }
}

function readRegularBytes(path, label, code = "CYCLE_SCHEMA") {
  const stat = existsSync(path) ? lstatSync(path) : null;
  if (!stat?.isFile() || stat.isSymbolicLink()) fail(`${label}: ожидался regular файл`, code);
  return readFileSync(path);
}

function readActiveCycle(root, states) {
  const active = readRegularJson(join(root, "active-cycle.json"), "active-cycle.json", "ACTIVE_CYCLE");
  validateActiveCycle(active, { states, taskRoot: root });
  validateSourceContext(active.sourceContext);
  validateSignalContext(active.signalContext);
  if (active.signalContext.sourceContextIdentity !== active.sourceContext.identity) fail("active cycle смешивает signal/source context", "ACTIVE_CYCLE");
  return active;
}

function installCycleTransaction({
  root,
  owner,
  operationLock,
  operationId,
  kind,
  cycleId,
  revision,
  sourceState,
  targetState,
  expectedActiveCycleIdentity,
  nextActiveCycle,
  artifacts,
  states,
  crashAfter = null,
}) {
  const currentBeforeInstall = expectedActiveCycleIdentity === null ? null : readActiveCycle(root, states);
  if (currentBeforeInstall !== null && currentBeforeInstall.identity !== expectedActiveCycleIdentity) {
    fail("active cycle изменился до построения transaction intent", "OPERATION_VERIFY");
  }
  const plan = buildOperationPlan({
    taskRoot: root,
    operationId,
    kind,
    cycleId,
    revision,
    sourceState,
    targetState,
    owner,
    operationLock,
    expectedActiveCycleIdentity,
    nextActiveCycle,
    artifacts: { ...artifacts, "active-cycle.json": jsonBytes(nextActiveCycle) },
  });
  const result = installPreparedCyclePlan({
    plan,
    currentBeforeInstall,
    operationLock,
    owner,
    states,
    crashAfter,
  });
  const installed = readActiveCycle(root, states);
  if (installed.identity !== nextActiveCycle.identity) fail("transaction установила другой active cycle", "OPERATION_VERIFY");
  return { ...result, activeCycle: installed, operationIdentity: plan.intent.identity };
}

function initialActiveCycle({ cycleId, owner, sourceContext, signalContext, state = "C02" }) {
  const active = buildIdentityArtifact({
    schemaVersion: 1,
    cycleId,
    revision: 0,
    state,
    activeRunId: null,
    lastCompletedRun: null,
    owner: structuredClone(owner),
    sourceContext: structuredClone(sourceContext),
    signalContext: structuredClone(signalContext),
    resumeContexts: [],
    lockPath: ".operation.lock",
  });
  return active;
}

function openCycleUnderLock({ root, cycleId, ledger, sourceContext, owner, operationLock, states }) {
  if (existsSync(join(root, "active-cycle.json"))) fail("open_cycle допустим только без active-cycle.json", "OPEN_CYCLE");
  const targetState = transitionTargetForOperation("open_cycle", null, {}, { states });
  const next = initialActiveCycle({ cycleId, owner, sourceContext, signalContext: ledger.signalContext, state: targetState });
  validateActiveCycle(next, { states });
  const operationId = `open-cycle-${cycleId}-${ledger.signalId}`;
  return installCycleTransaction({
    root,
    owner,
    operationLock,
    operationId,
    kind: "open_cycle",
    cycleId,
    revision: 0,
    sourceState: null,
    targetState,
    expectedActiveCycleIdentity: null,
    nextActiveCycle: next,
    artifacts: { [`cycles/${cycleId}/signals/${ledger.signalId}.json`]: jsonBytes(ledger) },
    states,
  });
}

function frameForHolding({ current, holdingState, targetState, signalIdentity, reason }) {
  const policy = targetState === "C12" ? "restore_exact" : ["P03", "G01"].includes(targetState) ? "new" : "none";
  return buildIdentityArtifact({
    schemaVersion: 1,
    frameId: `resume-${sha256Bytes(Buffer.from(canonicalJson([current.identity, holdingState, targetState, signalIdentity, reason]))).slice(0, 24)}`,
    cycleId: current.cycleId,
    revision: current.revision,
    register: holdingState === "B01" ? "return_state" : "resume_state",
    sourceState: current.state,
    holdingState,
    targetState,
    runPolicy: policy,
    savedRunId: current.activeRunId,
    gateIdentity: null,
    requestedAction: null,
    executor: "agent",
    owner: holdingState === "D01" ? "user" : "provider/operator",
    unblockEvent: reason,
    stopMode: holdingState === "D01" ? "decision_required" : "blocked",
    signalIdentity,
    sourceContextIdentity: current.sourceContext.identity,
  });
}

function preserveQuarantinedOperationAndBlock({ root, intent, current, operationLock, owner, states, error }) {
  assertOperationLockHeld(operationLock, { taskRoot: root, owner });
  if (current === null || current.identity !== intent.expectedActiveCycleIdentity) {
    fail("quarantine не может доказать исходный active cycle", "OPERATION_QUARANTINE");
  }
  const quarantineRoot = join(root, ".quarantine");
  mkdirSync(quarantineRoot, { recursive: true });
  const quarantineStat = lstatSync(quarantineRoot);
  if (!quarantineStat.isDirectory() || quarantineStat.isSymbolicLink() || realpathSync(quarantineRoot) !== quarantineRoot) {
    fail(".quarantine не является каноническим каталогом", "OPERATION_QUARANTINE");
  }
  const bundle = join(quarantineRoot, `operation-${intent.operationId}-${randomBytes(6).toString("hex")}`);
  mkdirSync(bundle, { recursive: false, mode: 0o700 });
  const intentPath = join(root, ".operation-intent.json");
  const preservedIntent = join(bundle, "operation-intent.json");
  if (!existsSync(intentPath)) fail("operation intent исчез до quarantine block", "OPERATION_QUARANTINE");
  renameSync(intentPath, preservedIntent);
  const pendingRoot = join(root, ".pending", intent.operationId);
  const preservedPending = join(bundle, "pending");
  let preservedPendingPath = null;
  if (existsSync(pendingRoot)) {
    renameSync(pendingRoot, preservedPending);
    preservedPendingPath = relative(root, preservedPending);
  }
  fsyncDirectory(root);
  fsyncDirectory(quarantineRoot);
  fsyncDirectory(bundle);

  const targetState = transitionTargetForOperation("transition_state", current.state, { reason: "operation_conflict" }, { states });
  const frame = frameForHolding({
    current,
    holdingState: targetState,
    targetState: current.state === "B01"
      ? (current.resumeContexts.at(-1)?.targetState ?? "C02")
      : (states[current.state]?.next ?? []).includes("B01") ? current.state : "C02",
    signalIdentity: current.signalContext.identity,
    reason: `operation_conflict:${intent.operationId}`,
  });
  const pushed = pushResumeFrame(current.resumeContexts, frame);
  const resumeContexts = pushed.ok ? pushed.stack : structuredClone(current.resumeContexts);
  const blocked = buildIdentityArtifact({
    ...current,
    state: targetState,
    activeRunId: null,
    lastCompletedRun: null,
    owner: structuredClone(owner),
    resumeContexts,
  });
  validateActiveCycle(blocked, { states });

  const quarantineBlock = buildIdentityArtifact({
    schemaVersion: 1,
    operationId: intent.operationId,
    operationIntentIdentity: intent.identity,
    cycleId: intent.cycleId,
    revision: intent.revision,
    sourceState: current.state,
    blockedState: targetState,
    conflictPath: error.conflictPath ?? ".operation-intent.json",
    quarantinedPath: error.quarantinedPath ?? null,
    preservedIntentPath: relative(root, preservedIntent),
    preservedPendingPath,
    owner: structuredClone(owner),
    blockedAt: new Date().toISOString(),
  });
  validateQuarantineBlock(quarantineBlock);
  const blockerOperationId = `block-${intent.operationId}-${quarantineBlock.identity.slice(0, 16)}`;
  const blockerPlan = buildOperationPlan({
    taskRoot: root,
    operationId: blockerOperationId,
    kind: "transition_state",
    cycleId: intent.cycleId,
    revision: intent.revision,
    sourceState: current.state,
    targetState,
    owner,
    operationLock,
    expectedActiveCycleIdentity: current.identity,
    nextActiveCycle: blocked,
    artifacts: {
      [`cycles/${intent.cycleId}/quarantine-blocks/${intent.operationId}.json`]: jsonBytes(quarantineBlock),
      "active-cycle.json": jsonBytes(blocked),
    },
  });
  installOperationPlan(blockerPlan, { operationLock, owner });
  return { blocked, quarantineBlock };
}

function installPreparedCyclePlan({ plan, currentBeforeInstall, operationLock, owner, states, crashAfter = null }) {
  try {
    return installOperationPlan(plan, { operationLock, owner, crashAfter });
  } catch (error) {
    if (error?.code !== "OPERATION_QUARANTINE") throw error;
    const result = preserveQuarantinedOperationAndBlock({
      root: plan.root,
      intent: plan.intent,
      current: currentBeforeInstall,
      operationLock,
      owner,
      states,
      error,
    });
    error.blockedState = result.blocked.state;
    error.quarantineBlockIdentity = result.quarantineBlock.identity;
    throw error;
  }
}

function blockSignalUnderLock({ root, current, owner, operationLock, states, signalIdentity, reason, returnState = current.state }) {
  const updatingHoldingEvidence = current.state === "B01";
  const effectiveReturnState = updatingHoldingEvidence
    ? (current.resumeContexts.at(-1)?.targetState ?? returnState)
    : returnState;
  const targetState = transitionTargetForOperation("register_signal", current.state, {
    stable: false,
    holdingEvidenceUpdate: updatingHoldingEvidence,
  }, { states });
  const frame = frameForHolding({
    current,
    holdingState: "B01",
    targetState: effectiveReturnState,
    signalIdentity: current.signalContext.identity,
    reason,
  });
  let next;
  if (updatingHoldingEvidence) {
    const pushed = pushResumeFrame(current.resumeContexts, frame);
    if (!pushed.ok || pushed.replaced !== true) fail("B01 evidence update не заменил верхний resume frame", "SIGNAL_CAPTURE");
    next = buildIdentityArtifact({ ...current, owner: structuredClone(owner), resumeContexts: pushed.stack });
  } else {
    next = transitionActiveCycle({
      current,
      expectedIdentity: current.identity,
      nextState: targetState,
      nextRunId: null,
      states,
      operationLock,
      taskRoot: root,
      owner,
      holdingFrame: frame,
    });
  }
  validateActiveCycle(next, { states });
  return installCycleTransaction({
    root,
    owner,
    operationLock,
    operationId: `block-signal-${sha256Bytes(Buffer.from(canonicalJson([current.identity, signalIdentity, reason]))).slice(0, 24)}`,
    kind: "register_signal",
    cycleId: current.cycleId,
    revision: current.revision,
    sourceState: current.state,
    targetState,
    expectedActiveCycleIdentity: current.identity,
    nextActiveCycle: next,
    artifacts: {},
    states,
  });
}

function registerStableSignalUnderLock({ root, cycleId, ledger, sourceContext, owner, operationLock, states }) {
  if (!existsSync(join(root, "active-cycle.json"))) {
    return { status: "registered", ledger, ...openCycleUnderLock({ root, cycleId, ledger, sourceContext, owner, operationLock, states }) };
  }
  const current = readActiveCycle(root, states);
  if (current.cycleId !== cycleId) fail("active cycle относится к другому cycleId", "SIGNAL_CAPTURE");
  const signalPath = join(root, "cycles", cycleId, "signals", `${ledger.signalId}.json`);
  if (existsSync(signalPath)) {
    const existing = readRegularJson(signalPath, "signal ledger", "SIGNAL_LEDGER");
    validateSignalLedger(existing);
    if (existing.identity === ledger.identity) return { status: "reused", ledger: existing, activeCycle: current };
    const blocked = blockSignalUnderLock({ root, current, owner, operationLock, states, signalIdentity: ledger.signalContext.identity, reason: "signal_id_payload_mismatch" });
    return { status: "blocked", state: "B01", returnState: current.state, reason: "signal_id_payload_mismatch", ...blocked };
  }
  if (!["C01", "C02"].includes(current.state)) fail("новый signal ledger регистрируется только из C01/C02", "SIGNAL_CAPTURE");
  const targetState = transitionTargetForOperation("register_signal", current.state, { stable: true }, { states });
  const transitioned = transitionActiveCycle({
    current,
    expectedIdentity: current.identity,
    nextState: targetState,
    nextRunId: null,
    states,
    operationLock,
    taskRoot: root,
    owner,
  });
  const next = buildIdentityArtifact({ ...transitioned, sourceContext: structuredClone(sourceContext), signalContext: structuredClone(ledger.signalContext) });
  validateActiveCycle(next, { states });
  const transaction = installCycleTransaction({
    root,
    owner,
    operationLock,
    operationId: `register-signal-${ledger.signalId}`,
    kind: "register_signal",
    cycleId,
    revision: current.revision,
    sourceState: current.state,
    targetState,
    expectedActiveCycleIdentity: current.identity,
    nextActiveCycle: next,
    artifacts: { [`cycles/${cycleId}/signals/${ledger.signalId}.json`]: jsonBytes(ledger) },
    states,
  });
  return { status: "registered", ledger, ...transaction };
}

export async function registerSignalCycle({ taskRoot, cycleId, signalId, captureProvider, owner = null, states = readRegistry().states }) {
  const root = canonicalTaskRoot(taskRoot);
  id(cycleId, "cycleId", "SIGNAL_CAPTURE");
  id(signalId, "signalId", "SIGNAL_CAPTURE");
  if (typeof captureProvider !== "function") fail("captureProvider обязателен", "SIGNAL_CAPTURE");
  const operationOwner = owner ?? await createOperationOwner(`register-signal-${signalId}`);
  validateOwner(operationOwner, "owner", "SIGNAL_CAPTURE");
  const operationLock = acquireOperationLock({ taskRoot: root, owner: operationOwner });
  try {
    if (existsSync(join(root, ".operation-intent.json"))) recoverOperation(root, { operationLock, owner: operationOwner });
    let previousRevision = null;
    let latest = null;
    for (let attempt = 1; attempt <= 3; attempt += 1) {
      assertOperationLockHeld(operationLock, { taskRoot: root, owner: operationOwner });
      const captured = await captureProvider({ attempt, ifProviderRevision: previousRevision });
      exactKeys(captured, ["providerRevision", "ledger", "sourceContext"], "provider capture", "SIGNAL_CAPTURE");
      validateSignalLedger(captured.ledger);
      validateSourceContext(captured.sourceContext);
      if (captured.ledger.signalEvidence.checkRunId !== null && captured.ledger.ciSnapshot === null) {
        fail("сигнал обязательной CI-проверки не содержит CI snapshot", "MUTABLE_SNAPSHOT_EVIDENCE");
      }
      if (captured.ledger.signalEvidence.reviewDelegationId !== null && captured.ledger.delegationSnapshot === null) {
        fail("сигнал делегированной проверки не содержит delegation snapshot", "MUTABLE_SNAPSHOT_EVIDENCE");
      }
      validateMutableSnapshotEvidence({
        reviewSnapshot: captured.ledger.reviewSnapshot,
        ciSnapshot: captured.ledger.ciSnapshot,
        delegationSnapshot: captured.ledger.delegationSnapshot,
      });
      if (captured.ledger.cycleId !== cycleId || captured.ledger.signalId !== signalId || captured.ledger.sourceContextIdentity !== captured.sourceContext.identity) fail("provider capture относится к другому циклу", "SIGNAL_CAPTURE");
      if (String(captured.providerRevision) !== String(captured.ledger.signalEvidence.providerRevision)) fail("providerRevision рассогласован", "SIGNAL_CAPTURE");
      if (captured.sourceContext.reviewSnapshotIdentity !== captured.ledger.reviewSnapshot.identity) fail("source context относится к другому review snapshot", "SIGNAL_CAPTURE");
      latest = captured;
      if (previousRevision !== null && String(captured.providerRevision) === String(previousRevision)) {
        return registerStableSignalUnderLock({ root, cycleId, ledger: captured.ledger, sourceContext: captured.sourceContext, owner: operationOwner, operationLock, states });
      }
      previousRevision = captured.providerRevision;
    }
    if (latest !== null && !existsSync(join(root, "active-cycle.json"))) {
      openCycleUnderLock({ root, cycleId, ledger: latest.ledger, sourceContext: latest.sourceContext, owner: operationOwner, operationLock, states });
    }
    const current = readActiveCycle(root, states);
    const blocked = blockSignalUnderLock({ root, current, owner: operationOwner, operationLock, states, signalIdentity: latest.ledger.signalContext.identity, reason: "provider_snapshot_stable", returnState: "C02" });
    return { status: "blocked", state: "B01", returnState: "C02", owner: "provider/operator", unblockEvent: "provider_snapshot_stable", ...blocked };
  } finally {
    releaseOperationLock(operationLock);
  }
}

function qualificationResumeFrame({ current, targetState, qualificationIdentity }) {
  const target = targetState === "B01" ? "P03" : "C07";
  return frameForHolding({
    current,
    holdingState: targetState,
    targetState: target,
    signalIdentity: current.signalContext.identity,
    reason: targetState === "B01" ? "review_qualification_resolved" : "user_scope_or_risk_decision",
  });
}

function revisionOpenFor({ current, owner, sourceState = current.state }) {
  const artifact = buildIdentityArtifact({
    schemaVersion: 1,
    cycleId: current.cycleId,
    revision: current.revision + 1,
    previousRevision: current.revision,
    sourceState,
    signalIdentity: current.signalContext.identity,
    sourceContext: structuredClone(current.sourceContext),
    openedBy: structuredClone(owner),
  });
  validateRevisionOpen(artifact);
  return artifact;
}

function nextCycleForAuthorReview({ current, targetState, reviewRunId, operationLock, owner, root, states }) {
  const frame = targetState === "D01"
    ? frameForHolding({
      current,
      holdingState: "D01",
      targetState: "C07",
      signalIdentity: current.signalContext.identity,
      reason: "user_scope_or_risk_decision",
    })
    : null;
  const pushed = frame === null ? null : pushResumeFrame(current.resumeContexts, frame);
  if (pushed && !pushed.ok) fail("resume stack переполнен перед D01", "AUTHOR_REVIEW_TRANSACTION");
  let next = transitionActiveCycle({
    current,
    expectedIdentity: current.identity,
    nextState: targetState,
    nextRunId: targetState === "P03" ? reviewRunId : null,
    states,
    operationLock,
    taskRoot: root,
    owner,
    deferRunPath: targetState === "P03",
  });
  let revisionOpen = null;
  if (targetState === "C07") {
    revisionOpen = revisionOpenFor({ current, owner });
    next = buildIdentityArtifact({ ...next, revision: revisionOpen.revision });
  } else if (targetState === "D01") {
    next = buildIdentityArtifact({ ...next, resumeContexts: pushed.stack });
  }
  validateActiveCycle(next, { states });
  return { next, revisionOpen };
}

export async function sealAndOpenReviewTransaction({ taskRoot, findings, prepareReviewRun, states = readRegistry().states }) {
  if (!Array.isArray(findings)) fail("findings должен быть массивом", "AUTHOR_REVIEW_TRANSACTION");
  const root = canonicalTaskRoot(taskRoot);
  const owner = await createOperationOwner(`seal-author-${randomBytes(12).toString("hex")}`);
  const operationLock = acquireOperationLock({ taskRoot: root, owner });
  try {
    if (existsSync(join(root, ".operation-intent.json"))) {
      const recovery = recoverOperation(root, { operationLock, owner });
      return { status: "recovered", activeCycle: readActiveCycle(root, states), recovery };
    }
    const current = readActiveCycle(root, states);
    if (current.state !== "C08" || current.revision < 1) fail("seal_author_review требует active C08", "AUTHOR_REVIEW_TRANSACTION");
    const revisionPrefix = `cycles/${current.cycleId}/revision-${current.revision}`;
    const revisionRoot = join(root, revisionPrefix);
    const revisionOpen = readRegularJson(join(revisionRoot, "revision-open.json"), "revision-open", "AUTHOR_REVIEW_TRANSACTION");
    validateRevisionOpen(revisionOpen);
    if (revisionOpen.cycleId !== current.cycleId || revisionOpen.revision !== current.revision
      || revisionOpen.sourceContext.identity !== current.sourceContext.identity
      || revisionOpen.signalIdentity !== current.signalContext.identity) {
      fail("revision-open относится к другому active cycle", "AUTHOR_REVIEW_TRANSACTION");
    }
    const tzBytes = readRegularBytes(join(revisionRoot, "tz.md"), "tz.md", "AUTHOR_REVIEW_TRANSACTION");
    const authorReviewBytes = readRegularBytes(join(revisionRoot, "author-review.md"), "author-review.md", "AUTHOR_REVIEW_TRANSACTION");
    if (tzBytes.length === 0 || authorReviewBytes.length === 0) fail("ТЗ и авторское ревью должны быть непустыми", "AUTHOR_REVIEW_TRANSACTION");
    const reviewedAt = new Date().toISOString();
    const authorReviewResult = buildIdentityArtifact({
      schemaVersion: 1,
      cycleId: current.cycleId,
      revision: current.revision,
      tzSha256: sha256Bytes(tzBytes),
      authorReviewSha256: sha256Bytes(authorReviewBytes),
      findings: structuredClone(findings),
      outcomeState: transitionTargetForOperation("seal_author_review", current.state, { findings }, { states }),
      reviewedBy: structuredClone(owner),
      reviewedAt,
    });
    validateAuthorReviewResult(authorReviewResult);
    const targetState = authorReviewResult.outcomeState;
    const revisionSeal = buildIdentityArtifact({
      schemaVersion: 1,
      cycleId: current.cycleId,
      revision: current.revision,
      revisionOpenIdentity: revisionOpen.identity,
      tzSha256: authorReviewResult.tzSha256,
      authorReviewSha256: authorReviewResult.authorReviewSha256,
      authorReviewResultIdentity: authorReviewResult.identity,
      sourceContextIdentity: current.sourceContext.identity,
      outcomeState: targetState,
      sealedBy: structuredClone(owner),
      sealedAt: reviewedAt,
    });
    validateRevisionSeal(revisionSeal);
    const reviewRunId = targetState === "P03" ? `review-${randomBytes(12).toString("hex")}` : null;
    const outcome = nextCycleForAuthorReview({ current, targetState, reviewRunId, operationLock, owner, root, states });
    const artifacts = {
      [`${revisionPrefix}/author-review-result.json`]: jsonBytes(authorReviewResult),
      [`${revisionPrefix}/revision-seal.json`]: jsonBytes(revisionSeal),
    };
    let runOpen = null;
    let reviewManifest = null;
    let launchIntent = null;
    if (targetState === "P03") {
      if (typeof prepareReviewRun !== "function") fail("P03 требует доверенный адаптер подготовки review run", "AUTHOR_REVIEW_TRANSACTION");
      const prepared = await prepareReviewRun({
        taskRoot: root,
        cycleId: current.cycleId,
        revision: current.revision,
        reviewRunId,
        sourceContext: structuredClone(current.sourceContext),
        revisionSeal: structuredClone(revisionSeal),
        tzBytes: Buffer.from(tzBytes),
        authorReviewBytes: Buffer.from(authorReviewBytes),
        owner: structuredClone(owner),
      });
      exactKeys(prepared, ["reviewManifest", "launchIntent"], "prepared review run", "AUTHOR_REVIEW_TRANSACTION");
      reviewManifest = validateReviewManifest(prepared.reviewManifest, { specBytes: tzBytes, authorReviewBytes });
      launchIntent = validateLaunchIntent(prepared.launchIntent);
      if (reviewManifest.base !== current.sourceContext.baseOid || reviewManifest.head !== current.sourceContext.inputHeadOid
        || launchIntent.cycleId !== current.cycleId || launchIntent.revision !== current.revision
        || launchIntent.reviewRunId !== reviewRunId || launchIntent.reviewManifestIdentity !== reviewManifest.identity
        || launchIntent.executableRealPath !== reviewManifest.environmentEvidence.executableRealPath
        || launchIntent.executableSha256 !== reviewManifest.environmentEvidence.executableSha256
        || launchIntent.settingsCheckpointSha256 !== reviewManifest.environmentEvidence.settingsCheckpointSha256
        || launchIntent.processPolicyIdentity !== identityOf(reviewManifest.processPolicy)) {
        fail("prepared review run относится к другому снимку", "AUTHOR_REVIEW_TRANSACTION");
      }
      runOpen = buildIdentityArtifact({
        schemaVersion: 1,
        cycleId: current.cycleId,
        revision: current.revision,
        reviewRunId,
        revisionSealIdentity: revisionSeal.identity,
        sourceContextIdentity: current.sourceContext.identity,
        reviewSnapshotIdentity: current.sourceContext.reviewSnapshotIdentity,
        openedBy: structuredClone(owner),
        openedAt: reviewedAt,
      });
      validateReviewRunOpen(runOpen);
      const runPrefix = `${revisionPrefix}/review-runs/${reviewRunId}`;
      artifacts[`${runPrefix}/run-open.json`] = jsonBytes(runOpen);
      artifacts[`${runPrefix}/review-manifest.json`] = jsonBytes(reviewManifest);
      artifacts[`${runPrefix}/launch-intent.json`] = jsonBytes(launchIntent);
    } else if (prepareReviewRun !== undefined) {
      fail("prepareReviewRun передан для исхода без внешнего review", "AUTHOR_REVIEW_TRANSACTION");
    }
    if (outcome.revisionOpen !== null) {
      artifacts[`cycles/${current.cycleId}/revision-${outcome.revisionOpen.revision}/revision-open.json`] = jsonBytes(outcome.revisionOpen);
    }
    const transaction = installCycleTransaction({
      root,
      owner,
      operationLock,
      operationId: `seal-author-${reviewRunId ?? authorReviewResult.identity.slice(0, 24)}`,
      kind: targetState === "P03" ? "seal_and_open_review" : "seal_author_review",
      cycleId: current.cycleId,
      revision: current.revision,
      sourceState: current.state,
      targetState,
      expectedActiveCycleIdentity: current.identity,
      nextActiveCycle: outcome.next,
      artifacts,
      states,
    });
    return {
      status: "sealed",
      target: targetState,
      reviewRunId,
      authorReviewResultIdentity: authorReviewResult.identity,
      revisionSealIdentity: revisionSeal.identity,
      reviewManifestIdentity: reviewManifest?.identity ?? null,
      launchIntentIdentity: launchIntent?.identity ?? null,
      activeCycle: transaction.activeCycle,
    };
  } finally {
    releaseOperationLock(operationLock);
  }
}

function nextCycleForQualification({ current, targetState, operationLock, owner, root, states, qualificationIdentity }) {
  let effectiveTarget = targetState;
  let frame = ["D01", "B01"].includes(effectiveTarget)
    ? qualificationResumeFrame({ current, targetState: effectiveTarget, qualificationIdentity })
    : null;
  let pushed = frame === null ? null : pushResumeFrame(current.resumeContexts, frame);
  if (effectiveTarget === "D01" && !pushed.ok) {
    effectiveTarget = "B01";
    frame = qualificationResumeFrame({ current, targetState: "B01", qualificationIdentity });
    pushed = pushResumeFrame(current.resumeContexts, frame);
  }
  let transitioned = transitionActiveCycle({
    current,
    expectedIdentity: current.identity,
    nextState: effectiveTarget,
    nextRunId: null,
    states,
    operationLock,
    taskRoot: root,
    owner,
    holdingFrame: effectiveTarget === "B01" ? frame : null,
  });
  let revisionOpen = null;
  if (effectiveTarget === "C07") {
    revisionOpen = revisionOpenFor({ current, owner });
    transitioned = buildIdentityArtifact({ ...transitioned, revision: revisionOpen.revision });
  } else if (effectiveTarget === "D01") {
    transitioned = buildIdentityArtifact({ ...transitioned, resumeContexts: pushed.stack });
  }
  validateActiveCycle(transitioned, { states });
  return { next: transitioned, targetState: effectiveTarget, revisionOpen };
}

export async function qualifyReviewTransaction({ taskRoot, dispositions, author = "Codex", crashAfter = null, states = readRegistry().states }) {
  const root = canonicalTaskRoot(taskRoot);
  const preliminary = readRegularJson(join(root, "active-cycle.json"), "active-cycle.json", "QUALIFICATION_TRANSACTION");
  const operationOwner = await createOperationOwner(`qualify-${preliminary.activeRunId ?? "missing-run"}`);
  const operationLock = acquireOperationLock({ taskRoot: root, owner: operationOwner });
  try {
    if (existsSync(join(root, ".operation-intent.json"))) {
      const recovered = recoverOperation(root, { operationLock, owner: operationOwner });
      const activeCycle = readActiveCycle(root, states);
      return { status: "recovered", target: activeCycle.state, activeCycle, recovery: recovered };
    }
    const current = readActiveCycle(root, states);
    if (current.state !== "P03" || current.activeRunId === null || current.revision < 1) fail("qualify_review требует active P03 review-run", "QUALIFICATION_TRANSACTION");
    const prepared = prepareQualificationArtifacts({
      taskRoot: root,
      cycleId: current.cycleId,
      revision: current.revision,
      runId: current.activeRunId,
      dispositions,
      author,
    });
    const policyTarget = transitionTargetForOperation("qualify_review", current.state, {
      dispositions: prepared.qualification.dispositions.map((item) => item.decision),
    }, { states });
    if (prepared.target !== policyTarget) fail("review qualification расходится с единой policy", "QUALIFICATION_TRANSACTION");
    const outcome = nextCycleForQualification({
      current,
      targetState: policyTarget,
      operationLock,
      owner: operationOwner,
      root,
      states,
      qualificationIdentity: prepared.qualificationIdentity,
    });
    const runPrefix = `cycles/${current.cycleId}/revision-${current.revision}/review-runs/${current.activeRunId}`;
    const artifacts = Object.fromEntries(Object.entries(prepared.resultArtifacts).map(([name, bytes]) => [`${runPrefix}/results/${name}`, bytes]));
    if (outcome.targetState === "C09") {
      const final = buildReviewFinalArtifacts({
        revisionRoot: prepared.revisionRoot,
        reviewManifestBytes: prepared.reviewManifestBytes,
        claudeBytes: prepared.claudeBytes,
        qualificationBytes: prepared.qualificationBytes,
        consolidatedBytes: prepared.consolidatedBytes,
      });
      for (const [name, bytes] of Object.entries(final)) artifacts[`cycles/${current.cycleId}/revision-${current.revision}/review-final/${name}`] = bytes;
    }
    if (outcome.revisionOpen !== null) {
      artifacts[`cycles/${current.cycleId}/revision-${outcome.revisionOpen.revision}/revision-open.json`] = jsonBytes(outcome.revisionOpen);
    }
    const transaction = installCycleTransaction({
      root,
      owner: operationOwner,
      operationLock,
      operationId: `qualify-${current.activeRunId}`,
      kind: "qualify_review",
      cycleId: current.cycleId,
      revision: current.revision,
      sourceState: "P03",
      targetState: outcome.targetState,
      expectedActiveCycleIdentity: current.identity,
      nextActiveCycle: outcome.next,
      artifacts,
      states,
      crashAfter,
    });
    if (outcome.targetState === "C09") verifyFinalDirectory(join(root, "cycles", current.cycleId, `revision-${current.revision}`, "review-final"));
    return {
      status: "qualified",
      target: outcome.targetState,
      qualificationIdentity: prepared.qualificationIdentity,
      activeCycle: transaction.activeCycle,
      operationIdentity: transaction.operationIdentity,
    };
  } finally {
    releaseOperationLock(operationLock);
  }
}

export async function enterPublicationBoundary({ taskRoot, states = readRegistry().states }) {
  const root = canonicalTaskRoot(taskRoot);
  const publicationRunId = `publication-${randomBytes(12).toString("hex")}`;
  const operationOwner = await createOperationOwner(`enter-publication-${publicationRunId}`);
  const operationLock = acquireOperationLock({ taskRoot: root, owner: operationOwner });
  try {
    if (existsSync(join(root, ".operation-intent.json"))) {
      const recovered = recoverOperation(root, { operationLock, owner: operationOwner });
      const activeCycle = readActiveCycle(root, states);
      return { status: "recovered", publicationRunId: activeCycle.activeRunId, activeCycle, recovery: recovered };
    }
    const current = readActiveCycle(root, states);
    if (current.state !== "C11" || current.revision < 1) fail("publication boundary открывается только из C11", "PUBLICATION_BOUNDARY");
    const revisionPrefix = `cycles/${current.cycleId}/revision-${current.revision}`;
    const revisionRoot = join(root, revisionPrefix);
    const implementationGatePath = join(revisionRoot, "implementation-gate.json");
    const finalManifestPath = join(revisionRoot, "review-final", "manifest.json");
    const tzPath = join(revisionRoot, "tz.md");
    const classificationPath = join(revisionRoot, "implementation-spec-classification.json");
    const implementationGate = readRegularJson(implementationGatePath, "implementation gate", "PUBLICATION_BOUNDARY");
    const classification = readRegularJson(classificationPath, "implementation Spec classification", "PUBLICATION_BOUNDARY");
    const verifiedFinalManifest = verifyFinalDirectory(join(revisionRoot, "review-final"));
    const finalManifestBytes = readRegularBytes(finalManifestPath, "review-final manifest", "PUBLICATION_BOUNDARY");
    const tzBytes = readRegularBytes(tzPath, "tz.md", "PUBLICATION_BOUNDARY");
    const rereadFinalManifest = JSON.parse(finalManifestBytes.toString("utf8"));
    if (verifiedFinalManifest.identity !== rereadFinalManifest.identity) {
      fail("проверенный review-final manifest изменился после проверки", "PUBLICATION_BOUNDARY");
    }
    if (verifiedFinalManifest.artifacts["tz.md"] !== sha256Bytes(tzBytes)) {
      fail("review-final относится к другому ТЗ текущей редакции", "PUBLICATION_BOUNDARY");
    }
    const decisionRoot = join(root, "cycles", current.cycleId, "decisions");
    const decisionEntries = existsSync(decisionRoot) ? readdirSync(decisionRoot).sort() : [];
    const implementationDecision = decisionEntries
      .filter((name) => name.endsWith(".json"))
      .map((name) => readRegularJson(join(decisionRoot, name), `decision ${name}`, "PUBLICATION_BOUNDARY"))
      .find((decision) => decision.identity === implementationGate.implementationDecisionIdentity);
    if (!implementationDecision) fail("implementation gate не связан с решением из ledger", "PUBLICATION_BOUNDARY");
    validateDecision(implementationDecision);
    if (implementationDecision.cycleId !== current.cycleId
      || implementationDecision.revision !== current.revision
      || implementationDecision.sourceContextIdentity !== current.sourceContext.identity) {
      fail("решение реализации относится к другому cycle/revision/source", "PUBLICATION_BOUNDARY");
    }
    validateImplementationGate(implementationGate, {
      finalManifestBytes,
      tzBytes,
      classification,
      cycleId: current.cycleId,
      revision: current.revision,
      base: current.sourceContext.baseOid,
      inputHead: current.sourceContext.inputHeadOid,
      implementationDecisionIdentity: implementationDecision.identity,
    });
    if (!implementationDecision.authorizedActions.every((action) => ACTIONS.has(action))) {
      fail("решение реализации содержит неизвестное действие", "PUBLICATION_BOUNDARY");
    }
    const gateReference = {
      kind: "implementation",
      issuer: "G00",
      artifact: "implementation-gate.json",
      identity: implementationGate.identity,
    };
    const targetState = transitionTargetForOperation("enter_publication_boundary", current.state, {}, { states });
    const next = transitionActiveCycle({
      current,
      expectedIdentity: current.identity,
      nextState: targetState,
      nextRunId: publicationRunId,
      states,
      operationLock,
      taskRoot: root,
      owner: operationOwner,
      gateEvidence: gateReference,
      deferRunPath: true,
    });
    const runOpen = buildIdentityArtifact({
      schemaVersion: 1,
      cycleId: current.cycleId,
      revision: current.revision,
      publicationRunId,
      implementationGateIdentity: implementationGate.identity,
      sourceContextIdentity: current.sourceContext.identity,
      publishBase: current.sourceContext.baseOid,
      openedBy: structuredClone(operationOwner),
      openedAt: new Date().toISOString(),
    });
    validatePublicationRunOpen(runOpen);
    const transaction = installCycleTransaction({
      root,
      owner: operationOwner,
      operationLock,
      operationId: `enter-publication-${publicationRunId}`,
      kind: "enter_publication_boundary",
      cycleId: current.cycleId,
      revision: current.revision,
      sourceState: "C11",
      targetState,
      expectedActiveCycleIdentity: current.identity,
      nextActiveCycle: next,
      artifacts: { [`${revisionPrefix}/publication-runs/${publicationRunId}/run-open.json`]: jsonBytes(runOpen) },
      states,
    });
    const runRoot = join(root, revisionPrefix, "publication-runs", publicationRunId);
    const entries = readdirSync(runRoot).sort();
    if (canonicalJson(entries) !== canonicalJson(["run-open.json"])) fail("новый publication run содержит лишние артефакты", "PUBLICATION_BOUNDARY");
    return { status: "opened", publicationRunId, runOpenIdentity: runOpen.identity, activeCycle: transaction.activeCycle };
  } finally {
    releaseOperationLock(operationLock);
  }
}

export function validateNoResultClosure(value) {
  exactKeys(value, ["schemaVersion", "terminalOutcome", "issueClosure", "specClosure", "cleanup", "identity"], "no-result closure", "NO_RESULT_CLOSURE");
  if (value.schemaVersion !== 1) fail("no-result closure schemaVersion должен быть 1", "NO_RESULT_CLOSURE");
  for (const key of ["terminalOutcome", "issueClosure", "specClosure", "cleanup"]) {
    exactKeys(value[key], ["status", "evidenceIdentity"], key, "NO_RESULT_CLOSURE");
    text(value[key].status, `${key}.status`, "NO_RESULT_CLOSURE");
    sha(value[key].evidenceIdentity, `${key}.evidenceIdentity`, "NO_RESULT_CLOSURE");
  }
  if (value.terminalOutcome.status !== "cancelled_without_materialization") fail("terminalOutcome не доказывает отсутствие materialization", "NO_RESULT_CLOSURE");
  if (!["closed", "left_open", "not_required"].includes(value.issueClosure.status)) fail("issueClosure.status невалиден", "NO_RESULT_CLOSURE");
  if (!["closed", "implemented", "not_required"].includes(value.specClosure.status)) fail("specClosure.status невалиден", "NO_RESULT_CLOSURE");
  if (value.cleanup.status !== "completed") fail("cleanup должен быть завершён", "NO_RESULT_CLOSURE");
  exactIdentity(value, "no-result closure", "NO_RESULT_CLOSURE");
  return value;
}

export async function recordNoResultClosure({ taskRoot, closure, states = readRegistry().states }) {
  validateNoResultClosure(closure);
  const root = canonicalTaskRoot(taskRoot);
  const owner = await createOperationOwner(`no-result-${closure.identity.slice(0, 24)}`);
  const operationLock = acquireOperationLock({ taskRoot: root, owner });
  try {
    if (existsSync(join(root, ".operation-intent.json"))) {
      const recovery = recoverOperation(root, { operationLock, owner });
      return { status: "recovered", activeCycle: readActiveCycle(root, states), recovery };
    }
    const current = readActiveCycle(root, states);
    const targetState = transitionTargetForOperation("record_no_result_closure", current.state, {
      exitName: "main_process_no_result_closure",
      closureValid: true,
    }, { states });
    const next = transitionActiveCycle({
      current,
      expectedIdentity: current.identity,
      nextState: targetState,
      nextRunId: null,
      states,
      operationLock,
      taskRoot: root,
      owner,
    });
    const result = installCycleTransaction({
      root,
      owner,
      operationLock,
      operationId: `no-result-${closure.identity.slice(0, 24)}`,
      kind: "transition_state",
      cycleId: current.cycleId,
      revision: current.revision,
      sourceState: current.state,
      targetState,
      expectedActiveCycleIdentity: current.identity,
      nextActiveCycle: next,
      artifacts: { [`cycles/${current.cycleId}/no-result-closure.json`]: jsonBytes(closure) },
      states,
    });
    return { status: "recorded", closureIdentity: closure.identity, activeCycle: result.activeCycle };
  } finally {
    releaseOperationLock(operationLock);
  }
}

async function selfTest() {
  const H = (value) => String(value).repeat(64).slice(0, 64);
  const O = (value) => String(value).repeat(40).slice(0, 40);
  const now = "2026-08-05T00:00:00.000Z";
  const check = (conclusion) => ({ name: "required", appSlug: "github-actions", checkRunId: "1", required: true, status: "completed", conclusion });
  const assertMissingAndExtraRejected = (value, validator) => {
    const missing = structuredClone(value);
    delete missing.schemaVersion;
    const missingWithIdentity = buildIdentityArtifact(missing);
    assert.throws(() => validator(missingWithIdentity));
    const extra = buildIdentityArtifact({ ...structuredClone(value), unexpected: true });
    assert.throws(() => validator(extra));
  };
  const signalFixture = (repositoryRealPath, cycleId, signalId, providerRevision, payloadSha256 = H("9")) => {
    repositoryRealPath = realpathSync(repositoryRealPath);
    const reviewSnapshot = buildIdentityArtifact({
      schemaVersion: 1,
      sourceKind: "pull_request",
      repositoryFullName: "Etogerman/Project-1",
      pullRequestNumber: 738,
      baseOid: O("a"),
      inputHeadOid: O("b"),
      inputTreeOid: O("c"),
      titleSha256: H("1"),
      bodySha256: H("2"),
      providerRevision,
    });
    const sourceContext = buildIdentityArtifact({
      schemaVersion: 1,
      sourceKind: "pull_request",
      repositoryFullName: "Etogerman/Project-1",
      repositoryIdentity: repositoryIdentity({ repositoryFullName: "Etogerman/Project-1", repositoryRealPath }),
      repositoryRealPath,
      pullRequestNumber: 738,
      baseOid: O("a"),
      inputHeadOid: O("b"),
      inputTreeOid: O("c"),
      reviewSnapshotIdentity: reviewSnapshot.identity,
    });
    const signalEvidence = buildIdentityArtifact({
      schemaVersion: 1,
      signalId,
      eventKind: "pull_request_review",
      sourceState: "C01",
      observedState: "C02",
      repositoryFullName: "Etogerman/Project-1",
      pullRequestNumber: 738,
      baseCandidates: [O("a")],
      inputHeadOid: O("b"),
      inputTreeOid: O("c"),
      checkRunId: null,
      reviewDelegationId: null,
      reviewSnapshotIdentity: reviewSnapshot.identity,
      ciSnapshotIdentity: null,
      delegationSnapshotIdentity: null,
      providerRevision,
      observedAt: now,
      payloadSha256,
    });
    const signalContext = buildIdentityArtifact({
      schemaVersion: 1,
      signalId,
      kind: "review",
      sourceState: "C01",
      originCheckState: null,
      reviewSnapshotIdentity: reviewSnapshot.identity,
      evidenceIdentity: signalEvidence.identity,
      sourceContextIdentity: sourceContext.identity,
    });
    const ledger = buildIdentityArtifact({
      schemaVersion: 1,
      cycleId,
      signalId,
      signalContext,
      signalEvidence,
      reviewSnapshot,
      ciSnapshot: null,
      delegationSnapshot: null,
      sourceContextIdentity: sourceContext.identity,
      registeredAt: now,
    });
    return { providerRevision, ledger, sourceContext };
  };
  const writeVerifiedFinalFixture = ({ revisionRoot, sourceContext, tzBytes, authorReviewBytes }) => {
    mkdirSync(join(revisionRoot, "review-final"), { recursive: true });
    writeFileSync(join(revisionRoot, "tz.md"), tzBytes);
    writeFileSync(join(revisionRoot, "author-review.md"), authorReviewBytes);
    const executableSha256 = fileSha(process.execPath);
    const clients = {
      claude: {
        authMode: "oauth_token",
        binary: "claude",
        model: "claude-opus-4-6",
        mcp: "disabled",
        settingsPath: join(revisionRoot, "settings.json"),
        settingsConfigurationSha256: H("b"),
        tools: ["Read", "Glob", "Grep"],
        transport: "official-cli",
        version: "self-test",
        preflight: { auth: "oauth_token-confirmed", readSmoke: true, settingsStable: true },
      },
    };
    const processPolicy = {
      ...PROCESS_POLICY,
      clients: {
        claude: {
          resolvedExecutable: process.execPath,
          executableSha256,
          allowedDescendantExecutables: [],
        },
      },
    };
    const reviewManifest = buildIdentityArtifact({
      schemaVersion: 1,
      base: sourceContext.baseOid,
      head: sourceContext.inputHeadOid,
      counts: { current: 1, base: 1 },
      algorithms: { tree: "sha256(git-ls-tree-r-z-full-tree)", files: "sha256(exact-bytes)", clients: "sha256(canonical-json)" },
      hashes: {
        current: H("1"),
        base_tree: H("2"),
        patch: H("3"),
        spec: sha256Bytes(tzBytes),
        author_review: sha256Bytes(authorReviewBytes),
        prompt: CANONICAL_REVIEW_PROMPT_SHA256,
        clients: identityOf(clients),
      },
      clients,
      processPolicy,
      environmentEvidence: {
        settingsCheckpointSha256: H("a"),
        executableRealPath: process.execPath,
        executableSha256,
        secretScanPolicyIdentity: H("4"),
        mcpPolicyIdentity: H("5"),
        outputRedactionPolicyIdentity: H("6"),
      },
    });
    validateReviewManifest(reviewManifest, { specBytes: tzBytes, authorReviewBytes });
    const reviewManifestBytes = jsonBytes(reviewManifest);
    const claudeBytes = Buffer.from([
      reviewManifest.identity,
      "",
      RESPONSE_MARKERS[0],
      "[]",
      RESPONSE_MARKERS[1],
      '["ТЗ и авторское ревью"]',
      RESPONSE_MARKERS[2],
      '["нет"]',
      RESPONSE_MARKERS[3],
      "блокеров нет",
      "",
    ].join("\n"));
    const qualification = buildQualification({ reviewManifestBytes, claudeBytes, dispositions: [] });
    const qualificationBytes = jsonBytes(qualification);
    const consolidatedBytes = Buffer.from([
      "# Сводный вывод внешнего ревью ТЗ",
      "",
      reviewManifest.identity,
      "",
      "## Замечания и решения",
      "",
      "Замечаний нет.",
      "",
      "## Итог",
      "",
      "Состояние: C09.",
      "Основание: Все замечания квалифицированы, блокеров текущего объёма нет.",
      "Автор квалификации: Self-test.",
      "",
    ].join("\n"));
    const final = buildReviewFinalArtifacts({
      revisionRoot,
      reviewManifestBytes,
      claudeBytes,
      qualificationBytes,
      consolidatedBytes,
    });
    for (const [name, bytes] of Object.entries(final)) writeFileSync(join(revisionRoot, "review-final", name), bytes);
    verifyFinalDirectory(join(revisionRoot, "review-final"));
    return final;
  };
  for (const conclusion of ["neutral", "skipped", "stale", "cancelled", "failure", "timed_out", null]) {
    const blocked = buildIdentityArtifact({ schemaVersion: 1, repositoryFullName: "Etogerman/Project-1", pullRequestNumber: 1, headOid: O("a"), requirementsIdentity: H("a"), applicableChecks: [check(conclusion)], aggregateStatus: "blocked", providerRevision: "1", capturedAt: now });
    assert(validateCiSnapshot(blocked));
  }
  const success = buildIdentityArtifact({ schemaVersion: 1, repositoryFullName: "Etogerman/Project-1", pullRequestNumber: 1, headOid: O("a"), requirementsIdentity: H("a"), applicableChecks: [check("success")], aggregateStatus: "success", providerRevision: "1", capturedAt: now });
  assert(validateCiSnapshot(success));
  assertMissingAndExtraRejected(success, validateCiSnapshot);
  const invalid = structuredClone(success); invalid.applicableChecks[0].conclusion = "neutral"; invalid.identity = jcsIdentity(invalid);
  assert.throws(() => validateCiSnapshot(invalid));
  const closure = buildIdentityArtifact({ schemaVersion: 1, terminalOutcome: { status: "cancelled_without_materialization", evidenceIdentity: H("1") }, issueClosure: { status: "not_required", evidenceIdentity: H("2") }, specClosure: { status: "not_required", evidenceIdentity: H("3") }, cleanup: { status: "completed", evidenceIdentity: H("4") } });
  assert(validateNoResultClosure(closure));
  assertMissingAndExtraRejected(closure, validateNoResultClosure);
  const missing = structuredClone(closure); delete missing.cleanup; missing.identity = jcsIdentity(missing);
  assert.throws(() => validateNoResultClosure(missing));
  const incompleteCleanup = buildIdentityArtifact({ ...closure, cleanup: { status: "pending", evidenceIdentity: H("4") } });
  assert.throws(() => validateNoResultClosure(incompleteCleanup), (error) => error?.code === "NO_RESULT_CLOSURE");

  const mutableReview = buildIdentityArtifact({
    schemaVersion: 1,
    sourceKind: "pull_request",
    repositoryFullName: "Etogerman/Project-1",
    pullRequestNumber: 1,
    baseOid: O("0"),
    inputHeadOid: O("a"),
    inputTreeOid: O("b"),
    titleSha256: H("1"),
    bodySha256: H("2"),
    providerRevision: "1",
  });
  const completeDelegation = buildIdentityArtifact({
    schemaVersion: 1,
    repositoryFullName: "Etogerman/Project-1",
    pullRequestNumber: 1,
    headOid: O("a"),
    delegationId: "delegation-1",
    expectedReviewerIds: ["claude"],
    completedReviewerIds: ["claude"],
    unresolvedThreadIds: [],
    reviewStatus: "completed",
    providerRevision: "1",
    capturedAt: now,
  });
  assertMissingAndExtraRejected(mutableReview, validateReviewSnapshot);
  assertMissingAndExtraRejected(completeDelegation, validateDelegationSnapshot);
  assert.deepEqual(validateMutableSnapshotEvidence({ reviewSnapshot: mutableReview, ciSnapshot: success, delegationSnapshot: completeDelegation }), {
    exactHeadOid: O("a"), ci: "success", delegation: "completed",
  });
  const foreignCi = buildIdentityArtifact({ ...success, headOid: O("f") });
  assert.throws(() => validateMutableSnapshotEvidence({ reviewSnapshot: mutableReview, ciSnapshot: foreignCi }), (error) => error?.code === "MUTABLE_SNAPSHOT_EVIDENCE");
  const unresolvedDelegation = buildIdentityArtifact({ ...completeDelegation, unresolvedThreadIds: ["thread-1"] });
  assert.throws(() => validateMutableSnapshotEvidence({ reviewSnapshot: mutableReview, delegationSnapshot: unresolvedDelegation }), (error) => error?.code === "MUTABLE_SNAPSHOT_EVIDENCE");

  const temporary = mkdtempSync(join(tmpdir(), "workflow-cycle-store-"));
  let operationLock = null;
  try {
    const owner = buildIdentityArtifact({
      schemaVersion: 1,
      host: "self-test",
      bootIdentity: H("b"),
      pid: process.pid,
      pgid: process.pid,
      processStartToken: "self-test-start",
      operationId: "cycle-store-self-test",
    });
    operationLock = acquireOperationLock({ taskRoot: temporary, owner });
    const oldCycle = buildIdentityArtifact({ schemaVersion: 1, state: "C07", marker: "old" });
    const nextCycle = buildIdentityArtifact({ schemaVersion: 1, state: "C08", marker: "next" });
    writeFileSync(join(temporary, "active-cycle.json"), `${JSON.stringify(oldCycle, null, 2)}\n`);
    const artifactBytes = Buffer.from("immutable artifact\n");
    const plan = buildOperationPlan({
      taskRoot: temporary,
      operationId: "crash-recovery",
      kind: "transition_state",
      cycleId: "cycle-1",
      revision: 1,
      sourceState: "C07",
      targetState: "C08",
      owner,
      operationLock,
      expectedActiveCycleIdentity: oldCycle.identity,
      nextActiveCycle: nextCycle,
      artifacts: {
        "cycles/cycle-1/revision-1/revision-seal.json": artifactBytes,
        "active-cycle.json": Buffer.from(`${JSON.stringify(nextCycle, null, 2)}\n`),
      },
    });
    assertMissingAndExtraRejected(plan.intent, validateOperationIntent);
    assert.throws(
      () => validateOperationIntent(buildIdentityArtifact({ ...plan.intent, targetState: "C10" })),
      (error) => error?.code === "OPERATION_INTENT",
    );
    assert.throws(() => installOperationPlan(plan, { operationLock, owner, crashAfter: 1 }), (error) => error?.code === "OPERATION_CRASH");
    const recovered = recoverOperation(temporary, { operationLock, owner });
    assert.equal(recovered.reused, false);
    assert.equal(JSON.parse(readFileSync(join(temporary, "active-cycle.json"), "utf8")).identity, nextCycle.identity);
    assert.equal(readFileSync(join(temporary, "cycles/cycle-1/revision-1/revision-seal.json"), "utf8"), artifactBytes.toString("utf8"));
    assert.equal(existsSync(join(temporary, ".operation-intent.json")), false);

    const commitIntent = buildIdentityArtifact({
      schemaVersion: 1, cycleId: "cycle-1", revision: 1, publicationRunId: "publication-1",
      actionId: "commit-1", actionKind: "commit", repositoryIdentity: H("1"), worktreeIdentity: H("2"),
      expectedHeadOid: O("a"), treeOid: O("b"), parentOid: O("a"), messageSha256: H("3"),
      authorIdentity: H("7"), committerIdentity: H("8"),
      authorTimestamp: now, committerTimestamp: now, remoteName: null, targetRef: null,
      expectedRemoteOid: null, intendedResultOid: O("c"), publicationGateIdentity: H("4"),
      executionCeilingIdentity: H("5"), commandIdentity: H("6"), createdBy: owner, createdAt: now,
    });
    assertMissingAndExtraRejected(commitIntent, validateActionIntent);
    const commitResult = buildIdentityArtifact({
      schemaVersion: 1,
      actionIntentIdentity: commitIntent.identity,
      actionKind: "commit",
      exitCode: 0,
      localHeadOid: commitIntent.intendedResultOid,
      remoteOid: null,
      startedAt: now,
      finishedAt: now,
      outcome: "success",
    });
    assert(validateActionResult(commitResult));
    assertMissingAndExtraRejected(commitResult, validateActionResult);
    assert.equal(recoverCommitAction(commitIntent, { headOid: O("a"), treeOid: O("b"), parentOid: O("a"), messageSha256: H("3") }), "retry");
    assert.equal(recoverCommitAction(commitIntent, { headOid: O("c"), treeOid: O("b"), parentOid: O("a"), messageSha256: H("3") }), "success");
    assert.equal(recoverCommitAction(commitIntent, { headOid: O("d"), treeOid: O("b"), parentOid: O("a"), messageSha256: H("3") }), "blocked");
    const pushIntent = buildIdentityArtifact({
      schemaVersion: 1, cycleId: "cycle-1", revision: 1, publicationRunId: "publication-1",
      actionId: "push-1", actionKind: "push", repositoryIdentity: H("1"), worktreeIdentity: H("2"),
      expectedHeadOid: O("c"), treeOid: null, parentOid: null, messageSha256: null,
      authorIdentity: null, committerIdentity: null, authorTimestamp: null, committerTimestamp: null,
      remoteName: "origin", targetRef: "refs/heads/codex/test", expectedRemoteOid: O("a"),
      intendedResultOid: O("c"), publicationGateIdentity: H("4"), executionCeilingIdentity: H("5"),
      commandIdentity: H("6"), createdBy: owner, createdAt: now,
    });
    assertMissingAndExtraRejected(pushIntent, validateActionIntent);
    assert.equal(recoverPushAction(pushIntent, O("c")), "success");
    assert.equal(recoverPushAction(pushIntent, O("a")), "retry");
    assert.equal(recoverPushAction(pushIntent, O("d")), "blocked");

    releaseOperationLock(operationLock);
    operationLock = null;
    const states = readRegistry().states;
    const signalRoot = join(temporary, "signal-root");
    mkdirSync(signalRoot);
    const signalOwner = buildIdentityArtifact({ ...owner, operationId: "register-signal" });
    const stableCapture = signalFixture(signalRoot, "signal-cycle", "signal-1", "provider-1");
    assertMissingAndExtraRejected(stableCapture.ledger.reviewSnapshot, validateReviewSnapshot);
    assertMissingAndExtraRejected(stableCapture.sourceContext, validateSourceContext);
    assertMissingAndExtraRejected(stableCapture.ledger.signalEvidence, validateSignalEvidence);
    assertMissingAndExtraRejected(stableCapture.ledger.signalContext, validateSignalContext);
    assertMissingAndExtraRejected(stableCapture.ledger, validateSignalLedger);
    const registered = await registerSignalCycle({
      taskRoot: signalRoot,
      cycleId: "signal-cycle",
      signalId: "signal-1",
      captureProvider: async () => stableCapture,
      owner: signalOwner,
      states,
    });
    assert.equal(registered.status, "registered");
    assert.equal(readActiveCycle(signalRoot, states).state, "C02");
    const reused = await registerSignalCycle({
      taskRoot: signalRoot,
      cycleId: "signal-cycle",
      signalId: "signal-1",
      captureProvider: async () => stableCapture,
      owner: signalOwner,
      states,
    });
    assert.equal(reused.status, "reused");

    const quarantineRoot = join(temporary, "quarantine-root");
    mkdirSync(quarantineRoot);
    const quarantineSignal = signalFixture(quarantineRoot, "quarantine-cycle", "quarantine-signal", "provider-1");
    const quarantineOwner = buildIdentityArtifact({ ...owner, operationId: "quarantine-operation" });
    const quarantineCurrent = initialActiveCycle({
      cycleId: "quarantine-cycle",
      owner: quarantineOwner,
      sourceContext: quarantineSignal.sourceContext,
      signalContext: quarantineSignal.ledger.signalContext,
      state: "C02",
    });
    writeFileSync(join(quarantineRoot, "active-cycle.json"), jsonBytes(quarantineCurrent));
    const quarantineLock = acquireOperationLock({ taskRoot: quarantineRoot, owner: quarantineOwner });
    try {
      const quarantineNext = transitionActiveCycle({
        current: quarantineCurrent,
        expectedIdentity: quarantineCurrent.identity,
        nextState: "C03",
        nextRunId: null,
        states,
        operationLock: quarantineLock,
        taskRoot: quarantineRoot,
        owner: quarantineOwner,
      });
      const conflictPath = "cycles/quarantine-cycle/revision-0/conflict.txt";
      mkdirSync(dirname(join(quarantineRoot, conflictPath)), { recursive: true });
      writeFileSync(join(quarantineRoot, conflictPath), "expected old bytes\n");
      const quarantinePlan = buildOperationPlan({
        taskRoot: quarantineRoot,
        operationId: "conflicting-install",
        kind: "transition_state",
        cycleId: "quarantine-cycle",
        revision: 0,
        sourceState: "C02",
        targetState: "C03",
        owner: quarantineOwner,
        operationLock: quarantineLock,
        expectedActiveCycleIdentity: quarantineCurrent.identity,
        nextActiveCycle: quarantineNext,
        artifacts: {
          [conflictPath]: Buffer.from("intended new bytes\n"),
          "active-cycle.json": jsonBytes(quarantineNext),
        },
      });
      writeFileSync(join(quarantineRoot, conflictPath), "foreign bytes\n");
      assert.throws(
        () => installPreparedCyclePlan({
          plan: quarantinePlan,
          currentBeforeInstall: quarantineCurrent,
          operationLock: quarantineLock,
          owner: quarantineOwner,
          states,
        }),
        (error) => error?.code === "OPERATION_QUARANTINE" && error?.blockedState === "B01" && SHA256.test(error?.quarantineBlockIdentity ?? ""),
      );
      const quarantineBlocked = readActiveCycle(quarantineRoot, states);
      assert.equal(quarantineBlocked.state, "B01");
      assert.equal(quarantineBlocked.resumeContexts.at(-1).targetState, "C02");
      const quarantineBlock = readRegularJson(
        join(quarantineRoot, "cycles/quarantine-cycle/quarantine-blocks/conflicting-install.json"),
        "quarantine block",
      );
      assert(validateQuarantineBlock(quarantineBlock));
      assertMissingAndExtraRejected(quarantineBlock, validateQuarantineBlock);
      assert(existsSync(join(quarantineRoot, quarantineBlock.preservedIntentPath)));
      assert(existsSync(join(quarantineRoot, quarantineBlock.preservedPendingPath)));
      assert(existsSync(join(quarantineRoot, quarantineBlock.quarantinedPath)));
      assert.equal(existsSync(join(quarantineRoot, ".operation-intent.json")), false);
    } finally {
      releaseOperationLock(quarantineLock);
    }

    const mismatchedCapture = signalFixture(signalRoot, "signal-cycle", "signal-1", "provider-1", H("8"));
    const mismatch = await registerSignalCycle({
      taskRoot: signalRoot,
      cycleId: "signal-cycle",
      signalId: "signal-1",
      captureProvider: async () => mismatchedCapture,
      owner: signalOwner,
      states,
    });
    assert.equal(mismatch.status, "blocked");
    assert.equal(readActiveCycle(signalRoot, states).state, "B01");
    assert.equal(readActiveCycle(signalRoot, states).resumeContexts.length, 1);
    const repeatedMismatch = await registerSignalCycle({
      taskRoot: signalRoot,
      cycleId: "signal-cycle",
      signalId: "signal-1",
      captureProvider: async () => signalFixture(signalRoot, "signal-cycle", "signal-1", "provider-1", H("7")),
      owner: signalOwner,
      states,
    });
    assert.equal(repeatedMismatch.status, "blocked");
    assert.equal(readActiveCycle(signalRoot, states).state, "B01");
    assert.equal(readActiveCycle(signalRoot, states).resumeContexts.length, 1);

    const unstableRoot = join(temporary, "unstable-root");
    mkdirSync(unstableRoot);
    const revisions = ["provider-1", "provider-2", "provider-3"];
    const unstable = await registerSignalCycle({
      taskRoot: unstableRoot,
      cycleId: "unstable-cycle",
      signalId: "signal-2",
      captureProvider: async ({ attempt }) => signalFixture(unstableRoot, "unstable-cycle", "signal-2", revisions[attempt - 1]),
      owner: buildIdentityArtifact({ ...owner, operationId: "unstable-signal" }),
      states,
    });
    assert.equal(unstable.status, "blocked");
    assert.equal(readActiveCycle(unstableRoot, states).state, "B01");

    const closureRoot = join(temporary, "closure-root");
    mkdirSync(closureRoot);
    const closureSignal = signalFixture(closureRoot, "closure-cycle", "closure-signal", "provider-1");
    const closureActive = initialActiveCycle({
      cycleId: "closure-cycle",
      owner,
      sourceContext: closureSignal.sourceContext,
      signalContext: closureSignal.ledger.signalContext,
      state: "X03",
    });
    writeFileSync(join(closureRoot, "active-cycle.json"), jsonBytes(closureActive));
    const recordedClosure = await recordNoResultClosure({ taskRoot: closureRoot, closure, states });
    assert.equal(recordedClosure.status, "recorded");
    assert.equal(readActiveCycle(closureRoot, states).state, "X03");
    assert.equal(readRegularJson(join(closureRoot, "cycles", "closure-cycle", "no-result-closure.json"), "closure").identity, closure.identity);

    const publicationRoot = join(temporary, "publication-root");
    mkdirSync(publicationRoot);
    const publicationSignal = signalFixture(publicationRoot, "publication-cycle", "publication-signal", "provider-1");
    const publicationBase = initialActiveCycle({
      cycleId: "publication-cycle",
      owner,
      sourceContext: publicationSignal.sourceContext,
      signalContext: publicationSignal.ledger.signalContext,
      state: "C02",
    });
    const publicationActive = buildIdentityArtifact({ ...publicationBase, revision: 1, state: "C11" });
    writeFileSync(join(publicationRoot, "active-cycle.json"), jsonBytes(publicationActive));
    const publicationRevisionRoot = join(publicationRoot, "cycles", "publication-cycle", "revision-1");
    mkdirSync(publicationRevisionRoot, { recursive: true });
    mkdirSync(join(publicationRoot, "cycles", "publication-cycle", "decisions"), { recursive: true });
    const publicationTzBytes = Buffer.from("# Проверенное ТЗ\n");
    const publicationAuthorReviewBytes = Buffer.from("# Авторская проверка\n\nБлокеров нет.\n");
    const publicationFinal = writeVerifiedFinalFixture({
      revisionRoot: publicationRevisionRoot,
      sourceContext: publicationSignal.sourceContext,
      tzBytes: publicationTzBytes,
      authorReviewBytes: publicationAuthorReviewBytes,
    });
    const publicationFinalBytes = publicationFinal["manifest.json"];
    const factor = { value: false, source: "self-test", evidenceRefs: ["tz.md"] };
    const factors = Object.fromEntries(gateConstants.CLASSIFICATION_FACTORS.map((name) => [name, structuredClone(factor)]));
    const implementationClassification = buildIdentityArtifact({
      schemaVersion: 1,
      phase: "implementation",
      snapshot: {
        tzSha256: sha256Bytes(publicationTzBytes),
        base: publicationSignal.sourceContext.baseOid,
        head: publicationSignal.sourceContext.inputHeadOid,
        treeOid: publicationSignal.sourceContext.inputTreeOid,
        changedFilesSha256: H("5"),
        diffSha256: H("6"),
      },
      streamClass: { value: "docs_only", source: "self-test", evidenceRefs: ["tz.md"] },
      factors,
      scopeAnalyzerSha256: H("7"),
      uncertainty: false,
      decision: "not_required",
      specRevision: null,
      rationale: "Self-test не требует внешнего ТЗ",
    });
    writeFileSync(join(publicationRevisionRoot, "implementation-spec-classification.json"), jsonBytes(implementationClassification));
    const userHint = buildIdentityArtifact({ kind: "user", stableSubject: "operator-self-test" });
    const implementationDecision = buildIdentityArtifact({
      schemaVersion: 1,
      cycleId: "publication-cycle",
      decisionId: "implementation-decision",
      decisionState: "D01",
      decisionKind: "implementation_authorization",
      requestedAction: "реализовать и подготовить публикацию",
      answer: "разрешено",
      authorizedActions: ["commit", "push"],
      sourceContextIdentity: publicationSignal.sourceContext.identity,
      revision: 1,
      evidenceRef: "chat:implementation-decision",
      evidenceSha256: H("8"),
      decidedBy: { kind: "user", identityHint: userHint },
      recordedBy: owner,
      decidedAt: now,
    });
    validateDecision(implementationDecision);
    assertMissingAndExtraRejected(implementationDecision, validateDecision);
    const publicationExecutionCeiling = executionCeilingFromDecision(implementationDecision, "C12");
    assert(validateExecutionCeiling(publicationExecutionCeiling, { decision: implementationDecision }));
    assertMissingAndExtraRejected(
      publicationExecutionCeiling,
      (value) => validateExecutionCeiling(value, { decision: implementationDecision }),
    );
    writeFileSync(join(publicationRoot, "cycles", "publication-cycle", "decisions", "implementation-decision.json"), jsonBytes(implementationDecision));
    const implementationGate = buildIdentityArtifact({
      schemaVersion: 1,
      cycleId: "publication-cycle",
      revision: 1,
      reviewFinalManifestSha256: sha256Bytes(publicationFinalBytes),
      tzSha256: sha256Bytes(publicationTzBytes),
      base: publicationSignal.sourceContext.baseOid,
      inputHead: publicationSignal.sourceContext.inputHeadOid,
      implementationSpecClassificationIdentity: implementationClassification.identity,
      implementationDecisionIdentity: implementationDecision.identity,
      issuedBy: "G00",
      allowedStates: ["C10", "C11", "G01"],
    });
    writeFileSync(join(publicationRevisionRoot, "implementation-gate.json"), jsonBytes(implementationGate));
    const openedPublication = await enterPublicationBoundary({ taskRoot: publicationRoot, states });
    assert.equal(openedPublication.status, "opened");
    assert.equal(openedPublication.activeCycle.state, "G01");
    const publicationEntries = readdirSync(join(publicationRevisionRoot, "publication-runs", openedPublication.publicationRunId)).sort();
    assert.deepEqual(publicationEntries, ["run-open.json"]);

    const authorRoot = join(temporary, "author-review-root");
    mkdirSync(authorRoot);
    const authorSignal = signalFixture(authorRoot, "author-cycle", "author-signal", "provider-1");
    const authorBase = initialActiveCycle({
      cycleId: "author-cycle",
      owner,
      sourceContext: authorSignal.sourceContext,
      signalContext: authorSignal.ledger.signalContext,
      state: "C02",
    });
    const authorActive = buildIdentityArtifact({ ...authorBase, revision: 1, state: "C08" });
    writeFileSync(join(authorRoot, "active-cycle.json"), jsonBytes(authorActive));
    const authorRevisionRoot = join(authorRoot, "cycles", "author-cycle", "revision-1");
    mkdirSync(authorRevisionRoot, { recursive: true });
    const authorTz = Buffer.from("# ТЗ авторского цикла\n");
    const authorReview = Buffer.from("# Авторское ревью\n\nБлокеров нет.\n");
    writeFileSync(join(authorRevisionRoot, "tz.md"), authorTz);
    writeFileSync(join(authorRevisionRoot, "author-review.md"), authorReview);
    const authorRevisionOpen = buildIdentityArtifact({
      schemaVersion: 1,
      cycleId: "author-cycle",
      revision: 1,
      previousRevision: 0,
      sourceState: "C06",
      signalIdentity: authorSignal.ledger.signalContext.identity,
      sourceContext: authorSignal.sourceContext,
      openedBy: owner,
    });
    assertMissingAndExtraRejected(authorRevisionOpen, validateRevisionOpen);
    writeFileSync(join(authorRevisionRoot, "revision-open.json"), jsonBytes(authorRevisionOpen));
    const executableSha256 = fileSha(process.execPath);
    const processPolicy = {
      ...PROCESS_POLICY,
      clients: {
        claude: {
          resolvedExecutable: process.execPath,
          executableSha256,
          allowedDescendantExecutables: [],
        },
      },
    };
    const settingsCheckpointSha256 = H("a");
    const preparedAuthor = await sealAndOpenReviewTransaction({
      taskRoot: authorRoot,
      findings: [],
      states,
      prepareReviewRun: async ({ cycleId, revision, reviewRunId }) => {
        const clients = {
          claude: {
            authMode: "oauth_token",
            binary: "claude",
            model: "claude-opus-4-6",
            mcp: "disabled",
            settingsPath: join(authorRoot, "settings.json"),
            settingsConfigurationSha256: H("b"),
            tools: ["Read", "Glob", "Grep"],
            transport: "official-cli",
            version: "self-test",
            preflight: { auth: "oauth_token-confirmed", readSmoke: true, settingsStable: true },
          },
        };
        const reviewManifest = buildIdentityArtifact({
          schemaVersion: 1,
          base: authorSignal.sourceContext.baseOid,
          head: authorSignal.sourceContext.inputHeadOid,
          counts: { current: 1, base: 1 },
          algorithms: { tree: "sha256(git-ls-tree-r-z-full-tree)", files: "sha256(exact-bytes)", clients: "sha256(canonical-json)" },
          hashes: {
            current: H("1"),
            base_tree: H("2"),
            patch: H("3"),
            spec: sha256Bytes(authorTz),
            author_review: sha256Bytes(authorReview),
            prompt: CANONICAL_REVIEW_PROMPT_SHA256,
            clients: identityOf(clients),
          },
          clients,
          processPolicy,
          environmentEvidence: {
            settingsCheckpointSha256,
            executableRealPath: process.execPath,
            executableSha256,
            secretScanPolicyIdentity: H("4"),
            mcpPolicyIdentity: H("5"),
            outputRedactionPolicyIdentity: H("6"),
          },
        });
        const launchIntent = buildIdentityArtifact({
          schemaVersion: 1,
          cycleId,
          revision,
          reviewRunId,
          reviewManifestIdentity: reviewManifest.identity,
          client: "claude",
          executableRealPath: process.execPath,
          executableSha256,
          argv: ["--version"],
          cwd: authorRoot,
          scratchIdentity: H("7"),
          settingsCheckpointSha256,
          processPolicyIdentity: identityOf(processPolicy),
          createdAt: now,
        });
        return { reviewManifest, launchIntent };
      },
    });
    assert.equal(preparedAuthor.status, "sealed");
    assert.equal(preparedAuthor.target, "P03");
    assert.equal(readActiveCycle(authorRoot, states).activeRunId, preparedAuthor.reviewRunId);
    assert.deepEqual(
      readdirSync(join(authorRevisionRoot, "review-runs", preparedAuthor.reviewRunId)).sort(),
      ["launch-intent.json", "review-manifest.json", "run-open.json"],
    );
    const storedAuthorResult = readRegularJson(join(authorRevisionRoot, "author-review-result.json"), "author result");
    const storedRevisionSeal = readRegularJson(join(authorRevisionRoot, "revision-seal.json"), "revision seal");
    const storedRunOpen = readRegularJson(join(authorRevisionRoot, "review-runs", preparedAuthor.reviewRunId, "run-open.json"), "run open");
    assertMissingAndExtraRejected(storedAuthorResult, validateAuthorReviewResult);
    assertMissingAndExtraRejected(storedRevisionSeal, validateRevisionSeal);
    assertMissingAndExtraRejected(storedRunOpen, validateReviewRunOpen);
  } finally {
    if (operationLock !== null) releaseOperationLock(operationLock);
    rmSync(temporary, { recursive: true, force: true });
  }
  console.log("workflow-cycle-store self-test passed");
}

if (resolve(process.argv[1] ?? "") === SELF_PATH) {
  if (process.argv.includes("--self-test")) await selfTest();
}
