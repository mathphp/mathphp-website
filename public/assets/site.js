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
  const engineHint = document.querySelector('#engine-hint');
  const result = document.querySelector('#result');
  const button = document.querySelector('#evaluate');
  const explainButton = document.querySelector('#explain');
  const piecewiseButton = document.querySelector('#piecewise');
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

  // General equation solving is opt-in and bounded to a finite interval. Add
  // the controls here so older cached markup remains compatible.
  let numericalEquationButton = document.querySelector('#solve-equation');
  let numericalVariable = document.querySelector('#equation-variable');
  let numericalMinimum = document.querySelector('#equation-minimum');
  let numericalMaximum = document.querySelector('#equation-maximum');
  let numericalSamples = document.querySelector('#equation-samples');
  let piecewiseEquationButton = document.querySelector('#analyze-piecewise');
  let recurrenceButton = document.querySelector('#analyze-recurrence');
  let limitButton = document.querySelector('#estimate-limit');
  let limitPoint = document.querySelector('#limit-point');
  let limitDirection = document.querySelector('#limit-direction');
  if (equation && !numericalEquationButton) {
    const tool = equation.closest('.equation-tool');
    const options = document.createElement('div');
    options.className = 'equation-numerical-options';
    options.innerHTML = '<label for="equation-variable">Variable<input id="equation-variable" value="x" maxlength="32" pattern="[A-Za-z_][A-Za-z0-9_]*" autocomplete="off"></label><label for="equation-minimum">From<input id="equation-minimum" type="number" value="-10" step="any"></label><label for="equation-maximum">To<input id="equation-maximum" type="number" value="10" step="any"></label><label for="equation-samples">Samples<input id="equation-samples" type="number" value="256" min="8" max="4096" step="1"></label>';
    tool?.append(options);
    numericalEquationButton = document.createElement('button');
    numericalEquationButton.type = 'button';
    numericalEquationButton.className = 'button button-secondary';
    numericalEquationButton.id = 'solve-equation';
    numericalEquationButton.innerHTML = 'Find all roots in interval <span>≈</span>';
    tool?.append(numericalEquationButton);
    numericalVariable = options.querySelector('#equation-variable');
    numericalMinimum = options.querySelector('#equation-minimum');
    numericalMaximum = options.querySelector('#equation-maximum');
    numericalSamples = options.querySelector('#equation-samples');
  }
  if (equation && !piecewiseEquationButton) {
    piecewiseEquationButton = document.createElement('button');
    piecewiseEquationButton.type = 'button';
    piecewiseEquationButton.className = 'button button-secondary';
    piecewiseEquationButton.id = 'analyze-piecewise';
    piecewiseEquationButton.innerHTML = 'Solve piecewise equation <span>≈</span>';
    equation.closest('.equation-tool')?.append(piecewiseEquationButton);
  }
  let recurrenceInput = document.querySelector('#recurrence');
  let recurrenceInitial = document.querySelector('#recurrence-initial');
  let recurrenceTerms = document.querySelector('#recurrence-terms');
  if (equation && !recurrenceButton) {
    const tool = document.createElement('div');
    tool.className = 'equation-tool recurrence-tool';
    tool.innerHTML = '<div class="panel-heading"><span>Recurrence expansion</span><span class="panel-hint">finite sequence</span></div><input id="recurrence" value="a(n+1) = 2*a(n) + 1" aria-label="Recurrence"><label>Initial values <small>JSON object keyed by n</small><textarea id="recurrence-initial" spellcheck="false">{"0":1}</textarea></label><label>Terms<input id="recurrence-terms" type="number" value="8" min="1" max="10000" step="1"></label><button class="button button-secondary" id="analyze-recurrence">Expand recurrence <span>→</span></button>';
    equation.closest('.editor-panel')?.append(tool);
    recurrenceButton = tool.querySelector('#analyze-recurrence');
    recurrenceInput = tool.querySelector('#recurrence');
    recurrenceInitial = tool.querySelector('#recurrence-initial');
    recurrenceTerms = tool.querySelector('#recurrence-terms');
  }
  if (expression && !limitButton) {
    const row = document.querySelector('.calculus-actions');
    const options = document.createElement('fieldset');
    options.className = 'limit-options';
    options.innerHTML = '<legend>Limit options <small>numerical estimate</small></legend><label for="limit-point">As variable approaches<input id="limit-point" type="number" value="0" step="any"></label><label for="limit-direction">Direction<select id="limit-direction"><option value="both">Both sides</option><option value="left">From the left</option><option value="right">From the right</option></select></label>';
    row?.insertAdjacentElement('afterend', options);
    limitButton = document.createElement('button');
    limitButton.type = 'button';
    limitButton.className = 'button button-secondary';
    limitButton.id = 'estimate-limit';
    limitButton.innerHTML = 'Estimate limit <span>→</span>';
    row?.append(limitButton);
    limitPoint = options.querySelector('#limit-point');
    limitDirection = options.querySelector('#limit-direction');
  }

  // Discover the runtime before enabling optional controls. This keeps a
  // core-only deployment honest: users can still read the playground, but
  // unavailable add-on actions are visibly disabled instead of failing only
  // after a click.
  const capabilityAvailability = new Map();
  let capabilitiesReady = false;
  let capabilityDiscoveryFailed = false;
  const capabilityAvailable = (id) => id === 'evaluate'
    ? true
    : capabilitiesReady && capabilityAvailability.get(id) === true;
  const capabilityLabel = (id) => ({
    explain: 'the Explaining add-on',
    piecewise: 'the Explaining add-on',
    'piecewise-equation': 'the Explaining add-on',
    recurrence: 'the Explaining add-on',
    limit: 'the Explaining add-on',
    'unit-explain': 'Explaining + Units add-ons',
    equation: 'the Explaining add-on',
    'numerical-equation': 'the Explaining add-on',
    'numerical-parabolic-pde-2d': 'the Explaining add-on',
    system: 'the Explaining add-on',
    matrix: 'the Explaining add-on',
    calculus: 'the Explaining add-on',
    area: 'the Explaining add-on',
    root: 'the Explaining add-on',
    statistics: 'the Explaining add-on',
    plot: 'the Visuals add-on',
    units: 'the Units add-on',
  }[id] || 'the selected package');
  const setControlAvailability = (control, id) => {
    if (!control) return;
    const available = capabilityAvailable(id);
    control.dataset.capability = id;
    control.disabled = !available;
    control.setAttribute('aria-disabled', String(!available));
    control.title = available
      ? ''
      : (!capabilitiesReady
        ? 'Checking installed add-ons…'
        : (capabilityDiscoveryFailed
          ? 'Add-on status is unavailable. Refresh to retry.'
          : `Unavailable: install ${capabilityLabel(id)}.`));
  };
  const refreshCapabilityControls = () => {
    if (engine) {
      [...engine.options].forEach((option) => {
        const capability = option.value === 'core' ? 'evaluate' : option.value;
        option.disabled = capability !== 'auto' && !capabilityAvailable(capability);
      });
    }
    const activeEngine = selectedEngine();
    if (engineHint) {
      if (!capabilitiesReady) {
        engineHint.textContent = 'Checking installed add-ons…';
      } else if (capabilityDiscoveryFailed) {
        engineHint.textContent = 'Add-on status unavailable · refresh to retry';
      } else {
        const label = activeEngine === 'units' ? 'Units' : 'Core';
        const capability = activeEngine === 'units' ? 'units' : 'evaluate';
        const available = capabilityAvailable(capability);
        const mode = engine?.value === 'auto' ? `Auto → ${label}` : `${label} selected`;
        engineHint.textContent = available ? mode : `${mode} unavailable`;
      }
    }
    setControlAvailability(button, activeEngine === 'units' ? 'units' : 'evaluate');
    setControlAvailability(explainButton, activeEngine === 'units' ? 'unit-explain' : 'explain');
    setControlAvailability(piecewiseButton, 'piecewise');
    setControlAvailability(analyzeButton, 'equation');
    setControlAvailability(piecewiseEquationButton, 'piecewise-equation');
    setControlAvailability(recurrenceButton, 'recurrence');
    setControlAvailability(limitButton, 'limit');
    setControlAvailability(numericalEquationButton, 'numerical-equation');
    setControlAvailability(plotButton, 'plot');
    setControlAvailability(systemButton, 'system');
    setControlAvailability(derivativeButton, 'calculus');
    setControlAvailability(integralButton, 'calculus');
    setControlAvailability(areaButton, 'area');
    setControlAvailability(rootButton, 'root');
    setControlAvailability(matrixButton, 'matrix');
  };

  const loadCapabilities = async () => {
    try {
      const response = await fetch('?api=capabilities', { headers: { Accept: 'application/json' } });
      if (!response.ok) throw new Error(`capabilities returned ${response.status}`);
      const data = await response.json();
      if (!Array.isArray(data.capabilities)) throw new Error('capabilities payload is invalid');
      data.capabilities.forEach((capability) => {
        if (typeof capability.id === 'string' && typeof capability.available === 'boolean') {
          capabilityAvailability.set(capability.id, capability.available);
        }
      });
      capabilitiesReady = true;
      refreshCapabilityControls();
    } catch {
      capabilitiesReady = true;
      capabilityDiscoveryFailed = true;
      refreshCapabilityControls();
    }
  };

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

  // Visuals are returned as SVG markup by our own renderer, but keep this
  // boundary defensive so a future renderer or dependency cannot introduce
  // scripts, event handlers, navigation, or embedded documents into results.
  const sanitizeSvg = (value) => {
    const template = document.createElement('template');
    template.innerHTML = String(value ?? '');
    const svg = template.content.querySelector('svg');
    if (!svg) return '';
    const blockedTags = new Set(['script', 'foreignobject', 'iframe', 'object', 'embed', 'style', 'link']);
    [svg, ...svg.querySelectorAll('*')].forEach((element) => {
      if (blockedTags.has(element.tagName.toLowerCase())) {
        element.remove();
        return;
      }
      [...element.attributes].forEach((attribute) => {
        const name = attribute.name.toLowerCase();
        const valueText = attribute.value.trim().toLowerCase();
        if (name.startsWith('on') || name === 'style' || name === 'src' || name === 'href' || name === 'xlink:href' || valueText.includes('javascript:') || valueText.includes('data:text/html')) {
          element.removeAttribute(attribute.name);
        }
      });
    });
    return svg.outerHTML;
  };

  const visualDetails = (visual, summary = 'Visual representation') => {
    if (!visual || typeof visual !== 'object') return '<p class="visual-unavailable">This analysis has no visual model for the supplied input.</p>';
    const description = typeof visual.description === 'string' && visual.description.trim() !== ''
      ? visual.description
      : 'The analysis returned structured visual data without a description.';
    const svg = typeof visual.svg === 'string' ? sanitizeSvg(visual.svg) : '';
    const preview = svg
      ? `<div class="visual-preview">${svg}</div>`
      : '<p class="visual-unavailable">SVG preview is unavailable; use the structured visual data instead.</p>';
    return `<details class="visual-details" open><summary>${escapeHtml(summary)}</summary><p>${escapeHtml(description)}</p>${preview}</details>`;
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
    if (!parsed.ok) { refreshCapabilityControls(); button.style.opacity = '1'; return; }

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
        const span = Array.isArray(data.span) && data.span.length >= 2 ? `${data.span[0]}–${data.span[1]}` : 'not provided';
        showResult(`<strong>${escapeHtml(data.code || 'evaluation.error')}</strong><p>${escapeHtml(data.message || 'The expression could not be evaluated.')}</p><code>source span: ${escapeHtml(span)}</code>`, 'result-error');
      }
    } catch {
      if (!isCurrentRequest(serial)) return;
      showResult('<strong>Could not reach the evaluator.</strong><span>Check that the local PHP server is running.</span>', 'result-error');
    } finally {
      refreshCapabilityControls();
      button.style.opacity = '1';
    }
  };

  const explain = async () => {
    const serial = beginRequest();
    explainButton.disabled = true;
    explainButton.style.opacity = '.65';
    const parsed = readVariables();
    if (!parsed.ok) { refreshCapabilityControls(); explainButton.style.opacity = '1'; return; }

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
      refreshCapabilityControls();
      explainButton.style.opacity = '1';
    }
  };

  const evaluatePiecewise = async () => {
    const serial = beginRequest();
    piecewiseButton.disabled = true;
    try {
      const parsed = readVariables();
      if (!parsed.ok) return;
      const response = await fetch('?api=piecewise', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ expression: expression.value, variables: parsed.value }) });
      const data = await response.json();
      if (!isCurrentRequest(serial)) return;
      if (!data.ok) { showResult(`<strong>${escapeHtml(data.code)}</strong><p>${escapeHtml(data.message)}</p>`, 'result-error'); return; }
      const piece = data.result;
      showResult(`<div class="explanation-result"><div class="explanation-summary"><span class="result-symbol">⌘</span><div><span class="explanation-label">piecewise · branch ${escapeHtml(piece.branch)}</span><strong>${escapeHtml(piece.value)}</strong><span class="explanation-hint">Selected ${escapeHtml(piece.condition)} → ${escapeHtml(piece.selectedExpression)}</span></div></div><ol class="step-list" aria-label="Piecewise evaluation steps">${piece.steps.map((step) => `<li>${escapeHtml(step)}</li>`).join('')}</ol></div>`, 'result-explanation');
      setEngineMeta('MathPHP Explaining · Piecewise');
    } catch { if (isCurrentRequest(serial)) showResult('<strong>Could not reach the piecewise evaluator.</strong><span>Check that the private explaining package is installed.</span>', 'result-error'); }
    finally { refreshCapabilityControls(); }
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
    finally { refreshCapabilityControls(); }
  };

  const solveNumerically = async () => {
    const serial = beginRequest();
    numericalEquationButton.disabled = true;
    try {
      const minimum = Number(numericalMinimum?.value ?? -10);
      const maximum = Number(numericalMaximum?.value ?? 10);
      const samples = Number(numericalSamples?.value ?? 256);
      const variable = numericalVariable?.value.trim() || 'x';
      const response = await fetch('?api=solve-equation', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ equation: equation.value, variable, minimum, maximum, samples }) });
      const data = await response.json();
      if (!isCurrentRequest(serial)) return;
      if (!data.ok) { showResult(`<strong>${escapeHtml(data.code)}</strong><p>${escapeHtml(data.message)}</p>`, 'result-error'); return; }
      const analysis = data.analysis;
      const roots = Array.isArray(analysis.solutions?.roots) ? analysis.solutions.roots : [];
      const rootText = roots.length ? roots.map((root) => Number(root).toPrecision(12)).join(', ') : 'No certified roots';
      showResult(`<div class="explanation-result"><div class="explanation-summary"><span class="result-symbol">≈</span><div><span class="explanation-label">${escapeHtml(analysis.status)} · sampled bisection</span><strong>${escapeHtml(rootText)}</strong><span class="explanation-hint">${escapeHtml(analysis.summary)}</span></div></div><ol class="step-list" aria-label="Numerical equation analysis">${analysis.steps.map((step) => `<li>${escapeHtml(step)}</li>`).join('')}</ol>${visualDetails(analysis.visual, 'Root samples')}</div>`, 'result-explanation');
      setEngineMeta('MathPHP Explaining · Numerical equations');
    } catch { if (isCurrentRequest(serial)) showResult('<strong>Could not reach the numerical equation analyzer.</strong>', 'result-error'); }
    finally { refreshCapabilityControls(); }
  };

  const solvePiecewise = async () => {
    const serial = beginRequest();
    piecewiseEquationButton.disabled = true;
    try {
      const minimum = Number(numericalMinimum?.value ?? -10);
      const maximum = Number(numericalMaximum?.value ?? 10);
      const samples = Number(numericalSamples?.value ?? 256);
      const variable = numericalVariable?.value.trim() || 'x';
      const parsed = readVariables();
      if (!parsed.ok) return;
      const response = await fetch('?api=piecewise-equation', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ equation: equation.value, variable, minimum, maximum, samples, known: parsed.value }) });
      const data = await response.json();
      if (!isCurrentRequest(serial)) return;
      if (!data.ok) { showResult(`<strong>${escapeHtml(data.code)}</strong><p>${escapeHtml(data.message)}</p>`, 'result-error'); return; }
      const analysis = data.analysis;
      const roots = Array.isArray(analysis.solutions?.roots) ? analysis.solutions.roots : [];
      const rootText = roots.length ? roots.map((root) => Number(root).toPrecision(12)).join(', ') : 'No certified roots';
      showResult(`<div class="explanation-result"><div class="explanation-summary"><span class="result-symbol">≈</span><div><span class="explanation-label">piecewise roots · ${escapeHtml(analysis.status)}</span><strong>${escapeHtml(rootText)}</strong><span class="explanation-hint">${escapeHtml(analysis.summary)}</span></div></div><ol class="step-list" aria-label="Piecewise equation analysis">${analysis.steps.map((step) => `<li>${escapeHtml(step)}</li>`).join('')}</ol>${visualDetails(analysis.visual, 'Branch-safe root samples')}</div>`, 'result-explanation');
      setEngineMeta('MathPHP Explaining · Piecewise equations');
    } catch { if (isCurrentRequest(serial)) showResult('<strong>Could not reach the piecewise equation analyzer.</strong>', 'result-error'); }
    finally { refreshCapabilityControls(); }
  };

  const solveRecurrence = async () => {
    const serial = beginRequest();
    recurrenceButton.disabled = true;
    try {
      let initial;
      try { initial = JSON.parse(recurrenceInitial?.value || '{}'); } catch { showResult('<strong>Initial values must be valid JSON.</strong>', 'result-error'); return; }
      const terms = Number(recurrenceTerms?.value ?? 12);
      const response = await fetch('?api=recurrence', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ recurrence: recurrenceInput?.value || '', initial, terms }) });
      const data = await response.json();
      if (!isCurrentRequest(serial)) return;
      if (!data.ok) { showResult(`<strong>${escapeHtml(data.code)}</strong><p>${escapeHtml(data.message)}</p>`, 'result-error'); return; }
      const analysis = data.analysis;
      const sequence = Object.entries(analysis.sequence || {}).map(([index, value]) => `<strong>${escapeHtml(`${index}: ${value}`)}</strong>`).join(' ');
      showResult(`<div class="explanation-result"><div class="explanation-summary"><span class="result-symbol">→</span><div><span class="explanation-label">recurrence · ${escapeHtml(analysis.status)}</span><strong>${sequence || 'No terms generated'}</strong><span class="explanation-hint">${escapeHtml(analysis.summary)}</span></div></div><ol class="step-list" aria-label="Recurrence expansion steps">${analysis.steps.map((step) => `<li>${escapeHtml(step)}</li>`).join('')}</ol>${visualDetails(analysis.visual, 'Sequence visual')}</div>`, 'result-explanation');
      setEngineMeta('MathPHP Explaining · Recurrences');
    } catch { if (isCurrentRequest(serial)) showResult('<strong>Could not reach the recurrence analyzer.</strong>', 'result-error'); }
    finally { refreshCapabilityControls(); }
  };

  const estimateLimit = async () => {
    const serial = beginRequest();
    limitButton.disabled = true;
    try {
      const parsed = readVariables();
      if (!parsed.ok) return;
      const response = await fetch('?api=limit', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ expression: expression.value, variable: 'x', point: Number(limitPoint?.value ?? 0), direction: limitDirection?.value || 'both', samples: 14, tolerance: 1e-8, known: parsed.value }) });
      const data = await response.json();
      if (!isCurrentRequest(serial)) return;
      if (!data.ok) { showResult(`<strong>${escapeHtml(data.code)}</strong><p>${escapeHtml(data.message)}</p>`, 'result-error'); return; }
      const analysis = data.analysis;
      const limitText = analysis.limit === null || analysis.limit === undefined ? 'No finite estimate certified' : Number(analysis.limit).toPrecision(12);
      const sampleCount = Array.isArray(analysis.samples) ? analysis.samples.length : 0;
      showResult(`<div class="explanation-result"><div class="explanation-summary"><span class="result-symbol">→</span><div><span class="explanation-label">limit · ${escapeHtml(analysis.status)}</span><strong>${escapeHtml(limitText)}</strong><span class="explanation-hint">${escapeHtml(analysis.summary)}</span></div></div><ol class="step-list" aria-label="Limit estimation steps">${analysis.steps.map((step) => `<li>${escapeHtml(step)}</li>`).join('')}</ol><p class="result-footnote">${sampleCount} geometric samples retained; numerical evidence is not a symbolic proof.</p>${visualDetails(analysis.visual, 'Limit approach')}</div>`, 'result-explanation');
      setEngineMeta('MathPHP Explaining · Limits');
    } catch { if (isCurrentRequest(serial)) showResult('<strong>Could not reach the limit analyzer.</strong><span>Check that the private explaining package is installed.</span>', 'result-error'); }
    finally { refreshCapabilityControls(); }
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
      if (!visual || typeof visual !== 'object') {
        showResult('<strong>Plot data is incomplete.</strong><p>The renderer returned no visual model. Try again or inspect the structured response.</p>', 'result-error');
        return;
      }
      const title = typeof visual.title === 'string' && visual.title.trim() !== '' ? visual.title : 'Function plot';
      const description = typeof visual.description === 'string' && visual.description.trim() !== '' ? visual.description : 'Structured plot data is available.';
      const svg = typeof visual.svg === 'string' ? sanitizeSvg(visual.svg) : '';
      const preview = svg ? `<div class="visual-preview">${svg}</div>` : '<p class="visual-unavailable">SVG preview is unavailable; use the structured plot data instead.</p>';
      showResult(`<div class="explanation-result"><div class="explanation-summary"><span class="result-symbol">⌁</span><div><span class="explanation-label">function plot</span><strong>${escapeHtml(title)}</strong><span class="explanation-hint">${escapeHtml(description)}</span></div></div>${preview}</div>`, 'result-explanation');
      setEngineMeta('MathPHP Visuals add-on');
    } catch { if (isCurrentRequest(serial)) showResult('<strong>Could not reach the plotting service.</strong>', 'result-error'); }
    finally { refreshCapabilityControls(); }
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
    finally { refreshCapabilityControls(); }
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
    finally { refreshCapabilityControls(); }
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
    finally { refreshCapabilityControls(); }
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
    finally { refreshCapabilityControls(); }
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
    finally { refreshCapabilityControls(); }
  };

  button.addEventListener('click', run);
  explainButton.addEventListener('click', explain);
  piecewiseButton?.addEventListener('click', evaluatePiecewise);
  analyzeButton.addEventListener('click', analyze);
  numericalEquationButton?.addEventListener('click', solveNumerically);
  piecewiseEquationButton?.addEventListener('click', solvePiecewise);
  recurrenceButton?.addEventListener('click', solveRecurrence);
  limitButton?.addEventListener('click', estimateLimit);
  plotButton.addEventListener('click', plot);
  systemButton.addEventListener('click', analyzeSystem);
  derivativeButton.addEventListener('click', () => calculus('derivative'));
  integralButton.addEventListener('click', () => calculus('integral'));
  areaButton.addEventListener('click', area);
  rootButton.addEventListener('click', findRoot);
  matrixButton.addEventListener('click', analyzeMatrix);
  engine?.addEventListener('change', refreshCapabilityControls);
  expression.addEventListener('input', refreshCapabilityControls);
  root.querySelectorAll('[data-example]').forEach((example) => example.addEventListener('click', () => { expression.value = example.dataset.example; run(); }));
  expression.addEventListener('keydown', (event) => { if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') run(); });
  refreshCapabilityControls();
  loadCapabilities();
})();
