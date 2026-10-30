'use strict';
// app.js runs in a stubbed Node context: no DOM, so only pure helpers are exercised here.
global.document = { currentScript: { dataset: {} }, createElement: function () { return {}; } };
global.window = {};
require('../assets/js/app.js');
const ui = window.TagVerityUI;
if (!ui || typeof ui.isOutside !== 'function') {
  console.error('window.TagVerityUI.isOutside is missing');
  process.exit(1);
}

function fakeNode(children) {
  const node = {
    children: children || [],
    contains: function (other) {
      if (other === node) return true;
      return node.children.some(child => child === other || (child.contains && child.contains(other)));
    }
  };
  return node;
}

const button = fakeNode();
const panel = fakeNode();
const outsideNode = fakeNode();
const wrapper = fakeNode([button, panel]);

const checks = [
  ['target inside button is not outside', ui.isOutside(button, [button, panel]) === false],
  ['target inside panel is not outside', ui.isOutside(panel, [button, panel]) === false],
  ['target inside a wrapper element is not outside', ui.isOutside(button, [wrapper]) === false],
  ['unrelated node is outside', ui.isOutside(outsideNode, [button, panel]) === true],
  ['null target is outside', ui.isOutside(null, [button, panel]) === true],
  ['no elements means everything is outside', ui.isOutside(button, []) === true]
];

const failed = checks.filter(check => !check[1]).map(check => check[0]);
if (failed.length) {
  console.error('isOutside failed: ' + failed.join('; '));
  process.exit(1);
}
console.log('Reveal helper tests passed.');
