import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import trackerSource from '../../resources/js/tracker/index.js?raw';
import {createTracker, detectProvider, eligible, fieldMeta} from '../../resources/js/tracker/index.js';

function form(html) {
	document.body.innerHTML = html;
	return document.querySelector('form');
}

function decodedEvents(fetchMock) {
	return fetchMock.mock.calls.flatMap((call) => JSON.parse(call[1].body).events);
}

function installJQueryEventFacade() {
	window.jQuery = (target) => ({
		on(events, selector, handler) {
			events.split(/\s+/).forEach((eventName) => {
				target.addEventListener(eventName.split('.')[0], (event) => {
					const matched = event.target && event.target.closest ? event.target.closest(selector) : null;
					if (matched && target.contains(matched)) {
						handler.call(matched, event, event.detail);
					}
				});
			});
		},
		off() {},
	});
}

describe('provider detection', () => {
	it('recognizes CF7 before generic', () => {
		const element = form('<div class="wpcf7" data-wpcf7-id="17"><form id="contact"><input type="hidden" name="_wpcf7" value="17"></form></div>');
		expect(detectProvider(element, '/contact')).toMatchObject({provider: 'cf7', provider_form_id: '17'});
	});

	it('recognizes WPForms by its rendered stable form id', () => {
		const element = form('<div class="wpforms-container"><div class="wpforms-title">Request a Quote</div><form class="wpforms-form" id="wpforms-form-42" data-formid="42"></form></div>');
		expect(detectProvider(element, '/quote')).toEqual({provider: 'wpforms', provider_form_id: '42', title: 'Request a Quote', page_path: '/quote'});
	});

	it('does not treat an unrelated data-formid attribute as WPForms', () => {
		const element = form('<form data-formid="42" data-formhawk-id="custom-provider"><input name="email"></form>');
		expect(detectProvider(element, '/custom')).toMatchObject({provider: 'html', provider_form_id: 'custom-provider'});
	});

	it('recognizes Elementor by document and widget ids', () => {
		const element = form('<form class="elementor-form" name="Contact Us"><input type="hidden" name="post_id" value="81"><input type="hidden" name="form_id" value="a1b2c3d"></form>');
		expect(detectProvider(element, '/contact')).toEqual({provider: 'elementor', provider_form_id: '81:a1b2c3d', title: 'Contact Us', page_path: '/contact'});
	});

	it('keeps identical Elementor names and widget ids distinct by document context', () => {
		document.body.innerHTML = [
			'<form class="elementor-form" name="Volunteer Form"><input type="hidden" name="post_id" value="724"><input type="hidden" name="form_id" value="e21d53d"></form>',
			'<form class="elementor-form" name="Volunteer Form"><input type="hidden" name="post_id" value="732"><input type="hidden" name="form_id" value="e21d53d"></form>',
		].join('');
		const identities = Array.from(document.querySelectorAll('form')).map((element) => detectProvider(element, '/contact').provider_form_id);
		expect(identities).toEqual(['724:e21d53d', '732:e21d53d']);
	});

	it('uses generic only as fallback and prioritizes explicit identity', () => {
		const element = form('<form id="native" data-formhawk-id="stable-checkout"><input name="email" type="email"></form>');
		expect(detectProvider(element, '/checkout')).toMatchObject({provider: 'html', provider_form_id: 'stable-checkout'});
	});

	it('derives a stable structural identity instead of a DOM index', () => {
		const first = form('<form action="/lead" method="post"><input name="email" type="email"></form>');
		const firstId = detectProvider(first, '/landing').provider_form_id;
		document.body.innerHTML = '<aside><form id="unrelated"></form></aside><form action="/lead" method="post"><input name="email" type="email"></form>';
		const second = document.querySelector('form[action="/lead"]');
		expect(detectProvider(second, '/landing').provider_form_id).toBe(firstId);
	});
});

