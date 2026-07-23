import { analyzeMarkdown, restoreInlineCodeTokens } from "./markdown-visibility.mjs";

const NO_LINKED_ISSUES_VALUE = "не требуется";

export const ISSUE_LINKAGE_DOCUMENTATION_LINES = Object.freeze([
  `\`Связанные задачи: ${NO_LINKED_ISSUES_VALUE} | #NNN | #NNN, #MMM\` — номера Issue должны быть положительными и уникальными; несколько номеров разделяются запятой и пробелом.`,
  `\`Основание связи: <профильная причина>\` — обязательно при указанных Issue; при \`Связанные задачи: ${NO_LINKED_ISSUES_VALUE}\` поле должно отсутствовать, быть пустым или содержать \`${NO_LINKED_ISSUES_VALUE}\`.`,
]);

export const PR_BODY_FIELD_DEFINITIONS = [
  ["changeType", "Тип изменения", true],
  ["substantialStream", "Существенный stream", true],
  ["deliveryLevel", "Уровень доставки", true],
  ["localMvp", "Локальный MVP", true],
  ["operatorAcceptance", "Операторская приёмка", true],
  ["authorSelfCheck", "Авторская самопроверка", true],
  ["linkedIssues", "Связанные задачи", true],
  ["blockers", "Блокеры", true],
  ["acceptedRisk", "Принятый риск", true],
  ["specRepo", "Spec repo"],
  ["specDoc", "Spec doc"],
  ["specRevision", "Spec revision"],
  ["linkBasis", "Основание связи"],
].map(([key, label, required]) => ({ key, label, required }));

export const SPEC_PR_BODY_FIELDS = PR_BODY_FIELD_DEFINITIONS.slice(9, 12);

const LINKED_ISSUES_VALUE_PATTERN = /^#[1-9]\d*(?:, #[1-9]\d*)*$/;
const NO_LINKED_ISSUES_VALUE_PATTERN = new RegExp(`^${NO_LINKED_ISSUES_VALUE}$`, "i");
const CLOSING_COMMAND_SOURCE = String.raw`\b(?:close(?:s|d)?|fix(?:es|ed)?|resolve(?:s|d)?)\b[ \t]*:?[ \t]+(?:https:\/\/github\.com\/[^/\s]+\/[^/\s]+\/issues\/[1-9]\d*|(?:[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+)?#[1-9]\d*)\b`;

function error(code, field, message) {
  return { code, field, message };
}

function invalidLinkedIssues(code, message) {
  return { valid: false, issues: [], code, error: message };
}

function parseLinkedIssuesValue(value) {
  const normalized = String(value || "").trim();

  if (NO_LINKED_ISSUES_VALUE_PATTERN.test(normalized)) {
    return { valid: true, noneRequired: true, issues: [] };
  }

  if (!LINKED_ISSUES_VALUE_PATTERN.test(normalized)) {
    return invalidLinkedIssues(
      "invalid_linked_issues",
      "ожидается `Связанные задачи: #NNN, #MMM` или `Связанные задачи: не требуется`",
    );
  }

  const issues = normalized.split(",").map((reference) => Number(reference.trim().slice(1)));

  if (issues.some((issueNumber) => !Number.isSafeInteger(issueNumber)) || new Set(issues).size !== issues.length) {
    return invalidLinkedIssues(
      "invalid_linked_issue_numbers", "недопустимый или повторяющийся номер в `Связанные задачи:`",
    );
  }

  return { valid: true, noneRequired: false, issues };
}

