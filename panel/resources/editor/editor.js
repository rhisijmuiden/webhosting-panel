// CodeMirror 6 bundle for the client file manager (window.WhpEditor).
// Rebuild with: npm ci && npm run build
import { EditorState, Compartment } from '@codemirror/state';
import {
  EditorView, keymap, lineNumbers, highlightActiveLine, highlightActiveLineGutter,
  drawSelection, dropCursor, rectangularSelection, crosshairCursor, highlightSpecialChars,
} from '@codemirror/view';
import { defaultKeymap, history, historyKeymap, indentWithTab } from '@codemirror/commands';
import { searchKeymap, highlightSelectionMatches, openSearchPanel } from '@codemirror/search';
import { autocompletion, completionKeymap, closeBrackets, closeBracketsKeymap } from '@codemirror/autocomplete';
import {
  syntaxHighlighting, HighlightStyle, indentOnInput, bracketMatching, foldGutter, foldKeymap,
  StreamLanguage, indentUnit,
} from '@codemirror/language';
import { tags as t } from '@lezer/highlight';
import { php } from '@codemirror/lang-php';
import { html } from '@codemirror/lang-html';
import { css } from '@codemirror/lang-css';
import { javascript } from '@codemirror/lang-javascript';
import { json } from '@codemirror/lang-json';
import { markdown } from '@codemirror/lang-markdown';
import { sql } from '@codemirror/lang-sql';
import { xml } from '@codemirror/lang-xml';
import { yaml } from '@codemirror/lang-yaml';
import { python } from '@codemirror/lang-python';
import { shell } from '@codemirror/legacy-modes/mode/shell';
import { nginx } from '@codemirror/legacy-modes/mode/nginx';
import { properties } from '@codemirror/legacy-modes/mode/properties';

const LANGS = {
  php: () => php(), phtml: () => php(), inc: () => php(),
  html: () => html(), htm: () => html(), twig: () => html(), blade: () => php(),
  css: () => css(), scss: () => css(), less: () => css(),
  js: () => javascript(), mjs: () => javascript(), cjs: () => javascript(),
  ts: () => javascript({ typescript: true }), jsx: () => javascript({ jsx: true }),
  tsx: () => javascript({ jsx: true, typescript: true }), vue: () => html(),
  json: () => json(), map: () => json(), webmanifest: () => json(),
  md: () => markdown(), markdown: () => markdown(),
  sql: () => sql(), xml: () => xml(), svg: () => xml(),
  yml: () => yaml(), yaml: () => yaml(), py: () => python(),
  sh: () => StreamLanguage.define(shell), bash: () => StreamLanguage.define(shell),
  conf: () => StreamLanguage.define(nginx),
  ini: () => StreamLanguage.define(properties), env: () => StreamLanguage.define(properties),
  htaccess: () => StreamLanguage.define(properties), properties: () => StreamLanguage.define(properties),
};

export function languageName(filename) {
  const name = (filename || '').toLowerCase();
  if (name.endsWith('.blade.php')) return 'blade';
  if (name === '.htaccess' || name === '.user.ini') return name === '.htaccess' ? 'htaccess' : 'ini';
  if (name === '.env' || name.startsWith('.env.')) return 'env';
  const ext = name.includes('.') ? name.split('.').pop() : '';
  return LANGS[ext] ? ext : '';
}

// Colours come from the panel's CSS tokens, so the editor follows the RHIS
// huisstijl and the light/dark theme without its own palette.
const highlight = HighlightStyle.define([
  { tag: [t.keyword, t.controlKeyword, t.moduleKeyword, t.operatorKeyword], color: 'var(--ed-keyword)', fontWeight: '600' },
  { tag: [t.string, t.special(t.string), t.regexp], color: 'var(--ed-string)' },
  { tag: [t.number, t.bool, t.null, t.atom], color: 'var(--ed-number)' },
  { tag: [t.comment, t.lineComment, t.blockComment, t.docComment], color: 'var(--ed-comment)', fontStyle: 'italic' },
  { tag: [t.function(t.variableName), t.function(t.propertyName)], color: 'var(--ed-function)' },
  { tag: [t.variableName, t.special(t.variableName)], color: 'var(--ed-variable)' },
  { tag: [t.typeName, t.className, t.namespace], color: 'var(--ed-type)' },
  { tag: [t.tagName, t.angleBracket], color: 'var(--ed-tag)' },
  { tag: [t.attributeName, t.propertyName], color: 'var(--ed-attr)' },
  { tag: [t.heading], color: 'var(--ed-keyword)', fontWeight: '700' },
  { tag: [t.link, t.url], color: 'var(--ed-string)', textDecoration: 'underline' },
  { tag: [t.meta, t.processingInstruction], color: 'var(--ed-comment)' },
  { tag: t.invalid, color: 'var(--status-offline)' },
]);

