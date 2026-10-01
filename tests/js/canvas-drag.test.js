const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { JSDOM } = require('jsdom');
const layout = require('../../assets/js/canvas-layout.js');

function bridge(t, { free = false, columns = false, boxes = [[20, 40, 180, 60], [20, 180, 180, 60], [20, 320, 180, 60]], image = false } = {}) {
	const page = new JSDOM('<section data-pb-section="pb-test" data-pb-root-index="0" data-pb-canvas-section="pb-test" data-pb-canvas-path="0" data-pb-canvas-type="core/' + (columns ? 'columns' : 'group') + '"></section>', { url: 'https://builder.test/', runScripts: 'outside-only', pretendToBeVisual: true });
	t.after(() => page.window.close());
	const win = page.window, doc = win.document, root = doc.querySelector('section'), messages = [], frames = new Map(), captures = [];
	let frameId = 0;
	Object.defineProperties(win, { innerWidth: { value: 1000 }, innerHeight: { value: 600 }, scrollX: { value: 0, writable: true }, scrollY: { value: 0, writable: true } });
	Object.defineProperties(doc.documentElement, { scrollHeight: { value: 2000 }, scrollWidth: { value: 1000 } });
	win.requestAnimationFrame = fn => { frames.set(++frameId, fn); return frameId; };
	win.cancelAnimationFrame = id => frames.delete(id);
	win.scrollBy = (x, y) => { win.scrollX += x; win.scrollY += y; win.dispatchEvent(new win.Event('scroll')); };
	win.postMessage = message => messages.push(message);
	win.gtPbCanvasLayout = layout;
	const rect = ([left, top, width, height]) => ({ left: left-win.scrollX, top: top-win.scrollY, right: left+width-win.scrollX, bottom: top+height-win.scrollY, width, height });
	root.style.border = '0 solid transparent';
	root.getBoundingClientRect = () => rect([0, 0, 1000, 1200]);
	if (free) root.className = 'pb-freeform';
	const items = boxes.map((box, i) => {
		const item = doc.createElement(columns ? 'div' : image && i === 0 ? 'figure' : 'p');
		item.id = 'authored-' + i;
		item.dataset.pbCanvasSection = 'pb-test';
		item.dataset.pbCanvasPath = '0.' + i;
		item.dataset.pbCanvasType = 'core/' + (columns ? 'column' : image && i === 0 ? 'image' : 'paragraph');
		item.innerHTML = image && i === 0 ? '<img id="authored-image" src="/test.png" alt="Test">' : 'Block ' + i;
		item.getBoundingClientRect = () => rect(box);
		root.appendChild(item);
		return item;
	});
	win.eval(fs.readFileSync(require.resolve('../../assets/js/canvas-bridge.js'), 'utf8'));
	win.eval(win.gtPbCanvasBridge.script({}, true));
	items[0].click();
	const move = doc.querySelector('[data-pb-move]'), resize = doc.querySelector('.pb-canvas-resize');
	for (const target of [move, resize, ...items]) {
		target.setPointerCapture = id => captures.push(['set', id]);
		target.releasePointerCapture = id => captures.push(['release', id]);
	}
	function pointer(type, x, y, props = {}, target = win) {
		const event = new win.MouseEvent(type, { bubbles: true, cancelable: true, button: 0, clientX: x, clientY: y, ...props });
		Object.defineProperty(event, 'pointerId', { value: props.pointerId ?? 7 });
		target.dispatchEvent(event);
		return event;
	}
	return { win, doc, root, items, messages, captures, frames, pointer, move, resize,
		start: (props = {}, target = move) => pointer('pointerdown', 50, 30, props, target),
		frame: (time = 16) => { const jobs = [...frames.values()]; frames.clear(); jobs.forEach(fn => fn(time)); },
		mutations: () => messages.filter(m => ['pb_canvas_layout', 'pb_canvas_reorder'].includes(m.type)),
	};
}

