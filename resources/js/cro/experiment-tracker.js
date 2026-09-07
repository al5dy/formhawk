export function createExperimentTracker(browserWindow, browserDocument, endpoint) {
	const states = new WeakMap();
	const active = new Set();
	let observer = null;
	let jqueryBound = false;

	function send(context, type, latencyMs) {
		if (!context || typeof browserWindow.fetch !== 'function') return;
		const payload = {context, type};
		if (Number.isInteger(latencyMs) && latencyMs >= 0) payload.latency_ms = Math.min(300000, latencyMs);
		try {
			browserWindow.fetch(endpoint, {
				method: 'POST',
				headers: {'Content-Type': 'application/json'},
				credentials: 'same-origin',
				keepalive: true,
				body: JSON.stringify(payload),
			}).catch(() => {});
		} catch {}
	}

	function viewed(form, state) {
		if (state.viewed) return;
		state.viewed = true;
		send(state.context, 'view');
		if (observer) observer.unobserve(form);
	}

	function attach(form, assignment) {
		bindJqueryProviderEvents();
		if (!assignment.context || states.has(form)) return;
		const state = {context: assignment.context, provider: assignment.provider, started: false, attempted: false, abandoned: false, validation: false, viewed: false, submittedAt: 0};
		states.set(form, state);
		active.add(form);
		const start = () => {
			if (!state.started) {
				state.started = true;
				send(state.context, 'start');
			}
		};
		form.addEventListener('focusin', start, {capture: true});
		form.addEventListener('input', start, {capture: true});
		form.addEventListener('change', start, {capture: true});
		form.addEventListener('invalid', () => {
			start();
			if (!state.validation) {
				state.validation = true;
				send(state.context, 'client_validation');
			}
		}, {capture: true});
		form.addEventListener('submit', () => {
			if (state.attempted) return;
			start();
			state.attempted = true;
			state.submittedAt = Date.now();
			send(state.context, 'attempt');
			if (state.provider === 'html') send(state.context, 'observed_submit');
		}, {capture: true});
		if (observer) observer.observe(form); else viewed(form, state);
	}

	function terminal(form, successful = true) {
		const state = states.get(form);
		if (!state || !state.submittedAt) return;
		send(state.context, 'latency', Date.now() - state.submittedAt);
		state.submittedAt = 0;
		if (!successful) state.attempted = false;
	}

	function providerForm(event) {
		const target = event && event.target;
		if (!target || typeof target.querySelector !== 'function') return null;
		return target.tagName === 'FORM' ? target : target.querySelector('form');
	}

	function domCF7Terminal(event) {
		const status = event && event.detail ? String(event.detail.status || '') : '';
		terminal(providerForm(event), status === 'mail_sent');
	}

	function domElementorTerminal(event) {
		terminal(providerForm(event), event.type === 'submit_success');
	}

	function bindJqueryProviderEvents() {
		if (jqueryBound || typeof browserWindow.jQuery !== 'function') return;
		jqueryBound = true;
		const jq = browserWindow.jQuery;
		jq(browserDocument).on('wpformsAjaxSubmitSuccess.formhawkCro', 'form.wpforms-form', function () { terminal(this, true); });
		jq(browserDocument).on('wpformsAjaxSubmitFailed.formhawkCro wpformsAjaxSubmitError.formhawkCro', 'form.wpforms-form', function () { terminal(this, false); });
		jq(browserDocument).on('submit_success.formhawkCro', 'form.elementor-form', function () { terminal(this, true); });
		jq(browserDocument).on('error.formhawkCro', 'form.elementor-form', function () { terminal(this, false); });
	}

	try {
		if (typeof browserWindow.IntersectionObserver === 'function') {
			observer = new browserWindow.IntersectionObserver((entries) => entries.forEach((entry) => {
				const state = states.get(entry.target);
				if (entry.isIntersecting && state) viewed(entry.target, state);
			}), {threshold: 0.25});
		}
	} catch { observer = null; }

	const pagehide = () => active.forEach((form) => {
		const state = states.get(form);
		if (state && state.started && !state.attempted && !state.abandoned) {
			state.abandoned = true;
			send(state.context, 'abandon');
		}
	});
	browserDocument.addEventListener('wpcf7submit', domCF7Terminal);
	browserDocument.addEventListener('submit_success', domElementorTerminal);
	browserDocument.addEventListener('error', domElementorTerminal);
	browserWindow.addEventListener('pagehide', pagehide, {capture: true});
	bindJqueryProviderEvents();

	return Object.freeze({
		attach,
		send,
		terminal,
		destroy() {
			if (observer) observer.disconnect();
			browserDocument.removeEventListener('wpcf7submit', domCF7Terminal);
			browserDocument.removeEventListener('submit_success', domElementorTerminal);
			browserDocument.removeEventListener('error', domElementorTerminal);
			browserWindow.removeEventListener('pagehide', pagehide, {capture: true});
			if (jqueryBound && typeof browserWindow.jQuery === 'function') browserWindow.jQuery(browserDocument).off('.formhawkCro');
		},
	});
}
