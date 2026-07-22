#!/usr/bin/env node
import assert from "node:assert/strict";

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
  /^\.agents\/skills\/ab-connector-skill-authoring\/SKILL\.md$/,
  /^\.agents\/skills\/ab-pr-ci-review\/(SKILL\.md|agents\/openai\.yaml)$/,
  /^\.agents\/skills\/ab-spec-workflow\/(SKILL\.md|agents\/openai\.yaml)$/,
  /^\.agents\/skills\/ab-stream-state-resolver\/(SKILL\.md|agents\/openai\.yaml)$/,
  /^\.github\/PULL_REQUEST_TEMPLATE\.md$/,
  /^\.github\/copilot-instructions\.md$/,
  /^\.github\/scripts\/ab-readiness-check\.mjs$/,
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
  /^\s{0,3}#{1,6}\s*(Summary|Overview|Description|Why|Validation|Testing|Tests|Checks|Delivery note|Implementation|Changes|Root cause|Impact|Risks|Rollout)\s*$/gim;
const ALLOWED_TECHNICAL_TERMS_PATTERN =
  /\b(codex|Copilot|Copilot CLI|Copilot Requests|CLI|PAT|token|secret|workflow_dispatch|GITHUB_TOKEN|COPILOT_GITHUB_TOKEN|READY_TO_MERGE|BLOCKED|shadow|verdict|merge-readiness|PR|MCP|CI|UI|URL|API|JSON|YAML|TOML|PHP|SQL|HTTP|HTTPS|Docker|Laravel|Boost|Filament|Livewire|Bitrix24|AB Connector|Spec repo|Spec doc|Spec revision|MVP|Staging PRs|Staging PR|Staging smoke|Staging Post-Deploy Smoke|rev-check|public smoke|admin smoke|dev-only|validated diff|clean-main-PR|workflow|runtime|main|staging|draft|ready|merge|commit|branch|pull request|hotfix|release-process-guard|ab-readiness-check|copilot-feasibility-spike|copilot-merge-readiness|php-artisan-test)\b/gi;