test('a click or small pointer wobble never creates a ghost or a layout change', t => {
	const b = bridge(t, { free: true });
	b.start(); b.pointer('pointermove', 53, 32);
	assert.equal(b.doc.querySelector('[data-pb-ghost]'), null);
	assert.equal(b.items[0].style.visibility, '');
	b.pointer('pointerup', 53, 32);
	assert.equal(b.mutations().length, 0);
	assert.deepEqual(b.captures, [['set', 7], ['release', 7]]);
});

test('freeform drag tracks the pointer from its original offset and strips clone ids and native paths', t => {
	const b = bridge(t, { free: true, image: true });
	b.start(); b.pointer('pointermove', 87, 81, { ctrlKey: true });
	const ghost = b.doc.querySelector('[data-pb-ghost]');
	assert.equal(ghost.style.left, '57px'); assert.equal(ghost.style.top, '91px');
	assert.equal(ghost.style.gridArea, 'auto');
	assert.equal(ghost.querySelector('[id]'), null); assert.equal(ghost.id, '');
	assert.equal(ghost.hasAttribute('data-pb-canvas-path'), false);
	assert.equal(ghost.getAttribute('aria-hidden'), 'true');
	assert.equal(b.items[0].style.visibility, 'hidden');
	b.pointer('pointerup', 87, 81);
	assert.equal(b.mutations().length, 1);
	const result = b.mutations()[0];
	assert.equal(result.elements[0].x, 57); assert.equal(result.elements[0].y, 91);
	assert.equal(result.precise, true);
	assert.equal(b.items[0].style.visibility, ''); assert.equal(b.doc.querySelector('[data-pb-ghost]'), null);
});

test('Shift axis locking, Alt duplication and resize retain their freeform behavior', t => {
	const b = bridge(t, { free: true });
	b.start({ altKey: true }); b.pointer('pointermove', 91, 41, { shiftKey: true, metaKey: true });
	assert.equal(b.items[0].style.visibility, '');
	b.pointer('pointerup', 91, 41);
	assert.equal(b.mutations()[0].duplicate, true);
	assert.equal(b.mutations()[0].elements[0].x, 61); assert.equal(b.mutations()[0].elements[0].y, 40);
	b.start({}, b.resize); b.pointer('pointermove', 120, 80); b.pointer('pointerup', 120, 80);
	const resized = b.mutations()[1].elements[0];
	assert.equal(resized.w, 250); assert.equal(resized.h, 110);
	assert.equal(resized.x, 20); assert.equal(resized.y, 40);
});

test('an unrelated pointer cannot move, commit or cancel the captured gesture', t => {
	const b = bridge(t, { free: true });
	b.start(); b.pointer('pointermove', 200, 200, { pointerId: 8 });
	assert.equal(b.doc.querySelector('[data-pb-ghost]'), null);
	b.pointer('pointercancel', 200, 200, { pointerId: 8 });
	b.pointer('pointermove', 80, 80, { ctrlKey: true });
	b.pointer('pointerup', 80, 80, { pointerId: 8 });
	assert.ok(b.doc.querySelector('[data-pb-ghost]')); assert.equal(b.mutations().length, 0);
	b.pointer('pointerup', 80, 80);
	assert.equal(b.mutations().length, 1);
});

test('Auto placement shows an insertion destination and preserves layout until the drop', t => {
	const b = bridge(t);
	b.start(); b.pointer('pointermove', 50, 410);
	const cue = b.doc.querySelector('.pb-canvas-insertion');
	assert.equal(cue.hidden, false); assert.equal(cue.style.top, '379px');
	assert.equal(b.doc.querySelector('.pb-canvas-drag-label').textContent, 'Drop after paragraph');
	assert.equal(b.items[0].style.opacity, '0.35');
	assert.deepEqual([...b.root.children].map(item => item.id), ['authored-0', 'authored-1', 'authored-2']);
	b.pointer('pointerup', 50, 410);
	assert.equal(b.mutations().length, 1);
	assert.equal(b.mutations()[0].targetPath, '0.2'); assert.equal(b.mutations()[0].after, true);
	assert.equal(b.doc.querySelector('.pb-canvas-insertion'), null); assert.equal(b.items[0].style.opacity, '');
	assert.equal(b.doc.querySelector('.pb-canvas-tools').style.visibility, '');
});

