#!/usr/bin/env node
import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import {
  analyzeMarkdown,
  stripMarkdownCode,
} from "./lib/markdown-visibility.mjs";

const PROCESS_ONLY_FILE_PATTERNS = [
  /^AGENTS\.md$/,
  /^README\.md$/,
  /^docs\//,
  /^\.github\/copilot-instructions\.md$/,
  /^\.github\/instructions\/.*\.instructions\.md$/,
  /^\.github\/scripts\/ci-change-scope\.mjs$/,
  /^\.github\/workflows\/php-artisan-test\.ya?ml$/,
  /^\.github\/scripts\/release-process-guard\.mjs$/,
  /^\.github\/workflows\/release-process-guard\.ya?ml$/,
  /^\.github\/scripts\/ab-readiness-check\.mjs$/,
  /^\.github\/workflows\/ab-readiness-check\.ya?ml$/,
  /^\.github\/scripts\/(?:package(?:-lock)?\.json|\.gitignore)$/,
  /^\.github\/scripts\/lib\/markdown-visibility\.mjs$/,
  /^\.github\/scripts\/tests\/markdown-visibility\.test\.mjs$/,
  /^\.github\/scripts\/copilot-feasibility-spike\.mjs$/,
  /^\.github\/workflows\/copilot-feasibility-spike\.ya?ml$/,
  /^\.github\/scripts\/copilot-merge-readiness\.mjs$/,
  /^\.github\/workflows\/copilot-merge-readiness\.ya?ml$/,
  /^phpunit\.xml$/,
  /^scripts\/ci-run-phpunit-shard\.sh$/,
  /^scripts\/local-test\.sh$/,
  /^tests\//,
  /^\.agents\/skills\//,
  /(^|\/)[^/]+\.md$/,
];

const STAGING_SMOKE_WORKFLOW_NAME = "Staging Post-Deploy Smoke";
const CYRILLIC_PATTERN = /[А-Яа-яЁё]/;
const LATIN_PATTERN = /[A-Za-z]/;
const ENGLISH_PR_HEADING_PATTERN =
  /^(Summary|Overview|Description|Why|Validation|Testing|Tests|Checks|Delivery note|Implementation|Changes|Root cause|Impact)\s*$/i;
const ALLOWED_TECHNICAL_TERMS_PATTERN =
  /\b(codex|Copilot|Copilot CLI|Copilot Requests|CLI|PAT|token|secret|workflow_dispatch|GITHUB_TOKEN|COPILOT_GITHUB_TOKEN|READY_TO_MERGE|BLOCKED|shadow|verdict|merge-readiness|PR|MCP|CI|UI|URL|API|JSON|YAML|TOML|PHP|SQL|HTTP|HTTPS|Docker|Laravel|Boost|Filament|Livewire|Bitrix24|AB Connector|Spec repo|Spec doc|Spec revision|Staging PR|Staging smoke|Staging Post-Deploy Smoke|rev-check|public smoke|admin smoke|dev-only|validated diff|clean-main-PR|workflow|runtime|main|staging|draft|ready|merge|commit|branch|pull request|release-process-guard|ab-readiness-check|copilot-feasibility-spike|copilot-merge-readiness|php-artisan-test)\b/gi;

function isProcessOnlyFile(filename) {
  return PROCESS_ONLY_FILE_PATTERNS.some((pattern) => pattern.test(filename));
}