const LINKED_ISSUES_PATTERN = /^#[1-9]\d*(?:, #[1-9]\d*)*$/;
const CLOSING_KEYWORD_PATTERN =
  /\b(?:close(?:s|d)?|fix(?:es|ed)?|resolve(?:s|d)?)\s+(?:https:\/\/github\.com\/[^/\s]+\/[^/\s]+\/issues\/[1-9]\d*|(?:[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+)?#[1-9]\d*)\b/i;

const REQUIRED_FIELDS = [
  { key: "changeType", label: "Тип изменения" },
  { key: "substantialStream", label: "Существенный stream" },
  { key: "deliveryLevel", label: "Уровень доставки" },
  { key: "localMvp", label: "Локальный MVP" },
  { key: "operatorAcceptance", label: "Операторская приёмка" },
  { key: "authorSelfCheck", label: "Авторская самопроверка" },
  { key: "linkedIssues", label: "Связанные задачи" },
  { key: "blockers", label: "Блокеры" },
  { key: "acceptedRisk", label: "Принятый риск" },
];

const SPEC_FIELDS = [
  { key: "specRepo", label: "Spec repo" },
  { key: "specDoc", label: "Spec doc" },
  { key: "specRevision", label: "Spec revision" },
];

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

function escapeRegExp(value) {
  return value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

function stripHtmlComments(text) {
  return text.replace(/<!--[\s\S]*?(?:-->|$)/g, " ");
}

function stripMarkdownFencedCode(text) {
  const lines = String(text).split("\n");
  let activeFence = null;

  return lines.map((line) => {
    if (activeFence) {
      const closingFence = line.match(/^ {0,3}(`{3,}|~{3,})[ \t]*\r?$/);

      if (
        closingFence
        && closingFence[1][0] === activeFence.marker
        && closingFence[1].length >= activeFence.length
      ) {
        activeFence = null;
      }

      return "";
    }

    const openingFence = line.match(/^ {0,3}(`{3,}|~{3,})/);

    if (!openingFence) {
      return line;
    }

    activeFence = {
      marker: openingFence[1][0],
      length: openingFence[1].length,
    };

    return "";
  }).join("\n");
}

function stripMarkdownCode(text) {
  return stripMarkdownFencedCode(stripHtmlComments(text))
    .replace(/`[^`]*`/g, " ");
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

function normalizeValue(value = "") {
  return value
    .trim()
    .replace(/^[-*]\s*/, "")
    .replace(/^`|`$/g, "")
    .replace(/\s+/g, " ");
}

function extractField(body, label, { preserveInternalWhitespace = false } = {}) {
  const pattern = new RegExp(`(?:^|\\n) {0,3}(?:[-*][^\\S\\n]*)?${escapeRegExp(label)}[^\\S\\n]*:[^\\S\\n]*(.*?)(?=\\n|$)`, "i");
  const match = body.match(pattern);

  if (!match) {
    return null;
  }

  const value = preserveInternalWhitespace
    ? match[1].trim().replace(/^`|`$/g, "")
    : normalizeValue(match[1]);

  return value === "" ? null : value;
}

function extractFields(body) {
  const visibleBody = stripHtmlComments(body);
  const fields = [...REQUIRED_FIELDS, ...SPEC_FIELDS].reduce(
    (fields, field) => ({
      ...fields,
      [field.key]: extractField(visibleBody, field.label),
    }),
    {},
  );

  fields.linkedIssues = extractField(
    stripMarkdownCode(visibleBody),
    "Связанные задачи",
    { preserveInternalWhitespace: true },
  );

  return fields;
}

function countFieldOccurrences(body, label) {
  const pattern = new RegExp(`(?:^|\\n) {0,3}(?:[-*][^\\S\\n]*)?${escapeRegExp(label)}[^\\S\\n]*:`, "gi");

  return [...stripMarkdownCode(body).matchAll(pattern)].length;
}

function parseLinkedIssues(value) {
  const normalized = String(value || "").trim().replace(/^[-*]\s*/, "").replace(/^`|`$/g, "");

  if (/^не требуется$/i.test(normalized)) {
    return { valid: true, noneRequired: true, issueNumbers: [] };
  }

  if (!LINKED_ISSUES_PATTERN.test(normalized)) {
    return { valid: false, noneRequired: false, issueNumbers: [] };
  }

  const issueNumbers = normalized
    .split(",")
    .map((reference) => Number(reference.trim().slice(1)));
  const hasUnsafeNumber = issueNumbers.some((issueNumber) => !Number.isSafeInteger(issueNumber));
  const hasDuplicates = new Set(issueNumbers).size !== issueNumbers.length;

  return {
    valid: !hasUnsafeNumber && !hasDuplicates,
    noneRequired: false,
    issueNumbers,
  };
}

function hasClosingIssueKeyword(body) {
  return CLOSING_KEYWORD_PATTERN.test(stripMarkdownCode(body));
}

function validatePublishLanguage({ title, body }) {
  const failures = [];
  const readableTitle = title.replace(/^\s*\[codex\]\s*/i, "").trim();
  const readableBody = stripTechnicalMarkdown(body);
  const readableText = `${stripTechnicalMarkdown(readableTitle)}\n${readableBody}`;
  const englishHeadings = [...stripMarkdownCode(body).matchAll(ENGLISH_PR_HEADING_PATTERN)].map((match) => match[1]);
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
  const visibleBody = stripMarkdownCode(body);

  return /(?:^|\n)\s*Staging\s+PRs?\s*:\s*(?:.*(?:https:\/\/github\.com\/[^/\s]+\/[^/\s]+\/pull\/)?#?\d+\b)/i.test(visibleBody)
    && /(?:^|\n)\s*Staging\s+smoke\s*:\s*(?:.*https:\/\/github\.com\/[^/\s]+\/[^/\s]+\/actions\/runs\/\d+)/i.test(visibleBody);
}

function isSubstantialStream(fields) {
  return /^(да)$/i.test(fields.substantialStream || "")
    || SPEC_FIELDS.some((field) => Boolean(fields[field.key]));
}

function validateSpecFields(fields) {
  const missing = [];
  const invalid = [];

  for (const field of SPEC_FIELDS) {
    if (!fields[field.key]) {
      missing.push(`Для существенного stream перед Ready нужно заполнить поле \`${field.label}:\`.`);
    }
  }

  if (fields.specRevision && !/^[0-9a-f]{7,40}$/i.test(fields.specRevision)) {
    invalid.push("Поле `Spec revision:` должно содержать commit hash длиной 7-40 hex-символов.");
  }

  return { missing, invalid };
}

function evaluateReadiness({ baseRef, title = "", body = "", files = [], isDraft = true }) {
  const failures = [];
  const readinessIssues = [];
  const warnings = [];
  const fields = extractFields(body);
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

  if (hasClosingIssueKeyword(body)) {
    failures.push("PR не должен содержать закрывающие ключевые слова `close`, `fix` или `resolve`: итоговое решение по связанным задачам фиксируется отдельно после приёмки результата.");
  }

  for (const field of REQUIRED_FIELDS) {
    if (!fields[field.key]) {
      readinessIssues.push(`Не заполнено поле \`${field.label}:\`.`);
    }
  }

  if (fields.linkedIssues && !parseLinkedIssues(fields.linkedIssues).valid) {
    readinessIssues.push("Поле `Связанные задачи:` должно быть `#NNN`, списком `#NNN, #MMM` или `не требуется`; повторы задач запрещены.");
  }

  if (countFieldOccurrences(body, "Связанные задачи") > 1) {
    readinessIssues.push("Поле `Связанные задачи:` должно встречаться ровно один раз.");
  }

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
  assert.deepEqual(parseLinkedIssues("#708"), {
    valid: true,
    noneRequired: false,
    issueNumbers: [708],
  });
  assert.deepEqual(parseLinkedIssues("#708, #712"), {
    valid: true,
    noneRequired: false,
    issueNumbers: [708, 712],
  });
  assert.deepEqual(parseLinkedIssues("не требуется"), {
    valid: true,
    noneRequired: true,
    issueNumbers: [],
  });

  for (const invalidLinkedIssues of [
    "708",
    "#708 #712",
    "#708,#712",
    "#708,  #712",
    "https://github.com/Etogerman/Project-1/issues/708",
    "Closes #708",
    "#0",
    "#708, #708",
    "#9999999999999999999999",
  ]) {
    assert.equal(parseLinkedIssues(invalidLinkedIssues).valid, false);
  }

  for (const closingReference of [
    "Closes #708",
    "closed Etogerman/Project-1#708",
    "Fixes https://github.com/Etogerman/Project-1/issues/708",
    "fixed #708",
    "Resolves #708",
    "resolved #708",
  ]) {
    assert.equal(hasClosingIssueKeyword(closingReference), true);
  }

  assert.equal(hasClosingIssueKeyword("Связанные задачи: #708"), false);
  assert.equal(hasClosingIssueKeyword("Пример: `Fixes #708` использовать нельзя."), false);
  assert.equal(hasClosingIssueKeyword("```text\nFixes #708\n```"), false);
  assert.equal(
    extractFields("Spec revision: `abcdef1`\nСвязанные задачи: #708").specRevision,
    "abcdef1",
  );

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
      baseRef: "main",
      title: "[codex] Уточнить процесс ревью",
      body: readyProcessBody.replace("- Связанные задачи: не требуется\n", ""),
      files: [{ filename: ".github/PULL_REQUEST_TEMPLATE.md" }],
      isDraft: false,
    }).failures.join("\n"),
    /Связанные задачи/,
  );

  assert.match(
    evaluateReadiness({
      baseRef: "main",
      title: "[codex] Уточнить процесс ревью",
      body: readyProcessBody.replace(
        "- Связанные задачи: не требуется",
        "~~~text\nСвязанные задачи: #708",
      ),
      files: [{ filename: ".github/PULL_REQUEST_TEMPLATE.md" }],
      isDraft: false,
    }).failures.join("\n"),
    /Связанные задачи/,
  );

  assert.match(
    evaluateReadiness({
      baseRef: "main",
      title: "[codex] Уточнить процесс ревью",
      body: readyProcessBody.replace(
        "- Связанные задачи: не требуется",
        "~~~text\nСвязанные задачи: #708\n~~~",
      ),
      files: [{ filename: ".github/PULL_REQUEST_TEMPLATE.md" }],
      isDraft: false,
    }).failures.join("\n"),
    /Связанные задачи/,
  );

  assert.match(
    evaluateReadiness({
      baseRef: "main",
      title: "[codex] Уточнить процесс ревью",
      body: readyProcessBody.replace(
        "- Связанные задачи: не требуется",
        "    Связанные задачи: #708",
      ),
      files: [{ filename: ".github/PULL_REQUEST_TEMPLATE.md" }],
      isDraft: false,
    }).failures.join("\n"),
    /Связанные задачи/,
  );

  assert.match(
    evaluateReadiness({
      baseRef: "main",
      title: "[codex] Уточнить процесс ревью",
      body: readyProcessBody.replace(
        "- Связанные задачи: не требуется",
        "```text\nСвязанные задачи: #708",
      ),
      files: [{ filename: ".github/PULL_REQUEST_TEMPLATE.md" }],
      isDraft: false,
    }).failures.join("\n"),
    /Связанные задачи/,
  );

  assert.match(
    evaluateReadiness({
      baseRef: "main",
      title: "[codex] Уточнить процесс ревью",
      body: readyProcessBody.replace(
        "- Связанные задачи: не требуется",
        "<!--\nСвязанные задачи: #708",
      ),
      files: [{ filename: ".github/PULL_REQUEST_TEMPLATE.md" }],
      isDraft: false,
    }).failures.join("\n"),
    /Связанные задачи/,
  );

  assert.match(
    evaluateReadiness({
      baseRef: "main",
      title: "[codex] Уточнить процесс ревью",
      body: readyProcessBody.replace(
        "- Связанные задачи: не требуется",
        "<!--\nСвязанные задачи: #708\n-->",
      ),
      files: [{ filename: ".github/PULL_REQUEST_TEMPLATE.md" }],
      isDraft: false,
    }).failures.join("\n"),
    /Связанные задачи/,
  );

  assert.match(
    evaluateReadiness({
      baseRef: "main",
      title: "[codex] Уточнить процесс ревью",
      body: `${readyProcessBody}\n- Связанные задачи: #708`,
      files: [{ filename: ".github/PULL_REQUEST_TEMPLATE.md" }],
      isDraft: false,
    }).failures.join("\n"),
    /ровно один раз/,
  );

  assert.match(
    evaluateReadiness({
      baseRef: "main",
      title: "[codex] Уточнить процесс ревью",
      body: readyProcessBody.replace(
        "- Связанные задачи: не требуется",
        "```text\nСвязанные задачи: #708\n```",
      ),
      files: [{ filename: ".github/PULL_REQUEST_TEMPLATE.md" }],
      isDraft: false,
    }).failures.join("\n"),
    /Связанные задачи/,
  );

  assert.match(
    evaluateReadiness({
      baseRef: "main",
      title: "[codex] Уточнить процесс ревью",
      body: `${readyProcessBody}\n\nFixes #708`,
      files: [{ filename: ".github/PULL_REQUEST_TEMPLATE.md" }],
      isDraft: true,
    }).failures.join("\n"),
    /закрывающие ключевые слова/,
  );

  assert.match(
    evaluateReadiness({
      baseRef: "main",
      title: "[codex] Уточнить процесс ревью",
      body: readyProcessBody.replace("Связанные задачи: не требуется", "Связанные задачи: #708 #712"),
      files: [{ filename: ".github/PULL_REQUEST_TEMPLATE.md" }],
      isDraft: false,
    }).failures.join("\n"),
    /Связанные задачи/,
  );

  assert.match(
    evaluateReadiness({
      baseRef: "main",
      title: "[codex] Уточнить процесс ревью",
      body: readyProcessBody.replace("- Связанные задачи: не требуется\n", ""),
      files: [{ filename: ".github/PULL_REQUEST_TEMPLATE.md" }],
      isDraft: true,
    }).warnings.join("\n"),
    /Связанные задачи/,
  );

  assert.match(
    evaluateReadiness({
      baseRef: "staging",
      title: "[codex] Исправить синхронизацию Bitrix24",
      body: readyProcessBody
        .replace("процессное", "кодовое")
        .replace("документационный путь", "PR в staging")
        .replace("Связанные задачи: не требуется", "Связанные задачи: #708")
        .concat("\n\nFixes #708"),
      files: [{ filename: "app/Services/Bitrix24ContactSyncService.php" }],
      isDraft: true,
    }).failures.join("\n"),
    /закрывающие ключевые слова/,
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
  const result = evaluateReadiness({
    baseRef: pullRequest.base.ref,
    title: pullRequest.title || "",
    body: pullRequest.body || "",
    files,
    isDraft: Boolean(pullRequest.draft),
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
