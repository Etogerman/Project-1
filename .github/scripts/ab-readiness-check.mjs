#!/usr/bin/env node
import assert from "node:assert/strict";
import {
  analyzeMarkdown,
  stripMarkdownCode,
} from "./lib/markdown-visibility.mjs";
import {
  analyzePullRequestBodyContract,
  analyzePrematureIssueClosing,
  collectClosingIssueReferences,
  SPEC_PR_BODY_FIELDS,
} from "./lib/pr-body-contract.mjs";

const PROCESS_ONLY_FILE_PATTERNS = [
  /^AGENTS\.md$/,
  /^README\.md$/,
  /^docs\//,
  /^\.github\/PULL_REQUEST_TEMPLATE\.md$/,
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
  /^\.github\/scripts\/lib\/pr-body-contract\.mjs$/,
  /^\.github\/scripts\/tests\/.*\.test\.mjs$/,
  /^\.github\/scripts\/copilot-feasibility-spike\.mjs$/,
  /^\.github\/workflows\/copilot-feasibility-spike\.ya?ml$/,
  /^\.github\/scripts\/copilot-merge-readiness\.mjs$/,
  /^\.github\/workflows\/copilot-merge-readiness\.ya?ml$/,
  /^\.agents\/skills\//,
  /(^|\/)[^/]+\.md$/,
];

const STAGING_PROCESS_CI_SYNC_FILE_PATTERNS = [
  /^AGENTS\.md$/,
  /^README\.md$/,
  /^docs\/action-ownership\.md$/,
  /^docs\/agent-docs-lifecycle\.md$/,
  /^docs\/agent-routing\.md$/,
  /^docs\/architecture\.md$/,
  /^docs\/reference\/active-specs\.md$/,
  /^docs\/reference\/environments\.md$/,
  /^docs\/reference\/local-bootstrap\.md$/,
  /^docs\/runbooks\/release-rollback\.md$/,
  /^docs\/runbooks\/test-env\.md$/,
  /^docs\/task-delivery-workflow\.md$/,
  /^\.agents\/skills\/ab-connector-skill-authoring\/(?:SKILL\.md|agents\/openai\.yaml)$/,
  /^\.agents\/skills\/ab-pr-ci-review\/(SKILL\.md|agents\/openai\.yaml)$/,
  /^\.agents\/skills\/ab-spec-workflow\/(SKILL\.md|agents\/openai\.yaml)$/,
  /^\.agents\/skills\/ab-stream-state-resolver\/(SKILL\.md|agents\/openai\.yaml)$/,
  /^\.github\/PULL_REQUEST_TEMPLATE\.md$/,
  /^\.github\/copilot-instructions\.md$/,
  /^\.github\/scripts\/ab-readiness-check\.mjs$/,
  /^\.github\/scripts\/(?:package(?:-lock)?\.json|\.gitignore)$/,
  /^\.github\/scripts\/lib\/markdown-visibility\.mjs$/,
  /^\.github\/scripts\/lib\/pr-body-contract\.mjs$/,
  /^\.github\/scripts\/tests\/.*\.test\.mjs$/,
  /^\.github\/scripts\/ci-change-scope\.mjs$/,
  /^\.github\/scripts\/copilot-feasibility-spike\.mjs$/,
  /^\.github\/scripts\/copilot-merge-readiness\.mjs$/,
  /^\.github\/scripts\/release-process-guard\.mjs$/,
  /^\.github\/workflows\/ab-readiness-check\.ya?ml$/,
  /^\.github\/workflows\/copilot-feasibility-spike\.ya?ml$/,
  /^\.github\/workflows\/copilot-merge-readiness\.ya?ml$/,
  /^\.github\/workflows\/php-artisan-test\.ya?ml$/,
  /^\.github\/workflows\/release-process-guard\.ya?ml$/,
];

const CYRILLIC_PATTERN = /[А-Яа-яЁё]/;
const LATIN_PATTERN = /[A-Za-z]/;
const ENGLISH_PR_HEADING_PATTERN =
  /^(Summary|Overview|Description|Why|Validation|Testing|Tests|Checks|Delivery note|Implementation|Changes|Root cause|Impact|Risks|Rollout)\s*$/i;
