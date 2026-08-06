#!/usr/bin/env node

import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { chmodSync, existsSync, mkdirSync, mkdtempSync, readFileSync, realpathSync, rmSync, writeFileSync } from "node:fs";
import { hostname, tmpdir } from "node:os";
import { join } from "node:path";
import process from "node:process";
import {
  acquireOperationLock,
  buildIdentityArtifact,
  compareAndSetActiveCycle,
  createOperationOwner,
  gateConstants,
  openPublicationRun,
  reclaimStaleOperationLock,
  releaseOperationLock,
  runExactTreeChecks,
  sealExpectedTree,
  transitionActiveCycle,
  validateActiveCycle,
  validateChecksManifest,
  validateImplementationGate,
  validateNoOpProof,
  validatePendingPublicationBlock,
  validatePublicationGate,
  validatePublicationRunOpen,
  validateQuarantineRecoveryAuthorization,
  validateQuarantineRecoveryAudit,
  validateSpecClassification,
  writeArtifactAtomically,
} from "./workflow-spec-review-gates.mjs";
import { canonicalJson, sha256Bytes } from "./workflow-spec-review.mjs";

const H = (value) => String(value).repeat(64).slice(0, 64);
const O = (value) => String(value).repeat(40).slice(0, 40);
const clone = (value) => structuredClone(value);
const resign = (value) => buildIdentityArtifact(value);

function withoutPath(value, dottedPath) {
  const copy = clone(value);
  const parts = dottedPath.split(".");
  const key = parts.pop();
  let cursor = copy;
  for (const part of parts) cursor = cursor[part];
  delete cursor[key];
  return copy;
}

function expectEveryRequiredField(value, paths, validator, code) {
  for (const path of paths) {
    const missing = withoutPath(value, path);
    expectThrow(() => validator(path === "identity" ? missing : resign(missing)), code);
  }
}

function expectThrow(fn, code) {
  let error = null;
  try {
    fn();
  } catch (caught) {
    error = caught;
  }
  assert(error, "ожидалась ошибка");
  assert.equal(error.code, code);
}

async function expectReject(promise, code) {
  let error = null;
  try {
    await promise;
  } catch (caught) {
    error = caught;
  }
  assert(error, "ожидалась ошибка Promise");
  assert.equal(error.code, code);
}

function factor(value = false) {
  return { value, source: "self-test", evidenceRefs: ["evidence.json"] };
}

function classification({ phase = "implementation", decision = "not_required", uncertainty = false, treeOid = O("c"), streamClass = "docs_only", factorOverrides = {} } = {}) {
  const factors = Object.fromEntries(gateConstants.CLASSIFICATION_FACTORS.map((name) => [name, factor(false)]));
  for (const [name, value] of Object.entries(factorOverrides)) factors[name] = factor(value);
  return resign({
    schemaVersion: 1,
    phase,
    snapshot: {
      tzSha256: H("1"),
      base: O("a"),
      head: phase === "implementation" ? O("b") : null,
      treeOid,
      changedFilesSha256: H("2"),
      diffSha256: H("3"),
    },
    streamClass: { value: streamClass, source: "self-test", evidenceRefs: ["tz.md"] },
    factors,
    scopeAnalyzerSha256: H("4"),
    uncertainty,
    decision,
    specRevision: decision === "fixed" ? O("f") : null,
    rationale: "Проверяемая классификация self-test",
  });
}

function checksManifest(treeOid = O("c"), exitCode = 0) {
  return resign({
    schemaVersion: 1,
    expectedTreeOid: treeOid,
    checks: [{
      name: "self-test",
      argv: ["node", "check.mjs", ";|$`"],
      cwd: ".",
      startedAt: "2026-08-03T00:00:00.000Z",
      finishedAt: "2026-08-03T00:00:01.000Z",
      exitCode,
      stdoutSha256: H("5"),
      stderrSha256: H("6"),
    }],
  });
}

function jsonBytes(value) {
  return Buffer.from(`${JSON.stringify(value, null, 2)}\n`);
}

function implementationGate(specClassification) {
  return resign({
    schemaVersion: 1,
    cycleId: "cycle-1",
    revision: 11,
    reviewFinalManifestSha256: H("7"),
    tzSha256: H("1"),
    base: O("a"),
    inputHead: O("b"),
    implementationSpecClassificationIdentity: specClassification.identity,
    implementationDecisionIdentity: H("8"),
    issuedBy: "G00",
    allowedStates: ["C10", "C11", "G01"],
  });
}

