(function () {
	const root = document.querySelector('.nc-mock');
	if (!root || typeof ncMock === 'undefined') {
		return;
	}

	const logEl = root.querySelector('[data-log]');
	const runEl = root.querySelector('[data-run]');
	const runFill = root.querySelector('[data-run-fill]');
	const runCaption = root.querySelector('[data-run-caption]');
	const stopBtn = root.querySelector('[data-action="stop"]');

	let abort = false;

	root.addEventListener('click', async (event) => {
		const button = event.target.closest('[data-action]');
		if (!button) {
			return;
		}

		const action = button.getAttribute('data-action');

		if (action === 'stop') {
			abort = true;
			return;
		}

		if (action === 'refresh') {
			await refreshCatalog();
			return;
		}

		if (action === 'generate') {
			const count = parseInt(button.getAttribute('data-count'), 10) || 10;
			await generate(count);
			return;
		}

		if (action === 'fill') {
			const total = parseInt(root.querySelector('[data-stat="total"]').textContent, 10) || 0;
			const need = Math.max(0, ncMock.target - total);
			if (need > 0) {
				await generate(need);
			}
			return;
		}

		if (action === 'remove') {
			if (!window.confirm('Archive every product created by North Mock? This hides them from the store but does not delete saved images.')) {
				return;
			}
			await removeAll();
		}
	});

	function selectedSources() {
		return Array.from(root.querySelectorAll('input[name="nc_mock_source"]:checked')).map(
			(input) => input.value
		);
	}

	async function post(action, extra) {
		const body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', ncMock.nonce);
		body.set('sources', selectedSources().join(','));
		if (extra) {
			Object.keys(extra).forEach((key) => body.set(key, extra[key]));
		}

		const response = await fetch(ncMock.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body,
		});

		const json = await response.json();
		if (!json || !json.success) {
			const message =
				(json && json.data && json.data.message) ||
				'Request failed (' + response.status + ')';
			throw new Error(message);
		}

		return json.data;
	}

	async function refreshCatalog() {
		setBusy(true);
		log('Fetching store catalogs…', false, true);
		try {
			const data = await post('nc_mock_refresh');
			applyStatus(data);
			const errors = data.errors || (data.meta && data.meta.errors) || {};
			const failed = Object.keys(errors);
			if (failed.length) {
				failed.forEach((id) => log(id + ': ' + errors[id], true));
			} else {
				log('Catalogs updated. ' + (data.remaining || 0) + ' unused products ready.');
			}
		} catch (error) {
			log(error.message, true);
		} finally {
			setBusy(false);
		}
	}

	async function generate(count) {
		abort = false;
		setBusy(true);
		runEl.hidden = false;
		stopBtn.hidden = false;
		clearLog();
		updateRun(0, count, 'Starting…');

		let created = 0;
		let skipped = 0;
		let failed = 0;
		let refreshed = false;

		for (let i = 0; i < count; i++) {
			if (abort) {
				log('Stopped after ' + created + ' product(s).');
				break;
			}

			updateRun(i, count, 'Creating product ' + (i + 1) + ' of ' + count + '…');

			try {
				const data = await post('nc_mock_generate');
				if (data.status) {
					applyStatus(data.status);
				}

				if (data.refreshed) {
					if (refreshed) {
						log('Catalog fetch did not stick. Check that wp-content/uploads is writable.', true);
						break;
					}
					refreshed = true;
					log('Catalogs fetched. Creating products…');
					i--;
					continue;
				}

				if (data.exhausted) {
					log('Catalog exhausted. Refresh or choose more sources.');
					break;
				}

				const product = data.product;
				if (product && product.skipped) {
					skipped++;
					log('Skipped (already exists): ' + product.name);
				} else if (product) {
					created++;
					log(
						product.source +
							' — ' +
							product.name +
							' · ' +
							product.variants +
							' variants · ' +
							product.images +
							' images'
					);
				}
			} catch (error) {
				failed++;
				log(error.message, true);
			}

			updateRun(i + 1, count, created + ' created');
		}

		updateRun(count, count, created + ' created, ' + skipped + ' skipped, ' + failed + ' failed');
		stopBtn.hidden = true;
		setBusy(false);
	}

	async function removeAll() {
		abort = false;
		setBusy(true);
		runEl.hidden = false;
		stopBtn.hidden = false;
		let archived = 0;

		while (!abort) {
			try {
				const data = await post('nc_mock_remove');
				archived += data.archived || 0;
				if (data.status) {
					applyStatus(data.status);
				}
				updateRun(1, 1, 'Archived ' + archived + ' mock products…');
				if (!data.more || !data.archived) {
					break;
				}
			} catch (error) {
				log(error.message, true);
				break;
			}
		}

		log('Archived ' + archived + ' mock product(s).');
		stopBtn.hidden = true;
		setBusy(false);
	}

	function applyStatus(status) {
		setText('[data-stat="total"]', status.total);
		setText('[data-stat="mock"]', status.mock);
		setText('[data-stat="remaining"]', status.remaining);

		const pct = Math.min(100, Math.round((status.total / ncMock.target) * 100));
		root.querySelector('[data-progress-fill]').style.width = pct + '%';

		const need = Math.max(0, ncMock.target - status.total);
		const caption = root.querySelector('[data-progress-caption]');
		caption.textContent =
			need > 0
				? status.total +
				  ' of ' +
				  ncMock.target +
				  ' — generate ' +
				  need +
				  ' more to hit the target. Variant SKUs do not count.'
				: 'Target reached. You can still generate more.';

		const fillBtn = root.querySelector('[data-action="fill"]');
		fillBtn.disabled = need <= 0;
		fillBtn.textContent = need > 0 ? 'Fill to 150 (' + need + ')' : 'Target reached';

		if (status.sources) {
			Object.keys(status.sources).forEach((id) => {
				const el = root.querySelector('[data-source-count="' + id + '"]');
				if (!el) {
					return;
				}
				const source = status.sources[id];
				el.textContent = source.unused + ' ready · ' + source.total + ' cached';
			});
		}

		const meta = root.querySelector('[data-cache-meta]');
		if (status.fetchedAt) {
			meta.textContent = 'Catalog last fetched just now.';
		}
	}

	function updateRun(current, total, label) {
		const pct = total ? Math.round((current / total) * 100) : 0;
		runFill.style.width = pct + '%';
		runCaption.textContent = label;
	}

	function log(message, isError, reset) {
		if (reset) {
			clearLog();
		}
		const item = document.createElement('li');
		item.textContent = message;
		if (isError) {
			item.className = 'is-error';
		}
		logEl.appendChild(item);
		logEl.scrollTop = logEl.scrollHeight;
	}

	function clearLog() {
		logEl.innerHTML = '';
	}

	function setText(selector, value) {
		const el = root.querySelector(selector);
		if (el) {
			el.textContent = String(value);
		}
	}

	function setBusy(busy) {
		root.classList.toggle('is-busy', busy);
		if (!busy) {
			abort = false;
		}
	}
})();
