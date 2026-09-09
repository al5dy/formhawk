import {applyVariant, installContextMarker} from './variant-engine.js';
import {createExperimentTracker} from './experiment-tracker.js';

function attribute(element, name) {
	return element ? String(element.getAttribute(name) || '').slice(0, 191) : '';
}

function hiddenAttribute(form, name) {
	const input = Array.from(form.querySelectorAll('input[type="hidden"][name]')).find((item) => attribute(item, 'name') === name);
	return attribute(input, 'value');
}

function hashString(value) {
	let hash = 2166136261;
	for (let index = 0; index < value.length; index += 1) {
		hash ^= value.charCodeAt(index);
		hash = Math.imul(hash, 16777619);
	}
	return (hash >>> 0).toString(36);
}

function genericIdentity(form) {
	const explicit = attribute(form, 'data-formhawk-id') || attribute(form, 'id') || attribute(form, 'name');
	if (explicit) return explicit;
	const structure = Array.from(form.elements || []).filter((field) => {
		const tag = String(field.tagName || '').toUpperCase();
		return ['INPUT', 'SELECT', 'TEXTAREA'].includes(tag) && !['hidden', 'submit', 'button', 'reset', 'image'].includes(attribute(field, 'type').toLowerCase());
	}).map((field) => `${attribute(field, 'name') || attribute(field, 'id')}:${attribute(field, 'type').toLowerCase() || String(field.tagName || '').toLowerCase() || 'field'}`).join('|');
	const formWindow = form && form.ownerDocument ? form.ownerDocument.defaultView : null;
	const location = formWindow && formWindow.location ? formWindow.location : {pathname: '/', href: 'http://localhost/'};
	let actionPath = '';
	try { actionPath = new URL(attribute(form, 'action') || location.pathname, location.href).pathname; } catch { actionPath = location.pathname || '/'; }
	return `auto-${hashString(`${attribute(form, 'method')}|${actionPath}|${structure}`)}`;
}

export function identity(form) {
	const cf7 = form.closest('.wpcf7');
	if (cf7) return {provider: 'cf7', provider_form_id: attribute(cf7, 'data-wpcf7-id') || hiddenAttribute(form, '_wpcf7')};
	if (form.classList.contains('wpforms-form')) {
		const match = attribute(form, 'id').match(/^wpforms-form-(\d+)$/);
		return {provider: 'wpforms', provider_form_id: attribute(form, 'data-formid') || (match ? match[1] : '')};
	}
	if (form.classList.contains('elementor-form')) {
		const widget = hiddenAttribute(form, 'form_id');
		const post = hiddenAttribute(form, 'post_id');
		return {provider: 'elementor', provider_form_id: post ? `${post}:${widget}` : `widget:${widget}`};
	}
	return {provider: 'html', provider_form_id: genericIdentity(form)};
}

