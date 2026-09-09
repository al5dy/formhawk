export function createExperimentTracker(browserWindow, browserDocument, endpoint) {
	const states = new WeakMap();
	const active = new Set();
	const queues = new Map();
	const maxAttempts = 50;
	let observer = null;
	let jqueryBound = false;

	function send(context, type, details = {}) {
		if (!context || typeof browserWindow.fetch !== 'function') return;
		const payload = {context, type, ...details};
		let queue = queues.get(context);
		if (!queue) {
			queue = {items: [], sending: false};
			queues.set(context, queue);
		}
		if (queue.items.length >= 256) return;
		queue.items.push(payload);
		drain(context, queue);
	}

	function drain(context, queue) {
		if (queue.sending || !queue.items.length) return;
		queue.sending = true;
		const payload = queue.items.shift();
		const done = () => {
			queue.sending = false;
			if (queue.items.length) drain(context, queue); else queues.delete(context);
		};
		// Serialize this assignment's observations so terminal events cannot overtake attempts.
		// Failed telemetry is never allowed to delay or intercept the customer's submission.
		try {
			Promise.resolve(browserWindow.fetch(endpoint, {
				method: 'POST',
				headers: {'Content-Type': 'application/json'},
				credentials: 'same-origin',
				keepalive: true,
				body: JSON.stringify(payload),
			})).then(done, done);
		} catch { done(); }
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
		const state = {context: assignment.context, provider: assignment.provider, started: false, attempted: false, completed: false, abandoned: false, validation: false, viewed: false, submittedAt: 0, sequence: 0};
		states.set(form, state);
		active.add(form);
		const start = () => {
			if (!state.started) {
				state.started = true;
				send(state.context, 'start');
			}
		};
		const edit = () => {
			if (state.completed) send(state.context, 'resume', {attempt: state.sequence});
			state.completed = false;
			start();
		};
		form.addEventListener('focusin', start, {capture: true});
		form.addEventListener('input', edit, {capture: true});
		form.addEventListener('change', edit, {capture: true});
		const invalid = () => {
			edit();
			if (!state.validation) {
				state.validation = true;
				send(state.context, 'client_validation');
			}
		};
		const submit = () => {
			if (state.attempted || state.sequence >= maxAttempts) return;
			start();
			state.completed = false;
			state.attempted = true;
			state.submittedAt = Date.now();
			state.sequence += 1;
			send(state.context, 'attempt', {attempt: state.sequence});
			if (state.provider === 'html') send(state.context, 'observed_submit', {attempt: state.sequence});
		};
		form.addEventListener('invalid', invalid, {capture: true});
		form.addEventListener('submit', submit, {capture: true});
		state.detach = () => {
			form.removeEventListener('focusin', start, true);
			form.removeEventListener('input', edit, true);
			form.removeEventListener('change', edit, true);
			form.removeEventListener('invalid', invalid, true);
			form.removeEventListener('submit', submit, true);
		};
		if (observer) observer.observe(form); else viewed(form, state);
	}

	function terminal(form, successful = true) {
		const state = states.get(form);
		if (!state || !state.attempted) return;
		send(state.context, 'latency', {attempt: state.sequence, latency_ms: Math.max(0, Math.min(300000, Date.now() - state.submittedAt)), successful: Boolean(successful)});
		state.submittedAt = 0;
		// Only an in-flight attempt accepts a terminal callback. A later submit
		// re-arms it without changing the page's experiment assignment or start.
		state.attempted = false;
		state.completed = successful;
	}

	function detach(form) {
		const state = states.get(form);
		if (state) state.detach();
		if (observer) observer.unobserve(form);
		states.delete(form);
		active.delete(form);
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
		if (state && state.started && !state.attempted && !state.completed && !state.abandoned) {
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
		detach,
		send,
		terminal,
		destroy() {
			active.forEach(detach);
			if (observer) observer.disconnect();
			browserDocument.removeEventListener('wpcf7submit', domCF7Terminal);
			browserDocument.removeEventListener('submit_success', domElementorTerminal);
			browserDocument.removeEventListener('error', domElementorTerminal);
			browserWindow.removeEventListener('pagehide', pagehide, {capture: true});
			if (jqueryBound && typeof browserWindow.jQuery === 'function') browserWindow.jQuery(browserDocument).off('.formhawkCro');
		},
	});
}
