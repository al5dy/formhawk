const PROVIDERS = Object.freeze({
	CF7: 'cf7',
	WPFORMS: 'wpforms',
	ELEMENTOR: 'elementor',
	GENERIC: 'html',
});

const SERVER_PROVIDERS = new Set([PROVIDERS.CF7, PROVIDERS.WPFORMS, PROVIDERS.ELEMENTOR]);
const SUBMISSION_FIELD = '_formhawk_submission';

export function cleanText(value, max = 191) {
	return String(value || '').replace(/\s+/g, ' ').trim().slice(0, max);
}

function attributeValue(element, name) {
	return element ? cleanText(element.getAttribute(name), 191) : '';
}

function structuralLabel(element) {
	if (!element || element.closest('[contenteditable], [aria-live]')) {
		return '';
	}
	// Nested controls, outputs and arbitrary dynamic markup can contain visitor text.
	// Only the label's own text nodes are metadata; ambiguous labels fall back to a key.
	return cleanText(Array.from(element.childNodes)
		.filter((node) => node.nodeType === 3)
		.map((node) => node.nodeValue).join(' '), 191);
}

function technicalHiddenAttribute(form, name) {
	const input = Array.from(form.querySelectorAll('input[type="hidden"][name]'))
		.find((candidate) => candidate.getAttribute('name') === name);
	return attributeValue(input, 'value');
}

function cf7Id(form) {
	const wrapper = form.closest ? form.closest('.wpcf7') : null;
	if (wrapper) {
		const direct = attributeValue(wrapper, 'data-wpcf7-id');
		if (direct) {
			return direct;
		}
		const unit = attributeValue(wrapper, 'id') || attributeValue(wrapper, 'data-wpcf7-unit-tag');
		const match = unit.match(/wpcf7-f(\d+)/);
		if (match) {
			return match[1];
		}
	}
	return technicalHiddenAttribute(form, '_wpcf7');
}

function wpformsId(form) {
	const nativeId = attributeValue(form, 'id');
	const hasSignature = form.classList.contains('wpforms-form')
		|| /^wpforms-form-\d+$/.test(nativeId);
	if (!hasSignature) {
		return '';
	}
	const match = nativeId.match(/^wpforms-form-(\d+)$/);
	const candidates = [attributeValue(form, 'data-formid'), match ? match[1] : '', technicalHiddenAttribute(form, 'wpforms[id]')];
	return candidates.find((candidate) => /^\d+$/.test(candidate) && Number(candidate) > 0) || '';
}

function elementorIdentity(form) {
	if (!form.classList.contains('elementor-form')) {
		return '';
	}
	const widgetId = technicalHiddenAttribute(form, 'form_id');
	if (!widgetId) {
		return '';
	}
	const postId = technicalHiddenAttribute(form, 'post_id');
	return postId ? `${postId}:${widgetId}` : `widget:${widgetId}`;
}

const PROVIDER_DETECTORS = Object.freeze([
	{
		provider: PROVIDERS.CF7,
		identity: cf7Id,
		title: () => '',
	},
	{
		provider: PROVIDERS.WPFORMS,
		identity: wpformsId,
		title: (form) => {
			const container = form.closest('.wpforms-container');
			const title = container ? container.querySelector('.wpforms-title') : null;
			return structuralLabel(title);
		},
	},
	{
		provider: PROVIDERS.ELEMENTOR,
		identity: elementorIdentity,
		title: (form) => attributeValue(form, 'name'),
	},
]);

function hashString(value) {
	let hash = 2166136261;
	for (let index = 0; index < value.length; index += 1) {
		hash ^= value.charCodeAt(index);
		hash = Math.imul(hash, 16777619);
	}
	return (hash >>> 0).toString(36);
}

function genericIdentity(form, pagePath) {
	const explicit = attributeValue(form, 'data-formhawk-id');
	if (explicit) {
		return explicit;
	}
	const nativeIdentity = attributeValue(form, 'id') || attributeValue(form, 'name');
	if (nativeIdentity) {
		return nativeIdentity;
	}
	const structure = Array.from(form.elements || [])
		.filter((field) => trackableField(field))
		.map((field) => `${attributeValue(field, 'name') || attributeValue(field, 'id')}:${normalizedInputType(field)}`)
		.join('|');
	let actionPath = '';
	try {
		actionPath = new URL(attributeValue(form, 'action') || pagePath, window.location.href).pathname;
	} catch {
		actionPath = pagePath;
	}
	return `auto-${hashString(`${attributeValue(form, 'method')}|${actionPath}|${structure}`)}`;
}