test('Columns use horizontal targeting even when their tops and heights differ', t => {
	const b = bridge(t, { columns: true, boxes: [[20, 20, 180, 420], [220, 280, 180, 100], [420, 20, 180, 420]] });
	b.start(); b.pointer('pointermove', 590, 300);
	const cue = b.doc.querySelector('.pb-canvas-insertion');
	assert.equal(cue.style.width, '3px'); assert.equal(cue.style.left, '599px');
	assert.equal(cue.style.height, '420px');
	b.pointer('pointerup', 590, 300);
	assert.equal(b.mutations()[0].targetPath, '0.2'); assert.equal(b.mutations()[0].after, true);
});

test('wrapped columns target the hovered row rather than the first block at the same x position', t => {
	const b = bridge(t, { columns: true, boxes: [[20, 20, 180, 100], [220, 20, 180, 100], [20, 220, 180, 100], [220, 220, 180, 100]] });
	b.start(); b.pointer('pointermove', 380, 260); b.pointer('pointerup', 380, 260);
	assert.equal(b.mutations()[0].targetPath, '0.3'); assert.equal(b.mutations()[0].after, true);
});

test('stacked Columns target vertically and returning to the current position creates no history entry', t => {
	const b = bridge(t, { columns: true });
	b.start(); b.pointer('pointermove', 70, 190);
	assert.equal(b.doc.querySelector('.pb-canvas-insertion').hidden, true);
	b.pointer('pointerup', 70, 190); assert.equal(b.mutations().length, 0);
	b.start(); b.pointer('pointermove', 70, 370);
	assert.equal(b.doc.querySelector('.pb-canvas-insertion').style.height, '3px');
	b.pointer('pointerup', 70, 370); assert.equal(b.mutations()[0].targetPath, '0.2');
});

test('right-to-left Columns map the visual left edge to after in DOM reading order', t => {
	const b = bridge(t, { columns: true, boxes: [[420, 20, 180, 100], [220, 20, 180, 100], [20, 20, 180, 100]] });
	b.start(); b.pointer('pointermove', 25, 80); b.pointer('pointerup', 25, 80);
	assert.equal(b.mutations()[0].targetPath, '0.2'); assert.equal(b.mutations()[0].after, true);
});

test('edge scrolling updates Auto insertion geometry while the pointer stays still', t => {
	const b = bridge(t, { boxes: [[20, 40, 180, 60], [20, 360, 180, 60], [20, 500, 180, 60]] });
	b.start(); b.pointer('pointermove', 70, 590);
	const cue = b.doc.querySelector('.pb-canvas-insertion'), before = parseFloat(cue.style.top);
	b.frame();
	assert.ok(b.win.scrollY > 0);
	assert.equal(parseFloat(cue.style.top), before-b.win.scrollY);
	b.pointer('pointerup', 70, 590);
	assert.equal(b.mutations()[0].targetPath, '0.2');
	const stoppedAt = b.win.scrollY; b.frame(32); assert.equal(b.win.scrollY, stoppedAt);
});

test('freeform edge scrolling adds the scroll offset to the drag instead of jumping away from the pointer', t => {
	const b = bridge(t, { free: true });
	b.start(); b.pointer('pointermove', 70, 590, { ctrlKey: true });
	const before = parseFloat(b.doc.querySelector('[data-pb-ghost]').style.top);
	b.frame();
	assert.ok(b.win.scrollY > 0);
	assert.ok(Math.abs(parseFloat(b.doc.querySelector('[data-pb-ghost]').style.top) - Math.round(before+b.win.scrollY)) < 1);
	b.pointer('pointerup', 70, 590);
	assert.equal(b.mutations()[0].elements[0].y, Math.round(before+b.win.scrollY));
});

