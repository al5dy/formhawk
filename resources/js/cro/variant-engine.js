const FORBIDDEN_PATTERN = /(?:password|passcode|payment|card(?:number)?|credit|debit|\bcvv\b|\bcvc\b|expir(?:y|ation)|bank|iban|swift|otp|2fa|captcha|recaptcha|turnstile|hcaptcha|nonce|csrf|honeypot|terms|privacy|gdpr|consent|agreement|signature|medical|health|diagnos|ssn|social[_ -]?security|auth(?:entication)?|login|security)/i;
const CONDITIONAL_SELECTOR = '[data-condition], [data-conditional], [data-conditional-logic], [data-dependency], [data-depends-on], [aria-controls], .wpforms-conditional-show, .wpforms-conditional-hide, .wpcf7cf-hidden, .wpcf7cf-show, .elementor-field-type-step';
const SECURITY_SELECTOR = 'input[type="password"], input[type="file"], [class*="captcha"], [class*="recaptcha"], [class*="turnstile"], [class*="hcaptcha"], [data-sitekey]';
let stepInstance = 0;

function attribute(element, name) {
	return element ? String(element.getAttribute(name) || '').slice(0, 191) : '';
}

function providerFieldKey(field, provider) {
	const name = attribute(field, 'name');
	if (provider === 'wpforms') {
		const match = name.match(/^wpforms\[fields\]\[([^\]]+)\]/);
		return match ? match[1] : attribute(field.closest('.wpforms-field'), 'data-field-id');
	}
	if (provider === 'elementor') {
		const match = name.match(/^form_fields\[([^\]]+)\]/);
		return match ? match[1] : '';
	}
	if (provider === 'cf7') {
		return attribute(field.closest('.wpcf7-form-control-wrap'), 'data-name') || name;
	}
	return name || attribute(field, 'id');
}

function trackableFields(form) {
	return Array.from(form.querySelectorAll('input, select, textarea')).filter((field) => {
		const type = attribute(field, 'type').toLowerCase();
		return !['hidden', 'submit', 'button', 'reset', 'image'].includes(type) && !field.disabled;
	});
}

function fieldUnit(field, provider) {
	if (provider === 'wpforms') {
		return field.closest('.wpforms-field[data-field-id]');
	}
	if (provider === 'elementor') {
		return field.closest('.elementor-field-group');
	}
	if (provider === 'cf7') {
		const wrap = field.closest('.wpcf7-form-control-wrap');
		return wrap && (wrap.closest('p') || wrap.closest('label') || wrap);
	}
	return field.closest('[data-formhawk-field], .form-field, .form-group, p, label') || field;
}

function fieldLabel(field) {
	const form = field.form;
	let label = '';
	const id = attribute(field, 'id');
	if (form && id) {
		const escaped = typeof CSS !== 'undefined' && CSS.escape ? CSS.escape(id) : id.replace(/[^a-zA-Z0-9_-]/g, '');
		const node = form.querySelector(`label[for="${escaped}"]`);
		label = node ? String(node.textContent || '') : '';
	}
	if (!label) {
		const node = field.closest('label');
		label = node ? String(node.textContent || '') : '';
	}
	return label.slice(0, 191);
}

export function classifyField(field, provider = 'html') {
	const type = attribute(field, 'type').toLowerCase();
	const key = providerFieldKey(field, provider);
	const structuralDescriptor = `${type} ${key} ${attribute(field, 'id')} ${attribute(field, 'autocomplete')} ${fieldLabel(field)}`;
	if (type === 'file' || type === 'hidden' || FORBIDDEN_PATTERN.test(structuralDescriptor)) {
		return 'FORBIDDEN';
	}
	if (field.required || attribute(field, 'aria-required') === 'true') {
		return 'PROTECTED';
	}
	if (['checkbox', 'radio', 'date', 'time', 'tel', 'number'].includes(type) || /(?:phone|mobile|budget|company|address|country|state|postal|zip|job[_ -]?title|qualification)/i.test(structuralDescriptor)) {
		return 'CAUTION';
	}
	return 'SAFE';
}

