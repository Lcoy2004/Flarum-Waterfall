/**
 * Every translation Flarum renders goes through its ICU MessageFormat parser:
 * `Translator.trans()` hands the string to `format-message.rich()`, which both
 * substitutes `{placeholders}` and resolves plural/select forms. A string it
 * cannot parse throws a SyntaxError from `trans()`, and because the admin's
 * settings fields are built while the page renders, one bad string takes the
 * whole admin down with "reload and try again" — naming nothing.
 *
 * The trap is a literal brace. A bare `{` opens a placeholder, so an example
 * like `{"key": "value"}` has to escape both braces as `'{'` / `'}'`. That
 * escaping is what the ICU-quoted single quotes are for, and dropping them
 * reads like fixing broken quoting — so this file pins it.
 *
 * The parser is the one Flarum itself uses: `format-message-parse` is what
 * `format-message` (a dependency of @flarum/jest-config) calls, and the
 * locale YAML is read exactly as the backend ships it to the browser.
 *
 * Written in CommonJS on purpose: `extensionsToTreatAsEsm` in
 * @flarum/jest-config covers only .ts/.tsx, so a .js test gets `__dirname` and
 * `require` without the project having to hand-declare Node's typings.
 */
const fs = require('fs');
const path = require('path');
const yaml = require('js-yaml');
const parse = require('format-message-parse');

const LOCALE_DIR = path.join(__dirname, '../../../locale');

/** Every leaf in the nested translation tree, as `[dotted key, value]`. */
function translations(node, prefix = '', out = []) {
  for (const [key, value] of Object.entries(node)) {
    const dotted = prefix ? `${prefix}.${key}` : key;

    if (value !== null && typeof value === 'object') {
      translations(value, dotted, out);
    } else {
      out.push([dotted, String(value)]);
    }
  }

  return out;
}

describe('locale files', () => {
  for (const file of ['en.yml', 'zh-Hans.yml']) {
    it(`${file} parses as ICU MessageFormat`, () => {
      const document = yaml.load(fs.readFileSync(path.join(LOCALE_DIR, file), 'utf8'));
      const entries = translations(document);

      // A silently empty read (a moved file, a changed shape) would make the
      // loop below pass while checking nothing.
      expect(entries.length).toBeGreaterThan(100);

      const failures = entries
        .map(([key, value]) => {
          try {
            parse(value);

            return null;
          } catch (e) {
            return `${key}: ${e.message}  (value: ${JSON.stringify(value)})`;
          }
        })
        .filter((failure) => failure !== null);

      expect(failures).toEqual([]);
    });
  }
});
