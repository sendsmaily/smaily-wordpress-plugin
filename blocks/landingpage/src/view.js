window.addEventListener('message', (ev) => {
	if (ev.data.source !== 'smaily') {
		return;
	}

	if (ev.data.status === 'landing-page-loaded') {
		const iframe = document.querySelector(
			'.smaily-wp-connect-landingpage-block-front'
		);
		if (iframe) {
			iframe.style.visibility = 'visible';
		}
	}
});