export function inspectForm(form, provider) {
	if (!form || form.querySelector(SECURITY_SELECTOR) || form.querySelector(CONDITIONAL_SELECTOR)) {
		return {safeStructure: false, fields: []};
	}
	const indexed = new Map();
	const units = new Map();
	const fields = [];
	for (const field of trackableFields(form)) {
		const key = providerFieldKey(field, provider);
		const unit = fieldUnit(field, provider);
		if (!key || !unit) {
			return {safeStructure: false, fields: []};
		}
		if (indexed.has(key)) {
			const existing = indexed.get(key);
			if (existing.unit !== unit) return {safeStructure: false, fields: []};
			existing.controls.push(field);
			const classification = classifyField(field, provider);
			const rank = {SAFE: 0, CAUTION: 1, PROTECTED: 2, FORBIDDEN: 3};
			if (rank[classification] > rank[existing.classification]) existing.classification = classification;
			continue;
		}
		if (units.has(unit) && units.get(unit) !== key) return {safeStructure: false, fields: []};
		const item = {key, field, controls: [field], unit, classification: classifyField(field, provider)};
		indexed.set(key, item);
		units.set(unit, key);
		fields.push(item);
	}
	return {safeStructure: fields.length > 0, fields};
}

function appendRestoreAnchor(unit, restores) {
	if (!unit.parentNode) {
		throw new Error('detached_field_unit');
	}
	const anchor = unit.ownerDocument.createComment('formhawk-cro-anchor');
	unit.parentNode.insertBefore(anchor, unit);
	restores.push(() => {
		if (anchor.parentNode) {
			anchor.parentNode.insertBefore(unit, anchor);
			anchor.remove();
		}
	});
	return anchor;
}

function submitControls(form) {
	return Array.from(form.querySelectorAll('button[type="submit"], input[type="submit"], button:not([type])'));
}

function applyFieldOrder(form, provider, config, restores) {
	const inspection = inspectForm(form, provider);
	if (!inspection.safeStructure) {
		throw new Error('unsafe_dependency_graph');
	}
	const requested = Array.isArray(config.field_order) ? config.field_order : [];
	const target = requested[requested.length - 1];
	const targetField = inspection.fields.find((item) => item.key === target);
	if (!targetField || !['SAFE', 'CAUTION'].includes(targetField.classification)) {
		throw new Error('unsafe_reorder_target');
	}
	const slots = inspection.fields.map((item) => appendRestoreAnchor(item.unit, restores));
	const parent = targetField.unit.parentNode;
	const ordered = requested[0] === '__all_except_target__'
		? inspection.fields.filter((item) => item.key !== target).concat(targetField)
		: requested.map((key) => inspection.fields.find((item) => item.key === key)).filter(Boolean);
	if (ordered.length !== inspection.fields.length || ordered.some((item) => item.unit.parentNode !== parent)) {
		throw new Error('non_independent_field_units');
	}
	ordered.forEach((item, index) => slots[index].parentNode.insertBefore(item.unit, slots[index]));
}

function applyProgressive(form, provider, config, restores, strings) {
	const inspection = inspectForm(form, provider);
	const keys = Array.isArray(config.fields) ? config.fields : [];
	const selected = keys.map((key) => inspection.fields.find((item) => item.key === key)).filter(Boolean);
	if (!inspection.safeStructure || selected.length !== keys.length || selected.some((item) => !['SAFE', 'CAUTION'].includes(item.classification))) {
		throw new Error('unsafe_progressive_fields');
	}
	const details = form.ownerDocument.createElement('details');
	details.className = 'formhawk-cro-more';
	const summary = form.ownerDocument.createElement('summary');
	summary.textContent = String(config.label || strings.more || 'Add additional information');
	details.appendChild(summary);
	selected.forEach((item) => {
		appendRestoreAnchor(item.unit, restores);
		details.appendChild(item.unit);
	});
	const first = submitControls(form)[0];
	(first && first.parentNode ? first.parentNode : form).insertBefore(details, first || null);
	restores.push(() => details.remove());
}

export function applyRemoveField(form, provider, config, restores) {
	const inspection = inspectForm(form, provider);
	const target = inspection.fields.find((item) => item.key === config.field_key);
	const allowed = config.safety === 'caution' ? ['SAFE', 'CAUTION'] : ['SAFE'];
	if (!config.dependency_verified || !inspection.safeStructure || !target || !allowed.includes(target.classification) || target.controls.some((control) => control.required || attribute(control, 'aria-required') === 'true') || hasExternalReferences(form, target)) {
		throw new Error('unsafe_remove_field');
	}
	appendRestoreAnchor(target.unit, restores);
	target.unit.remove();
}

