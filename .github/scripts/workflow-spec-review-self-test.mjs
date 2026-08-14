#!/usr/bin/env node

import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import {
  chmodSync,
  copyFileSync,
  existsSync,
  lstatSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  readdirSync,
  realpathSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import process from "node:process";
import {
  CANONICAL_REVIEW_PROMPT_SHA256,
  ENV_ALLOWLIST,
  PROCESS_POLICY,
  assertCanonicalPrompt,
  assertNodeVersion,
  buildQualification,
  buildClaudeArgs,
  buildReviewFinalArtifacts,
  buildReviewerEnvironment,
  executeReviewRun,
  jcsIdentity,
  openReviewRun,
  parseReviewerResponse,
  prepareQualificationArtifacts,
  readStrictPrompt,
  scanSecrets,
  sha256Bytes,
  validateLaunchIntent,
  validateProcessResult,
  validateQualification,
  validateReviewManifest,
  validateReviewRunOpen,
  validateReviewerResponse,
  validateReviewSummary,
  validateStartedArtifact,
  verifyFinalDirectory,
} from "./workflow-spec-review.mjs";
import { qualifyReviewTransaction, repositoryIdentity } from "./workflow-cycle-store.mjs";
import { readRegistry } from "./workflow-state-policy.mjs";

const H = (character) => character.repeat(64);

function git(repository, arguments_) {
  return execFileSync("git", ["-C", repository, ...arguments_], { encoding: "utf8" }).trim();
}

function artifact(value) {
  const result = { ...structuredClone(value), identity: "" };
  result.identity = jcsIdentity(result);
  return result;
}

function expectThrow(callback, code = null) {
  let caught = null;
  try {
    callback();
  } catch (error) {
    caught = error;
  }
  assert(caught, "ожидалась ошибка");
  if (code !== null) assert.equal(caught.code, code);
  return caught;
}

async function expectReject(promise, code = null) {
  let caught = null;
  try {
    await promise;
  } catch (error) {
    caught = error;
  }
  assert(caught, "ожидалась ошибка Promise");
  if (code !== null) assert.equal(caught.code, code);
  return caught;
}

function writeExecutable(path, modePath, mcpPidPath) {
  writeFileSync(path, `#!/usr/bin/env node
const fs = require("node:fs");
const path = require("node:path");
const { spawn } = require("node:child_process");
const args = process.argv.slice(2);
const mode = fs.existsSync(${JSON.stringify(modePath)}) ? fs.readFileSync(${JSON.stringify(modePath)}, "utf8").trim() : "valid";
if (args[0] === "--version") {
  if (mode === "mcp-preflight") {
    const child = spawn(process.execPath, ["-e", "setInterval(() => {}, 1000)", "boost:mcp"], { detached: false, stdio: "ignore" });
    fs.writeFileSync(${JSON.stringify(mcpPidPath)}, String(child.pid));
    child.unref();
    process.exit(0);
  }
  console.log("claude-self-test-1");
  process.exit(0);
}
if (args[0] === "auth" && args[1] === "status") { console.log("oauth_token"); process.exit(0); }
const printIndex = args.lastIndexOf("--print");
const prompt = printIndex === -1 ? "" : args[printIndex + 1];
if (prompt.includes("WORKFLOW_SPEC_REVIEW_READ_SMOKE_OK")) { console.log("WORKFLOW_SPEC_REVIEW_READ_SMOKE_OK"); process.exit(0); }
if (mode === "invalid") { console.log("invalid response"); process.exit(0); }
if (mode === "oauth") { console.log(process.env.CLAUDE_CODE_OAUTH_TOKEN); process.exit(0); }
if (mode === "error") { console.error("review failed"); process.exit(2); }
const manifest = JSON.parse(fs.readFileSync(path.join(process.cwd(), "input", "manifest.json"), "utf8"));
console.log(manifest.identity);
console.log("REVIEW_FINDINGS");
console.log("[]");
console.log("REVIEW_CHECKED_SCOPE");
console.log('["tz.md","author-review.md","base...head diff","current worktree snapshot"]');
console.log("REVIEW_UNCHECKED_SCOPE");
console.log('["runtime execution"]');
console.log("REVIEW_VERDICT");
console.log("блокеров нет");
`);
  chmodSync(path, 0o755);
}

function createSourceRepository(root, promptSource) {
  const repository = join(root, "source");
  mkdirSync(join(repository, "docs", "workflow", "pr-correction"), { recursive: true });
  copyFileSync(promptSource, join(repository, "docs", "workflow", "pr-correction", "external-spec-review-prompt.md"));
  writeFileSync(join(repository, "base.txt"), "base\n");
  git(repository, ["init", "-q"]);
  git(repository, ["config", "user.email", "self-test@example.invalid"]);
  git(repository, ["config", "user.name", "Workflow Self Test"]);
  git(repository, ["add", "."]);
  git(repository, ["commit", "-qm", "base"]);
  const base = git(repository, ["rev-parse", "HEAD"]);
  writeFileSync(join(repository, "head.txt"), "head\n");
  git(repository, ["add", "head.txt"]);
  git(repository, ["commit", "-qm", "head"]);
  const head = git(repository, ["rev-parse", "HEAD"]);
  return { repository: realpathSync(repository), base, head };
}

function createTaskRoot(root, cycleId = "cycle-1", revision = 1) {
  const taskRoot = join(root, `task-${cycleId}`);
  const revisionRoot = join(taskRoot, "cycles", cycleId, `revision-${revision}`);
  mkdirSync(revisionRoot, { recursive: true });
  writeFileSync(join(revisionRoot, "tz.md"), "# ТЗ\n\nПроверяемый контракт.\n");
  writeFileSync(join(revisionRoot, "author-review.md"), "# Авторское ревью\n\nБлокеров нет.\n");
  const revisionSeal = artifact({ schemaVersion: 1, cycleId, revision, marker: "sealed" });
  writeFileSync(join(revisionRoot, "revision-seal.json"), `${JSON.stringify(revisionSeal, null, 2)}\n`);
  return { taskRoot, revisionRoot, revisionSeal };
}

function createSettings(root) {
  const home = join(root, "home");
  const settingsPath = join(home, ".claude", "settings.json");
  mkdirSync(join(home, ".claude"), { recursive: true });
  writeFileSync(settingsPath, `${JSON.stringify({ env: { CLAUDE_CODE_OAUTH_TOKEN: "oauth-self-test-value-123456" } })}\n`);
  chmodSync(settingsPath, 0o600);
  return { home, settingsPath };
}

function owner(operationId) {
  return artifact({
    schemaVersion: 1,
    host: "self-test",
    bootIdentity: H("b"),
    pid: process.pid,
    pgid: process.pid,
    processStartToken: "self-test-start",
    operationId,
  });
}

function openRun({ task, runId, sourceContextIdentity = H("1"), reviewSnapshotIdentity = H("2") }) {
  return openReviewRun({
    taskRoot: task.taskRoot,
    cycleId: "cycle-1",
    revision: 1,
    runId,
    revisionSealIdentity: task.revisionSeal.identity,
    sourceContextIdentity,
    reviewSnapshotIdentity,
    openedBy: owner(`open-${runId}`),
  });
}

function response(identity, findings = []) {
  const verdict = findings.some((finding) => ["P0", "P1", "P2"].includes(finding.priority)) ? "нужны правки" : "блокеров нет";
  return Buffer.from([
    identity,
    "REVIEW_FINDINGS",
    JSON.stringify(findings),
    "REVIEW_CHECKED_SCOPE",
    '["весь снимок"]',
    "REVIEW_UNCHECKED_SCOPE",
    '["runtime"]',
    "REVIEW_VERDICT",
    verdict,
    "",
  ].join("\n"));
}

function reidentify(value) {
  const copy = structuredClone(value);
  copy.identity = "";
  copy.identity = jcsIdentity(copy);
  return copy;
}

function assertMissingAndExtraRejected(value, validator, code) {
  const keys = Object.keys(value).filter((key) => key !== "identity");
  const missing = structuredClone(value);
  delete missing[keys[0]];
  missing.identity = jcsIdentity(missing);
  expectThrow(() => validator(missing), code);
  const extra = reidentify({ ...structuredClone(value), unexpected: true });
  expectThrow(() => validator(extra), code);
}

function makeTreeWritable(path) {
  if (!existsSync(path)) return;
  const stat = lstatSync(path);
  if (stat.isSymbolicLink()) return;
  if (stat.isDirectory()) {
    chmodSync(path, 0o700);
    for (const entry of readdirSync(path)) makeTreeWritable(join(path, entry));
  } else {
    chmodSync(path, 0o600);
  }
}

function processExists(pid) {
  try {
    process.kill(pid, 0);
    return true;
  } catch (error) {
    return error?.code === "EPERM";
  }
}

async function waitForProcessExit(pid, timeoutMs = 2_000) {
  const deadline = Date.now() + timeoutMs;
  while (processExists(pid) && Date.now() < deadline) {
    await new Promise((resolvePromise) => setTimeout(resolvePromise, 25));
  }
  return !processExists(pid);
}

async function main() {
  const temporary = mkdtempSync(join(tmpdir(), "workflow-spec-review-self-test-"));
  try {
    assert.equal(assertNodeVersion("22.0.0"), 22);
    expectThrow(() => assertNodeVersion("21.9.0"), "NODE_VERSION");

    const promptSource = resolve(process.cwd(), "docs/workflow/pr-correction/external-spec-review-prompt.md");
    const prompt = readStrictPrompt(promptSource);
    assert.equal(sha256Bytes(prompt.bytes), CANONICAL_REVIEW_PROMPT_SHA256);
    assert(assertCanonicalPrompt(prompt.bytes));
    const changedPrompt = join(temporary, "changed-prompt.md");
    writeFileSync(changedPrompt, Buffer.concat([prompt.bytes, Buffer.from("\n")]));
    expectThrow(() => assertCanonicalPrompt(readStrictPrompt(changedPrompt).bytes), "PROMPT_HASH");

    const cleanEnvironment = buildReviewerEnvironment({ HOME: "/safe", PATH: "/bin", LANG: "C.UTF-8", ANTHROPIC_API_KEY: "forbidden" }, "claude", "oauth-self-test-value-123456");
    assert.deepEqual(Object.keys(cleanEnvironment).sort(), ["CLAUDE_CODE_OAUTH_TOKEN", "HOME", "LANG", "PATH"]);
    assert(ENV_ALLOWLIST.includes("HOME"));
    expectThrow(() => buildReviewerEnvironment({}, "gemini"), "REVIEW_CLIENT");
    assert.equal(buildClaudeArgs("/review", "prompt").at(-1), "prompt");

    const sampleIdentity = H("a");
    assert(validateReviewerResponse(response(sampleIdentity), sampleIdentity));
    assert.equal(parseReviewerResponse(response(sampleIdentity), sampleIdentity).verdict, "блокеров нет");
    const finding = { id: "P1-01", priority: "P1", summary: "Пробел", evidence: ["current/a.md:1"], minimalFix: "Исправить" };
    assert.equal(parseReviewerResponse(response(sampleIdentity, [finding]), sampleIdentity).verdict, "нужны правки");
    for (const invalid of [
      Buffer.from(`prefix\n${response(sampleIdentity)}`),
      Buffer.from(response(sampleIdentity).toString("utf8").replace("блокеров нет", "неизвестно")),
      Buffer.from(response(sampleIdentity).toString("utf8").replace("REVIEW_CHECKED_SCOPE", "REVIEW_FINDINGS")),
      response(sampleIdentity, [{ ...finding, extra: true }]),
    ]) assert.equal(validateReviewerResponse(invalid, sampleIdentity), false);

    const secretRoot = join(temporary, "secret-scan");
    for (const section of ["current", "base", "input"]) mkdirSync(join(secretRoot, section), { recursive: true });
    writeFileSync(join(secretRoot, "current", "secret.txt"), `ghp_${"A".repeat(36)}`);
    expectThrow(() => scanSecrets(secretRoot), "SECRET_SCAN");
    writeFileSync(join(secretRoot, "current", "secret.txt"), "Authorization: Bearer <TOKEN>\n");
    assert.deepEqual(scanSecrets(secretRoot), []);

    const source = createSourceRepository(temporary, promptSource);
    const settings = createSettings(temporary);
    const modePath = join(temporary, "fake-mode");
    const mcpPidPath = join(temporary, "mcp-child.pid");
    const executable = join(temporary, "fake-claude");
    writeExecutable(executable, modePath, mcpPidPath);
    writeFileSync(modePath, "valid\n");
    const environment = { ...process.env, HOME: settings.home, PATH: process.env.PATH, TMPDIR: temporary };
    const task = createTaskRoot(temporary);
    const reviewSnapshotIdentity = H("2");
    const sourceContext = artifact({
      schemaVersion: 1,
      sourceKind: "commit",
      repositoryFullName: "Etogerman/Project-1",
      repositoryIdentity: repositoryIdentity({ repositoryFullName: "Etogerman/Project-1", repositoryRealPath: source.repository }),
      repositoryRealPath: source.repository,
      pullRequestNumber: null,
      baseOid: source.base,
      inputHeadOid: source.head,
      inputTreeOid: git(source.repository, ["rev-parse", `${source.head}^{tree}`]),
      reviewSnapshotIdentity,
    });
    const signalContext = artifact({
      schemaVersion: 1,
      signalId: "signal-1",
      kind: "commit_review",
      sourceState: "C01",
      originCheckState: null,
      reviewSnapshotIdentity,
      evidenceIdentity: H("3"),
      sourceContextIdentity: sourceContext.identity,
    });
    const common = {
      selfTest: true,
      repo: source.repository,
      base: source.base,
      head: source.head,
      taskRoot: task.taskRoot,
      cycleId: "cycle-1",
      revision: 1,
      sourceContextIdentity: sourceContext.identity,
      reviewSnapshotIdentity,
      settingsPaths: { claude: settings.settingsPath },
      commands: { claude: executable },
      environment,
      nodeVersion: "22.0.0",
      hardTimeoutMs: 5_000,
      overallTimeoutMs: 20_000,
      preflightTimeoutMs: 8_000,
      snapshotTimeoutMs: 8_000,
    };

    openRun({ task, runId: "valid-run", sourceContextIdentity: sourceContext.identity });
    const activeCycle = artifact({
      schemaVersion: 1,
      cycleId: "cycle-1",
      revision: 1,
      state: "P03",
      activeRunId: "valid-run",
      lastCompletedRun: null,
      owner: owner("active-cycle"),
      sourceContext,
      signalContext,
      resumeContexts: [],
      lockPath: ".operation.lock",
    });
    writeFileSync(join(task.taskRoot, "active-cycle.json"), `${JSON.stringify(activeCycle, null, 2)}\n`);
    const successful = await executeReviewRun({ ...common, runId: "valid-run" });
    assert.equal(successful.status, "completed");
    assert.equal(successful.target, "P03");
    assert.equal(successful.qualificationRequired, true);
    const runRoot = successful.runRoot;
    const runOpen = validateReviewRunOpen(JSON.parse(readFileSync(join(runRoot, "run-open.json"), "utf8")));
    const manifest = validateReviewManifest(JSON.parse(readFileSync(join(runRoot, "review-manifest.json"), "utf8")));
    const launchIntent = validateLaunchIntent(JSON.parse(readFileSync(join(runRoot, "launch-intent.json"), "utf8")));
    const started = validateStartedArtifact(JSON.parse(readFileSync(join(runRoot, "started.json"), "utf8")));
    const processResult = validateProcessResult(JSON.parse(readFileSync(join(runRoot, "capture", "process-result.json"), "utf8")));
    const summary = validateReviewSummary(JSON.parse(readFileSync(join(runRoot, "capture", "summary.json"), "utf8")));
    assert.equal(runOpen.reviewRunId, "valid-run");
    assert.deepEqual(Object.keys(manifest.clients), ["claude"]);
    assert.deepEqual(Object.keys(manifest.processPolicy.clients), ["claude"]);
    assert.equal(manifest.processPolicy.maxParallel, 1);
    assert.equal(launchIntent.reviewManifestIdentity, manifest.identity);
    assert.equal(started.reviewManifestIdentity, manifest.identity);
    assert.equal(processResult.reviewManifestIdentity, manifest.identity);
    assert.equal(summary.reviewManifestIdentity, manifest.identity);
    assert.equal(readFileSync(join(runRoot, "capture", "stdout.bin"), "utf8").includes("gemini"), false);

    const reviewManifestBytes = readFileSync(join(runRoot, "review-manifest.json"));
    const blockingClaudeBytes = response(manifest.identity, [finding]);
    const disposition = (decision, target, rationale) => ({
      source: "claude",
      findingId: finding.id,
      priority: finding.priority,
      decision,
      target,
      evidenceRefs: ["current/a.md:1"],
      rationale,
    });
    const correctionQualification = buildQualification({
      reviewManifestBytes,
      claudeBytes: blockingClaudeBytes,
      dispositions: [disposition("confirmed_current_scope", "C07", "Пробел подтверждён в текущем объёме")],
    });
    assert.equal(correctionQualification.outcome.state, "C07");
    expectThrow(() => buildReviewFinalArtifacts({
      revisionRoot: task.revisionRoot,
      reviewManifestBytes,
      claudeBytes: blockingClaudeBytes,
      qualificationBytes: Buffer.from(`${JSON.stringify(correctionQualification)}\n`),
      consolidatedBytes: Buffer.from("не должен использоваться\n"),
    }), "FINAL_QUALIFICATION");
    const decisionQualification = buildQualification({
      reviewManifestBytes,
      claudeBytes: blockingClaudeBytes,
      dispositions: [disposition("confirmed_scope_or_risk", "D01", "Нужно решение по объёму")],
    });
    assert.equal(decisionQualification.outcome.state, "D01");
    expectThrow(() => buildReviewFinalArtifacts({
      revisionRoot: task.revisionRoot,
      reviewManifestBytes,
      claudeBytes: blockingClaudeBytes,
      qualificationBytes: Buffer.from(`${JSON.stringify(decisionQualification)}\n`),
      consolidatedBytes: Buffer.from("не должен использоваться\n"),
    }), "FINAL_QUALIFICATION");
    const rejectedQualification = buildQualification({
      reviewManifestBytes,
      claudeBytes: blockingClaudeBytes,
      dispositions: [disposition("rejected_with_evidence", null, "Замечание опровергнуто проверяемыми данными")],
    });
    assert.equal(rejectedQualification.outcome.state, "C09");

    const preparedQualification = prepareQualificationArtifacts({
      taskRoot: task.taskRoot,
      cycleId: "cycle-1",
      revision: 1,
      runId: "valid-run",
      dispositions: [],
    });
    const contradictoryConsolidated = Buffer.from(preparedQualification.consolidatedBytes.toString("utf8").replace("Состояние: C09.", "Состояние: C07."));
    expectThrow(() => buildReviewFinalArtifacts({
      revisionRoot: task.revisionRoot,
      reviewManifestBytes: preparedQualification.reviewManifestBytes,
      claudeBytes: preparedQualification.claudeBytes,
      qualificationBytes: preparedQualification.qualificationBytes,
      consolidatedBytes: contradictoryConsolidated,
    }), "FINAL_QUALIFICATION");

    assertMissingAndExtraRejected(runOpen, validateReviewRunOpen, "REVIEW_RUN_OPEN");
    assertMissingAndExtraRejected(launchIntent, validateLaunchIntent, "LAUNCH_INTENT");
    assertMissingAndExtraRejected(started, validateStartedArtifact, "REVIEW_STARTED");
    assertMissingAndExtraRejected(processResult, validateProcessResult, "PROCESS_RESULT");
    assertMissingAndExtraRejected(summary, validateReviewSummary, "REVIEW_SUMMARY");
    assertMissingAndExtraRejected(manifest, validateReviewManifest, "REVIEW_MANIFEST");

    await expectReject(qualifyReviewTransaction({ taskRoot: task.taskRoot, dispositions: [], states: readRegistry().states, crashAfter: 1 }), "OPERATION_CRASH");
    const qualified = await qualifyReviewTransaction({ taskRoot: task.taskRoot, dispositions: [], states: readRegistry().states });
    assert.equal(qualified.target, "C09");
    assert.equal(qualified.status, "recovered");
    assert.deepEqual(readdirSync(join(runRoot, "results")).sort(), ["claude.md", "consolidated.md", "qualification.json"]);
    const qualification = JSON.parse(readFileSync(join(runRoot, "results", "qualification.json"), "utf8"));
    assert(validateQualification(qualification, {
      reviewManifestBytes: readFileSync(join(runRoot, "review-manifest.json")),
      claudeBytes: readFileSync(join(runRoot, "results", "claude.md")),
    }));
    assertMissingAndExtraRejected(qualification, (value) => validateQualification(value, {
      reviewManifestBytes: readFileSync(join(runRoot, "review-manifest.json")),
      claudeBytes: readFileSync(join(runRoot, "results", "claude.md")),
    }), "QUALIFICATION");
    const finalRoot = join(task.revisionRoot, "review-final");
    assert.deepEqual(readdirSync(finalRoot).sort(), ["author-review.md", "claude.md", "consolidated.md", "manifest.json", "qualification.json", "review-manifest.json", "tz.md"]);
    assert(verifyFinalDirectory(finalRoot));
    assert.equal(existsSync(join(task.taskRoot, "runs")), false);
    assert.equal(existsSync(join(task.taskRoot, "final")), false);

    const mcpRun = "mcp-preflight";
    openRun({ task, runId: mcpRun, sourceContextIdentity: sourceContext.identity });
    writeFileSync(modePath, "mcp-preflight\n");
    const mcpResult = await executeReviewRun({ ...common, runId: mcpRun, preflightMonitorIntervalMs: 1_000 });
    assert.equal(mcpResult.status, "blocked");
    assert.equal(mcpResult.target, "B01");
    assert.equal(JSON.parse(readFileSync(join(mcpResult.runRoot, "capture", "preparation-error.json"), "utf8")).errorCode, "CLIENT_MCP_POLICY");
    const mcpPid = Number.parseInt(readFileSync(mcpPidPath, "utf8"), 10);
    assert(Number.isInteger(mcpPid) && mcpPid > 0);
    assert.equal(await waitForProcessExit(mcpPid), true);
    writeFileSync(modePath, "valid\n");

    const settingsRun = "settings-changed";
    openRun({ task, runId: settingsRun, sourceContextIdentity: sourceContext.identity });
    await expectReject(executeReviewRun({
      ...common,
      runId: settingsRun,
      beforeClientStart: () => writeFileSync(settings.settingsPath, `${JSON.stringify({ env: { CLAUDE_CODE_OAUTH_TOKEN: "oauth-self-test-value-changed" } })}\n`),
    }), "SETTINGS_CHANGED");
    writeFileSync(settings.settingsPath, `${JSON.stringify({ env: { CLAUDE_CODE_OAUTH_TOKEN: "oauth-self-test-value-123456" } })}\n`);
    chmodSync(settings.settingsPath, 0o600);

    const executableRun = "executable-changed";
    openRun({ task, runId: executableRun, sourceContextIdentity: sourceContext.identity });
    await expectReject(executeReviewRun({
      ...common,
      runId: executableRun,
      beforeClientStart: () => writeFileSync(executable, `${readFileSync(executable, "utf8")}\n`),
    }), "PROCESS_EXECUTABLE_CHANGED");
    writeExecutable(executable, modePath, mcpPidPath);

    const oauthRun = "oauth-output";
    openRun({ task, runId: oauthRun, sourceContextIdentity: sourceContext.identity });
    writeFileSync(modePath, "oauth\n");
    const oauthResult = await executeReviewRun({ ...common, runId: oauthRun });
    assert.equal(oauthResult.status, "blocked");
    assert.equal(oauthResult.target, "B01");
    for (const path of ["stdout.bin", "stderr.bin"]) {
      assert.equal(readFileSync(join(oauthResult.runRoot, "capture", path), "utf8").includes("oauth-self-test-value-123456"), false);
    }

    const invalidRun = "invalid-response";
    openRun({ task, runId: invalidRun, sourceContextIdentity: sourceContext.identity });
    writeFileSync(modePath, "invalid\n");
    const invalidResult = await executeReviewRun({ ...common, runId: invalidRun });
    assert.equal(invalidResult.status, "blocked");
    assert.equal(invalidResult.target, "B01");

    const secretCase = mkdtempSync(join(tmpdir(), "workflow-spec-review-secret-"));
    try {
      const secretSource = createSourceRepository(secretCase, promptSource);
      writeFileSync(join(secretSource.repository, "credential.txt"), `ghp_${"S".repeat(36)}`);
      git(secretSource.repository, ["add", "credential.txt"]);
      git(secretSource.repository, ["commit", "-qm", "secret"]);
      const secretHead = git(secretSource.repository, ["rev-parse", "HEAD"]);
      const secretTask = createTaskRoot(secretCase);
      openRun({ task: secretTask, runId: "secret-run", sourceContextIdentity: sourceContext.identity });
      await expectReject(executeReviewRun({ ...common, repo: secretSource.repository, base: secretSource.base, head: secretHead, taskRoot: secretTask.taskRoot, runId: "secret-run" }), "SECRET_SCAN");
    } finally {
      rmSync(secretCase, { recursive: true, force: true, maxRetries: 5, retryDelay: 25 });
    }

    await expectReject(executeReviewRun({ taskRoot: task.taskRoot, repo: source.repository }), "CALLER_IDENTITY");
    assert.equal(PROCESS_POLICY.launchMode, "single");
    assert.equal(PROCESS_POLICY.maxParallel, 1);
    console.log("workflow-spec-review self-test passed");
  } finally {
    // Дождаться завершения последних асинхронных снимков process-monitor,
    // прежде чем удалять дерево, которое они проверяли.
    await new Promise((resolvePromise) => setTimeout(resolvePromise, 300));
    makeTreeWritable(temporary);
    rmSync(temporary, { recursive: true, force: true, maxRetries: 5, retryDelay: 25 });
  }
}

try {
  await main();
} catch (error) {
  console.error(`Error: workflow-spec-review-self-test [${error?.code ?? "UNEXPECTED"}]: ${error instanceof Error ? error.message : "неизвестная ошибка"}`);
  if (error instanceof Error && error.stack) console.error(error.stack);
  process.exitCode = 1;
}
