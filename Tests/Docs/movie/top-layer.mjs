// TYPO3-specific helper for the movie kit (movie-kit.mjs stays as shared by all Supertext plugins).
/**
 * TYPO3's modals (the Localize wizard, the Extension Configuration) are dialogs in the browser's top layer, above any z-index: without this the
 * caption, cursor and click ripples would sit under its dimmed backdrop. Puts them into the top layer
 * too (as popovers, shown after the dialog, so above it); their look doesn't change.
 */
export const overlayToFront = (page) => page.evaluate(() => {
	// Popover defaults the overlay's own rules don't set (inset, border, padding, background) are reset.
	const reset = {
		'stk-cap': { top: 'auto', right: 'auto', border: '0' },
		'stk-cur': { right: 'auto', bottom: 'auto', padding: '0' },
		'stk-rip': { right: 'auto', bottom: 'auto', padding: '0', background: 'transparent' },
	};
	const front = (el, key) => {
		if (!el || typeof el.showPopover !== 'function') return;
		if (!el.popover) {
			el.popover = 'manual';
			Object.assign(el.style, reset[key]);
		}
		if (el.matches(':popover-open')) el.hidePopover();
		el.showPopover();
	};
	front(document.getElementById('stk-cap'), 'stk-cap');
	front(document.getElementById('stk-cur'), 'stk-cur');
	const stk = window.__stk;
	if (stk && !stk.frontRipple) {
		stk.frontRipple = true;
		const ripple = stk.ripple;
		stk.ripple = (x, y) => { ripple(x, y); document.querySelectorAll('.stk-rip').forEach((r) => front(r, 'stk-rip')); };
	}
});