function hasExternalReferences(form, target) {
	const ids = Array.from(target.unit.querySelectorAll('[id]'));
	if (target.unit.id) ids.push(target.unit);
	const values = new Set(ids.map((node) => attribute(node, 'id')).filter(Boolean));
	if (!values.size) return false;
	return Array.from(form.querySelectorAll('label[for], [aria-describedby], [aria-labelledby], [aria-controls]')).some((node) => {
		if (target.unit.contains(node)) return false;
		if (node.tagName === 'LABEL' && values.has(attribute(node, 'for'))) return true;
		return ['aria-describedby', 'aria-labelledby', 'aria-controls'].some((name) => attribute(node, name).split(/\s+/).some((id) => values.has(id)));
	});
}

export function applyMakeOptional(form, provider, config, restores) {
	const inspection = inspectForm(form, provider);
	const target = inspection.fields.find((item) => item.key === config.field_key);
	if (!config.provider_semantics_verified || !inspection.safeStructure || !target || target.classification === 'FORBIDDEN') {
		throw new Error('unsafe_make_optional');
	}
	target.controls.forEach((control) => {
		const required = control.required;
		const requiredAttribute = control.getAttribute('required');
		const ariaRequired = control.getAttribute('aria-required');
		control.required = false;
		control.removeAttribute('required');
		control.setAttribute('aria-required', 'false');
		restores.push(() => {
			control.required = required;
			if (requiredAttribute === null) control.removeAttribute('required'); else control.setAttribute('required', requiredAttribute);
			if (ariaRequired === null) control.removeAttribute('aria-required'); else control.setAttribute('aria-required', ariaRequired);
		});
	});
	target.unit.classList.add('formhawk-cro-optional');
	restores.push(() => target.unit.classList.remove('formhawk-cro-optional'));
}

export function applyMakeRequired(form, provider, config, restores) {
	const inspection = inspectForm(form, provider);
	const target = inspection.fields.find((item) => item.key === config.field_key);
	if (!config.provider_semantics_verified || !config.risk_authorized || !inspection.safeStructure || !target || target.classification === 'FORBIDDEN') {
		throw new Error('unsafe_make_required');
	}
	const controls = target.controls.filter((control) => !['checkbox', 'radio'].includes(attribute(control, 'type').toLowerCase()));
	if (controls.length !== 1) throw new Error('ambiguous_required_control');
	const control = controls[0];
	const required = control.required;
	const requiredAttribute = control.getAttribute('required');
	const ariaRequired = control.getAttribute('aria-required');
	control.required = true;
	control.setAttribute('required', '');
	control.setAttribute('aria-required', 'true');
	restores.push(() => {
		control.required = required;
		if (requiredAttribute === null) control.removeAttribute('required'); else control.setAttribute('required', requiredAttribute);
		if (ariaRequired === null) control.removeAttribute('aria-required'); else control.setAttribute('aria-required', ariaRequired);
	});
}

function clearChildren(node) {
	while (node.firstChild) {
		node.removeChild(node.firstChild);
	}
}

function applySubmitButton(form, config, restores) {
	const controls = submitControls(form);
	if (!controls.length || typeof config.text !== 'string' || !config.text.trim()) {
		throw new Error('submit_control_missing');
	}
	controls.forEach((control) => {
		if (control.tagName === 'INPUT') {
			const original = control.getAttribute('value');
			control.setAttribute('value', config.text.slice(0, 80));
			restores.push(() => original === null ? control.removeAttribute('value') : control.setAttribute('value', original));
		} else {
			const children = Array.from(control.childNodes).map((node) => node.cloneNode(true));
			control.textContent = config.text.slice(0, 80);
			restores.push(() => {
				clearChildren(control);
				children.forEach((node) => control.appendChild(node));
			});
		}
	});
}

function showStep(stepNodes, index, submitters, progress, live) {
	stepNodes.forEach((step, stepIndex) => {
		const active = stepIndex === index;
		step.hidden = !active;
		if (active) {
			step.removeAttribute('inert');
		} else {
			step.setAttribute('inert', '');
		}
	});
	submitters.forEach((button) => { button.hidden = index !== stepNodes.length - 1; });
	progress.setAttribute('aria-valuenow', String(index + 1));
	progress.textContent = `${index + 1} / ${stepNodes.length}`;
	live.textContent = `${index + 1} / ${stepNodes.length}`;
}

