#!/usr/bin/env node
import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";

const PROCESS_ONLY_FILE_PATTERNS = [
  /^AGENTS\.md$/,
  /^README\.md$/,
  /^docs\//,
  /^\.github\/copilot-instructions\.md$/,
  /^\.github\/instructions\/.*\.instructions\.md$/,
  /^\.github\/scripts\/release-process-guard\.mjs$/,
  /^\.github\/workflows\/release-process-guard\.ya?ml$/,
  /^\.agents\/skills\//,
  /(^|\/)[^/]+\.md$/,
];

const STAGING_SMOKE_WORKFLOW_NAME = "Staging Post-Deploy Smoke";
const CYRILLIC_PATTERN = /[А-Яа-яЁё]/;
const LATIN_PATTERN = /[A-Za-z]/;
const ENGLISH_PR_HEADING_PATTERN =
  /^\s{0,3}#{1,6}\s*(Summary|Overview|Description|Why|Validation|Testing|Tests|Checks|Delivery note|Implementation|Changes|Root cause|Impact)\s*$/gim;
const ALLOWED_TECHNICAL_TERMS_PATTERN =
  /\b(codex|PR|MCP|CI|UI|URL|API|JSON|YAML|TOML|PHP|SQL|HTTP|HTTPS|Docker|Laravel|Boost|Filament|Livewire|Bitrix24|AB Connector|Staging PR|Staging smoke|Staging Post-Deploy Smoke|rev-check|public smoke|admin smoke|dev-only|validated diff|clean-main-PR|workflow|runtime|main|staging|draft|ready|merge|commit|branch|pull request|release-process-guard|php-artisan-test)\b/gi;

function isProcessOnlyFile(filename) {
  return PROCESS_ONLY_FILE_PATTERNS.some((pattern) => pattern.test(filename));
}

function extractStagingPrNumber(body) {
  const patterns = [
    /(?:^|\n)\s*Staging\s+PR\s*:\s*(?:https:\/\/github\.com\/[^/\s]+\/[^/\s]+\/pull\/)?#?(\d+)\b/i,
    /(?:^|\n)\s*staging-pr\s*:\s*#?(\d+)\b/i,
  ];

  for (const pattern of patterns) {
    const match = body.match(pattern);

    if (match) {
      return Number.parseInt(match[1], 10);
    }
  }

  return null;
}

