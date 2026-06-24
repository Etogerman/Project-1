#!/usr/bin/env node
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";

const MARKER = "<!-- copilot-merge-readiness -->";
const REQUIRED_CHECKS = ["php-artisan-test", "ab-readiness-check", "release-process-guard"];
const VALID_COPILOT_VERDICTS = new Set(["READY_TO_MERGE", "BLOCKED", "READY_AFTER_HUMAN_CHECK"]);
const DEFAULT_CONTEXT_BUDGET_BYTES = 60000;
const DEFAULT_CHECK_WAIT_SECONDS = 600;
const CHECK_POLL_SECONDS = 15;
const COPILOT_OUTPUT_RETRY_BYTES = 12000;

function byteLength(value) {
  return Buffer.byteLength(value, "utf8");
}

function truncate(value = "", maxLength = 2000) {
  return value.length > maxLength ? `${value.slice(0, maxLength)}...` : value;
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function normalizeRepo(value) {
  const [owner, repo] = (value || "").split("/");

  if (!owner || !repo) {
    throw new Error("GITHUB_REPOSITORY must be owner/repo.");
  }

  return { owner, repo };
}

async function githubRequest(path, { method = "GET", token, headers = {}, body } = {}) {
  const response = await fetch(`https://api.github.com${path}`, {
    method,
    headers: {
      Accept: "application/vnd.github+json",
      Authorization: `Bearer ${token}`,
      "User-Agent": "project-1-copilot-merge-readiness",
      "X-GitHub-Api-Version": "2022-11-28",
      ...headers,
    },
    body: body ? JSON.stringify(body) : undefined,
  });

  const text = await response.text();

  if (!response.ok) {
    throw new Error(`GitHub API ${method} ${path} failed with ${response.status}: ${truncate(text)}`);
  }

  if (!text) {
    return null;
  }

  const contentType = response.headers.get("content-type") || "";

  return contentType.includes("application/json") ? JSON.parse(text) : text;
}

async function githubPaginated(path, { token }) {
  const items = [];
  let page = 1;

  while (true) {
    const separator = path.includes("?") ? "&" : "?";
    const batch = await githubRequest(`${path}${separator}per_page=100&page=${page}`, { token });

    if (!Array.isArray(batch) || batch.length === 0) {
      break;
    }

    items.push(...batch);

    if (batch.length < 100) {
      break;
    }

    page += 1;
  }

  return items;
}

async function readBaseFile({ owner, repo, ref, path, token }) {
  try {
    return await githubRequest(
      `/repos/${owner}/${repo}/contents/${encodeURIComponent(path).replaceAll("%2F", "/")}?ref=${encodeURIComponent(ref)}`,
      {
        token,
        headers: { Accept: "application/vnd.github.raw" },
      },
    );
  } catch (error) {
    return null;
  }
}

function latestByName(checkRuns) {
  const latest = new Map();

  for (const run of checkRuns) {
    const existing = latest.get(run.name);
    const currentTime = Date.parse(run.started_at || run.completed_at || run.created_at || 0);
    const existingTime = Date.parse(existing?.started_at || existing?.completed_at || existing?.created_at || 0);

    if (!existing || currentTime >= existingTime) {
      latest.set(run.name, run);
    }
  }

  return latest;
}

function evaluateRequiredChecks(checkRuns) {
  const latest = latestByName(checkRuns);
  const blockers = [];
  const pending = [];
  const missing = [];
  const checked = [];

  for (const name of REQUIRED_CHECKS) {
    const run = latest.get(name);

    if (!run) {
      missing.push(name);
      checked.push(`${name}: missing`);
      continue;
    }

    checked.push(`${name}: ${run.status}${run.conclusion ? `/${run.conclusion}` : ""}`);

    if (run.status !== "completed") {
      pending.push(name);
      continue;
    }

    if (run.conclusion !== "success") {
      blockers.push(`Required check \`${name}\` завершился как \`${run.conclusion || "unknown"}\`.`);
    }
  }

  return { blockers, pending, missing, checked };
}

async function waitForRequiredChecks({ owner, repo, headSha, token, waitSeconds }) {
  const deadline = Date.now() + waitSeconds * 1000;
  let lastResult = null;

  while (true) {
    const payload = await githubRequest(`/repos/${owner}/${repo}/commits/${headSha}/check-runs?per_page=100`, { token });
    lastResult = evaluateRequiredChecks(payload.check_runs || []);

    if (lastResult.pending.length === 0 && lastResult.missing.length === 0) {
      return lastResult;
    }

    if (Date.now() >= deadline) {
      const timeoutBlockers = [];

      if (lastResult.pending.length > 0) {
        timeoutBlockers.push(`Required checks всё ещё pending после ${waitSeconds} секунд: ${lastResult.pending.join(", ")}.`);
      }

      if (lastResult.missing.length > 0) {
        timeoutBlockers.push(`Required checks не появились после ${waitSeconds} секунд: ${lastResult.missing.join(", ")}.`);
      }

      return {
        ...lastResult,
        blockers: [
          ...lastResult.blockers,
          ...timeoutBlockers,
        ],
      };
    }

    await sleep(CHECK_POLL_SECONDS * 1000);
  }
}

function buildDiff(files) {
  const blockers = [];
  const chunks = [];

  for (const file of files) {
    const previous = file.previous_filename || file.filename;
    const header = `diff --git a/${previous} b/${file.filename}`;

    if (typeof file.patch !== "string" || file.patch.trim() === "") {
      blockers.push(`Diff для \`${file.filename}\` недоступен через GitHub API.`);
      chunks.push(`${header}\n[patch unavailable]`);
      continue;
    }

    chunks.push(`${header}\n${file.patch}`);
  }

  return {
    blockers,
    diff: chunks.join("\n\n"),
  };
}

function buildPrompt({ pr, files, diff, instructions, deterministic }) {
  const fileList = files.map((file) => `- ${file.status} ${file.filename} (+${file.additions}/-${file.deletions})`).join("\n");

  return [
    "Return exactly one strict JSON object and nothing else.",
    "Return minified JSON on a single line.",
    "Do not use markdown, code fences, comments, or explanatory text.",
    "Every JSON string value must be single-line. Replace line breaks with spaces or escaped \\n sequences.",
    "Never put raw control characters inside JSON strings.",
    "Array values must be short single-line Russian strings.",
    "The JSON shape must be:",
    "{\"verdict\":\"READY_TO_MERGE|BLOCKED|READY_AFTER_HUMAN_CHECK\",\"blockers\":[],\"risks\":[],\"checked_conditions\":[],\"missing_data\":[],\"next_step\":\"...\"}",
    "Review the pull request for merge-readiness. Write all human-facing strings in Russian.",
    "If there is any meaningful uncertainty, use BLOCKED or READY_AFTER_HUMAN_CHECK, not READY_TO_MERGE.",
    "",
    "Deterministic facts:",
    JSON.stringify(deterministic, null, 2),
    "",
    "Pull request:",
    JSON.stringify({
      number: pr.number,
      title: pr.title,
      body: pr.body || "",
      base: pr.base.ref,
      head: pr.head.ref,
      draft: pr.draft,
    }, null, 2),
    "",
    "Copilot instructions from base branch:",
    instructions || "[not available]",
    "",
    "Changed files:",
    fileList,
    "",
    "Full diff:",
    diff,
  ].join("\n");
}

function parseStrictJson(output) {
  const trimmed = output.trim();

  if (!trimmed.startsWith("{")) {
    throw new Error("Copilot output is not strict JSON.");
  }

  const jsonEnd = findJsonObjectEnd(trimmed);

  if (jsonEnd === -1) {
    throw new Error("Copilot output does not contain a complete JSON object.");
  }

  const trailingText = trimmed.slice(jsonEnd + 1).trim();

  return {
    payload: JSON.parse(trimmed.slice(0, jsonEnd + 1)),
    normalizedControlChars: false,
    normalizedTrailingText: trailingText.length > 0,
  };
}

function findJsonObjectEnd(value) {
  let depth = 0;
  let inString = false;
  let escaped = false;

  for (let index = 0; index < value.length; index += 1) {
    const char = value[index];

    if (inString) {
      if (escaped) {
        escaped = false;
      } else if (char === "\\") {
        escaped = true;
      } else if (char === "\"") {
        inString = false;
      }

      continue;
    }

    if (char === "\"") {
      inString = true;
      continue;
    }

    if (char === "{") {
      depth += 1;
      continue;
    }

    if (char === "}") {
      depth -= 1;

      if (depth === 0) {
        return index;
      }
    }
  }

  return -1;
}

function escapeRawControlCharsInJsonStrings(value) {
  let result = "";
  let inString = false;
  let escaped = false;

  for (const char of value) {
    const code = char.charCodeAt(0);

    if (!inString) {
      if (char === "\"") {
        inString = true;
      }

      result += char;
      continue;
    }

    if (escaped) {
      result += char;
      escaped = false;
      continue;
    }

    if (char === "\\") {
      result += char;
      escaped = true;
      continue;
    }

    if (char === "\"") {
      result += char;
      inString = false;
      continue;
    }

    if (code <= 0x1F) {
      if (char === "\n") {
        result += "\\n";
      } else if (char === "\r") {
        result += "\\r";
      } else if (char === "\t") {
        result += "\\t";
      } else {
        result += `\\u${code.toString(16).padStart(4, "0")}`;
      }

      continue;
    }

    result += char;
  }

  return result;
}

function parseStrictJsonWithControlCharNormalization(output) {
  try {
    return parseStrictJson(output);
  } catch (error) {
    if (!/control character/i.test(error.message)) {
      throw error;
    }

    const trimmed = output.trim();
    const jsonEnd = findJsonObjectEnd(trimmed);

    if (jsonEnd === -1) {
      throw error;
    }

    return {
      payload: JSON.parse(escapeRawControlCharsInJsonStrings(trimmed.slice(0, jsonEnd + 1))),
      normalizedControlChars: true,
      normalizedTrailingText: trimmed.slice(jsonEnd + 1).trim().length > 0,
    };
  }
}

function parseCopilotResponse(output) {
  const parsed = parseStrictJsonWithControlCharNormalization(output);

  return {
    payload: normalizeCopilotPayload(parsed.payload),
    normalizedControlChars: parsed.normalizedControlChars,
    normalizedTrailingText: parsed.normalizedTrailingText,
  };
}

function normalizeStringArray(value) {
  return Array.isArray(value) ? value.map((item) => String(item)).filter(Boolean) : [];
}

function normalizeCopilotPayload(payload) {
  if (!payload || typeof payload !== "object" || Array.isArray(payload)) {
    throw new Error("Copilot JSON payload must be an object.");
  }

  if (!VALID_COPILOT_VERDICTS.has(payload.verdict)) {
    throw new Error("Copilot JSON payload has invalid verdict.");
  }

  return {
    verdict: payload.verdict,
    blockers: normalizeStringArray(payload.blockers),
    risks: normalizeStringArray(payload.risks),
    checked_conditions: normalizeStringArray(payload.checked_conditions),
    missing_data: normalizeStringArray(payload.missing_data),
    next_step: String(payload.next_step || ""),
  };
}

function buildCopilotArgs(prompt) {
  return [
    "-p",
    prompt,
    "--silent",
    "--no-ask-user",
    "--no-color",
    "--stream",
    "off",
    "--no-custom-instructions",
  ];
}

function runCopilotPrompt(prompt) {
  const result = spawnSync("copilot", buildCopilotArgs(prompt), {
    encoding: "utf8",
    env: {
      ...process.env,
      CI: "true",
      NO_COLOR: "1",
    },
    timeout: 180000,
  });

  if (result.error) {
    throw result.error;
  }

  if (result.status !== 0) {
    throw new Error([
      `Copilot CLI exited with status ${result.status}.`,
      `stdout bytes: ${byteLength(result.stdout || "")}`,
      `stderr bytes: ${byteLength(result.stderr || "")}`,
      `stderr preview: ${truncate(result.stderr || "")}`,
    ].join("\n"));
  }

  return {
    stdout: result.stdout || "",
    stderr: result.stderr || "",
  };
}

function buildJsonRetryPrompt({ previousOutput, parseError }) {
  return [
    "Your previous answer was rejected because it was not strict JSON.",
    `Parser error: ${parseError.message}`,
    "Convert the previous answer into exactly one strict JSON object and nothing else.",
    "Return minified JSON on a single line.",
    "Do not use markdown, code fences, comments, prose, or explanatory text.",
    "Every JSON string value must be single-line. Replace line breaks with spaces or escaped \\n sequences.",
    "Never put raw control characters inside JSON strings.",
    "Array values must be short single-line Russian strings.",
    "The JSON shape must be:",
    "{\"verdict\":\"READY_TO_MERGE|BLOCKED|READY_AFTER_HUMAN_CHECK\",\"blockers\":[],\"risks\":[],\"checked_conditions\":[],\"missing_data\":[],\"next_step\":\"...\"}",
    "Write all human-facing strings in Russian.",
    "If the previous answer cannot be safely converted, return verdict BLOCKED and explain that in blockers or missing_data.",
    "",
    "Previous answer:",
    truncate(previousOutput || "", COPILOT_OUTPUT_RETRY_BYTES),
  ].join("\n");
}

function withCheckedCondition(payload, condition) {
  return {
    ...payload,
    checked_conditions: [...payload.checked_conditions, condition],
  };
}

function withParseDiagnostics(parsed, condition) {
  const diagnostics = [condition];

  if (parsed.normalizedControlChars) {
    diagnostics.push("Copilot JSON raw control characters normalized");
  }

  if (parsed.normalizedTrailingText) {
    diagnostics.push("Copilot JSON trailing text ignored");
  }

  return diagnostics.reduce(
    (payload, diagnostic) => withCheckedCondition(payload, diagnostic),
    parsed.payload,
  );
}

function callCopilot(prompt) {
  if (!process.env.COPILOT_GITHUB_TOKEN) {
    return {
      verdict: "BLOCKED",
      blockers: ["Secret `COPILOT_GITHUB_TOKEN` недоступен для workflow."],
      risks: [],
      checked_conditions: [],
      missing_data: ["COPILOT_GITHUB_TOKEN"],
      next_step: "Настроить GitHub Actions secret и повторить shadow-check.",
    };
  }

  const first = runCopilotPrompt(prompt);

  try {
    return withParseDiagnostics(parseCopilotResponse(first.stdout), "Copilot JSON attempt 1: valid");
  } catch (firstError) {
    const retryPrompt = buildJsonRetryPrompt({
      previousOutput: first.stdout,
      parseError: firstError,
    });
    const second = runCopilotPrompt(retryPrompt);

    try {
      const parsedSecond = parseCopilotResponse(second.stdout);

      return withParseDiagnostics({
        ...parsedSecond,
        payload: withCheckedCondition(parsedSecond.payload, "Copilot JSON attempt 1: invalid strict JSON"),
      }, "Copilot JSON attempt 2: valid");
    } catch (secondError) {
      throw new Error([
        "Copilot output is not strict JSON after 2 attempts.",
        `first parse error: ${firstError.message}`,
        `second parse error: ${secondError.message}`,
        `first stdout bytes: ${byteLength(first.stdout)}`,
        `second stdout bytes: ${byteLength(second.stdout)}`,
      ].join("\n"));
    }
  }
}

function finalVerdict({ deterministicBlockers, contextBlockers, copilot }) {
  if (deterministicBlockers.length > 0 || contextBlockers.length > 0) {
    return "BLOCKED";
  }

  return copilot?.verdict === "READY_TO_MERGE" ? "READY_TO_MERGE" : "BLOCKED";
}

function listOrNone(items) {
  return items.length > 0 ? items.map((item) => `- ${item}`).join("\n") : "- отсутствуют";
}

function buildComment({ verdict, pr, mode, deterministic, copilot, context, nextStep }) {
  const deterministicBlockers = deterministic.blockers || [];
  const copilotBlockers = copilot?.blockers || [];
  const risks = copilot?.risks || [];
  const checkedConditions = [
    ...(deterministic.checked || []),
    ...(copilot?.checked_conditions || []),
  ];
  const missingData = [
    ...(context.missingData || []),
    ...(copilot?.missing_data || []),
  ];

  return [
    MARKER,
    "## Copilot merge-readiness",
    "",
    `**Verdict:** \`${verdict}\``,
    `**Режим:** \`${mode}\``,
    "",
    "> Shadow-комментарий не является approval, required check или разрешением на merge.",
    "",
    "### Детерминированные блокеры",
    "",
    listOrNone(deterministicBlockers),
    "",
    "### Блокеры Copilot",
    "",
    listOrNone(copilotBlockers),
    "",
    "### Риски",
    "",
    listOrNone(risks),
    "",
    "### Проверенные условия",
    "",
    listOrNone(checkedConditions),
    "",
    "### Недостающие данные",
    "",
    listOrNone(missingData),
    "",
    "### Следующий шаг",
    "",
    nextStep || copilot?.next_step || (verdict === "READY_TO_MERGE" ? "Проверить PR по обычному процессу." : "Устранить блокеры и повторить проверку."),
    "",
    `PR: #${pr.number}`,
  ].join("\n");
}

async function upsertComment({ owner, repo, prNumber, token, body }) {
  const comments = await githubPaginated(`/repos/${owner}/${repo}/issues/${prNumber}/comments`, { token });
  const existing = comments.find((comment) => typeof comment.body === "string" && comment.body.includes(MARKER));

  if (existing) {
    await githubRequest(`/repos/${owner}/${repo}/issues/comments/${existing.id}`, {
      method: "PATCH",
      token,
      body: { body },
    });

    return { action: "updated", url: existing.html_url };
  }

  const created = await githubRequest(`/repos/${owner}/${repo}/issues/${prNumber}/comments`, {
    method: "POST",
    token,
    body: { body },
  });

  return { action: "created", url: created.html_url };
}

async function run() {
  const token = process.env.GITHUB_TOKEN;

  if (!token) {
    throw new Error("GITHUB_TOKEN is required.");
  }

  const prNumber = Number.parseInt(process.env.PR_NUMBER || "", 10);

  if (!Number.isInteger(prNumber)) {
    throw new Error("PR_NUMBER is required.");
  }

  const { owner, repo } = normalizeRepo(process.env.GITHUB_REPOSITORY);
  const waitSeconds = Number.parseInt(process.env.CHECK_WAIT_SECONDS || "", 10) || DEFAULT_CHECK_WAIT_SECONDS;
  const contextBudget = Number.parseInt(process.env.COPILOT_CONTEXT_BUDGET_BYTES || "", 10) || DEFAULT_CONTEXT_BUDGET_BYTES;
  const pr = await githubRequest(`/repos/${owner}/${repo}/pulls/${prNumber}`, { token });
  const files = await githubPaginated(`/repos/${owner}/${repo}/pulls/${prNumber}/files`, { token });
  const checkResult = await waitForRequiredChecks({ owner, repo, headSha: pr.head.sha, token, waitSeconds });
  const instructions = await readBaseFile({
    owner,
    repo,
    ref: pr.base.ref,
    path: ".github/copilot-instructions.md",
    token,
  });
  const { blockers: diffBlockers, diff } = buildDiff(files);
  const deterministicBlockers = [];
  const checked = [
    `base branch: ${pr.base.ref}`,
    `draft: ${pr.draft ? "yes" : "no"}`,
    `changed files: ${files.length}`,
    ...checkResult.checked,
  ];

  if (pr.draft) {
    deterministicBlockers.push("PR всё ещё draft.");
  }

  if (!["main", "staging"].includes(pr.base.ref)) {
    deterministicBlockers.push("Base branch должен быть `main` или `staging`.");
  }

  deterministicBlockers.push(...checkResult.blockers);

  if (!pr.title || !pr.body) {
    deterministicBlockers.push("PR title/body должны быть доступны и заполнены.");
  }

  if (!instructions) {
    deterministicBlockers.push("Не удалось прочитать `.github/copilot-instructions.md` из base branch.");
  }

  deterministicBlockers.push(...diffBlockers);

  const deterministic = {
    pass: deterministicBlockers.length === 0,
    blockers: deterministicBlockers,
    checked,
  };
  const context = { missingData: [] };
  let copilot = null;

  if (deterministic.pass) {
    const prompt = buildPrompt({ pr, files, diff, instructions, deterministic });
    const promptBytes = byteLength(prompt);
    checked.push(`copilot prompt bytes: ${promptBytes}/${contextBudget}`);

    if (promptBytes > contextBudget) {
      context.missingData.push("Полный diff не помещается в контекст Copilot.");
    } else {
      try {
        copilot = callCopilot(prompt);
      } catch (error) {
        copilot = {
          verdict: "BLOCKED",
          blockers: [`Copilot CLI не вернул валидный результат: ${error.message}`],
          risks: [],
          checked_conditions: [],
          missing_data: ["Copilot JSON verdict"],
          next_step: "Проверить Copilot CLI output и повторить shadow-check.",
        };
      }
    }
  }

  if (!copilot && deterministic.pass && context.missingData.length > 0) {
    copilot = {
      verdict: "BLOCKED",
      blockers: [],
      risks: [],
      checked_conditions: [],
      missing_data: [],
      next_step: "Разбить PR или увеличить безопасный context budget после отдельного решения.",
    };
  }

  const verdict = finalVerdict({
    deterministicBlockers,
    contextBlockers: context.missingData,
    copilot,
  });
  const comment = buildComment({
    verdict,
    pr,
    mode: "shadow",
    deterministic,
    copilot,
    context,
    nextStep: verdict === "READY_TO_MERGE"
      ? "Shadow-verdict зелёный. Продолжить обычный процесс ревью и delivery."
      : "Устранить блокеры или повторить shadow-check после завершения недостающих условий.",
  });
  const result = await upsertComment({ owner, repo, prNumber, token, body: comment });

  console.log(JSON.stringify({ verdict, comment: result.action, url: result.url }));
}

function selfTest() {
  assert.deepEqual(
    evaluateRequiredChecks([
      { name: "php-artisan-test", status: "completed", conclusion: "success", started_at: "2026-01-01T00:00:00Z" },
      { name: "ab-readiness-check", status: "completed", conclusion: "success", started_at: "2026-01-01T00:00:00Z" },
      { name: "release-process-guard", status: "completed", conclusion: "success", started_at: "2026-01-01T00:00:00Z" },
    ]).blockers,
    [],
  );
  assert.equal(
    evaluateRequiredChecks([
      { name: "php-artisan-test", status: "completed", conclusion: "failure", started_at: "2026-01-01T00:00:00Z" },
    ]).missing.length,
    2,
  );
  assert.equal(
    evaluateRequiredChecks([
      { name: "php-artisan-test", status: "completed", conclusion: "failure", started_at: "2026-01-01T00:00:00Z" },
    ]).blockers.length,
    1,
  );
  assert.equal(finalVerdict({ deterministicBlockers: [], contextBlockers: [], copilot: { verdict: "READY_TO_MERGE" } }), "READY_TO_MERGE");
  assert.equal(finalVerdict({ deterministicBlockers: ["x"], contextBlockers: [], copilot: { verdict: "READY_TO_MERGE" } }), "BLOCKED");
  assert.equal(finalVerdict({ deterministicBlockers: [], contextBlockers: ["x"], copilot: { verdict: "READY_TO_MERGE" } }), "BLOCKED");
  assert.equal(parseCopilotResponse("{\"verdict\":\"BLOCKED\",\"blockers\":[\"x\"],\"risks\":[],\"checked_conditions\":[],\"missing_data\":[],\"next_step\":\"fix\"}").payload.verdict, "BLOCKED");
  assert.deepEqual(
    parseCopilotResponse("{\"verdict\":\"BLOCKED\",\"blockers\":[\"line\nbreak\"],\"risks\":[],\"checked_conditions\":[],\"missing_data\":[],\"next_step\":\"fix\"}"),
    {
      payload: {
        verdict: "BLOCKED",
        blockers: ["line\nbreak"],
        risks: [],
        checked_conditions: [],
        missing_data: [],
        next_step: "fix",
      },
      normalizedControlChars: true,
      normalizedTrailingText: false,
    },
  );
  assert.deepEqual(
    parseCopilotResponse("{\"verdict\":\"BLOCKED\",\"blockers\":[\"x\"],\"risks\":[],\"checked_conditions\":[],\"missing_data\":[],\"next_step\":\"fix\"}\n\nextra text"),
    {
      payload: {
        verdict: "BLOCKED",
        blockers: ["x"],
        risks: [],
        checked_conditions: [],
        missing_data: [],
        next_step: "fix",
      },
      normalizedControlChars: false,
      normalizedTrailingText: true,
    },
  );
  assert.throws(() => parseStrictJson("```json\n{}\n```"), /strict JSON/);
  assert.throws(() => parseStrictJson("{\"verdict\":\"BLOCKED\""), /complete JSON object/);
  assert.deepEqual(
    buildCopilotArgs("prompt").filter((arg) => ["--no-custom-instructions", "--no-color", "--silent", "--stream", "off"].includes(arg)),
    ["--silent", "--no-color", "--stream", "off", "--no-custom-instructions"],
  );
  assert.match(
    buildJsonRetryPrompt({ previousOutput: "```json\n{}\n```", parseError: new Error("not strict") }),
    /Previous answer:/,
  );
  assert.match(
    buildPrompt({
      pr: { number: 1, title: "t", body: "b", base: { ref: "main" }, head: { ref: "branch" }, draft: false },
      files: [],
      diff: "",
      instructions: "",
      deterministic: { pass: true },
    }),
    /Return minified JSON on a single line/,
  );
  assert.equal(buildDiff([{ filename: "a.txt", previous_filename: "old.txt", patch: "@@ -1 +1 @@\n-a\n+b" }]).blockers.length, 0);
  assert.equal(buildDiff([{ filename: "image.png" }]).blockers.length, 1);
  console.log("copilot-merge-readiness self-test passed");
}

if (process.argv.includes("--self-test")) {
  selfTest();
} else {
  run().catch((error) => {
    console.error(error.message);
    process.exit(1);
  });
}
