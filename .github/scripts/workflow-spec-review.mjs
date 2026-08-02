#!/usr/bin/env node
import { execFileSync, spawn, spawnSync } from "node:child_process";
import { createHash, randomBytes } from "node:crypto";
import {
  chmodSync,
  copyFileSync,
  existsSync,
  lstatSync,
  mkdirSync,
  readFileSync,
  readlinkSync,
  readdirSync,
  realpathSync,
  renameSync,
  rmdirSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import { homedir } from "node:os";
import { basename, dirname, join, resolve, sep } from "node:path";
import process from "node:process";
import { TextDecoder } from "node:util";
import { fileURLToPath } from "node:url";

export const NODE_MINIMUM_MAJOR = 22;
export const HARD_TIMEOUT_MS = 25 * 60_000;
export const GEMINI_PRINT_TIMEOUT = "24m";
export const PROMPT_MAX_BYTES = 65_536;
export const PROMPT_PATH = "docs/workflow/pr-correction/external-spec-review-prompt.md";
export const ENV_ALLOWLIST = Object.freeze([
  "HOME", "USER", "LOGNAME", "SHELL", "PATH", "TMPDIR", "LANG", "LC_ALL",
  "LC_CTYPE", "TERM", "COLORTERM", "NO_COLOR",
]);
export const RESPONSE_MARKERS = Object.freeze([
  "REVIEW_FINDINGS",
  "REVIEW_CHECKED_SCOPE",
  "REVIEW_UNCHECKED_SCOPE",
  "REVIEW_VERDICT",
]);
export const RESPONSE_VERDICTS = new Set(["блокеров нет", "нужны правки"]);
const FINAL_FILES = Object.freeze([
  "author-review.md",
  "claude.md",
  "consolidated.md",
  "gemini.md",
  "manifest.json",
  "review-manifest.json",
  "tz.md",
]);
const FINAL_ARTIFACTS = FINAL_FILES.filter((path) => path !== "manifest.json");

function fail(message, code = "REVIEW_ERROR") {
  const error = new Error(message);
  error.code = code;
  throw error;
}

export function assertNodeVersion(version = process.versions.node) {
  const major = Number.parseInt(String(version).split(".")[0], 10);
  if (!Number.isInteger(major) || major < NODE_MINIMUM_MAJOR) fail(`требуется Node.js ${NODE_MINIMUM_MAJOR} или новее`, "NODE_VERSION");
  return major;
}

export function sha256Bytes(bytes) {
  return createHash("sha256").update(bytes).digest("hex");
}

export function canonicalJson(value) {
  if (Array.isArray(value)) return `[${value.map(canonicalJson).join(",")}]`;
  if (value !== null && typeof value === "object") {
    return `{${Object.keys(value).sort().map((key) => `${JSON.stringify(key)}:${canonicalJson(value[key])}`).join(",")}}`;
  }
  return JSON.stringify(value);
}

export function readStrictPrompt(path) {
  const bytes = readFileSync(path);
  if (bytes.length > PROMPT_MAX_BYTES) fail(`prompt превышает ${PROMPT_MAX_BYTES} bytes`, "PROMPT_SIZE");
  if (bytes.includes(0)) fail("prompt содержит NUL", "PROMPT_UTF8");
  let text;
  try {
    text = new TextDecoder("utf-8", { fatal: true }).decode(bytes);
  } catch {
    fail("prompt не является строгим UTF-8", "PROMPT_UTF8");
  }
  if (!Buffer.from(text, "utf8").equals(bytes)) fail("байты prompt изменились после UTF-8 round-trip", "PROMPT_UTF8");
  return { bytes, text };
}

function walk(root, relativePath = "") {
  const absolute = join(root, relativePath);
  const result = [];
  for (const entry of readdirSync(absolute, { withFileTypes: true })) {
    const child = relativePath ? `${relativePath}/${entry.name}` : entry.name;
    if (entry.isDirectory()) result.push(...walk(root, child));
    else result.push(child);
  }
  return result.sort();
}

function isInside(parent, child) {
  const normalizedParent = resolve(parent) + sep;
  return resolve(child).startsWith(normalizedParent);
}

function pathsOverlap(left, right) {
  const absoluteLeft = resolve(left);
  const absoluteRight = resolve(right);
  return absoluteLeft === absoluteRight || isInside(absoluteLeft, absoluteRight) || isInside(absoluteRight, absoluteLeft);
}

function ensureTaskLocalPath(path) {
  const absolute = resolve(path);
  if (absolute === "/" || absolute === homedir() || basename(absolute) === ".git") fail("небезопасный task-local путь", "PATH_SCOPE");
  const stat = lstatSafe(absolute);
  if (stat === null || !stat.isDirectory() || stat.isSymbolicLink()) fail("task-local root должен быть существующим обычным каталогом", "PATH_SCOPE");
  return realpathSync(absolute);
}

function ensureContainedDirectory(root, path, label) {
  const stat = lstatSafe(path);
  if (stat === null || !stat.isDirectory() || stat.isSymbolicLink()) {
    fail(`${label} должен быть обычным каталогом`, "PATH_SCOPE");
  }
  const physicalRoot = realpathSync(root);
  const physicalPath = realpathSync(path);
  if (physicalPath !== physicalRoot && !isInside(physicalRoot, physicalPath)) {
    fail(`${label} выходит за пределы task-local root`, "PATH_SCOPE");
  }
  return physicalPath;
}

function validatePathSegment(value, label, pattern) {
  if (typeof value !== "string" || !pattern.test(value)) fail(`${label} имеет недопустимый формат`, "PATH_SEGMENT");
  return value;
}

function assertRegularFile(path, code = "FILE_TYPE") {
  const stat = lstatSafe(path);
  if (stat === null || !stat.isFile() || stat.isSymbolicLink()) fail(`${path}: ожидался обычный файл`, code);
  return stat;
}

function publishAcceptedResults(reviewRoot, claudeBytes, geminiBytes) {
  const results = join(reviewRoot, "results");
  exactFileSet(results, []);
  const pending = join(reviewRoot, `.results-pending-${process.pid}-${randomBytes(6).toString("hex")}`);
  mkdirSync(pending, { recursive: false });
  try {
    writeFileSync(join(pending, "claude.md"), claudeBytes, { flag: "wx" });
    writeFileSync(join(pending, "gemini.md"), geminiBytes, { flag: "wx" });
    exactFileSet(pending, ["claude.md", "gemini.md"]);
    rmdirSync(results);
    renameSync(pending, results);
  } catch (error) {
    rmSync(pending, { recursive: true, force: true });
    if (!existsSync(results)) mkdirSync(results);
    throw error;
  }
}

function exactFileSet(directory, expected) {
  const actual = readdirSync(directory).sort();
  const wanted = [...expected].sort();
  if (actual.length !== wanted.length || actual.some((path, index) => path !== wanted[index])) {
    fail(`${directory}: ожидался набор [${wanted.join(", ")}], получен [${actual.join(", ")}]`, "FILE_SET");
  }
}

function git(repo, args, options = {}) {
  return execFileSync("git", ["-C", repo, ...args], {
    encoding: options.encoding ?? null,
    env: options.env ?? process.env,
    maxBuffer: options.maxBuffer ?? 256 * 1024 * 1024,
  });
}

function assertSourceRepository(repo, base, head) {
  if (!/^[0-9a-f]{40,64}$/.test(base) || !/^[0-9a-f]{40,64}$/.test(head)) fail("base/head должны быть полными Git SHA", "GIT_IDENTITY");
  const resolvedBase = git(repo, ["rev-parse", "--verify", `${base}^{commit}`], { encoding: "utf8" }).trim();
  const resolvedHead = git(repo, ["rev-parse", "--verify", `${head}^{commit}`], { encoding: "utf8" }).trim();
  if (resolvedBase !== base || resolvedHead !== head) fail("base/head не совпадают с разрешёнными commit", "GIT_IDENTITY");
  const status = git(repo, ["status", "--porcelain=v1", "--untracked-files=all"], { encoding: "utf8" });
  if (status.length !== 0) fail("source worktree должен быть чистым перед snapshot", "DIRTY_SOURCE");
}

export function parseGitTree(bytes) {
  const entries = [];
  for (const raw of bytes.toString("utf8").split("\0")) {
    if (raw === "") continue;
    const match = raw.match(/^([0-7]{6}) ([a-z]+) ([0-9a-f]{40,64})\t([\s\S]+)$/);
    if (!match) fail("git ls-tree вернул неподдерживаемую запись", "TREE_FORMAT");
    entries.push({ mode: match[1], type: match[2], object: match[3], path: match[4] });
  }
  return entries;
}

function hashObject(repo, bytes) {
  const result = spawnSync("git", ["-C", repo, "hash-object", "--stdin"], { input: bytes, encoding: "utf8" });
  if (result.status !== 0) fail("git hash-object завершился ошибкой", "SNAPSHOT_HASH");
  return result.stdout.trim();
}

function snapshotEntry(root, entry, repo) {
  const path = join(root, entry.path);
  if (!existsSync(path) && !lstatSafe(path)) fail(`snapshot не содержит ${entry.path}`, "SNAPSHOT_PATH");
  const stat = lstatSync(path);
  if (stat.isSymbolicLink()) {
    const lexicalTarget = resolve(dirname(path), readlinkSync(path));
    if (lexicalTarget !== resolve(root) && !isInside(root, lexicalTarget)) {
      fail(`${entry.path}: symlink выходит за пределы snapshot`, "SNAPSHOT_SYMLINK");
    }
    let physicalTarget;
    try {
      physicalTarget = realpathSync(path);
    } catch {
      fail(`${entry.path}: symlink не разрешается внутри snapshot`, "SNAPSHOT_SYMLINK");
    }
    const physicalRoot = realpathSync(root);
    if (physicalTarget !== physicalRoot && !isInside(physicalRoot, physicalTarget)) {
      fail(`${entry.path}: цепочка symlink выходит за пределы snapshot`, "SNAPSHOT_SYMLINK");
    }
    return { mode: "120000", object: hashObject(repo, Buffer.from(readlinkSync(path))) };
  }
  if (!stat.isFile()) fail(`${entry.path}: поддерживаются только blob-файлы и symlink`, "SNAPSHOT_TYPE");
  const mode = (stat.mode & 0o111) === 0 ? "100644" : "100755";
  return { mode, object: hashObject(repo, readFileSync(path)) };
}

function lstatSafe(path) {
  try {
    return lstatSync(path);
  } catch {
    return null;
  }
}

export function verifySnapshot(repo, commit, destination, treeBytes = null) {
  const bytes = treeBytes ?? git(repo, ["ls-tree", "-r", "-z", "--full-tree", commit]);
  const entries = parseGitTree(bytes);
  const actualPaths = walk(destination);
  if (actualPaths.length !== entries.length) fail(`snapshot ${commit}: число файлов ${actualPaths.length}, ожидалось ${entries.length}`, "SNAPSHOT_COUNT");
  for (const entry of entries) {
    if (entry.type !== "blob") fail(`${entry.path}: gitlink/tree внутри tracked snapshot не поддержан`, "SNAPSHOT_TYPE");
    const actual = snapshotEntry(destination, entry, repo);
    if (actual.mode !== entry.mode || actual.object !== entry.object) fail(`${entry.path}: mode/blob не совпадает с ${commit}`, "SNAPSHOT_MISMATCH");
  }
  return { entries, bytes };
}

function makeReadOnly(path) {
  const stat = lstatSync(path);
  if (stat.isSymbolicLink()) return;
  if (stat.isDirectory()) {
    for (const entry of readdirSync(path)) makeReadOnly(join(path, entry));
    chmodSync(path, 0o500);
  } else if (stat.isFile()) {
    chmodSync(path, (stat.mode & 0o111) === 0 ? 0o400 : 0o500);
  }
}

function makeWritable(path) {
  const stat = lstatSafe(path);
  if (stat === null || stat.isSymbolicLink()) return;
  if (stat.isDirectory()) {
    chmodSync(path, 0o700);
    for (const entry of readdirSync(path)) makeWritable(join(path, entry));
  } else if (stat.isFile()) {
    chmodSync(path, (stat.mode & 0o111) === 0 ? 0o600 : 0o700);
  }
}

export function materializeTree(repo, commit, destination, captureDirectory) {
  mkdirSync(destination, { recursive: false });
  mkdirSync(captureDirectory, { recursive: true });
  const indexPath = join(captureDirectory, `index-${basename(destination)}`);
  const env = { ...process.env, GIT_INDEX_FILE: indexPath };
  git(repo, ["read-tree", commit], { env });
  git(repo, ["checkout-index", "--all", `--prefix=${destination}${sep}`], { env });
  const treeBytes = git(repo, ["ls-tree", "-r", "-z", "--full-tree", commit]);
  const verified = verifySnapshot(repo, commit, destination, treeBytes);
  return { treeBytes, count: verified.entries.length };
}

function isBearerPlaceholder(value) {
  const lower = value.toLowerCase();
  return ["example", "dummy", "test", "changeme", "replace"].includes(lower)
    || /^<[^>]+>$/.test(value)
    || /^\$\{[^}]+\}$/.test(value)
    || /^\*+$/.test(value);
}

