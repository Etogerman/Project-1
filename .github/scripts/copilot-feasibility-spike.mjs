#!/usr/bin/env node
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import { appendFileSync } from "node:fs";

const PROMPT = [
  "Return exactly one strict JSON object and nothing else.",
  "Do not use markdown, code fences, comments, or explanatory text.",
  "The object must be:",
  "{\"status\":\"ok\",\"source\":\"copilot-cli\",\"message\":\"Copilot CLI headless JSON check passed\"}",
].join(" ");

function truncate(value = "", maxLength = 1600) {
  return value.length > maxLength ? `${value.slice(0, maxLength)}...` : value;
}

function parseStrictJson(output) {
  const trimmed = output.trim();

  if (!trimmed.startsWith("{") || !trimmed.endsWith("}")) {
    throw new Error("Copilot output is not strict JSON.");
  }

  return JSON.parse(trimmed);
}

function validatePayload(payload) {
  assert.equal(typeof payload, "object");
  assert.equal(Array.isArray(payload), false);
  assert.equal(payload.status, "ok");
  assert.equal(payload.source, "copilot-cli");
  assert.equal(typeof payload.message, "string");
  assert.notEqual(payload.message.trim(), "");

  const keys = Object.keys(payload).sort();
  assert.deepEqual(keys, ["message", "source", "status"]);

  return payload;
}

function writeSummary({ ok, authPath, payload, error }) {
  if (!process.env.GITHUB_STEP_SUMMARY) {
    return;
  }

  const lines = [
    "## Copilot CLI feasibility spike",
    "",
    `- Result: ${ok ? "passed" : "failed"}`,
    `- Auth path: ${authPath || "not confirmed"}`,
  ];

  if (payload) {
    lines.push(`- JSON status: \`${payload.status}\``);
    lines.push(`- JSON source: \`${payload.source}\``);
    lines.push(`- JSON message: ${payload.message}`);
  }

  if (error) {
    lines.push("");
    lines.push("### Error");
    lines.push("");
    lines.push("```text");
    lines.push(truncate(error));
    lines.push("```");
  }

  appendFileSync(process.env.GITHUB_STEP_SUMMARY, `${lines.join("\n")}\n`);
}

function runCopilot() {
  const authPath = "COPILOT_GITHUB_TOKEN";

  if (!process.env.COPILOT_GITHUB_TOKEN) {
    throw new Error(
      "Secret COPILOT_GITHUB_TOKEN is not configured. Create a user-owned fine-grained PAT with the Copilot Requests permission.",
    );
  }

  const result = spawnSync(
    "copilot",
    ["-p", PROMPT, "-s", "--no-ask-user"],
    {
      encoding: "utf8",
      env: {
        ...process.env,
        CI: "true",
        NO_COLOR: "1",
      },
      timeout: 120000,
    },
  );

  if (result.error) {
    throw result.error;
  }

  if (result.status !== 0) {
    throw new Error([
      `Copilot CLI exited with status ${result.status}.`,
      `stdout: ${truncate(result.stdout || "")}`,
      `stderr: ${truncate(result.stderr || "")}`,
    ].join("\n"));
  }

  const payload = validatePayload(parseStrictJson(result.stdout));
  writeSummary({ ok: true, authPath, payload });

  console.log(JSON.stringify({ authPath, ...payload }));
}

function selfTest() {
  const payload = validatePayload(parseStrictJson(
    "{\"status\":\"ok\",\"source\":\"copilot-cli\",\"message\":\"Copilot CLI headless JSON check passed\"}",
  ));

  assert.equal(payload.status, "ok");
  assert.throws(() => parseStrictJson("```json\n{\"status\":\"ok\"}\n```"), /strict JSON/);
  assert.throws(
    () => validatePayload({ status: "ok", source: "copilot-cli", message: "ok", extra: true }),
    /Expected values to be strictly deep-equal/,
  );

  console.log("copilot-feasibility-spike self-test passed");
}

if (process.argv.includes("--self-test")) {
  selfTest();
} else {
  try {
    runCopilot();
  } catch (error) {
    writeSummary({ ok: false, authPath: process.env.COPILOT_GITHUB_TOKEN ? "COPILOT_GITHUB_TOKEN" : null, error: error.message });
    console.error(error.message);
    process.exit(1);
  }
}
