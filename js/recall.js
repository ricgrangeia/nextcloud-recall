/**
 * Melhoria progressiva da interface do Recall.
 *
 * JavaScript simples, sem build: nao ha npm, nem bundler, nem transpilador --
 * este ficheiro e servido tal e qual. Se falhar ou nao carregar, os
 * formularios continuam a funcionar por POST normal, so com recarregamento da
 * pagina; nada aqui e indispensavel.
 *
 * Fala com a API OCS da propria app, que ja existe e esta testada, em vez de
 * duplicar logica no cliente.
 */
(function () {
	'use strict';

	const root = document.getElementById('recall');
	if (!root) {
		return;
	}

	const episodesUrl = root.dataset.episodesUrl;
	const contactsUrl = root.dataset.contactsUrl;
	if (!episodesUrl || !contactsUrl) {
		return;
	}

	function token() {
		if (typeof OC !== 'undefined' && OC.requestToken) {
			return OC.requestToken;
		}
		return (document.head && document.head.dataset.requesttoken) || '';
	}

	function ocs(url, options) {
		const opts = options || {};
		return fetch(url, {
			method: opts.method || 'GET',
			headers: Object.assign({
				'OCS-APIRequest': 'true',
				'requesttoken': token(),
				'Accept': 'application/json',
				'Content-Type': 'application/json',
			}, opts.headers || {}),
			body: opts.body ? JSON.stringify(opts.body) : undefined,
		}).then(function (response) {
			if (!response.ok) {
				throw new Error('HTTP ' + response.status);
			}
			return response.json();
		}).then(function (json) {
			return json.ocs ? json.ocs.data : json;
		});
	}

	// ---------------------------------------------------------------- apagar

	root.addEventListener('submit', function (event) {
		const form = event.target.closest('form[data-delete-id]');
		if (!form) {
			return;
		}
		// O confirm() esta no onsubmit do proprio formulario, para tambem
		// existir sem JavaScript. Se o utilizador cancelou, o evento chega
		// aqui ja cancelado -- e prosseguir apagaria o que ele recusou.
		if (event.defaultPrevented) {
			return;
		}
		event.preventDefault();

		const id = form.dataset.deleteId;
		const row = form.closest('tr');
		ocs(episodesUrl + '/' + encodeURIComponent(id) + '?format=json', { method: 'DELETE' })
			.then(function () {
				if (row) {
					row.remove();
				}
			})
			.catch(function () {
				// Sem rede ou sem permissao: cai no caminho normal, que
				// mostra o erro do Nextcloud em vez de falhar em silencio.
				form.submit();
			});
	});

	// ------------------------------------------------- seletor de contactos

	const contactInput = document.getElementById('recall-contact');
	const contactRef = document.getElementById('recall-contact-ref');
	const contactList = document.getElementById('recall-contact-results');

	if (contactInput && contactRef && contactList) {
		let timer = null;

		function clearResults() {
			contactList.innerHTML = '';
			contactList.hidden = true;
		}

		function choose(contact) {
			contactInput.value = contact.name;
			contactRef.value = contact.uid;
			clearResults();
		}

		contactInput.addEventListener('input', function () {
			// Escrever nao mantem uma escolha anterior valida: o rotulo e o
			// UID tem de andar sempre juntos, senao gravava-se o nome de uma
			// pessoa com o identificador de outra.
			contactRef.value = '';

			const q = contactInput.value.trim();
			window.clearTimeout(timer);
			if (q.length < 2) {
				clearResults();
				return;
			}

			timer = window.setTimeout(function () {
				ocs(contactsUrl + '?format=json&q=' + encodeURIComponent(q))
					.then(function (data) {
						clearResults();
						(data.contacts || []).forEach(function (contact) {
							const item = document.createElement('li');
							item.textContent = contact.name;
							item.addEventListener('mousedown', function (event) {
								event.preventDefault();
								choose(contact);
							});
							contactList.appendChild(item);
						});
						contactList.hidden = contactList.childElementCount === 0;
					})
					.catch(clearResults);
			}, 250);
		});

		contactInput.addEventListener('blur', function () {
			window.setTimeout(clearResults, 150);
		});
	}
})();