for (const trigger of ['Escape', 'pointercancel', 'lostpointercapture', 'blur']) {
	test(trigger + ' cancels cleanly without changing content or leaving a scroll loop', t => {
		const b = bridge(t, { free: true });
		b.start(); b.pointer('pointermove', 70, 590, { ctrlKey: true });
		if (trigger === 'Escape') b.doc.body.dispatchEvent(new b.win.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
		else if (trigger === 'blur') b.win.dispatchEvent(new b.win.Event('blur'));
		else b.pointer(trigger, 70, 590, {}, trigger === 'lostpointercapture' ? b.move : b.win);
		assert.equal(b.mutations().length, 0);
		assert.equal(b.doc.querySelector('[data-pb-ghost]'), null);
		assert.equal(b.items[0].style.visibility, '');
		assert.equal(b.doc.querySelector('.pb-canvas-tools').style.visibility, '');
		b.frame(); assert.equal(b.win.scrollY, 0);
		assert.deepEqual(b.captures, [['set', 7], ['release', 7]]);
	});
}

test('selected images support direct dragging, while paragraph body gestures keep text selection available', t => {
	const b = bridge(t, { image: true });
	b.start({}, b.items[0].querySelector('img')); b.pointer('pointermove', 70, 380); b.pointer('pointerup', 70, 380);
	assert.equal(b.mutations().length, 1);
	b.items[1].click();
	b.start({}, b.items[1]); b.pointer('pointermove', 70, 380); b.pointer('pointerup', 70, 380);
	assert.equal(b.mutations().length, 1);
});


test('a scrolling freeform container retains content coordinates and scrolls before its outer viewport', t => {
	const b = bridge(t, { free: true });
	b.root.style.overflow = 'auto';
	b.root.scrollTop = 100;
	Object.defineProperties(b.root, { scrollHeight: { value: 1400 }, scrollWidth: { value: 1000 }, clientHeight: { value: 400 }, clientWidth: { value: 1000 } });
	b.root.getBoundingClientRect = () => ({ left: 0, top: 0, right: 1000, bottom: 400, width: 1000, height: 400 });
	b.items[0].getBoundingClientRect = () => ({ left: 20, top: 140-b.root.scrollTop, right: 200, bottom: 200-b.root.scrollTop, width: 180, height: 60 });
	b.start(); b.pointer('pointermove', 70, 390, { ctrlKey: true });
	const before = parseFloat(b.doc.querySelector('[data-pb-ghost]').style.top);
	assert.equal(before, 500);
	b.frame();
	assert.ok(b.root.scrollTop > 100); assert.equal(b.win.scrollY, 0);
	assert.equal(parseFloat(b.doc.querySelector('[data-pb-ghost]').style.top), Math.round(before+b.root.scrollTop-100));
	b.pointer('pointerup', 70, 390);
	assert.equal(b.mutations()[0].original.y, 140);
});

test('cancelling Auto reorder restores authored opacity and removes both insertion indicators', t => {
	const b = bridge(t);
	b.items[0].style.opacity = '.7';
	b.start(); b.pointer('pointermove', 70, 410);
	b.pointer('pointercancel', 70, 410);
	assert.equal(b.mutations().length, 0);
	assert.equal(b.items[0].style.opacity, '0.7');
	assert.equal(b.doc.querySelector('.pb-canvas-insertion'), null);
	assert.equal(b.doc.querySelector('.pb-canvas-drag-label'), null);
});


test('removing a source during a captured gesture safely cancels the later drop', t => {
	const b = bridge(t, { free: true });
	b.start(); b.pointer('pointermove', 70, 80);
	b.items[0].remove(); b.pointer('pointerup', 70, 80);
	assert.equal(b.mutations().length, 0);
	assert.equal(b.doc.querySelector('[data-pb-ghost]'), null);
	assert.deepEqual(b.captures, [['set', 7], ['release', 7]]);
});