describe('field normalization', () => {
	it('keeps forbidden payload and persistence APIs out of production tracker source', () => {
		expect(trackerSource).not.toMatch(/\.value\b|\bFormData\b|\blocalStorage\b|\bsessionStorage\b|\bindexedDB\b|document\.cookie|preventDefault\s*\(/);
	});
	it('never extracts control contents or dynamic descendants from a wrapping label', () => {
		const element = form('<form><label>Message <textarea name="message">private-message</textarea><output>private-output</output><span contenteditable>private-editable</span></label><label>Topic <select name="topic"><option>private-option</option></select></label></form>');
		expect(fieldMeta(element.querySelector('textarea'), element)).toEqual({key: 'message', label: 'Message', type: 'textarea'});
		expect(fieldMeta(element.querySelector('select'), element)).toEqual({key: 'topic', label: 'Topic', type: 'select'});
		expect(fieldMeta(element.querySelector('[contenteditable]'), element)).toBeNull();
	});
	it('normalizes WPForms compound fields without choice values', () => {
		const element = form('<form class="wpforms-form" data-formid="42"><div class="wpforms-field wpforms-field-name" data-field-id="3"><label class="wpforms-field-label">Full name</label><input name="wpforms[fields][3][first]" type="text"><input name="wpforms[fields][3][last]" type="text"></div></form>');
		const fields = element.querySelectorAll('input');
		expect(fieldMeta(fields[0], element, 'wpforms')).toEqual({key: '3.first', label: 'Full name', type: 'name'});
		expect(fieldMeta(fields[1], element, 'wpforms')).toEqual({key: '3.last', label: 'Full name', type: 'name'});
	});

	it('groups WPForms checkbox choices under the stable field id', () => {
		const element = form('<form class="wpforms-form" data-formid="42"><div class="wpforms-field wpforms-field-checkbox" data-field-id="8"><label class="wpforms-field-label">Topics</label><input name="wpforms[fields][8][]" type="checkbox" value="private-choice"></div></form>');
		expect(fieldMeta(element.querySelector('input'), element, 'wpforms')).toEqual({key: '8', label: 'Topics', type: 'checkbox'});
	});

	it('normalizes Elementor field ids and ignores hidden/non-input controls', () => {
		const element = form('<form class="elementor-form"><div class="elementor-field-group elementor-field-type-email"><label class="elementor-field-label">Work email</label><input name="form_fields[email_address]" type="email"></div><input type="hidden" name="form_id" value="widget"><button type="button">Next</button></form>');
		expect(fieldMeta(element.querySelector('input[type="email"]'), element, 'elementor')).toEqual({key: 'email_address', label: 'Work email', type: 'email'});
		expect(fieldMeta(element.querySelector('input[type="hidden"]'), element, 'elementor')).toBeNull();
		expect(fieldMeta(element.querySelector('button'), element, 'elementor')).toBeNull();
	});

	it('normalizes Elementor checkbox groups without reading choice values', () => {
		const element = form('<form class="elementor-form"><div class="elementor-field-group elementor-field-type-checkbox"><input name="form_fields[field_7bc2634][]" type="checkbox" value="private-choice"><input name="form_fields[field_7bc2634][]" type="checkbox" value="another-private-choice"></div></form>');
		const fields = element.querySelectorAll('input');
		expect(fieldMeta(fields[0], element, 'elementor')).toEqual({key: 'field_7bc2634', label: '', type: 'checkbox'});
		expect(fieldMeta(fields[1], element, 'elementor')).toEqual({key: 'field_7bc2634', label: '', type: 'checkbox'});
		expect(JSON.stringify(fieldMeta(fields[0], element, 'elementor'))).not.toContain('private-choice');
	});

	it('keeps generic forms eligible while excluding search and ignored forms', () => {
		expect(eligible(form('<form><input name="message"></form>'))).toBe(true);
		expect(eligible(form('<form role="search"></form>'))).toBe(false);
		expect(eligible(form('<form data-formhawk-ignore></form>'))).toBe(false);
	});
});

describe('tracker lifecycle, deduplication, and privacy', () => {
	let fetchMock;
	let trackers;

	beforeEach(() => {
		document.body.innerHTML = '';
		fetchMock = vi.fn(() => Promise.resolve({ok: true}));
		window.fetch = fetchMock;
		delete window.IntersectionObserver;
		delete window.jQuery;
		trackers = [];
	});

	afterEach(() => {
		trackers.forEach((tracker) => tracker.destroy());
		vi.restoreAllMocks();
		delete window.jQuery;
	});

	function tracker(config) {
		const instance = createTracker(window, document, config);
		trackers.push(instance);
		return instance;
	}

	it('preserves pending validation evidence when a terminal event arrives before the coalescing task', () => {
		const element = form('<form id="pending"><input name="email" required></form>');
		const instance = tracker({endpoint: '/events', token: 'public', path: '/pending'});
		document.dispatchEvent(new Event('DOMContentLoaded'));
		element.querySelector('input').dispatchEvent(new Event('invalid'));
		element.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
		instance.flush();
		expect(decodedEvents(fetchMock).filter((event) => event.type === 'client_validation_failure')).toHaveLength(1);
		expect(decodedEvents(fetchMock).filter((event) => event.type === 'form_submit')).toHaveLength(1);
	});

	it('falls back safely when optional observer constructors throw', () => {
		window.IntersectionObserver = class { constructor() { throw new Error('Unavailable'); } };
		const original = window.MutationObserver;
		window.MutationObserver = class { constructor() { throw new Error('Unavailable'); } };
		try {
			form('<form id="fallback"><input name="email"></form>');
			const instance = tracker({endpoint: '/events', token: 'public'});
			document.dispatchEvent(new Event('DOMContentLoaded'));
			instance.flush();
			expect(decodedEvents(fetchMock).filter((event) => event.type === 'form_view')).toHaveLength(1);
		} finally {
			window.MutationObserver = original;
			delete window.IntersectionObserver;
		}
	});

	it('captures new dynamic fields while keeping existing personalized labels out', async () => {
		const element = form('<form id="dynamic-field"><input name="first"></form>');
		const instance = tracker({endpoint: '/events', token: 'public'});
		document.dispatchEvent(new Event('DOMContentLoaded'));
		element.insertAdjacentHTML('beforeend', '<label for="new">Static label</label><input id="new" name="new">');
		await new Promise((resolve) => window.setTimeout(resolve, 1));
		element.querySelector('label').textContent = 'private-dynamic-text';
		element.querySelector('#new').dispatchEvent(new Event('input', {bubbles: true}));
		instance.flush();
		expect(decodedEvents(fetchMock).find((event) => event.type === 'field_interaction').field.label).toBe('Static label');
		expect(JSON.stringify(decodedEvents(fetchMock))).not.toContain('private-dynamic-text');
	});

	it('uses metadata captured before visitor interaction when labels are personalized later', () => {
		const element = form('<form id="lead"><label for="email">Email</label><input id="email" name="email"></form>');
		const instance = tracker({endpoint: '/events', token: 'public', path: '/lead'});
		document.dispatchEvent(new Event('DOMContentLoaded'));
		element.querySelector('label').textContent = 'visitor@example.test';
		const input = element.querySelector('input');
		Object.defineProperty(input, 'value', {get() { throw new Error('Analytics read a value'); }});
		input.dispatchEvent(new Event('input', {bubbles: true}));
		instance.flush();
		expect(JSON.stringify(decodedEvents(fetchMock))).not.toContain('visitor@example.test');
		expect(decodedEvents(fetchMock).find((event) => event.type === 'field_interaction').field.label).toBe('Email');
	});

	it('emits one lifecycle for a generic form and never sends its entered value', () => {
		const element = form('<form data-formhawk-id="lead"><label for="email">Email</label><input id="email" name="email" type="email"></form>');
		const input = element.querySelector('input');
		input.value = 'visitor@example.test';
		const trackerInstance = tracker({endpoint: '/events', token: 'public', path: '/lead'});
		document.dispatchEvent(new Event('DOMContentLoaded'));
		input.dispatchEvent(new Event('focusin', {bubbles: true}));
		input.dispatchEvent(new Event('input', {bubbles: true}));
		element.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
		trackerInstance.flush();
		const events = decodedEvents(fetchMock);
		expect(events.filter((event) => event.type === 'form_view')).toHaveLength(1);
		expect(events.filter((event) => event.type === 'form_start')).toHaveLength(1);
		expect(events.filter((event) => event.type === 'field_interaction')).toHaveLength(1);
		expect(events.filter((event) => event.type === 'form_submit')).toHaveLength(1);
		expect(JSON.stringify(events)).not.toContain('visitor@example.test');
	});

	it('does not classify provider forms as generic or duplicate a submit event', () => {
		const element = form('<form class="wpforms-form" data-formid="7"><input name="wpforms[fields][1]" type="text"></form>');
		const trackerInstance = tracker({endpoint: '/events', token: 'public', path: '/mixed'});
		document.dispatchEvent(new Event('DOMContentLoaded'));
		element.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
		element.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
		trackerInstance.flush();
		const submitEvents = decodedEvents(fetchMock).filter((event) => event.type === 'form_submit');
		expect(submitEvents).toHaveLength(1);
		expect(submitEvents[0].provider).toBe('wpforms');
	});

	it('coalesces native field errors into one validation failure', async () => {
		const element = form('<form data-formhawk-id="validation"><input name="email" type="email"><input name="phone" type="tel"></form>');
		const trackerInstance = tracker({endpoint: '/events', token: 'public', path: '/validation'});
		document.dispatchEvent(new Event('DOMContentLoaded'));
		const fields = element.querySelectorAll('input');
		fields[0].dispatchEvent(new Event('invalid', {bubbles: false, cancelable: true}));
		fields[1].dispatchEvent(new Event('invalid', {bubbles: false, cancelable: true}));
		await new Promise((resolve) => window.setTimeout(resolve, 1));
		trackerInstance.flush();
		const failures = decodedEvents(fetchMock).filter((event) => event.type === 'client_validation_failure');
		expect(failures).toHaveLength(1);
		expect(failures[0].fields.map((field) => field.key)).toEqual(['email', 'phone']);
	});

	it('deduplicates native and jQuery friction by lifecycle even after the old debounce window', async () => {
		installJQueryEventFacade();
		const element = form('<form class="wpforms-form" data-formid="73"><input name="wpforms[fields][1]" required></form>');
		const input = element.querySelector('input');
		const instance = tracker({endpoint: '/events', token: 'public', path: '/native'});
		document.dispatchEvent(new Event('DOMContentLoaded'));
		expect(element.checkValidity()).toBe(false);
		await new Promise((resolve) => window.setTimeout(resolve, 1));
		vi.spyOn(Date, 'now').mockReturnValue(Date.now() + 5000);
		element.dispatchEvent(new CustomEvent('invalid-form', {bubbles: true, detail: {errorList: [{element: input}]}}));
		expect(element.checkValidity()).toBe(false);
		await new Promise((resolve) => window.setTimeout(resolve, 1));
		instance.flush();
		const events = decodedEvents(fetchMock);
		expect(events.filter((event) => event.type === 'client_validation_failure')).toHaveLength(1);
		expect(events.filter((event) => event.type === 'form_submit')).toHaveLength(0);
		expect(events.filter((event) => event.type === 'validation_error')).toHaveLength(0);
		expect(events.some((event) => event.source === 'provider')).toBe(false);
	});

	it('flushes native validation before pagehide without waiting for a timer', () => {
		const element = form('<form data-formhawk-id="leave"><input name="email" required></form>');
		tracker({endpoint: '/events', token: 'public', path: '/leave'});
		document.dispatchEvent(new Event('DOMContentLoaded'));
		element.checkValidity();
		window.dispatchEvent(new Event('pagehide'));
		expect(decodedEvents(fetchMock).filter((event) => event.type === 'client_validation_failure')).toHaveLength(1);
		expect(decodedEvents(fetchMock).filter((event) => event.type === 'form_submit')).toHaveLength(0);
	});

	it('bounds a validation field batch to fifty structural keys', async () => {
		const element = form('<form data-formhawk-id="large">' + Array.from({length: 80}, (_, index) => '<input name="field-' + index + '" required>').join('') + '</form>');
		const instance = tracker({endpoint: '/events', token: 'public', path: '/large'});
		document.dispatchEvent(new Event('DOMContentLoaded'));
		element.checkValidity();
		await new Promise((resolve) => window.setTimeout(resolve, 1));
		instance.flush();
		const reports = decodedEvents(fetchMock).filter((event) => event.type === 'client_validation_failure');
		expect(reports).toHaveLength(1);
		expect(reports[0].fields).toHaveLength(50);
	});

	it('does not throw when fetch fails synchronously', () => {
		window.fetch = () => { throw new Error('Blocked API'); };
		const element = form('<form data-formhawk-id="blocked"><input name="email"></form>');
		const instance = tracker({endpoint: '/events', token: 'public', path: '/blocked'});
		document.dispatchEvent(new Event('DOMContentLoaded'));
		expect(() => {
			element.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
			instance.flush();
		}).not.toThrow();
	});

	it('allows abandonment after a provider submit returns validation errors', async () => {
		const element = form('<form class="wpforms-form" data-formid="74"><input name="wpforms[fields][2]" type="email"></form>');
		const input = element.querySelector('input');
		tracker({endpoint: '/events', token: 'public', path: '/validation-abandon'});
		document.dispatchEvent(new Event('DOMContentLoaded'));
		input.dispatchEvent(new Event('focusin', {bubbles: true}));
		element.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
		input.dispatchEvent(new Event('invalid', {bubbles: false, cancelable: true}));
		await new Promise((resolve) => window.setTimeout(resolve, 1));
		window.dispatchEvent(new Event('pagehide'));
		const events = decodedEvents(fetchMock);
		expect(events.filter((event) => event.type === 'client_validation_failure')).toHaveLength(1);
		expect(events.filter((event) => event.type === 'form_abandon')).toHaveLength(1);
	});

	it('keeps Elementor active after a provider error and abandons it on pagehide', () => {
		installJQueryEventFacade();
		const element = form('<form class="elementor-form"><input type="hidden" name="post_id" value="81"><input type="hidden" name="form_id" value="widget-error"><input name="form_fields[email]" type="email"></form>');
		const input = element.querySelector('input[type="email"]');
		tracker({endpoint: '/events', token: 'public', path: '/elementor-error'});
		document.dispatchEvent(new Event('DOMContentLoaded'));
		input.dispatchEvent(new Event('focusin', {bubbles: true}));
		element.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
		element.dispatchEvent(new CustomEvent('error', {bubbles: true}));
		window.dispatchEvent(new Event('pagehide'));
		const events = decodedEvents(fetchMock);
		expect(events.filter((event) => event.type === 'form_submit')).toHaveLength(1);
		expect(events.filter((event) => event.type === 'form_abandon')).toHaveLength(1);
	});

	it('marks only the matching WPForms instance complete after provider success', () => {
		installJQueryEventFacade();
		document.body.innerHTML = '<form class="wpforms-form" data-formid="31"><input name="wpforms[fields][1]"></form><form class="wpforms-form" data-formid="31"><input name="wpforms[fields][1]"></form>';
		tracker({endpoint: '/events', token: 'public', path: '/wpforms-success'});
		document.dispatchEvent(new Event('DOMContentLoaded'));
		const forms = document.querySelectorAll('form');
		forms.forEach((element) => element.querySelector('input').dispatchEvent(new Event('focusin', {bubbles: true})));
		forms[0].dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
		forms[0].dispatchEvent(new CustomEvent('wpformsAjaxSubmitSuccess', {bubbles: true}));
		window.dispatchEvent(new Event('pagehide'));
		const events = decodedEvents(fetchMock);
		expect(events.filter((event) => event.type === 'form_submit')).toHaveLength(1);
		expect(events.filter((event) => event.type === 'form_abandon')).toHaveLength(1);
	});

	it('discovers a dynamically inserted provider form once', async () => {
		const trackerInstance = tracker({endpoint: '/events', token: 'public', path: '/popup'});
		document.dispatchEvent(new Event('DOMContentLoaded'));
		document.body.insertAdjacentHTML('beforeend', '<form class="wpforms-form" data-formid="91"><input name="wpforms[fields][1]"></form>');
		await new Promise((resolve) => window.setTimeout(resolve, 1));
		trackerInstance.refresh();
		trackerInstance.flush();
		const views = decodedEvents(fetchMock).filter((event) => event.type === 'form_view');
		expect(views).toHaveLength(1);
		expect(views[0]).toMatchObject({provider: 'wpforms', provider_form_id: '91'});
	});

	it('binds provider outcomes when jQuery arrives with a dynamic form', async () => {
		const trackerInstance = tracker({endpoint: '/events', token: 'public', path: '/late-provider'});
		document.dispatchEvent(new Event('DOMContentLoaded'));
		installJQueryEventFacade();
		document.body.insertAdjacentHTML('beforeend', '<form class="wpforms-form" data-formid="93"><input name="wpforms[fields][1]"></form>');
		await new Promise((resolve) => window.setTimeout(resolve, 2));
		const element = document.querySelector('form');
		element.querySelector('input').dispatchEvent(new Event('focusin', {bubbles: true}));
		element.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
		element.dispatchEvent(new CustomEvent('wpformsAjaxSubmitSuccess', {bubbles: true}));
		window.dispatchEvent(new Event('pagehide'));
		trackerInstance.flush();
		const events = decodedEvents(fetchMock);
		expect(trackerInstance.stateFor(element).completed).toBe(true);
		expect(events.filter((event) => event.type === 'form_submit')).toHaveLength(1);
		expect(events.filter((event) => event.type === 'form_abandon')).toHaveLength(0);
	});

	it('degrades safely without observer APIs and can be refreshed explicitly', () => {
		const mutationObserver = window.MutationObserver;
		delete window.MutationObserver;
		try {
			const trackerInstance = tracker({endpoint: '/events', token: 'public', path: '/observer-fallback'});
			document.dispatchEvent(new Event('DOMContentLoaded'));
			document.body.insertAdjacentHTML('beforeend', '<form class="wpforms-form" data-formid="92"></form>');
			trackerInstance.refresh();
			trackerInstance.flush();
			const views = decodedEvents(fetchMock).filter((event) => event.type === 'form_view');
			expect(views).toHaveLength(1);
			expect(views[0].provider_form_id).toBe('92');
		} finally {
			window.MutationObserver = mutationObserver;
		}
	});

	it('swallows REST delivery failures without affecting the form lifecycle', async () => {
		fetchMock.mockImplementation(() => Promise.reject(new Error('Synthetic REST failure')));
		const element = form('<form data-formhawk-id="rest-failure"><input name="email"></form>');
		const trackerInstance = tracker({endpoint: '/events', token: 'public', path: '/rest-failure'});
		document.dispatchEvent(new Event('DOMContentLoaded'));
		element.querySelector('input').dispatchEvent(new Event('focusin', {bubbles: true}));
		element.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
		trackerInstance.flush();
		await Promise.resolve();
		expect(fetchMock).toHaveBeenCalled();
		expect(trackerInstance.stateFor(element).completed).toBe(true);
	});

	it('keeps mixed providers isolated and never emits a generic shadow event', () => {
		document.body.innerHTML = [
			'<div class="wpcf7" data-wpcf7-id="11"><form><input name="your-name"></form></div>',
			'<form class="wpforms-form" data-formid="12"><input name="wpforms[fields][1]"></form>',
			'<form class="elementor-form"><input type="hidden" name="post_id" value="13"><input type="hidden" name="form_id" value="widget13"><input name="form_fields[email]"></form>',
			'<form data-formhawk-id="plain14"><input name="message"></form>',
		].join('');
		const trackerInstance = tracker({endpoint: '/events', token: 'public', path: '/mixed'});
		document.dispatchEvent(new Event('DOMContentLoaded'));
		trackerInstance.flush();
		const views = decodedEvents(fetchMock).filter((event) => event.type === 'form_view');
		expect(views.map((event) => event.provider)).toEqual(['cf7', 'wpforms', 'elementor', 'html']);
		expect(views).toHaveLength(4);
	});

	it('tracks two instances of one WPForms definition independently', () => {
		document.body.innerHTML = '<form class="wpforms-form" data-formid="15"><input name="wpforms[fields][1]"></form><form class="wpforms-form" data-formid="15"><input name="wpforms[fields][1]"></form>';
		const trackerInstance = tracker({endpoint: '/events', token: 'public', path: '/duplicate'});
		document.dispatchEvent(new Event('DOMContentLoaded'));
		const forms = document.querySelectorAll('form');
		forms[0].dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
		forms[1].dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
		trackerInstance.flush();
		const events = decodedEvents(fetchMock);
		expect(events.filter((event) => event.type === 'form_view')).toHaveLength(2);
		expect(events.filter((event) => event.type === 'form_submit')).toHaveLength(2);
		expect(new Set(events.map((event) => event.provider_form_id))).toEqual(new Set(['15']));
	});

	it('reuses only a detached instance lifecycle when duplicate dynamic forms are reordered', async () => {
		document.body.innerHTML = '<form class="wpforms-form" data-formid="25"><input name="wpforms[fields][1]"></form><form class="wpforms-form" data-formid="25"><input name="wpforms[fields][1]"></form>';
		const trackerInstance = tracker({endpoint: '/events', token: 'public', path: '/dynamic-duplicates'});
		document.dispatchEvent(new Event('DOMContentLoaded'));
		const originalForms = Array.from(document.querySelectorAll('form'));
		const detachedState = trackerInstance.stateFor(originalForms[0]);
		const retainedState = trackerInstance.stateFor(originalForms[1]);
		originalForms[0].remove();
		document.body.insertAdjacentHTML('beforeend', '<form class="wpforms-form" data-formid="25"><input name="wpforms[fields][1]"></form>');
		await new Promise((resolve) => window.setTimeout(resolve, 2));
		const currentForms = document.querySelectorAll('form');
		const recreatedState = trackerInstance.stateFor(currentForms[1]);

		expect(recreatedState).toBe(detachedState);
		expect(recreatedState).not.toBe(retainedState);
		currentForms[0].dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
		currentForms[1].dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
		trackerInstance.flush();
		const events = decodedEvents(fetchMock);
		expect(events.filter((event) => event.type === 'form_view')).toHaveLength(2);
		expect(events.filter((event) => event.type === 'form_submit')).toHaveLength(2);
	});

	it('does not register a recreated Elementor popup form twice', async () => {
		const markup = '<div class="elementor-element" data-id="widget7"><form class="elementor-form" name="Popup"><input type="hidden" name="post_id" value="300"><input type="hidden" name="form_id" value="widget7"><input name="form_fields[email]" type="email"></form></div>';
		document.body.innerHTML = markup;
		const trackerInstance = tracker({endpoint: '/events', token: 'public', path: '/popup'});
		document.dispatchEvent(new Event('DOMContentLoaded'));
		document.body.firstElementChild.remove();
		document.body.insertAdjacentHTML('beforeend', markup);
		await new Promise((resolve) => window.setTimeout(resolve, 2));
		trackerInstance.flush();
		const views = decodedEvents(fetchMock).filter((event) => event.type === 'form_view');
		expect(views).toHaveLength(1);
		expect(views[0].provider_form_id).toBe('300:widget7');
	});
});
