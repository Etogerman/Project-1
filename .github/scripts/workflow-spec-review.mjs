#!/usr/bin/env node
import { spawn } from "node:child_process";
import { createHash, randomBytes } from "node:crypto";
import {
  accessSync,
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
  rmSync,
  writeFileSync,
} from "node:fs";
import { constants as fsConstants } from "node:fs";
import { homedir } from "node:os";
import { basename, dirname, join, resolve, sep } from "node:path";
import process from "node:process";
import { TextDecoder } from "node:util";
import { fileURLToPath } from "node:url";

export const NODE_MINIMUM_MAJOR = 22;
export const HARD_TIMEOUT_MS = 30 * 60_000;
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
export const PROCESS_POLICY = Object.freeze({
  launchMode: "parallel",
  mcpMode: "disabled",
  unknownLongLivedDescendant: "fail",
  snapshotCommandSeconds: 120,
  preflightSeconds: 180,
  clientSeconds: 1800,
  qualificationSeconds: 300,
  finalDrainSeconds: 30,
  overallSeconds: 2700,
  terminateGraceSeconds: 10,
  killGraceSeconds: 5,
  maxOutputBytesPerProcess: 16_777_216,
});
export const CANONICAL_REVIEW_PROMPT_SHA256 = "b82d29c1754d92fc0b811bea5b35c345cd4df1f2d814075f8ed2fb6847d20382";
const SELF_PATH = fileURLToPath(import.meta.url);
const SHA256_PATTERN = /^[0-9a-f]{64}$/;
const FINDING_ID_PATTERN = /^[A-Z0-9][A-Z0-9._-]{0,63}$/;
const FINAL_FILES = Object.freeze([
  "author-review.md",
  "claude.md",
  "consolidated.md",
  "gemini.md",
  "manifest.json",
  "qualification.json",
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

export function jcsIdentity(value) {
  const copy = structuredClone(value);
  delete copy.identity;
  return sha256Bytes(Buffer.from(canonicalJson(copy)));
}

function exactKeys(value, expected, label, code = "SCHEMA") {
  if (value === null || typeof value !== "object" || Array.isArray(value)) fail(`${label}: ожидался объект`, code);
  const actual = Object.keys(value).sort();
  const wanted = [...expected].sort();
  if (canonicalJson(actual) !== canonicalJson(wanted)) fail(`${label}: неверный набор полей`, code);
}

function nonemptyString(value, label, code = "SCHEMA") {
  if (typeof value !== "string" || value.trim() === "") fail(`${label}: ожидалась непустая строка`, code);
  return value;
}

function assertSha256(value, label, code = "SCHEMA") {
  if (typeof value !== "string" || !SHA256_PATTERN.test(value)) fail(`${label}: ожидался SHA-256 lowercase hex`, code);
}

function assertOid(value, label, code = "SCHEMA") {
  if (typeof value !== "string" || !/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/.test(value)) fail(`${label}: ожидался полный Git OID`, code);
}

function assertStringArray(value, label, { nonempty = false, unique = false, allowEmptyItems = false } = {}, code = "SCHEMA") {
  if (!Array.isArray(value) || (nonempty && value.length === 0)) fail(`${label}: ожидался ${nonempty ? "непустой " : ""}массив строк`, code);
  const seen = new Set();
  for (const [index, item] of value.entries()) {
    if (typeof item !== "string" || (!allowEmptyItems && item.trim() === "")) {
      fail(`${label}[${index}]: ожидалась ${allowEmptyItems ? "" : "непустая "}строка`, code);
    }
    if (unique && seen.has(item)) fail(`${label}: повторное значение ${item}`, code);
    seen.add(item);
  }
}

function assertCanonicalIdentity(value, label, code = "SCHEMA") {
  assertSha256(value.identity, `${label}.identity`, code);
  if (value.identity !== jcsIdentity(value)) fail(`${label}.identity не совпадает с RFC 8785 JCS`, code);
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

export function assertCanonicalPrompt(bytes) {
  if (sha256Bytes(bytes) !== CANONICAL_REVIEW_PROMPT_SHA256) fail("prompt и контрольный SHA рассогласованы", "PROMPT_HASH");
  return true;
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

function publishQualifiedResults(reviewRoot, claudeBytes, geminiBytes, qualificationBytes, consolidatedBytes) {
  const results = join(reviewRoot, "results");
  exactFileSet(results, []);
  const pending = join(reviewRoot, `.results-pending-${process.pid}-${randomBytes(6).toString("hex")}`);
  mkdirSync(pending, { recursive: false });
  try {
    writeFileSync(join(pending, "claude.md"), claudeBytes, { flag: "wx" });
    writeFileSync(join(pending, "gemini.md"), geminiBytes, { flag: "wx" });
    writeFileSync(join(pending, "qualification.json"), qualificationBytes, { flag: "wx" });
    writeFileSync(join(pending, "consolidated.md"), consolidatedBytes, { flag: "wx" });
    exactFileSet(pending, ["claude.md", "gemini.md", "qualification.json", "consolidated.md"]);
    renameSync(pending, results);
  } catch (error) {
    rmSync(pending, { recursive: true, force: true });
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

function remainingBudget(deadlineAt, label, code = "PHASE_TIMEOUT") {
  if (deadlineAt === undefined || deadlineAt === null) return Number.POSITIVE_INFINITY;
  const remaining = deadlineAt - Date.now();
  if (remaining <= 0) fail(`${label}: временной бюджет исчерпан`, code);
  return remaining;
}

async function runLifecycleCommand(command, args, options = {}) {
  const group = startProcessGroup(command, args, {
    cwd: options.cwd,
    env: options.env ?? process.env,
    timeoutMs: options.timeoutMs,
    maxOutputBytes: options.maxOutputBytes ?? PROCESS_POLICY.maxOutputBytesPerProcess,
    terminateGraceMs: options.terminateGraceMs,
    killGraceAfterMs: options.killGraceAfterMs,
    finalDrainMs: options.finalDrainMs,
    signal: options.signal,
  });
  const started = await group.started;
  const result = await group.done;
  if (!started.ok || result.error || result.timedOut || result.cancelled || result.outputExceeded || result.identityMismatch
    || result.residualProcessDetected || !result.drainComplete || result.code !== 0) {
    const error = new Error(`${basename(command)} завершился ошибкой: ${result.stderr.toString("utf8").trim()}`);
    error.code = result.timedOut ? "PROCESS_TIMEOUT" : result.outputExceeded ? "PROCESS_OUTPUT_LIMIT" : "PROCESS_FAILED";
    error.processResult = result;
    throw error;
  }
  return result;
}

async function git(repo, args, options = {}) {
  const timeout = Math.max(1, Math.min(
    options.timeoutMs ?? PROCESS_POLICY.snapshotCommandSeconds * 1_000,
    remainingBudget(options.deadlineAt, "Git snapshot"),
    remainingBudget(options.overallDeadlineAt, "общий запуск"),
  ));
  const result = await runLifecycleCommand("git", ["-C", repo, ...args], {
    cwd: repo,
    env: options.env ?? process.env,
    timeoutMs: timeout,
    maxOutputBytes: options.maxBuffer ?? PROCESS_POLICY.maxOutputBytesPerProcess,
    terminateGraceMs: options.terminateGraceMs,
    killGraceAfterMs: options.killGraceAfterMs,
    finalDrainMs: options.finalDrainMs,
    signal: options.signal,
  });
  return options.encoding === "utf8" ? result.stdout.toString("utf8") : result.stdout;
}

async function assertSourceRepository(repo, base, head, options = {}) {
  const oidPattern = /^(?:[0-9a-f]{40}|[0-9a-f]{64})$/;
  if (!oidPattern.test(base) || !oidPattern.test(head)) fail("base/head должны быть полными Git SHA", "GIT_IDENTITY");
  const resolvedBase = (await git(repo, ["rev-parse", "--verify", `${base}^{commit}`], { ...options, encoding: "utf8" })).trim();
  const resolvedHead = (await git(repo, ["rev-parse", "--verify", `${head}^{commit}`], { ...options, encoding: "utf8" })).trim();
  if (resolvedBase !== base || resolvedHead !== head) fail("base/head не совпадают с разрешёнными commit", "GIT_IDENTITY");
  const status = await git(repo, ["status", "--porcelain=v1", "--untracked-files=all"], { ...options, encoding: "utf8" });
  if (status.length !== 0) fail("source worktree должен быть чистым перед snapshot", "DIRTY_SOURCE");
}

export function parseGitTree(bytes) {
  const entries = [];
  for (const raw of bytes.toString("utf8").split("\0")) {
    if (raw === "") continue;
    const match = raw.match(/^([0-7]{6}) ([a-z]+) ((?:[0-9a-f]{40}|[0-9a-f]{64}))\t([\s\S]+)$/);
    if (!match) fail("git ls-tree вернул неподдерживаемую запись", "TREE_FORMAT");
    entries.push({ mode: match[1], type: match[2], object: match[3], path: match[4] });
  }
  return entries;
}

async function repositoryObjectFormat(repo, options = {}) {
  const format = (await git(repo, ["rev-parse", "--show-object-format"], { ...options, encoding: "utf8" })).trim();
  if (!["sha1", "sha256"].includes(format)) fail(`неподдерживаемый Git object-format ${format}`, "SNAPSHOT_HASH");
  return format;
}

function hashObject(objectFormat, bytes) {
  return createHash(objectFormat).update(Buffer.from(`blob ${bytes.length}\0`)).update(bytes).digest("hex");
}

function snapshotEntry(root, entry, objectFormat) {
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
    return { mode: "120000", object: hashObject(objectFormat, Buffer.from(readlinkSync(path))) };
  }
  if (!stat.isFile()) fail(`${entry.path}: поддерживаются только blob-файлы и symlink`, "SNAPSHOT_TYPE");
  const mode = (stat.mode & 0o111) === 0 ? "100644" : "100755";
  return { mode, object: hashObject(objectFormat, readFileSync(path)) };
}

function lstatSafe(path) {
  try {
    return lstatSync(path);
  } catch {
    return null;
  }
}

function verifySnapshotBytes(commit, destination, bytes, objectFormat) {
  const entries = parseGitTree(bytes);
  const actualPaths = walk(destination);
  if (actualPaths.length !== entries.length) fail(`snapshot ${commit}: число файлов ${actualPaths.length}, ожидалось ${entries.length}`, "SNAPSHOT_COUNT");
  for (const entry of entries) {
    if (entry.type !== "blob") fail(`${entry.path}: gitlink/tree внутри tracked snapshot не поддержан`, "SNAPSHOT_TYPE");
    const actual = snapshotEntry(destination, entry, objectFormat);
    if (actual.mode !== entry.mode || actual.object !== entry.object) fail(`${entry.path}: mode/blob не совпадает с ${commit}`, "SNAPSHOT_MISMATCH");
  }
  return { entries, bytes, objectFormat };
}

export async function verifySnapshot(repo, commit, destination, treeBytes = null, options = {}) {
  const bytes = treeBytes ?? await git(repo, ["ls-tree", "-r", "-z", "--full-tree", commit], options);
  return verifySnapshotBytes(commit, destination, bytes, await repositoryObjectFormat(repo, options));
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

export async function materializeTree(repo, commit, destination, captureDirectory, options = {}) {
  mkdirSync(destination, { recursive: false });
  mkdirSync(captureDirectory, { recursive: true });
  const indexPath = join(captureDirectory, `index-${basename(destination)}`);
  const env = { ...(options.env ?? process.env), GIT_INDEX_FILE: indexPath };
  await git(repo, ["read-tree", commit], { ...options, env });
  await git(repo, ["checkout-index", "--all", `--prefix=${destination}${sep}`], { ...options, env });
  const treeBytes = await git(repo, ["ls-tree", "-r", "-z", "--full-tree", commit], options);
  const verified = await verifySnapshot(repo, commit, destination, treeBytes, options);
  return { treeBytes, count: verified.entries.length, objectFormat: verified.objectFormat };
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

const FORBIDDEN_INTERPRETERS = new Set([
  "sh", "bash", "zsh", "dash", "csh", "tcsh", "fish",
  "cmd", "cmd.exe", "powershell", "powershell.exe", "pwsh", "pwsh.exe",
]);
const COMMAND_MODE_FLAGS = new Set(["-c", "--command", "/c", "-command", "-encodedcommand"]);

export function resolveExecutable(command, environment = process.env) {
  nonemptyString(command, "command", "PROCESS_POLICY");
  const candidates = command.includes(sep)
    ? [resolve(command)]
    : String(environment.PATH ?? "").split(":").filter(Boolean).map((directory) => resolve(directory, command));
  for (const candidate of candidates) {
    try {
      const physical = realpathSync(candidate);
      const stat = lstatSync(physical);
      if (!stat.isFile() || stat.isSymbolicLink()) continue;
      accessSync(physical, fsConstants.X_OK);
      return physical;
    } catch {
      // Проверяется следующий PATH-кандидат.
    }
  }
  fail(`исполняемый файл ${command} не найден`, "PROCESS_EXECUTABLE");
}

export function validateSafeInvocation(command, args, options = {}) {
  if (options.shell !== false) fail("внешняя команда обязана использовать shell:false", "PROCESS_SHELL");
  assertStringArray(args, "argv", { allowEmptyItems: true }, "PROCESS_ARGV");
  const executable = options.resolvedExecutable ?? resolveExecutable(command, options.env ?? process.env);
  const base = basename(executable).toLowerCase();
  if (FORBIDDEN_INTERPRETERS.has(base)) fail(`интерпретатор ${base} запрещён`, "PROCESS_TRAMPOLINE");
  if (base === "env") {
    if (args.some((value) => value === "-S" || value.startsWith("--split-string"))) fail("env split-string trampoline запрещён", "PROCESS_TRAMPOLINE");
    let firstCommand = null;
    for (let index = 0; index < args.length; index += 1) {
      const value = args[index];
      if (["-u", "--unset", "-C", "--chdir"].includes(value)) {
        index += 1;
        continue;
      }
      if (value === "--") {
        firstCommand = args[index + 1] ?? null;
        break;
      }
      if (value.startsWith("-") || /^[A-Za-z_][A-Za-z0-9_]*=/.test(value)) continue;
      firstCommand = value;
      break;
    }
    if (firstCommand && FORBIDDEN_INTERPRETERS.has(basename(firstCommand).toLowerCase())) {
      fail(`env trampoline к ${firstCommand} запрещён`, "PROCESS_TRAMPOLINE");
    }
  }
  if (FORBIDDEN_INTERPRETERS.has(base) && args.some((value) => COMMAND_MODE_FLAGS.has(value.toLowerCase()))) {
    fail("режим интерпретации командной строки запрещён", "PROCESS_TRAMPOLINE");
  }
  return executable;
}

function parseProcStat(pid, text) {
  const close = text.lastIndexOf(")");
  if (close < 0) fail(`PID ${pid}: /proc stat невалиден`, "PROCESS_IDENTITY");
  const parsedPid = Number.parseInt(text.slice(0, text.indexOf(" ")), 10);
  const fields = text.slice(close + 2).trim().split(/\s+/);
  const pgid = Number.parseInt(fields[2], 10);
  const startToken = fields[19];
  const status = fields[0];
  if (parsedPid !== pid || !Number.isInteger(pgid) || pgid <= 0 || !/^\d+$/.test(startToken ?? "") || !status) {
    fail(`PID ${pid}: /proc identity невалидна`, "PROCESS_IDENTITY");
  }
  return { pid, pgid, processStartToken: `proc:${startToken}`, status };
}

function parsePsIdentity(pid, text) {
  const line = text.trim();
  const match = line.match(/^(\d+)\s+(\d+)\s+(\S+\s+\S+\s+\d+\s+\d{2}:\d{2}:\d{2}\s+\d{4})\s+(\S+)$/);
  if (!match || Number(match[1]) !== pid) fail(`PID ${pid}: ps identity невалидна`, "PROCESS_IDENTITY");
  const pgid = Number.parseInt(match[2], 10);
  if (!Number.isInteger(pgid) || pgid <= 0) fail(`PID ${pid}: PGID невалидна`, "PROCESS_IDENTITY");
  return { pid, pgid, processStartToken: `ps:${match[3]}`, status: match[4] };
}

function probeWithPs(pid, timeoutMs = 2_000) {
  return new Promise((resolvePromise, rejectPromise) => {
    const psPath = existsSync("/bin/ps") ? "/bin/ps" : "/usr/bin/ps";
    const child = spawn(psPath, ["-o", "pid=,pgid=,lstart=,stat=", "-p", String(pid)], {
      cwd: "/",
      env: process.env,
      detached: true,
      stdio: ["ignore", "pipe", "pipe"],
      shell: false,
    });
    const stdout = [];
    const stderr = [];
    let bytes = 0;
    let settled = false;
    const settle = (error, value = null) => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      if (error) rejectPromise(error);
      else resolvePromise(value);
    };
    const timer = setTimeout(() => {
      try {
        process.kill(-child.pid, "SIGKILL");
      } catch {
        // Probe уже завершился.
      }
      const error = new Error(`PID ${pid}: ps identity timeout`);
      error.code = "PROCESS_IDENTITY";
      settle(error);
    }, timeoutMs);
    const capture = (target, chunk) => {
      bytes += chunk.length;
      if (bytes > 65_536) {
        try {
          process.kill(-child.pid, "SIGKILL");
        } catch {
          // Probe уже завершился.
        }
        const error = new Error(`PID ${pid}: ps identity превысила лимит вывода`);
        error.code = "PROCESS_IDENTITY";
        settle(error);
        return;
      }
      target.push(chunk);
    };
    child.stdout.on("data", (chunk) => capture(stdout, chunk));
    child.stderr.on("data", (chunk) => capture(stderr, chunk));
    child.once("error", (error) => settle(error));
    child.once("close", (code) => {
      if (settled) return;
      if (code !== 0) {
        const error = new Error(`PID ${pid}: ps завершился с кодом ${code}: ${Buffer.concat(stderr).toString("utf8").trim()}`);
        error.code = "PROCESS_IDENTITY";
        settle(error);
        return;
      }
      try {
        settle(null, parsePsIdentity(pid, Buffer.concat(stdout).toString("utf8")));
      } catch (error) {
        settle(error);
      }
    });
  });
}

function probeGroupWithPs(pgid, timeoutMs = 2_000) {
  return new Promise((resolvePromise, rejectPromise) => {
    const psPath = existsSync("/bin/ps") ? "/bin/ps" : "/usr/bin/ps";
    const child = spawn(psPath, ["-axo", "pid=,pgid=,lstart=,stat="], {
      cwd: "/",
      env: process.env,
      detached: true,
      stdio: ["ignore", "pipe", "pipe"],
      shell: false,
    });
    const stdout = [];
    const stderr = [];
    let bytes = 0;
    let settled = false;
    const settle = (error, value = null) => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      if (error) rejectPromise(error);
      else resolvePromise(value);
    };
    const timer = setTimeout(() => {
      try {
        process.kill(-child.pid, "SIGKILL");
      } catch {
        // Probe уже завершился.
      }
      const error = new Error(`PGID ${pgid}: ps group identity timeout`);
      error.code = "PROCESS_IDENTITY";
      settle(error);
    }, timeoutMs);
    const capture = (target, chunk) => {
      bytes += chunk.length;
      if (bytes > 8 * 1024 * 1024) {
        try {
          process.kill(-child.pid, "SIGKILL");
        } catch {
          // Probe уже завершился.
        }
        const error = new Error(`PGID ${pgid}: ps group identity превысила лимит вывода`);
        error.code = "PROCESS_IDENTITY";
        settle(error);
        return;
      }
      target.push(chunk);
    };
    child.stdout.on("data", (chunk) => capture(stdout, chunk));
    child.stderr.on("data", (chunk) => capture(stderr, chunk));
    child.once("error", (error) => settle(error));
    child.once("close", (code) => {
      if (settled) return;
      if (code !== 0) {
        const error = new Error(`PGID ${pgid}: ps завершился с кодом ${code}: ${Buffer.concat(stderr).toString("utf8").trim()}`);
        error.code = "PROCESS_IDENTITY";
        settle(error);
        return;
      }
      try {
        const identities = Buffer.concat(stdout).toString("utf8").split("\n").filter((line) => line.trim() !== "").map((line) => {
          const pidMatch = line.trim().match(/^(\d+)/);
          if (!pidMatch) fail("ps group identity содержит строку без PID", "PROCESS_IDENTITY");
          return parsePsIdentity(Number(pidMatch[1]), line);
        }).filter((identity) => identity.pgid === pgid);
        settle(null, identities);
      } catch (error) {
        settle(error);
      }
    });
  });
}

export async function readSystemProcessIdentity(pid) {
  if (!Number.isInteger(pid) || pid <= 0) fail("PID должен быть положительным целым", "PROCESS_IDENTITY");
  const procPath = `/proc/${pid}/stat`;
  if (existsSync(procPath)) {
    try {
      return parseProcStat(pid, readFileSync(procPath, "utf8"));
    } catch (error) {
      if (error?.code === "PROCESS_IDENTITY") throw error;
      fail(`PID ${pid}: не удалось прочитать /proc identity`, "PROCESS_IDENTITY");
    }
  }
  return probeWithPs(pid);
}

export async function readSystemProcessGroupIdentities(pgid) {
  if (!Number.isInteger(pgid) || pgid <= 0) fail("PGID должен быть положительным целым", "PROCESS_IDENTITY");
  if (existsSync("/proc")) {
    const identities = [];
    for (const entry of readdirSync("/proc")) {
      if (!/^\d+$/.test(entry)) continue;
      try {
        const identity = parseProcStat(Number(entry), readFileSync(`/proc/${entry}/stat`, "utf8"));
        if (identity.pgid === pgid) identities.push(identity);
      } catch {
        // Процесс мог завершиться во время обхода /proc.
      }
    }
    return identities;
  }
  return probeGroupWithPs(pgid);
}

export function assertSignalTargetIdentity(expected, current, controllerPgid) {
  for (const value of [expected, current]) {
    if (!value || !Number.isInteger(value.pid) || value.pid <= 0 || !Number.isInteger(value.pgid) || value.pgid <= 0
      || typeof value.processStartToken !== "string" || value.processStartToken === "") {
      fail("неполная process identity перед сигналом", "PROCESS_IDENTITY");
    }
  }
  if (!Number.isInteger(controllerPgid) || controllerPgid <= 0 || expected.pgid === controllerPgid || current.pgid === controllerPgid) {
    fail("сигнал в PGID контроллера запрещён", "PROCESS_CONTROLLER_PGID");
  }
  if (expected.pid !== current.pid || expected.pgid !== current.pgid || expected.processStartToken !== current.processStartToken) {
    fail("PID/PGID/start token изменились перед сигналом", "PROCESS_IDENTITY");
  }
  return { zombie: /^Z/.test(current.status ?? "") };
}

export function assertGroupSignalIdentity(expectedRoot, knownMembers, currentMembers, controllerPgid) {
  if (!Array.isArray(knownMembers) || knownMembers.length === 0 || !Array.isArray(currentMembers) || currentMembers.length === 0) {
    fail("недостаточно process identity для группового сигнала", "PROCESS_IDENTITY");
  }
  if (expectedRoot.pgid === controllerPgid || currentMembers.some((member) => member.pgid === controllerPgid)) {
    fail("сигнал в PGID контроллера запрещён", "PROCESS_CONTROLLER_PGID");
  }
  const knownByPid = new Map(knownMembers.map((member) => [member.pid, member]));
  const verified = currentMembers.filter((member) => {
    const known = knownByPid.get(member.pid);
    return known && known.pgid === member.pgid && known.processStartToken === member.processStartToken;
  });
  if (currentMembers.every((member) => member.pgid !== expectedRoot.pgid) || verified.length === 0) {
    fail("не найден выживший член исходной PGID с тем же start token", "PROCESS_IDENTITY");
  }
  return { verified, unknown: currentMembers.filter((member) => !knownByPid.has(member.pid)) };
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

function waitForGroupExit(pid, timeoutMs, pollMs = 25) {
  const deadline = Date.now() + Math.max(0, timeoutMs);
  return new Promise((resolvePromise) => {
    const poll = () => {
      if (!processGroupExists(pid)) {
        resolvePromise(true);
        return;
      }
      if (Date.now() >= deadline) {
        resolvePromise(false);
        return;
      }
      setTimeout(poll, Math.min(pollMs, Math.max(1, deadline - Date.now())));
    };
    poll();
  });
}

export function startProcessGroup(command, args, options = {}) {
  const executable = validateSafeInvocation(command, args, {
    shell: false,
    env: options.env,
    resolvedExecutable: options.resolvedExecutable,
  });
  const child = spawn(process.execPath, [SELF_PATH, "--process-supervisor", executable, "--", ...args], {
    cwd: options.cwd,
    env: options.env,
    detached: true,
    stdio: ["pipe", "pipe", "pipe", "pipe"],
    shell: false,
  });
  const stdout = [];
  const stderr = [];
  let timedOut = false;
  let cancelled = false;
  let terminationStarted = false;
  let outputExceeded = false;
  let outputBytes = 0;
  let terminationReason = null;
  let identityMismatch = false;
  let processIdentity = null;
  let controllerPgid = null;
  let processFingerprint = null;
  let knownProcessIdentities = [];
  let identityMonitor = null;
  let identityMonitorBusy = false;
  let terminationPromise = null;
  let controlBuffer = "";
  let readyHandled = false;
  let startHandshakePromise = null;
  let outcomeHandled = false;
  let startedSettled = false;
  let resolveStarted;
  const started = new Promise((resolvePromise) => {
    resolveStarted = (value) => {
      if (startedSettled) return;
      startedSettled = true;
      resolvePromise(value);
    };
  });
  const mergeKnownMembers = (members) => {
    const byPid = new Map(knownProcessIdentities.map((member) => [member.pid, member]));
    for (const member of members) if (!byPid.has(member.pid)) byPid.set(member.pid, member);
    knownProcessIdentities = [...byPid.values()].sort((left, right) => left.pid - right.pid);
  };
  const signalVerifiedGroup = async (signal) => {
    if (processIdentity === null || controllerPgid === null) fail("process identity не зафиксирована", "PROCESS_IDENTITY");
    const currentMembers = await readSystemProcessGroupIdentities(processIdentity.pgid);
    const currentRoot = currentMembers.find((member) => member.pid === processIdentity.pid);
    if (currentRoot) {
      const verdict = assertSignalTargetIdentity(processIdentity, currentRoot, controllerPgid);
      mergeKnownMembers(currentMembers);
      if (!verdict.zombie) process.kill(-processIdentity.pgid, signal);
      return;
    }
    assertGroupSignalIdentity(processIdentity, knownProcessIdentities, currentMembers, controllerPgid);
    process.kill(-processIdentity.pgid, signal);
  };
  const terminateAndDrain = async () => {
    const start = await started;
    if (!start.ok) return false;
    try {
      await signalVerifiedGroup("SIGTERM");
      const termGone = await waitForGroupExit(processIdentity.pgid, options.terminateGraceMs ?? options.killGraceMs ?? PROCESS_POLICY.terminateGraceSeconds * 1_000);
      if (!termGone) {
        await signalVerifiedGroup("SIGKILL");
        await waitForGroupExit(processIdentity.pgid, options.killGraceAfterMs ?? PROCESS_POLICY.killGraceSeconds * 1_000);
      }
      return true;
    } catch {
      identityMismatch = true;
      return false;
    }
  };
  const finishGroup = (reason) => {
    if (reason === "timeout") timedOut = true;
    if (reason === "cancel") cancelled = true;
    if (terminationStarted) return;
    terminationStarted = true;
    terminationReason = reason;
    terminationPromise = terminateAndDrain();
  };
  const timeoutMs = options.timeoutMs ?? HARD_TIMEOUT_MS;
  const timeout = setTimeout(() => finishGroup("timeout"), timeoutMs);
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
  child.stdio[3].on("data", (chunk) => {
    controlBuffer += chunk.toString("utf8");
    const lines = controlBuffer.split("\n");
    controlBuffer = lines.pop() ?? "";
    for (const line of lines) {
      if (line === "READY" && !readyHandled) {
        readyHandled = true;
        void Promise.all([readSystemProcessIdentity(child.pid), readSystemProcessIdentity(process.pid)])
          .then(([captured, controller]) => {
            if (captured.pgid !== child.pid) fail("supervisor не получил отдельную PGID", "PROCESS_IDENTITY");
            assertSignalTargetIdentity(captured, captured, controller.pgid);
            processIdentity = captured;
            controllerPgid = controller.pgid;
            processFingerprint = sha256Bytes(Buffer.from(canonicalJson({
              executable,
              argv: args,
              root: processIdentity,
              controllerPgid,
              timeoutMs,
              maxOutputBytes: options.maxOutputBytes ?? 16 * 1024 * 1024,
            })));
            child.stdin.write("GO\n");
          })
          .catch((error) => {
            resolveStarted({ ok: false, error: error.message, code: error?.code ?? "PROCESS_IDENTITY" });
            child.kill("SIGKILL");
            finishGroup("identity");
          });
      } else if (line.startsWith("STARTED ")) {
        if (processIdentity === null || controllerPgid === null) {
          resolveStarted({ ok: false, error: "STARTED получен до process identity", code: "PROCESS_IDENTITY" });
          finishGroup("identity");
        } else {
          const targetPid = Number.parseInt(line.slice("STARTED ".length), 10);
          if (!Number.isInteger(targetPid) || targetPid <= 0 || startHandshakePromise !== null) {
            resolveStarted({ ok: false, error: "STARTED содержит невалидный или повторный target PID", code: "PROCESS_IDENTITY" });
            finishGroup("identity");
            continue;
          }
          startHandshakePromise = (async () => {
            const current = await readSystemProcessGroupIdentities(processIdentity.pgid);
            const root = current.find((member) => member.pid === processIdentity.pid);
            if (!root) fail("supervisor исчез до фиксации target", "PROCESS_IDENTITY");
            assertSignalTargetIdentity(processIdentity, root, controllerPgid);
            const target = current.find((member) => member.pid === targetPid);
            if (target && target.pgid !== processIdentity.pgid) fail("target получил чужую PGID", "PROCESS_IDENTITY");
            mergeKnownMembers(current);
            identityMonitor = setInterval(() => {
              if (identityMonitorBusy || processIdentity === null) return;
              identityMonitorBusy = true;
              void readSystemProcessGroupIdentities(processIdentity.pgid).then((members) => {
                const currentRoot = members.find((member) => member.pid === processIdentity.pid);
                if (currentRoot) {
                  assertSignalTargetIdentity(processIdentity, currentRoot, controllerPgid);
                  mergeKnownMembers(members);
                }
              }).catch(() => {
                // Root exit проверяется повторно в close/drain path.
              }).finally(() => {
                identityMonitorBusy = false;
              });
            }, options.identityMonitorMs ?? 250);
            resolveStarted({ ok: true, error: null, processIdentity, controllerPgid, targetPid });
          })().catch((error) => {
            identityMismatch = true;
            resolveStarted({ ok: false, error: error.message, code: error?.code ?? "PROCESS_IDENTITY" });
            finishGroup("identity");
          });
        }
      } else if (line.startsWith("START_ERROR ")) {
        let startError;
        try {
          startError = JSON.parse(line.slice("START_ERROR ".length));
        } catch {
          startError = null;
        }
        const message = startError && typeof startError.message === "string" && startError.message !== ""
          ? startError.message
          : "target не запущен";
        resolveStarted({ ok: false, error: message, code: "PROCESS_START" });
        child.stdin.end();
      } else if (line.startsWith("OUTCOME ")) {
        if (outcomeHandled) {
          identityMismatch = true;
          finishGroup("identity");
          continue;
        }
        outcomeHandled = true;
        void (async () => {
          if (startHandshakePromise === null || processIdentity === null || controllerPgid === null) {
            fail("OUTCOME получен до STARTED", "PROCESS_IDENTITY");
          }
          await startHandshakePromise;
          const targetOutcome = JSON.parse(line.slice("OUTCOME ".length));
          if (targetOutcome === null || typeof targetOutcome !== "object" || Array.isArray(targetOutcome)
            || !Object.hasOwn(targetOutcome, "code") || !Object.hasOwn(targetOutcome, "signal")) {
            fail("OUTCOME имеет невалидный формат", "PROCESS_IDENTITY");
          }
          const current = await readSystemProcessGroupIdentities(processIdentity.pgid);
          const root = current.find((member) => member.pid === processIdentity.pid);
          if (!root) fail("supervisor исчез до финального снимка группы", "PROCESS_IDENTITY");
          assertSignalTargetIdentity(processIdentity, root, controllerPgid);
          mergeKnownMembers(current);
          child.stdin.end("ACK\n");
        })().catch((error) => {
          identityMismatch = true;
          resolveStarted({ ok: false, error: error.message, code: error?.code ?? "PROCESS_IDENTITY" });
          finishGroup("identity");
        });
      }
    }
  });
  child.once("error", (error) => resolveStarted({ ok: false, error: error.message, code: "PROCESS_START" }));
  const closeOutcome = new Promise((resolvePromise) => {
    child.once("error", (error) => resolvePromise({ code: null, signal: null, error: error.message }));
    child.once("close", (code, signal) => resolvePromise({ code, signal, error: null }));
  });
  const done = (async () => {
    const forcedAfterMs = timeoutMs
      + (options.terminateGraceMs ?? options.killGraceMs ?? PROCESS_POLICY.terminateGraceSeconds * 1_000)
      + (options.killGraceAfterMs ?? PROCESS_POLICY.killGraceSeconds * 1_000)
      + (options.finalDrainMs ?? PROCESS_POLICY.finalDrainSeconds * 1_000)
      + 1_000;
    let forcedTimer;
    const forcedOutcome = new Promise((resolvePromise) => {
      forcedTimer = setTimeout(() => resolvePromise({ code: null, signal: null, error: "process lifecycle не завершился в пределах общего drain" }), forcedAfterMs);
    });
    const outcome = await Promise.race([closeOutcome, forcedOutcome]);
    clearTimeout(forcedTimer);
    clearTimeout(timeout);
    if (identityMonitor !== null) clearInterval(identityMonitor);
    if (!startedSettled) resolveStarted({ ok: false, error: outcome.error ?? "process завершился до STARTED", code: "PROCESS_START" });
    const groupId = processIdentity?.pgid ?? child.pid;
    const residualProcessDetected = processGroupExists(groupId);
    if (residualProcessDetected && !terminationStarted) finishGroup("residual");
    if (terminationPromise) await terminationPromise;
    const drainComplete = await waitForGroupExit(groupId, options.finalDrainMs ?? PROCESS_POLICY.finalDrainSeconds * 1_000);
    if (!drainComplete && identityMismatch) {
      child.unref();
      child.stdin.destroy();
      child.stdout.destroy();
      child.stderr.destroy();
      child.stdio[3].destroy();
    }
    if (options.signal) options.signal.removeEventListener("abort", abort);
    return {
      ...outcome,
      timedOut,
      cancelled,
      outputExceeded,
      residualProcessDetected,
      drainComplete,
      terminationReason,
      identityMismatch,
      processIdentity,
      controllerPgid,
      processFingerprint,
      knownProcessIdentities,
      stdout: Buffer.concat(stdout),
      stderr: Buffer.concat(stderr),
    };
  })();
  return { child, started, done, terminate: finishGroup };
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
  let monitorRunning = false;
  const monitor = setInterval(async () => {
    if (monitorRunning) return;
    monitorRunning = true;
    if (await descendantsContainMcp(new Set([processGroup.child.pid]))) {
      mcpDetected = true;
      processGroup.terminate("policy");
    }
    monitorRunning = false;
  }, options.monitorIntervalMs ?? 250);
  const result = await processGroup.done;
  clearInterval(monitor);
  if (result.cancelled) fail(`${basename(command)} preflight отменён пользователем`, "CLIENT_CANCELLED");
  if (result.code !== 0 || result.error || result.timedOut || result.outputExceeded || result.identityMismatch || result.residualProcessDetected || !result.drainComplete || mcpDetected) {
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

async function clientPreflight({ commands, reviewRoot, environments, selfTest, signal, overallDeadlineAt, preflightTimeoutMs = null }) {
  const phaseDeadlineAt = Math.min(
    overallDeadlineAt ?? Number.POSITIVE_INFINITY,
    Date.now() + (preflightTimeoutMs ?? (selfTest ? 5_000 : PROCESS_POLICY.preflightSeconds * 1_000)),
  );
  const budget = () => Math.max(1, remainingBudget(phaseDeadlineAt, "preflight"));
  const preflightOptions = { cwd: reviewRoot, killGraceMs: selfTest ? 100 : 10_000, monitorIntervalMs: selfTest ? 50 : 250, signal };
  const claudeOptions = { ...preflightOptions, env: environments.claude, forbiddenValues: [environments.claude.CLAUDE_CODE_OAUTH_TOKEN] };
  const claudeVersion = await runShort(commands.claude, ["--version"], { ...claudeOptions, timeoutMs: budget() });
  const auth = await runShort(commands.claude, ["auth", "status"], { ...claudeOptions, timeoutMs: budget() });
  if (!/oauth_token/i.test(auth)) fail("Claude auth status не подтверждает oauth_token", "CLAUDE_AUTH_MODE");
  const geminiVersion = await runShort(commands.gemini, ["--version"], { ...preflightOptions, env: environments.gemini, timeoutMs: budget() });
  const models = await runShort(commands.gemini, ["models"], { ...preflightOptions, env: environments.gemini, timeoutMs: budget() });
  if (!/gemini-3\.1-pro-high/i.test(models)) fail("модель gemini-3.1-pro-high недоступна", "GEMINI_MODEL");
  const marker = "WORKFLOW_SPEC_REVIEW_READ_SMOKE_OK";
  const smokePrompt = `Прочитай input/prompt.md и верни только ${marker}`;
  const claudeSmoke = await runShort(commands.claude, buildClaudeArgs(reviewRoot, smokePrompt), { ...claudeOptions, timeoutMs: budget() });
  const geminiSmokeArgs = buildGeminiArgs(reviewRoot, smokePrompt, selfTest ? "2s" : "2m");
  validateGeminiInvocation(geminiSmokeArgs, selfTest ? 3_000 : 3 * 60_000);
  const geminiSmoke = await runShort(commands.gemini, geminiSmokeArgs, { ...preflightOptions, env: environments.gemini, timeoutMs: Math.min(budget(), selfTest ? 3_000 : 3 * 60_000) });
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

export function parseReviewerResponse(bytes, identity) {
  if (!Buffer.isBuffer(bytes) || bytes.length === 0 || bytes.includes(0)) fail("ответ reviewer-а пуст или содержит NUL", "RESPONSE_FORMAT");
  let text;
  try {
    text = new TextDecoder("utf-8", { fatal: true }).decode(bytes);
  } catch {
    fail("ответ reviewer-а не является строгим UTF-8", "RESPONSE_FORMAT");
  }
  if (containsHookEvidence(text)) fail("ответ reviewer-а загрязнён hook", "RESPONSE_FORMAT");
  if (uniqueIdentityCount(text, identity) !== 1) fail("identity должна встречаться ровно один раз", "RESPONSE_IDENTITY");
  const lines = text.split(/\r?\n/);
  const firstNonempty = lines.findIndex((line) => line !== "");
  if (firstNonempty < 0 || lines[firstNonempty] !== identity || lines.slice(0, firstNonempty).some((line) => line !== "")) {
    fail("identity должна быть первой непустой строкой", "RESPONSE_IDENTITY");
  }
  const body = lines.slice(firstNonempty + 1);
  while (body[0] === "") body.shift();
  while (body.at(-1) === "") body.pop();
  const positions = RESPONSE_MARKERS.map((marker) => {
    const indexes = body.flatMap((line, index) => line === marker ? [index] : []);
    if (indexes.length !== 1) fail(`маркер ${marker} должен встречаться ровно один раз`, "RESPONSE_MARKER");
    return indexes[0];
  });
  if (positions[0] !== 0 || positions.some((position, index) => index > 0 && position <= positions[index - 1])) {
    fail("маркеры расположены не в каноническом порядке", "RESPONSE_MARKER");
  }
  const section = (index) => body.slice(positions[index] + 1, positions[index + 1]).join("\n").trim();
  let findings;
  let checked;
  let unchecked;
  try {
    findings = JSON.parse(section(0));
    checked = JSON.parse(section(1));
    unchecked = JSON.parse(section(2));
  } catch {
    fail("секции findings и scope должны быть JSON", "RESPONSE_JSON");
  }
  if (!Array.isArray(findings)) fail("REVIEW_FINDINGS должен быть JSON-массивом", "RESPONSE_FINDING");
  const ids = new Set();
  for (const [index, finding] of findings.entries()) {
    exactKeys(finding, ["id", "priority", "summary", "evidence", "minimalFix"], `finding[${index}]`, "RESPONSE_FINDING");
    nonemptyString(finding.id, `finding[${index}].id`, "RESPONSE_FINDING");
    if (!FINDING_ID_PATTERN.test(finding.id) || ids.has(finding.id)) fail("finding id невалиден или повторяется", "RESPONSE_FINDING");
    ids.add(finding.id);
    if (!/^(?:P0|P1|P2|P3)$/.test(finding.priority)) fail("priority должен быть P0-P3", "RESPONSE_FINDING");
    nonemptyString(finding.summary, `finding[${index}].summary`, "RESPONSE_FINDING");
    nonemptyString(finding.minimalFix, `finding[${index}].minimalFix`, "RESPONSE_FINDING");
    assertStringArray(finding.evidence, `finding[${index}].evidence`, { nonempty: true }, "RESPONSE_FINDING");
  }
  assertStringArray(checked, "REVIEW_CHECKED_SCOPE", { nonempty: true }, "RESPONSE_SCOPE");
  assertStringArray(unchecked, "REVIEW_UNCHECKED_SCOPE", { nonempty: true }, "RESPONSE_SCOPE");
  const verdictLines = body.slice(positions[3] + 1).filter((line) => line !== "");
  if (verdictLines.length !== 1 || !RESPONSE_VERDICTS.has(verdictLines[0])) fail("невалидный verdict", "RESPONSE_VERDICT");
  const expectedVerdict = findings.some((finding) => ["P0", "P1", "P2"].includes(finding.priority)) ? "нужны правки" : "блокеров нет";
  if (verdictLines[0] !== expectedVerdict) fail("verdict не соответствует приоритетам", "RESPONSE_VERDICT");
  return { findings, checked, unchecked, verdict: verdictLines[0] };
}

export function validateReviewerResponse(bytes, identity) {
  try {
    parseReviewerResponse(bytes, identity);
    return true;
  } catch {
    return false;
  }
}

async function processTable() {
  try {
    const result = await runLifecycleCommand("ps", ["-axo", "pid=,ppid=,command="], {
      cwd: "/",
      env: process.env,
      timeoutMs: 2_000,
      maxOutputBytes: 8 * 1024 * 1024,
      terminateGraceMs: 100,
      killGraceAfterMs: 100,
      finalDrainMs: 200,
    });
    return result.stdout.toString("utf8");
  } catch {
    return null;
  }
}

async function descendantsContainMcp(rootPids) {
  const table = await processTable();
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

async function buildSnapshot({ repo, base, head, taskRoot, revision, runId, deadlineAt, overallDeadlineAt }) {
  const revisionRoot = ensureRevision(taskRoot, revision);
  validatePathSegment(runId, "run-id", /^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/);
  const runsRoot = join(taskRoot, "runs");
  mkdirSync(runsRoot, { recursive: true });
  ensureContainedDirectory(taskRoot, runsRoot, "runs");
  const runRoot = join(runsRoot, runId);
  const capture = join(runRoot, "capture");
  const reviewRoot = join(runRoot, "review-root");
  if (!existsSync(runRoot)) mkdirSync(runRoot, { recursive: false });
  else {
    ensureContainedDirectory(taskRoot, runRoot, "run");
    exactFileSet(runRoot, []);
  }
  mkdirSync(capture);
  mkdirSync(reviewRoot);
  mkdirSync(join(reviewRoot, "input"));
  mkdirSync(join(reviewRoot, "results"));
  copyFileSync(join(revisionRoot, "tz.md"), join(runRoot, "tz.md"));
  copyFileSync(join(revisionRoot, "author-review.md"), join(runRoot, "author-review.md"));
  copyFileSync(join(repo, PROMPT_PATH), join(runRoot, "review-prompt.md"));
  const commandBudget = { deadlineAt, overallDeadlineAt };
  const current = await materializeTree(repo, head, join(reviewRoot, "current"), capture, commandBudget);
  const baseTree = await materializeTree(repo, base, join(reviewRoot, "base"), capture, commandBudget);
  const patch = await git(repo, ["diff", "--binary", "--full-index", "--no-ext-diff", base, head, "--"], commandBudget);
  writeFileSync(join(reviewRoot, "input", "changes.patch"), patch, { flag: "wx" });
  copyFileSync(join(runRoot, "tz.md"), join(reviewRoot, "input", "tz.md"));
  copyFileSync(join(runRoot, "review-prompt.md"), join(reviewRoot, "input", "prompt.md"));
  return { runRoot, capture, reviewRoot, current, baseTree, patch, revisionRoot };
}

function processPolicyFor(commands, environments) {
  const clients = {};
  for (const name of ["claude", "gemini"]) {
    const resolvedExecutable = resolveExecutable(commands[name], environments[name]);
    clients[name] = {
      resolvedExecutable,
      executableSha256: fileHash(resolvedExecutable),
      allowedDescendantExecutables: [],
    };
  }
  return { ...PROCESS_POLICY, clients };
}

export function validateReviewManifest(manifest, context = {}) {
  exactKeys(manifest, ["schemaVersion", "base", "head", "counts", "algorithms", "hashes", "clients", "processPolicy", "identity"], "review-manifest", "REVIEW_MANIFEST");
  if (manifest.schemaVersion !== 1) fail("review-manifest.schemaVersion должен быть 1", "REVIEW_MANIFEST");
  assertOid(manifest.base, "review-manifest.base", "REVIEW_MANIFEST");
  assertOid(manifest.head, "review-manifest.head", "REVIEW_MANIFEST");
  exactKeys(manifest.counts, ["current", "base"], "review-manifest.counts", "REVIEW_MANIFEST");
  if (![manifest.counts.current, manifest.counts.base].every((value) => Number.isInteger(value) && value >= 0)) fail("review-manifest.counts невалиден", "REVIEW_MANIFEST");
  exactKeys(manifest.algorithms, ["tree", "files", "clients"], "review-manifest.algorithms", "REVIEW_MANIFEST");
  const algorithms = { tree: "sha256(git-ls-tree-r-z-full-tree)", files: "sha256(exact-bytes)", clients: "sha256(canonical-json)" };
  if (canonicalJson(manifest.algorithms) !== canonicalJson(algorithms)) fail("review-manifest.algorithms неканоничен", "REVIEW_MANIFEST");
  exactKeys(manifest.hashes, ["current", "base_tree", "patch", "spec", "author_review", "prompt", "clients"], "review-manifest.hashes", "REVIEW_MANIFEST");
  for (const [key, value] of Object.entries(manifest.hashes)) assertSha256(value, `review-manifest.hashes.${key}`, "REVIEW_MANIFEST");
  if (manifest.hashes.prompt !== CANONICAL_REVIEW_PROMPT_SHA256) fail("review-manifest ссылается на неканонический prompt", "REVIEW_MANIFEST");
  for (const [hashKey, bytes] of [
    ["current", context.currentTreeBytes],
    ["base_tree", context.baseTreeBytes],
    ["patch", context.patchBytes],
    ["spec", context.specBytes],
    ["author_review", context.authorReviewBytes],
    ["prompt", context.promptBytes],
  ]) {
    if (bytes !== undefined && manifest.hashes[hashKey] !== sha256Bytes(bytes)) fail(`review-manifest.hashes.${hashKey} не совпадает с точными байтами`, "REVIEW_MANIFEST");
  }

  exactKeys(manifest.clients, ["claude", "gemini"], "review-manifest.clients", "REVIEW_MANIFEST");
  const claude = manifest.clients.claude;
  const gemini = manifest.clients.gemini;
  exactKeys(claude, ["authMode", "binary", "model", "mcp", "settingsPath", "settingsConfigurationSha256", "tools", "transport", "version", "preflight"], "clients.claude", "REVIEW_MANIFEST");
  exactKeys(gemini, ["apiBilling", "binary", "model", "modelListSha256", "sandbox", "settingsPath", "settingsConfigurationSha256", "transport", "version", "preflight"], "clients.gemini", "REVIEW_MANIFEST");
  exactKeys(claude.preflight, ["auth", "readSmoke", "settingsStable"], "clients.claude.preflight", "REVIEW_MANIFEST");
  exactKeys(gemini.preflight, ["model", "readSmoke", "settingsStable"], "clients.gemini.preflight", "REVIEW_MANIFEST");
  if (claude.authMode !== "oauth_token" || claude.binary !== "claude" || claude.model !== "claude-opus-4-6" || claude.mcp !== "disabled"
    || claude.transport !== "official-cli" || canonicalJson(claude.tools) !== canonicalJson(["Read", "Glob", "Grep"])
    || claude.preflight.auth !== "oauth_token-confirmed" || claude.preflight.readSmoke !== true || claude.preflight.settingsStable !== true) {
    fail("clients.claude содержит неверный контракт", "REVIEW_MANIFEST");
  }
  if (gemini.apiBilling !== false || gemini.binary !== "agy" || gemini.model !== "gemini-3.1-pro-high" || gemini.sandbox !== true
    || gemini.transport !== "official-cli" || gemini.preflight.model !== "gemini-3.1-pro-high-confirmed"
    || gemini.preflight.readSmoke !== true || gemini.preflight.settingsStable !== true) {
    fail("clients.gemini содержит неверный контракт", "REVIEW_MANIFEST");
  }
  for (const [label, value] of [
    ["claude.settingsConfigurationSha256", claude.settingsConfigurationSha256],
    ["gemini.settingsConfigurationSha256", gemini.settingsConfigurationSha256],
    ["gemini.modelListSha256", gemini.modelListSha256],
  ]) assertSha256(value, label, "REVIEW_MANIFEST");
  for (const [label, value] of [["claude.settingsPath", claude.settingsPath], ["gemini.settingsPath", gemini.settingsPath]]) {
    if (typeof value !== "string" || !value.startsWith("/") || resolve(value) !== value) fail(`${label} должен быть нормализованным абсолютным путём`, "REVIEW_MANIFEST");
  }
  nonemptyString(claude.version, "claude.version", "REVIEW_MANIFEST");
  nonemptyString(gemini.version, "gemini.version", "REVIEW_MANIFEST");
  if (manifest.hashes.clients !== sha256Bytes(Buffer.from(canonicalJson(manifest.clients)))) fail("hash clients не совпадает", "REVIEW_MANIFEST");

  const policyKeys = ["launchMode", "mcpMode", "unknownLongLivedDescendant", "snapshotCommandSeconds", "preflightSeconds", "clientSeconds", "qualificationSeconds", "finalDrainSeconds", "overallSeconds", "terminateGraceSeconds", "killGraceSeconds", "maxOutputBytesPerProcess", "clients"];
  exactKeys(manifest.processPolicy, policyKeys, "review-manifest.processPolicy", "REVIEW_MANIFEST");
  if (manifest.processPolicy.launchMode !== PROCESS_POLICY.launchMode || manifest.processPolicy.mcpMode !== PROCESS_POLICY.mcpMode
    || manifest.processPolicy.unknownLongLivedDescendant !== PROCESS_POLICY.unknownLongLivedDescendant) fail("processPolicy mode невалиден", "REVIEW_MANIFEST");
  for (const key of ["snapshotCommandSeconds", "preflightSeconds", "clientSeconds", "qualificationSeconds", "finalDrainSeconds", "overallSeconds", "terminateGraceSeconds", "killGraceSeconds", "maxOutputBytesPerProcess"]) {
    if (!Number.isInteger(manifest.processPolicy[key]) || manifest.processPolicy[key] !== PROCESS_POLICY[key]) fail(`processPolicy.${key} изменён`, "REVIEW_MANIFEST");
  }
  const serialBudget = PROCESS_POLICY.snapshotCommandSeconds + PROCESS_POLICY.preflightSeconds + PROCESS_POLICY.clientSeconds
    + PROCESS_POLICY.qualificationSeconds + PROCESS_POLICY.finalDrainSeconds + PROCESS_POLICY.terminateGraceSeconds + PROCESS_POLICY.killGraceSeconds;
  if (serialBudget !== 2445 || serialBudget >= PROCESS_POLICY.overallSeconds) fail("processPolicy budget невалиден", "REVIEW_MANIFEST");
  exactKeys(manifest.processPolicy.clients, ["claude", "gemini"], "processPolicy.clients", "REVIEW_MANIFEST");
  for (const name of ["claude", "gemini"]) {
    const policy = manifest.processPolicy.clients[name];
    exactKeys(policy, ["resolvedExecutable", "executableSha256", "allowedDescendantExecutables"], `processPolicy.clients.${name}`, "REVIEW_MANIFEST");
    if (typeof policy.resolvedExecutable !== "string" || !policy.resolvedExecutable.startsWith("/") || resolve(policy.resolvedExecutable) !== policy.resolvedExecutable) fail("resolvedExecutable невалиден", "REVIEW_MANIFEST");
    assertSha256(policy.executableSha256, `processPolicy.clients.${name}.executableSha256`, "REVIEW_MANIFEST");
    assertStringArray(policy.allowedDescendantExecutables, `processPolicy.clients.${name}.allowedDescendantExecutables`, { unique: true }, "REVIEW_MANIFEST");
    if (policy.allowedDescendantExecutables.some((path) => !path.startsWith("/") || resolve(path) !== path)) fail("allowlist должен содержать абсолютные нормализованные пути", "REVIEW_MANIFEST");
    validateSafeInvocation(policy.resolvedExecutable, [], { shell: false, resolvedExecutable: policy.resolvedExecutable });
  }
  assertCanonicalIdentity(manifest, "review-manifest", "REVIEW_MANIFEST");
  return manifest;
}

export function buildReviewIdentity({ base, head, currentTreeBytes, baseTreeBytes, patchBytes, specBytes, authorReviewBytes, promptBytes, clients, counts = { current: 0, base: 0 }, processPolicy }) {
  const hashes = {
    current: sha256Bytes(currentTreeBytes),
    base_tree: sha256Bytes(baseTreeBytes),
    patch: sha256Bytes(patchBytes),
    spec: sha256Bytes(specBytes),
    author_review: sha256Bytes(authorReviewBytes),
    prompt: sha256Bytes(promptBytes),
    clients: sha256Bytes(Buffer.from(canonicalJson(clients))),
  };
  const manifest = {
    schemaVersion: 1,
    base,
    head,
    counts,
    algorithms: { tree: "sha256(git-ls-tree-r-z-full-tree)", files: "sha256(exact-bytes)", clients: "sha256(canonical-json)" },
    hashes,
    clients,
    processPolicy,
    identity: "",
  };
  manifest.identity = jcsIdentity(manifest);
  validateReviewManifest(manifest, { currentTreeBytes, baseTreeBytes, patchBytes, specBytes, authorReviewBytes, promptBytes });
  return { hashes, identity: manifest.identity, manifest };
}

function manifestFor({ base, head, snapshot, prompt, clients, processPolicy }) {
  return buildReviewIdentity({
    base,
    head,
    currentTreeBytes: snapshot.current.treeBytes,
    baseTreeBytes: snapshot.baseTree.treeBytes,
    patchBytes: snapshot.patch,
    specBytes: readFileSync(join(snapshot.runRoot, "tz.md")),
    authorReviewBytes: readFileSync(join(snapshot.runRoot, "author-review.md")),
    promptBytes: prompt.bytes,
    clients,
    counts: { current: snapshot.current.count, base: snapshot.baseTree.count },
    processPolicy,
  }).manifest;
}

function recordPreparationBlock({ taskRoot, runId, error }) {
  validatePathSegment(runId, "run-id", /^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/);
  const runsRoot = join(taskRoot, "runs");
  if (!existsSync(runsRoot)) mkdirSync(runsRoot, { recursive: false });
  ensureContainedDirectory(taskRoot, runsRoot, "runs");
  const runRoot = join(runsRoot, runId);
  if (!existsSync(runRoot)) mkdirSync(runRoot, { recursive: false });
  ensureContainedDirectory(taskRoot, runRoot, "run");
  const capture = join(runRoot, "capture");
  const reviewRoot = join(runRoot, "review-root");
  for (const directory of [capture, reviewRoot]) if (!existsSync(directory)) mkdirSync(directory, { recursive: false });
  ensureContainedDirectory(taskRoot, capture, "capture");
  ensureContainedDirectory(taskRoot, reviewRoot, "review-root");
  for (const name of ["input", "results"]) {
    const directory = join(reviewRoot, name);
    if (!existsSync(directory)) mkdirSync(directory, { recursive: false });
    ensureContainedDirectory(taskRoot, directory, `review-root/${name}`);
  }
  const diagnostic = { schemaVersion: 1, runId, phase: "snapshot", errorCode: error?.code ?? "PROCESS_FAILED" };
  const diagnosticPath = join(capture, "preparation-error.json");
  if (!existsSync(diagnosticPath)) writeFileSync(diagnosticPath, `${JSON.stringify(diagnostic, null, 2)}\n`, { flag: "wx" });
  const summary = {
    schemaVersion: 1,
    runId,
    identity: null,
    status: "blocked",
    target: "B01",
    returnState: "P03",
    stopMode: null,
    settingsUnchanged: true,
    mcpDetected: false,
    hookDetected: false,
    secretOutputDetected: false,
    responsesValid: false,
    clientsPassed: false,
    qualificationRequired: false,
    errorCode: diagnostic.errorCode,
  };
  const summaryPath = join(capture, "summary.json");
  if (!existsSync(summaryPath)) writeFileSync(summaryPath, `${JSON.stringify(summary, null, 2)}\n`, { flag: "wx" });
  return { ...summary, runRoot, reviewRoot };
}

export async function executeReviewRun(options) {
  const selfTest = options.selfTest === true;
  const selfTestOnlyOptions = ["commands", "environment", "settingsPaths", "nodeVersion", "hardTimeoutMs", "geminiPrintTimeout", "overallTimeoutMs", "snapshotTimeoutMs", "preflightTimeoutMs", "beforeSecondClientStart"];
  if (!selfTest && selfTestOnlyOptions.some((key) => Object.hasOwn(options, key))) {
    fail("подмена reviewer runtime разрешена только в offline self-test", "SELF_TEST_OVERRIDE");
  }
  assertNodeVersion(selfTest ? options.nodeVersion : undefined);
  const operationStartedAt = Date.now();
  const overallDeadlineAt = operationStartedAt + (selfTest && options.overallTimeoutMs ? options.overallTimeoutMs : PROCESS_POLICY.overallSeconds * 1_000);
  const snapshotDeadlineAt = Math.min(overallDeadlineAt, Date.now() + (selfTest && options.snapshotTimeoutMs ? options.snapshotTimeoutMs : PROCESS_POLICY.snapshotCommandSeconds * 1_000));
  const repo = realpathSync(resolve(options.repo));
  const taskRoot = ensureTaskLocalPath(options.taskRoot);
  if (pathsOverlap(repo, taskRoot)) fail("task-root и source repo не должны совпадать или быть вложены друг в друга", "PATH_SCOPE");
  const runId = options.runId ?? createRunId();
  let snapshot;
  try {
    await assertSourceRepository(repo, options.base, options.head, { deadlineAt: snapshotDeadlineAt, overallDeadlineAt });
    snapshot = await buildSnapshot({ repo, base: options.base, head: options.head, taskRoot, revision: options.revision, runId, deadlineAt: snapshotDeadlineAt, overallDeadlineAt });
  } catch (error) {
    if (["PROCESS_TIMEOUT", "PROCESS_OUTPUT_LIMIT", "PROCESS_FAILED", "PHASE_TIMEOUT"].includes(error?.code)) {
      return recordPreparationBlock({ taskRoot, runId, error });
    }
    throw error;
  }
  const prompt = readStrictPrompt(join(snapshot.reviewRoot, "input", "prompt.md"));
  assertCanonicalPrompt(prompt.bytes);
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
  let overallTimedOut = false;
  const overallTimer = setTimeout(() => {
    overallTimedOut = true;
    controller.abort();
  }, Math.max(1, remainingBudget(overallDeadlineAt, "общий запуск")));
  if (externalSignal?.aborted) controller.abort();
  else if (externalSignal) externalSignal.addEventListener("abort", abort, { once: true });
  try {
    verifySnapshotBytes(options.head, join(snapshot.reviewRoot, "current"), snapshot.current.treeBytes, snapshot.current.objectFormat);
    verifySnapshotBytes(options.base, join(snapshot.reviewRoot, "base"), snapshot.baseTree.treeBytes, snapshot.baseTree.objectFormat);
    scanSecrets(snapshot.reviewRoot);
    writeFileSync(join(snapshot.capture, "preflight-started.json"), `${JSON.stringify({
      schemaVersion: 1,
      runId,
      base: options.base,
      head: options.head,
      startedAt: new Date(operationStartedAt).toISOString(),
      overallDeadlineAt: new Date(overallDeadlineAt).toISOString(),
    }, null, 2)}\n`, { flag: "wx" });
    let preflight;
    try {
      preflight = await clientPreflight({ commands, reviewRoot: snapshot.reviewRoot, environments, selfTest, signal: controller.signal, overallDeadlineAt, preflightTimeoutMs: selfTest ? options.preflightTimeoutMs : null });
    } catch (error) {
      const cancelledByUser = !overallTimedOut && externalSignal?.aborted === true;
      const cancelledSummary = {
        schemaVersion: 1,
        runId,
        identity: null,
        status: cancelledByUser ? "cancelled_by_user" : "blocked",
        target: cancelledByUser ? "X03" : "B01",
        returnState: cancelledByUser ? null : "P03",
        stopMode: cancelledByUser ? "final_cancellation" : null,
        settingsUnchanged: settingsCheckpointUnchanged(settingsBefore.claude, settingsPaths.claude, "claude")
          && settingsCheckpointUnchanged(settingsBefore.gemini, settingsPaths.gemini, "gemini"),
        mcpDetected: false,
        hookDetected: false,
        secretOutputDetected: false,
        responsesValid: false,
        clientsPassed: false,
        qualificationRequired: false,
        errorCode: error?.code ?? "CLIENT_PREFLIGHT",
      };
      writeFileSync(join(snapshot.capture, "summary.json"), `${JSON.stringify(cancelledSummary, null, 2)}\n`, { flag: "wx" });
      return { ...cancelledSummary, runRoot: snapshot.runRoot, reviewRoot: snapshot.reviewRoot };
    }
    if (!settingsCheckpointUnchanged(settingsBefore.claude, settingsPaths.claude, "claude")
      || !settingsCheckpointUnchanged(settingsBefore.gemini, settingsPaths.gemini, "gemini")) fail("settings изменились во время preflight", "SETTINGS_CHANGED");
    const clients = clientMetadata(preflight, settingsPaths, settingsBefore);
    const processPolicy = processPolicyFor(commands, environments);
    const manifest = manifestFor({ base: options.base, head: options.head, snapshot, prompt, clients, processPolicy });
    writeFileSync(join(snapshot.runRoot, "review-manifest.json"), `${JSON.stringify(manifest, null, 2)}\n`, { flag: "wx" });
    copyFileSync(join(snapshot.runRoot, "review-manifest.json"), join(snapshot.reviewRoot, "input", "manifest.json"));
    verifySnapshotBytes(options.head, join(snapshot.reviewRoot, "current"), snapshot.current.treeBytes, snapshot.current.objectFormat);
    verifySnapshotBytes(options.base, join(snapshot.reviewRoot, "base"), snapshot.baseTree.treeBytes, snapshot.baseTree.objectFormat);
    scanSecrets(snapshot.reviewRoot);
    for (const path of [join(snapshot.reviewRoot, "current"), join(snapshot.reviewRoot, "base"), join(snapshot.reviewRoot, "input")]) makeReadOnly(path);
    if (readdirSync(join(snapshot.reviewRoot, "results")).length !== 0) fail("results должен быть пустым", "RESULTS_NOT_EMPTY");
    if (!settingsCheckpointUnchanged(settingsBefore.claude, settingsPaths.claude, "claude")
      || !settingsCheckpointUnchanged(settingsBefore.gemini, settingsPaths.gemini, "gemini")) fail("settings изменились перед review", "SETTINGS_CHANGED");
    writeFileSync(join(snapshot.capture, "started.json"), `${JSON.stringify({
      identity: manifest.identity,
      runId,
      startedAt: new Date(operationStartedAt).toISOString(),
      overallDeadlineAt: new Date(overallDeadlineAt).toISOString(),
    }, null, 2)}\n`, { flag: "wx" });

    const claudeArgs = buildClaudeArgs(snapshot.reviewRoot, prompt.text);
    const geminiTimeout = selfTest && options.geminiPrintTimeout ? options.geminiPrintTimeout : GEMINI_PRINT_TIMEOUT;
    const geminiArgs = buildGeminiArgs(snapshot.reviewRoot, prompt.text, geminiTimeout);
    const hardTimeoutMs = Math.max(1, Math.min(
      selfTest && options.hardTimeoutMs ? options.hardTimeoutMs : PROCESS_POLICY.clientSeconds * 1_000,
      remainingBudget(overallDeadlineAt, "общий запуск"),
    ));
    validateGeminiInvocation(geminiArgs, hardTimeoutMs);
    for (const name of ["claude", "gemini"]) {
      if (fileHash(processPolicy.clients[name].resolvedExecutable) !== processPolicy.clients[name].executableSha256) fail(`${name}: исполняемый файл изменился после manifest`, "PROCESS_EXECUTABLE_CHANGED");
    }
    let claude = null;
    let gemini = null;
    try {
      claude = startProcessGroup(commands.claude, claudeArgs, { cwd: snapshot.reviewRoot, env: environments.claude, resolvedExecutable: processPolicy.clients.claude.resolvedExecutable, timeoutMs: hardTimeoutMs, killGraceMs: selfTest ? 100 : PROCESS_POLICY.terminateGraceSeconds * 1_000, finalDrainMs: selfTest ? 200 : PROCESS_POLICY.finalDrainSeconds * 1_000, maxOutputBytes: PROCESS_POLICY.maxOutputBytesPerProcess, signal: controller.signal });
      if (selfTest && typeof options.beforeSecondClientStart === "function") options.beforeSecondClientStart();
      gemini = startProcessGroup(commands.gemini, geminiArgs, { cwd: snapshot.reviewRoot, env: environments.gemini, resolvedExecutable: processPolicy.clients.gemini.resolvedExecutable, timeoutMs: hardTimeoutMs, killGraceMs: selfTest ? 100 : PROCESS_POLICY.terminateGraceSeconds * 1_000, finalDrainMs: selfTest ? 200 : PROCESS_POLICY.finalDrainSeconds * 1_000, maxOutputBytes: PROCESS_POLICY.maxOutputBytesPerProcess, signal: controller.signal });
      const starts = await Promise.all([claude.started, gemini.started]);
      if (starts.some((result) => !result.ok)) fail("не оба reviewer-процесса успешно запущены", "PARTIAL_START");
    } catch (error) {
      claude?.terminate("partial_start");
      gemini?.terminate("partial_start");
      await Promise.all([claude?.done, gemini?.done].filter(Boolean));
      for (const path of ["claude.stdout", "claude.stderr", "gemini.stdout", "gemini.stderr"]) {
        if (!existsSync(join(snapshot.capture, path))) writeFileSync(join(snapshot.capture, path), "");
      }
      const summary = {
        schemaVersion: 1,
        runId,
        identity: manifest.identity,
        status: "blocked",
        target: "B01",
        returnState: "P03",
        stopMode: null,
        settingsUnchanged: settingsCheckpointUnchanged(settingsBefore.claude, settingsPaths.claude, "claude")
          && settingsCheckpointUnchanged(settingsBefore.gemini, settingsPaths.gemini, "gemini"),
        mcpDetected: false,
        hookDetected: false,
        secretOutputDetected: false,
        responsesValid: false,
        clientsPassed: false,
        qualificationRequired: false,
        errorCode: error?.code ?? "PARTIAL_START",
      };
      writeFileSync(join(snapshot.capture, "summary.json"), `${JSON.stringify(summary, null, 2)}\n`, { flag: "wx" });
      return { ...summary, runRoot: snapshot.runRoot, reviewRoot: snapshot.reviewRoot };
    }
    let mcpDetected = false;
    let monitorRunning = false;
    const monitor = setInterval(async () => {
      if (monitorRunning) return;
      monitorRunning = true;
      if (await descendantsContainMcp(new Set([claude.child.pid, gemini.child.pid]))) {
        mcpDetected = true;
        claude.terminate("policy");
        gemini.terminate("policy");
      }
      monitorRunning = false;
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
    const cancelled = !overallTimedOut && (claudeResult.cancelled || geminiResult.cancelled || externalSignal?.aborted === true);
    const clientsPassed = [claudeResult, geminiResult].every((result) => result.code === 0 && !result.timedOut && !result.cancelled
      && !result.outputExceeded && !result.identityMismatch && !result.residualProcessDetected && result.drainComplete && result.error === null);
    const hookDetected = containsHookEvidence(claudeResult.stdout, claudeResult.stderr, geminiResult.stdout, geminiResult.stderr);
    let parsedResponses = null;
    try {
      parsedResponses = {
        claude: parseReviewerResponse(claudeResult.stdout, manifest.identity),
        gemini: parseReviewerResponse(geminiResult.stdout, manifest.identity),
      };
    } catch {
      parsedResponses = null;
    }
    const responsesValid = !hookDetected && !secretOutputDetected && parsedResponses !== null;
    let status = "completed";
    let target = "P03";
    if (cancelled) {
      status = "cancelled_by_user";
      target = "X03";
    } else if (overallTimedOut || !clientsPassed || !responsesValid || mcpDetected || !settingsUnchanged) {
      status = "blocked";
      target = "B01";
    }
    if (status !== "completed" && readdirSync(join(snapshot.reviewRoot, "results")).length !== 0) {
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
      parsed: status === "completed" ? {
        claude: { verdict: parsedResponses.claude.verdict, findingIds: parsedResponses.claude.findings.map((finding) => finding.id) },
        gemini: { verdict: parsedResponses.gemini.verdict, findingIds: parsedResponses.gemini.findings.map((finding) => finding.id) },
      } : null,
    };
    writeFileSync(join(snapshot.capture, "summary.json"), `${JSON.stringify(summary, null, 2)}\n`, { flag: "wx" });
    return { ...summary, runRoot: snapshot.runRoot, reviewRoot: snapshot.reviewRoot };
  } finally {
    clearTimeout(overallTimer);
    if (externalSignal) externalSignal.removeEventListener("abort", abort);
  }
}

function fileHash(path) {
  return sha256Bytes(readFileSync(path));
}

const DISPOSITION_RULES = Object.freeze({
  confirmed_current_scope: Object.freeze({ priorities: ["P0", "P1", "P2"], target: "C07" }),
  confirmed_scope_or_risk: Object.freeze({ priorities: ["P0", "P1", "P2", "P3"], target: "D01" }),
  unresolved: Object.freeze({ priorities: ["P0", "P1", "P2", "P3"], target: "B01" }),
  rejected_with_evidence: Object.freeze({ priorities: ["P0", "P1", "P2", "P3"], target: null }),
  recorded_non_blocking: Object.freeze({ priorities: ["P3"], target: null }),
});

function qualificationOutcome(dispositions) {
  if (dispositions.some((item) => item.decision === "unresolved")) return { state: "B01", reason: "Осталось неразрешённое замечание" };
  if (dispositions.some((item) => item.decision === "confirmed_scope_or_risk")) return { state: "D01", reason: "Требуется решение по объёму или риску" };
  if (dispositions.some((item) => item.decision === "confirmed_current_scope")) return { state: "C07", reason: "Подтверждён блокер текущего объёма" };
  return { state: "C09", reason: "Все замечания квалифицированы, блокеров текущего объёма нет" };
}

export function validateQualification(qualification, context) {
  exactKeys(qualification, ["schemaVersion", "reviewManifestSha256", "identity", "reviews", "dispositions", "outcome"], "qualification", "QUALIFICATION");
  if (qualification.schemaVersion !== 1) fail("qualification.schemaVersion должен быть 1", "QUALIFICATION");
  assertSha256(qualification.reviewManifestSha256, "qualification.reviewManifestSha256", "QUALIFICATION");
  if (qualification.reviewManifestSha256 !== sha256Bytes(context.reviewManifestBytes)) fail("qualification ссылается на другой review-manifest", "QUALIFICATION");
  const manifest = validateReviewManifest(JSON.parse(context.reviewManifestBytes.toString("utf8")));
  const parsed = {
    claude: parseReviewerResponse(context.claudeBytes, manifest.identity),
    gemini: parseReviewerResponse(context.geminiBytes, manifest.identity),
  };
  exactKeys(qualification.reviews, ["claude", "gemini"], "qualification.reviews", "QUALIFICATION");
  for (const source of ["claude", "gemini"]) {
    const review = qualification.reviews[source];
    exactKeys(review, ["sha256", "verdict", "findingIds"], `qualification.reviews.${source}`, "QUALIFICATION");
    assertSha256(review.sha256, `qualification.reviews.${source}.sha256`, "QUALIFICATION");
    if (review.sha256 !== sha256Bytes(context[`${source}Bytes`])) fail(`${source}: hash ответа не совпадает`, "QUALIFICATION");
    if (review.verdict !== parsed[source].verdict) fail(`${source}: verdict не совпадает с ответом`, "QUALIFICATION");
    assertStringArray(review.findingIds, `qualification.reviews.${source}.findingIds`, { unique: true }, "QUALIFICATION");
    if (canonicalJson(review.findingIds) !== canonicalJson(parsed[source].findings.map((finding) => finding.id))) fail(`${source}: порядок findingIds не совпадает с ответом`, "QUALIFICATION");
  }
  if (!Array.isArray(qualification.dispositions)) fail("qualification.dispositions должен быть массивом", "QUALIFICATION");
  const expected = [
    ...parsed.claude.findings.map((finding) => ({ source: "claude", finding })),
    ...parsed.gemini.findings.map((finding) => ({ source: "gemini", finding })),
  ];
  if (qualification.dispositions.length !== expected.length) fail("каждое замечание должно иметь ровно одну disposition", "QUALIFICATION");
  const globalIds = new Set();
  for (const [index, disposition] of qualification.dispositions.entries()) {
    exactKeys(disposition, ["source", "findingId", "priority", "decision", "target", "evidenceRefs", "rationale"], `qualification.dispositions[${index}]`, "QUALIFICATION");
    const expectedItem = expected[index];
    if (!expectedItem || disposition.source !== expectedItem.source || disposition.findingId !== expectedItem.finding.id || disposition.priority !== expectedItem.finding.priority) {
      fail(`disposition ${index} не совпадает с каноническим порядком findings`, "QUALIFICATION");
    }
    const globalId = `${disposition.source}:${disposition.findingId}`;
    if (globalIds.has(globalId)) fail(`повторная disposition ${globalId}`, "QUALIFICATION");
    globalIds.add(globalId);
    const rule = DISPOSITION_RULES[disposition.decision];
    if (!rule || !rule.priorities.includes(disposition.priority) || disposition.target !== rule.target) fail(`${globalId}: неверное решение, priority или target`, "QUALIFICATION");
    assertStringArray(disposition.evidenceRefs, `${globalId}.evidenceRefs`, { nonempty: true }, "QUALIFICATION");
    nonemptyString(disposition.rationale, `${globalId}.rationale`, "QUALIFICATION");
  }
  exactKeys(qualification.outcome, ["state", "reason"], "qualification.outcome", "QUALIFICATION");
  const expectedOutcome = qualificationOutcome(qualification.dispositions);
  if (qualification.outcome.state !== expectedOutcome.state) fail("qualification.outcome нарушает приоритет результатов", "QUALIFICATION");
  if (qualification.outcome.reason !== expectedOutcome.reason) fail("qualification.outcome.reason не совпадает с каноническим результатом", "QUALIFICATION");
  assertCanonicalIdentity(qualification, "qualification", "QUALIFICATION");
  return { qualification, manifest, parsed };
}

export function buildQualification({ reviewManifestBytes, claudeBytes, geminiBytes, dispositions }) {
  const manifest = validateReviewManifest(JSON.parse(reviewManifestBytes.toString("utf8")));
  const parsed = {
    claude: parseReviewerResponse(claudeBytes, manifest.identity),
    gemini: parseReviewerResponse(geminiBytes, manifest.identity),
  };
  const qualification = {
    schemaVersion: 1,
    reviewManifestSha256: sha256Bytes(reviewManifestBytes),
    identity: "",
    reviews: {
      claude: { sha256: sha256Bytes(claudeBytes), verdict: parsed.claude.verdict, findingIds: parsed.claude.findings.map((finding) => finding.id) },
      gemini: { sha256: sha256Bytes(geminiBytes), verdict: parsed.gemini.verdict, findingIds: parsed.gemini.findings.map((finding) => finding.id) },
    },
    dispositions: structuredClone(dispositions),
    outcome: qualificationOutcome(dispositions),
  };
  qualification.identity = jcsIdentity(qualification);
  validateQualification(qualification, { reviewManifestBytes, claudeBytes, geminiBytes });
  return qualification;
}

function consolidatedFor(manifest, qualification, parsed, author) {
  nonemptyString(author, "author", "QUALIFICATION");
  const lines = ["# Сводный вывод внешнего ревью ТЗ", "", manifest.identity, "", "## Замечания и решения", ""];
  const findings = [
    ...parsed.claude.findings.map((finding) => ({ source: "claude", finding })),
    ...parsed.gemini.findings.map((finding) => ({ source: "gemini", finding })),
  ];
  if (findings.length === 0) lines.push("Замечаний нет.");
  else for (const [index, item] of findings.entries()) {
    const disposition = qualification.dispositions[index];
    lines.push(`- ${item.source}:${item.finding.id} (${item.finding.priority}) — ${item.finding.summary}; решение: ${disposition.decision}; основание: ${disposition.rationale}`);
  }
  lines.push("", "## Итог", "", `Состояние: ${qualification.outcome.state}.`, `Основание: ${qualification.outcome.reason}.`, `Автор квалификации: ${author}.`, "");
  return Buffer.from(lines.join("\n"));
}

function validateRunDeadlineMarker(marker, runId, identity = null, code = "RUN_DEADLINE") {
  const expected = identity === null
    ? ["schemaVersion", "runId", "base", "head", "startedAt", "overallDeadlineAt"]
    : ["identity", "runId", "startedAt", "overallDeadlineAt"];
  exactKeys(marker, expected, "run deadline marker", code);
  if ((identity === null && marker.schemaVersion !== 1) || marker.runId !== runId || (identity !== null && marker.identity !== identity)) {
    fail("run deadline marker относится к другому запуску", code);
  }
  const startedAt = Date.parse(marker.startedAt);
  const overallDeadlineAt = Date.parse(marker.overallDeadlineAt);
  if (!Number.isFinite(startedAt) || !Number.isFinite(overallDeadlineAt) || overallDeadlineAt <= startedAt
    || !marker.startedAt.endsWith("Z") || !marker.overallDeadlineAt.endsWith("Z")) {
    fail("run deadline marker содержит невалидные временные границы", code);
  }
  if (Date.now() > overallDeadlineAt) fail("общий временной бюджет review run исчерпан", code);
  return { startedAt, overallDeadlineAt };
}

export function qualifyReviewRun({ taskRoot, runId, dispositions, author = "Codex" }) {
  const qualificationStartedAt = Date.now();
  const root = ensureTaskLocalPath(taskRoot);
  validatePathSegment(runId, "run-id", /^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/);
  const runRoot = join(root, "runs", runId);
  ensureContainedDirectory(root, runRoot, "run");
  const reviewRoot = join(runRoot, "review-root");
  ensureContainedDirectory(root, reviewRoot, "review-root");
  const capture = join(runRoot, "capture");
  ensureContainedDirectory(root, capture, "capture");
  const reviewManifestBytes = readFileSync(join(runRoot, "review-manifest.json"));
  const reviewManifest = validateReviewManifest(JSON.parse(reviewManifestBytes.toString("utf8")));
  const preflightMarker = JSON.parse(readFileSync(join(capture, "preflight-started.json"), "utf8"));
  const startedMarker = JSON.parse(readFileSync(join(capture, "started.json"), "utf8"));
  const preflightDeadline = validateRunDeadlineMarker(preflightMarker, runId, null, "QUALIFICATION_TIMEOUT");
  const startedDeadline = validateRunDeadlineMarker(startedMarker, runId, reviewManifest.identity, "QUALIFICATION_TIMEOUT");
  if (preflightMarker.base !== reviewManifest.base || preflightMarker.head !== reviewManifest.head
    || preflightDeadline.startedAt !== startedDeadline.startedAt || preflightDeadline.overallDeadlineAt !== startedDeadline.overallDeadlineAt) {
    fail("deadline markers review run рассогласованы", "QUALIFICATION_TIMEOUT");
  }
  const claudeBytes = readFileSync(join(capture, "claude.stdout"));
  const geminiBytes = readFileSync(join(capture, "gemini.stdout"));
  const summary = JSON.parse(readFileSync(join(capture, "summary.json"), "utf8"));
  if (summary.runId !== runId || summary.status !== "completed" || summary.qualificationRequired !== true || summary.responsesValid !== true || summary.clientsPassed !== true) {
    fail("run не готов к qualification", "QUALIFICATION_RUN");
  }
  const qualification = buildQualification({ reviewManifestBytes, claudeBytes, geminiBytes, dispositions });
  const validated = validateQualification(qualification, { reviewManifestBytes, claudeBytes, geminiBytes });
  const qualificationBytes = Buffer.from(`${JSON.stringify(qualification, null, 2)}\n`);
  const consolidatedBytes = consolidatedFor(validated.manifest, qualification, validated.parsed, author);
  if (Date.now() - qualificationStartedAt > PROCESS_POLICY.qualificationSeconds * 1_000 || Date.now() > startedDeadline.overallDeadlineAt) {
    fail("qualification превысила фазовый или общий deadline", "QUALIFICATION_TIMEOUT");
  }
  publishQualifiedResults(reviewRoot, claudeBytes, geminiBytes, qualificationBytes, consolidatedBytes);
  return { runRoot, reviewRoot, target: qualification.outcome.state, qualificationIdentity: qualification.identity };
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
  exactKeys(manifest, ["schemaVersion", "reviewIdentity", "artifacts", "identity"], "final manifest", "FINAL_MANIFEST");
  if (manifest.schemaVersion !== 1) fail("final manifest schemaVersion должен быть 1", "FINAL_MANIFEST");
  assertSha256(manifest.reviewIdentity, "final manifest.reviewIdentity", "FINAL_MANIFEST");
  exactKeys(manifest.artifacts, FINAL_ARTIFACTS, "final manifest.artifacts", "FINAL_MANIFEST");
  for (const path of FINAL_ARTIFACTS) {
    assertSha256(manifest.artifacts[path], `final manifest.artifacts.${path}`, "FINAL_MANIFEST");
    if (fileHash(join(finalRoot, path)) !== manifest.artifacts[path]) fail(`${path}: hash итогового файла не совпадает`, "FINAL_HASH");
  }
  assertCanonicalIdentity(manifest, "final manifest", "FINAL_MANIFEST");
  let reviewManifest;
  try {
    reviewManifest = validateReviewManifest(JSON.parse(readFileSync(join(finalRoot, "review-manifest.json"), "utf8")));
  } catch {
    fail("review-manifest итогового набора содержит невалидный JSON", "FINAL_PROVENANCE");
  }
  if (reviewManifest.identity !== manifest.reviewIdentity
    || fileHash(join(finalRoot, "tz.md")) !== reviewManifest.hashes.spec
    || fileHash(join(finalRoot, "author-review.md")) !== reviewManifest.hashes.author_review) {
    fail("итоговый набор смешивает разные identity, ревизии или результаты", "FINAL_PROVENANCE");
  }
  validateQualification(JSON.parse(readFileSync(join(finalRoot, "qualification.json"), "utf8")), {
    reviewManifestBytes: readFileSync(join(finalRoot, "review-manifest.json")),
    claudeBytes: readFileSync(join(finalRoot, "claude.md")),
    geminiBytes: readFileSync(join(finalRoot, "gemini.md")),
  });
  validateConsolidated(join(finalRoot, "consolidated.md"), manifest.reviewIdentity);
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

export function finalizeReviewCycle({ taskRoot, revision, runId }) {
  const root = ensureTaskLocalPath(taskRoot);
  validatePathSegment(runId, "run-id", /^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/);
  const revisionRoot = ensureRevision(root, revision);
  const finalRoot = join(root, "final");
  if (lstatSafe(finalRoot) !== null) {
    ensureContainedDirectory(root, finalRoot, "final");
    const manifest = verifyFinalDirectory(finalRoot);
    const runRoot = join(root, "runs", runId);
    ensureContainedDirectory(root, runRoot, "run");
    const reviewManifestPath = join(runRoot, "review-manifest.json");
    assertRegularFile(reviewManifestPath, "FINAL_EXISTS");
    const expectedReviewIdentity = validateReviewManifest(JSON.parse(readFileSync(reviewManifestPath, "utf8"))).identity;
    if (manifest.reviewIdentity !== expectedReviewIdentity
      || !readFileSync(join(finalRoot, "tz.md")).equals(readFileSync(join(revisionRoot, "tz.md")))
      || !readFileSync(join(finalRoot, "author-review.md")).equals(readFileSync(join(revisionRoot, "author-review.md")))) {
      fail("итоговый каталог относится к другому запуску или ревизии", "FINAL_EXISTS");
    }
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
  exactFileSet(results, ["claude.md", "consolidated.md", "gemini.md", "qualification.json"]);
  for (const path of ["claude.md", "consolidated.md", "gemini.md", "qualification.json"]) assertRegularFile(join(results, path), "FINAL_PROVENANCE");
  const reviewManifestPath = join(runRoot, "review-manifest.json");
  assertRegularFile(reviewManifestPath, "FINAL_PROVENANCE");
  for (const path of ["started.json", "summary.json", "claude.stdout", "gemini.stdout"]) assertRegularFile(join(capture, path), "FINAL_PROVENANCE");
  const reviewManifestBytes = readFileSync(reviewManifestPath);
  const reviewManifest = validateReviewManifest(JSON.parse(reviewManifestBytes.toString("utf8")));
  const started = JSON.parse(readFileSync(join(capture, "started.json"), "utf8"));
  const runDeadline = validateRunDeadlineMarker(started, runId, reviewManifest.identity, "FINAL_TIMEOUT");
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
  if (fileHash(join(runRoot, "tz.md")) !== reviewManifest.hashes.spec
    || fileHash(join(runRoot, "author-review.md")) !== reviewManifest.hashes.author_review
    || !readFileSync(join(runRoot, "tz.md")).equals(readFileSync(join(revisionRoot, "tz.md")))
    || !readFileSync(join(runRoot, "author-review.md")).equals(readFileSync(join(revisionRoot, "author-review.md")))) {
    fail("review относится к другой ревизии ТЗ или авторского ревью", "FINAL_PROVENANCE");
  }
  const qualification = JSON.parse(readFileSync(join(results, "qualification.json"), "utf8"));
  validateQualification(qualification, {
    reviewManifestBytes,
    claudeBytes: readFileSync(join(results, "claude.md")),
    geminiBytes: readFileSync(join(results, "gemini.md")),
  });
  if (qualification.outcome.state !== "C09") fail("финализация разрешена только для исхода C09", "FINAL_QUALIFICATION");
  validateConsolidated(join(results, "consolidated.md"), reviewManifest.identity);
  if (!readFileSync(join(results, "claude.md")).equals(readFileSync(join(capture, "claude.stdout")))
    || !readFileSync(join(results, "gemini.md")).equals(readFileSync(join(capture, "gemini.stdout")))) {
    fail("accepted results не совпадают с захваченным stdout", "FINAL_PROVENANCE");
  }
  const pendingFinal = join(root, `.final-pending-${process.pid}-${randomBytes(6).toString("hex")}`);
  mkdirSync(pendingFinal, { recursive: false });
  try {
    const copies = [
      [join(runRoot, "tz.md"), "tz.md"],
      [join(runRoot, "author-review.md"), "author-review.md"],
      [reviewManifestPath, "review-manifest.json"],
      [join(results, "claude.md"), "claude.md"],
      [join(results, "gemini.md"), "gemini.md"],
      [join(results, "qualification.json"), "qualification.json"],
      [join(results, "consolidated.md"), "consolidated.md"],
    ];
    for (const [source, destination] of copies) copyFileSync(source, join(pendingFinal, destination));
    const artifacts = Object.fromEntries([...FINAL_ARTIFACTS].sort().map((path) => [path, fileHash(join(pendingFinal, path))]));
    const manifest = { schemaVersion: 1, reviewIdentity: reviewManifest.identity, artifacts, identity: "" };
    manifest.identity = jcsIdentity(manifest);
    writeFileSync(join(pendingFinal, "manifest.json"), `${JSON.stringify(manifest, null, 2)}\n`, { flag: "wx" });
    exactFileSet(pendingFinal, FINAL_FILES);
    for (const [path, hash] of Object.entries(artifacts)) if (fileHash(join(pendingFinal, path)) !== hash) fail(`${path}: hash итогового файла не совпадает`, "FINAL_HASH");
    const finalManifestHash = fileHash(join(pendingFinal, "manifest.json"));
    if (Date.now() > runDeadline.overallDeadlineAt) fail("финализация превысила общий deadline", "FINAL_TIMEOUT");
    renameSync(pendingFinal, finalRoot);
    verifyFinalDirectory(finalRoot);
    return { finalRoot, finalManifestHash, artifacts, target: "C09", reused: false };
  } catch (error) {
    rmSync(pendingFinal, { recursive: true, force: true });
    throw error;
  }
}

async function superviseProcess(argv) {
  const separator = argv.indexOf("--");
  if (separator !== 1 || typeof argv[0] !== "string" || !argv[0].startsWith("/")) {
    throw new Error("невалидный внутренний supervisor argv");
  }
  const executable = argv[0];
  const targetArgs = argv.slice(separator + 1);
  const control = await import("node:fs").then(({ createWriteStream }) => createWriteStream(null, { fd: 3, autoClose: false }));
  const queuedLines = [];
  const lineWaiters = [];
  let inputBuffer = "";
  let inputEnded = false;
  const deliverLine = (line) => {
    const waiter = lineWaiters.shift();
    if (waiter) waiter(line);
    else queuedLines.push(line);
  };
  process.stdin.setEncoding("utf8");
  process.stdin.on("data", (chunk) => {
    inputBuffer += chunk;
    const lines = inputBuffer.split("\n");
    inputBuffer = lines.pop() ?? "";
    for (const line of lines) deliverLine(line);
  });
  process.stdin.on("end", () => {
    inputEnded = true;
    if (inputBuffer !== "") deliverLine(inputBuffer);
    while (lineWaiters.length > 0) lineWaiters.shift()(null);
  });
  const readControlLine = () => {
    if (queuedLines.length > 0) return Promise.resolve(queuedLines.shift());
    if (inputEnded) return Promise.resolve(null);
    return new Promise((resolvePromise) => lineWaiters.push(resolvePromise));
  };
  control.write("READY\n");
  const command = await readControlLine();
  if (command !== "GO") throw new Error("supervisor не получил GO");
  const target = spawn(executable, targetArgs, {
    cwd: process.cwd(),
    env: process.env,
    detached: false,
    stdio: ["ignore", "inherit", "inherit"],
    shell: false,
  });
  let outcome;
  try {
    outcome = await new Promise((resolvePromise, rejectPromise) => {
      target.once("spawn", () => control.write(`STARTED ${target.pid}\n`));
      target.once("error", rejectPromise);
      target.once("close", (code, signal) => resolvePromise({ code, signal }));
    });
  } catch (error) {
    control.write(`START_ERROR ${JSON.stringify({ message: error instanceof Error ? error.message : "target не запущен" })}\n`);
    process.stdin.pause();
    await new Promise((resolvePromise) => control.end(resolvePromise));
    process.exitCode = 1;
    return;
  }
  control.write(`OUTCOME ${JSON.stringify(outcome)}\n`);
  const acknowledgement = await readControlLine();
  if (acknowledgement !== "ACK") throw new Error("supervisor не получил ACK финального снимка группы");
  control.end();
  if (Number.isInteger(outcome.code)) process.exitCode = outcome.code;
  else if (outcome.signal) process.exitCode = 128;
  else process.exitCode = 1;
}

function parseArguments(argv) {
  const flags = new Map();
  for (let index = 0; index < argv.length; index += 1) {
    const value = argv[index];
    if (!value.startsWith("--")) fail(`неожиданный аргумент ${value}`, "CLI_ARGS");
    if (["--self-test", "--finalize", "--qualify"].includes(value)) flags.set(value, true);
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
  if (flags.has("--qualify")) {
    const runId = flags.get("--run-id");
    const dispositionsPath = flags.get("--dispositions");
    if (!runId || !dispositionsPath) fail("для --qualify обязательны --run-id и --dispositions", "CLI_ARGS");
    let dispositions;
    try {
      dispositions = JSON.parse(readFileSync(resolve(dispositionsPath), "utf8"));
    } catch {
      fail("dispositions должен быть JSON-файлом", "CLI_ARGS");
    }
    console.log(JSON.stringify(qualifyReviewRun({ taskRoot, runId, dispositions, author: flags.get("--author") ?? "Codex" })));
    return;
  }
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

if (resolve(process.argv[1] ?? "") === SELF_PATH) {
  try {
    if (process.argv[2] === "--process-supervisor") await superviseProcess(process.argv.slice(3));
    else await main(process.argv.slice(2));
  } catch (error) {
    console.error(`Error: workflow-spec-review [${error?.code ?? "UNEXPECTED"}]: ${error instanceof Error ? error.message : "неизвестная ошибка"}`);
    process.exitCode = 1;
  }
}
