#!/usr/bin/env node
import assert from "node:assert/strict";

const PROCESS_ONLY_FILE_PATTERNS = [
  /^AGENTS\.md$/,
  /^README\.md$/,
  /^docs\//,
  /^\.github\/copilot-instructions\.md$/,
  /^\.github\/instructions\/.*\.instructions\.md$/,
  /^\.github\/scripts\/release-process-guard\.mjs$/,
  /^\.github\/workflows\/release-process-guard\.ya?ml$/,
  /(^|\/)[^/]+\.md$/,
];

const STAGING_SMOKE_WORKFLOW_NAME = "Staging Post-Deploy Smoke";

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

function evaluatePullRequest({
  baseRef,
  body,
  files,
  repository = null,
  stagingPr = null,
  stagingPrFetchError = null,
  currentPrCommitShas = [],
  stagingSmokeRun = null,
  stagingSmokeRunFetchError = null,
}) {
  const failures = [];
  const { runtimeFiles } = summarizeFiles(files);
  const stagingPrNumber = extractStagingPrNumber(body);
  const stagingSmokeRunUrl = extractStagingSmokeRunUrl(body);
  const stagingSmokeRunReference = parseGitHubActionsRunUrl(stagingSmokeRunUrl);

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
    } else if (!currentPrCommitShas.includes(stagingPr.merge_commit_sha)) {
      failures.push(
        `Current main PR must include staging merge commit ${stagingPr.merge_commit_sha} from PR #${stagingPr.number}.`,
      );
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

    files.push(...batch.map((file) => ({ filename: file.filename })));

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

function runSelfTest() {
  assert.equal(isProcessOnlyFile("docs/release.md"), true);
  assert.equal(isProcessOnlyFile(".github/copilot-instructions.md"), true);
  assert.equal(isProcessOnlyFile(".github/workflows/release-process-guard.yml"), true);
  assert.equal(isProcessOnlyFile("app/Services/Bitrix24ContactSyncService.php"), false);
  assert.deepEqual(parseGitHubActionsRunUrl("https://github.com/Etogerman/Project-1/actions/runs/123"), {
    owner: "Etogerman",
    repo: "Project-1",
    runId: 123,
  });
  assert.equal(extractStagingSmokeRunUrl("Staging smoke: [run](https://github.com/Etogerman/Project-1/actions/runs/123)"), "https://github.com/Etogerman/Project-1/actions/runs/123");

  const runtimeFiles = [{ filename: "app/Services/Bitrix24ContactSyncService.php" }];
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
    evaluatePullRequest({ baseRef: "staging", body: "", files: runtimeFiles }).failures,
    [],
  );

  assert.match(
    evaluatePullRequest({ baseRef: "main", body: "", files: runtimeFiles }).failures.join("\n"),
    /Staging PR/,
  );

  assert.deepEqual(
    evaluatePullRequest({
      baseRef: "main",
      body: "Staging PR: #614\nStaging smoke: https://github.com/Etogerman/Project-1/actions/runs/123",
      files: runtimeFiles,
      repository: { owner: "Etogerman", repo: "Project-1" },
      stagingPr,
      currentPrCommitShas: [stagingPr.merge_commit_sha],
      stagingSmokeRun: successfulSmokeRun,
    }).failures,
    [],
  );

  assert.match(
    evaluatePullRequest({
      baseRef: "main",
      body: "Staging PR: #614\nStaging smoke: https://github.com/Etogerman/Project-1/actions/runs/123",
      files: runtimeFiles,
      stagingPr: {
        ...stagingPr,
        merged_at: null,
      },
      currentPrCommitShas: [stagingPr.merge_commit_sha],
      stagingSmokeRun: successfulSmokeRun,
    }).failures.join("\n"),
    /must be merged/,
  );

  assert.match(
    evaluatePullRequest({
      baseRef: "main",
      body: "Staging PR: #614\nStaging smoke: https://github.com/Etogerman/Project-1/actions/runs/123",
      files: runtimeFiles,
      stagingPr,
      currentPrCommitShas: ["1111111111111111111111111111111111111111"],
      stagingSmokeRun: successfulSmokeRun,
    }).failures.join("\n"),
    /must include staging merge commit/,
  );

  assert.match(
    evaluatePullRequest({
      baseRef: "main",
      body: "Staging PR: #614\nStaging smoke: https://example.com/run",
      files: runtimeFiles,
      stagingPr,
      currentPrCommitShas: [stagingPr.merge_commit_sha],
      stagingSmokeRun: successfulSmokeRun,
    }).failures.join("\n"),
    /GitHub Actions run URL/,
  );

  assert.match(
    evaluatePullRequest({
      baseRef: "main",
      body: "Staging PR: #614\nStaging smoke: https://github.com/Etogerman/Project-1/actions/runs/123",
      files: runtimeFiles,
      stagingPr,
      currentPrCommitShas: [stagingPr.merge_commit_sha],
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
    body: pullRequest.body || "",
    files,
    repository: { owner, repo },
  });

  let stagingPr = null;
  let stagingPrFetchError = null;
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
    body: pullRequest.body || "",
    files,
    repository: { owner, repo },
    stagingPr,
    stagingPrFetchError,
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
} else {
  await run();
}