export function detectProvider(form, pagePath = '/') {
	for (const detector of PROVIDER_DETECTORS) {
		const identity = detector.identity(form);
		if (identity) {
			return {
				provider: detector.provider,
				provider_form_id: identity,
				title: detector.title(form),
				page_path: pagePath,
			};
		}
	}

	const identity = genericIdentity(form, pagePath);
	return {
		provider: PROVIDERS.GENERIC,
		provider_form_id: cleanText(identity, 191),
		title: cleanText(
			attributeValue(form, 'data-formhawk-title') || attributeValue(form, 'aria-label') || attributeValue(form, 'id') || attributeValue(form, 'name'),
			255,
	),
		page_path: pagePath,
	};
}

export function eligible(form) {
	if (!form || form.nodeType !== 1 || form.tagName !== 'FORM') {
		return false;
	}
	if (form.hasAttribute('data-formhawk-ignore')) {
		return false;
	}
	if (attributeValue(form, 'role') === 'search' || form.classList.contains('search-form')) {
		return false;
	}
	return form.id !== 'loginform' && form.id !== 'lostpasswordform' && !form.closest('#wpadminbar');
}

function normalizedInputType(field) {
	const tag = String(field.tagName || '').toLowerCase();
	const type = attributeValue(field, 'type').toLowerCase();
	return cleanText(type || tag || 'field', 32);
}

function trackableField(field) {
	if (!field || !field.tagName) {
		return false;
	}
	const tag = String(field.tagName).toUpperCase();
	const type = attributeValue(field, 'type').toLowerCase();
	return ['INPUT', 'SELECT', 'TEXTAREA'].includes(tag) && !['hidden', 'submit', 'button', 'reset', 'image'].includes(type);
}

function wpformsFieldMeta(field) {
	const name = attributeValue(field, 'name');
	const match = name.match(/^wpforms\[fields\]\[([^\]]+)\](?:\[([^\]]*)\])?/);
	const wrapper = field.closest ? field.closest('.wpforms-field[data-field-id], .wpforms-field') : null;
	const wrapperId = wrapper ? attributeValue(wrapper, 'data-field-id') : '';
	const base = match ? match[1] : wrapperId;
	if (!base) {
		return null;
	}
	const part = match && match[2] && !/^\d+$/.test(match[2]) ? `.${match[2]}` : '';
	const labelNode = wrapper ? wrapper.querySelector('.wpforms-field-label') : null;
	const typeMatch = wrapper ? Array.from(wrapper.classList).find((className) => /^wpforms-field-[a-z0-9-]+$/.test(className) && !/^wpforms-field-(small|medium|large|required)$/.test(className)) : '';
	return {
		key: cleanText(`${base}${part}`, 191),
		label: structuralLabel(labelNode),
		type: cleanText(typeMatch ? typeMatch.replace('wpforms-field-', '') : normalizedInputType(field), 32),
	};
}

function elementorFieldMeta(field) {
	const name = attributeValue(field, 'name');
	const match = name.match(/^form_fields\[([^\]]+)\]/);
	if (!match) {
		return null;
	}
	const wrapper = field.closest ? field.closest('.elementor-field-group') : null;
	const labelNode = wrapper ? wrapper.querySelector('.elementor-field-label') : null;
	const typeClass = wrapper
		? Array.from(wrapper.classList).find((className) => /^elementor-field-type-[a-z0-9_-]+$/.test(className))
		: '';
	return {
		key: cleanText(match[1], 191),
		label: structuralLabel(labelNode),
		type: cleanText(typeClass ? typeClass.replace('elementor-field-type-', '') : normalizedInputType(field), 32),
	};
}

const PROVIDER_FIELD_NORMALIZERS = Object.freeze({
	[PROVIDERS.WPFORMS]: wpformsFieldMeta,
	[PROVIDERS.ELEMENTOR]: elementorFieldMeta,
});

