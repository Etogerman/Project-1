import assert from "node:assert/strict";
import test from "node:test";
import {
  analyzeMarkdown,
  restoreInlineCodeTokens,
} from "../lib/markdown-visibility.mjs";

const CLOSING_ISSUE_PATTERN = /\b(?:close(?:s|d)?|fix(?:es|ed)?|resolve(?:s|d)?)\s+#\d+\b/i;
const LINKED_ISSUES_FIELD_PATTERN = /(?:^|\n)Связанные задачи\s*:/gi;

function hasVisibleClosingKeyword(source) {
  return CLOSING_ISSUE_PATTERN.test(analyzeMarkdown(source).visibleText);
}

function linkedIssuesFieldCount(source) {
  return [...analyzeMarkdown(source).visibleFieldText.matchAll(LINKED_ISSUES_FIELD_PATTERN)].length;
}

test("inline code of any delimiter length is excluded", () => {
  assert.equal(hasVisibleClosingKeyword("Пример: `Fixes #708` использовать нельзя."), false);
  assert.equal(hasVisibleClosingKeyword("Пример: ``Fixes #708`` использовать нельзя."), false);
  assert.equal(hasVisibleClosingKeyword("Пример: ```Fixes #708``` использовать нельзя."), false);
  assert.equal(hasVisibleClosingKeyword("Пример: `Fixes #708 использовать нельзя."), true);
});

test("closed and unclosed fenced code is excluded", () => {
  assert.equal(hasVisibleClosingKeyword("```text\nFixes #708\n```"), false);
  assert.equal(hasVisibleClosingKeyword("```text\nFixes #708"), false);
  assert.equal(hasVisibleClosingKeyword("~~~text\nFixes #708\n~~~"), false);
  assert.equal(hasVisibleClosingKeyword("~~~text\nFixes #708"), false);
});

test("backticks invalidate only backtick fence info strings", () => {
  const invalidBacktickFence = "Связанные задачи: не требуется\n```text`x\nСвязанные задачи: #708";
  const validTildeFence = "Связанные задачи: не требуется\n~~~text`x\nСвязанные задачи: #708";

  assert.equal(linkedIssuesFieldCount(invalidBacktickFence), 2);
  assert.equal(linkedIssuesFieldCount(validTildeFence), 1);
});

test("paragraph and indented-code context follows CommonMark containers", () => {
  assert.equal(hasVisibleClosingKeyword("Обычный текст\n    Fixes #708"), true);
  assert.equal(hasVisibleClosingKeyword("Обычный текст\n\tFixes #708"), true);
  assert.equal(hasVisibleClosingKeyword("Обычный текст\n\n    Fixes #708"), false);
  assert.equal(hasVisibleClosingKeyword("# Заголовок\n    Fixes #708"), false);
  assert.equal(hasVisibleClosingKeyword("Заголовок\n---\n    Fixes #708"), false);
});

test("list indentation is interpreted relative to the list container", () => {
  assert.equal(hasVisibleClosingKeyword("- пояснение\n    Fixes #708"), true);
  assert.equal(hasVisibleClosingKeyword("- пояснение\n\n    Fixes #708"), true);
  assert.equal(hasVisibleClosingKeyword("- пояснение\n\n        Fixes #708"), false);
});

test("blockquote code and visible prose remain distinguishable", () => {
  assert.equal(hasVisibleClosingKeyword("> пояснение\n>\n> Fixes #708"), true);
  assert.equal(hasVisibleClosingKeyword("> пояснение\n>\n>     Fixes #708"), false);
});

test("HTML comments are excluded through EOF", () => {
  assert.equal(hasVisibleClosingKeyword("<!--\nFixes #708\n-->"), false);
  assert.equal(hasVisibleClosingKeyword("<!--\nFixes #708"), false);
});

test("machine-readable fields are accepted only from paragraph contexts", () => {
  assert.equal(linkedIssuesFieldCount("Связанные задачи: #708"), 1);
  assert.equal(linkedIssuesFieldCount("- Связанные задачи: #708"), 1);
  assert.equal(linkedIssuesFieldCount("# Связанные задачи: #708"), 0);
  assert.equal(linkedIssuesFieldCount("> Связанные задачи: #708"), 0);
  assert.equal(linkedIssuesFieldCount("```text\nСвязанные задачи: #708\n```"), 0);
});

test("inline code values can be restored without exposing code as field labels", () => {
  const markdown = analyzeMarkdown("Spec revision: `abcdef1`\n`Связанные задачи: #708`");
  const specLine = markdown.fieldText.match(/^Spec revision:\s*(.+)$/m);

  assert.ok(specLine);
  assert.equal(restoreInlineCodeTokens(specLine[1], markdown.inlineCodeTokens), "abcdef1");
  assert.equal(linkedIssuesFieldCount("`Связанные задачи: #708`"), 0);
});

test("raw token-like input cannot alias generated inline-code tokens", () => {
  const rawMarker = "\uE000ab-inline-code:0:0\uE001";
  const markdown = analyzeMarkdown(`Справка: \`abcdef1\`\n\nSpec revision: ${rawMarker}`);
  const specLine = markdown.fieldText.match(/^Spec revision:\s*(.+)$/m);

  assert.ok(specLine);
  assert.equal(
    restoreInlineCodeTokens(specLine[1], markdown.inlineCodeTokens),
    rawMarker,
  );
});

test("CRLF and tab input keeps the same code visibility", () => {
  assert.equal(hasVisibleClosingKeyword("Обычный текст\r\n\tFixes #708"), true);
  assert.equal(hasVisibleClosingKeyword("Обычный текст\r\n\r\n\tFixes #708"), false);
});
