/**
 * Прокрутка к ошибке на странице оформления заказа (sale.order.ajax).
 *
 * Проблема: ошибка «иностранный домен» в e-mail приходит с сервера
 * (обработчик OnSaleOrderBeforeSaved / OnSaleComponentOrderProperties)
 * и выводится в блок ошибок, но страница к ней не прокручивается,
 * и покупатель думает, что кнопка «Оформить заказ» не работает.
 *
 * Решение: после ответа сервера и после показа ошибок ищем первую видимую
 * ошибку (лучше поле e-mail) и плавно прокручиваем к ней.
 */
(function () {
	'use strict';

	var ERROR_SELECTORS = [
		'.form-group.has-error',
		'.bx-soa-customer-field.has-error',
		'.has-error',
		'.alert-danger',
		'.bx-soa-section.bx-step-error',
		'.errortext',
		'[data-error-scroll]'
	];
	var HEADER_OFFSET = 100; // высота фиксированной шапки, подправить под сайт

	function isVisible(node) {
		return !!(node && (node.offsetWidth || node.offsetHeight || node.getClientRects().length))
			&& window.getComputedStyle(node).visibility !== 'hidden';
	}

	function findFirstError(root) {
		root = root || document.getElementById('bx-soa-order') || document;

		// Сначала поле e-mail, если оно помечено ошибкой
		var email = root.querySelector('input[type="email"], input[data-name="EMAIL"], input[name*="EMAIL"]');
		if (email) {
			var group = email.closest('.has-error, .form-group');
			if (group && /has-error/.test(group.className) && isVisible(group)) {
				return group;
			}
		}

		var best = null;
		for (var i = 0; i < ERROR_SELECTORS.length; i++) {
			var nodes = root.querySelectorAll(ERROR_SELECTORS[i]);
			for (var j = 0; j < nodes.length; j++) {
				if (!isVisible(nodes[j]) || !nodes[j].textContent.trim() && !nodes[j].querySelector('input')) {
					continue;
				}
				if (!best || nodes[j].getBoundingClientRect().top < best.getBoundingClientRect().top) {
					best = nodes[j];
				}
			}
		}
		return best;
	}

	function scrollToError() {
		var node = findFirstError();
		if (!node) {
			return false;
		}
		var top = node.getBoundingClientRect().top + window.pageYOffset - HEADER_OFFSET;
		window.scrollTo({top: Math.max(top, 0), behavior: 'smooth'});

		var input = node.matches('input, textarea, select') ? node : node.querySelector('input, textarea, select');
		if (input && typeof input.focus === 'function') {
			setTimeout(function () { input.focus({preventScroll: true}); }, 400);
		}
		return true;
	}

	// Шаблон перерисовывает блоки не сразу, поэтому пробуем несколько раз
	function scrollToErrorDeferred() {
		var attempts = 0;
		(function tryScroll() {
			if (!scrollToError() && ++attempts < 10) {
				setTimeout(tryScroll, 100);
			}
		})();
	}

	function patchComponent() {
		var C = window.BX && BX.Sale && BX.Sale.OrderAjaxComponent;
		if (!C || C.__errorScrollPatched) {
			return !!C;
		}
		C.__errorScrollPatched = true;

		// Ошибки, пришедшие с сервера после нажатия «Оформить заказ»
		var origShowErrors = C.showErrors;
		if (typeof origShowErrors === 'function') {
			C.showErrors = function (errors, scroll) {
				var res = origShowErrors.apply(this, arguments);
				if (scroll !== false && errors && Object.keys(errors).length) {
					scrollToErrorDeferred();
				}
				return res;
			};
		}

		// Ответ на saveOrderAjax: на случай, если ошибка выводится мимо showErrors
		var origSaveHandler = C.saveOrderWithJson || C.saveOrder;
		if (typeof origSaveHandler === 'function') {
			var name = C.saveOrderWithJson ? 'saveOrderWithJson' : 'saveOrder';
			C[name] = function (result) {
				var res = origSaveHandler.apply(this, arguments);
				var order = result && (result.order || result);
				if (order && order.ERROR && !order.REDIRECT_URL) {
					scrollToErrorDeferred();
				}
				return res;
			};
		}
		return true;
	}

	if (window.BX && BX.ready) {
		BX.ready(function () {
			if (!patchComponent()) {
				setTimeout(patchComponent, 500);
			}
		});
	}

	// Подстраховка для своей JS-проверки домена (которая не идёт через компонент):
	// после клика по кнопке оформления ждём появления ошибки и прокручиваем к ней.
	document.addEventListener('click', function (e) {
		var btn = e.target.closest && e.target.closest('[data-save-button], #bx-soa-orderSave a, .bx-soa-orderSave, button[name="save"]');
		if (btn) {
			setTimeout(scrollToErrorDeferred, 300);
		}
	}, true);

	window.orderScrollToError = scrollToError;
})();