export function fieldMeta(field, form, provider = '') {
	if (!trackableField(field)) {
		return null;
	}
	const normalizeProviderField = PROVIDER_FIELD_NORMALIZERS[provider];
	if (normalizeProviderField) {
		const metadata = normalizeProviderField(field);
		if (metadata) {
			return metadata;
		}
	}

	const fields = Array.from(form.elements || []).filter(trackableField);
	const index = Math.max(0, fields.indexOf(field));
	const key = attributeValue(field, 'name') || attributeValue(field, 'id') || `${String(field.tagName).toLowerCase()}-${index}`;
	let label = attributeValue(field, 'data-formhawk-label') || attributeValue(field, 'aria-label');
	if (!label && field.id) {
		const explicit = form.querySelector(`label[for="${cssEscape(field.id)}"]`);
		label = structuralLabel(explicit);
	}
	if (!label && field.closest) {
		const parentLabel = field.closest('label');
		label = structuralLabel(parentLabel);
	}
	label = label || attributeValue(field, 'placeholder') || key;
	return {key: cleanText(key, 191), label: cleanText(label, 191), type: normalizedInputType(field)};
}

export function cssEscape(value) {
	if (typeof window !== 'undefined' && window.CSS && typeof window.CSS.escape === 'function') {
		return window.CSS.escape(value);
	}
	return String(value).replace(/([ #;?%&,.+*~':"!^$[\]()=>|/@])/g, '\\$1');
}

export function opaqueSubmissionId(browserWindow) {
	try {
		const bytes = new Uint8Array(16);
		browserWindow.crypto.getRandomValues(bytes);
		const binary = Array.from(bytes, (byte) => String.fromCharCode(byte)).join('');
		return `fh_${browserWindow.btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')}`;
	} catch {
		// A weak fallback would undermine the privacy boundary. Attribution is
		// simply unavailable in obsolete/locked-down browsers.
		return '';
	}
}

export function createTracker(browserWindow, browserDocument, suppliedConfig = {}) {
	const config = suppliedConfig || {};
	if (!config.endpoint || !config.token) {
		return null;
	}

	const states = new WeakMap();
	const metadataCache = new WeakMap();
	const activeForms = new Set();
	const lifecycleCache = new Map();
	const nextInstanceIds = new Map();
	const queue = [];
	let flushTimer = null;
	let providerEventsBound = false;
	let jqueryProviderEventsBound = false;
	let mutationObserver = null;
	let destroyed = false;

	const path = () => cleanText(config.path || browserWindow.location.pathname || '/', 500) || '/';

	function eventFor(state, type, extra = null) {
		return Object.assign({schema_version: 2, type, ...state.meta}, extra || {});
	}

	function queueEvent(event, urgent = false) {
		if (destroyed) {
			return;
		}
		if (queue.length >= 100) {
			queue.shift();
		}
		queue.push(event);
		if (urgent || queue.length >= 10) {
			flush(urgent);
			return;
		}
		if (!flushTimer) {
			flushTimer = browserWindow.setTimeout(() => flush(false), 700);
		}
	}

	function sendBatch(events, beacon) {
		const body = JSON.stringify({token: config.token, events});
		if (beacon && browserWindow.navigator && typeof browserWindow.navigator.sendBeacon === 'function') {
			try {
				const blob = new browserWindow.Blob([body], {type: 'application/json'});
				if (browserWindow.navigator.sendBeacon(config.endpoint, blob)) {
					return;
				}
			} catch {}
		}
		if (typeof browserWindow.fetch === 'function') {
			try {
				browserWindow.fetch(config.endpoint, {
				method: 'POST',
				headers: {'Content-Type': 'application/json'},
				credentials: 'same-origin',
				keepalive: Boolean(beacon),
				body,
			}).catch(() => {});
			} catch {}
		}
	}

	function flush(beacon = false) {
		if (flushTimer) {
			browserWindow.clearTimeout(flushTimer);
			flushTimer = null;
		}
		while (queue.length) {
			sendBatch(queue.splice(0, 20), beacon);
		}
	}

	function ensureStarted(state) {
		if (state.started || state.completed) {
			return;
		}
		state.started = true;
		state.startedAt = Date.now();
		queueEvent(eventFor(state, 'form_start'));
	}

	function interact(form, field) {
		const state = states.get(form);
		if (!state || state.completed) {
			return;
		}
		const metadata = metadataCache.get(field);
		if (!metadata) {
			return;
		}
		ensureStarted(state);
		state.lastField = metadata;
		if (!state.interacted.has(metadata.key)) {
			state.interacted.add(metadata.key);
			queueEvent(eventFor(state, 'field_interaction', {field: metadata}));
		}
	}

	function emitValidation(state) {
		state.validationTimer = null;
		const fields = Array.from(state.validationFields.values()).filter((field) => !state.validationReportedFields.has(field.key));
		state.validationFields.clear();
		if (state.completed) {
			return;
		}
		// A validation response leaves the form active, so a recent provider submit
		// attempt must not suppress a real pagehide abandonment.
		state.lastSubmitAt = 0;
		// One browser friction report per form lifecycle; field friction once per key.
		// This is structural deduplication, independent of provider evidence and timing.
		const firstReport = !state.validationReported;
		state.validationReported = true;
		fields.forEach((field) => state.validationReportedFields.add(field.key));
		if (firstReport) {
			queueEvent(eventFor(state, 'client_validation_failure', {fields: fields.slice(0, 50)}));
		} else {
			fields.slice(0, 50).forEach((field) => queueEvent(eventFor(state, 'validation_error', {field})));
		}
	}

	function validationFields(form, elements) {
		const state = states.get(form);
		if (!state || state.completed) {
			return;
		}
		ensureStarted(state);
		Array.from(elements || []).forEach((field) => {
			const metadata = metadataCache.get(field);
			if (metadata) {
				state.lastField = metadata;
				if (state.validationFields.size < 50 && !state.validationReportedFields.has(metadata.key)) {
					state.validationFields.set(metadata.key, metadata);
				}
			}
		});
		if (!state.validationTimer) {
			state.validationTimer = browserWindow.setTimeout(() => emitValidation(state), 0);
		}
	}

	function markComplete(form) {
		const state = states.get(form);
		if (state) {
			flushValidation(state);
			state.completed = true;
			state.abandoned = false;
		}
	}

	function flushValidation(state) {
		if (state.validationTimer) {
			browserWindow.clearTimeout(state.validationTimer);
			emitValidation(state);
		}
	}

	function resetPendingSubmit(form) {
		const state = states.get(form);
		if (state && !state.completed) {
			state.lastSubmitAt = 0;
		}
	}

	function abandon(form, state) {
		if (!state || !state.started || state.completed || state.abandoned) {
			return;
		}
		if (SERVER_PROVIDERS.has(state.meta.provider) && state.lastSubmitAt && Date.now() - state.lastSubmitAt < 15000) {
			return;
		}
		state.abandoned = true;
		const extra = {duration_ms: state.startedAt ? Math.min(3600000, Math.max(0, Date.now() - state.startedAt)) : 0};
		if (state.lastField) {
			extra.field = state.lastField;
		}
		queueEvent(eventFor(state, 'form_abandon', extra));
	}

	function lifecycleKey(form, meta) {
		const base = `${meta.provider}|${meta.provider_form_id}|${meta.page_path}`;
		const elementorWrapper = form.closest ? form.closest('.elementor-element[data-id]') : null;
		const context = elementorWrapper ? attributeValue(elementorWrapper, 'data-id') : '';
		if (context) {
			return `${base}|context:${context}`;
		}
		const prefix = `${base}|instance:`;
		for (const [key, state] of lifecycleCache.entries()) {
			if (key.startsWith(prefix) && (!state.currentForm || !browserDocument.contains(state.currentForm))) {
				return key;
			}
		}
		const next = nextInstanceIds.get(base) || 0;
		nextInstanceIds.set(base, next + 1);
		return `${prefix}${next}`;
	}

	let intersectionObserver = null;
	try {
		if (typeof browserWindow.IntersectionObserver === 'function') {
			intersectionObserver = new browserWindow.IntersectionObserver((entries) => {
				entries.forEach((entry) => {
					const state = states.get(entry.target);
					if (state && entry.isIntersecting && !state.viewed) {
						state.viewed = true;
						queueEvent(eventFor(state, 'form_view'));
						intersectionObserver.unobserve(entry.target);
					}
				});
			}, {threshold: [0]});
		}
	} catch {}

	function cacheFields(root) {
		const fields = root && typeof root.querySelectorAll === 'function'
			? Array.from(root.querySelectorAll('input, select, textarea')) : [];
		if (trackableField(root)) {
			fields.unshift(root);
		}
		fields.forEach((field) => {
			const form = field.form;
			const state = states.get(form);
			if (state && !metadataCache.has(field)) {
				metadataCache.set(field, fieldMeta(field, form, state.meta.provider));
			}
		});
	}

	function attachForm(form, state) {
		states.set(form, state);
		cacheFields(form);
		activeForms.add(form);
		state.currentForm = form;
		if (config.outcomeAttribution && SERVER_PROVIDERS.has(state.meta.provider) && !technicalHiddenAttribute(form, SUBMISSION_FIELD)) {
			const publicId = opaqueSubmissionId(browserWindow);
			if (publicId) {
				const marker = browserDocument.createElement('input');
				marker.setAttribute('type', 'hidden');
				marker.setAttribute('name', SUBMISSION_FIELD);
				marker.setAttribute('value', publicId);
				marker.setAttribute('data-formhawk-technical', 'submission-link');
				form.appendChild(marker);
			}
		}
		if (!state.viewed) {
			if (intersectionObserver) {
				intersectionObserver.observe(form);
			} else {
				state.viewed = true;
				queueEvent(eventFor(state, 'form_view'));
			}
		}

		form.addEventListener('focusin', (event) => interact(form, event.target), true);
		form.addEventListener('input', (event) => interact(form, event.target), true);
		form.addEventListener('change', (event) => interact(form, event.target), true);
		form.addEventListener('invalid', (event) => validationFields(form, [event.target]), true);
		form.addEventListener('submit', () => {
			const current = states.get(form);
			if (!current || current.completed || Date.now() - current.lastSubmitAt < 750) {
				return;
			}
			flushValidation(current);
			ensureStarted(current);
			current.lastSubmitAt = Date.now();
			queueEvent(eventFor(current, 'form_submit', {
				duration_ms: current.startedAt ? Math.min(3600000, Math.max(0, Date.now() - current.startedAt)) : 0,
			}), true);
			if (!SERVER_PROVIDERS.has(current.meta.provider)) {
				current.completed = true;
			}
		}, true);
	}

	function trackForm(form) {
		if (!eligible(form) || states.has(form)) {
			return;
		}
		const meta = detectProvider(form, path());
		const key = lifecycleKey(form, meta);
		let state = lifecycleCache.get(key);
		if (!state) {
			state = {
				meta,
				viewed: false,
				started: false,
				startedAt: 0,
				completed: false,
				lastSubmitAt: 0,
				abandoned: false,
				lastField: null,
				interacted: new Set(),
				validationFields: new Map(),
				validationTimer: null,
				validationReported: false,
				validationReportedFields: new Set(),
				currentForm: form,
			};
			lifecycleCache.set(key, state);
		}
		attachForm(form, state);
	}

	function discover(root = browserDocument) {
		// Dynamic providers can enqueue jQuery with their late-rendered form. Retry
		// the one-time delegated binding when discovery runs; no polling is needed.
		bindProviderEvents();
		const forms = [];
		if (root && root.tagName === 'FORM') {
			forms.push(root);
		}
		if (root && typeof root.querySelectorAll === 'function') {
			forms.push(...root.querySelectorAll('form'));
		}
		forms.forEach(trackForm);
		// Snapshot newly inserted fields before later interaction can personalize their labels.
		cacheFields(root);
	}

	function formFromProviderEvent(event) {
		const target = event.target;
		if (!target || typeof target.querySelector !== 'function') {
			return null;
		}
		return target.tagName === 'FORM' ? target : target.querySelector('form');
	}

	function bindProviderEvents() {
		if (!providerEventsBound) {
			providerEventsBound = true;
			['wpcf7mailsent', 'wpcf7mailfailed', 'wpcf7spam', 'wpcf7aborted'].forEach((eventName) => {
				browserDocument.addEventListener(eventName, (event) => {
					const form = formFromProviderEvent(event);
					if (form) {
						markComplete(form);
						flush(false);
					}
				}, false);
			});
			browserDocument.addEventListener('wpcf7invalid', (event) => {
				const form = formFromProviderEvent(event);
				if (form) {
					resetPendingSubmit(form);
				}
			}, false);
		}

		if (jqueryProviderEventsBound) {
			return;
		}

		const jq = browserWindow.jQuery;
		if (typeof jq === 'function') {
			jqueryProviderEventsBound = true;
			jq(browserDocument).on('wpformsAjaxSubmitSuccess.formhawk', 'form.wpforms-form', function () {
				markComplete(this);
				flush(false);
			});
			jq(browserDocument).on('wpformsAjaxSubmitFailed.formhawk wpformsAjaxSubmitError.formhawk', 'form.wpforms-form', function () {
				resetPendingSubmit(this);
			});
			jq(browserDocument).on('submit_success.formhawk', 'form.elementor-form', function () {
				markComplete(this);
				flush(false);
			});
			jq(browserDocument).on('error.formhawk', 'form.elementor-form', function () {
				// Server validation and action failures keep the lifecycle active.
				resetPendingSubmit(this);
			});
			jq(browserDocument).on('invalid-form.validate.formhawk', 'form.wpforms-form', function (event, validator) {
				const elements = validator && Array.isArray(validator.errorList)
					? validator.errorList.map((item) => item && item.element).filter(Boolean)
					: [];
				validationFields(this, elements);
			});
		}
	}

	function cleanupRemoved(root) {
		browserWindow.setTimeout(() => {
			activeForms.forEach((form) => {
				if ((form === root || (root.contains && root.contains(form))) && !browserDocument.contains(form)) {
					const state = states.get(form);
					if (!state || !state.currentForm || !browserDocument.contains(state.currentForm)) {
						abandon(form, state);
					}
					activeForms.delete(form);
					if (state && state.currentForm === form) {
						state.currentForm = null;
					}
					if (intersectionObserver) {
						intersectionObserver.unobserve(form);
					}
				}
			});
		}, 0);
	}

	function init() {
		bindProviderEvents();
		discover(browserDocument);
		try {
			if (typeof browserWindow.MutationObserver === 'function' && browserDocument.body) {
				mutationObserver = new browserWindow.MutationObserver((mutations) => {
					mutations.forEach((mutation) => {
						Array.from(mutation.addedNodes || []).forEach((node) => {
							if (node && node.nodeType === 1) {
								discover(node);
							}
						});
						Array.from(mutation.removedNodes || []).forEach((node) => {
							if (node && node.nodeType === 1) {
								cleanupRemoved(node);
							}
						});
					});
				});
				mutationObserver.observe(browserDocument.body, {childList: true, subtree: true});
			}
		} catch {}
	}

	function onPageHide() {
		activeForms.forEach((form) => {
			const state = states.get(form);
			if (state) {
				flushValidation(state);
			}
		});
		activeForms.forEach((form) => abandon(form, states.get(form)));
		flush(true);
	}

	browserWindow.addEventListener('pagehide', onPageHide, {capture: true});

	if (browserDocument.readyState === 'loading') {
		browserDocument.addEventListener('DOMContentLoaded', init, {once: true});
	} else {
		init();
	}

	return Object.freeze({
		refresh: () => discover(browserDocument),
		flush: () => flush(false),
		stateFor: (form) => states.get(form),
		destroy: () => {
			destroyed = true;
			if (flushTimer) {
				browserWindow.clearTimeout(flushTimer);
				flushTimer = null;
			}
			if (mutationObserver) {
				mutationObserver.disconnect();
			}
			if (intersectionObserver) {
				intersectionObserver.disconnect();
			}
			if (jqueryProviderEventsBound && typeof browserWindow.jQuery === 'function') {
				browserWindow.jQuery(browserDocument).off('.formhawk');
			}
			browserWindow.removeEventListener('pagehide', onPageHide, {capture: true});
			queue.length = 0;
		},
	});
}

if (typeof window !== 'undefined' && typeof document !== 'undefined' && window.FormhawkConfig) {
	window.Formhawk = createTracker(window, document, window.FormhawkConfig);
}

export {PROVIDERS};