export function analyzePullRequestBodyContract(body = "") {
  const markdown = analyzeMarkdown(body);
  const fields = {};
  const occurrences = {};
  const errors = [];

  for (const field of PR_BODY_FIELD_DEFINITIONS) {
    const pattern = new RegExp(
      `^ {0,3}(?:[-*][^\\S\\n]*)?${field.label}[^\\S\\n]*:[^\\S\\n]*(.*?)\\r?$`,
      "gim",
    );
    const values = [...markdown.fieldText.matchAll(pattern)].map((match) => (
      restoreInlineCodeTokens(match[1], markdown.inlineCodeTokens).trim().replace(/\s+/g, " ")
    ));

    occurrences[field.key] = values.length;
    fields[field.key] = values[0] || null;

    if (values.length > 1) {
      errors.push(error("duplicate_field", field.key, `Дубликат поля \`${field.label}:\`.`));
    }

    if (field.required && (values.length === 0 || !fields[field.key])) {
      errors.push(error("missing_required_field", field.key, `Не заполнено поле \`${field.label}:\`.`));
    }
  }

  let linkedIssues;

  if (occurrences.linkedIssues === 1) {
    linkedIssues = parseLinkedIssuesValue(fields.linkedIssues);

    if (!linkedIssues.valid) {
      errors.push(error(linkedIssues.code, "linkedIssues", `${linkedIssues.error}.`));
    }
  } else {
    const linkedError = errors.find(({ field }) => field === "linkedIssues");
    linkedIssues = invalidLinkedIssues(
      linkedError?.code || "invalid_linked_issues", linkedError?.message || "Невалидное поле `Связанные задачи:`",
    );
  }

  if (linkedIssues.valid && occurrences.linkBasis <= 1) {
    if (linkedIssues.noneRequired && fields.linkBasis && !NO_LINKED_ISSUES_VALUE_PATTERN.test(fields.linkBasis)) {
      errors.push(error(
        "unexpected_link_basis",
        "linkBasis",
        "Для `Связанные задачи: не требуется` основание должно отсутствовать, быть пустым или `не требуется`.",
      ));
    } else if (!linkedIssues.noneRequired && (!fields.linkBasis || NO_LINKED_ISSUES_VALUE_PATTERN.test(fields.linkBasis))) {
      errors.push(error(
        "missing_link_basis",
        "linkBasis",
        "Список `Связанные задачи:` требует одно профильное `Основание связи:`.",
      ));
    }
  }

  const linkageErrors = errors.filter(({ field }) => field === "linkedIssues" || field === "linkBasis");

  if (linkageErrors.length > 0) {
    linkedIssues = {
      ...linkedIssues,
      valid: false,
      code: linkageErrors[0].code,
      error: linkageErrors.map(({ message }) => message.replace(/\.$/, "")).join(" "),
    };
  }

  return { errors, fields, linkedIssues };
}

export function parseLinkedIssuesFromBody(body = "") {
  return analyzePullRequestBodyContract(body).linkedIssues;
}

export function findCommitClosingCommands(message = "") {
  return [...String(message).matchAll(new RegExp(CLOSING_COMMAND_SOURCE, "giu"))].map((match) => match[0]);
}

export function analyzePrematureIssueClosing({ closingIssueReferences = [], commits = [] } = {}) {
  const commitCommands = commits.flatMap((commit) => findCommitClosingCommands(commit.message).map((command) => ({
    sha: commit.sha || "unknown", command,
  })));

  return {
    valid: closingIssueReferences.length === 0 && commitCommands.length === 0,
    closingIssueReferences,
    commitCommands,
  };
}

export async function collectClosingIssueReferences(loadPage) {
  const references = [];
  let cursor = null;

  for (;;) {
    const connection = await loadPage(cursor);

    if (
      !connection
      || !Array.isArray(connection.nodes)
      || connection.nodes.some((reference) => !reference || !Number.isSafeInteger(reference.number))
      || !connection.pageInfo
    ) throw new Error("GitHub closingIssuesReferences returned incomplete data.");

    references.push(...connection.nodes);

    if (!connection.pageInfo.hasNextPage) return references;
    if (!connection.pageInfo.endCursor || connection.pageInfo.endCursor === cursor) {
      throw new Error("GitHub closingIssuesReferences pagination did not advance.");
    }

    cursor = connection.pageInfo.endCursor;
  }
}

export function formatIssueNumbers(issueNumbers) {
  return issueNumbers.length > 0
    ? issueNumbers.map((issueNumber) => `#${issueNumber}`).join(", ")
    : "не требуется";
}
