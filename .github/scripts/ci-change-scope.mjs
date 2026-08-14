#!/usr/bin/env node
import assert from "node:assert/strict";
import { appendFileSync, readFileSync } from "node:fs";

const DOC_ONLY_FILE_PATTERNS = [
  /^AGENTS\.md$/,
  /^README\.md$/,
  /^docs\//,
  /^\.agents\/skills\//,
  /^\.github\/.*\.md$/,
  /(^|\/)[^/]+\.md$/,
];

const PROCESS_ONLY_FILE_PATTERNS = [
  /^\.github\/copilot-instructions\.md$/,
  /^\.github\/instructions\/.*\.instructions\.md$/,
  /^\.github\/scripts\/ci-change-scope\.mjs$/,
  /^\.github\/scripts\/release-process-guard\.mjs$/,
  /^\.github\/scripts\/ab-readiness-check\.mjs$/,
  /^\.github\/scripts\/copilot-feasibility-spike\.mjs$/,
  /^\.github\/scripts\/copilot-merge-readiness\.mjs$/,
  /^\.github\/scripts\/workflow-docs-check\.mjs$/,
  /^\.github\/scripts\/workflow-state-policy\.mjs$/,
  /^\.github\/scripts\/workflow-cycle-store\.mjs$/,
  /^\.github\/scripts\/workflow-spec-review(?:-self-test|-gates|-gates-self-test)?\.mjs$/,
  /^\.github\/workflows\/php-artisan-test\.ya?ml$/,
  /^\.github\/workflows\/release-process-guard\.ya?ml$/,
  /^\.github\/workflows\/ab-readiness-check\.ya?ml$/,
  /^\.github\/workflows\/copilot-feasibility-spike\.ya?ml$/,
  /^\.github\/workflows\/copilot-merge-readiness\.ya?ml$/,
];

function matchesAny(filename, patterns) {
  return patterns.some((pattern) => pattern.test(filename));
}

function classifyFile(filename) {
  if (matchesAny(filename, PROCESS_ONLY_FILE_PATTERNS)) {
    return "process";
  }

  if (matchesAny(filename, DOC_ONLY_FILE_PATTERNS)) {
    return "docs";
  }

  return "runtime";
}

function classifyFiles(files) {
  const normalizedFiles = files
    .map((file) => file.trim())
    .filter(Boolean);

  if (normalizedFiles.length === 0) {
    return {
      scope: "runtime",
      runPhpTests: true,
      reason: "changed files are unknown",
    };
  }

  const classifiedFiles = normalizedFiles.map((filename) => ({
    filename,
    scope: classifyFile(filename),
  }));
  const runtimeFiles = classifiedFiles.filter((file) => file.scope === "runtime");
  const processFiles = classifiedFiles.filter((file) => file.scope === "process");

  if (runtimeFiles.length > 0) {
    return {
      scope: "runtime",
      runPhpTests: true,
      reason: "runtime files changed",
    };
  }

  if (processFiles.length > 0) {
    return {
      scope: "process-only",
      runPhpTests: false,
      reason: "only process or CI files changed",
    };
  }

  return {
    scope: "docs-only",
    runPhpTests: false,
    reason: "only documentation files changed",
  };
}

function writeOutput(result) {
  const lines = [
    `scope=${result.scope}`,
    `run_php_tests=${result.runPhpTests ? "true" : "false"}`,
    `reason=${result.reason}`,
  ];

  if (process.env.GITHUB_OUTPUT) {
    appendFileSync(process.env.GITHUB_OUTPUT, `${lines.join("\n")}\n`);
    return;
  }

  console.log(lines.join("\n"));
}

function selfTest() {
  assert.deepEqual(classifyFiles(["docs/task-delivery-workflow.md"]), {
    scope: "docs-only",
    runPhpTests: false,
    reason: "only documentation files changed",
  });

  assert.deepEqual(classifyFiles([".github/workflows/php-artisan-test.yml"]), {
    scope: "process-only",
    runPhpTests: false,
    reason: "only process or CI files changed",
  });

  assert.deepEqual(classifyFiles([".github/copilot-instructions.md"]), {
    scope: "process-only",
    runPhpTests: false,
    reason: "only process or CI files changed",
  });

  assert.deepEqual(classifyFiles([".github/scripts/workflow-docs-check.mjs"]), {
    scope: "process-only",
    runPhpTests: false,
    reason: "only process or CI files changed",
  });

  assert.deepEqual(classifyFiles([".github/scripts/workflow-spec-review.mjs"]), {
    scope: "process-only",
    runPhpTests: false,
    reason: "only process or CI files changed",
  });

  assert.deepEqual(classifyFiles([".github/scripts/workflow-spec-review-self-test.mjs"]), {
    scope: "process-only",
    runPhpTests: false,
    reason: "only process or CI files changed",
  });
  assert.deepEqual(classifyFiles([".github/scripts/workflow-spec-review-gates.mjs", ".github/scripts/workflow-spec-review-gates-self-test.mjs"]), {
    scope: "process-only",
    runPhpTests: false,
    reason: "only process or CI files changed",
  });

  assert.deepEqual(classifyFiles([".github/instructions/release.instructions.md"]), {
    scope: "process-only",
    runPhpTests: false,
    reason: "only process or CI files changed",
  });

  assert.deepEqual(classifyFiles(["app/Actions/ExampleAction.php"]), {
    scope: "runtime",
    runPhpTests: true,
    reason: "runtime files changed",
  });

  assert.deepEqual(classifyFiles(["docs/task-delivery-workflow.md", "routes/web.php"]), {
    scope: "runtime",
    runPhpTests: true,
    reason: "runtime files changed",
  });

  assert.deepEqual(classifyFiles([]), {
    scope: "runtime",
    runPhpTests: true,
    reason: "changed files are unknown",
  });

  console.log("ci-change-scope self-test passed");
}

if (process.argv.includes("--self-test")) {
  selfTest();
} else {
  const changedFilesPath = process.argv[2];

  if (!changedFilesPath) {
    throw new Error("Usage: node .github/scripts/ci-change-scope.mjs <changed-files.txt>");
  }

  writeOutput(classifyFiles(readFileSync(changedFilesPath, "utf8").split("\n")));
}
