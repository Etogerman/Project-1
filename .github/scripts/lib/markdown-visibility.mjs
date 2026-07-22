import * as commonmark from "commonmark";

const parser = new commonmark.Parser();
const INLINE_CODE_TOKEN_PREFIX = "\uE000ab-inline-code:";
const INLINE_CODE_TOKEN_END = "\uE001";

function emptyRenderedText(value = "") {
  return {
    visibleText: value,
    visibleFieldText: value,
    fieldText: value,
  };
}

function appendRenderedText(target, source) {
  target.visibleText += source.visibleText;
  target.visibleFieldText += source.visibleFieldText;
  target.fieldText += source.fieldText;
}

function chooseInlineCodeTokenPrefix(source) {
  let nonce = 0;
  let prefix = `${INLINE_CODE_TOKEN_PREFIX}${nonce}:`;

  while (source.includes(prefix)) {
    nonce += 1;
    prefix = `${INLINE_CODE_TOKEN_PREFIX}${nonce}:`;
  }

  return prefix;
}

function inlineCodeToken(context, index) {
  return `${context.inlineCodeTokenPrefix}${index}${INLINE_CODE_TOKEN_END}`;
}

function isHtmlComment(literal) {
  return /^\s*<!--/.test(String(literal || ""));
}

function renderChildren(node, context, allowMachineFields) {
  const rendered = emptyRenderedText();

  for (let child = node.firstChild; child; child = child.next) {
    appendRenderedText(rendered, renderNode(child, context, allowMachineFields));
  }

  return rendered;
}

function renderNode(node, context, allowMachineFields) {
  switch (node.type) {
    case "text":
      return emptyRenderedText(node.literal || "");

    case "softbreak":
    case "linebreak":
      return emptyRenderedText("\n");

    case "code": {
      const token = inlineCodeToken(context, context.inlineCodeTokens.length);
      context.inlineCodeTokens.push({
        token,
        value: node.literal || "",
      });

      return {
        visibleText: " ",
        visibleFieldText: " ",
        fieldText: token,
      };
    }

    case "code_block":
    case "thematic_break":
      return emptyRenderedText("\n");

    case "html_inline": {
      if (isHtmlComment(node.literal)) {
        return emptyRenderedText(" ");
      }

      return emptyRenderedText(node.literal || "");
    }

    case "html_block": {
      if (isHtmlComment(node.literal)) {
        return emptyRenderedText("\n");
      }

      const literal = node.literal || "";
      const suffix = literal.endsWith("\n") ? "" : "\n";

      return {
        visibleText: `${literal}${suffix}`,
        visibleFieldText: "\n",
        fieldText: "\n",
      };
    }

    case "heading": {
      const rendered = renderChildren(node, context, false);
      const headingText = rendered.visibleText.replace(/\s+/g, " ").trim();

      if (headingText !== "") {
        context.headings.push(headingText);
      }

      return {
        visibleText: `${rendered.visibleText}\n`,
        visibleFieldText: "\n",
        fieldText: "\n",
      };
    }

    case "paragraph": {
      const rendered = renderChildren(node, context, allowMachineFields);

      return {
        visibleText: `${rendered.visibleText}\n`,
        visibleFieldText: allowMachineFields ? `${rendered.visibleFieldText}\n` : "\n",
        fieldText: allowMachineFields ? `${rendered.fieldText}\n` : "\n",
      };
    }

    case "block_quote": {
      const rendered = renderChildren(node, context, false);

      return {
        visibleText: rendered.visibleText,
        visibleFieldText: "\n",
        fieldText: "\n",
      };
    }

    case "link": {
      const rendered = renderChildren(node, context, allowMachineFields);
      const destination = node.destination ? ` ${node.destination}` : "";

      return {
        visibleText: rendered.visibleText,
        visibleFieldText: `${rendered.visibleFieldText}${destination}`,
        fieldText: `${rendered.fieldText}${destination}`,
      };
    }

    default:
      return renderChildren(node, context, allowMachineFields);
  }
}

export function analyzeMarkdown(text) {
  const source = String(text);
  const context = {
    headings: [],
    inlineCodeTokenPrefix: chooseInlineCodeTokenPrefix(source),
    inlineCodeTokens: [],
  };
  const document = parser.parse(source);
  const rendered = renderNode(document, context, true);

  return {
    ...rendered,
    headings: context.headings,
    inlineCodeTokens: context.inlineCodeTokens,
  };
}

export function restoreInlineCodeTokens(text, inlineCodeTokens) {
  let restored = String(text);

  inlineCodeTokens.forEach(({ token, value }) => {
    restored = restored.replaceAll(token, value);
  });

  return restored;
}

export function stripMarkdownCode(text) {
  return analyzeMarkdown(text).visibleText;
}
