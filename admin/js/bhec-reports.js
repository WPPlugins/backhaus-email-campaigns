/**
 * Reports: draws each chart as SVG from the numbers in its data-chart
 * attribute, at the width it has, and shows a day's numbers on hover, touch
 * or the arrow keys. The same numbers are in the table under the charts.
 */
(function () {
	'use strict';

	var SVG = 'http://www.w3.org/2000/svg';
	var HEIGHT = 220;
	var TOP = 16;
	var BOTTOM = 26;
	var RIGHT = 6;
	var LABEL_WIDTH = 46;
	var numbers = new Intl.NumberFormat(document.documentElement.lang || undefined);

	function fmt(n) {
		return numbers.format(n);
	}

	function node(name, attrs, parent) {
		var el = document.createElementNS(SVG, name);
		Object.keys(attrs).forEach(function (k) {
			el.setAttribute(k, attrs[k]);
		});
		if (parent) {
			parent.appendChild(el);
		}
		return el;
	}

	function html(tag, className, text, parent) {
		var el = document.createElement(tag);
		if (className) {
			el.className = className;
		}
		if (text !== undefined) {
			el.textContent = text;
		}
		if (parent) {
			parent.appendChild(el);
		}
		return el;
	}

	// The top of the scale: the max rounded up to a 1, 2 or 5 step, in about
	// four whole-number steps.
	function scale(max) {
		if (max <= 0) {
			return { top: 4, step: 1 };
		}
		var raw = max / 4;
		var pow = Math.pow(10, Math.floor(Math.log10(raw)));
		var step = [1, 2, 5, 10].map(function (m) { return m * pow; }).filter(function (s) { return s >= raw; })[0];
		step = Math.max(1, step);
		return { top: Math.ceil(max / step) * step, step: step };
	}

	// A column with a rounded top, square at the baseline.
	function columnPath(x, y, w, h) {
		var r = Math.min(4, w / 2, h);
		return 'M' + x + ' ' + (y + h) +
			'V' + (y + r) +
			'A' + r + ' ' + r + ' 0 0 1 ' + (x + r) + ' ' + y +
			'H' + (x + w - r) +
			'A' + r + ' ' + r + ' 0 0 1 ' + (x + w) + ' ' + (y + r) +
			'V' + (y + h) + 'Z';
	}

	function draw(box) {
		var data = box.bhecData;
		var width = box.clientWidth;
		if (!width) {
			return;
		}
		box.bhecWidth = width;
		box.textContent = '';

		var n = data.labels.length;
		var max = 0;
		data.series.forEach(function (s) {
			s.values.forEach(function (v) {
				if (v !== null && v > max) {
					max = v;
				}
			});
		});
		var sc = scale(max);
		var left = fmt(sc.top).length * 7 + 12;
		var plotW = Math.max(40, width - left - RIGHT);
		var plotH = HEIGHT - TOP - BOTTOM;
		var band = plotW / n;
		var base = TOP + plotH;
		var x = function (i) { return left + band * (i + 0.5); };
		var y = function (v) { return base - (v / sc.top) * plotH; };

		var svg = node('svg', { width: width, height: HEIGHT, 'aria-hidden': 'true', focusable: 'false' }, box);

		for (var v = 0; v <= sc.top; v += sc.step) {
			var gy = Math.round(y(v)) + 0.5;
			node('line', { x1: left, x2: width - RIGHT, y1: gy, y2: gy, 'class': v === 0 ? 'bhec-chart-base' : 'bhec-chart-grid' }, svg);
			node('text', { x: left - 8, y: gy + 4, 'text-anchor': 'end', 'class': 'bhec-chart-tick' }, svg).textContent = fmt(v);
		}

		// Day labels from the newest backwards, as many as fit.
		var every = Math.max(1, Math.ceil(LABEL_WIDTH / band));
		for (var i = n - 1; i >= 0; i -= every) {
			var lx = x(i);
			var anchor = 'middle';
			if (lx - LABEL_WIDTH / 2 < left - 8) {
				anchor = 'start';
				lx = Math.max(lx - band / 2, left - 8);
			} else if (lx + LABEL_WIDTH / 2 > width) {
				anchor = 'end';
				lx = width;
			}
			node('text', { x: lx, y: HEIGHT - 8, 'text-anchor': anchor, 'class': 'bhec-chart-label' }, svg).textContent = data.labels[i];
		}

		var bars = [];
		var dots = [];
		var cross = null;

		if (data.type === 'columns') {
			var s = data.series[0];
			var w = Math.max(1, Math.min(24, band - 2));
			var peak = -1;
			s.values.forEach(function (val, i) {
				if (!val) {
					return;
				}
				var h = Math.max(1, (val / sc.top) * plotH);
				bars[i] = node('path', { d: columnPath(x(i) - w / 2, base - h, w, h), 'class': 'bhec-chart-bar bhec-series-' + s.key }, svg);
				if (peak < 0 || val > s.values[peak]) {
					peak = i;
				}
			});
			// Label the busiest day; the tooltip and table carry the rest.
			if (peak >= 0) {
				node('text', { x: x(peak), y: y(s.values[peak]) - 6, 'text-anchor': 'middle', 'class': 'bhec-chart-value' }, svg).textContent = fmt(s.values[peak]);
			}
		} else {
			cross = node('line', { y1: TOP, y2: base, 'class': 'bhec-chart-cross', visibility: 'hidden' }, svg);
			data.series.forEach(function (s) {
				var d = '';
				var pen = false;
				s.values.forEach(function (val, i) {
					if (val === null) {
						pen = false;
						return;
					}
					d += (pen ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(val).toFixed(1);
					pen = true;
				});
				node('path', { d: d, 'class': 'bhec-chart-line bhec-series-' + s.key }, svg);
				// Few points: mark each one.
				if (n <= 14) {
					s.values.forEach(function (val, i) {
						if (val !== null) {
							node('circle', { cx: x(i), cy: y(val), r: 4, 'class': 'bhec-chart-dot bhec-series-' + s.key }, svg);
						}
					});
				}
			});
			data.series.forEach(function (s) {
				dots.push(node('circle', { r: 4, 'class': 'bhec-chart-dot bhec-series-' + s.key, visibility: 'hidden' }, svg));
			});
		}

		var tip = html('div', 'bhec-chart-tip', undefined, box);
		tip.hidden = true;

		box.bhecShow = function (i) {
			i = Math.max(0, Math.min(n - 1, i));
			box.bhecIndex = i;
			bars.forEach(function (bar, j) {
				if (bar) {
					bar.classList.toggle('is-active', j === i);
				}
			});
			if (cross) {
				var cx = Math.round(x(i)) + 0.5;
				cross.setAttribute('x1', cx);
				cross.setAttribute('x2', cx);
				cross.setAttribute('visibility', 'visible');
				data.series.forEach(function (s, k) {
					var val = s.values[i];
					dots[k].setAttribute('visibility', val === null ? 'hidden' : 'visible');
					if (val !== null) {
						dots[k].setAttribute('cx', x(i));
						dots[k].setAttribute('cy', y(val));
					}
				});
			}

			tip.textContent = '';
			html('div', 'bhec-chart-tip-date', data.titles[i], tip);
			data.series.forEach(function (s) {
				if (s.values[i] === null) {
					return;
				}
				var row = html('div', 'bhec-chart-tip-row', undefined, tip);
				html('span', 'bhec-chart-key bhec-series-' + s.key, undefined, row);
				html('strong', '', fmt(s.values[i]), row);
				html('span', '', s.label, row);
			});
			tip.hidden = false;

			// Beside the day, on whichever side has room.
			var tw = tip.offsetWidth;
			var tx = x(i) + band / 2 + 8;
			if (tx + tw > width) {
				tx = Math.max(0, x(i) - band / 2 - 8 - tw);
			}
			tip.style.left = tx + 'px';
			tip.style.top = TOP + 'px';
		};

		box.bhecHide = function () {
			tip.hidden = true;
			bars.forEach(function (bar) {
				if (bar) {
					bar.classList.remove('is-active');
				}
			});
			if (cross) {
				cross.setAttribute('visibility', 'hidden');
				dots.forEach(function (dot) { dot.setAttribute('visibility', 'hidden'); });
			}
		};

		box.bhecPoint = function (clientX) {
			var i = Math.floor((clientX - svg.getBoundingClientRect().left - left) / band);
			if (i < 0 || i >= n) {
				box.bhecHide();
			} else {
				box.bhecShow(i);
			}
		};

		if (document.activeElement === box && box.bhecIndex !== undefined) {
			box.bhecShow(box.bhecIndex);
		}
	}

	// The last day that has a number (today, or the last day of a campaign).
	function lastIndex(data) {
		var values = data.series[0].values;
		for (var i = values.length - 1; i >= 0; i--) {
			if (values[i] !== null) {
				return i;
			}
		}
		return 0;
	}

	function init(box) {
		try {
			box.bhecData = JSON.parse(box.getAttribute('data-chart'));
		} catch (e) {
			return;
		}
		if (!box.bhecData.labels || !box.bhecData.labels.length) {
			return;
		}

		// Until the chart is drawn there is nothing to point at.
		var drawn = function () { return !!box.bhecShow; };

		box.addEventListener('pointermove', function (e) {
			if (drawn()) {
				box.bhecPoint(e.clientX);
			}
		});
		box.addEventListener('pointerdown', function (e) {
			if (drawn()) {
				box.bhecPoint(e.clientX);
			}
		});
		box.addEventListener('pointerleave', function () {
			if (drawn() && document.activeElement !== box) {
				box.bhecHide();
			}
		});
		box.addEventListener('focus', function () {
			if (drawn()) {
				box.bhecShow(box.bhecIndex !== undefined ? box.bhecIndex : lastIndex(box.bhecData));
			}
		});
		box.addEventListener('blur', function () {
			if (drawn()) {
				box.bhecHide();
			}
		});
		box.addEventListener('keydown', function (e) {
			if (!drawn()) {
				return;
			}
			var i = box.bhecIndex !== undefined ? box.bhecIndex : lastIndex(box.bhecData);
			var keys = {
				ArrowLeft: i - 1,
				ArrowRight: i + 1,
				Home: 0,
				End: lastIndex(box.bhecData)
			};
			if (keys[e.key] !== undefined) {
				e.preventDefault();
				box.bhecShow(keys[e.key]);
			} else if (e.key === 'Escape') {
				box.bhecHide();
			}
		});

		draw(box);

		if (window.ResizeObserver) {
			var frame = 0;
			new ResizeObserver(function () {
				cancelAnimationFrame(frame);
				frame = requestAnimationFrame(function () {
					if (box.clientWidth !== box.bhecWidth) {
						draw(box);
					}
				});
			}).observe(box);
		}
	}

	function start() {
		Array.prototype.forEach.call(document.querySelectorAll('.bhec-chart[data-chart]'), init);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