function applyMultiStep(form, provider, config, restores, strings) {
	const inspection = inspectForm(form, provider);
	if (!inspection.safeStructure || inspection.fields.length < 6 || inspection.fields.some((item) => item.classification === 'FORBIDDEN')) {
		throw new Error('unsafe_multi_step_form');
	}
	let groups = Array.isArray(config.steps) ? config.steps : [];
	if (groups.length === 2 && groups[0][0] === '__first_half__') {
		const split = Math.ceil(inspection.fields.length / 2);
		groups = [inspection.fields.slice(0, split).map((item) => item.key), inspection.fields.slice(split).map((item) => item.key)];
	}
	const flat = groups.reduce((all, group) => all.concat(group), []);
	if (groups.length < 2 || flat.length !== inspection.fields.length || new Set(flat).size !== inspection.fields.length) {
		throw new Error('invalid_step_partition');
	}
	const document = form.ownerDocument;
	const shell = document.createElement('div');
	shell.className = 'formhawk-cro-steps';
	const firstUnit = inspection.fields[0].unit;
	if (!firstUnit.parentNode) throw new Error('detached_step_fields');
	firstUnit.parentNode.insertBefore(shell, firstUnit);
	restores.push(() => shell.remove());
	const progress = document.createElement('div');
	progress.className = 'formhawk-cro-progress';
	progress.setAttribute('role', 'progressbar');
	progress.setAttribute('aria-valuemin', '1');
	progress.setAttribute('aria-valuemax', String(groups.length));
	progress.setAttribute('aria-label', strings.progress || 'Form progress');
	const live = document.createElement('span');
	live.className = 'screen-reader-text';
	live.setAttribute('aria-live', 'polite');
	shell.append(progress, live);
	const stepNodes = [];
	let currentIndex = 0;
	const moveTo = (index) => {
		currentIndex = index;
		showStep(stepNodes, index, submitters, progress, live);
		const focus = stepNodes[index].querySelector('input, select, textarea, button');
		if (focus) focus.focus();
	};
	const advance = (index) => {
		const step = stepNodes[index];
		const invalid = Array.from(step.querySelectorAll('input, select, textarea')).find((field) => {
			if (typeof field.checkValidity !== 'function') return false;
			// Provider markup can contain a browser-version-incompatible pattern.
			// Constraint API failure must not trap the visitor inside an Autopilot step.
			try { return !field.checkValidity(); } catch { return false; }
		});
		if (invalid) {
			if (typeof invalid.reportValidity === 'function') {
				try { invalid.reportValidity(); } catch {}
			}
			invalid.focus();
			return false;
		}
		moveTo(index + 1);
		return true;
	};
	stepInstance += 1;
	groups.forEach((keys, index) => {
		const step = document.createElement('section');
		step.className = 'formhawk-cro-step';
		const heading = document.createElement('h3');
		const headingId = `formhawk-step-${stepInstance}-${index}`;
		heading.id = headingId;
		heading.className = 'screen-reader-text';
		heading.textContent = `${strings.step || 'Step'} ${index + 1}`;
		step.setAttribute('role', 'group');
		step.setAttribute('aria-labelledby', headingId);
		step.appendChild(heading);
		keys.forEach((key) => {
			const item = inspection.fields.find((field) => field.key === key);
			if (!item) {
				throw new Error('step_field_missing');
			}
			appendRestoreAnchor(item.unit, restores);
			step.appendChild(item.unit);
		});
		const nav = document.createElement('div');
		nav.className = 'formhawk-cro-step-nav';
		if (index > 0) {
			const back = document.createElement('button');
			back.type = 'button';
			back.className = 'formhawk-cro-back';
			back.textContent = strings.back || 'Back';
			back.addEventListener('click', () => {
				moveTo(index - 1);
			});
			nav.appendChild(back);
		}
		if (index < groups.length - 1) {
			const next = document.createElement('button');
			next.type = 'button';
			next.className = 'formhawk-cro-next';
			next.textContent = strings.next || 'Next';
			next.addEventListener('click', () => advance(index));
			nav.appendChild(next);
		}
		step.appendChild(nav);
		stepNodes.push(step);
		shell.appendChild(step);
	});
	const submitters = submitControls(form);
	submitters.forEach((button) => {
		const original = button.hidden;
		restores.push(() => { button.hidden = original; });
	});
	const handleKeydown = (event) => {
		if (event.key === 'Enter' && currentIndex < stepNodes.length - 1 && !event.shiftKey && event.target && !['TEXTAREA', 'BUTTON'].includes(event.target.tagName)) {
			event.preventDefault();
			advance(currentIndex);
		}
	};
	const handleSubmit = (event) => {
		if (currentIndex < stepNodes.length - 1) {
			event.preventDefault();
			event.stopImmediatePropagation();
			advance(currentIndex);
		}
	};
	const handleInvalid = (event) => {
		const index = stepNodes.findIndex((step) => step.contains(event.target));
		if (index >= 0 && index !== currentIndex) moveTo(index);
	};
	form.addEventListener('keydown', handleKeydown, {capture: true});
	form.addEventListener('submit', handleSubmit, {capture: true});
	form.addEventListener('invalid', handleInvalid, {capture: true});
	restores.push(() => {
		form.removeEventListener('keydown', handleKeydown, {capture: true});
		form.removeEventListener('submit', handleSubmit, {capture: true});
		form.removeEventListener('invalid', handleInvalid, {capture: true});
	});
	showStep(stepNodes, 0, submitters, progress, live);
}