function extractStagingPrNumbers(body) {
  const fieldPattern = /(?:^|\n) {0,3}(?:Staging[ \t]+PRs?|staging-prs?)[ \t]*:[ \t]*(.+)/gi;
  const matches = [...analyzeMarkdown(body).visibleFieldText.matchAll(fieldPattern)];

  return [
    ...new Set(
      matches.flatMap((match) => (
        [...match[1].matchAll(/(?:https:\/\/github\.com\/[^/\s]+\/[^/\s]+\/pull\/)?#?(\d+)\b/gi)]
          .map((numberMatch) => Number.parseInt(numberMatch[1], 10))
          .filter(Number.isInteger)
      )),
    ),
  ];
}

function extractStagingPrNumber(body) {
  return extractStagingPrNumbers(body)[0] || null;
}

function parseLinkedIssues(body = "") {
  const fieldPattern = /^ {0,3}(?:[-*][ \t]*)?Связанные задачи[ \t]*:[ \t]*(.*?)[ \t]*\r?$/gim;
  const matches = [...analyzeMarkdown(body).visibleFieldText.matchAll(fieldPattern)];

  if (matches.length === 0) {
    return {
      valid: false,
      issues: [],
      error: "отсутствует строка `Связанные задачи: #NNN, #MMM` или `Связанные задачи: не требуется`",
    };
  }

  if (matches.length > 1) {
    return {
      valid: false,
      issues: [],
      error: "поле `Связанные задачи` должно встречаться ровно один раз",
    };
  }

  const value = matches[0][1].trim().replace(/^`|`$/g, "");

  if (/^не требуется$/i.test(value)) {
    return { valid: true, issues: [], error: null };
  }

  if (!/^#[1-9]\d*(?:, #[1-9]\d*)*$/.test(value)) {
    return {
      valid: false,
      issues: [],
      error: "поле `Связанные задачи` должно содержать только `#NNN, #MMM` или `не требуется`",
    };
  }

  const issues = [...value.matchAll(/#([1-9]\d*)/g)].map((match) => Number.parseInt(match[1], 10));

  if (issues.some((issueNumber) => !Number.isSafeInteger(issueNumber))) {
    return {
      valid: false,
      issues: [],
      error: "поле `Связанные задачи` содержит недопустимый номер задачи",
    };
  }

  if (new Set(issues).size !== issues.length) {
    return {
      valid: false,
      issues: [],
      error: "поле `Связанные задачи` содержит повторяющиеся номера",
    };
  }

  return { valid: true, issues, error: null };
}

function formatIssueNumbers(issueNumbers) {
  return issueNumbers.length > 0 ? issueNumbers.map((issueNumber) => `#${issueNumber}`).join(", ") : "не требуется";
}

function validateLinkedIssuesLineage({
  body,
  stagingPrNumbers,
  stagingPrs,
  stagingPrFetchErrors,
}) {
  const failures = [];
  const mainLinkedIssues = parseLinkedIssues(body);

  if (!mainLinkedIssues.valid) {
    failures.push(`PR в \`main\`: ${mainLinkedIssues.error}.`);
  }

  const expectedIssues = new Set();
  let stagingFieldsValid = true;

  for (const stagingPr of stagingPrs) {
    const stagingLinkedIssues = parseLinkedIssues(stagingPr.body);

    if (!stagingLinkedIssues.valid) {
      stagingFieldsValid = false;
      failures.push(`Связанный staging PR #${stagingPr.number}: ${stagingLinkedIssues.error}.`);
      continue;
    }

    for (const issueNumber of stagingLinkedIssues.issues) {
      expectedIssues.add(issueNumber);
    }
  }

  const allStagingPrsLoaded = stagingPrNumbers.length > 0
    && stagingPrFetchErrors.length === 0
    && stagingPrs.length === stagingPrNumbers.length;

  if (!mainLinkedIssues.valid || !stagingFieldsValid || !allStagingPrsLoaded) {
    return failures;
  }

  const actualIssues = new Set(mainLinkedIssues.issues);
  const missingIssues = [...expectedIssues].filter((issueNumber) => !actualIssues.has(issueNumber)).sort((a, b) => a - b);
  const extraIssues = [...actualIssues].filter((issueNumber) => !expectedIssues.has(issueNumber)).sort((a, b) => a - b);

  if (missingIssues.length > 0 || extraIssues.length > 0) {
    failures.push(
      "PR в `main` должен точно сохранять объединение поля `Связанные задачи` из всех связанных staging PR: "
      + `ожидалось ${formatIssueNumbers([...expectedIssues].sort((a, b) => a - b))}; `
      + `получено ${formatIssueNumbers([...actualIssues].sort((a, b) => a - b))}; `
      + `отсутствуют ${formatIssueNumbers(missingIssues)}; лишние ${formatIssueNumbers(extraIssues)}.`,
    );
  }

  return failures;
}

function extractStagingSmokeRunUrl(body) {
  const lineMatch = analyzeMarkdown(body).visibleFieldText.match(/(?:^|\n) {0,3}Staging[ \t]+smoke[ \t]*:[ \t]*(.+)/i);

  if (!lineMatch) {
    return null;
  }

  const value = lineMatch[1].trim();
  const markdownMatch = value.match(/\]\((https?:\/\/[^)\s]+)\)/i);
  const directMatch = value.match(/https?:\/\/[^\s>)]+/i);
  const url = markdownMatch?.[1] || directMatch?.[0] || null;

  return url ? url.replace(/[.,;]+$/, "") : null;
}

function parseGitHubActionsRunUrl(url) {
  if (!url) {
    return null;
  }

  const match = url.match(/^https:\/\/github\.com\/([^/\s]+)\/([^/\s]+)\/actions\/runs\/(\d+)(?:\/.*)?(?:[?#].*)?$/i);

  if (!match) {
    return null;
  }

  return {
    owner: match[1],
    repo: match[2],
    runId: Number.parseInt(match[3], 10),
  };
}

function summarizeFiles(files) {
  const runtimeFiles = files.filter((file) => !isProcessOnlyFile(file.filename));

  return {
    runtimeFiles,
    processOnlyFiles: files.filter((file) => isProcessOnlyFile(file.filename)),
  };
}

function normalizePatch(patch = "") {
  return patch
    .split("\n")
    .filter((line) => {
      if (line.startsWith("+++ ") || line.startsWith("--- ")) {
        return false;
      }

      if (line.startsWith("+") || line.startsWith("-")) {
        return true;
      }

      return false;
    })
    .join("\n")
    .trim();
}

function fileFingerprint(file) {
  return {
    status: file.status || "modified",
    patch: normalizePatch(file.patch || ""),
    previousFilename: file.previous_filename || "",
  };
}

function groupFilesByName(files) {
  const grouped = new Map();

  for (const file of files) {
    const group = grouped.get(file.filename) || [];
    group.push(file);
    grouped.set(file.filename, group);
  }

  return grouped;
}

function aggregateFileStatus(files) {
  const statuses = new Set(files.map((file) => file.status || "modified"));

  if (statuses.size === 1) {
    return [...statuses][0];
  }

  if (statuses.has("added") && !statuses.has("removed")) {
    return "added";
  }

  if (statuses.has("removed") && !statuses.has("added")) {
    return "removed";
  }

  return "modified";
}

function aggregatePreviousFilename(files) {
  return files.find((file) => file.previous_filename)?.previous_filename || "";
}

function countPatchLines(files) {
  const additions = new Map();
  const removals = new Map();

  for (const file of files) {
    const patch = normalizePatch(file.patch || "");

    if (patch === "") {
      continue;
    }

    for (const line of patch.split("\n")) {
      const content = line.slice(1);
      const bucket = line.startsWith("+") ? additions : removals;

      bucket.set(content, (bucket.get(content) || 0) + 1);
    }
  }

  const counts = new Map();
  const contents = new Set([...additions.keys(), ...removals.keys()]);

  for (const content of contents) {
    const delta = (additions.get(content) || 0) - (removals.get(content) || 0);

    if (delta > 0) {
      counts.set(`+${content}`, delta);
    } else if (delta < 0) {
      counts.set(`-${content}`, Math.abs(delta));
    }
  }

  return counts;
}

function comparePatchLineCounts(currentCounts, stagingCounts) {
  const failures = [];

  for (const [line, expectedCount] of stagingCounts.entries()) {
    const currentCount = currentCounts.get(line) || 0;

    if (currentCount < expectedCount) {
      failures.push(`missing ${expectedCount - currentCount} occurrence(s) of ${JSON.stringify(line)}`);
    }
  }

  for (const [line, currentCount] of currentCounts.entries()) {
    const expectedCount = stagingCounts.get(line) || 0;

    if (currentCount > expectedCount) {
      failures.push(`unexpected ${currentCount - expectedCount} occurrence(s) of ${JSON.stringify(line)}`);
    }
  }

  return failures;
}

function compareValidatedFiles(currentFiles, stagingFiles) {
  const current = groupFilesByName(currentFiles);
  const staging = groupFilesByName(stagingFiles);
  const failures = [];

  for (const filename of current.keys()) {
    if (!staging.has(filename)) {
      failures.push(`unexpected file in main PR: ${filename}`);
    }
  }

  for (const [filename, stagingGroup] of staging.entries()) {
    const currentGroup = current.get(filename);

    if (!currentGroup) {
      failures.push(`missing file from staging PR: ${filename}`);
      continue;
    }

    const currentFingerprint = fileFingerprint({
      filename,
      status: aggregateFileStatus(currentGroup),
      patch: "",
      previous_filename: aggregatePreviousFilename(currentGroup),
    });
    const stagingFingerprint = fileFingerprint({
      filename,
      status: aggregateFileStatus(stagingGroup),
      patch: "",
      previous_filename: aggregatePreviousFilename(stagingGroup),
    });

    if (currentFingerprint.status !== stagingFingerprint.status) {
      failures.push(
        `status mismatch for ${filename}: expected ${stagingFingerprint.status}, got ${currentFingerprint.status}`,
      );
    }

    const patchFailures = comparePatchLineCounts(countPatchLines(currentGroup), countPatchLines(stagingGroup));

    if (patchFailures.length > 0) {
      failures.push(`patch mismatch for ${filename}: ${patchFailures.slice(0, 5).join("; ")}`);
    }

    if (currentFingerprint.previousFilename !== stagingFingerprint.previousFilename) {
      failures.push(`rename source mismatch for ${filename}`);
    }
  }

  return failures;
}

function fileContentsEqual(left, right) {
  return left.exists === right.exists
    && Buffer.compare(left.content || Buffer.alloc(0), right.content || Buffer.alloc(0)) === 0;
}

function compareValidatedFileContents(currentFiles, stagingFiles, fileContentSnapshots) {
  const current = groupFilesByName(currentFiles);
  const staging = groupFilesByName(stagingFiles);
  const failures = [];

  for (const filename of current.keys()) {
    if (!staging.has(filename)) {
      failures.push(`unexpected file in main PR: ${filename}`);
    }
  }

  for (const filename of staging.keys()) {
    const snapshot = fileContentSnapshots.get(filename);

    if (!snapshot) {
      failures.push(`missing content snapshot for ${filename}`);
      continue;
    }

    if (snapshot.error) {
      failures.push(`content snapshot failed for ${filename}: ${snapshot.error}`);
      continue;
    }

    const currentGroup = current.get(filename);
    const baseMatchesStaging = fileContentsEqual(snapshot.base, snapshot.staging);
    const headMatchesStaging = fileContentsEqual(snapshot.head, snapshot.staging);

    if (!currentGroup && !baseMatchesStaging) {
      failures.push(`missing file from staging PR: ${filename}`);
      continue;
    }

    if (currentGroup && !headMatchesStaging) {
      failures.push(`content mismatch for ${filename}: current PR head must match the staging smoke commit`);
    }
  }

  return failures;
}

function stripTechnicalMarkdown(text) {
  return stripMarkdownCode(text)
    .replace(/https?:\/\/\S+/g, " ")
    .replace(/(?:^|\s)[\w./-]+\.[A-Za-z0-9]+(?=\s|$)/g, " ")
    .replace(ALLOWED_TECHNICAL_TERMS_PATTERN, " ");
}

function countMatches(text, pattern) {
  return [...text.matchAll(pattern)].length;
}

function argValue(name) {
  const index = process.argv.indexOf(name);

  if (index === -1) {
    return null;
  }

  const value = process.argv[index + 1];

  if (!value || value.startsWith("--")) {
    throw new Error(`${name} requires a value.`);
  }

  return value;
}

function requiredArgValue(name) {
  const value = argValue(name);

  if (value === null || value.trim() === "") {
    throw new Error(`${name} is required.`);
  }

  return value;
}

function git(args) {
  return execFileSync("git", args, {
    encoding: "utf8",
    stdio: ["ignore", "pipe", "pipe"],
  }).trim();
}

function gitRaw(args) {
  return execFileSync("git", args, {
    encoding: "buffer",
    stdio: ["ignore", "pipe", "pipe"],
  });
}

function fileStatusFromNameStatus(statusCode) {
  if (statusCode.startsWith("R")) {
    return "renamed";
  }

  return {
    A: "added",
    D: "removed",
    M: "modified",
  }[statusCode[0]] || "modified";
}

function localDiffPatch(diffBase, filename) {
  try {
    return git(["diff", "--find-renames", `${diffBase}...HEAD`, "--", filename]);
  } catch {
    return "";
  }
}

function listLocalDiffFiles(diffBase) {
  const nameStatus = git(["diff", "--name-status", "--find-renames", `${diffBase}...HEAD`]);

  if (nameStatus === "") {
    return [];
  }

  return nameStatus.split("\n").map((line) => {
    const parts = line.split("\t");
    const statusCode = parts[0] || "M";
    const isRename = statusCode.startsWith("R");
    const filename = isRename ? parts[2] : parts[1];
    const previousFilename = isRename ? parts[1] : "";

    return {
      filename,
      patch: localDiffPatch(diffBase, filename),
      previous_filename: previousFilename,
      status: fileStatusFromNameStatus(statusCode),
    };
  });
}

function worktreeStatus() {
  return git(["status", "--porcelain"]);
}

function validatePublishLanguage({ title, body }) {
  const failures = [];
  const markdown = analyzeMarkdown(body);
  const readableTitle = title.replace(/^\s*\[codex\]\s*/i, "").trim();
  const readableBody = stripTechnicalMarkdown(body);
  const readableText = `${stripTechnicalMarkdown(readableTitle)}\n${readableBody}`;
  const englishHeadings = markdown.headings.filter((heading) => ENGLISH_PR_HEADING_PATTERN.test(heading));
  const cyrillicCount = countMatches(readableText, /[А-Яа-яЁё]/g);
  const latinCount = countMatches(readableText, /[A-Za-z]/g);

  if (!CYRILLIC_PATTERN.test(readableTitle)) {
    failures.push("PR title must be written in Russian, except technical tokens such as `[codex]`.");
  }

  if (!CYRILLIC_PATTERN.test(readableBody)) {
    failures.push("PR body must contain Russian human-readable prose.");
  }

  if (englishHeadings.length > 0) {
    failures.push(`PR body must use Russian section headings, got: ${englishHeadings.join(", ")}.`);
  }

  if (LATIN_PATTERN.test(readableText) && latinCount > Math.max(20, cyrillicCount * 0.25)) {
    failures.push("PR title/body contain too much English prose outside allowed technical tokens.");
  }

  return failures;
}

function evaluatePullRequest({
  baseRef,
  title = "",
  body,
  files,
  repository = null,
  stagingPr = null,
  stagingPrFetchError = null,
  stagingPrs = [],
  stagingPrFetchErrors = [],
  stagingPrFiles = [],
  stagingPrFilesFetchError = null,
  stagingPrFilesFetchErrors = [],
  fileContentSnapshots = null,
  currentPrCommitShas = [],
  stagingSmokeRun = null,
  stagingSmokeRunFetchError = null,
}) {
  const failures = [];
  const { runtimeFiles } = summarizeFiles(files);
  const stagingPrNumbers = extractStagingPrNumbers(body);
  const stagingPrNumber = stagingPrNumbers[0] || null;
  const stagingSmokeRunUrl = extractStagingSmokeRunUrl(body);
  const stagingSmokeRunReference = parseGitHubActionsRunUrl(stagingSmokeRunUrl);
  const resolvedStagingPrs = stagingPrs.length > 0 ? stagingPrs : [stagingPr].filter(Boolean);
  const resolvedStagingPrFetchErrors = stagingPrFetchErrors.length > 0
    ? stagingPrFetchErrors
    : [stagingPrFetchError ? { number: stagingPrNumber, error: stagingPrFetchError } : null].filter(Boolean);
  const resolvedStagingPrFilesFetchErrors = stagingPrFilesFetchErrors.length > 0
    ? stagingPrFilesFetchErrors
    : [stagingPrFilesFetchError ? { number: stagingPrNumber, error: stagingPrFilesFetchError } : null].filter(Boolean);

  failures.push(...validatePublishLanguage({ title, body }));

  if (baseRef === "staging") {
    return { failures, runtimeFiles, stagingPrNumber, stagingPrNumbers, stagingSmokeRunReference };
  }

  if (baseRef !== "main") {
    failures.push("Release Process Guard supports PRs to `staging` or `main` only.");
    return { failures, runtimeFiles, stagingPrNumber, stagingPrNumbers, stagingSmokeRunReference };
  }

  if (runtimeFiles.length === 0) {
    return { failures, runtimeFiles, stagingPrNumber, stagingPrNumbers, stagingSmokeRunReference };
  }

  if (stagingPrNumbers.length === 0) {
    failures.push("Main PRs with runtime changes must include `Staging PR: #NNN` or `Staging PRs: #NNN, #MMM` in the PR body.");
  }

  if (!stagingSmokeRunUrl) {
    failures.push("Main PRs with runtime changes must include `Staging smoke: https://...` in the PR body.");
  } else if (!stagingSmokeRunReference) {
    failures.push("`Staging smoke` must link to a GitHub Actions run URL.");
  } else if (repository && (stagingSmokeRunReference.owner !== repository.owner || stagingSmokeRunReference.repo !== repository.repo)) {
    failures.push("`Staging smoke` must link to a GitHub Actions run in this repository.");
  }

  for (const { number, error } of resolvedStagingPrFetchErrors) {
    failures.push(`Referenced staging PR #${number || "unknown"} could not be loaded: ${error.message}`);
  }

  failures.push(...validateLinkedIssuesLineage({
    body,
    stagingPrNumbers,
    stagingPrs: resolvedStagingPrs,
    stagingPrFetchErrors: resolvedStagingPrFetchErrors,
  }));

  for (const currentStagingPr of resolvedStagingPrs) {
    if (currentStagingPr.base?.ref !== "staging") {
      failures.push(`Referenced staging PR #${currentStagingPr.number} must target \`staging\`.`);
    }

    if (!currentStagingPr.merged_at) {
      failures.push(`Referenced staging PR #${currentStagingPr.number} must be merged before opening a runtime PR to \`main\`.`);
    }

    if (!currentStagingPr.merge_commit_sha) {
      failures.push(`Referenced staging PR #${currentStagingPr.number} must expose a merge commit SHA.`);
    } else if (currentPrCommitShas.includes(currentStagingPr.merge_commit_sha)) {
      failures.push(
        `Current main PR must use validated diff from staging PR #${currentStagingPr.number}, not include staging merge commit ${currentStagingPr.merge_commit_sha}.`,
      );
    }
  }

  if (resolvedStagingPrs.length > 0) {
    for (const { number, error } of resolvedStagingPrFilesFetchErrors) {
      failures.push(
        `Files from staging PR #${number || "unknown"} could not be loaded: ${error.message}`,
      );
    }

    const stagingRuntimeFiles = stagingPrFiles.filter((file) => !isProcessOnlyFile(file.filename));

    if (stagingPrFiles.length === 0) {
      failures.push(`Referenced staging PRs #${stagingPrNumbers.join(", #")} must expose changed files.`);
    } else if (stagingRuntimeFiles.length === 0) {
      failures.push(`Referenced staging PRs #${stagingPrNumbers.join(", #")} must expose runtime changed files.`);
    } else {
      const fileFailures = fileContentSnapshots
        ? compareValidatedFileContents(runtimeFiles, stagingRuntimeFiles, fileContentSnapshots)
        : compareValidatedFiles(runtimeFiles, stagingRuntimeFiles);

      if (fileFailures.length > 0) {
        failures.push(
          `Current main PR must match validated runtime file content from staging PRs #${stagingPrNumbers.join(", #")}: ${fileFailures.join("; ")}`,
        );
      }
    }
  }

  if (stagingSmokeRunFetchError) {
    failures.push(`Referenced staging smoke run could not be loaded: ${stagingSmokeRunFetchError.message}`);
  }

  if (stagingSmokeRun) {
    if (stagingSmokeRun.name !== STAGING_SMOKE_WORKFLOW_NAME) {
      failures.push(`Staging smoke run must use workflow \`${STAGING_SMOKE_WORKFLOW_NAME}\`.`);
    }

    if (stagingSmokeRun.head_branch !== "staging") {
      failures.push("Staging smoke run must execute on the `staging` branch.");
    }

    if (stagingSmokeRun.status !== "completed" || stagingSmokeRun.conclusion !== "success") {
      failures.push("Staging smoke run must be completed successfully.");
    }

    const finalStagingPr = resolvedStagingPrs.at(-1);

    if (finalStagingPr?.merge_commit_sha && stagingSmokeRun.head_sha !== finalStagingPr.merge_commit_sha) {
      failures.push(
        `Staging smoke run must verify final staging merge commit ${finalStagingPr.merge_commit_sha} from staging PR #${finalStagingPr.number}, got ${stagingSmokeRun.head_sha}.`,
      );
    }
  }

  return { failures, runtimeFiles, stagingPrNumber, stagingPrNumbers, stagingSmokeRunReference };
}

async function githubRequest(path, token) {
  const response = await fetch(`https://api.github.com${path}`, {
    headers: {
      Accept: "application/vnd.github+json",
      Authorization: `Bearer ${token}`,
      "User-Agent": "project-1-release-process-guard",
      "X-GitHub-Api-Version": "2022-11-28",
    },
  });

  if (!response.ok) {
    const body = await response.text();
    throw new Error(`GitHub API request failed: ${response.status} ${response.statusText}\n${body}`);
  }

  return response.json();
}

async function listPullRequestFiles({ owner, repo, pullNumber, token }) {
  const files = [];

  for (let page = 1; ; page += 1) {
    const batch = await githubRequest(
      `/repos/${owner}/${repo}/pulls/${pullNumber}/files?per_page=100&page=${page}`,
      token,
    );

    files.push(
      ...batch.map((file) => ({
        filename: file.filename,
        patch: file.patch || "",
        previous_filename: file.previous_filename || "",
        status: file.status || "modified",
      })),
    );

    if (batch.length < 100) {
      return files;
    }
  }
}

async function listPullRequestCommitShas({ owner, repo, pullNumber, token }) {
  const commitShas = [];

  for (let page = 1; ; page += 1) {
    const batch = await githubRequest(
      `/repos/${owner}/${repo}/pulls/${pullNumber}/commits?per_page=100&page=${page}`,
      token,
    );

    commitShas.push(...batch.map((commit) => commit.sha));

    if (batch.length < 100) {
      return commitShas;
    }
  }
}

function ensureGitCommit(sha) {
  if (!sha) {
    return;
  }

  try {
    gitRaw(["cat-file", "-e", `${sha}^{commit}`]);
    return;
  } catch {
    // The workflow checkout is shallow. Fetch the exact validated commits only
    // when local object storage does not already contain them.
  }

  git(["fetch", "--no-tags", "--depth=1", "origin", sha]);
}

function gitFileSnapshot(sha, filename) {
  try {
    return {
      exists: true,
      content: gitRaw(["show", `${sha}:${filename}`]),
    };
  } catch {
    return {
      exists: false,
      content: Buffer.alloc(0),
    };
  }
}

function buildFileContentSnapshots({ filenames, baseSha, headSha, stagingSha }) {
  ensureGitCommit(baseSha);
  ensureGitCommit(headSha);
  ensureGitCommit(stagingSha);

  const snapshots = new Map();

  for (const filename of filenames) {
    snapshots.set(filename, {
      base: gitFileSnapshot(baseSha, filename),
      head: gitFileSnapshot(headSha, filename),
      staging: gitFileSnapshot(stagingSha, filename),
    });
  }

  return snapshots;
}

function parseRepository(repository) {
  const [owner, repo] = repository.split("/");

  if (!owner || !repo) {
    throw new Error(`Invalid GITHUB_REPOSITORY value: ${repository}`);
  }

  return { owner, repo };
}

function printFailure(message) {
  console.error(`::error::${message}`);
}

function runLocalPr() {
  const baseRef = requiredArgValue("--base");
  const title = readFileSync(requiredArgValue("--title-file"), "utf8").trim();
  const body = readFileSync(requiredArgValue("--body-file"), "utf8");
  const diffBase = argValue("--diff-base") || `origin/${baseRef}`;
  const dirtyStatus = worktreeStatus();

  if (dirtyStatus !== "" && !process.argv.includes("--allow-dirty")) {
    console.error("Local release process guard failed.");
    console.error("Working tree has uncommitted changes. Commit or stash them before PR preflight.");
    console.error(dirtyStatus);
    process.exit(1);
  }

  const files = listLocalDiffFiles(diffBase);
  const result = evaluatePullRequest({
    baseRef,
    title,
    body,
    files,
  });

  if (result.failures.length > 0) {
    console.error("Local release process guard failed.");
    console.error(`Base branch: ${baseRef}`);
    console.error(`Diff base: ${diffBase}`);
    console.error(`Runtime files: ${result.runtimeFiles.map((file) => file.filename).join(", ") || "none"}`);
    result.failures.forEach(printFailure);
    process.exit(1);
  }

  console.log("Local release process guard passed.");
  console.log(`Base branch: ${baseRef}`);
  console.log(`Diff base: ${diffBase}`);
  console.log(`Runtime files: ${result.runtimeFiles.length}`);

  if (result.stagingPrNumbers.length > 0) {
    console.log(`Staging PRs: #${result.stagingPrNumbers.join(", #")}`);
  }
}

function runSelfTest() {
  assert.equal(isProcessOnlyFile("docs/release.md"), true);
  assert.equal(isProcessOnlyFile(".github/copilot-instructions.md"), true);
  assert.equal(isProcessOnlyFile(".github/workflows/release-process-guard.yml"), true);
  assert.equal(isProcessOnlyFile(".github/scripts/ab-readiness-check.mjs"), true);
  assert.equal(isProcessOnlyFile(".github/scripts/package-lock.json"), true);
  assert.equal(isProcessOnlyFile(".github/scripts/lib/markdown-visibility.mjs"), true);
  assert.equal(isProcessOnlyFile(".github/scripts/tests/markdown-visibility.test.mjs"), true);
  assert.equal(isProcessOnlyFile(".github/workflows/ab-readiness-check.yml"), true);
  assert.equal(isProcessOnlyFile(".github/scripts/copilot-feasibility-spike.mjs"), true);
  assert.equal(isProcessOnlyFile(".github/workflows/copilot-feasibility-spike.yml"), true);
  assert.equal(isProcessOnlyFile(".github/scripts/copilot-merge-readiness.mjs"), true);
  assert.equal(isProcessOnlyFile(".github/workflows/copilot-merge-readiness.yml"), true);
  assert.equal(isProcessOnlyFile("phpunit.xml"), true);
  assert.equal(isProcessOnlyFile("scripts/ci-run-phpunit-shard.sh"), true);
  assert.equal(isProcessOnlyFile("scripts/local-test.sh"), true);
  assert.equal(isProcessOnlyFile("tests/Feature/ScenarioBuilderV3StateTest.php"), true);
  assert.equal(isProcessOnlyFile(".agents/skills/ab-connector-skill-authoring/agents/openai.yaml"), true);
  assert.equal(isProcessOnlyFile("app/Services/Bitrix24ContactSyncService.php"), false);
  assert.match(stripMarkdownCode("Обычный текст\n    Fixes #708"), /Fixes #708/);
  assert.match(stripMarkdownCode("- пояснение\n\n    Fixes #708"), /Fixes #708/);
  assert.doesNotMatch(stripMarkdownCode("Обычный текст\n\n    Fixes #708"), /Fixes #708/);
  assert.doesNotMatch(stripMarkdownCode("# Заголовок\n    Fixes #708"), /Fixes #708/);
  assert.doesNotMatch(stripMarkdownCode("Заголовок\n---\n    Fixes #708"), /Fixes #708/);
  assert.deepEqual(parseGitHubActionsRunUrl("https://github.com/Etogerman/Project-1/actions/runs/123"), {
    owner: "Etogerman",
    repo: "Project-1",
    runId: 123,
  });
  assert.equal(extractStagingSmokeRunUrl("Staging smoke: [run](https://github.com/Etogerman/Project-1/actions/runs/123)"), "https://github.com/Etogerman/Project-1/actions/runs/123");
  assert.deepEqual(extractStagingPrNumbers("Staging PR: #614"), [614]);
  assert.deepEqual(extractStagingPrNumbers("Staging PRs: #614, #615"), [614, 615]);
  assert.deepEqual(
    extractStagingPrNumbers("Staging PRs: https://github.com/Etogerman/Project-1/pull/614, #615"),
    [614, 615],
  );
  assert.deepEqual(
    extractStagingPrNumbers("Staging PR: #614\nStaging PRs: #615, #616"),
    [614, 615, 616],
  );
  assert.deepEqual(
    extractStagingPrNumbers("<!--\nStaging PR: #999\n-->\nStaging PR: #614"),
    [614],
  );
  assert.deepEqual(extractStagingPrNumbers("``Staging PR: #614``"), []);
  assert.deepEqual(extractStagingPrNumbers("    Staging PR: #614"), []);
  assert.equal(extractStagingSmokeRunUrl("``Staging smoke: https://github.com/Etogerman/Project-1/actions/runs/123``"), null);
  assert.equal(extractStagingSmokeRunUrl("    Staging smoke: https://github.com/Etogerman/Project-1/actions/runs/123"), null);
  assert.deepEqual(parseLinkedIssues("- Связанные задачи: #708"), {
    valid: true,
    issues: [708],
    error: null,
  });
  assert.deepEqual(parseLinkedIssues("Связанные задачи: #712, #708"), {
    valid: true,
    issues: [712, 708],
    error: null,
  });
  assert.deepEqual(parseLinkedIssues("* Связанные задачи: НЕ ТРЕБУЕТСЯ"), {
    valid: true,
    issues: [],
    error: null,
  });
  assert.equal(parseLinkedIssues("Описание без поля").valid, false);
  assert.equal(parseLinkedIssues("Связанные задачи: #708, #708").valid, false);
  assert.equal(parseLinkedIssues("Связанные задачи: #708,#712").valid, false);
  assert.equal(parseLinkedIssues("Связанные задачи: #708,  #712").valid, false);
  assert.equal(parseLinkedIssues("Связанные задачи: не требуется, #708").valid, false);
  assert.equal(parseLinkedIssues("Связанные задачи: Closes #708").valid, false);
  assert.equal(parseLinkedIssues("Связанные задачи: https://github.com/Etogerman/Project-1/issues/708").valid, false);
  assert.equal(parseLinkedIssues("Связанные задачи: #0").valid, false);
  assert.equal(parseLinkedIssues("```text\nСвязанные задачи: #708\n```").valid, false);
  assert.equal(parseLinkedIssues("```text\nСвязанные задачи: #708").valid, false);
  assert.equal(parseLinkedIssues("~~~text\nСвязанные задачи: #708\n~~~").valid, false);
  assert.equal(parseLinkedIssues("~~~text\nСвязанные задачи: #708").valid, false);
  assert.equal(parseLinkedIssues("``Связанные задачи: #708``").valid, false);
  assert.equal(parseLinkedIssues("    Связанные задачи: #708").valid, false);
  assert.equal(parseLinkedIssues("<!--\nСвязанные задачи: #708\n-->").valid, false);
  assert.equal(parseLinkedIssues("<!--\nСвязанные задачи: #708").valid, false);
  assert.equal(
    parseLinkedIssues("Связанные задачи: не требуется\n```text`x\nСвязанные задачи: #708").valid,
    false,
  );
  const linkedStagingPr708 = { number: 614, body: "Связанные задачи: #708" };
  const linkedStagingPr708And712 = { number: 615, body: "Связанные задачи: #712, #708" };
  const linkedStagingPrWithoutIssues = { number: 616, body: "Связанные задачи: не требуется" };
  assert.deepEqual(
    validateLinkedIssuesLineage({
      body: "Связанные задачи: #708, #712",
      stagingPrNumbers: [614, 615],
      stagingPrs: [linkedStagingPr708, linkedStagingPr708And712],
      stagingPrFetchErrors: [],
    }),
    [],
  );
  assert.deepEqual(
    validateLinkedIssuesLineage({
      body: "Связанные задачи: #708",
      stagingPrNumbers: [614, 616],
      stagingPrs: [linkedStagingPr708, linkedStagingPrWithoutIssues],
      stagingPrFetchErrors: [],
    }),
    [],
  );
  assert.deepEqual(
    validateLinkedIssuesLineage({
      body: "Связанные задачи: не требуется",
      stagingPrNumbers: [616],
      stagingPrs: [linkedStagingPrWithoutIssues],
      stagingPrFetchErrors: [],
    }),
    [],
  );
  assert.match(
    validateLinkedIssuesLineage({
      body: "Связанные задачи: #708",
      stagingPrNumbers: [614, 615],
      stagingPrs: [linkedStagingPr708, linkedStagingPr708And712],
      stagingPrFetchErrors: [],
    }).join("\n"),
    /отсутствуют #712/,
  );
  assert.match(
    validateLinkedIssuesLineage({
      body: "Связанные задачи: #708, #999",
      stagingPrNumbers: [614],
      stagingPrs: [linkedStagingPr708],
      stagingPrFetchErrors: [],
    }).join("\n"),
    /лишние #999/,
  );
  assert.match(
    validateLinkedIssuesLineage({
      body: "Описание без поля",
      stagingPrNumbers: [614],
      stagingPrs: [linkedStagingPr708],
      stagingPrFetchErrors: [],
    }).join("\n"),
    /PR в `main`: отсутствует строка/,
  );
  assert.match(
    validateLinkedIssuesLineage({
      body: "Связанные задачи: #708",
      stagingPrNumbers: [614],
      stagingPrs: [{ number: 614, body: "Описание без поля" }],
      stagingPrFetchErrors: [],
    }).join("\n"),
    /Связанный staging PR #614: отсутствует строка/,
  );
  assert.deepEqual(
    validateLinkedIssuesLineage({
      body: "Связанные задачи: #708",
      stagingPrNumbers: [614, 615],
      stagingPrs: [linkedStagingPr708],
      stagingPrFetchErrors: [{ number: 615, error: new Error("503") }],
    }),
    [],
  );
  assert.equal(
    normalizePatch("index aaa..bbb 100644\n@@ -1,3 +1,3 @@\n context\n-old\n+new"),
    "-old\n+new",
  );
  assert.equal(fileStatusFromNameStatus("A"), "added");
  assert.equal(fileStatusFromNameStatus("M"), "modified");
  assert.equal(fileStatusFromNameStatus("D"), "removed");
  assert.equal(fileStatusFromNameStatus("R100"), "renamed");

  assert.deepEqual(
    validatePublishLanguage({
      title: "[codex] Исправить проверку процесса релиза",
      body: "## Что изменено\n\n- Проверка описания PR работает на русском языке.\n\nStaging PR: #614\nStaging smoke: https://github.com/Etogerman/Project-1/actions/runs/123\n- `node .github/scripts/release-process-guard.mjs --self-test`",
    }),
    [],
  );

  assert.match(
    validatePublishLanguage({
      title: "[codex] Fix release guard",
      body: "## Summary\n\n- Update release guard.\n\n## Validation\n\n- `node test.js`",
    }).join("\n"),
    /PR title must be written in Russian.*Russian section headings/s,
  );

  assert.match(
    validatePublishLanguage({
      title: "[codex] Исправить release guard",
      body: "## Что изменено\n\n- This guard now validates pull request metadata and blocks bad descriptions with English prose.",
    }).join("\n"),
    /too much English prose/,
  );

  const runtimeFiles = [
    {
      filename: "app/Services/Bitrix24ContactSyncService.php",
      patch: "index aaa..bbb 100644\n@@ -10,7 +10,7 @@\n context from main\n-old\n+new",
      status: "modified",
    },
  ];
  const matchingStagingFiles = [{ ...runtimeFiles[0] }];
  const mismatchingStagingFiles = [
    {
      ...runtimeFiles[0],
      patch: "index ccc..ddd 100644\n@@ -99,7 +99,7 @@\n context from staging\n-old\n+different",
    },
  ];
  const matchingStagingFilesWithDifferentContext = [
    {
      ...runtimeFiles[0],
      patch: "index ccc..ddd 100644\n@@ -99,7 +99,7 @@\n context from staging\n-old\n+new",
    },
  ];
  const stagingPr = {
    number: 614,
    base: { ref: "staging" },
    merged_at: "2026-06-21T20:00:00Z",
    merge_commit_sha: "2c2097fd20d0aede9124c99fdec293f17c4d7eb1",
    body: "Описание staging PR на русском языке.\n\nСвязанные задачи: #708",
  };
  const nextStagingPr = {
    number: 615,
    base: { ref: "staging" },
    merged_at: "2026-06-21T21:00:00Z",
    merge_commit_sha: "3c2097fd20d0aede9124c99fdec293f17c4d7eb2",
    body: "Описание staging PR на русском языке.\n\nСвязанные задачи: #708",
  };
  const successfulSmokeRun = {
    name: STAGING_SMOKE_WORKFLOW_NAME,
    head_branch: "staging",
    head_sha: stagingPr.merge_commit_sha,
    status: "completed",
    conclusion: "success",
  };

  assert.deepEqual(
    evaluatePullRequest({
      baseRef: "staging",
      title: "[codex] Исправить синхронизацию Bitrix24",
      body: "Описание PR на русском языке.",
      files: runtimeFiles,
    }).failures,
    [],
  );

  assert.deepEqual(
    evaluatePullRequest({
      baseRef: "main",
      title: "[codex] Исправить синхронизацию Bitrix24",
      body: "Описание PR на русском языке.\n\nСвязанные задачи: #708\nStaging PRs: #614, #615\nStaging smoke: https://github.com/Etogerman/Project-1/actions/runs/123",
      files: [
        {
          ...runtimeFiles[0],
          patch: "index aaa..bbb 100644\n@@ -10,7 +10,8 @@\n context from main\n-old\n+new\n+next",
        },
      ],
      repository: { owner: "Etogerman", repo: "Project-1" },
      stagingPrs: [stagingPr, nextStagingPr],
      stagingPrFiles: [
        matchingStagingFilesWithDifferentContext[0],
        {
          ...runtimeFiles[0],
          patch: "index ddd..eee 100644\n@@ -100,6 +100,7 @@\n context from staging\n+next",
        },
      ],
      currentPrCommitShas: ["1111111111111111111111111111111111111111"],
      stagingSmokeRun: {
        ...successfulSmokeRun,
        head_sha: nextStagingPr.merge_commit_sha,
      },
    }).failures,
    [],
  );

  assert.deepEqual(
    evaluatePullRequest({
      baseRef: "main",
      title: "[codex] Исправить cutover автоответчика",
      body: "Описание PR на русском языке.\n\nСвязанные задачи: #708\nStaging PRs: #614, #615\nStaging smoke: https://github.com/Etogerman/Project-1/actions/runs/123",
      files: [
        {
          filename: ".env.example",
          patch: "index aaa..bbb 100644\n@@ -1,2 +1,3 @@\n context\n+BOT_LEGACY_AUTO_REPLY_RULES_ENABLED=false",
          status: "modified",
        },
      ],
      repository: { owner: "Etogerman", repo: "Project-1" },
      stagingPrs: [stagingPr, nextStagingPr],
      stagingPrFiles: [
        {
          filename: ".env.example",
          patch: "index aaa..bbb 100644\n@@ -1,2 +1,3 @@\n context\n+BOT_LEGACY_AUTO_REPLY_RULES_ENABLED=true",
          status: "modified",
        },
        {
          filename: ".env.example",
          patch: "index bbb..ccc 100644\n@@ -1,3 +1,3 @@\n context\n-BOT_LEGACY_AUTO_REPLY_RULES_ENABLED=true\n+BOT_LEGACY_AUTO_REPLY_RULES_ENABLED=false",
          status: "modified",
        },
      ],
      currentPrCommitShas: ["1111111111111111111111111111111111111111"],
      stagingSmokeRun: {
        ...successfulSmokeRun,
        head_sha: nextStagingPr.merge_commit_sha,
      },
    }).failures,
    [],
  );

  assert.deepEqual(
    evaluatePullRequest({
      baseRef: "main",
      title: "[codex] Исправить синхронизацию Bitrix24",
      body: "Описание PR на русском языке.\n\nСвязанные задачи: #708\nStaging PRs: #614, #615\nStaging smoke: https://github.com/Etogerman/Project-1/actions/runs/123",
      files: runtimeFiles,
      repository: { owner: "Etogerman", repo: "Project-1" },
      stagingPrs: [stagingPr, nextStagingPr],
      stagingPrFiles: [
        matchingStagingFiles[0],
        {
          filename: "app/Services/AlreadyReleasedService.php",
          patch: "index aaa..bbb 100644\n@@ -1,3 +1,3 @@\n-old\n+already released",
          status: "modified",
        },
      ],
      fileContentSnapshots: new Map([
        [
          "app/Services/Bitrix24ContactSyncService.php",
          {
            base: { exists: true, content: Buffer.from("old") },
            head: { exists: true, content: Buffer.from("new") },
            staging: { exists: true, content: Buffer.from("new") },
          },
        ],
        [
          "app/Services/AlreadyReleasedService.php",
          {
            base: { exists: true, content: Buffer.from("already released") },
            head: { exists: true, content: Buffer.from("already released") },
            staging: { exists: true, content: Buffer.from("already released") },
          },
        ],
      ]),
      currentPrCommitShas: ["1111111111111111111111111111111111111111"],
      stagingSmokeRun: {
        ...successfulSmokeRun,
        head_sha: nextStagingPr.merge_commit_sha,
      },
    }).failures,
    [],
  );

  assert.match(
    evaluatePullRequest({
      baseRef: "main",
      title: "[codex] Исправить синхронизацию Bitrix24",
      body: "Описание PR на русском языке.\n\nСвязанные задачи: #708\nStaging PR: #614\nStaging smoke: https://github.com/Etogerman/Project-1/actions/runs/123",
      files: runtimeFiles,
      stagingPr,
      stagingPrFiles: matchingStagingFiles,
      fileContentSnapshots: new Map([
        [
          "app/Services/Bitrix24ContactSyncService.php",
          {
            base: { exists: true, content: Buffer.from("old") },
            head: { exists: true, content: Buffer.from("wrong") },
            staging: { exists: true, content: Buffer.from("new") },
          },
        ],
      ]),
      currentPrCommitShas: ["1111111111111111111111111111111111111111"],
      stagingSmokeRun: successfulSmokeRun,
    }).failures.join("\n"),
    /content mismatch/,
  );

  assert.match(
    evaluatePullRequest({
      baseRef: "main",
      title: "[codex] Исправить синхронизацию Bitrix24",
      body: "Описание PR на русском языке.",
      files: runtimeFiles,
    }).failures.join("\n"),
    /Staging PR/,
  );

  assert.deepEqual(
    evaluatePullRequest({
      baseRef: "main",
      title: "[codex] Исправить синхронизацию Bitrix24",
      body: "Описание PR на русском языке.\n\nСвязанные задачи: #708\nStaging PR: #614\nStaging smoke: https://github.com/Etogerman/Project-1/actions/runs/123",
      files: runtimeFiles,
      repository: { owner: "Etogerman", repo: "Project-1" },
      stagingPr,
      stagingPrFiles: matchingStagingFilesWithDifferentContext,
      currentPrCommitShas: ["1111111111111111111111111111111111111111"],
      stagingSmokeRun: successfulSmokeRun,
    }).failures,
    [],
  );

  assert.match(
    evaluatePullRequest({
      baseRef: "main",
      title: "[codex] Исправить синхронизацию Bitrix24",
      body: "Описание PR на русском языке.\n\nСвязанные задачи: #708\nStaging PR: #614\nStaging smoke: https://github.com/Etogerman/Project-1/actions/runs/123",
      files: runtimeFiles,
      stagingPr: {
        ...stagingPr,
        merged_at: null,
      },
      stagingPrFiles: matchingStagingFiles,
      currentPrCommitShas: ["1111111111111111111111111111111111111111"],
      stagingSmokeRun: successfulSmokeRun,
    }).failures.join("\n"),
    /must be merged/,
  );

  assert.match(
    evaluatePullRequest({
      baseRef: "main",
      title: "[codex] Исправить синхронизацию Bitrix24",
      body: "Описание PR на русском языке.\n\nСвязанные задачи: #708\nStaging PR: #614\nStaging smoke: https://github.com/Etogerman/Project-1/actions/runs/123",
      files: runtimeFiles,
      stagingPr,
      stagingPrFiles: mismatchingStagingFiles,
      currentPrCommitShas: ["1111111111111111111111111111111111111111"],
      stagingSmokeRun: successfulSmokeRun,
    }).failures.join("\n"),
    /must match validated runtime file content/,
  );

  assert.match(
    evaluatePullRequest({
      baseRef: "main",
      title: "[codex] Исправить синхронизацию Bitrix24",
      body: "Описание PR на русском языке.\n\nСвязанные задачи: #708\nStaging PR: #614\nStaging smoke: https://github.com/Etogerman/Project-1/actions/runs/123",
      files: runtimeFiles,
      stagingPr,
      stagingPrFiles: matchingStagingFiles,
      currentPrCommitShas: [stagingPr.merge_commit_sha],
      stagingSmokeRun: successfulSmokeRun,
    }).failures.join("\n"),
    /must use validated diff/,
  );

  assert.match(
    evaluatePullRequest({
      baseRef: "main",
      title: "[codex] Исправить синхронизацию Bitrix24",
      body: "Описание PR на русском языке.\n\nСвязанные задачи: #708\nStaging PR: #614\nStaging smoke: https://example.com/run",
      files: runtimeFiles,
      stagingPr,
      stagingPrFiles: matchingStagingFiles,
      currentPrCommitShas: ["1111111111111111111111111111111111111111"],
      stagingSmokeRun: successfulSmokeRun,
    }).failures.join("\n"),
    /GitHub Actions run URL/,
  );

  assert.match(
    evaluatePullRequest({
      baseRef: "main",
      title: "[codex] Исправить синхронизацию Bitrix24",
      body: "Описание PR на русском языке.\n\nСвязанные задачи: #708\nStaging PR: #614\nStaging smoke: https://github.com/Etogerman/Project-1/actions/runs/123",
      files: runtimeFiles,
      stagingPr,
      stagingPrFiles: matchingStagingFiles,
      currentPrCommitShas: ["1111111111111111111111111111111111111111"],
      stagingSmokeRun: {
        ...successfulSmokeRun,
        head_sha: "1111111111111111111111111111111111111111",
      },
    }).failures.join("\n"),
    /must verify final staging merge commit/,
  );

  console.log("release-process-guard self-test passed");
}

async function run() {
  const token = process.env.GITHUB_TOKEN;
  const repository = process.env.GITHUB_REPOSITORY;
  const pullNumber = Number.parseInt(process.env.PR_NUMBER || "", 10);

  if (!token) {
    throw new Error("GITHUB_TOKEN is required.");
  }

  if (!repository) {
    throw new Error("GITHUB_REPOSITORY is required.");
  }

  if (!Number.isInteger(pullNumber)) {
    throw new Error("PR_NUMBER is required.");
  }

  const { owner, repo } = parseRepository(repository);
  const pullRequest = await githubRequest(`/repos/${owner}/${repo}/pulls/${pullNumber}`, token);
  const files = await listPullRequestFiles({ owner, repo, pullNumber, token });
  const initialResult = evaluatePullRequest({
    baseRef: pullRequest.base.ref,
    title: pullRequest.title || "",
    body: pullRequest.body || "",
    files,
    repository: { owner, repo },
  });

  const stagingPrs = [];
  const stagingPrFetchErrors = [];
  let stagingPrFiles = [];
  const stagingPrFilesFetchErrors = [];
  let fileContentSnapshots = null;
  let currentPrCommitShas = [];
  let stagingSmokeRun = null;
  let stagingSmokeRunFetchError = null;

  if (pullRequest.base.ref === "main" && initialResult.runtimeFiles.length > 0) {
    currentPrCommitShas = await listPullRequestCommitShas({ owner, repo, pullNumber, token });

    for (const stagingPrNumber of initialResult.stagingPrNumbers) {
      let stagingPr = null;

      try {
        stagingPr = await githubRequest(`/repos/${owner}/${repo}/pulls/${stagingPrNumber}`, token);
        stagingPrs.push(stagingPr);
      } catch (error) {
        stagingPrFetchErrors.push({ number: stagingPrNumber, error });
      }

      if (stagingPr) {
        try {
          stagingPrFiles = stagingPrFiles.concat(
            await listPullRequestFiles({ owner, repo, pullNumber: stagingPr.number, token }),
          );
        } catch (error) {
          stagingPrFilesFetchErrors.push({ number: stagingPr.number, error });
        }
      }
    }

    if (
      initialResult.stagingSmokeRunReference &&
      initialResult.stagingSmokeRunReference.owner === owner &&
      initialResult.stagingSmokeRunReference.repo === repo
    ) {
      try {
        stagingSmokeRun = await githubRequest(
          `/repos/${owner}/${repo}/actions/runs/${initialResult.stagingSmokeRunReference.runId}`,
          token,
        );
      } catch (error) {
        stagingSmokeRunFetchError = error;
      }
    }
  }

  if (
    pullRequest.base.ref === "main"
    && initialResult.runtimeFiles.length > 0
    && stagingSmokeRun?.head_sha
    && stagingPrFiles.length > 0
  ) {
    const snapshotFilenames = [
      ...new Set(
        [...initialResult.runtimeFiles, ...stagingPrFiles]
          .filter((file) => !isProcessOnlyFile(file.filename))
          .map((file) => file.filename),
      ),
    ].sort();

    fileContentSnapshots = buildFileContentSnapshots({
      filenames: snapshotFilenames,
      baseSha: pullRequest.base.sha,
      headSha: pullRequest.head.sha,
      stagingSha: stagingSmokeRun.head_sha,
    });
  }

  const result = evaluatePullRequest({
    baseRef: pullRequest.base.ref,
    title: pullRequest.title || "",
    body: pullRequest.body || "",
    files,
    repository: { owner, repo },
    stagingPrs,
    stagingPrFetchErrors,
    stagingPrFiles,
    stagingPrFilesFetchErrors,
    fileContentSnapshots,
    currentPrCommitShas,
    stagingSmokeRun,
    stagingSmokeRunFetchError,
  });

  if (result.failures.length > 0) {
    console.error("Release process guard failed.");
    console.error(`Base branch: ${pullRequest.base.ref}`);
    console.error(`Runtime files: ${result.runtimeFiles.map((file) => file.filename).join(", ") || "none"}`);
    result.failures.forEach(printFailure);
    process.exit(1);
  }

  console.log("Release process guard passed.");
  console.log(`Base branch: ${pullRequest.base.ref}`);
  console.log(`Runtime files: ${result.runtimeFiles.length}`);

  if (result.stagingPrNumbers.length > 0) {
    console.log(`Staging PRs: #${result.stagingPrNumbers.join(", #")}`);
  }
}

if (process.argv.includes("--self-test")) {
  runSelfTest();
} else if (process.argv.includes("--local-pr")) {
  runLocalPr();
} else {
  await run();
}