const theme = EditorView.theme({
  '&': { height: '100%', fontSize: '13.5px', backgroundColor: 'var(--ed-bg)', color: 'var(--ink)' },
  '.cm-scroller': { fontFamily: 'ui-monospace, "JetBrains Mono", "SFMono-Regular", Menlo, Consolas, monospace', lineHeight: '1.6' },
  '.cm-gutters': { backgroundColor: 'var(--ed-gutter)', color: 'var(--ink-muted)', border: 'none', borderRight: '1px solid var(--line)' },
  '.cm-activeLine': { backgroundColor: 'var(--ed-active)' },
  '.cm-activeLineGutter': { backgroundColor: 'var(--ed-active)', color: 'var(--ink)' },
  '.cm-cursor': { borderLeftColor: 'var(--koraal)', borderLeftWidth: '2px' },
  '&.cm-focused .cm-selectionBackground, .cm-selectionBackground, ::selection': { backgroundColor: 'var(--ed-selection) !important' },
  '.cm-matchingBracket': { backgroundColor: 'var(--ed-selection)', outline: '1px solid var(--lucht)' },
  '.cm-searchMatch': { backgroundColor: 'rgba(255, 107, 87, .25)' },
  '.cm-panels': { backgroundColor: 'var(--surface-raised)', color: 'var(--ink)', borderColor: 'var(--line)' },
  '.cm-panel input, .cm-panel button': { font: 'inherit', fontSize: '13px' },
  '.cm-tooltip': { backgroundColor: 'var(--surface-raised)', border: '1px solid var(--line)', borderRadius: '8px' },
  '.cm-tooltip-autocomplete > ul > li[aria-selected]': { backgroundColor: 'var(--nacht)', color: '#fff' },
});

export function create(parent, opts = {}) {
  const lang = new Compartment();
  const wrap = new Compartment();
  const tabs = new Compartment();
  const langName = languageName(opts.filename);
  const view = new EditorView({
    parent,
    state: EditorState.create({
      doc: opts.content || '',
      extensions: [
        lineNumbers(), highlightActiveLineGutter(), highlightSpecialChars(), history(), foldGutter(),
        drawSelection(), dropCursor(), EditorState.allowMultipleSelections.of(true), indentOnInput(),
        syntaxHighlighting(highlight), bracketMatching(), closeBrackets(), autocompletion(),
        rectangularSelection(), crosshairCursor(), highlightActiveLine(), highlightSelectionMatches(),
        keymap.of([
          { key: 'Mod-s', preventDefault: true, run: () => { opts.onSave && opts.onSave(); return true; } },
          ...closeBracketsKeymap, ...defaultKeymap, ...searchKeymap, ...historyKeymap, ...foldKeymap,
          ...completionKeymap, indentWithTab,
        ]),
        theme,
        lang.of(langName ? LANGS[langName]() : []),
        wrap.of(opts.wrap ? EditorView.lineWrapping : []),
        tabs.of(indentUnit.of(' '.repeat(opts.indent || 4))),
        EditorState.readOnly.of(!!opts.readOnly),
        EditorView.updateListener.of((u) => {
          if (u.docChanged && opts.onChange) opts.onChange();
          if (u.selectionSet && opts.onCursor) {
            const pos = u.state.selection.main.head;
            const line = u.state.doc.lineAt(pos);
            opts.onCursor(line.number, pos - line.from + 1);
          }
        }),
      ],
    }),
  });
  return {
    view,
    language: langName,
    getValue: () => view.state.doc.toString(),
    setWrap: (on) => view.dispatch({ effects: wrap.reconfigure(on ? EditorView.lineWrapping : []) }),
    search: () => openSearchPanel(view),
    focus: () => view.focus(),
    destroy: () => view.destroy(),
  };
}

window.WhpEditor = { create, languageName };