export function createAutopilot(browserWindow, browserDocument, config) {
	if (!config || !config.configEndpoint || !config.eventsEndpoint) return null;
	const assignments = new WeakMap();
	const requested = new WeakSet();
	const applied = new WeakMap();
	const tracker = createExperimentTracker(browserWindow, browserDocument, config.eventsEndpoint);
	let mutationObserver = null;
	let pending = false;
	let preparationExpired = false;
	const queuedForms = new Set();
	let lastPath = browserWindow.location && browserWindow.location.pathname ? browserWindow.location.pathname : (config.path || '/');

	function currentPath() { return browserWindow.location && browserWindow.location.pathname ? browserWindow.location.pathname : (config.path || '/'); }
	function key(item, path = currentPath()) { return `${item.provider}|${item.provider_form_id}${item.provider === 'html' ? `|${path}` : ''}`; }
	function installAttribution(form, assignment) {
		// Generic HTML has no server-confirmed lifecycle. Adding a technical
		// control would alter arbitrary local or third-party submission payloads.
		const providerContext = ['cf7', 'wpforms', 'elementor'].includes(assignment.provider) ? assignment.context : '';
		installContextMarker(form, providerContext);
	}

	function forms(root = browserDocument) {
		const candidates = [];
		if (root.matches && root.matches('form')) candidates.push(root);
		if (root.querySelectorAll) candidates.push(...root.querySelectorAll('form'));
		return candidates.filter((form) => {
			const item = identity(form);
			return item.provider_form_id && !form.hasAttribute('data-formhawk-ignore');
		});
	}

	function fallback(form, assignment, failureReason) {
		tracker.send(assignment.context, 'js_error');
		const fallbackAssignment = Object.assign({}, assignment, {
			variant_id: 0,
			config: assignment.fallback_config || {mutations: []},
			context: assignment.fallback_context || '',
		});
		const result = applyVariant(form, fallbackAssignment, config.strings || {});
		if (result.ok) {
			installAttribution(form, fallbackAssignment);
			applied.set(form, result);
			tracker.attach(form, fallbackAssignment);
		} else {
			installContextMarker(form, '');
			form.removeAttribute('data-formhawk-cro-applied');
		}
		if (config.debug && browserWindow.console) browserWindow.console.warn('Formhawk CRO fail-open:', failureReason);
	}

	function apply(form) {
		if (applied.has(form)) return;
		const assignment = assignments.get(form);
		if (!assignment) return;
		const result = applyVariant(form, assignment, config.strings || {});
		if (!result.ok) {
			fallback(form, assignment, result.reason);
			return;
		}
		installAttribution(form, assignment);
		applied.set(form, result);
		tracker.attach(form, assignment);
	}

	function discover(root = browserDocument) {
		forms(root).forEach(apply);
	}

	function resetForNavigation() {
		const path = currentPath();
		if (path === lastPath) return;
		lastPath = path;
		forms().forEach((form) => {
			if (identity(form).provider !== 'html') return;
			const result = applied.get(form);
			if (result) result.restore();
			applied.delete(form);
			assignments.delete(form);
			requested.delete(form);
			tracker.detach(form);
			installContextMarker(form, '');
		});
		load();
	}

	async function load(candidateForms = null, initialRequest = false) {
		const candidates = Array.isArray(candidateForms) ? candidateForms : forms();
		if (pending) {
			candidates.forEach((form) => {
				if (!requested.has(form)) queuedForms.add(form);
			});
			return;
		}
		pending = true;
		// A DOM instance owns its own issued lifecycle, even when provider form IDs match.
		const availablePairs = Array.from(new Set(candidates)).filter((form) => !requested.has(form)).map((form) => [form, identity(form)]);
		const pairs = availablePairs.slice(0, 20);
		if (availablePairs.length > pairs.length) {
			availablePairs.slice(20).forEach(([form]) => queuedForms.add(form));
		}
		const requestForms = pairs.map(([form]) => form);
		const identities = pairs.map(([, item]) => item);
		if (!identities.length) {
			pending = false;
			browserDocument.documentElement.classList.remove('formhawk-cro-pending');
			return;
		}
		requestForms.forEach((form) => requested.add(form));
		const requestPath = currentPath();
		const payload = {page_path: requestPath, segment: browserWindow.matchMedia && browserWindow.matchMedia('(max-width: 782px)').matches ? 'mobile' : 'desktop', forms: identities};
		if (config.testMode && ['control', 'variant'].includes(config.force)) payload.force = config.force;
		try {
			const response = await browserWindow.fetch(config.configEndpoint, {method: 'POST', headers: {'Content-Type': 'application/json'}, credentials: 'same-origin', cache: 'no-store', body: JSON.stringify(payload)});
			if (!response.ok) throw new Error('config_request_failed');
			const data = await response.json();
			if (!data || !Array.isArray(data.assignments)) throw new Error('config_response_invalid');
			if (initialRequest && preparationExpired) return;
			if (requestPath !== currentPath()) return;
			data.assignments.forEach((assignment) => {
				const form = requestForms[assignment.form_index];
				if (form && key(identity(form), requestPath) === key(assignment, requestPath)) assignments.set(form, assignment);
			});
			discover();
		} catch (error) {
			requestForms.forEach((form) => requested.delete(form));
			if (config.debug && browserWindow.console) browserWindow.console.warn('Formhawk CRO unavailable; original forms preserved.', error);
		} finally {
			pending = false;
			browserDocument.documentElement.classList.remove('formhawk-cro-pending');
			if (queuedForms.size) {
				const queued = Array.from(queuedForms);
				queuedForms.clear();
				load(queued);
			}
		}
	}

	function init() {
		load(null, true);
		browserWindow.addEventListener('popstate', resetForNavigation);
		browserDocument.addEventListener('formhawk:navigation', resetForNavigation);
		try {
			if (typeof browserWindow.MutationObserver === 'function' && browserDocument.body) {
				mutationObserver = new browserWindow.MutationObserver((records) => {
					resetForNavigation();
					const addedForms = [];
					records.forEach((record) => Array.from(record.addedNodes || []).forEach((node) => {
						if (node && node.nodeType === 1) {
							const discovered = forms(node);
							discovered.forEach(apply);
							addedForms.push(...discovered);
						}
					}));
					if (addedForms.length) load(addedForms);
				});
				mutationObserver.observe(browserDocument.body, {childList: true, subtree: true});
			}
		} catch {}
	}

	const failOpenTimer = browserWindow.setTimeout(() => {
		preparationExpired = true;
		browserDocument.documentElement.classList.remove('formhawk-cro-pending');
	}, 1200);
	if (browserDocument.readyState === 'loading') browserDocument.addEventListener('DOMContentLoaded', init, {once: true}); else init();
	return Object.freeze({refresh: load, destroy() { browserWindow.clearTimeout(failOpenTimer); browserWindow.removeEventListener('popstate', resetForNavigation); browserDocument.removeEventListener('formhawk:navigation', resetForNavigation); if (mutationObserver) mutationObserver.disconnect(); tracker.destroy(); }});
}

if (typeof window !== 'undefined' && typeof document !== 'undefined' && window.FormhawkCROConfig) {
	window.FormhawkAutopilot = createAutopilot(window, document, window.FormhawkCROConfig);
}