export function scanSecrets(reviewRoot) {
  const findings = [];
  const rules = [
    ["private-key", /-----BEGIN (?:RSA |EC |DSA |OPENSSH |PGP )?PRIVATE KEY-----/g],
    ["provider-token", /(?:gh[pousr]_[A-Za-z0-9]{36,255}|github_pat_[A-Za-z0-9_]{82,255}|sk-ant-[A-Za-z0-9_-]{20,255}|sk-(?:proj-)?[A-Za-z0-9_-]{20,255}|AIza[0-9A-Za-z_-]{35}|xox[baprs]-[0-9A-Za-z-]{10,255}|(?:AKIA|ASIA)[A-Z0-9]{16})/g],
    ["bearer-token", /Authorization:\s*Bearer\s+([^\s"']{20,4096})/gi],
  ];
  for (const section of ["current", "base", "input"]) {
    const root = join(reviewRoot, section);
    for (const path of walk(root)) {
      const absolute = join(root, path);
      const stat = lstatSync(absolute);
      if (!stat.isFile()) continue;
      const text = readFileSync(absolute).toString("latin1");
      for (const [rule, pattern] of rules) {
        pattern.lastIndex = 0;
        for (let match = pattern.exec(text); match; match = pattern.exec(text)) {
          if (rule === "bearer-token" && isBearerPlaceholder(match[1])) continue;
          const line = text.slice(0, match.index).split("\n").length;
          findings.push({ rule, path: `${section}/${path}`, line });
        }
      }
    }
  }
  if (findings.length > 0) {
    fail(`secret scan: ${findings.map((item) => `${item.rule}:${item.path}:${item.line}`).join(", ")}`, "SECRET_SCAN");
  }
  return [];
}

export function resolveSettingsPaths(home = homedir()) {
  return {
    claude: join(home, ".claude", "settings.json"),
    gemini: join(home, ".gemini", "antigravity-cli", "settings.json"),
  };
}

function readRegularSettings(path) {
  const stat = lstatSafe(path);
  if (stat === null || !stat.isFile() || stat.isSymbolicLink()) fail(`${path}: settings должен быть обычным файлом`, "SETTINGS_FILE");
  return readFileSync(path);
}

function redactSettingsStrings(value, secretValues) {
  if (typeof value === "string") {
    let redacted = value;
    for (const secret of secretValues) redacted = redacted.split(secret).join("<redacted-oauth-token>");
    return redacted;
  }
  if (Array.isArray(value)) return value.map((item) => redactSettingsStrings(item, secretValues));
  if (value !== null && typeof value === "object") {
    return Object.fromEntries(Object.entries(value).map(([key, child]) => [
      redactSettingsStrings(key, secretValues),
      redactSettingsStrings(child, secretValues),
    ]));
  }
  return value;
}

function captureSettingsCheckpoint(path, client) {
  const bytes = readRegularSettings(path);
  let settings;
  try {
    settings = JSON.parse(bytes.toString("utf8"));
  } catch {
    fail(`${client} settings содержит невалидный JSON`, client === "claude" ? "CLAUDE_SETTINGS" : "GEMINI_SETTINGS");
  }
  let oauthToken = null;
  if (client === "claude") {
    oauthToken = settings?.env?.CLAUDE_CODE_OAUTH_TOKEN;
    if (typeof oauthToken !== "string" || oauthToken.length < 16) fail("Claude OAuth token недоступен", "CLAUDE_OAUTH");
  }
  const redacted = redactSettingsStrings(settings, oauthToken === null ? [] : [oauthToken]);
  return {
    bytes,
    configurationSha256: sha256Bytes(Buffer.from(canonicalJson(redacted))),
    oauthToken,
  };
}

export function settingsConfigurationSha256(path, client) {
  return captureSettingsCheckpoint(path, client).configurationSha256;
}

function settingsCheckpointUnchanged(before, path, client) {
  const after = captureSettingsCheckpoint(path, client);
  return before.bytes.equals(after.bytes) && before.configurationSha256 === after.configurationSha256;
}

export function buildReviewerEnvironment(parentEnvironment, client, oauthToken = null) {
  const environment = {};
  for (const key of ENV_ALLOWLIST) {
    const value = parentEnvironment[key];
    if (typeof value === "string" && value !== "") environment[key] = value;
  }
  if (client === "claude") {
    if (typeof oauthToken !== "string" || oauthToken.length < 16) fail("Claude OAuth token недоступен", "CLAUDE_OAUTH");
    environment.CLAUDE_CODE_OAUTH_TOKEN = oauthToken;
  }
  return environment;
}

export function validateGeminiSettings(settingsPath) {
  let settings;
  try {
    settings = JSON.parse(readFileSync(settingsPath, "utf8"));
  } catch {
    fail("Gemini settings содержит невалидный JSON", "GEMINI_SETTINGS");
  }
  const visit = (value, path = "$") => {
    if (Array.isArray(value)) {
      value.forEach((item, index) => visit(item, `${path}[${index}]`));
      return;
    }
    if (value === null || typeof value !== "object") return;
    if (value.permissions && typeof value.permissions === "object" && Object.hasOwn(value.permissions, "allow")) {
      fail(`${path}.permissions.allow запрещён для reviewer-а`, "GEMINI_SETTINGS_PERMISSION");
    }
    for (const [key, child] of Object.entries(value)) {
      if (/^(?:command|commands|mcp|mcpServers)$/i.test(key) && child && (typeof child !== "object" || Object.keys(child).length > 0)) {
        fail(`${path}.${key} запрещён для reviewer-а`, "GEMINI_SETTINGS_TOOL");
      }
      visit(child, `${path}.${key}`);
    }
  };
  visit(settings);
  return true;
}

export function buildClaudeArgs(reviewRoot, prompt, model = "claude-opus-4-6") {
  return [
    "--add-dir", reviewRoot,
    "--output-format", "text",
    "--permission-mode", "plan",
    "--disable-slash-commands",
    "--no-session-persistence",
    "--no-chrome",
    "--tools", "Read,Glob,Grep",
    "--strict-mcp-config",
    "--mcp-config", "{\"mcpServers\":{}}",
    "--setting-sources", "",
    "--settings", "{}",
    "--model", model,
    "--effort", "high",
    "--print", prompt,
  ];
}

function parseDuration(value) {
  const match = String(value).match(/^(\d+)(ms|s|m)$/);
  if (!match) fail(`неверный timeout ${value}`, "TIMEOUT_VALUE");
  const factor = { ms: 1, s: 1_000, m: 60_000 }[match[2]];
  return Number(match[1]) * factor;
}

export function buildGeminiArgs(reviewRoot, prompt, printTimeout = GEMINI_PRINT_TIMEOUT) {
  return [
    "--add-dir", reviewRoot,
    "--output-format", "text",
    "--mode", "plan",
    "--sandbox",
    "--disable-slash-commands",
    "--effort", "high",
    "--model", "gemini-3.1-pro-high",
    "--print-timeout", printTimeout,
    "--print", prompt,
  ];
}

export function validateGeminiInvocation(args, hardTimeoutMs = HARD_TIMEOUT_MS) {
  const forbidden = new Set(["--project", "--dangerously-skip-permissions", "--command"]);
  if (args.some((value) => forbidden.has(value) || /permissions\.allow/i.test(value))) fail("Gemini argv содержит запрещённый флаг", "GEMINI_ARGV");
  const addDir = args.indexOf("--add-dir");
  const print = args.lastIndexOf("--print");
  const timeout = args.indexOf("--print-timeout");
  if (addDir === -1 || print === -1 || print !== args.length - 2 || timeout === -1) fail("Gemini argv не соответствует контракту", "GEMINI_ARGV");
  if (parseDuration(args[timeout + 1]) >= hardTimeoutMs) fail("внутренний timeout Gemini должен быть меньше hard-timeout", "TIMEOUT_ORDER");
  return true;
}

function terminateGroup(pid, signal) {
  try {
    process.kill(-pid, signal);
  } catch {
    // Процесс мог завершиться между проверкой и сигналом.
  }
}

function processGroupExists(pid) {
  if (!Number.isInteger(pid) || pid <= 0) return false;
  try {
    process.kill(-pid, 0);
    return true;
  } catch (error) {
    return error?.code === "EPERM";
  }
}

export function startProcessGroup(command, args, options = {}) {
  const child = spawn(command, args, {
    cwd: options.cwd,
    env: options.env,
    detached: true,
    stdio: ["ignore", "pipe", "pipe"],
  });
  const stdout = [];
  const stderr = [];
  let timedOut = false;
  let cancelled = false;
  let killTimer = null;
  let terminationStarted = false;
  let outputExceeded = false;
  let outputBytes = 0;
  const finishGroup = (reason) => {
    if (reason === "timeout") timedOut = true;
    if (reason === "cancel") cancelled = true;
    if (terminationStarted) return;
    terminationStarted = true;
    terminateGroup(child.pid, "SIGTERM");
    killTimer = setTimeout(() => terminateGroup(child.pid, "SIGKILL"), options.killGraceMs ?? 10_000);
  };
  const timeout = setTimeout(() => finishGroup("timeout"), options.timeoutMs ?? HARD_TIMEOUT_MS);
  const abort = () => finishGroup("cancel");
  if (options.signal) {
    if (options.signal.aborted) abort();
    else options.signal.addEventListener("abort", abort, { once: true });
  }
  const capture = (target, chunk) => {
    outputBytes += chunk.length;
    if (outputBytes > (options.maxOutputBytes ?? 16 * 1024 * 1024)) {
      outputExceeded = true;
      finishGroup("output");
      return;
    }
    target.push(chunk);
  };
  child.stdout.on("data", (chunk) => capture(stdout, chunk));
  child.stderr.on("data", (chunk) => capture(stderr, chunk));
  const done = new Promise((resolvePromise) => {
    const cleanup = () => {
      clearTimeout(timeout);
      if (killTimer && !processGroupExists(child.pid)) clearTimeout(killTimer);
      if (options.signal) options.signal.removeEventListener("abort", abort);
    };
    child.once("error", (error) => {
      cleanup();
      resolvePromise({ code: null, signal: null, timedOut, cancelled, outputExceeded, stdout: Buffer.concat(stdout), stderr: Buffer.concat(stderr), error: error.message });
    });
    child.once("close", (code, signal) => {
      cleanup();
      resolvePromise({ code, signal, timedOut, cancelled, outputExceeded, stdout: Buffer.concat(stdout), stderr: Buffer.concat(stderr), error: null });
    });
  });
  return { child, done, terminate: finishGroup };
}

async function runShort(command, args, options) {
  const processGroup = startProcessGroup(command, args, {
    cwd: options.cwd,
    env: options.env,
    timeoutMs: options.timeoutMs ?? 180_000,
    killGraceMs: options.killGraceMs ?? 10_000,
    signal: options.signal,
  });
  let mcpDetected = false;
  const monitor = setInterval(() => {
    if (descendantsContainMcp(new Set([processGroup.child.pid]))) {
      mcpDetected = true;
      processGroup.terminate("policy");
    }
  }, options.monitorIntervalMs ?? 250);
  const result = await processGroup.done;
  clearInterval(monitor);
  if (result.cancelled) fail(`${basename(command)} preflight отменён пользователем`, "CLIENT_CANCELLED");
  if (result.code !== 0 || result.error || result.timedOut || result.outputExceeded || mcpDetected) {
    fail(`${basename(command)} preflight завершился ошибкой`, "CLIENT_PREFLIGHT");
  }
  for (const value of options.forbiddenValues ?? []) {
    if (typeof value === "string" && value !== "" && (result.stdout.includes(Buffer.from(value)) || result.stderr.includes(Buffer.from(value)))) {
      fail(`${basename(command)} preflight вывел запрещённое credential-значение`, "CLIENT_SECRET_OUTPUT");
    }
  }
  const output = `${result.stdout.toString("utf8")}\n${result.stderr.toString("utf8")}`;
  if (/<hook_prompt|hook_run_id|обратная связь от хука/iu.test(output)) fail(`${basename(command)} preflight загрязнён hook`, "CLIENT_HOOK");
  return output.trim();
}

async function clientPreflight({ commands, reviewRoot, environments, selfTest, signal }) {
  const preflightOptions = { cwd: reviewRoot, killGraceMs: selfTest ? 100 : 10_000, monitorIntervalMs: selfTest ? 50 : 250, signal };
  const claudeOptions = { ...preflightOptions, env: environments.claude, forbiddenValues: [environments.claude.CLAUDE_CODE_OAUTH_TOKEN] };
  const claudeVersion = await runShort(commands.claude, ["--version"], claudeOptions);
  const auth = await runShort(commands.claude, ["auth", "status"], claudeOptions);
  if (!/oauth_token/i.test(auth)) fail("Claude auth status не подтверждает oauth_token", "CLAUDE_AUTH_MODE");
  const geminiVersion = await runShort(commands.gemini, ["--version"], { ...preflightOptions, env: environments.gemini });
  const models = await runShort(commands.gemini, ["models"], { ...preflightOptions, env: environments.gemini });
  if (!/gemini-3\.1-pro-high/i.test(models)) fail("модель gemini-3.1-pro-high недоступна", "GEMINI_MODEL");
  const marker = "WORKFLOW_SPEC_REVIEW_READ_SMOKE_OK";
  const smokePrompt = `Прочитай input/prompt.md и верни только ${marker}`;
  const claudeSmoke = await runShort(commands.claude, buildClaudeArgs(reviewRoot, smokePrompt), claudeOptions);
  const geminiSmokeArgs = buildGeminiArgs(reviewRoot, smokePrompt, selfTest ? "2s" : "2m");
  validateGeminiInvocation(geminiSmokeArgs, selfTest ? 3_000 : 3 * 60_000);
  const geminiSmoke = await runShort(commands.gemini, geminiSmokeArgs, { ...preflightOptions, env: environments.gemini, timeoutMs: selfTest ? 3_000 : 3 * 60_000 });
  if (claudeSmoke.trim() !== marker || geminiSmoke.trim() !== marker) fail("task-local read-smoke не подтверждён", "READ_SMOKE");
  return {
    claudeVersion,
    geminiVersion,
    modelListSha256: sha256Bytes(Buffer.from(models)),
    checks: {
      claudeAuth: "oauth_token-confirmed",
      claudeReadSmoke: true,
      geminiModel: "gemini-3.1-pro-high-confirmed",
      geminiReadSmoke: true,
    },
  };
}

function uniqueIdentityCount(text, identity) {
  return text.split(identity).length - 1;
}

function containsHookEvidence(...values) {
  return values.some((value) => /<hook_prompt|hook_run_id|обратная связь от хука/iu.test(Buffer.isBuffer(value) ? value.toString("utf8") : String(value)));
}

function containsExactValue(value, ...buffers) {
  if (typeof value !== "string" || value === "") return false;
  const needle = Buffer.from(value);
  return buffers.some((buffer) => buffer.includes(needle));
}

export function validateReviewerResponse(bytes, identity) {
  let text;
  try {
    text = new TextDecoder("utf-8", { fatal: true }).decode(bytes);
  } catch {
    return false;
  }
  if (bytes.length === 0 || bytes.includes(0) || uniqueIdentityCount(text, identity) !== 1) return false;
  if (containsHookEvidence(text)) return false;
  const lines = text.split(/\r?\n/);
  const identityIndexes = lines.flatMap((line, index) => line === identity ? [index] : []);
  if (identityIndexes.length !== 1) return false;
  const after = lines.slice(identityIndexes[0] + 1);
  while (after[0] === "") after.shift();
  while (after.at(-1) === "") after.pop();
  const indexes = RESPONSE_MARKERS.map((marker) => after.flatMap((line, index) => line === marker ? [index] : []));
  if (indexes.some((values) => values.length !== 1)) return false;
  const positions = indexes.map((values) => values[0]);
  if (positions[0] !== 0 || positions.some((position, index) => index > 0 && position <= positions[index - 1])) return false;
  for (let index = 0; index < positions.length - 1; index += 1) {
    if (!after.slice(positions[index] + 1, positions[index + 1]).some((line) => line.trim() !== "")) return false;
  }
  const verdict = after.slice(positions.at(-1) + 1).filter((line) => line.trim() !== "");
  return verdict.length === 1 && RESPONSE_VERDICTS.has(verdict[0]);
}

function processTable() {
  try {
    return execFileSync("ps", ["-axo", "pid=,ppid=,command="], { encoding: "utf8" });
  } catch {
    return null;
  }
}

function descendantsContainMcp(rootPids) {
  const table = processTable();
  if (table === null) return true;
  const rows = table.split("\n").map((line) => {
    const match = line.match(/^\s*(\d+)\s+(\d+)\s+(.*)$/);
    return match ? { pid: Number(match[1]), ppid: Number(match[2]), command: match[3] } : null;
  }).filter(Boolean);
  const descendants = new Set();
  let changed = true;
  while (changed) {
    changed = false;
    for (const row of rows) {
      if (!descendants.has(row.pid) && (rootPids.has(row.ppid) || descendants.has(row.ppid))) {
        descendants.add(row.pid);
        changed = true;
      }
    }
  }
  return rows.some((row) => descendants.has(row.pid) && /(?:laravel.{0,20}boost|boost:mcp|mcp[-_]server)/i.test(row.command));
}

function clientMetadata(preflight, settingsPaths, settingsCheckpoints) {
  return {
    claude: {
      authMode: "oauth_token",
      binary: "claude",
      model: "claude-opus-4-6",
      mcp: "disabled",
      settingsPath: settingsPaths.claude,
      settingsConfigurationSha256: settingsCheckpoints.claude.configurationSha256,
      tools: ["Read", "Glob", "Grep"],
      transport: "official-cli",
      version: preflight.claudeVersion,
      preflight: {
        auth: preflight.checks.claudeAuth,
        readSmoke: preflight.checks.claudeReadSmoke,
        settingsStable: true,
      },
    },
    gemini: {
      apiBilling: false,
      binary: "agy",
      model: "gemini-3.1-pro-high",
      modelListSha256: preflight.modelListSha256,
      sandbox: true,
      settingsPath: settingsPaths.gemini,
      settingsConfigurationSha256: settingsCheckpoints.gemini.configurationSha256,
      transport: "official-cli",
      version: preflight.geminiVersion,
      preflight: {
        model: preflight.checks.geminiModel,
        readSmoke: preflight.checks.geminiReadSmoke,
        settingsStable: true,
      },
    },
  };
}

function createRunId() {
  return `${new Date().toISOString().replace(/[-:.TZ]/g, "").slice(0, 14)}-${process.pid}-${randomBytes(4).toString("hex")}`;
}

function ensureRevision(taskRoot, revision) {
  validatePathSegment(String(revision), "revision", /^\d{2,6}$/);
  const revisionsRoot = join(taskRoot, "revisions");
  ensureContainedDirectory(taskRoot, revisionsRoot, "revisions");
  const directory = join(revisionsRoot, String(revision));
  const directoryStat = lstatSafe(directory);
  if (directoryStat === null || !directoryStat.isDirectory() || directoryStat.isSymbolicLink()) fail(`ревизия ${revision} не найдена как обычный каталог`, "REVISION_MISSING");
  ensureContainedDirectory(taskRoot, directory, `ревизия ${revision}`);
  exactFileSet(directory, ["author-review.md", "tz.md"]);
  assertRegularFile(join(directory, "author-review.md"), "REVISION_FILE");
  assertRegularFile(join(directory, "tz.md"), "REVISION_FILE");
  return directory;
}

function buildSnapshot({ repo, base, head, taskRoot, revision, runId }) {
  const revisionRoot = ensureRevision(taskRoot, revision);
  validatePathSegment(runId, "run-id", /^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/);
  const runsRoot = join(taskRoot, "runs");
  mkdirSync(runsRoot, { recursive: true });
  ensureContainedDirectory(taskRoot, runsRoot, "runs");
  const runRoot = join(runsRoot, runId);
  const capture = join(runRoot, "capture");
  const reviewRoot = join(runRoot, "review-root");
  mkdirSync(runRoot, { recursive: false });
  mkdirSync(capture);
  mkdirSync(reviewRoot);
  mkdirSync(join(reviewRoot, "input"));
  mkdirSync(join(reviewRoot, "results"));
  const current = materializeTree(repo, head, join(reviewRoot, "current"), capture);
  const baseTree = materializeTree(repo, base, join(reviewRoot, "base"), capture);
  const patch = git(repo, ["diff", "--binary", "--full-index", "--no-ext-diff", base, head, "--"]);
  writeFileSync(join(reviewRoot, "input", "changes.patch"), patch, { flag: "wx" });
  copyFileSync(join(revisionRoot, "tz.md"), join(reviewRoot, "input", "tz.md"));
  copyFileSync(join(repo, PROMPT_PATH), join(reviewRoot, "input", "prompt.md"));
  return { runRoot, capture, reviewRoot, current, baseTree, patch, revisionRoot };
}

export function buildReviewIdentity({ base, head, currentTreeBytes, baseTreeBytes, patchBytes, specBytes, promptBytes, clients }) {
  const hashes = {
    current: sha256Bytes(currentTreeBytes),
    base_tree: sha256Bytes(baseTreeBytes),
    patch: sha256Bytes(patchBytes),
    spec: sha256Bytes(specBytes),
    prompt: sha256Bytes(promptBytes),
    clients: sha256Bytes(Buffer.from(canonicalJson(clients))),
  };
  const identity = `TZ review snapshot: base=${base} head=${head} current=${hashes.current} base_tree=${hashes.base_tree} patch=${hashes.patch} spec=${hashes.spec} prompt=${hashes.prompt} clients=${hashes.clients}`;
  return { hashes, identity };
}

function manifestFor({ base, head, snapshot, prompt, clients }) {
  const { hashes, identity } = buildReviewIdentity({
    base,
    head,
    currentTreeBytes: snapshot.current.treeBytes,
    baseTreeBytes: snapshot.baseTree.treeBytes,
    patchBytes: snapshot.patch,
    specBytes: readFileSync(join(snapshot.reviewRoot, "input", "tz.md")),
    promptBytes: prompt.bytes,
    clients,
  });
  return {
    schemaVersion: 1,
    base,
    head,
    counts: { current: snapshot.current.count, base: snapshot.baseTree.count },
    algorithms: { tree: "sha256(git-ls-tree-r-z-full-tree)", files: "sha256(exact-bytes)", clients: "sha256(canonical-json)" },
    hashes,
    clients,
    identity,
  };
}

export async function executeReviewRun(options) {
  const selfTest = options.selfTest === true;
  const selfTestOnlyOptions = ["commands", "environment", "settingsPaths", "nodeVersion", "hardTimeoutMs", "geminiPrintTimeout"];
  if (!selfTest && selfTestOnlyOptions.some((key) => Object.hasOwn(options, key))) {
    fail("подмена reviewer runtime разрешена только в offline self-test", "SELF_TEST_OVERRIDE");
  }
  assertNodeVersion(selfTest ? options.nodeVersion : undefined);
  const repo = realpathSync(resolve(options.repo));
  const taskRoot = ensureTaskLocalPath(options.taskRoot);
  if (pathsOverlap(repo, taskRoot)) fail("task-root и source repo не должны совпадать или быть вложены друг в друга", "PATH_SCOPE");
  assertSourceRepository(repo, options.base, options.head);
  const runId = options.runId ?? createRunId();
  const snapshot = buildSnapshot({ repo, base: options.base, head: options.head, taskRoot, revision: options.revision, runId });
  const prompt = readStrictPrompt(join(snapshot.reviewRoot, "input", "prompt.md"));
  const settingsPaths = selfTest && options.settingsPaths ? options.settingsPaths : resolveSettingsPaths();
  const settingsBefore = {
    claude: captureSettingsCheckpoint(settingsPaths.claude, "claude"),
    gemini: captureSettingsCheckpoint(settingsPaths.gemini, "gemini"),
  };
  const oauth = settingsBefore.claude.oauthToken;
  validateGeminiSettings(settingsPaths.gemini);
  const environments = {
    claude: buildReviewerEnvironment(selfTest ? (options.environment ?? process.env) : process.env, "claude", oauth),
    gemini: buildReviewerEnvironment(selfTest ? (options.environment ?? process.env) : process.env, "gemini"),
  };
  const commands = selfTest && options.commands ? options.commands : { claude: "claude", gemini: "agy" };
  const controller = new AbortController();
  const externalSignal = options.signal;
  const abort = () => controller.abort();
  if (externalSignal?.aborted) controller.abort();
  else if (externalSignal) externalSignal.addEventListener("abort", abort, { once: true });
  try {
    verifySnapshot(repo, options.head, join(snapshot.reviewRoot, "current"), snapshot.current.treeBytes);
    verifySnapshot(repo, options.base, join(snapshot.reviewRoot, "base"), snapshot.baseTree.treeBytes);
    scanSecrets(snapshot.reviewRoot);
    writeFileSync(join(snapshot.capture, "preflight-started.json"), `${JSON.stringify({ schemaVersion: 1, runId, base: options.base, head: options.head }, null, 2)}\n`, { flag: "wx" });
    let preflight;
    try {
      preflight = await clientPreflight({ commands, reviewRoot: snapshot.reviewRoot, environments, selfTest, signal: controller.signal });
    } catch (error) {
      if (error?.code !== "CLIENT_CANCELLED" && !controller.signal.aborted) throw error;
      const cancelledSummary = {
        schemaVersion: 1,
        runId,
        identity: null,
        status: "cancelled_by_user",
        target: "X03",
        returnState: null,
        stopMode: "final_cancellation",
        settingsUnchanged: settingsCheckpointUnchanged(settingsBefore.claude, settingsPaths.claude, "claude")
          && settingsCheckpointUnchanged(settingsBefore.gemini, settingsPaths.gemini, "gemini"),
        mcpDetected: false,
        hookDetected: false,
        secretOutputDetected: false,
        responsesValid: false,
        clientsPassed: false,
        qualificationRequired: false,
      };
      writeFileSync(join(snapshot.capture, "summary.json"), `${JSON.stringify(cancelledSummary, null, 2)}\n`, { flag: "wx" });
      return { ...cancelledSummary, runRoot: snapshot.runRoot, reviewRoot: snapshot.reviewRoot };
    }
    if (!settingsCheckpointUnchanged(settingsBefore.claude, settingsPaths.claude, "claude")
      || !settingsCheckpointUnchanged(settingsBefore.gemini, settingsPaths.gemini, "gemini")) fail("settings изменились во время preflight", "SETTINGS_CHANGED");
    const clients = clientMetadata(preflight, settingsPaths, settingsBefore);
    const manifest = manifestFor({ base: options.base, head: options.head, snapshot, prompt, clients });
    writeFileSync(join(snapshot.reviewRoot, "input", "manifest.json"), `${JSON.stringify(manifest, null, 2)}\n`, { flag: "wx" });
    verifySnapshot(repo, options.head, join(snapshot.reviewRoot, "current"), snapshot.current.treeBytes);
    verifySnapshot(repo, options.base, join(snapshot.reviewRoot, "base"), snapshot.baseTree.treeBytes);
    scanSecrets(snapshot.reviewRoot);
    for (const path of [join(snapshot.reviewRoot, "current"), join(snapshot.reviewRoot, "base"), join(snapshot.reviewRoot, "input")]) makeReadOnly(path);
    if (readdirSync(join(snapshot.reviewRoot, "results")).length !== 0) fail("results должен быть пустым", "RESULTS_NOT_EMPTY");
    if (!settingsCheckpointUnchanged(settingsBefore.claude, settingsPaths.claude, "claude")
      || !settingsCheckpointUnchanged(settingsBefore.gemini, settingsPaths.gemini, "gemini")) fail("settings изменились перед review", "SETTINGS_CHANGED");
    writeFileSync(join(snapshot.capture, "started.json"), `${JSON.stringify({ identity: manifest.identity, runId }, null, 2)}\n`, { flag: "wx" });

    const claudeArgs = buildClaudeArgs(snapshot.reviewRoot, prompt.text);
    const geminiTimeout = selfTest && options.geminiPrintTimeout ? options.geminiPrintTimeout : GEMINI_PRINT_TIMEOUT;
    const geminiArgs = buildGeminiArgs(snapshot.reviewRoot, prompt.text, geminiTimeout);
    const hardTimeoutMs = selfTest && options.hardTimeoutMs ? options.hardTimeoutMs : HARD_TIMEOUT_MS;
    validateGeminiInvocation(geminiArgs, hardTimeoutMs);
    const claude = startProcessGroup(commands.claude, claudeArgs, { cwd: snapshot.reviewRoot, env: environments.claude, timeoutMs: hardTimeoutMs, killGraceMs: selfTest ? 100 : 10_000, signal: controller.signal });
    const gemini = startProcessGroup(commands.gemini, geminiArgs, { cwd: snapshot.reviewRoot, env: environments.gemini, timeoutMs: hardTimeoutMs, killGraceMs: selfTest ? 100 : 10_000, signal: controller.signal });
    let mcpDetected = false;
    const monitor = setInterval(() => {
      if (descendantsContainMcp(new Set([claude.child.pid, gemini.child.pid]))) {
        mcpDetected = true;
        claude.terminate("policy");
        gemini.terminate("policy");
      }
    }, selfTest ? 50 : 250);
    const [claudeResult, geminiResult] = await Promise.all([claude.done, gemini.done]);
    clearInterval(monitor);
    const secretOutputDetected = containsExactValue(
      oauth,
      claudeResult.stdout,
      claudeResult.stderr,
      geminiResult.stdout,
      geminiResult.stderr,
    );
    const redactedOutput = Buffer.from("[reviewer output removed: credential exposure detected]\n");
    writeFileSync(join(snapshot.capture, "claude.stdout"), secretOutputDetected ? redactedOutput : claudeResult.stdout);
    writeFileSync(join(snapshot.capture, "claude.stderr"), secretOutputDetected ? redactedOutput : claudeResult.stderr);
    writeFileSync(join(snapshot.capture, "gemini.stdout"), secretOutputDetected ? redactedOutput : geminiResult.stdout);
    writeFileSync(join(snapshot.capture, "gemini.stderr"), secretOutputDetected ? redactedOutput : geminiResult.stderr);
    const settingsUnchanged = settingsCheckpointUnchanged(settingsBefore.claude, settingsPaths.claude, "claude")
      && settingsCheckpointUnchanged(settingsBefore.gemini, settingsPaths.gemini, "gemini");
    const cancelled = claudeResult.cancelled || geminiResult.cancelled || controller.signal.aborted;
    const clientsPassed = [claudeResult, geminiResult].every((result) => result.code === 0 && !result.timedOut && !result.cancelled && !result.outputExceeded && result.error === null);
    const hookDetected = containsHookEvidence(claudeResult.stdout, claudeResult.stderr, geminiResult.stdout, geminiResult.stderr);
    const responsesValid = !hookDetected && !secretOutputDetected
      && validateReviewerResponse(claudeResult.stdout, manifest.identity)
      && validateReviewerResponse(geminiResult.stdout, manifest.identity);
    let status = "completed";
    let target = "P03";
    if (cancelled) {
      status = "cancelled_by_user";
      target = "X03";
    } else if (!clientsPassed || !responsesValid || mcpDetected || !settingsUnchanged) {
      status = "blocked";
      target = "B01";
    }
    if (status === "completed") {
      publishAcceptedResults(snapshot.reviewRoot, claudeResult.stdout, geminiResult.stdout);
    } else if (readdirSync(join(snapshot.reviewRoot, "results")).length !== 0) {
      fail("невалидный запуск оставил accepted results", "RESULT_ATOMICITY");
    }
    const summary = {
      schemaVersion: 1,
      runId,
      identity: manifest.identity,
      status,
      target,
      returnState: target === "B01" ? "P03" : null,
      stopMode: target === "X03" ? "final_cancellation" : null,
      settingsUnchanged,
      mcpDetected,
      hookDetected,
      secretOutputDetected,
      responsesValid,
      clientsPassed,
      qualificationRequired: status === "completed",
    };
    writeFileSync(join(snapshot.capture, "summary.json"), `${JSON.stringify(summary, null, 2)}\n`, { flag: "wx" });
    return { ...summary, runRoot: snapshot.runRoot, reviewRoot: snapshot.reviewRoot };
  } finally {
    if (externalSignal) externalSignal.removeEventListener("abort", abort);
  }
}

function fileHash(path) {
  return sha256Bytes(readFileSync(path));
}

export function verifyFinalDirectory(finalRoot) {
  exactFileSet(finalRoot, FINAL_FILES);
  for (const path of FINAL_FILES) assertRegularFile(join(finalRoot, path), "FINAL_FILE_TYPE");
  let manifest;
  try {
    manifest = JSON.parse(readFileSync(join(finalRoot, "manifest.json"), "utf8"));
  } catch {
    fail("итоговый manifest содержит невалидный JSON", "FINAL_MANIFEST");
  }
  const keys = Object.keys(manifest).sort();
  if (canonicalJson(keys) !== canonicalJson(["artifacts", "identity", "revision", "runId", "schemaVersion"].sort())
    || manifest.schemaVersion !== 1 || typeof manifest.identity !== "string" || manifest.identity === ""
    || typeof manifest.revision !== "string" || typeof manifest.runId !== "string" || !Array.isArray(manifest.artifacts)) {
    fail("итоговый manifest не соответствует схеме", "FINAL_MANIFEST");
  }
  const artifactPaths = manifest.artifacts.map((artifact) => artifact?.path);
  if (canonicalJson(artifactPaths) !== canonicalJson([...FINAL_ARTIFACTS].sort())) fail("итоговый manifest содержит неверный набор artifacts", "FINAL_MANIFEST");
  for (const artifact of manifest.artifacts) {
    if (!artifact || canonicalJson(Object.keys(artifact).sort()) !== canonicalJson(["path", "sha256"])
      || typeof artifact.sha256 !== "string" || !/^[0-9a-f]{64}$/.test(artifact.sha256)
      || fileHash(join(finalRoot, artifact.path)) !== artifact.sha256) {
      fail(`${artifact?.path ?? "unknown"}: hash итогового файла не совпадает`, "FINAL_HASH");
    }
  }
  let reviewManifest;
  try {
    reviewManifest = JSON.parse(readFileSync(join(finalRoot, "review-manifest.json"), "utf8"));
  } catch {
    fail("review-manifest итогового набора содержит невалидный JSON", "FINAL_PROVENANCE");
  }
  if (reviewManifest?.identity !== manifest.identity
    || typeof reviewManifest?.hashes?.spec !== "string"
    || fileHash(join(finalRoot, "tz.md")) !== reviewManifest.hashes.spec
    || !validateReviewerResponse(readFileSync(join(finalRoot, "claude.md")), manifest.identity)
    || !validateReviewerResponse(readFileSync(join(finalRoot, "gemini.md")), manifest.identity)) {
    fail("итоговый набор смешивает разные identity, ревизии или результаты", "FINAL_PROVENANCE");
  }
  validateConsolidated(join(finalRoot, "consolidated.md"), manifest.identity);
  return manifest;
}

function validateConsolidated(path, identity) {
  const bytes = readFileSync(path);
  let text;
  try {
    text = new TextDecoder("utf-8", { fatal: true }).decode(bytes);
  } catch {
    fail("сводный вывод не является строгим UTF-8", "FINAL_QUALIFICATION");
  }
  const identityLines = text.split(/\r?\n/).filter((line) => line === identity);
  if (text.trim() === "" || identityLines.length !== 1) {
    fail("сводный вывод должен содержать identity запуска отдельной строкой ровно один раз", "FINAL_QUALIFICATION");
  }
  return true;
}

function removeRunRoots(root) {
  const runsRoot = join(root, "runs");
  if (lstatSafe(runsRoot) === null) return;
  ensureContainedDirectory(root, runsRoot, "runs");
  makeWritable(runsRoot);
  rmSync(runsRoot, { recursive: true, force: false });
}

export function finalizeReviewCycle({ taskRoot, revision, runId }) {
  const root = ensureTaskLocalPath(taskRoot);
  validatePathSegment(runId, "run-id", /^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/);
  const revisionRoot = ensureRevision(root, revision);
  const finalRoot = join(root, "final");
  if (lstatSafe(finalRoot) !== null) {
    ensureContainedDirectory(root, finalRoot, "final");
    const manifest = verifyFinalDirectory(finalRoot);
    if (manifest.revision !== String(revision) || manifest.runId !== runId) {
      fail("итоговый каталог относится к другой ревизии или запуску", "FINAL_EXISTS");
    }
    removeRunRoots(root);
    return {
      finalRoot,
      finalManifestHash: fileHash(join(finalRoot, "manifest.json")),
      artifacts: manifest.artifacts,
      target: "C09",
      reused: true,
    };
  }
  const runsRoot = join(root, "runs");
  ensureContainedDirectory(root, runsRoot, "runs");
  const runRoot = join(runsRoot, runId);
  ensureContainedDirectory(root, runRoot, "run");
  const reviewRoot = join(runRoot, "review-root");
  ensureContainedDirectory(root, reviewRoot, "review-root");
  const input = join(reviewRoot, "input");
  ensureContainedDirectory(root, input, "input");
  const results = join(reviewRoot, "results");
  ensureContainedDirectory(root, results, "results");
  const capture = join(runRoot, "capture");
  ensureContainedDirectory(root, capture, "capture");
  exactFileSet(results, ["claude.md", "consolidated.md", "gemini.md"]);
  for (const path of ["claude.md", "consolidated.md", "gemini.md"]) assertRegularFile(join(results, path), "FINAL_PROVENANCE");
  const reviewManifestPath = join(input, "manifest.json");
  assertRegularFile(reviewManifestPath, "FINAL_PROVENANCE");
  for (const path of ["started.json", "summary.json", "claude.stdout", "gemini.stdout"]) assertRegularFile(join(capture, path), "FINAL_PROVENANCE");
  const reviewManifest = JSON.parse(readFileSync(reviewManifestPath, "utf8"));
  const started = JSON.parse(readFileSync(join(capture, "started.json"), "utf8"));
  const summary = JSON.parse(readFileSync(join(capture, "summary.json"), "utf8"));
  if (started.runId !== runId || started.identity !== reviewManifest.identity) fail("marker запуска не совпадает с review manifest", "FINAL_PROVENANCE");
  if (summary.runId !== runId || summary.identity !== reviewManifest.identity
    || summary.status !== "completed" || summary.target !== "P03"
    || summary.clientsPassed !== true || summary.responsesValid !== true
    || summary.settingsUnchanged !== true || summary.mcpDetected !== false
    || summary.hookDetected !== false
    || summary.secretOutputDetected !== false
    || summary.qualificationRequired !== true) {
    fail("запуск не подтверждает валидный P03", "FINAL_PROVENANCE");
  }
  if (fileHash(join(revisionRoot, "tz.md")) !== reviewManifest.hashes.spec) fail("review относится к другой ревизии ТЗ", "FINAL_PROVENANCE");
  if (!validateReviewerResponse(readFileSync(join(results, "claude.md")), reviewManifest.identity)
    || !validateReviewerResponse(readFileSync(join(results, "gemini.md")), reviewManifest.identity)) fail("внешние результаты невалидны", "FINAL_REVIEW");
  validateConsolidated(join(results, "consolidated.md"), reviewManifest.identity);
  if (!readFileSync(join(results, "claude.md")).equals(readFileSync(join(capture, "claude.stdout")))
    || !readFileSync(join(results, "gemini.md")).equals(readFileSync(join(capture, "gemini.stdout")))) {
    fail("accepted results не совпадают с захваченным stdout", "FINAL_PROVENANCE");
  }
  const pendingFinal = join(root, `.final-pending-${process.pid}-${randomBytes(6).toString("hex")}`);
  mkdirSync(pendingFinal, { recursive: false });
  try {
    const copies = [
      [join(revisionRoot, "tz.md"), "tz.md"],
      [join(revisionRoot, "author-review.md"), "author-review.md"],
      [reviewManifestPath, "review-manifest.json"],
      [join(results, "claude.md"), "claude.md"],
      [join(results, "gemini.md"), "gemini.md"],
      [join(results, "consolidated.md"), "consolidated.md"],
    ];
    for (const [source, destination] of copies) copyFileSync(source, join(pendingFinal, destination));
    const artifacts = FINAL_ARTIFACTS.map((path) => ({ path, sha256: fileHash(join(pendingFinal, path)) })).sort((left, right) => left.path.localeCompare(right.path));
    const manifest = { schemaVersion: 1, identity: reviewManifest.identity, revision: String(revision), runId, artifacts };
    writeFileSync(join(pendingFinal, "manifest.json"), `${JSON.stringify(manifest, null, 2)}\n`, { flag: "wx" });
    exactFileSet(pendingFinal, FINAL_FILES);
    for (const artifact of artifacts) if (fileHash(join(pendingFinal, artifact.path)) !== artifact.sha256) fail(`${artifact.path}: hash итогового файла не совпадает`, "FINAL_HASH");
    const finalManifestHash = fileHash(join(pendingFinal, "manifest.json"));
    renameSync(pendingFinal, finalRoot);
    verifyFinalDirectory(finalRoot);
    removeRunRoots(root);
    return { finalRoot, finalManifestHash, artifacts, target: "C09", reused: false };
  } catch (error) {
    rmSync(pendingFinal, { recursive: true, force: true });
    throw error;
  }
}

function parseArguments(argv) {
  const flags = new Map();
  for (let index = 0; index < argv.length; index += 1) {
    const value = argv[index];
    if (!value.startsWith("--")) fail(`неожиданный аргумент ${value}`, "CLI_ARGS");
    if (["--self-test", "--finalize"].includes(value)) flags.set(value, true);
    else {
      const next = argv[index + 1];
      if (!next || next.startsWith("--")) fail(`после ${value} требуется значение`, "CLI_ARGS");
      flags.set(value, next);
      index += 1;
    }
  }
  return flags;
}

async function main(argv) {
  const flags = parseArguments(argv);
  if (flags.has("--self-test")) fail("offline self-test запускается отдельным workflow-spec-review-self-test.mjs", "CLI_ARGS");
  const taskRoot = flags.get("--task-root");
  const revision = flags.get("--revision");
  if (!taskRoot || !revision) fail("обязательны --task-root и --revision", "CLI_ARGS");
  if (flags.has("--finalize")) {
    const runId = flags.get("--run-id");
    if (!runId) fail("для --finalize обязателен --run-id", "CLI_ARGS");
    console.log(JSON.stringify(finalizeReviewCycle({ taskRoot, revision, runId })));
    return;
  }
  const repo = flags.get("--repo");
  const base = flags.get("--base");
  const head = flags.get("--head");
  if (!repo || !base || !head) fail("обязательны --repo, --base и --head", "CLI_ARGS");
  const controller = new AbortController();
  const cancel = () => controller.abort();
  process.once("SIGINT", cancel);
  process.once("SIGTERM", cancel);
  try {
    console.log(JSON.stringify(await executeReviewRun({ repo, base, head, taskRoot, revision, signal: controller.signal })));
  } finally {
    process.removeListener("SIGINT", cancel);
    process.removeListener("SIGTERM", cancel);
  }
}

if (resolve(process.argv[1] ?? "") === fileURLToPath(import.meta.url)) {
  try {
    await main(process.argv.slice(2));
  } catch (error) {
    console.error(`Error: workflow-spec-review [${error?.code ?? "UNEXPECTED"}]: ${error instanceof Error ? error.message : "неизвестная ошибка"}`);
    process.exitCode = 1;
  }
}
