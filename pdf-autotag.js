(function() {
	if(window.pdfAutoTagBound) return;
	window.pdfAutoTagBound = true;
	document.addEventListener('change', function(event) {
		var input = event.target;
		if(!input.classList || !input.classList.contains('pdf-autotag-request')) return;
		var wrap = input.closest ? input.closest('.pdf-autotag') : null;
		if(!wrap) return;
		var report = wrap.querySelector('.pdf-autotag-report');
		if(!report) return;
		report.style.display = input.checked ? 'block' : 'none';
		if(!input.checked) {
			var box = report.querySelector('input');
			if(box) box.checked = false;
		}
	});
})();
