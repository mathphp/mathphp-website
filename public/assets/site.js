(() => {
  const menus = [...document.querySelectorAll('.nav-menu')];
  const mobileToggle = document.querySelector('.mobile-menu-toggle');
  const primaryMenu = document.querySelector('#primary-menu');
  const menuBackdrop = document.querySelector('.menu-backdrop');
  const closeMobileMenu = () => {
    if (!mobileToggle || !primaryMenu) return;
    mobileToggle.setAttribute('aria-expanded', 'false');
    primaryMenu.classList.remove('mobile-open');
    menuBackdrop?.classList.remove('visible');
    document.body.classList.remove('menu-is-open');
  };
  mobileToggle?.addEventListener('click', () => {
    const expanded = mobileToggle.getAttribute('aria-expanded') === 'true';
    mobileToggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
    primaryMenu?.classList.toggle('mobile-open', !expanded);
    menuBackdrop?.classList.toggle('visible', !expanded);
    document.body.classList.toggle('menu-is-open', !expanded);
    if (!expanded) primaryMenu?.querySelector('a')?.focus();
  });
  menuBackdrop?.addEventListener('click', closeMobileMenu);
  primaryMenu?.querySelectorAll('a').forEach((link) => link.addEventListener('click', closeMobileMenu));
  menus.forEach((menu) => menu.addEventListener('toggle', () => {
    if (!menu.open) return;
    menus.filter((other) => other !== menu).forEach((other) => { other.open = false; });
  }));
  document.addEventListener('click', (event) => {
    if (!event.target.closest('.nav-menu')) menus.forEach((menu) => { menu.open = false; });
    if (!event.target.closest('.site-header')) closeMobileMenu();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') { menus.forEach((menu) => { menu.open = false; }); closeMobileMenu(); }
  });

  const root = document.querySelector('[data-playground]');
  if (!root) return;

  const expression = document.querySelector('#expression');
  const variables = document.querySelector('#variables');
  const engine = document.querySelector('#engine');
  const result = document.querySelector('#result');
  const button = document.querySelector('#evaluate');
  const explainButton = document.querySelector('#explain');
  const analyzeButton = document.querySelector('#analyze');
  const plotButton = document.querySelector('#plot');
  const systemButton = document.querySelector('#analyze-system');
  const system = document.querySelector('#system');
  const derivativeButton = document.querySelector('#derivative');
  const integralButton = document.querySelector('#integral');
  const areaButton = document.querySelector('#area');
  const rootButton = document.querySelector('#root-find');
  const matrixButton = document.querySelector('#analyze-matrix');
  const matrix = document.querySelector('#matrix');
  const equation = document.querySelector('#equation');
  const locale = document.querySelector('#locale');
  const resultHeading = document.querySelector('#result-heading');

  // Plot controls keep the browser path aligned with the renderer-neutral API.
  const plotButtonRow = plotButton?.closest('.action-row');
  if (plotButtonRow && !document.querySelector('.plot-options')) {
    const plotOptions = document.createElement('fieldset');
    plotOptions.className = 'plot-options';
    plotOptions.innerHTML = '<legend>Plot options <small>optional</small></legend><label for="plot-variable">Variable<input id="plot-variable" value="x" maxlength="32" pattern="[A-Za-z_][A-Za-z0-9_]*" autocomplete="off"></label><label for="plot-x-unit">X-axis unit<input id="plot-x-unit" maxlength="16" placeholder="s" autocomplete="off"></label><label for="plot-y-unit">Y-axis unit<input id="plot-y-unit" maxlength="16" placeholder="m" autocomplete="off"></label>';
    plotButtonRow.insertAdjacentElement('afterend', plotOptions);
  }
  const plotVariable = document.querySelector('#plot-variable');
  const plotXUnit = document.querySelector('#plot-x-unit');
  const plotYUnit = document.querySelector('#plot-y-unit');

  const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[character]));

  const visualDetails = (visual, summary = 'Visual representation') => {
    if (!visual) return '<p class="visual-unavailable">This analysis has no visual model for the supplied input.</p>';
    return `<details class="visual-details" open><summary>${escapeHtml(summary)}</summary><p>${escapeHtml(visual.description)}</p><div class="visual-preview">${visual.svg}</div></details>`;
  };

  const showResult = (html, className) => {
    result.className = className;
    result.innerHTML = html;
  };

  let requestSerial = 0;
  const beginRequest = () => { requestSerial += 1; return requestSerial; };
  const isCurrentRequest = (serial) => serial === requestSerial;

  const setEngineMeta = (value) => {
    const engineMeta = document.querySelector('#result-engine');
    if (engineMeta) engineMeta.textContent = value;
  };

  // Keep Auto mode conservative: scientific notation is a scalar, not a unit
  // token (for example, the `e3` suffix in `2e3`). Explicit conversions still
  // opt into Units, and scientific notation followed by a real unit remains
  // supported when the unit is separated from the exponent (`2e3 m`).
  const looksLikeUnits = (value) => {
    if (/\bto\s+[A-Za-z][A-Za-z0-9_°-]*/i.test(value)) return true;
    if (/(?:\d+(?:\.\d+)?|\.\d+)[eE][+-]?\d+\s+[A-Za-z][A-Za-z0-9_°-]*/.test(value)) return true;
    return /(?:^|[^\w.])(?:\d+(?:\.\d+)?|\.\d+)[ \t]*([A-Za-z°][A-Za-z0-9_°-]*)/i.test(value)
      && !/(?:^|[^\w.])(?:\d+(?:\.\d+)?|\.\d+)[ \t]*[eE][+-]?\d+(?:$|[^A-Za-z0-9_°-])/i.test(value);
  };

  const selectedEngine = () => {
    const selected = engine?.value || 'auto';
    return selected === 'auto' ? (looksLikeUnits(expression.value) ? 'units' : 'core') : selected;
  };

  const readVariables = () => {
    try {
      const value = JSON.parse(variables.value || '{}');
      if (!value || Array.isArray(value) || typeof value !== 'object') throw new Error('not an object');
      const invalid = Object.entries(value).some(([name, item]) => name.trim() === '' || (typeof item !== 'number') || !Number.isFinite(item));
      if (invalid) throw new Error('not numeric');
      return { ok: true, value };
    } catch {
      showResult('<strong>Variables must be a JSON object of finite numbers.</strong><span>Use an object such as {"subtotal": 42.5}.</span>', 'result-error');
      return { ok: false };
    }
  };

  const run = async () => {
    const serial = beginRequest();
    button.disabled = true;
    button.style.opacity = '.65';
    const parsed = readVariables();
    if (!parsed.ok) { button.disabled = false; button.style.opacity = '1'; return; }

    try {
      const activeEngine = selectedEngine();
      const endpoint = activeEngine === 'units' ? '?api=units' : '?api=evaluate';
      const response = await fetch(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ expression: expression.value, variables: parsed.value }) });
      const data = await response.json();
      if (!isCurrentRequest(serial)) return;
      if (data.ok) {
        if (activeEngine === 'units') {
          const quantity = data.quantity;
          const dimensions = Object.entries(quantity.dimensions || {}).map(([name, power]) => `${name}${power === 1 ? '' : `^${power}`}`).join(' · ') || 'dimensionless';
          resultHeading.textContent = 'Units result';
          showResult(`<span class="success-value">${escapeHtml(quantity.formatted)}</span><span class="success-type">${escapeHtml(quantity.unit || 'scalar')} · ${escapeHtml(dimensions)} · normalized</span>`, 'result-success');
        } else {
          resultHeading.textContent = 'Result';
          showResult(`<span class="success-value">${escapeHtml(data.display)}</span><span class="success-type">${escapeHtml(data.type)} · deterministic result</span>`, 'result-success');
        }
        setEngineMeta(activeEngine === 'units' ? 'MathPHP Units add-on' : 'MathPHP Core');
      } else {
        showResult(`<strong>${escapeHtml(data.code)}</strong><p>${escapeHtml(data.message)}</p><code>source span: ${data.span[0]}–${data.span[1]}</code>`, 'result-error');
      }
    } catch {
      if (!isCurrentRequest(serial)) return;
      showResult('<strong>Could not reach the evaluator.</strong><span>Check that the local PHP server is running.</span>', 'result-error');
    } finally {
      button.disabled = false;
      button.style.opacity = '1';
    }
  };

  const explain = async () => {
    const serial = beginRequest();
    explainButton.disabled = true;
    explainButton.style.opacity = '.65';
    const parsed = readVariables();
    if (!parsed.ok) { explainButton.disabled = false; explainButton.style.opacity = '1'; return; }

    try {
      const activeEngine = selectedEngine();
      const endpoint = activeEngine === 'units' ? '?api=unit-explain' : '?api=explain';
      const response = await fetch(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ expression: expression.value, variables: parsed.value, locale: locale.value }) });
      const data = await response.json();
      if (!isCurrentRequest(serial)) return;
      if (!data.ok) {
        showResult(`<strong>${escapeHtml(data.code)}</strong><p>${escapeHtml(data.message)}</p>`, 'result-error');
        return;
      }
      if (activeEngine === 'units') {
        const explanation = data.unitExplanation;
        const steps = explanation.steps.map((step) => `<li><span class="step-index" aria-hidden="true">${step.id}</span><div class="step-copy"><code>${escapeHtml(step.expression)}</code><strong>${escapeHtml(step.message)}</strong><span class="step-detail">${escapeHtml(step.detail)}</span></div><strong class="step-result">${escapeHtml(step.result.formatted)}</strong></li>`).join('');
        resultHeading.textContent = 'Unit steps';
        showResult(`<div class="explanation-result"><div class="explanation-summary"><span class="result-symbol">↗</span><div><span class="explanation-label">${escapeHtml(explanation.locale)} unit explanation</span><strong>${escapeHtml(explanation.result.formatted)}</strong><span class="explanation-hint">Each card shows the operation, conversion, and resulting quantity.</span></div></div><ol class="step-list" aria-label="Unit calculation steps">${steps}</ol></div>`, 'result-explanation');
        setEngineMeta('MathPHP Explaining + Units');
        return;
      }
      resultHeading.textContent = 'Step-by-step';
      const steps = data.explanation.steps.map((step) => `<li><span class="step-index" aria-hidden="true">${step.id}</span><div class="step-copy"><code>${escapeHtml(step.expression)}</code><strong>${escapeHtml(step.message)}</strong><span class="step-detail">${escapeHtml(step.detail)}</span></div><strong class="step-result">${escapeHtml(step.result)}</strong></li>`).join('');
      const visual = data.explanation.visual;
      const visualMarkup = visualDetails(visual);
      showResult(`<div class="explanation-result"><div class="explanation-summary"><span class="result-symbol">✦</span><div><span class="explanation-label">${escapeHtml(data.explanation.locale)} explanation</span><strong>${escapeHtml(data.explanation.result)}</strong><span class="explanation-hint">Each card shows the rule, the substitution, and the result.</span></div></div><ol class="step-list" aria-label="Calculation steps">${steps}</ol>${visualMarkup}</div>`, 'result-explanation');
      setEngineMeta('MathPHP Explaining');
    } catch {
      if (!isCurrentRequest(serial)) return;
      showResult('<strong>Could not reach the explanation service.</strong><span>Check that the private explaining package is installed.</span>', 'result-error');
    } finally {
      explainButton.disabled = false;
      explainButton.style.opacity = '1';
    }
  };

  const analyze = async () => {
    const serial = beginRequest();
    analyzeButton.disabled = true;
    try {
      const parsed = readVariables();
      if (!parsed.ok) return;
      const response = await fetch('?api=analyze', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ equation: equation.value, known: parsed.value }) });
      const data = await response.json();
      if (!isCurrentRequest(serial)) return;
      if (!data.ok) { showResult(`<strong>${escapeHtml(data.code)}</strong><p>${escapeHtml(data.message)}</p>`, 'result-error'); return; }
      const analysis = data.analysis;
      const steps = analysis.steps.map((step) => `<li>${escapeHtml(step)}</li>`).join('');
      const solution = Object.entries(analysis.solutions).map(([key, value]) => `<strong>${escapeHtml(key)} = ${escapeHtml(value)}</strong>`).join(' ');
      showResult(`<div class="explanation-result"><div class="explanation-summary"><span class="result-symbol">≈</span><div><span class="explanation-label">${escapeHtml(analysis.status)}</span><strong>${solution || 'No unique value yet'}</strong><span class="explanation-hint">${escapeHtml(analysis.summary)}</span></div></div><ol class="step-list" aria-label="Equation analysis">${steps}</ol>${visualDetails(analysis.visual)}</div>`, 'result-explanation');
      setEngineMeta('MathPHP Explaining · Equations');
    } catch { if (isCurrentRequest(serial)) showResult('<strong>Could not reach the equation analyzer.</strong>', 'result-error'); }
    finally { analyzeButton.disabled = false; }
  };

  const plot = async () => {
    const serial = beginRequest();
    plotButton.disabled = true;
    try {
      const parsed = readVariables();
      if (!parsed.ok) return;
      const variable = plotVariable?.value.trim() || 'x';
      if (!/^[A-Za-z_][A-Za-z0-9_]*$/.test(variable)) {
        showResult('<strong>Plot variable must be a valid identifier.</strong><span>Use a name such as x or t.</span>', 'result-error');
        return;
      }
      const response = await fetch('?api=plot', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ expression: expression.value, variable, minimum: -10, maximum: 10, samples: 101, xUnit: plotXUnit?.value.trim() || '', yUnit: plotYUnit?.value.trim() || '', variables: parsed.value }) });
      const data = await response.json();
      if (!isCurrentRequest(serial)) return;
      if (!data.ok) { showResult(`<strong>${escapeHtml(data.code)}</strong><p>${escapeHtml(data.message)}</p>`, 'result-error'); return; }
      const visual = data.visual;
      showResult(`<div class="explanation-result"><div class="explanation-summary"><span class="result-symbol">⌁</span><div><span class="explanation-label">function plot</span><strong>${escapeHtml(visual.title)}</strong><span class="explanation-hint">${escapeHtml(visual.description)}</span></div></div><div class="visual-preview">${visual.svg}</div></div>`, 'result-explanation');
      setEngineMeta('MathPHP Visuals add-on');
    } catch { if (isCurrentRequest(serial)) showResult('<strong>Could not reach the plotting service.</strong>', 'result-error'); }
    finally { plotButton.disabled = false; }
  };

  const analyzeSystem = async () => {
    const serial = beginRequest();
    systemButton.disabled = true;
    try {
      const response = await fetch('?api=system', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ system: system.value }) });
      const data = await response.json();
      if (!isCurrentRequest(serial)) return;
      if (!data.ok) { showResult(`<strong>${escapeHtml(data.code)}</strong><p>${escapeHtml(data.message)}</p>`, 'result-error'); return; }
      const analysis = data.analysis;
      const solution = Object.entries(analysis.solutions).map(([key, value]) => `<strong>${escapeHtml(key)} = ${escapeHtml(value)}</strong>`).join(' ');
      const visual = analysis.visual;
      showResult(`<div class="explanation-result"><div class="explanation-summary"><span class="result-symbol">▦</span><div><span class="explanation-label">${escapeHtml(analysis.status)}</span><strong>${solution || 'No unique solution yet'}</strong><span class="explanation-hint">${escapeHtml(analysis.summary)}</span></div></div><ol class="step-list" aria-label="System analysis">${analysis.steps.map((step) => `<li>${escapeHtml(step)}</li>`).join('')}</ol>${visualDetails(visual, 'Matrix representation')}</div>`, 'result-explanation');
      setEngineMeta('MathPHP Explaining · Systems');
    } catch { if (isCurrentRequest(serial)) showResult('<strong>Could not reach the system analyzer.</strong>', 'result-error'); }
    finally { systemButton.disabled = false; }
  };

  const calculus = async (operation) => {
    const serial = beginRequest();
    const control = operation === 'integral' ? integralButton : derivativeButton;
    control.disabled = true;
    try {
      const response = await fetch('?api=calculus', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ operation, expression: expression.value, variable: 'x' }) });
      const data = await response.json();
      if (!isCurrentRequest(serial)) return;
      if (!data.ok) { showResult(`<strong>${escapeHtml(data.code)}</strong><p>${escapeHtml(data.message)}</p>`, 'result-error'); return; }
      const analysis = data.analysis;
      showResult(`<div class="explanation-result"><div class="explanation-summary"><span class="result-symbol">${operation === 'integral' ? '∫' : '′'}</span><div><span class="explanation-label">${escapeHtml(analysis.operation)}</span><strong>${escapeHtml(analysis.result)}</strong><span class="explanation-hint">${escapeHtml(analysis.status)}</span></div></div><ol class="step-list" aria-label="Calculus steps">${analysis.steps.map((step) => `<li>${escapeHtml(step)}</li>`).join('')}</ol>${visualDetails(analysis.visual, 'Calculus visual')}</div>`, 'result-explanation');
      setEngineMeta('MathPHP Explaining · Calculus');
    } catch { if (isCurrentRequest(serial)) showResult('<strong>Could not reach the calculus analyzer.</strong>', 'result-error'); }
    finally { control.disabled = false; }
  };

  const area = async () => {
    const serial = beginRequest();
    areaButton.disabled = true;
    try {
      const response = await fetch('?api=area', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ expression: expression.value, variable: 'x', minimum: 0, maximum: 1, samples: 101 }) });
      const data = await response.json();
      if (!isCurrentRequest(serial)) return;
      if (!data.ok) { showResult(`<strong>${escapeHtml(data.code)}</strong><p>${escapeHtml(data.message)}</p>`, 'result-error'); return; }
      const analysis = data.analysis;
      showResult(`<div class="explanation-result"><div class="explanation-summary"><span class="result-symbol">∫</span><div><span class="explanation-label">signed area · ${escapeHtml(analysis.status)}</span><strong>${escapeHtml(analysis.area)}</strong><span class="explanation-hint">${escapeHtml(analysis.expression)} from ${escapeHtml(analysis.domain[0])} to ${escapeHtml(analysis.domain[1])}</span></div></div><ol class="step-list" aria-label="Area steps">${analysis.steps.map((step) => `<li>${escapeHtml(step)}</li>`).join('')}</ol>${visualDetails(analysis.visual, 'Area visual')}</div>`, 'result-explanation');
      setEngineMeta('MathPHP Explaining · Area');
    } catch { if (isCurrentRequest(serial)) showResult('<strong>Could not reach the area analyzer.</strong>', 'result-error'); }
    finally { areaButton.disabled = false; }
  };

  const findRoot = async () => {
    const serial = beginRequest();
    rootButton.disabled = true;
    try {
      const response = await fetch('?api=root', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ expression: expression.value, variable: 'x', minimum: 0, maximum: 2, iterations: 40 }) });
      const data = await response.json();
      if (!isCurrentRequest(serial)) return;
      if (!data.ok) { showResult(`<strong>${escapeHtml(data.code)}</strong><p>${escapeHtml(data.message)}</p>`, 'result-error'); return; }
      const analysis = data.analysis;
      showResult(`<div class="explanation-result"><div class="explanation-summary"><span class="result-symbol">≈</span><div><span class="explanation-label">root · ${escapeHtml(analysis.status)}</span><strong>${escapeHtml(analysis.root ?? 'No certified root')}</strong><span class="explanation-hint">Bisection on [${escapeHtml(analysis.domain[0])}, ${escapeHtml(analysis.domain[1])}]</span></div></div><ol class="step-list" aria-label="Root steps">${analysis.steps.map((step) => `<li>${escapeHtml(step)}</li>`).join('')}</ol>${visualDetails(analysis.visual, 'Convergence visual')}</div>`, 'result-explanation');
      setEngineMeta('MathPHP Explaining · Root');
    } catch { if (isCurrentRequest(serial)) showResult('<strong>Could not reach the root analyzer.</strong>', 'result-error'); }
    finally { rootButton.disabled = false; }
  };

  const analyzeMatrix = async () => {
    const serial = beginRequest();
    matrixButton.disabled = true;
    try {
      let value;
      try { value = JSON.parse(matrix.value); } catch { showResult('<strong>Matrix must be valid JSON.</strong>', 'result-error'); return; }
      const response = await fetch('?api=matrix', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ matrix: value }) });
      const data = await response.json();
      if (!isCurrentRequest(serial)) return;
      if (!data.ok) { showResult(`<strong>${escapeHtml(data.code)}</strong><p>${escapeHtml(data.message)}</p>`, 'result-error'); return; }
      const analysis = data.analysis;
      const values = Object.entries(analysis.result).map(([key, value]) => `<strong>${escapeHtml(key)} = ${escapeHtml(JSON.stringify(value))}</strong>`).join(' ');
      showResult(`<div class="explanation-result"><div class="explanation-summary"><span class="result-symbol">▦</span><div><span class="explanation-label">matrix · ${escapeHtml(analysis.status)}</span><strong>${values}</strong></div></div><ol class="step-list" aria-label="Matrix steps">${analysis.steps.map((step) => `<li>${escapeHtml(step)}</li>`).join('')}</ol>${visualDetails(analysis.visual, 'Matrix visual')}</div>`, 'result-explanation');
      setEngineMeta('MathPHP Explaining · Matrix');
    } catch { if (isCurrentRequest(serial)) showResult('<strong>Could not reach the matrix analyzer.</strong>', 'result-error'); }
    finally { matrixButton.disabled = false; }
  };

  button.addEventListener('click', run);
  explainButton.addEventListener('click', explain);
  analyzeButton.addEventListener('click', analyze);
  plotButton.addEventListener('click', plot);
  systemButton.addEventListener('click', analyzeSystem);
  derivativeButton.addEventListener('click', () => calculus('derivative'));
  integralButton.addEventListener('click', () => calculus('integral'));
  areaButton.addEventListener('click', area);
  rootButton.addEventListener('click', findRoot);
  matrixButton.addEventListener('click', analyzeMatrix);
  root.querySelectorAll('[data-example]').forEach((example) => example.addEventListener('click', () => { expression.value = example.dataset.example; run(); }));
  expression.addEventListener('keydown', (event) => { if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') run(); });
})();
