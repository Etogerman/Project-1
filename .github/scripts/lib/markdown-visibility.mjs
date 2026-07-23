import * as commonmark from "commonmark";

const parser = new commonmark.Parser();
const TOKEN_PREFIX = "\uE000ab-inline-code:";
const TOKEN_END = "\uE001";

function chooseTokenPrefix(source) {
  let nonce = 0;
  while (source.includes(`${TOKEN_PREFIX}${nonce}:`)) nonce += 1;
  return `${TOKEN_PREFIX}${nonce}:`;
}

export function analyzeMarkdown(text) {
  const source = String(text);
  const result = { visibleText: "", fieldText: "", headings: [], inlineCodeTokens: [] };
  const tokenPrefix = chooseTokenPrefix(source);
  const walker = parser.parse(source).walker();
  let quoteDepth = 0;
  let fieldParagraph = false;
  let headingText = null;

  const append = (visible, field = visible) => {
    result.visibleText += visible;
    if (fieldParagraph) result.fieldText += field;
    if (headingText !== null) headingText += visible;
  };

  for (let event = walker.next(); event; event = walker.next()) {
    const { node, entering } = event;

    if (node.type === "block_quote") {
      quoteDepth += entering ? 1 : -1;
    } else if (node.type === "paragraph") {
      if (entering) fieldParagraph = quoteDepth === 0;
      else {
        result.visibleText += "\n";
        result.fieldText += "\n";
        fieldParagraph = false;
      }
    } else if (node.type === "heading") {
      if (entering) headingText = "";
      else {
        const heading = headingText.replace(/\s+/g, " ").trim();
        if (heading) result.headings.push(heading);
        result.visibleText += "\n";
        result.fieldText += "\n";
        headingText = null;
      }
    } else if (entering && node.type === "text") append(node.literal || "");
    else if (entering && ["softbreak", "linebreak"].includes(node.type)) append("\n");
    else if (entering && node.type === "code") {
      const token = `${tokenPrefix}${result.inlineCodeTokens.length}${TOKEN_END}`;
      result.inlineCodeTokens.push({ token, value: node.literal || "" });
      append(" ", token);
    } else if (!entering && node.type === "link" && fieldParagraph && node.destination) {
      result.fieldText += ` ${node.destination}`;
    } else if (entering && node.type === "html_inline" && !/^\s*<!--/.test(node.literal || "")) {
      append(node.literal || "");
    } else if (entering && node.type === "html_block") {
      if (!/^\s*<!--/.test(node.literal || "")) result.visibleText += node.literal || "";
      result.visibleText += "\n";
      result.fieldText += "\n";
    } else if (entering && ["code_block", "thematic_break"].includes(node.type)) {
      result.visibleText += "\n";
      result.fieldText += "\n";
    }
  }

  return { ...result, visibleFieldText: result.fieldText };
}

export function restoreInlineCodeTokens(text, tokens) {
  return tokens.reduce((value, token) => value.replaceAll(token.token, token.value), String(text));
}

export function stripMarkdownCode(text) {
  return analyzeMarkdown(text).visibleText;
}