const ALLOWED_TECHNICAL_TERMS_PATTERN =
  /\b(codex|Copilot|Copilot CLI|Copilot Requests|CLI|PAT|token|secret|workflow_dispatch|GITHUB_TOKEN|COPILOT_GITHUB_TOKEN|READY_TO_MERGE|BLOCKED|shadow|verdict|merge-readiness|PR|MCP|CI|UI|URL|API|JSON|YAML|TOML|PHP|SQL|HTTP|HTTPS|Docker|Laravel|Boost|Filament|Livewire|Bitrix24|AB Connector|Spec repo|Spec doc|Spec revision|MVP|Staging PRs|Staging PR|Staging smoke|Staging Post-Deploy Smoke|rev-check|public smoke|admin smoke|dev-only|validated diff|clean-main-PR|workflow|runtime|main|staging|draft|ready|merge|commit|branch|pull request|hotfix|release-process-guard|ab-readiness-check|copilot-feasibility-spike|copilot-merge-readiness|php-artisan-test)\b/gi;
function isProcessOnlyFile(filename) {
  return PROCESS_ONLY_FILE_PATTERNS.some((pattern) => pattern.test(filename));
}

function summarizeFiles(files) {
  const runtimeFiles = files.filter((file) => !isProcessOnlyFile(file.filename));

  return {
    runtimeFiles,
    processOnlyFiles: files.filter((file) => isProcessOnlyFile(file.filename)),
  };
}

function isStagingProcessCiSync({ baseRef, runtimeFiles, processOnlyFiles }) {
  return baseRef === "staging"
    && runtimeFiles.length === 0
    && processOnlyFiles.length > 0
    && processOnlyFiles.every((file) => (
      STAGING_PROCESS_CI_SYNC_FILE_PATTERNS.some((pattern) => pattern.test(file.filename))
    ));
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
    failures.push("Заголовок PR должен быть написан на русском языке, кроме технических токенов вроде `[codex]`.");
  }

  if (!CYRILLIC_PATTERN.test(readableBody)) {
    failures.push("Описание PR должно содержать человекочитаемый русский текст.");
  }

  if (englishHeadings.length > 0) {
    failures.push(`Описание PR должно использовать русские заголовки разделов, найдены: ${englishHeadings.join(", ")}.`);
  }

  if (LATIN_PATTERN.test(readableText) && latinCount > Math.max(20, cyrillicCount * 0.25)) {
    failures.push("В заголовке или описании PR слишком много английского текста вне разрешённых технических терминов.");
  }

  return failures;
}

