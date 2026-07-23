import assert from "node:assert/strict";
import test from "node:test";
import { analyzeMarkdown, restoreInlineCodeTokens } from "../lib/markdown-visibility.mjs";
import {
  analyzePullRequestBodyContract, analyzePrematureIssueClosing, collectClosingIssueReferences,
  findCommitClosingCommands, parseLinkedIssuesFromBody, PR_BODY_FIELD_DEFINITIONS,
} from "../lib/pr-body-contract.mjs";

const linkedBody = (issues, basis) => (
  `Связанные задачи: ${issues}${basis === undefined ? "" : `\nОснование связи: ${basis}`}`
);

test("PR body contract matrix", async () => {
  const raw = "\uE000ab-inline-code:0:\uE001";
  const markdown = analyzeMarkdown(`${raw}\nSpec revision: \`abcdef1\``);
  const value = markdown.fieldText.match(/^Spec revision:\s*(.+)$/m)[1];
  assert.equal(restoreInlineCodeTokens(raw, markdown.inlineCodeTokens), raw);
  assert.equal(restoreInlineCodeTokens(value, markdown.inlineCodeTokens), "abcdef1");
  assert.equal(analyzePullRequestBodyContract("Spec revision: `abcdef1`").fields.specRevision, "abcdef1");
  for (const field of PR_BODY_FIELD_DEFINITIONS) {
    const body = field.key === "linkedIssues"
      ? `${linkedBody("не требуется")}\nСвязанные задачи: #708`
      : field.key === "linkBasis"
        ? `${linkedBody("#708", "первая")}\nОснование связи: вторая`
        : `${linkedBody("не требуется")}\n${field.label}: первое\n${field.label}: второе`;
    assert.ok(analyzePullRequestBodyContract(body).errors.some((item) => (
      item.code === "duplicate_field" && item.field === field.key
    )), field.label);
  }
  for (const body of [
    linkedBody("#708, #712", "задачи"), linkedBody("`#708`", "задача"),
    `- ${linkedBody("#708", "задача").replace("\n", "\r\n- ")}`,
    `Текст\n    ${linkedBody("#708", "задача")}`,
    linkedBody("не требуется"), linkedBody("не требуется", "не требуется"),
  ]) assert.equal(parseLinkedIssuesFromBody(body).valid, true, body);
  for (const body of [
    linkedBody("#708"), linkedBody("#708", "не требуется"), linkedBody("#708, #708", "причина"),
    linkedBody("Closes #708", "причина"), linkedBody("не требуется", "обсуждение"),
    linkedBody("`#708"), linkedBody("#708`"), `${linkedBody("#708", "причина")}\nСвязанные задачи: #712`,
    `\`\`\`text\n${linkedBody("#708")}`, `    ${linkedBody("#708")}`,
    `<!--\n${linkedBody("#708")}`, `> ${linkedBody("#708")}`,
  ]) assert.equal(parseLinkedIssuesFromBody(body).valid, false, body);
  for (const message of [
    "CLOSES: #708", "fixed Etogerman/Project-1#708", "Resolves https://github.com/Etogerman/Project-1/issues/708",
  ]) assert.ok(findCommitClosingCommands(message).length, message);
  assert.equal(analyzePrematureIssueClosing({ closingIssueReferences: [{ number: 708 }] }).valid, false);
  for (const position of [0, 1, 2]) {
    const commits = [0, 1, 2].map((index) => ({ sha: `${index}`, message: index === position ? "Fixes: #708" : "Обычный" }));
    assert.equal(analyzePrematureIssueClosing({ commits }).commitCommands[0].sha, `${position}`);
  }
  const references = await collectClosingIssueReferences(async (cursor) => (cursor === null
    ? { nodes: [{ number: 708 }], pageInfo: { hasNextPage: true, endCursor: "next" } }
    : { nodes: [{ number: 712 }], pageInfo: { hasNextPage: false, endCursor: "next" } }));
  assert.deepEqual(references.map(({ number }) => number), [708, 712]);
  await assert.rejects(collectClosingIssueReferences(async () => ({ nodes: [], pageInfo: { hasNextPage: true, endCursor: null } })), /did not advance/);
  await assert.rejects(collectClosingIssueReferences(async () => ({ nodes: [null], pageInfo: { hasNextPage: false } })), /incomplete/);
  await assert.rejects(collectClosingIssueReferences(async () => { throw new Error("offline"); }), /offline/);
});
