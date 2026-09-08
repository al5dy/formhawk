import {beforeEach, describe, expect, it, vi} from 'vitest';
import autopilotSource from '../../resources/js/cro/autopilot.js?raw';
import engineSource from '../../resources/js/cro/variant-engine.js?raw';
import experimentTrackerSource from '../../resources/js/cro/experiment-tracker.js?raw';
import {applyVariant, classifyField, inspectForm, installContextMarker} from '../../resources/js/cro/variant-engine.js';
import {createAutopilot, identity} from '../../resources/js/cro/autopilot.js';
import {createExperimentTracker} from '../../resources/js/cro/experiment-tracker.js';

function assignment(provider, mutations) {
	return {provider, provider_form_id: '42', experiment_id: 1, variant_id: 2, context: 'signed.context', config: {mutations}};
}

describe('CRO privacy and identity', () => {
	beforeEach(() => { document.documentElement.className = ''; document.body.innerHTML = ''; });

	it('does not use browser persistence, cookies, fingerprints, or serialize form payloads', () => {
		const source = `${autopilotSource}\n${engineSource}\n${experimentTrackerSource}`;
		expect(source).not.toMatch(/localStorage|sessionStorage|indexedDB|document\.cookie|canvas|FormData|navigator\.userAgent/);
		expect(experimentTrackerSource).not.toMatch(/\.value\b|preventDefault\s*\(/);
	});

	it('uses stable provider identities', () => {
		document.body.innerHTML = '<div class="wpcf7" data-wpcf7-id="42"><form><input type="hidden" name="_wpcf7" value="42"></form></div>';
		expect(identity(document.querySelector('form'))).toEqual({provider: 'cf7', provider_form_id: '42'});
		document.body.innerHTML = '<form class="elementor-form"><input type="hidden" name="post_id" value="8"><input type="hidden" name="form_id" value="abc"></form>';
		expect(identity(document.querySelector('form'))).toEqual({provider: 'elementor', provider_form_id: '8:abc'});
	});
});

describe('field safety classifier', () => {
	beforeEach(() => { document.body.innerHTML = ''; });

	it('forbids security, legal, payment, CAPTCHA, file and authentication fields', () => {
		document.body.innerHTML = '<form><label>Privacy consent<input name="consent" type="checkbox"></label><input name="password" type="password"><input name="attachment" type="file"></form>';
		const fields = document.querySelectorAll('input');
		expect(Array.from(fields).map((field) => classifyField(field))).toEqual(['FORBIDDEN', 'FORBIDDEN', 'FORBIDDEN']);
	});

	it('protects required fields and fails closed on conditional logic', () => {
		document.body.innerHTML = '<form><div class="form-group"><input name="email" required></div><div class="form-group" data-condition="email"><input name="company"></div></form>';
		expect(classifyField(document.querySelector('[name="email"]'))).toBe('PROTECTED');
		expect(inspectForm(document.querySelector('form'), 'html').safeStructure).toBe(false);
	});
});

describe('runtime mutations', () => {
	beforeEach(() => { document.body.innerHTML = ''; });

	it('changes and restores CTA without changing business fields', () => {
		document.body.innerHTML = '<form data-formhawk-id="42"><input name="email" value="person@example.test"><button type="submit"><span>Submit</span></button></form>';
		const form = document.querySelector('form');
		const before = Array.from(new FormData(form).entries());
		const result = applyVariant(form, assignment('html', [{type: 'submit_button', config: {text: 'Send request'}}]));
		expect(result.ok).toBe(true);
		expect(form.querySelector('button').textContent).toBe('Send request');
		expect(Array.from(new FormData(form).entries())).toEqual(before);
		result.restore();
		expect(form.querySelector('button').textContent).toBe('Submit');
	});

	it('reorders only independent SAFE fields and is idempotent', () => {
		document.body.innerHTML = '<form><div class="form-group"><input name="name"></div><div class="form-group"><input name="phone" type="tel"></div><div class="form-group"><input name="email" type="email"></div><button type="submit">Submit</button></form>';
		const form = document.querySelector('form');
		const config = assignment('html', [{type: 'field_order', config: {field_order: ['__all_except_target__', 'phone']}}]);
		const result = applyVariant(form, config);
		expect(result.ok).toBe(true);
		expect(Array.from(form.querySelectorAll('.form-group input')).map((field) => field.name)).toEqual(['name', 'email', 'phone']);
		expect(form.lastElementChild.tagName).toBe('BUTTON');
		expect(applyVariant(form, config).reused).toBe(true);
		result.restore();
		expect(Array.from(form.querySelectorAll('.form-group input')).map((field) => field.name)).toEqual(['name', 'phone', 'email']);
	});

	it('rejects structural mutation when dependencies or protected target are present', () => {
		document.body.innerHTML = '<form><div class="form-group"><input name="email" required></div><div class="form-group"><input name="phone"></div></form>';
		const result = applyVariant(document.querySelector('form'), assignment('html', [{type: 'field_order', config: {field_order: ['phone', 'email']}}]));
		expect(result.ok).toBe(false);
		expect(document.querySelector('form').hasAttribute('data-formhawk-cro-applied')).toBe(false);
	});

	it('progressively discloses only optional safe fields', () => {
		document.body.innerHTML = '<form><div class="form-group"><input name="email" required></div><div class="form-group"><input name="company"></div><button type="submit">Submit</button></form>';
		const form = document.querySelector('form');
		const result = applyVariant(form, assignment('html', [{type: 'progressive_disclosure', config: {fields: ['company'], label: 'More'}}]));
		expect(result.ok).toBe(true);
		expect(form.querySelector('details summary').textContent).toBe('More');
		expect(form.querySelector('details [name="company"]')).not.toBeNull();
		result.restore();
		expect(form.querySelector('details')).toBeNull();
	});

	it('applies and restores label and placeholder presentation strategies', () => {
		document.body.innerHTML = '<form><label>Email<input name="email"></label><button type="submit">Submit</button></form>';
		const form = document.querySelector('form');
		const result = applyVariant(form, assignment('html', [
			{type: 'label_presentation', config: {position: 'above'}},
			{type: 'placeholder_presentation', config: {mode: 'deemphasize'}},
		]));
		expect(result.ok).toBe(true);
		expect(form.classList.contains('formhawk-labels-above')).toBe(true);
		expect(form.classList.contains('formhawk-placeholders-deemphasize')).toBe(true);
		result.restore();
		expect(form.className).toBe('');
	});

	it('creates accessible multi-step navigation and preserves submission payload', () => {
		document.body.innerHTML = `<form>${Array.from({length: 6}, (_, index) => `<div class="form-group"><label for="f${index}">Field ${index}</label><input id="f${index}" name="f${index}" value="v${index}" ${index === 0 ? 'required' : ''}></div>`).join('')}<button type="submit">Submit</button></form>`;
		const form = document.querySelector('form');
		const before = Array.from(new FormData(form).entries());
		const result = applyVariant(form, assignment('html', [{type: 'multi_step', config: {steps: [['__first_half__'], ['__second_half__']]}}]), {next: 'Next', back: 'Back'});
		expect(result.ok).toBe(true);
		expect(form.querySelector('[role="progressbar"]').getAttribute('aria-valuenow')).toBe('1');
		expect(form.querySelector('button[type="submit"]').hidden).toBe(true);
		expect(Array.from(new FormData(form).entries())).toEqual(before);
		result.restore();
		expect(Array.from(new FormData(form).entries())).toEqual(before);
	});

	it('turns Enter into safe step navigation and allows only the final provider submit', () => {
		document.body.innerHTML = `<form>${Array.from({length: 6}, (_, index) => `<div class="form-group"><label for="k${index}">Field ${index}</label><input id="k${index}" name="k${index}" value="v${index}" ${index === 0 ? 'required' : ''}></div>`).join('')}<button type="submit">Submit</button></form>`;
		const form = document.querySelector('form');
		const result = applyVariant(form, assignment('html', [{type: 'multi_step', config: {steps: [['__first_half__'], ['__second_half__']]}}]));
		let providerSubmits = 0;
		form.addEventListener('submit', () => { providerSubmits += 1; });
		form.querySelector('#k0').dispatchEvent(new KeyboardEvent('keydown', {key: 'Enter', bubbles: true, cancelable: true}));
		expect(form.querySelector('[role="progressbar"]').getAttribute('aria-valuenow')).toBe('2');
		Array.from(form.querySelectorAll('.formhawk-cro-step-nav button')).find((button) => button.textContent === 'Back').click();
		expect(form.querySelector('[role="progressbar"]').getAttribute('aria-valuenow')).toBe('1');
		form.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
		expect(providerSubmits).toBe(0);
		expect(form.querySelector('[role="progressbar"]').getAttribute('aria-valuenow')).toBe('2');
		form.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
		expect(providerSubmits).toBe(1);
		result.restore();
	});

	it('fails open when provider constraint validation throws for its own pattern', () => {
		document.body.innerHTML = `<form>${Array.from({length: 6}, (_, index) => `<div class="form-group"><input id="p${index}" name="p${index}"></div>`).join('')}<button type="submit">Submit</button></form>`;
		const form = document.querySelector('form');
		const result = applyVariant(form, assignment('html', [{type: 'multi_step', config: {steps: [['__first_half__'], ['__second_half__']]}}]));
		form.querySelector('#p1').checkValidity = () => { throw new SyntaxError('provider_pattern'); };
		expect(() => form.querySelector('.formhawk-cro-next').click()).not.toThrow();
		expect(form.querySelector('[role="progressbar"]').getAttribute('aria-valuenow')).toBe('2');
		result.restore();
	});

	it('technical context is isolated from provider field namespaces', () => {
		document.body.innerHTML = '<form class="wpforms-form"><input name="wpforms[fields][1]" value="business"><button type="submit">Submit</button></form>';
		const form = document.querySelector('form');
		installContextMarker(form, 'signed.context');
		expect(form.querySelector('[name="_formhawk_cro"]').getAttribute('data-formhawk-technical')).toBe('experiment-context');
		expect(form.querySelectorAll('[name^="wpforms[fields]"]').length).toBe(1);
	});
});

describe('Autopilot lifecycle', () => {
	it.each(['wpforms', 'elementor', 'cf7'])('tracks two successful %s attempts with stable experiment context and no false abandon', (provider) => {
		document.body.innerHTML = '<form><input name="email"><button type="submit">Submit</button></form>';
		window.fetch = vi.fn(() => Promise.resolve({ok: true}));
		const tracker = createExperimentTracker(window, document, '/events');
		const form = document.querySelector('form');
		installContextMarker(form, 'signed.context');
		tracker.attach(form, assignment(provider, []));
		for (let attempt = 0; attempt < 2; attempt += 1) {
			form.dispatchEvent(new Event('submit', {bubbles: true}));
			form.dispatchEvent(new Event('submit', {bubbles: true}));
			tracker.terminal(form, true);
			tracker.terminal(form, true);
			tracker.terminal(form, false);
			window.dispatchEvent(new Event('pagehide'));
		}
		const events = window.fetch.mock.calls.map((call) => JSON.parse(call[1].body));
		expect(events.filter((event) => event.type === 'attempt')).toHaveLength(2);
		expect(events.filter((event) => event.type === 'latency')).toHaveLength(2);
		expect(events.filter((event) => event.type === 'view')).toHaveLength(1);
		expect(events.filter((event) => event.type === 'start')).toHaveLength(1);
		expect(events.filter((event) => event.type === 'abandon')).toHaveLength(0);
		expect(events.every((event) => event.context === 'signed.context')).toBe(true);
		expect(form.querySelector('[name="_formhawk_cro"]').getAttribute('value')).toBe('signed.context');
		tracker.destroy();
	});

	it('applies a deterministic runtime assignment and tracks it', async () => {
		document.body.innerHTML = '<form data-formhawk-id="42"><input name="email" value="business@example.test"><button type="submit">Submit</button></form>';
		const before = Array.from(new FormData(document.querySelector('form')).entries());
		const fetch = vi.fn()
			.mockResolvedValueOnce({ok: true, json: async () => ({assignments: [{...assignment('html', [{type: 'submit_button', config: {text: 'Send request'}}]), fallback_config: {mutations: []}, fallback_context: 'control.context'}]})})
			.mockResolvedValue({ok: true, json: async () => ({})});
		window.fetch = fetch;
		const runtime = createAutopilot(window, document, {configEndpoint: '/config', eventsEndpoint: '/events', path: '/', strings: {}});
		await new Promise((resolve) => setTimeout(resolve, 10));
		expect(document.querySelector('button').textContent).toBe('Send request');
		expect(document.querySelector('[name="_formhawk_cro"]')).toBeNull();
		expect(Array.from(new FormData(document.querySelector('form')).entries())).toEqual(before);
		runtime.destroy();
	});

	it('installs server attribution only for a confirmed provider form', async () => {
		document.body.innerHTML = '<form class="wpforms-form" data-formid="42"><input name="wpforms[fields][1]" value="business"><button type="submit">Submit</button></form>';
		window.fetch = vi.fn()
			.mockResolvedValueOnce({ok: true, json: async () => ({assignments: [{...assignment('wpforms', []), fallback_config: {mutations: []}, fallback_context: 'control.context'}]})})
			.mockResolvedValue({ok: true, json: async () => ({})});
		const runtime = createAutopilot(window, document, {configEndpoint: '/config', eventsEndpoint: '/events', path: '/', strings: {}});
		await new Promise((resolve) => setTimeout(resolve, 10));
		expect(document.querySelector('[name="_formhawk_cro"]').getAttribute('data-formhawk-technical')).toBe('experiment-context');
		expect(document.querySelectorAll('[name^="wpforms[fields]"]').length).toBe(1);
		runtime.destroy();
	});

	it('deduplicates double submit and pagehide while preserving a real provider retry', () => {
		document.body.innerHTML = '<form class="wpforms-form" data-formid="42"><input name="wpforms[fields][1]"><button type="submit">Submit</button></form>';
		const eventTypes = [];
		window.fetch = vi.fn(async (url, options) => {
			eventTypes.push(JSON.parse(options.body).type);
			return {ok: true, json: async () => ({})};
		});
		const tracker = createExperimentTracker(window, document, '/events');
		const form = document.querySelector('form');
		tracker.attach(form, assignment('wpforms', []));
		form.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
		form.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
		expect(eventTypes.filter((type) => type === 'attempt')).toHaveLength(1);
		tracker.terminal(form, false);
		form.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
		expect(eventTypes.filter((type) => type === 'attempt')).toHaveLength(2);
		tracker.terminal(form, false);

		window.dispatchEvent(new PageTransitionEvent('pagehide'));
		window.dispatchEvent(new PageTransitionEvent('pagehide'));
		expect(eventTypes.filter((type) => type === 'abandon')).toHaveLength(1);
		tracker.destroy();
	});

	it('applies to multiple dynamic instances and requests a new generic placement after SPA navigation', async () => {
		document.body.innerHTML = '<form data-formhawk-id="42"><input name="email"><button type="submit">Submit</button></form>';
		const configBodies = [];
		window.fetch = vi.fn(async (url, options) => {
			if (url === '/config') {
				configBodies.push(JSON.parse(options.body));
				return {ok: true, json: async () => ({assignments: [{...assignment('html', [{type: 'submit_button', config: {text: 'Send request'}}]), fallback_config: {mutations: []}, fallback_context: 'control.context'}]})};
			}
			return {ok: true, json: async () => ({})};
		});
		const runtime = createAutopilot(window, document, {configEndpoint: '/config', eventsEndpoint: '/events', path: '/', strings: {}});
		await new Promise((resolve) => setTimeout(resolve, 10));
		const clone = document.querySelector('form').cloneNode(true);
		clone.removeAttribute('data-formhawk-cro-applied');
		document.body.appendChild(clone);
		await new Promise((resolve) => setTimeout(resolve, 10));
		expect(Array.from(document.querySelectorAll('form button')).map((button) => button.textContent)).toEqual(['Send request', 'Send request']);

		window.history.pushState({}, '', '/spa-placement');
		document.body.innerHTML = '<form data-formhawk-id="42"><input name="email"><button type="submit">Submit</button></form>';
		document.dispatchEvent(new Event('formhawk:navigation'));
		await new Promise((resolve) => setTimeout(resolve, 15));
		expect(configBodies.at(-1).page_path).toBe('/spa-placement');
		expect(document.querySelector('button').textContent).toBe('Send request');
		runtime.destroy();
		window.history.replaceState({}, '', '/');
	});

	it('retries transient config failure and ignores unrelated DOM mutations without a document rescan', async () => {
		document.body.innerHTML = '<form data-formhawk-id="42"><input name="email"><button type="submit">Submit</button></form>';
		let configRequests = 0;
		window.fetch = vi.fn(async (url) => {
			if (url === '/config') {
				configRequests += 1;
				if (configRequests === 1) throw new Error('temporary');
				return {ok: true, json: async () => ({assignments: [{...assignment('html', [{type: 'submit_button', config: {text: 'Send request'}}]), fallback_config: {mutations: []}, fallback_context: 'control.context'}]})};
			}
			return {ok: true, json: async () => ({})};
		});
		const runtime = createAutopilot(window, document, {configEndpoint: '/config', eventsEndpoint: '/events', path: '/', strings: {}});
		await new Promise((resolve) => setTimeout(resolve, 10));
		const documentScan = vi.spyOn(document, 'querySelectorAll');
		document.body.appendChild(document.createElement('div'));
		await new Promise((resolve) => setTimeout(resolve, 5));
		expect(documentScan).not.toHaveBeenCalled();
		documentScan.mockRestore();
		await runtime.refresh();
		expect(configRequests).toBe(2);
		expect(document.querySelector('button').textContent).toBe('Send request');
		runtime.destroy();
	});

	it('keeps visible control when the initial assignment misses the preparation window', async () => {
		vi.useFakeTimers();
		document.documentElement.classList.add('formhawk-cro-pending');
		document.body.innerHTML = '<form data-formhawk-id="42"><input name="email"><button type="submit">Submit</button></form>';
		let resolveConfig;
		window.fetch = vi.fn(() => new Promise((resolve) => { resolveConfig = resolve; }));
		const runtime = createAutopilot(window, document, {configEndpoint: '/config', eventsEndpoint: '/events', path: '/', strings: {}});
		await vi.advanceTimersByTimeAsync(1201);
		resolveConfig({ok: true, json: async () => ({assignments: [{...assignment('html', [{type: 'submit_button', config: {text: 'Send request'}}]), fallback_config: {mutations: []}, fallback_context: 'control.context'}]})});
		await vi.runAllTimersAsync();
		expect(document.documentElement.classList.contains('formhawk-cro-pending')).toBe(false);
		expect(document.querySelector('button').textContent).toBe('Submit');
		runtime.destroy();
		vi.useRealTimers();
	});
});