function applyPresentationClass(form, className, restores) {
	form.classList.add(className);
	restores.push(() => form.classList.remove(className));
}

export function applyVariant(form, assignment, strings = {}) {
	const restores = [];
	try {
		const applicationKey = `${assignment && assignment.experiment_id || 0}:${assignment && assignment.variant_id || 0}`;
		if (form && form.getAttribute('data-formhawk-cro-applied') === applicationKey) {
			return {ok: true, reused: true, restore() {}};
		}
		if (form && form.hasAttribute('data-formhawk-cro-applied')) {
			throw new Error('different_variant_already_applied');
		}
		if (!assignment || !assignment.config || !Array.isArray(assignment.config.mutations)) {
			throw new Error('invalid_variant_config');
		}
		for (const mutation of assignment.config.mutations) {
			if (!mutation || typeof mutation.type !== 'string' || !mutation.config || typeof mutation.config !== 'object') {
				throw new Error('invalid_mutation');
			}
			switch (mutation.type) {
				case 'field_order': applyFieldOrder(form, assignment.provider, mutation.config, restores); break;
				case 'progressive_disclosure': applyProgressive(form, assignment.provider, mutation.config, restores, strings); break;
				case 'multi_step': applyMultiStep(form, assignment.provider, mutation.config, restores, strings); break;
				case 'submit_button': applySubmitButton(form, mutation.config, restores); break;
				case 'label_presentation': applyPresentationClass(form, `formhawk-labels-${mutation.config.position}`, restores); break;
				case 'placeholder_presentation': applyPresentationClass(form, `formhawk-placeholders-${mutation.config.mode}`, restores); break;
				case 'remove_field': applyRemoveField(form, assignment.provider, mutation.config, restores); break;
				case 'make_optional': applyMakeOptional(form, assignment.provider, mutation.config, restores); break;
				case 'make_required': applyMakeRequired(form, assignment.provider, mutation.config, restores); break;
				default: throw new Error('unsupported_mutation');
			}
		}
		form.setAttribute('data-formhawk-cro-applied', applicationKey);
		restores.push(() => form.removeAttribute('data-formhawk-cro-applied'));
		return {
			ok: true,
			restore() {
				for (let index = restores.length - 1; index >= 0; index -= 1) {
					try { restores[index](); } catch {}
				}
			},
		};
	} catch (error) {
		for (let index = restores.length - 1; index >= 0; index -= 1) {
			try { restores[index](); } catch {}
		}
		return {ok: false, reason: String(error && error.message || 'mutation_failed'), restore() {}};
	}
}

export function installContextMarker(form, context) {
	form.querySelectorAll('input[name="_formhawk_cro"]').forEach((marker) => marker.remove());
	if (!context) return;
	const marker = form.ownerDocument.createElement('input');
	marker.type = 'hidden';
	marker.name = '_formhawk_cro';
	marker.setAttribute('value', context);
	marker.setAttribute('data-formhawk-technical', 'experiment-context');
	form.appendChild(marker);
}
