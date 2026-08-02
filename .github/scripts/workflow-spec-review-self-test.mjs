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
  renameSync,
  rmSync,
  symlinkSync,
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import process from "node:process";
import {
  ENV_ALLOWLIST,
  GEMINI_PRINT_TIMEOUT,
  HARD_TIMEOUT_MS,
  assertNodeVersion,
  buildClaudeArgs,
  buildGeminiArgs,
  buildReviewIdentity,
  buildReviewerEnvironment,
  canonicalJson,
  executeReviewRun,
  finalizeReviewCycle,
  materializeTree,
  readStrictPrompt,
  resolveSettingsPaths,
  scanSecrets,
  settingsConfigurationSha256,
  sha256Bytes,
  startProcessGroup,
  validateGeminiInvocation,
  validateGeminiSettings,
  validateReviewerResponse,
  verifyFinalDirectory,
  verifySnapshot,
} from "./workflow-spec-review.mjs";

function git(repo, args) {
  return execFileSync("git", ["-C", repo, ...args], { encoding: "utf8" }).trim();
}

function expectThrow(fn, code = null) {
  let caught = null;
  try {
    fn();
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

function makeExecutable(path, body) {
  writeFileSync(path, `#!/usr/bin/env node\n${body}\n`);
  chmodSync(path, 0o755);
}

function createFakeClient(path, role, logPath, modePath, childPidPath) {
  makeExecutable(path, `
const fs = require("node:fs");
const cp = require("node:child_process");
const path = require("node:path");
const args = process.argv.slice(2);
fs.appendFileSync(${JSON.stringify(logPath)}, JSON.stringify({ role: ${JSON.stringify(role)}, args, envKeys: Object.keys(process.env).sort() }) + "\\n");
const mode = fs.existsSync(${JSON.stringify(modePath)}) ? fs.readFileSync(${JSON.stringify(modePath)}, "utf8").trim() : "valid";
if (mode === "preflight-mcp" && args[0] === "--version") {
  const child = cp.spawn(process.execPath, ["-e", "setInterval(() => {}, 1000)", "boost:mcp"], { detached: false, stdio: "ignore" });
  fs.appendFileSync(${JSON.stringify(childPidPath)}, String(child.pid) + "\\n");
  setTimeout(() => { try { child.kill("SIGTERM"); } catch {} console.log(${JSON.stringify(`${role}-version-1`)}); }, 400);
} else if (args[0] === "--version") { console.log(${JSON.stringify(`${role}-version-1`)}); process.exit(0); }
else if (args[0] === "auth" && args[1] === "status") { console.log("oauth_token"); process.exit(0); }
else if (args[0] === "models") { console.log("gemini-3.1-pro-high"); process.exit(0); }
else {
const print = args.lastIndexOf("--print");
const prompt = print === -1 ? "" : args[print + 1];
if (prompt.includes("WORKFLOW_SPEC_REVIEW_READ_SMOKE_OK")) { console.log("WORKFLOW_SPEC_REVIEW_READ_SMOKE_OK"); process.exit(0); }
if (mode === "hang") {
  const child = cp.spawn(process.execPath, ["-e", "process.on('SIGTERM', () => {}); setInterval(() => {}, 1000)", "review-child"], { detached: false, stdio: "ignore" });
  fs.appendFileSync(${JSON.stringify(childPidPath)}, String(child.pid) + "\\n");
  setInterval(() => {}, 1000);
} else if (mode === "empty") {
  process.exit(0);
} else if (mode === "error") {
  console.error("fake client error");
  process.exit(2);
} else if (mode === "invalid") {
  console.log("invalid response");
} else {
  const emit = () => {
    const manifest = JSON.parse(fs.readFileSync(path.join(process.cwd(), "input", "manifest.json"), "utf8"));
    console.log("technical preface");
    console.log(mode === "wrong-identity" ? manifest.identity + "-wrong" : manifest.identity);
    console.log("REVIEW_FINDINGS");
    console.log("findings отсутствуют");
    console.log("REVIEW_CHECKED_SCOPE");
    console.log("проверен весь снимок");
    console.log("REVIEW_UNCHECKED_SCOPE");
    console.log("нет");
    console.log("REVIEW_VERDICT");
    console.log("блокеров нет");
  };
  if (mode === "hook-stderr") console.error("<hook_prompt hook_run_id=\\"test\\">unexpected hook</hook_prompt>");
  if (mode === "leak-token" && ${JSON.stringify(role)} === "claude") console.error(process.env.CLAUDE_CODE_OAUTH_TOKEN);
  if (mode === "slow-valid") setTimeout(emit, 400);
  else if (mode === "mcp") {
    const child = cp.spawn(process.execPath, ["-e", "setInterval(() => {}, 1000)", "boost:mcp"], { detached: false, stdio: "ignore" });
    fs.appendFileSync(${JSON.stringify(childPidPath)}, String(child.pid) + "\\n");
    setTimeout(() => { try { child.kill("SIGTERM"); } catch {} emit(); }, 400);
  } else emit();
}
}
`);
}

function createTaskRoot(root, revision = "01") {
  const taskRoot = join(root, "task-root");
  const revisionRoot = join(taskRoot, "revisions", revision);
  mkdirSync(revisionRoot, { recursive: true });
  writeFileSync(join(revisionRoot, "tz.md"), "# ТЗ\n\nТестовый контракт.\n");
  writeFileSync(join(revisionRoot, "author-review.md"), "# Авторское ревью\n\nПробелов нет.\n");
  mkdirSync(join(taskRoot, "runs"));
  return taskRoot;
}

function createRepository(root, promptSource) {
  const repo = join(root, "repo");
  mkdirSync(join(repo, "docs", "workflow", "pr-correction"), { recursive: true });
  writeFileSync(join(repo, ".gitattributes"), "hidden.txt export-ignore\n");
  writeFileSync(join(repo, "hidden.txt"), "tracked despite export-ignore\n");
  writeFileSync(join(repo, "base.txt"), "base\n");
  copyFileSync(promptSource, join(repo, "docs", "workflow", "pr-correction", "external-spec-review-prompt.md"));
  git(repo, ["init", "-q"]);
  git(repo, ["config", "user.email", "self-test@example.invalid"]);
  git(repo, ["config", "user.name", "Self Test"]);
  git(repo, ["add", "."]);
  git(repo, ["commit", "-qm", "base"]);
  const base = git(repo, ["rev-parse", "HEAD"]);
  writeFileSync(join(repo, "head.txt"), "head\n");
  symlinkSync("head.txt", join(repo, "internal-link"));
  git(repo, ["add", "head.txt", "internal-link"]);
  git(repo, ["commit", "-qm", "head"]);
  const head = git(repo, ["rev-parse", "HEAD"]);
  return { repo, base, head };
}

function createSettings(root) {
  const home = join(root, "home");
  const paths = resolveSettingsPaths(home);
  mkdirSync(join(home, ".claude"), { recursive: true });
  mkdirSync(join(home, ".gemini", "antigravity-cli"), { recursive: true });
  writeFileSync(paths.claude, `${JSON.stringify({ env: { CLAUDE_CODE_OAUTH_TOKEN: "oauth-token-for-self-test-123456" } })}\n`);
  writeFileSync(paths.gemini, "{}\n");
  return paths;
}

function validResponse(identity) {
  return Buffer.from([
    "preface",
    identity,
    "REVIEW_FINDINGS",
    "нет",
    "REVIEW_CHECKED_SCOPE",
    "всё",
    "REVIEW_UNCHECKED_SCOPE",
    "ничего",
    "REVIEW_VERDICT",
    "блокеров нет",
    "",
  ].join("\n"));
}

function secretFixture(root, value) {
  rmSync(root, { recursive: true, force: true });
  for (const section of ["current", "base", "input"]) mkdirSync(join(root, section), { recursive: true });
  writeFileSync(join(root, "current", "fixture.txt"), value);
}

function makeTreeWritable(path) {
  if (!existsSync(path)) return;
  const stat = lstatSync(path);
  if (stat.isSymbolicLink()) return;
  if (stat.isDirectory()) {
    chmodSync(path, 0o700);
    for (const entry of readdirSync(path)) makeTreeWritable(join(path, entry));
  } else {
    chmodSync(path, 0o700);
  }
}

async function main() {
  const temporary = mkdtempSync(join(tmpdir(), "workflow-spec-review-self-test-"));
  try {
    assert.equal(assertNodeVersion("22.0.0"), 22);
    assert.equal(assertNodeVersion("31.1.0"), 31);
    expectThrow(() => assertNodeVersion("21.9.0"), "NODE_VERSION");

    const promptBoundary = join(temporary, "prompt-boundary.md");
    writeFileSync(promptBoundary, Buffer.alloc(65_536, 0x61));
    assert.equal(readStrictPrompt(promptBoundary).bytes.length, 65_536);
    writeFileSync(promptBoundary, Buffer.alloc(65_537, 0x61));
    expectThrow(() => readStrictPrompt(promptBoundary), "PROMPT_SIZE");
    writeFileSync(promptBoundary, Buffer.from([0x61, 0x00, 0x62]));
    expectThrow(() => readStrictPrompt(promptBoundary), "PROMPT_UTF8");
    writeFileSync(promptBoundary, Buffer.from([0xc3, 0x28]));
    expectThrow(() => readStrictPrompt(promptBoundary), "PROMPT_UTF8");

    const parentEnvironment = {
      HOME: "/safe/home",
      PATH: "/safe/bin",
      LANG: "C.UTF-8",
      ANTROPIC_API_KEY: "must-not-pass",
      ANTHROPIC_API_KEY: "must-not-pass",
      GEMINI_API_KEY: "must-not-pass",
      GOOGLE_APPLICATION_CREDENTIALS: "/unsafe/key",
      AWS_ACCESS_KEY_ID: "must-not-pass",
      NODE_OPTIONS: "--require bad",
      DYLD_INSERT_LIBRARIES: "/bad.dylib",
    };
    const claudeEnvironment = buildReviewerEnvironment(parentEnvironment, "claude", "oauth-token-self-test-123456");
    const geminiEnvironment = buildReviewerEnvironment(parentEnvironment, "gemini");
    assert.deepEqual(Object.keys(claudeEnvironment).sort(), ["CLAUDE_CODE_OAUTH_TOKEN", "HOME", "LANG", "PATH"]);
    assert.deepEqual(Object.keys(geminiEnvironment).sort(), ["HOME", "LANG", "PATH"]);
    assert(ENV_ALLOWLIST.includes("HOME"));

    const identity = `TZ review snapshot: ${"x".repeat(80)}`;
    assert(validateReviewerResponse(validResponse(identity), identity));
    for (const mutate of [
      (text) => text.replace("REVIEW_CHECKED_SCOPE\nвсё\n", ""),
      (text) => text.replace("REVIEW_FINDINGS\nнет", "REVIEW_FINDINGS\nREVIEW_CHECKED_SCOPE\nвсё"),
      (text) => text.replace("блокеров нет", "неизвестно"),
      (text) => text.replace("блокеров нет", " блокеров нет "),
      (text) => `${text}\n${identity}`,
      (text) => text.replace("REVIEW_VERDICT", "REVIEW_FINDINGS"),
    ]) assert(!validateReviewerResponse(Buffer.from(mutate(validResponse(identity).toString("utf8"))), identity));

    const geminiArgs = buildGeminiArgs("/review", "prompt");
    assert.equal(geminiArgs[geminiArgs.indexOf("--print-timeout") + 1], GEMINI_PRINT_TIMEOUT);
    assert(validateGeminiInvocation(geminiArgs, HARD_TIMEOUT_MS));
    for (const forbidden of ["--project", "--dangerously-skip-permissions", "--command", "permissions.allow=*"]) {
      expectThrow(() => validateGeminiInvocation([...geminiArgs.slice(0, -2), forbidden, "x", ...geminiArgs.slice(-2)]), "GEMINI_ARGV");
    }
    expectThrow(() => validateGeminiInvocation(buildGeminiArgs("/review", "prompt", "25m"), HARD_TIMEOUT_MS), "TIMEOUT_ORDER");
    assert.equal(buildClaudeArgs("/review", "prompt").at(-1), "prompt");

    const secretRoot = join(temporary, "secret-root");
    const privateKey = ["-----BEGIN ", "PRIVATE KEY-----"].join("");
    const providerToken = ["gh", "p_", "A".repeat(36)].join("");
    const bearerToken = ["Authorization", ": ", "Bearer ", "z".repeat(20)].join("");
    for (const value of [privateKey, providerToken, bearerToken]) {
      secretFixture(secretRoot, value);
      expectThrow(() => scanSecrets(secretRoot), "SECRET_SCAN");
    }
    for (const placeholder of ["example", "dummy", "test", "changeme", "replace", "<TOKEN>", "${TOKEN}", "*****"]) {
      secretFixture(secretRoot, `Authorization: Bearer ${placeholder}`);
      assert.deepEqual(scanSecrets(secretRoot), []);
    }

    const promptSource = resolve(process.cwd(), "docs/workflow/pr-correction/external-spec-review-prompt.md");
    const source = createRepository(temporary, promptSource);
    const snapshotRoot = join(temporary, "snapshot");
    const capture = join(temporary, "snapshot-capture");
    const materialized = materializeTree(source.repo, source.head, snapshotRoot, capture);
    assert(existsSync(join(snapshotRoot, "hidden.txt")), "export-ignore файл должен попасть в snapshot");
    assert(lstatSync(join(snapshotRoot, "internal-link")).isSymbolicLink(), "внутренний symlink должен сохраниться");
    assert.equal(verifySnapshot(source.repo, source.head, snapshotRoot, materialized.treeBytes).entries.length, materialized.count);
    writeFileSync(join(snapshotRoot, "head.txt"), "tampered\n");
    expectThrow(() => verifySnapshot(source.repo, source.head, snapshotRoot, materialized.treeBytes), "SNAPSHOT_MISMATCH");
    const modeSnapshot = join(temporary, "snapshot-mode");
    const modeMaterialized = materializeTree(source.repo, source.head, modeSnapshot, join(temporary, "snapshot-mode-capture"));
    chmodSync(join(modeSnapshot, "head.txt"), 0o755);
    expectThrow(() => verifySnapshot(source.repo, source.head, modeSnapshot, modeMaterialized.treeBytes), "SNAPSHOT_MISMATCH");
    const pathSnapshot = join(temporary, "snapshot-path");
    const pathMaterialized = materializeTree(source.repo, source.head, pathSnapshot, join(temporary, "snapshot-path-capture"));
    renameSync(join(pathSnapshot, "head.txt"), join(pathSnapshot, "renamed.txt"));
    expectThrow(() => verifySnapshot(source.repo, source.head, pathSnapshot, pathMaterialized.treeBytes), "SNAPSHOT_PATH");
    const outsideTarget = join(temporary, "outside-snapshot.txt");
    writeFileSync(outsideTarget, "outside\n");
    symlinkSync(outsideTarget, join(source.repo, "outside-link"));
    git(source.repo, ["add", "outside-link"]);
    git(source.repo, ["commit", "-qm", "unsafe symlink"]);
    const unsafeHead = git(source.repo, ["rev-parse", "HEAD"]);
    expectThrow(() => materializeTree(source.repo, unsafeHead, join(temporary, "unsafe-snapshot"), join(temporary, "unsafe-capture")), "SNAPSHOT_SYMLINK");

    const settingsPaths = createSettings(temporary);
    assert.equal(settingsPaths.claude, join(temporary, "home", ".claude", "settings.json"));
    assert.equal(settingsPaths.gemini, join(temporary, "home", ".gemini", "antigravity-cli", "settings.json"));
    assert(validateGeminiSettings(settingsPaths.gemini));
    const claudeSettingsBytesBefore = readFileSync(settingsPaths.claude);
    const claudeSettingsHashBefore = settingsConfigurationSha256(settingsPaths.claude, "claude");
    writeFileSync(settingsPaths.claude, `${JSON.stringify({ env: { CLAUDE_CODE_OAUTH_TOKEN: "oauth-token-self-test-replaced-654321" } })}\n`);
    const claudeSettingsHashAfter = settingsConfigurationSha256(settingsPaths.claude, "claude");
    assert.equal(claudeSettingsHashBefore, claudeSettingsHashAfter, "OAuth-значение не должно входить в hash конфигурации");
    assert(!claudeSettingsBytesBefore.equals(readFileSync(settingsPaths.claude)), "точная смена settings должна оставаться видимой");
    writeFileSync(settingsPaths.claude, `${JSON.stringify({ env: { CLAUDE_CODE_OAUTH_TOKEN: "oauth-token-self-test-123456" } })}\n`);
    const geminiSettingsHashBefore = settingsConfigurationSha256(settingsPaths.gemini, "gemini");
    writeFileSync(settingsPaths.gemini, "{\"theme\":\"dark\"}\n");
    const geminiSettingsHashAfter = settingsConfigurationSha256(settingsPaths.gemini, "gemini");
    assert.notEqual(geminiSettingsHashBefore, geminiSettingsHashAfter, "конфигурация Gemini должна входить в client identity");
    writeFileSync(settingsPaths.gemini, "{}\n");
    const forbiddenGeminiSettings = join(temporary, "forbidden-gemini-settings.json");
    writeFileSync(forbiddenGeminiSettings, `${JSON.stringify({ permissions: { allow: ["command"] } })}\n`);
    expectThrow(() => validateGeminiSettings(forbiddenGeminiSettings), "GEMINI_SETTINGS_PERMISSION");
    const badSettings = join(temporary, "bad-settings-link");
    symlinkSync(settingsPaths.claude, badSettings);
    const logPath = join(temporary, "fake-client.log");
    const modePath = join(temporary, "fake-mode");
    const childPidPath = join(temporary, "child-pids");
    const claudePath = join(temporary, "fake-claude");
    const geminiPath = join(temporary, "fake-agy");
    createFakeClient(claudePath, "claude", logPath, modePath, childPidPath);
    createFakeClient(geminiPath, "gemini", logPath, modePath, childPidPath);
    const commands = { claude: claudePath, gemini: geminiPath };
    const environment = { ...parentEnvironment, PATH: process.env.PATH, USER: "self-test", SHELL: "/bin/zsh", TMPDIR: temporary };
    const taskRoot = createTaskRoot(temporary);
    const common = { selfTest: true, repo: source.repo, base: source.base, head: source.head, taskRoot, revision: "01", settingsPaths, commands, environment, nodeVersion: "22.0.0" };

    await expectReject(executeReviewRun({ ...common, nodeVersion: "21.9.0", runId: "old-node" }), "NODE_VERSION");
    assert(!existsSync(join(taskRoot, "runs", "old-node")), "Node version gate должен срабатывать до snapshot");
    await expectReject(executeReviewRun({ ...common, taskRoot: source.repo, runId: "overlap" }), "PATH_SCOPE");
    await expectReject(executeReviewRun({ ...common, runId: "../escape" }), "PATH_SEGMENT");
    await expectReject(executeReviewRun({ ...common, revision: "../01", runId: "bad-revision" }), "PATH_SEGMENT");
    const taskRootLink = join(temporary, "task-root-link");
    symlinkSync(taskRoot, taskRootLink);
    await expectReject(executeReviewRun({ ...common, taskRoot: taskRootLink, runId: "symlink-root" }), "PATH_SCOPE");
    const symlinkRunsTask = createTaskRoot(join(temporary, "symlink-runs"));
    rmSync(join(symlinkRunsTask, "runs"), { recursive: true });
    const externalRuns = join(temporary, "external-runs");
    mkdirSync(externalRuns);
    symlinkSync(externalRuns, join(symlinkRunsTask, "runs"));
    await expectReject(executeReviewRun({ ...common, taskRoot: symlinkRunsTask, runId: "symlink-runs" }), "PATH_SCOPE");
    const symlinkRevisionsTask = createTaskRoot(join(temporary, "symlink-revisions"));
    const externalRevisions = join(temporary, "external-revisions");
    renameSync(join(symlinkRevisionsTask, "revisions"), externalRevisions);
    symlinkSync(externalRevisions, join(symlinkRevisionsTask, "revisions"));
    await expectReject(executeReviewRun({ ...common, taskRoot: symlinkRevisionsTask, runId: "symlink-revisions" }), "PATH_SCOPE");
    const symlinkRevisionTask = createTaskRoot(join(temporary, "symlink-revision"));
    rmSync(join(symlinkRevisionTask, "revisions", "01", "tz.md"));
    symlinkSync(promptSource, join(symlinkRevisionTask, "revisions", "01", "tz.md"));
    await expectReject(executeReviewRun({ ...common, taskRoot: symlinkRevisionTask, runId: "symlink-revision" }), "REVISION_FILE");
    await expectReject(executeReviewRun({ ...common, runId: "bad-settings", settingsPaths: { ...settingsPaths, claude: badSettings } }), "SETTINGS_FILE");
    await expectReject(executeReviewRun({ ...common, runId: "missing-settings", settingsPaths: { ...settingsPaths, gemini: join(temporary, "missing-settings.json") } }), "SETTINGS_FILE");
    const settingsDirectory = join(temporary, "settings-directory");
    mkdirSync(settingsDirectory);
    await expectReject(executeReviewRun({ ...common, runId: "directory-settings", settingsPaths: { ...settingsPaths, gemini: settingsDirectory } }), "SETTINGS_FILE");
    await expectReject(executeReviewRun({ ...common, selfTest: false, runId: "fake-production" }), "SELF_TEST_OVERRIDE");
    const productionShaped = { repo: source.repo, base: source.base, head: source.head, taskRoot, revision: "01", selfTest: false };
    await expectReject(executeReviewRun({ ...productionShaped, runId: "environment-production", environment }), "SELF_TEST_OVERRIDE");
    await expectReject(executeReviewRun({ ...productionShaped, runId: "version-production", nodeVersion: "22.0.0" }), "SELF_TEST_OVERRIDE");

    const secretCase = join(temporary, "secret-case");
    const secretSource = createRepository(secretCase, promptSource);
    writeFileSync(join(secretSource.repo, "credential.txt"), ["gh", "p_", "S".repeat(36)].join(""));
    git(secretSource.repo, ["add", "credential.txt"]);
    git(secretSource.repo, ["commit", "-qm", "secret"]);
    const secretHead = git(secretSource.repo, ["rev-parse", "HEAD"]);
    const secretTaskRoot = createTaskRoot(secretCase);
    const logBeforeSecret = existsSync(logPath) ? readFileSync(logPath).length : 0;
    await expectReject(executeReviewRun({ ...common, repo: secretSource.repo, base: secretSource.base, head: secretHead, taskRoot: secretTaskRoot, runId: "secret-run" }), "SECRET_SCAN");
    assert.equal(existsSync(logPath) ? readFileSync(logPath).length : 0, logBeforeSecret, "secret scan должен завершиться до первого client spawn");

    writeFileSync(modePath, "preflight-mcp\n");
    await expectReject(executeReviewRun({ ...common, runId: "preflight-mcp-run", hardTimeoutMs: 3_000, geminiPrintTimeout: "2s" }), "CLIENT_PREFLIGHT");
    assert.deepEqual(readdirSync(join(taskRoot, "runs", "preflight-mcp-run", "review-root", "results")), []);

    writeFileSync(modePath, "valid\n");
    const successful = await executeReviewRun({ ...common, runId: "valid-run", hardTimeoutMs: 3_000, geminiPrintTimeout: "2s" });
    assert.equal(successful.status, "completed");
    assert.equal(successful.target, "P03");
    assert.equal(successful.qualificationRequired, true);
    const successfulClientManifest = JSON.parse(readFileSync(join(successful.reviewRoot, "input", "manifest.json"), "utf8"));
    assert.match(successfulClientManifest.clients.claude.settingsConfigurationSha256, /^[0-9a-f]{64}$/);
    assert.match(successfulClientManifest.clients.gemini.settingsConfigurationSha256, /^[0-9a-f]{64}$/);
    assert.deepEqual(readdirSync(join(successful.reviewRoot, "results")).sort(), ["claude.md", "gemini.md"]);
    assert(!existsSync(join(successful.reviewRoot, "author-review.md")));
    assert(!existsSync(join(successful.reviewRoot, "input", "author-review.md")));
    assert(existsSync(join(taskRoot, "runs", "valid-run", "capture", "preflight-started.json")));
    assert(existsSync(join(taskRoot, "runs", "valid-run", "capture", "started.json")));
    const logRows = readFileSync(logPath, "utf8").trim().split("\n").map((line) => JSON.parse(line));
    const fullRows = logRows.filter((row) => row.args.includes("--print") && !row.args.at(-1).includes("READ_SMOKE"));
    assert.equal(fullRows.length, 2);
    for (const row of fullRows) {
      assert.equal(row.args.at(-1), readFileSync(promptSource, "utf8"));
      const expectedArgs = row.role === "claude"
        ? buildClaudeArgs(successful.reviewRoot, readFileSync(promptSource, "utf8"))
        : buildGeminiArgs(successful.reviewRoot, readFileSync(promptSource, "utf8"), "2s");
      assert.deepEqual(row.args, expectedArgs);
      assert(!row.envKeys.includes("ANTHROPIC_API_KEY"));
      assert(!row.envKeys.includes("GEMINI_API_KEY"));
      assert(!row.envKeys.includes("GOOGLE_APPLICATION_CREDENTIALS"));
      assert(!row.envKeys.includes("NODE_OPTIONS"));
      if (row.role === "gemini") assert(!row.envKeys.includes("CLAUDE_CODE_OAUTH_TOKEN"));
    }
    const logLengthBeforeReuse = readFileSync(logPath).length;
    await expectReject(executeReviewRun({ ...common, runId: "valid-run", hardTimeoutMs: 3_000, geminiPrintTimeout: "2s" }), null);
    assert.equal(readFileSync(logPath).length, logLengthBeforeReuse, "повторный run-id должен блокироваться до client spawn");

    for (const [mode, runId] of [["invalid", "invalid-run"], ["empty", "empty-run"], ["error", "error-run"], ["wrong-identity", "wrong-identity-run"], ["hook-stderr", "hook-stderr-run"], ["leak-token", "leak-token-run"]]) {
      writeFileSync(modePath, `${mode}\n`);
      const invalid = await executeReviewRun({ ...common, runId, hardTimeoutMs: 3_000, geminiPrintTimeout: "2s" });
      assert.equal(invalid.target, "B01", mode);
      if (mode === "hook-stderr") assert.equal(invalid.hookDetected, true);
      if (mode === "leak-token") {
        assert.equal(invalid.secretOutputDetected, true);
        const captureRoot = join(taskRoot, "runs", runId, "capture");
        for (const path of ["claude.stdout", "claude.stderr", "gemini.stdout", "gemini.stderr"]) {
          assert(!readFileSync(join(captureRoot, path), "utf8").includes("oauth-token-self-test-123456"));
        }
      }
      assert.deepEqual(readdirSync(join(invalid.reviewRoot, "results")), [], mode);
    }

    writeFileSync(modePath, "mcp\n");
    const mcp = await executeReviewRun({ ...common, runId: "mcp-run", hardTimeoutMs: 3_000, geminiPrintTimeout: "2s" });
    assert.equal(mcp.target, "B01");
    assert.equal(mcp.mcpDetected, true);
    assert.deepEqual(readdirSync(join(mcp.reviewRoot, "results")), []);

    writeFileSync(modePath, "slow-valid\n");
    const settingsChangedPromise = executeReviewRun({ ...common, runId: "settings-changed-run", hardTimeoutMs: 3_000, geminiPrintTimeout: "2s" });
    const fullReviewMarker = join(taskRoot, "runs", "settings-changed-run", "capture", "started.json");
    const changeSettingsAfterPreflight = new Promise((resolvePromise, rejectPromise) => {
      let attempts = 0;
      const poll = () => {
        attempts += 1;
        if (existsSync(fullReviewMarker)) {
          writeFileSync(settingsPaths.gemini, "{\"changed\":true}\n");
          resolvePromise();
        } else if (attempts > 300) {
          rejectPromise(new Error("full review marker не появился"));
        } else {
          setTimeout(poll, 10);
        }
      };
      poll();
    });
    const [settingsChanged] = await Promise.all([settingsChangedPromise, changeSettingsAfterPreflight]);
    assert.equal(settingsChanged.target, "B01");
    assert.equal(settingsChanged.settingsUnchanged, false);
    assert.deepEqual(readdirSync(join(settingsChanged.reviewRoot, "results")), []);
    writeFileSync(settingsPaths.gemini, "{}\n");

    writeFileSync(modePath, "hang\n");
    const timedOut = await executeReviewRun({ ...common, runId: "timeout-run", hardTimeoutMs: 300, geminiPrintTimeout: "200ms" });
    assert.equal(timedOut.target, "B01");
    assert.deepEqual(readdirSync(join(timedOut.reviewRoot, "results")), []);
    await new Promise((resolvePromise) => setTimeout(resolvePromise, 200));
    for (const pid of readFileSync(childPidPath, "utf8").trim().split("\n").map(Number)) {
      assert.throws(() => process.kill(pid, 0));
    }

    const controller = new AbortController();
    setTimeout(() => controller.abort(), 150);
    const cancelled = await executeReviewRun({ ...common, runId: "cancel-run", hardTimeoutMs: 3_000, geminiPrintTimeout: "2s", signal: controller.signal });
    assert.equal(cancelled.status, "cancelled_by_user");
    assert.equal(cancelled.target, "X03");
    assert.equal(cancelled.qualificationRequired, false);
    assert.deepEqual(readdirSync(join(cancelled.reviewRoot, "results")), []);

    expectThrow(() => finalizeReviewCycle({ taskRoot, revision: "01", runId: "invalid-run" }), "FILE_SET");
    assert(existsSync(join(taskRoot, "runs")), "неполная финализация не удаляет runs");
    writeFileSync(modePath, "valid\n");
    const provenance = await executeReviewRun({ ...common, runId: "provenance-run", hardTimeoutMs: 3_000, geminiPrintTimeout: "2s" });
    const provenanceManifest = JSON.parse(readFileSync(join(provenance.reviewRoot, "input", "manifest.json"), "utf8"));
    writeFileSync(join(provenance.reviewRoot, "results", "consolidated.md"), "");
    expectThrow(() => finalizeReviewCycle({ taskRoot, revision: "01", runId: "provenance-run" }), "FINAL_QUALIFICATION");
    writeFileSync(join(provenance.reviewRoot, "results", "consolidated.md"), `# Сводный вывод\n\n${provenanceManifest.identity}\n\nБлокеров нет.\n`);
    writeFileSync(join(provenance.reviewRoot, "results", "claude.md"), validResponse(provenanceManifest.identity));
    expectThrow(() => finalizeReviewCycle({ taskRoot, revision: "01", runId: "provenance-run" }), "FINAL_PROVENANCE");
    const provenanceResults = join(provenance.reviewRoot, "results");
    const escapedResults = join(temporary, "escaped-results");
    renameSync(provenanceResults, escapedResults);
    symlinkSync(escapedResults, provenanceResults);
    expectThrow(() => finalizeReviewCycle({ taskRoot, revision: "01", runId: "provenance-run" }), "PATH_SCOPE");
    rmSync(provenanceResults);
    renameSync(escapedResults, provenanceResults);
    assert(!existsSync(join(taskRoot, "final")));
    const successfulManifest = JSON.parse(readFileSync(join(successful.reviewRoot, "input", "manifest.json"), "utf8"));
    writeFileSync(join(successful.reviewRoot, "results", "consolidated.md"), `# Сводный вывод\n\n${successfulManifest.identity}\n\nБлокеров нет.\n`);
    rmSync(modePath, { force: true });
    const final = finalizeReviewCycle({ taskRoot, revision: "01", runId: "valid-run" });
    assert.equal(final.target, "C09");
    assert.deepEqual(readdirSync(final.finalRoot).sort(), ["author-review.md", "claude.md", "consolidated.md", "gemini.md", "manifest.json", "review-manifest.json", "tz.md"]);
    assert(!existsSync(join(taskRoot, "runs")), "runs удаляется только после полной финализации");
    const finalManifest = JSON.parse(readFileSync(join(final.finalRoot, "manifest.json"), "utf8"));
    assert.equal(finalManifest.artifacts.length, 6);
    for (const artifact of finalManifest.artifacts) assert.equal(sha256Bytes(readFileSync(join(final.finalRoot, artifact.path))), artifact.sha256);
    assert.equal(readFileSync(join(final.finalRoot, "tz.md"), "utf8"), readFileSync(join(taskRoot, "revisions", "01", "tz.md"), "utf8"));
    assert.equal(readFileSync(join(final.finalRoot, "author-review.md"), "utf8"), readFileSync(join(taskRoot, "revisions", "01", "author-review.md"), "utf8"));
    assert.equal(verifyFinalDirectory(final.finalRoot).identity, finalManifest.identity);
    const originalFinalTz = readFileSync(join(final.finalRoot, "tz.md"));
    writeFileSync(join(final.finalRoot, "tz.md"), Buffer.concat([originalFinalTz, Buffer.from("tampered\n")]));
    expectThrow(() => verifyFinalDirectory(final.finalRoot), "FINAL_HASH");
    writeFileSync(join(final.finalRoot, "tz.md"), originalFinalTz);
    writeFileSync(join(final.finalRoot, "extra.md"), "extra\n");
    expectThrow(() => verifyFinalDirectory(final.finalRoot), "FILE_SET");
    rmSync(join(final.finalRoot, "extra.md"));
    assert(verifyFinalDirectory(final.finalRoot));
    mkdirSync(join(taskRoot, "runs", "leftover"), { recursive: true });
    writeFileSync(join(taskRoot, "runs", "leftover", "partial"), "partial\n");
    const recovered = finalizeReviewCycle({ taskRoot, revision: "01", runId: "valid-run" });
    assert.equal(recovered.reused, true);
    assert.equal(recovered.target, "C09");
    assert(!existsSync(join(taskRoot, "runs")), "повторная финализация должна завершить cleanup");

    const identityInput = {
      base: "a".repeat(40),
      head: "b".repeat(40),
      currentTreeBytes: Buffer.from("current"),
      baseTreeBytes: Buffer.from("base"),
      patchBytes: Buffer.from("patch"),
      specBytes: Buffer.from("spec"),
      promptBytes: Buffer.from("prompt"),
      clients: { model: "a" },
    };
    const originalIdentity = buildReviewIdentity(identityInput).identity;
    for (const [key, value] of [
      ["base", "c".repeat(40)],
      ["head", "d".repeat(40)],
      ["currentTreeBytes", Buffer.from("current-2")],
      ["baseTreeBytes", Buffer.from("base-2")],
      ["patchBytes", Buffer.from("patch-2")],
      ["specBytes", Buffer.from("spec-2")],
      ["promptBytes", Buffer.from("prompt-2")],
      ["clients", { model: "b" }],
    ]) assert.notEqual(buildReviewIdentity({ ...identityInput, [key]: value }).identity, originalIdentity, key);
    assert.notEqual(sha256Bytes(Buffer.from(canonicalJson({ model: "a" }))), sha256Bytes(Buffer.from(canonicalJson({ model: "b" }))));

    const hangScript = join(temporary, "direct-hang");
    makeExecutable(hangScript, "setInterval(() => {}, 1000);");
    const direct = startProcessGroup(hangScript, [], { cwd: temporary, env: { PATH: process.env.PATH }, timeoutMs: 100, killGraceMs: 50 });
    assert((await direct.done).timedOut);

    console.log("workflow-spec-review self-test passed");
  } finally {
    makeTreeWritable(temporary);
    rmSync(temporary, { recursive: true, force: true });
  }
}

try {
  await main();
} catch (error) {
  console.error(`Error: workflow-spec-review-self-test [${error?.code ?? "UNEXPECTED"}]: ${error instanceof Error ? error.message : "неизвестная ошибка"}`);
  process.exitCode = 1;
}
