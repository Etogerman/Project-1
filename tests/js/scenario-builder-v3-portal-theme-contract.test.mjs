import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import test from 'node:test';

test('body portals share the scenario builder light and dark theme scope', () => {
    const appSource = readFileSync(
        join(process.cwd(), 'resources/js/scenario-builder-v3/App.jsx'),
        'utf8',
    );
    const styleSource = readFileSync(
        join(process.cwd(), 'resources/js/scenario-builder-v3/style.css'),
        'utf8',
    );
    const bodyPortalTargets = appSource.match(/document\.body\s*,?\s*\)/g) ?? [];
    const themedPortalRoots = appSource.match(
        /className="ac-v3-builder__portal ac-v3-builder__/g,
    ) ?? [];
    const lightThemeScope = styleSource.match(
        /\.ac-v3-builder,\s*\.ac-v3-builder__portal\s*\{([^}]*)\}/,
    )?.[1];
    const darkThemeScope = styleSource.match(
        /:where\(\.dark, \[data-theme="dark"\]\) \.ac-v3-builder,\s*:where\(\.dark, \[data-theme="dark"\]\) \.ac-v3-builder__portal\s*\{([^}]*)\}/,
    )?.[1];

    assert.equal(bodyPortalTargets.length, 2);
    assert.equal(themedPortalRoots.length, bodyPortalTargets.length);
    assert.match(
        appSource,
        /className="ac-v3-builder__portal ac-v3-builder__start-expression-popover"/,
    );
    assert.match(
        appSource,
        /className="ac-v3-builder__portal ac-v3-builder__dialog-field-suggestions"/,
    );
    assert.ok(lightThemeScope);
    assert.ok(darkThemeScope);
    assert.match(
        lightThemeScope,
        /--surface:\s*#ffffff;/,
    );
    assert.match(
        lightThemeScope,
        /font:\s*500 13px\/1\.45/,
    );
    assert.match(
        darkThemeScope,
        /--surface:\s*#171a1d;/,
    );
});
