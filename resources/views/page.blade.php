<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pipelines</title>
    {{-- Inline, because a package cannot assume a host app's asset pipeline. --}}
    <style>
        :root { color-scheme: light dark; --line: #8883; --muted: #8888; }
        body { margin: 0; padding: 2rem; font: 14px/1.5 ui-monospace, SFMono-Regular, Menlo, monospace; }
        h1 { font-size: 1rem; margin: 0 0 1.5rem; }
        h2 { font-size: .95rem; margin: 0 0 .25rem; }
        section { border: 1px solid var(--line); border-radius: 6px; padding: 1rem; margin-bottom: 1rem; }
        dl { display: grid; grid-template-columns: max-content 1fr; gap: .1rem .75rem; margin: .5rem 0 1rem; }
        dt { color: var(--muted); }
        dd { margin: 0; }
        ol { list-style: none; margin: 0; padding: 0; }
        li { border-top: 1px solid var(--line); padding: .35rem 0; }
        .position { display: flex; gap: .5rem; align-items: baseline; flex-wrap: wrap; }
        .verdict { font-size: .8rem; padding: 0 .4rem; border: 1px solid var(--line); border-radius: 3px; }
        .muted { color: var(--muted); }
        button { font: inherit; cursor: pointer; background: none; border: 1px solid var(--line); border-radius: 3px; }
        pre { white-space: pre-wrap; word-break: break-word; overflow-x: auto; background: #8881; padding: .5rem; margin: .35rem 0 0; }
        [hidden] { display: none !important; }
    </style>
</head>
<body>
<h1>Pipelines</h1>

@if ($error !== null)
    <section data-config-error>
        <h2>The pipeline config could not be read</h2>
        <p>{{ $error }}</p>
    </section>
@endif

@forelse ($pipelines as $pipeline)
    <section data-pipeline="{{ $pipeline['pipeline'] }}">
        <h2>{{ $pipeline['pipeline'] }}</h2>
        <div data-body>
            @include('boost-pipeline::partials.body', ['pipeline' => $pipeline])
        </div>
    </section>
@empty
    @if ($error === null)
        <p class="muted">No pipeline is declared.</p>
    @endif
@endforelse

<script>
    (() => {
        const endpoint = @json($dataUrl);
        const logTemplate = @json($logUrlTemplate);
        const placeholder = @json($urlPlaceholder);

        // The template comes from the named route, so the page never rebuilds a
        // path from configuration the provider already normalised.
        //
        // Substituted last placeholder first, and the order matters. A run id is
        // caller-supplied and survives encodeURIComponent intact, so one holding
        // the step placeholder would be hit by the step substitution if run went
        // in first. Going backwards, an injected placeholder can only land after
        // the real one it imitates, and replace() takes the first occurrence.
        const logEndpoint = (pipeline, run, step) => logTemplate
            .replace(placeholder + 'S', encodeURIComponent(step))
            .replace(placeholder + 'R', encodeURIComponent(run))
            .replace(placeholder + 'P', encodeURIComponent(pipeline));

        // Every value below is command output or a path the server produced.
        // It is written with textContent, never innerHTML: a failing assertion
        // or a test name can carry markup, and the local and loopback gates do
        // not make rendering it as markup safe.
        const el = (tag, className, text) => {
            const node = document.createElement(tag);
            if (className) node.className = className;
            if (text !== undefined && text !== null) node.textContent = String(text);
            return node;
        };

        const facts = (pipeline) => {
            const current = pipeline.current;
            const live = pipeline.live;
            const dl = el('dl');
            const row = (label, value) => {
                dl.append(el('dt', null, label), el('dd', null, value));
            };

            if (live) {
                row('now', live.interrupted
                    ? 'interrupted — ' + live.steps.join(', ')
                    : live.state + ' — ' + live.steps.join(', '));
            }

            if (!current) {
                row('run', 'nothing recorded');
                return dl;
            }

            row('run', current.run);
            row('state', current.state);
            row('verified', current.all_verified ? 'yes' : 'no');
            if (current.scope) row('scope', current.scope);
            if (current.config_matches === false) {
                row('config', 'the run walked a different declaration than this config produces now');
            }

            if (current.tree_matches !== null) {
                row('tree', current.tree_matches ? 'matches the code on disk' : 'moved since the run');
            }
            if (current.stale) row('stale', current.stale);
            return dl;
        };

        const positions = (pipeline) => {
            const list = el('ol');
            const current = pipeline.current;
            if (!current) return list;

            for (const position of current.positions) {
                const item = el('li');
                for (const step of position.steps) {
                    const line = el('div', 'position');
                    line.append(el('span', null, step.id));
                    line.append(el('span', 'verdict', step.verdict ?? 'not run'));
                    if (position.parallel) line.append(el('span', 'muted', 'parallel'));
                    line.append(el('span', 'muted', step.phase));
                    let panel = null;

                    if (step.log) {
                        const [button, output] = logToggle(pipeline.pipeline, current.run, step.id);
                        line.append(button);
                        panel = output;
                    }

                    item.append(line);

                    if (panel) item.append(panel);
                }
                list.append(item);
            }

            for (const step of current.undeclared) {
                const item = el('li');
                const line = el('div', 'position');
                line.append(el('span', null, step.id));
                line.append(el('span', 'verdict', step.verdict));
                line.append(el('span', 'muted', 'no longer declared'));
                item.append(line);
                list.append(item);
            }

            return list;
        };

        // Which logs the reader has opened, so a re-render restores them. The
        // poll replaces the DOM, and without this an opened log closed itself
        // within one tick.
        const openLogs = new Set();

        const fillLog = async (output, pipeline, run, step) => {
            output.textContent = 'loading…';
            try {
                const response = await fetch(logEndpoint(pipeline, run, step));
                const body = await response.json();
                output.textContent = response.ok
                    ? body.summary + (body.truncated ? '\n\n… truncated' : '')
                    : body.message;
            } catch (error) {
                output.textContent = 'The log could not be read.';
            }
        };

        // Returns the button and the panel it toggles. The caller appends the
        // panel after the step line, so the log reads below its step.
        const logToggle = (pipeline, run, step) => {
            const key = [pipeline, run, step].join('\u0000');
            const button = el('button', null, 'log');
            const output = el('pre');
            output.hidden = !openLogs.has(key);

            if (!output.hidden) fillLog(output, pipeline, run, step);

            button.addEventListener('click', () => {
                if (openLogs.delete(key)) {
                    output.hidden = true;
                    return;
                }
                openLogs.add(key);
                output.hidden = false;
                fillLog(output, pipeline, run, step);
            });

            return [button, output];
        };

        const history = (pipeline) => {
            const list = el('ol');
            for (const run of pipeline.history) {
                const verdicts = Object.entries(run.verdicts)
                    .map(([verdict, count]) => count + ' ' + verdict)
                    .join(', ');
                const item = el('li');
                const line = el('div', 'position');
                line.append(el('span', null, run.run));
                line.append(el('span', 'muted', run.state));
                line.append(el('span', 'muted', verdicts || 'no verdicts'));
                // Only when it moved. A row that says nothing about the config is
                // read as ordinary, and this run is the one the gate refuses.
                if (run.config_matches === false) {
                    line.append(el('span', 'muted', 'config moved'));
                }
                line.append(el('span', 'muted', run.recorded_at));
                item.append(line);
                list.append(item);
            }
            return list;
        };

        const render = (payload) => {
            for (const pipeline of payload.pipelines) {
                const section = document.querySelector('[data-pipeline="' + CSS.escape(pipeline.pipeline) + '"] [data-body]');
                if (!section) continue;
                section.replaceChildren(
                    facts(pipeline),
                    el('h3', 'muted', 'steps'),
                    positions(pipeline),
                    el('h3', 'muted', 'history'),
                    history(pipeline),
                );
            }
        };

        let lastPayload = null;

        const poll = async () => {
            try {
                const response = await fetch(endpoint, { headers: { Accept: 'application/json' } });
                if (!response.ok) return;
                const text = await response.text();

                // Nothing changed, so nothing is rebuilt. A walk spends most of
                // its time in one position, and re-rendering an identical page
                // twice a second only costs the reader their scroll and their
                // selection.
                if (text === lastPayload) return;

                lastPayload = text;
                render(JSON.parse(text));
            } catch (error) {
                // A poll that fails leaves the last render in place. The server
                // being briefly unreachable is not news worth blanking the page for.
            }
        };

        poll();
        setInterval(poll, 2000);
    })();
</script>
</body>
</html>