function hasStagingEvidence(body) {
  const visibleBody = analyzeMarkdown(body).visibleFieldText;

  return /(?:^|\n) {0,3}Staging[ \t]+PRs?[ \t]*:[ \t]*(?:.*(?:https:\/\/github\.com\/[^/\s]+\/[^/\s]+\/pull\/)?#?\d+\b)/i.test(visibleBody)
    && /(?:^|\n) {0,3}Staging[ \t]+smoke[ \t]*:[ \t]*(?:.*https:\/\/github\.com\/[^/\s]+\/[^/\s]+\/actions\/runs\/\d+)/i.test(visibleBody);
}

function isSubstantialStream(fields) {
  return /^(да)$/i.test(fields.substantialStream || "")
    || SPEC_PR_BODY_FIELDS.some((field) => Boolean(fields[field.key]));
}

function validateSpecFields(fields) {
  const missing = [];
  const invalid = [];

  for (const field of SPEC_PR_BODY_FIELDS) {
    if (!fields[field.key]) {
      missing.push(`Для существенного stream перед Ready нужно заполнить поле \`${field.label}:\`.`);
    }
  }

  if (fields.specRevision && !/^[0-9a-f]{7,40}$/i.test(fields.specRevision)) {
    invalid.push("Поле `Spec revision:` должно содержать commit hash длиной 7-40 hex-символов.");
  }

  return { missing, invalid };
}

function evaluateReadiness({
  baseRef,
  title = "",
  body = "",
  files = [],
  isDraft = true,
  closingIssueReferences = [],
  commits = [],
}) {
  const failures = [];
  const readinessIssues = [];
  const warnings = [];
  const bodyContract = analyzePullRequestBodyContract(body);
  const { fields } = bodyContract;
  const prematureClosing = analyzePrematureIssueClosing({ closingIssueReferences, commits });
  const { runtimeFiles, processOnlyFiles } = summarizeFiles(files);
  const hasRuntimeFiles = runtimeFiles.length > 0;
  const allowStagingProcessCiSync = isStagingProcessCiSync({ baseRef, runtimeFiles, processOnlyFiles });

  failures.push(...validatePublishLanguage({ title, body }));

  if (!["main", "staging"].includes(baseRef)) {
    failures.push("PR должен быть направлен только в `main` или `staging`.");
  }

  if (!hasRuntimeFiles && baseRef !== "main" && !allowStagingProcessCiSync) {
    failures.push("Документационный или процессный PR должен идти в `main` по docs-only path.");
  }

  if (baseRef === "main" && hasRuntimeFiles && !hasStagingEvidence(body)) {
    failures.push("Runtime PR в `main` должен содержать `Staging PR: #NNN` или `Staging PRs: #NNN, #MMM`, а также `Staging smoke: https://github.com/.../actions/runs/...`.");
  }

  if (prematureClosing.closingIssueReferences.length > 0) {
    const references = prematureClosing.closingIssueReferences
      .map((reference) => reference.url || `#${reference.number || "unknown"}`)
      .join(", ");
    failures.push(`PR содержит GitHub closing relationship (${references}); связанные задачи закрываются только отдельным пользовательским действием после приёмки результата.`);
  }

  if (prematureClosing.commitCommands.length > 0) {
    const commands = prematureClosing.commitCommands
      .map(({ sha, command }) => `${sha.slice(0, 7)}: ${command}`)
      .join(", ");
    failures.push(`Commit messages содержат преждевременные closing commands (${commands}); используйте нейтральное поле \`Связанные задачи:\`.`);
  }

  readinessIssues.push(...bodyContract.errors.map(({ message }) => message));

  if (fields.blockers && !/^отсутствуют$/i.test(fields.blockers)) {
    readinessIssues.push("Поле `Блокеры:` должно быть `отсутствуют` перед Ready.");
  }

  if (fields.authorSelfCheck && !/^выполнена$/i.test(fields.authorSelfCheck)) {
    readinessIssues.push("Поле `Авторская самопроверка:` должно быть `выполнена`.");
  }

  if (fields.changeType && !/^(кодовое|документационное|процессное|hotfix)$/i.test(fields.changeType)) {
    readinessIssues.push("Поле `Тип изменения:` должно быть `кодовое`, `документационное`, `процессное` или `hotfix`.");
  }

  if (fields.substantialStream && !/^(да|нет)$/i.test(fields.substantialStream)) {
    readinessIssues.push("Поле `Существенный stream:` должно быть `да` или `нет`.");
  }

  if (
    fields.deliveryLevel
    && !/^(PR в staging|через staging|до merge в main|документационный путь)$/i.test(fields.deliveryLevel)
  ) {
    readinessIssues.push(
      "Поле `Уровень доставки:` должно быть `PR в staging`, `через staging`, `до merge в main` или `документационный путь`.",
    );
  }

  if (!hasRuntimeFiles && fields.deliveryLevel && !/^документационный путь$/i.test(fields.deliveryLevel)) {
    readinessIssues.push("Для документационного или процессного PR поле `Уровень доставки:` должно быть `документационный путь`.");
  }

  if (hasRuntimeFiles && fields.deliveryLevel && /^документационный путь$/i.test(fields.deliveryLevel)) {
    readinessIssues.push("Runtime PR не может использовать `документационный путь`.");
  }

  if (fields.localMvp && !/^(принят|не требуется)$/i.test(fields.localMvp)) {
    readinessIssues.push("Поле `Локальный MVP:` должно быть `принят` или `не требуется` перед Ready.");
  }

  if (fields.operatorAcceptance && !/^(принята|не требуется)$/i.test(fields.operatorAcceptance)) {
    readinessIssues.push("Поле `Операторская приёмка:` должно быть `принята` или `не требуется` перед Ready.");
  }

  if (fields.acceptedRisk && !/^(отсутствует|принят:\s*.+)$/i.test(fields.acceptedRisk)) {
    readinessIssues.push("Поле `Принятый риск:` должно быть `отсутствует` или `принят: <краткая причина>`.");
  }

  if (isSubstantialStream(fields)) {
    const specFailures = validateSpecFields(fields);
    failures.push(...specFailures.invalid);

    if (isDraft) {
      warnings.push(...specFailures.missing);
    } else {
      failures.push(...specFailures.missing);
    }
  }

  if (isDraft) {
    warnings.push(...readinessIssues);
  } else {
    failures.push(...readinessIssues);
  }

  return {
    failures,
    warnings,
    runtimeFiles,
    processOnlyFiles,
    fields,
  };
}

async function githubRequest(path, token) {
  const response = await fetch(`https://api.github.com${path}`, {
    headers: {
      Accept: "application/vnd.github+json",
      Authorization: `Bearer ${token}`,
      "User-Agent": "project-1-ab-readiness-check",
      "X-GitHub-Api-Version": "2022-11-28",
    },
  });

  if (!response.ok) {
    const body = await response.text();
    throw new Error(`GitHub API request failed: ${response.status} ${response.statusText}\n${body}`);
  }

  return response.json();
}

async function githubGraphqlRequest({ query, variables, token }) {
  const response = await fetch("https://api.github.com/graphql", {
    method: "POST",
    headers: {
      Accept: "application/vnd.github+json",
      Authorization: `Bearer ${token}`,
      "Content-Type": "application/json",
      "User-Agent": "project-1-ab-readiness-check",
    },
    body: JSON.stringify({ query, variables }),
  });

  if (!response.ok) {
    const body = await response.text();
    throw new Error(`GitHub GraphQL request failed: ${response.status} ${response.statusText}\n${body}`);
  }

  const payload = await response.json();

  if (payload.errors?.length > 0) {
    throw new Error(`GitHub GraphQL request failed: ${payload.errors.map((error) => error.message).join("; ")}`);
  }

  return payload.data;
}

async function listPullRequestClosingIssueReferences({ owner, repo, pullNumber, token }) {
  const query = `query ClosingIssueReferences($owner:String!,$repo:String!,$pullNumber:Int!,$after:String){repository(owner:$owner,name:$repo){pullRequest(number:$pullNumber){closingIssuesReferences(first:100,after:$after,userLinkedOnly:false){nodes{number}pageInfo{hasNextPage endCursor}}}}}`;

  return collectClosingIssueReferences(async (after) => {
    const data = await githubGraphqlRequest({
      query,
      variables: { owner, repo, pullNumber, after },
      token,
    });
    const pullRequest = data?.repository?.pullRequest;

    if (!pullRequest) {
      throw new Error(`GitHub GraphQL did not return PR #${pullNumber}.`);
    }

    return pullRequest.closingIssuesReferences;
  });
}

async function listPullRequestCommits({ owner, repo, pullNumber, token }) {
  const commits = [];

  for (let page = 1; ; page += 1) {
    const batch = await githubRequest(
      `/repos/${owner}/${repo}/pulls/${pullNumber}/commits?per_page=100&page=${page}`,
      token,
    );

    commits.push(...batch.map((commit) => ({
      sha: commit.sha || "unknown",
      message: commit.commit?.message || "",
    })));

    if (batch.length < 100) {
      return commits;
    }
  }
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
        status: file.status || "modified",
      })),
    );

    if (batch.length < 100) {
      return files;
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

function parsePullNumber(value) {
  const normalized = String(value || "").trim();

  if (!/^[1-9]\d*$/.test(normalized)) {
    throw new Error("PR_NUMBER must be a positive integer.");
  }

  const pullNumber = Number(normalized);

  if (!Number.isSafeInteger(pullNumber)) {
    throw new Error("PR_NUMBER must be a safe positive integer.");
  }

  return pullNumber;
}

function printFailure(message) {
  console.log(`::error::${message}`);
}

function printWarning(message) {
  console.log(`::warning::${message}`);
}

function runSelfTest() {
  assert.equal(isProcessOnlyFile(".github/scripts/ab-readiness-check.mjs"), true);
  assert.equal(isProcessOnlyFile(".github/workflows/ab-readiness-check.yml"), true);
  assert.equal(isProcessOnlyFile(".github/scripts/copilot-feasibility-spike.mjs"), true);
  assert.equal(isProcessOnlyFile(".github/workflows/copilot-feasibility-spike.yml"), true);
  assert.equal(isProcessOnlyFile(".github/scripts/copilot-merge-readiness.mjs"), true);
  assert.equal(isProcessOnlyFile(".github/workflows/copilot-merge-readiness.yml"), true);
  assert.equal(isProcessOnlyFile(".github/PULL_REQUEST_TEMPLATE.md"), true);
  assert.equal(isProcessOnlyFile("app/Services/Bitrix24ContactSyncService.php"), false);
  assert.equal(parsePullNumber("627"), 627);
  assert.equal(parsePullNumber(" 627 "), 627);
  assert.throws(() => parsePullNumber("123abc"), /positive integer/);
  assert.throws(() => parsePullNumber("0"), /positive integer/);
  assert.throws(() => parsePullNumber(""), /positive integer/);
  const readyProcessBody = [
    "## Что изменено",
    "",
    "Описание на русском языке.",
    "",
    "## Примечание по доставке",
    "",
    "- Тип изменения: процессное",
    "- Существенный stream: нет",
    "- Уровень доставки: документационный путь",
    "- Локальный MVP: не требуется",
    "- Операторская приёмка: не требуется",
    "- Авторская самопроверка: выполнена",
    "- Связанные задачи: не требуется",
    "- Блокеры: отсутствуют",
    "- Принятый риск: отсутствует",
  ].join("\n");

  assert.deepEqual(
    evaluateReadiness({
      baseRef: "main",
      title: "[codex] Уточнить процесс ревью",
      body: readyProcessBody,
      files: [{ filename: ".github/PULL_REQUEST_TEMPLATE.md" }],
      isDraft: false,
    }).failures,
    [],
  );

  assert.match(
    evaluateReadiness({
      baseRef: "main",
      title: "[codex] Уточнить процесс ревью",
      body: readyProcessBody,
      files: [{ filename: ".github/PULL_REQUEST_TEMPLATE.md" }],
      isDraft: false,
      closingIssueReferences: [{ number: 708 }],
      commits: [{ sha: "abcdef1234567890", message: "Closes: #708" }],
    }).failures.join("\n"),
    /closing relationship.*closing commands/s,
  );

  assert.deepEqual(
    evaluateReadiness({
      baseRef: "main",
      title: "[codex] Уточнить процесс ревью",
      body: `${readyProcessBody}\n\nSpec repo:\nSpec doc:\nSpec revision:\n`,
      files: [{ filename: ".github/PULL_REQUEST_TEMPLATE.md" }],
      isDraft: false,
    }).failures,
    [],
  );

  const stagingProcessCiSyncFiles = [
    { filename: ".github/scripts/ab-readiness-check.mjs" },
    { filename: ".github/workflows/ab-readiness-check.yml" },
    { filename: ".github/scripts/ci-change-scope.mjs" },
    { filename: ".github/workflows/php-artisan-test.yml" },
    { filename: ".github/workflows/release-process-guard.yml" },
    { filename: "docs/task-delivery-workflow.md" },
  ];

  assert.deepEqual(
    evaluateReadiness({
      baseRef: "staging",
      title: "[codex] Синхронизировать process CI guard-ы в staging",
      body: readyProcessBody,
      files: stagingProcessCiSyncFiles,
      isDraft: false,
    }).failures,
    [],
  );

  assert.match(
    evaluateReadiness({
      baseRef: "staging",
      title: "[codex] Уточнить процесс ревью",
      body: readyProcessBody,
      files: [{ filename: "docs/unrelated-process-note.md" }],
      isDraft: false,
    }).failures.join("\n"),
    /docs-only path/,
  );

  assert.deepEqual(
    evaluateReadiness({
      baseRef: "main",
      title: "[codex] Уточнить процесс ревью",
      body: `${readyProcessBody}\n\nПример markdown:\n\n\`\`\`md\n## Summary\n\n- Example text.\n\`\`\``,
      files: [{ filename: ".github/PULL_REQUEST_TEMPLATE.md" }],
      isDraft: false,
    }).failures,
    [],
  );

  assert.match(
    evaluateReadiness({
      baseRef: "main",
      title: "[codex] Fix readiness check",
      body: "## Summary\n\n- Update check.",
      files: [{ filename: ".github/PULL_REQUEST_TEMPLATE.md" }],
      isDraft: true,
    }).failures.join("\n"),
    /русском языке.*русские заголовки/s,
  );

  assert.equal(
    evaluateReadiness({
      baseRef: "staging",
      title: "[codex] Исправить синхронизацию Bitrix24",
      body: "Описание PR на русском языке.",
      files: [{ filename: "app/Services/Bitrix24ContactSyncService.php" }],
      isDraft: true,
    }).warnings.length > 0,
    true,
  );

  assert.match(
    evaluateReadiness({
      baseRef: "staging",
      title: "[codex] Исправить синхронизацию Bitrix24",
      body: readyProcessBody
        .replace("процессное", "кодовое")
        .replace("- Существенный stream: нет\n", "")
        .replace("документационный путь", "PR в staging"),
      files: [{ filename: "app/Services/Bitrix24ContactSyncService.php" }],
      isDraft: false,
    }).failures.join("\n"),
    /Существенный stream/,
  );

  assert.match(
    evaluateReadiness({
      baseRef: "staging",
      title: "[codex] Исправить синхронизацию Bitrix24",
      body: readyProcessBody
        .replace("процессное", "кодовое")
        .replace("Существенный stream: нет", "Существенный stream: да")
        .replace("документационный путь", "PR в staging"),
      files: [{ filename: "app/Services/Bitrix24ContactSyncService.php" }],
      isDraft: false,
    }).failures.join("\n"),
    /Spec repo/,
  );

  assert.match(
    evaluateReadiness({
      baseRef: "staging",
      title: "[codex] Исправить синхронизацию Bitrix24",
      body: readyProcessBody.replace("Блокеры: отсутствуют", "Блокеры: ждём тестовую среду"),
      files: [{ filename: "app/Services/Bitrix24ContactSyncService.php" }],
      isDraft: true,
    }).warnings.join("\n"),
    /Блокеры/,
  );

  assert.match(
    evaluateReadiness({
      baseRef: "main",
      title: "[codex] Уточнить процесс ревью",
      body: readyProcessBody.replace("Авторская самопроверка: выполнена", "Авторская самопроверка: выполнено"),
      files: [{ filename: ".github/PULL_REQUEST_TEMPLATE.md" }],
      isDraft: false,
    }).failures.join("\n"),
    /Авторская самопроверка/,
  );

  assert.match(
    evaluateReadiness({
      baseRef: "main",
      title: "[codex] Уточнить процесс ревью",
      body: readyProcessBody.replace("Локальный MVP: не требуется", "Локальный MVP: принята"),
      files: [{ filename: ".github/PULL_REQUEST_TEMPLATE.md" }],
      isDraft: false,
    }).failures.join("\n"),
    /Локальный MVP/,
  );

  assert.match(
    evaluateReadiness({
      baseRef: "main",
      title: "[codex] Уточнить процесс ревью",
      body: readyProcessBody.replace("Операторская приёмка: не требуется", "Операторская приёмка: принят"),
      files: [{ filename: ".github/PULL_REQUEST_TEMPLATE.md" }],
      isDraft: false,
    }).failures.join("\n"),
    /Операторская приёмка/,
  );

  assert.match(
    evaluateReadiness({
      baseRef: "main",
      title: "[codex] Уточнить процесс ревью",
      body: readyProcessBody.replace("Принятый риск: отсутствует", "Принятый риск: принято: временно"),
      files: [{ filename: ".github/PULL_REQUEST_TEMPLATE.md" }],
      isDraft: false,
    }).failures.join("\n"),
    /Принятый риск/,
  );

  assert.match(
    evaluateReadiness({
      baseRef: "staging",
      title: "[codex] Исправить синхронизацию Bitrix24",
      body: readyProcessBody.replace("Блокеры: отсутствуют", "Блокеры: ждём тестовую среду"),
      files: [{ filename: "app/Services/Bitrix24ContactSyncService.php" }],
      isDraft: false,
    }).failures.join("\n"),
    /Блокеры/,
  );

  assert.match(
    evaluateReadiness({
      baseRef: "staging",
      title: "[codex] Исправить синхронизацию Bitrix24",
      body: "Описание PR на русском языке.",
      files: [{ filename: "app/Services/Bitrix24ContactSyncService.php" }],
      isDraft: false,
    }).failures.join("\n"),
    /Тип изменения/,
  );

  assert.match(
    evaluateReadiness({
      baseRef: "main",
      title: "[codex] Исправить синхронизацию Bitrix24",
      body: readyProcessBody.replace("процессное", "кодовое"),
      files: [{ filename: "app/Services/Bitrix24ContactSyncService.php" }],
      isDraft: false,
    }).failures.join("\n"),
    /Runtime PR/,
  );

  assert.equal(
    hasStagingEvidence("```text\nStaging PR: #614\nStaging smoke: https://github.com/Etogerman/Project-1/actions/runs/123"),
    false,
  );

  assert.deepEqual(
    evaluateReadiness({
      baseRef: "main",
      title: "[codex] Исправить синхронизацию Bitrix24",
      body: `${readyProcessBody.replace("процессное", "кодовое").replace("документационный путь", "до merge в main")}\n\nStaging PR: #614\nStaging smoke: https://github.com/Etogerman/Project-1/actions/runs/123`,
      files: [{ filename: "app/Services/Bitrix24ContactSyncService.php" }],
      isDraft: false,
    }).failures,
    [],
  );

  assert.deepEqual(
    evaluateReadiness({
      baseRef: "main",
      title: "[codex] Исправить синхронизацию Bitrix24",
      body: `${readyProcessBody.replace("процессное", "кодовое").replace("документационный путь", "до merge в main")}\n\nStaging PRs: #614, #615\nStaging smoke: https://github.com/Etogerman/Project-1/actions/runs/123`,
      files: [{ filename: "app/Services/Bitrix24ContactSyncService.php" }],
      isDraft: false,
    }).failures,
    [],
  );

  assert.match(
    evaluateReadiness({
      baseRef: "staging",
      title: "[codex] Исправить синхронизацию Bitrix24",
      body: `${readyProcessBody.replace("процессное", "кодовое")}\n\nСущественный stream: да\nSpec repo: Etogerman/Project-1-specs\nSpec doc: streams/tz.md\nSpec revision: latest`,
      files: [{ filename: "app/Services/Bitrix24ContactSyncService.php" }],
      isDraft: false,
    }).failures.join("\n"),
    /Spec revision/,
  );

  console.log("ab-readiness-check self-test passed");
}

async function run() {
  const token = process.env.GITHUB_TOKEN;
  const repository = process.env.GITHUB_REPOSITORY;

  if (!token) {
    throw new Error("GITHUB_TOKEN is required.");
  }

  if (!repository) {
    throw new Error("GITHUB_REPOSITORY is required.");
  }

  const pullNumber = parsePullNumber(process.env.PR_NUMBER);

  const { owner, repo } = parseRepository(repository);
  const pullRequest = await githubRequest(`/repos/${owner}/${repo}/pulls/${pullNumber}`, token);
  const files = await listPullRequestFiles({ owner, repo, pullNumber, token });
  const closingIssueReferences = await listPullRequestClosingIssueReferences({
    owner,
    repo,
    pullNumber,
    token,
  });
  const commits = await listPullRequestCommits({ owner, repo, pullNumber, token });
  const result = evaluateReadiness({
    baseRef: pullRequest.base.ref,
    title: pullRequest.title || "",
    body: pullRequest.body || "",
    files,
    isDraft: Boolean(pullRequest.draft),
    closingIssueReferences,
    commits,
  });
  if (result.failures.length > 0) {
    console.error("AB readiness check failed.");
    console.error(`Base branch: ${pullRequest.base.ref}`);
    console.error(`Draft: ${pullRequest.draft ? "yes" : "no"}`);
    console.error(`Runtime files: ${result.runtimeFiles.map((file) => file.filename).join(", ") || "none"}`);
    result.warnings.forEach(printWarning);
    result.failures.forEach(printFailure);
    process.exit(1);
  }

  result.warnings.forEach(printWarning);
  console.log("AB readiness check passed.");
  console.log(`Base branch: ${pullRequest.base.ref}`);
  console.log(`Draft: ${pullRequest.draft ? "yes" : "no"}`);
  console.log(`Runtime files: ${result.runtimeFiles.length}`);

  if (result.warnings.length > 0) {
    console.log("Draft PR is not ready for Ready for review yet.");
  } else {
    console.log("PR readiness fields are complete.");
  }
}

if (process.argv.includes("--self-test")) {
  runSelfTest();
} else {
  await run();
}
