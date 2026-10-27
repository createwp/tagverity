'use strict';
global.document = { currentScript: { dataset: {} }, createElement: function () { return {}; } };
global.window = {};
require('../assets/js/mapper-snippet.js');
const lib = window.TagVerityMapperSnippet;
const fakeElement = {tagName: 'BUTTON', id: '', className: 'book-consultation', textContent: 'Book now', attributes: [], getAttribute: function () { return null; }, closest: function () { return null; }, classList: ['book-consultation']};
const selector = lib.selectorFor(fakeElement);
if (typeof selector !== 'string' || selector.indexOf('book-consultation') === -1) {
  console.error('selectorFor did not produce a class-based selector: ' + selector);
  process.exit(1);
}
console.log('MapperSnippet selector tests passed.');