function extractStagingSmokeRunUrl(body) {
  const lineMatch = body.match(/(?:^|\n)\s*Staging\s+smoke\s*:\s*(.+)/i);

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
        return true;
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

function compareValidatedFiles(currentFiles, stagingFiles) {
  const current = new Map(currentFiles.map((file) => [file.filename, fileFingerprint(file)]));
  const staging = new Map(stagingFiles.map((file) => [file.filename, fileFingerprint(file)]));
  const failures = [];

  for (const filename of current.keys()) {
    if (!staging.has(filename)) {
      failures.push(`unexpected file in main PR: ${filename}`);
    }
  }

  for (const [filename, stagingFingerprint] of staging.entries()) {
    const currentFingerprint = current.get(filename);

    if (!currentFingerprint) {
      failures.push(`missing file from staging PR: ${filename}`);
      continue;
    }

    if (currentFingerprint.status !== stagingFingerprint.status) {
      failures.push(
        `status mismatch for ${filename}: expected ${stagingFingerprint.status}, got ${currentFingerprint.status}`,
      );
    }

    if (currentFingerprint.patch !== stagingFingerprint.patch) {
      failures.push(`patch mismatch for ${filename}`);
    }

    if (currentFingerprint.previousFilename !== stagingFingerprint.previousFilename) {
      failures.push(`rename source mismatch for ${filename}`);
    }
  }

  return failures;
}

function stripTechnicalMarkdown(text) {
  return text
    .replace(/```[\s\S]*?```/g, " ")
    .replace(/`[^`]*`/g, " ")
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
  const readableTitle = title.replace(/^\s*\[codex\]\s*/i, "").trim();
  const readableBody = stripTechnicalMarkdown(body);
  const readableText = `${stripTechnicalMarkdown(readableTitle)}\n${readableBody}`;
  const englishHeadings = [...body.matchAll(ENGLISH_PR_HEADING_PATTERN)].map((match) => match[1]);
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
  stagingPrFiles = [],
  stagingPrFilesFetchError = null,
  currentPrCommitShas = [],
  stagingSmokeRun = null,
  stagingSmokeRunFetchError = null,
}) {
  const failures = [];
  const { runtimeFiles } = summarizeFiles(files);
  const stagingPrNumber = extractStagingPrNumber(body);
  const stagingSmokeRunUrl = extractStagingSmokeRunUrl(body);
  const stagingSmokeRunReference = parseGitHubActionsRunUrl(stagingSmokeRunUrl);

  failures.push(...validatePublishLanguage({ title, body }));

  if (baseRef === "staging") {
    return { failures, runtimeFiles, stagingPrNumber, stagingSmokeRunReference };
  }

  if (baseRef !== "main") {
    failures.push("Release Process Guard supports PRs to `staging` or `main` only.");
    return { failures, runtimeFiles, stagingPrNumber, stagingSmokeRunReference };
  }

  if (runtimeFiles.length === 0) {
    return { failures, runtimeFiles, stagingPrNumber, stagingSmokeRunReference };
  }

  if (!stagingPrNumber) {
    failures.push("Main PRs with runtime changes must include `Staging PR: #NNN` in the PR body.");
  }

  if (!stagingSmokeRunUrl) {
    failures.push("Main PRs with runtime changes must include `Staging smoke: https://...` in the PR body.");
  } else if (!stagingSmokeRunReference) {
    failures.push("`Staging smoke` must link to a GitHub Actions run URL.");
  } else if (repository && (stagingSmokeRunReference.owner !== repository.owner || stagingSmokeRunReference.repo !== repository.repo)) {
    failures.push("`Staging smoke` must link to a GitHub Actions run in this repository.");
  }

  if (stagingPrFetchError) {
    failures.push(`Referenced staging PR #${stagingPrNumber} could not be loaded: ${stagingPrFetchError.message}`);
  }

  if (stagingPr) {
    if (stagingPr.base?.ref !== "staging") {
      failures.push(`Referenced staging PR #${stagingPr.number} must target \`staging\`.`);
    }

    if (!stagingPr.merged_at) {
      failures.push(`Referenced staging PR #${stagingPr.number} must be merged before opening a runtime PR to \`main\`.`);
    }

    if (!stagingPr.merge_commit_sha) {
      failures.push(`Referenced staging PR #${stagingPr.number} must expose a merge commit SHA.`);
    } else if (currentPrCommitShas.includes(stagingPr.merge_commit_sha)) {
      failures.push(
        `Current main PR must use validated diff from staging PR #${stagingPr.number}, not include staging merge commit ${stagingPr.merge_commit_sha}.`,
      );
    }

    if (stagingPrFilesFetchError) {
      failures.push(
        `Files from staging PR #${stagingPr.number} could not be loaded: ${stagingPrFilesFetchError.message}`,
      );
    } else if (stagingPrFiles.length === 0) {
      failures.push(`Referenced staging PR #${stagingPr.number} must expose changed files.`);
    } else {
      const fileFailures = compareValidatedFiles(files, stagingPrFiles);

      if (fileFailures.length > 0) {
        failures.push(
          `Current main PR must match validated file content from staging PR #${stagingPr.number}: ${fileFailures.join("; ")}`,
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

    if (stagingPr?.merge_commit_sha && stagingSmokeRun.head_sha !== stagingPr.merge_commit_sha) {
      failures.push(
        `Staging smoke run must verify staging merge commit ${stagingPr.merge_commit_sha}, got ${stagingSmokeRun.head_sha}.`,
      );
    }
  }

  return { failures, runtimeFiles, stagingPrNumber, stagingSmokeRunReference };
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
}

function runSelfTest() {
  assert.equal(isProcessOnlyFile("docs/release.md"), true);
  assert.equal(isProcessOnlyFile(".github/copilot-instructions.md"), true);
  assert.equal(isProcessOnlyFile(".github/workflows/release-process-guard.yml"), true);
  assert.equal(isProcessOnlyFile(".agents/skills/ab-connector-skill-authoring/agents/openai.yaml"), true);
  assert.equal(isProcessOnlyFile("app/Services/Bitrix24ContactSyncService.php"), false);
  assert.deepEqual(parseGitHubActionsRunUrl("https://github.com/Etogerman/Project-1/actions/runs/123"), {
    owner: "Etogerman",
    repo: "Project-1",
    runId: 123,
  });
  assert.equal(extractStagingSmokeRunUrl("Staging smoke: [run](https://github.com/Etogerman/Project-1/actions/runs/123)"), "https://github.com/Etogerman/Project-1/actions/runs/123");
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
      body: "Описание PR на русском языке.\n\nStaging PR: #614\nStaging smoke: https://github.com/Etogerman/Project-1/actions/runs/123",
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
      body: "Описание PR на русском языке.\n\nStaging PR: #614\nStaging smoke: https://github.com/Etogerman/Project-1/actions/runs/123",
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
      body: "Описание PR на русском языке.\n\nStaging PR: #614\nStaging smoke: https://github.com/Etogerman/Project-1/actions/runs/123",
      files: runtimeFiles,
      stagingPr,
      stagingPrFiles: mismatchingStagingFiles,
      currentPrCommitShas: ["1111111111111111111111111111111111111111"],
      stagingSmokeRun: successfulSmokeRun,
    }).failures.join("\n"),
    /must match validated file content/,
  );

  assert.match(
    evaluatePullRequest({
      baseRef: "main",
      title: "[codex] Исправить синхронизацию Bitrix24",
      body: "Описание PR на русском языке.\n\nStaging PR: #614\nStaging smoke: https://github.com/Etogerman/Project-1/actions/runs/123",
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
      body: "Описание PR на русском языке.\n\nStaging PR: #614\nStaging smoke: https://example.com/run",
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
      body: "Описание PR на русском языке.\n\nStaging PR: #614\nStaging smoke: https://github.com/Etogerman/Project-1/actions/runs/123",
      files: runtimeFiles,
      stagingPr,
      stagingPrFiles: matchingStagingFiles,
      currentPrCommitShas: ["1111111111111111111111111111111111111111"],
      stagingSmokeRun: {
        ...successfulSmokeRun,
        head_sha: "1111111111111111111111111111111111111111",
      },
    }).failures.join("\n"),
    /must verify staging merge commit/,
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

  let stagingPr = null;
  let stagingPrFetchError = null;
  let stagingPrFiles = [];
  let stagingPrFilesFetchError = null;
  let currentPrCommitShas = [];
  let stagingSmokeRun = null;
  let stagingSmokeRunFetchError = null;

  if (pullRequest.base.ref === "main" && initialResult.runtimeFiles.length > 0) {
    currentPrCommitShas = await listPullRequestCommitShas({ owner, repo, pullNumber, token });

    if (initialResult.stagingPrNumber) {
      try {
        stagingPr = await githubRequest(`/repos/${owner}/${repo}/pulls/${initialResult.stagingPrNumber}`, token);
      } catch (error) {
        stagingPrFetchError = error;
      }

      if (stagingPr) {
        try {
          stagingPrFiles = await listPullRequestFiles({ owner, repo, pullNumber: stagingPr.number, token });
        } catch (error) {
          stagingPrFilesFetchError = error;
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

  const result = evaluatePullRequest({
    baseRef: pullRequest.base.ref,
    title: pullRequest.title || "",
    body: pullRequest.body || "",
    files,
    repository: { owner, repo },
    stagingPr,
    stagingPrFetchError,
    stagingPrFiles,
    stagingPrFilesFetchError,
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

  if (result.stagingPrNumber) {
    console.log(`Staging PR: #${result.stagingPrNumber}`);
  }
}

if (process.argv.includes("--self-test")) {
  runSelfTest();
} else if (process.argv.includes("--local-pr")) {
  runLocalPr();
} else {
  await run();
}
