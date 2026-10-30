'use strict';
global.document = { currentScript: { dataset: {} }, createElement: function () { return {}; } };
global.window = {};
require('../assets/js/mapper-snippet.js');
const lib = window.TagVerityMapperSnippet;
const cases = [
  [lib.isMatchingAck('a1', {type: 'element_selected_ack', selection_id: 'a1'}) === true, 'matching id acknowledges'],
  [lib.isMatchingAck('b2', {type: 'element_selected_ack', selection_id: 'a1'}) === false, 'stale id does not acknowledge'],
  [lib.isMatchingAck('', {type: 'element_selected_ack', selection_id: ''}) === false, 'empty pending id never matches'],
  [lib.isMatchingAck('a1', {type: 'other', selection_id: 'a1'}) === false, 'wrong message type does not acknowledge'],
];
const failed = cases.filter(function (c) { return !c[0]; }).map(function (c) { return c[1]; });
if (failed.length) {
  console.error('MapperSnippet ack tests failed: ' + failed.join('; '));
  process.exit(1);
}
console.log('MapperSnippet ack tests passed.');