function publicationGate({ implementation, publication, checks }) {
  const checksBytes = jsonBytes(checks);
  return resign({
    schemaVersion: 1,
    cycleId: "cycle-1",
    revision: 11,
    implementationGateIdentity: implementation.identity,
    tzSha256: implementation.tzSha256,
    publishBase: O("a"),
    expectedTreeOid: publication.snapshot.treeOid,
    validatedDiffSha256: sha256Bytes(Buffer.from("validated-diff")),
    publishedFiles: ["a.md", "z.md"],
    implementationSpecClassificationIdentity: implementation.implementationSpecClassificationIdentity,
    publicationSpecClassificationIdentity: publication.identity,
    specStatus: publication.decision,
    specRevision: publication.specRevision,
    checksSha256: sha256Bytes(checksBytes),
    executionCeilingIdentity: H("9"),
    issuedBy: "G01",
    allowedStates: ["C12"],
  });
}

function noOpProof({ implementation, publication, checks }) {
  return resign({
    schemaVersion: 1,
    cycleId: "cycle-1",
    revision: 11,
    implementationGateIdentity: implementation.identity,
    tzSha256: implementation.tzSha256,
    publishBase: O("a"),
    remoteHead: O("a"),
    remoteTreeOid: publication.snapshot.treeOid,
    expectedTreeOid: publication.snapshot.treeOid,
    validatedDiffSha256: gateConstants.EMPTY_DIFF_SHA256,
    publicationSpecClassificationIdentity: publication.identity,
    specStatus: publication.decision,
    specRevision: publication.specRevision,
    checksSha256: sha256Bytes(jsonBytes(checks)),
    mutationRequired: false,
    issuedBy: "G01",
    allowedStates: ["C13"],
  });
}

function cycle(state, activeRunId = ["P03", "G01", "C12"].includes(state) ? "run-1" : null) {
  const owner = resign({ schemaVersion: 1, host: "host", bootIdentity: H("b"), pid: 100, pgid: 100, processStartToken: "token", operationId: "op-1" });
  return resign({
    schemaVersion: 1,
    cycleId: "cycle-1",
    revision: 11,
    state,
    activeRunId,
    lastCompletedRun: null,
    owner,
    sourceContext: resign({ kind: "source", stableSubject: "self-test" }),
    signalContext: resign({ kind: "signal", stableSubject: "self-test" }),
    resumeContexts: [],
    lockPath: ".operation.lock",
  });
}

function resumeFrame(current, { holdingState, targetState, savedRunId = current.activeRunId, gateIdentity = null, register = "resume_state" }) {
  return resign({
    schemaVersion: 1,
    frameId: `frame-${holdingState.toLowerCase()}-${targetState.toLowerCase()}`,
    cycleId: current.cycleId,
    revision: current.revision,
    register,
    sourceState: current.state,
    holdingState,
    targetState,
    runPolicy: targetState === "C12" ? "restore_exact" : ["P03", "G01"].includes(targetState) ? "new" : "none",
    savedRunId,
    gateIdentity,
    requestedAction: null,
    executor: "agent",
    owner: "user/operator",
    unblockEvent: "self_test_unblocked",
    stopMode: "blocked",
    signalIdentity: current.signalContext.identity,
    sourceContextIdentity: current.sourceContext.identity,
  });
}

function git(repo, args) {
  return execFileSync("git", ["-C", repo, ...args], { encoding: "utf8" }).trim();
}

async function main() {
  const states = JSON.parse(readFileSync(new URL("../../docs/workflow/pr-correction/states.json", import.meta.url), "utf8")).states;
  const runtimeOwner = await createOperationOwner("operation-self-test");
  assert.equal(runtimeOwner.pid, process.pid);
  assert.equal(runtimeOwner.operationId, "operation-self-test");
  const implementationClassification = classification();
  assert(validateSpecClassification(implementationClassification, { phase: "implementation", expectedTreeOid: O("c") }));
  expectEveryRequiredField(implementationClassification, [
    "schemaVersion", "phase", "snapshot", "streamClass", "factors", "scopeAnalyzerSha256", "uncertainty", "decision", "specRevision", "rationale", "identity",
    ...["tzSha256", "base", "head", "treeOid", "changedFilesSha256", "diffSha256"].map((key) => `snapshot.${key}`),
    ...["value", "source", "evidenceRefs"].map((key) => `streamClass.${key}`),
    ...gateConstants.CLASSIFICATION_FACTORS.map((key) => `factors.${key}`),
    ...gateConstants.CLASSIFICATION_FACTORS.flatMap((factorName) => ["value", "source", "evidenceRefs"].map((key) => `factors.${factorName}.${key}`)),
  ], (value) => validateSpecClassification(value, { phase: "implementation", expectedTreeOid: O("c") }), "SPEC_CLASSIFICATION");
  const publicationChecks = checksManifest();
  const publicationClassification = classification({ phase: "publication" });
  assert(validateSpecClassification(publicationClassification, { phase: "publication", expectedTreeOid: O("c"), checksManifest: publicationChecks }));

  for (const mutate of [
    (value) => { value.extra = true; },
    (value) => { value.uncertainty = true; },
    (value) => { value.factors.migrationChanged.value = true; },
    (value) => { value.specRevision = O("d"); },
  ]) {
    const invalid = clone(implementationClassification);
    mutate(invalid);
    expectThrow(() => validateSpecClassification(resign(invalid)), "SPEC_CLASSIFICATION");
  }
  const pending = classification({ decision: "pending", uncertainty: true, streamClass: "substantial", factorOverrides: { externalMutationRequired: true } });
  assert(validateSpecClassification(pending));
  const pendingWithSpec = clone(pending);
  pendingWithSpec.specRevision = O("e");
  expectThrow(() => validateSpecClassification(resign(pendingWithSpec)), "SPEC_CLASSIFICATION");
  const fixedWithoutSpec = classification({ decision: "fixed", streamClass: "substantial" });
  fixedWithoutSpec.specRevision = null;
  expectThrow(() => validateSpecClassification(resign(fixedWithoutSpec)), "SPEC_CLASSIFICATION");
  const wrongPublicationHead = clone(publicationClassification);
  wrongPublicationHead.snapshot.head = O("b");
  expectThrow(() => validateSpecClassification(resign(wrongPublicationHead), { phase: "publication", checksManifest: publicationChecks }), "SPEC_CLASSIFICATION");
  expectThrow(() => validateSpecClassification(publicationClassification), "SPEC_CLASSIFICATION");
  expectThrow(() => validateSpecClassification(publicationClassification, { phase: "publication", checksManifest: checksManifest(O("d")) }), "SPEC_CLASSIFICATION");
  expectThrow(() => validateSpecClassification(publicationClassification, { phase: "publication", checksManifest: checksManifest(O("c"), 1) }), "SPEC_CLASSIFICATION");

  const finalBytes = Buffer.from("final-manifest");
  const tzBytes = Buffer.from("tz");
  const gate = implementationGate(implementationClassification);
  gate.reviewFinalManifestSha256 = sha256Bytes(finalBytes);
  gate.tzSha256 = sha256Bytes(tzBytes);
  const validGate = resign(gate);
  const gateContext = { finalManifestBytes: finalBytes, tzBytes, classification: implementationClassification, cycleId: "cycle-1", revision: 11, base: O("a"), inputHead: O("b"), implementationDecisionIdentity: H("8") };
  assert(validateImplementationGate(validGate, gateContext));
  expectEveryRequiredField(validGate, Object.keys(validGate), (value) => validateImplementationGate(value, gateContext), "IMPLEMENTATION_GATE");
  for (const [field, value] of [["issuedBy", "C09"], ["cycleId", "cycle-2"], ["revision", 12], ["base", O("d")]]) {
    const invalid = clone(validGate);
    invalid[field] = value;
    expectThrow(() => validateImplementationGate(resign(invalid), gateContext), "IMPLEMENTATION_GATE");
  }
  expectThrow(() => validateImplementationGate(validGate, { ...gateContext, finalManifestBytes: Buffer.from("other") }), "IMPLEMENTATION_GATE");
  const pendingGate = implementationGate(pending);
  pendingGate.reviewFinalManifestSha256 = sha256Bytes(finalBytes);
  pendingGate.tzSha256 = sha256Bytes(tzBytes);
  expectThrow(() => validateImplementationGate(resign(pendingGate), { ...gateContext, classification: pending }), "IMPLEMENTATION_GATE");

  const publication = publicationGate({ implementation: validGate, publication: publicationClassification, checks: publicationChecks });
  const checksBytes = jsonBytes(publicationChecks);
  const publicationContext = {
    implementationGate: validGate,
    implementationGateContext: gateContext,
    publicationClassification,
    checksManifest: publicationChecks,
    checksManifestBytes: checksBytes,
    cycleId: "cycle-1",
    revision: 11,
    tzBytes,
    publishBase: O("a"),
    expectedTreeOid: O("c"),
    validatedDiffBytes: Buffer.from("validated-diff"),
    publishedFiles: ["a.md", "z.md"],
    executionCeilingIdentity: H("9"),
  };
  assert(validatePublicationGate(publication, publicationContext));
  expectEveryRequiredField(publication, Object.keys(publication), (value) => validatePublicationGate(value, publicationContext), "PUBLICATION_GATE");
  for (const mutate of [
    (value) => { value.publishedFiles = ["z.md", "a.md"]; },
    (value) => { value.publishedFiles = ["a.md", "a.md"]; },
    (value) => { value.specStatus = "pending"; },
    (value) => { value.implementationGateIdentity = H("9"); },
    (value) => { value.tzSha256 = H("9"); },
    (value) => { value.extra = true; },
  ]) {
    const invalid = clone(publication);
    mutate(invalid);
    expectThrow(() => validatePublicationGate(resign(invalid), publicationContext), "PUBLICATION_GATE");
  }
  expectThrow(() => validatePublicationGate(publication, { ...publicationContext, checksManifestBytes: jsonBytes(checksManifest(O("c"), 1)) }), "PUBLICATION_GATE");

  const proof = noOpProof({ implementation: validGate, publication: publicationClassification, checks: publicationChecks });
  const proofContext = {
    implementationGate: validGate,
    implementationGateContext: gateContext,
    publicationClassification,
    checksManifest: publicationChecks,
    checksManifestBytes: checksBytes,
    cycleId: "cycle-1",
    revision: 11,
    tzBytes,
    publishBase: O("a"),
    remoteHead: O("a"),
    publishBaseTreeOid: O("c"),
    publicationGateExists: false,
  };
  const correctedProof = resign({ ...proof, tzSha256: sha256Bytes(tzBytes) });
  assert(validateNoOpProof(correctedProof, proofContext));
  expectEveryRequiredField(correctedProof, Object.keys(correctedProof), (value) => validateNoOpProof(value, proofContext), "NOOP_PROOF");
  for (const mutate of [
    (value) => { value.remoteHead = O("d"); },
    (value) => { value.remoteTreeOid = O("d"); },
    (value) => { value.validatedDiffSha256 = H("8"); },
    (value) => { value.specStatus = "pending"; },
    (value) => { value.mutationRequired = true; },
    (value) => { value.tzSha256 = H("9"); },
    (value) => { value.extra = true; },
  ]) {
    const invalid = clone(correctedProof);
    mutate(invalid);
    expectThrow(() => validateNoOpProof(resign(invalid), proofContext), "NOOP_PROOF");
  }
  expectThrow(() => validateNoOpProof(correctedProof, { ...proofContext, publicationGateExists: true }), "NOOP_PROOF");
  expectThrow(() => validateNoOpProof(correctedProof, { ...proofContext, checksManifestBytes: jsonBytes(checksManifest(O("c"), 1)) }), "NOOP_PROOF");
  const uncertainFixed = classification({ phase: "publication", decision: "fixed", uncertainty: true, streamClass: "substantial" });
  const uncertainProof = noOpProof({ implementation: validGate, publication: uncertainFixed, checks: publicationChecks });
  expectThrow(() => validateNoOpProof(resign({ ...uncertainProof, tzSha256: sha256Bytes(tzBytes) }), { ...proofContext, publicationClassification: uncertainFixed }), "NOOP_PROOF");

  assert(validatePendingPublicationBlock({ state: "B01", reason: "Отсутствует проверенная Spec revision", owner: "оператор", unblockEvent: "Spec revision существует и проверена", return_state: "G01" }));
  expectThrow(() => validatePendingPublicationBlock({ state: "B01", reason: "Ждём", owner: "оператор", unblockEvent: "готово", return_state: "G01" }), "PENDING_BLOCKER");

  assert(validateChecksManifest(publicationChecks));
  expectThrow(() => validateChecksManifest(resign({ ...publicationChecks, expectedTreeOid: "a".repeat(41) })), "CHECKS_MANIFEST");
  expectEveryRequiredField(publicationChecks, [
    "schemaVersion", "expectedTreeOid", "checks", "identity",
    ...["name", "argv", "cwd", "startedAt", "finishedAt", "exitCode", "stdoutSha256", "stderrSha256"].map((key) => `checks.0.${key}`),
  ], validateChecksManifest, "CHECKS_MANIFEST");
  for (const mutate of [
    (value) => { value.checks = []; },
    (value) => { value.checks[0].extra = true; },
    (value) => { value.checks.push(clone(value.checks[0])); },
    (value) => { value.checks[0].argv = ["bash", "-c", "true"]; },
    (value) => { value.checks[0].argv = ["env", "X=1", "/bin/sh", "-c", "true"]; },
    (value) => { value.checks[0].argv = ["env", "-S", "sh -c true"]; },
    (value) => { value.checks[0].cwd = "../outside"; },
    (value) => { value.checks[0].finishedAt = "2025-08-03T00:00:00.000Z"; },
  ]) {
    const invalid = clone(publicationChecks);
    mutate(invalid);
    expectThrow(() => validateChecksManifest(resign(invalid)), "CHECKS_MANIFEST");
  }

  for (const state of Object.keys(states)) assert(validateActiveCycle(cycle(state)));
  const activeCycleFixture = cycle("C08");
  expectEveryRequiredField(activeCycleFixture, [
    "schemaVersion", "cycleId", "revision", "state", "activeRunId", "lastCompletedRun", "owner",
    "sourceContext", "signalContext", "resumeContexts", "lockPath", "identity",
    ...["schemaVersion", "host", "bootIdentity", "pid", "pgid", "processStartToken", "operationId", "identity"].map((key) => `owner.${key}`),
  ], (value) => validateActiveCycle(value, { states }), "ACTIVE_CYCLE");
  expectThrow(() => validateActiveCycle(cycle("P03", null), { states }), "ACTIVE_CYCLE");
  expectThrow(() => validateActiveCycle(cycle("C09", "run-1"), { states }), "ACTIVE_CYCLE");
  const unsafeCycle = cycle("P03");
  unsafeCycle.activeRunId = "../run";
  expectThrow(() => validateActiveCycle(resign(unsafeCycle), { states }), "ACTIVE_CYCLE");
  const cycleTemporary = mkdtempSync(join(tmpdir(), "workflow-cycle-self-test-"));
  try {
    const owner = await createOperationOwner("op-cycle");
    const cycleRoot = join(cycleTemporary, "cycles", "cycle-1", "revision-11");
    mkdirSync(join(cycleRoot, "review-runs", "review-1"), { recursive: true });
    mkdirSync(join(cycleRoot, "review-runs", "review-2"), { recursive: true });
    const publicationRoot = join(cycleRoot, "publication-runs", "publish-1");
    const otherPublicationRoot = join(cycleRoot, "publication-runs", "other");
    const noOpRoot = join(cycleRoot, "publication-runs", "publish-noop");
    mkdirSync(publicationRoot, { recursive: true });
    mkdirSync(otherPublicationRoot, { recursive: true });
    mkdirSync(noOpRoot, { recursive: true });
    writeFileSync(join(cycleRoot, "implementation-gate.json"), jsonBytes(validGate));
    writeFileSync(join(publicationRoot, "publication-gate.json"), jsonBytes(publication));
    writeFileSync(join(otherPublicationRoot, "publication-gate.json"), jsonBytes(publication));
    writeFileSync(join(noOpRoot, "no-op-proof.json"), jsonBytes(correctedProof));
    const operationLock = acquireOperationLock({ taskRoot: cycleTemporary, owner });
    expectThrow(() => acquireOperationLock({ taskRoot: cycleTemporary, owner }), "ACTIVE_CYCLE_LOCK_BUSY");
    const liveLockRecord = JSON.parse(readFileSync(join(cycleTemporary, ".operation.lock"), "utf8"));
    const liveAuthorization = resign({
      schemaVersion: 1,
      decisionId: "reclaim-live-lock",
      decisionKind: "quarantine_recovery",
      requestedAction: "reclaim_stale_operation_lock",
      targetOperationLockIdentity: liveLockRecord.identity,
      authorizedObjects: [".operation.lock"],
      answer: "approved",
      decidedBy: { kind: "user", identityHint: H("7") },
      evidenceIdentity: H("8"),
      decidedAt: "2026-08-03T00:00:00.000Z",
    });
    assert(validateQuarantineRecoveryAuthorization(liveAuthorization));
    expectEveryRequiredField(liveAuthorization, Object.keys(liveAuthorization), validateQuarantineRecoveryAuthorization, "QUARANTINE_RECOVERY");
    await expectReject(reclaimStaleOperationLock({ taskRoot: cycleTemporary, targetOperationLockIdentity: liveLockRecord.identity, owner, authorization: liveAuthorization }), "ACTIVE_CYCLE_LOCK_UNKNOWN");
    const c08 = cycle("C08");
    const transition = (input) => transitionActiveCycle({ ...input, states, operationLock, taskRoot: cycleTemporary, owner });
    const p03 = transition({ current: c08, expectedIdentity: c08.identity, nextState: "P03", nextRunId: "review-1" });
    assert.equal(p03.activeRunId, "review-1");
    assert.equal(transition({ current: p03, expectedIdentity: p03.identity, nextState: "P03", nextRunId: "other" }).activeRunId, "review-1");
    const c09 = transition({ current: p03, expectedIdentity: p03.identity, nextState: "C09" });
    assert.equal(c09.activeRunId, null);
    const c11 = cycle("C11");
    const implementationEvidence = { ...states.G01.requiredGate, identity: validGate.identity };
    const publicationEvidence = { ...states.C12.requiredGate, identity: publication.identity };
    const noOpEvidence = { ...states.G01.requiredTransitionProofs.C13, identity: correctedProof.identity };
    expectThrow(() => transition({ current: c11, expectedIdentity: c11.identity, nextState: "G01", nextRunId: "publish-1" }), "ACTIVE_CYCLE_GATE");
    expectThrow(() => transition({ current: c11, expectedIdentity: c11.identity, nextState: "G01", nextRunId: "publish-1", gateEvidence: { ...implementationEvidence, identity: H("b") } }), "ACTIVE_CYCLE_GATE");
    const g01 = transition({ current: c11, expectedIdentity: c11.identity, nextState: "G01", nextRunId: "publish-1", gateEvidence: implementationEvidence });
    expectThrow(() => transition({ current: g01, expectedIdentity: g01.identity, nextState: "C12" }), "ACTIVE_CYCLE_GATE");
    const c12 = transition({ current: g01, expectedIdentity: g01.identity, nextState: "C12", gateEvidence: publicationEvidence });
    assert.equal(c12.activeRunId, "publish-1");
    const g01NoOp = cycle("G01", "publish-noop");
    expectThrow(() => transition({ current: g01NoOp, expectedIdentity: g01NoOp.identity, nextState: "C13" }), "ACTIVE_CYCLE_PROOF");
    expectThrow(() => transition({ current: g01NoOp, expectedIdentity: g01NoOp.identity, nextState: "C13", transitionProof: { ...noOpEvidence, identity: H("d") } }), "ACTIVE_CYCLE_PROOF");
    const noOpCompleted = transition({ current: g01NoOp, expectedIdentity: g01NoOp.identity, nextState: "C13", transitionProof: noOpEvidence });
    assert.equal(noOpCompleted.activeRunId, null);
    assert.equal(noOpCompleted.lastCompletedRun.entryGateIdentity, correctedProof.implementationGateIdentity);
    expectThrow(() => transition({ current: c12, expectedIdentity: c12.identity, nextState: "C13" }), "ACTIVE_CYCLE_COMPLETION");
    const completed = transition({
      current: c12,
      expectedIdentity: c12.identity,
      nextState: "C13",
      completionEvidence: { kind: "publication", runId: "publish-1", entryGateIdentity: publication.identity, terminalEvidenceIdentity: H("e") },
    });
    assert.equal(completed.lastCompletedRun.runId, "publish-1");
    const d02Frame = resumeFrame(c12, { holdingState: "D02", targetState: "C12", savedRunId: "publish-1", gateIdentity: publication.identity });
    const d02 = transition({ current: c12, expectedIdentity: c12.identity, nextState: "D02", holdingFrame: d02Frame });
    assert.equal(d02.activeRunId, null);
    assert.equal(d02.resumeContexts.at(-1).identity, d02Frame.identity);
    const restored = transition({ current: d02, expectedIdentity: d02.identity, nextState: "C12", nextRunId: "publish-1", resumeFrameIdentity: d02Frame.identity, gateEvidence: publicationEvidence });
    assert.equal(restored.activeRunId, "publish-1");
    assert.equal(restored.resumeContexts.length, 0);
    const wrongGateFrame = resign({ ...d02Frame, gateIdentity: H("a") });
    const wrongGateCycle = resign({ ...d02, resumeContexts: [wrongGateFrame] });
    expectThrow(() => transition({ current: wrongGateCycle, expectedIdentity: wrongGateCycle.identity, nextState: "C12", nextRunId: "publish-1", resumeFrameIdentity: wrongGateFrame.identity, gateEvidence: publicationEvidence }), "ACTIVE_CYCLE_RESUME");
    expectThrow(() => transition({ current: d02, expectedIdentity: d02.identity, nextState: "C12", nextRunId: "other", resumeFrameIdentity: d02Frame.identity, gateEvidence: publicationEvidence }), "ACTIVE_CYCLE_RESUME");
    const reviewBlockFrame = resumeFrame(p03, { holdingState: "B01", targetState: "P03", register: "return_state" });
    const reviewBlocked = transition({ current: p03, expectedIdentity: p03.identity, nextState: "B01", holdingFrame: reviewBlockFrame });
    expectThrow(() => transition({ current: reviewBlocked, expectedIdentity: reviewBlocked.identity, nextState: "P03", nextRunId: "review-1", resumeFrameIdentity: reviewBlockFrame.identity }), "ACTIVE_CYCLE_RESUME");
    const resumedReview = transition({ current: reviewBlocked, expectedIdentity: reviewBlocked.identity, nextState: "P03", nextRunId: "review-2", resumeFrameIdentity: reviewBlockFrame.identity });
    assert.equal(resumedReview.activeRunId, "review-2");
    assert.equal(resumedReview.resumeContexts.length, 0);
    expectThrow(() => transition({ current: c08, expectedIdentity: c08.identity, nextState: "C10" }), "ACTIVE_CYCLE_CAS");
    expectThrow(() => transition({ current: c08, expectedIdentity: H("f"), nextState: "P03", nextRunId: "review-2" }), "ACTIVE_CYCLE_CAS");
    expectThrow(() => transitionActiveCycle({ current: c08, expectedIdentity: c08.identity, nextState: "P03", nextRunId: "review-2", states, operationLock: null, taskRoot: cycleTemporary, owner }), "ACTIVE_CYCLE_LOCK");
    releaseOperationLock(operationLock);
    expectThrow(() => releaseOperationLock(operationLock), "ACTIVE_CYCLE_LOCK");

    const staleOwner = resign({
      ...owner,
      host: hostname(),
      pid: 999_999,
      pgid: 999_999,
      processStartToken: "missing-process",
      operationId: "stale-owner",
    });
    const staleRecord = resign({
      schemaVersion: 1,
      kind: "operation",
      taskRootIdentity: sha256Bytes(Buffer.from(canonicalJson(realpathSync(cycleTemporary)))),
      owner: staleOwner,
      createdAt: "2026-08-03T00:00:00.000Z",
    });
    writeFileSync(join(cycleTemporary, ".operation.lock"), `${JSON.stringify(staleRecord, null, 2)}\n`, { mode: 0o600 });
    const staleAuthorization = resign({ ...liveAuthorization, decisionId: "reclaim-stale-lock", targetOperationLockIdentity: staleRecord.identity });
    await expectReject(reclaimStaleOperationLock({ taskRoot: cycleTemporary, targetOperationLockIdentity: staleRecord.identity, owner }), "QUARANTINE_RECOVERY");
    const reclaimed = await reclaimStaleOperationLock({ taskRoot: cycleTemporary, targetOperationLockIdentity: staleRecord.identity, owner, authorization: staleAuthorization });
    assert(existsSync(reclaimed.quarantinedPath));
    assert(existsSync(reclaimed.auditPath));
    const reclaimAudit = JSON.parse(readFileSync(reclaimed.auditPath, "utf8"));
    assert(validateQuarantineRecoveryAudit(reclaimAudit));
    expectEveryRequiredField(reclaimAudit, Object.keys(reclaimAudit), validateQuarantineRecoveryAudit, "QUARANTINE_RECOVERY");
    assert.equal(reclaimAudit.authorizationIdentity, staleAuthorization.identity);
    releaseOperationLock(reclaimed.operationLock);

    const casRoot = join(cycleTemporary, "cas");
    mkdirSync(casRoot);
    const initial = cycle("C08");
    writeFileSync(join(casRoot, "active-cycle.json"), `${JSON.stringify(initial, null, 2)}\n`);
    const moved = await compareAndSetActiveCycle({ taskRoot: casRoot, owner, expectedIdentity: initial.identity, nextState: "P03", nextRunId: "review-cas", states });
    assert.equal(moved.activeRunId, "review-cas");
    assert(validateActiveCycle(JSON.parse(readFileSync(join(casRoot, "active-cycle.json"), "utf8")), { states, taskRoot: casRoot }));
    await expectReject(compareAndSetActiveCycle({ taskRoot: casRoot, owner, expectedIdentity: initial.identity, nextState: "C09", states }), "ACTIVE_CYCLE_CAS");
    const held = acquireOperationLock({ taskRoot: casRoot, owner });
    await expectReject(compareAndSetActiveCycle({ taskRoot: casRoot, owner, expectedIdentity: moved.identity, nextState: "C09", states }), "ACTIVE_CYCLE_LOCK_BUSY");
    releaseOperationLock(held);
    writeFileSync(join(casRoot, ".operation.lock"), "stale\n", { flag: "wx" });
    expectThrow(() => acquireOperationLock({ taskRoot: casRoot, owner }), "ACTIVE_CYCLE_LOCK_BUSY");
    assert(existsSync(join(casRoot, ".operation.lock")), "stale lock не удаляется автоматически");
  } finally {
    rmSync(cycleTemporary, { recursive: true, force: true });
  }

  const temporary = mkdtempSync(join(tmpdir(), "workflow-gates-self-test-"));
  try {
    const repo = join(temporary, "repo");
    const taskRoot = join(temporary, "task-root");
    const runRoot = join(taskRoot, "cycles", "cycle-1", "revision-11", "publication-runs", "publish-1");
    mkdirSync(repo);
    mkdirSync(runRoot, { recursive: true });
    const publicationPath = { taskRoot, cycleId: "cycle-1", revision: 11, publicationRunId: "publish-1", publicationRunRoot: runRoot };
    git(repo, ["init", "-q"]);
    git(repo, ["config", "user.email", "self-test@example.invalid"]);
    git(repo, ["config", "user.name", "Self Test"]);
    writeFileSync(join(repo, "a.txt"), "base\n");
    writeFileSync(join(repo, "check.mjs"), "import fs from 'node:fs'; if (!fs.existsSync('b.txt')) process.exit(2); console.log(process.argv[2]);\n");
    chmodSync(join(repo, "check.mjs"), 0o755);
    git(repo, ["add", "."]);
    git(repo, ["commit", "-qm", "base"]);
    const base = git(repo, ["rev-parse", "HEAD"]);
    writeFileSync(join(repo, "a.txt"), "changed\n");
    writeFileSync(join(repo, "b.txt"), "new\n");
    await expectReject(sealExpectedTree({ repo, publishBase: base, publishedFiles: ["a.txt", "b.txt"], ...publicationPath }), "PUBLICATION_RUN_OPEN");
    const runOpen = openPublicationRun({
      ...publicationPath,
      implementationGateIdentity: validGate.identity,
      sourceContextIdentity: cycle("C11").sourceContext.identity,
      publishBase: base,
      openedBy: runtimeOwner,
    });
    assert(validatePublicationRunOpen(runOpen));
    const sealed = await sealExpectedTree({ repo, publishBase: base, publishedFiles: ["a.txt", "b.txt"], ...publicationPath });
    assert.equal(sealed.validatedDiffSha256, sha256Bytes(sealed.diffBytes));
    const manifest = await runExactTreeChecks({
      repo,
      expectedTreeOid: sealed.expectedTreeOid,
      ...publicationPath,
      checks: [{ name: "exact-tree", argv: [process.execPath, "check.mjs", ";|$`"], cwd: "." }],
    });
    assert.equal(manifest.expectedTreeOid, sealed.expectedTreeOid);
    assert.equal(manifest.checks[0].exitCode, 0);
    assert(validateChecksManifest(manifest));
    writeFileSync(join(repo, "foreign.txt"), "foreign\n");
    await expectReject(sealExpectedTree({ repo, publishBase: base, publishedFiles: ["a.txt", "b.txt"], ...publicationPath }), "TREE_SEAL");
    const noOpRepo = join(temporary, "noop-repo");
    const noOpRunRoot = join(taskRoot, "cycles", "cycle-1", "revision-11", "publication-runs", "publish-2");
    mkdirSync(noOpRepo);
    mkdirSync(noOpRunRoot, { recursive: true });
    git(noOpRepo, ["init", "-q"]);
    git(noOpRepo, ["config", "user.email", "self-test@example.invalid"]);
    git(noOpRepo, ["config", "user.name", "Self Test"]);
    writeFileSync(join(noOpRepo, "unchanged.txt"), "unchanged\n");
    git(noOpRepo, ["add", "."]);
    git(noOpRepo, ["commit", "-qm", "base"]);
    const noOpBase = git(noOpRepo, ["rev-parse", "HEAD"]);
    openPublicationRun({
      taskRoot,
      cycleId: "cycle-1",
      revision: 11,
      publicationRunId: "publish-2",
      publicationRunRoot: noOpRunRoot,
      implementationGateIdentity: validGate.identity,
      sourceContextIdentity: cycle("C11").sourceContext.identity,
      publishBase: noOpBase,
      openedBy: runtimeOwner,
    });
    const noOpSealed = await sealExpectedTree({
      repo: noOpRepo,
      publishBase: noOpBase,
      publishedFiles: [],
      taskRoot,
      cycleId: "cycle-1",
      revision: 11,
      publicationRunId: "publish-2",
      publicationRunRoot: noOpRunRoot,
    });
    assert.equal(noOpSealed.validatedDiffSha256, gateConstants.EMPTY_DIFF_SHA256);
    assert.equal(noOpSealed.diffBytes.length, 0);
    const artifactPath = join(runRoot, "artifact.json");
    writeArtifactAtomically(artifactPath, publicationChecks);
    assert.deepEqual(JSON.parse(readFileSync(artifactPath, "utf8")), publicationChecks);
    expectThrow(() => writeArtifactAtomically(artifactPath, publicationChecks), "ARTIFACT_EXISTS");
  } finally {
    rmSync(temporary, { recursive: true, force: true });
  }

  assert.notEqual(canonicalJson({ a: 1, b: 2 }), canonicalJson({ a: 1, b: 3 }));
  console.log("workflow-spec-review-gates self-test passed");
}

try {
  await main();
} catch (error) {
  console.error(`Error: workflow-spec-review-gates-self-test [${error?.code ?? "UNEXPECTED"}]: ${error instanceof Error ? error.message : "неизвестная ошибка"}`);
  if (error instanceof Error && error.stack) console.error(error.stack);
  process.exitCode = 1;
}
