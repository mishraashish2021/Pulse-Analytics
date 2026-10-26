import { useEffect, useState } from 'react';
import { fetchStats } from './api.js';
import Filters from './components/Filters.jsx';
import KpiCards from './components/KpiCards.jsx';
import TrendChart from './components/TrendChart.jsx';
import DataTable from './components/DataTable.jsx';

const isoDay = (offsetDays) => new Date(Date.now() - offsetDays * 86400000).toISOString().slice(0, 10);

export default function App() {
  const [range, setRange] = useState({ from: isoDay(6), to: isoDay(0), preset: 6 });
  const [project, setProject] = useState('');
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const [tick, setTick] = useState(0); // bump to refetch

  useEffect(() => {
    const controller = new AbortController(); // ignore stale responses when filters change fast
    setLoading(true);
    setError('');

    fetchStats({ from: range.from, to: range.to, project }, controller.signal)
      .then((d) => {
        setData(d);
        if (!project) setProject(String(d.project.id));
      })
      .catch((e) => {
        if (e.name !== 'AbortError') setError(e.message);
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });

    return () => controller.abort();
  }, [range.from, range.to, project, tick]);

  const applyPreset = (days) => setRange({ from: isoDay(days), to: isoDay(0), preset: days });

  return (
    <>
      <Filters
        range={range}
        onPreset={applyPreset}
        onCustom={(next) => setRange({ ...range, ...next, preset: null })}
        projects={data?.projects ?? []}
        project={project}
        onProject={setProject}
        onRefresh={() => setTick((t) => t + 1)}
        loading={loading}
        error={error}
      />

      <main>
        {data && (
          <>
            <KpiCards totals={data.totals} />

            <section className="card">
              <h2>
                Traffic over time <span className="muted">(visitors are unique per day, UTC)</span>
              </h2>
              <TrendChart series={data.series} />
            </section>

            <div className="grid2">
              <DataTable
                title="Popular pages"
                rows={data.top_pages}
                columns={[
                  { key: 'path', label: 'Path' },
                  { key: 'views', label: 'Views', num: true },
                  { key: 'visitors', label: 'Visitors', num: true },
                ]}
              />
              <DataTable
                title="Top events"
                rows={data.top_events}
                columns={[
                  { key: 'name', label: 'Event' },
                  { key: 'source', label: 'Source', tag: true },
                  { key: 'count', label: 'Count', num: true },
                  { key: 'visitors', label: 'Visitors', num: true },
                ]}
              />
            </div>

            <DataTable
              title="Latest events"
              rows={data.recent}
              columns={[
                { key: 'occurred_at', label: 'Time (UTC)' },
                { key: 'name', label: 'Event' },
                { key: 'path', label: 'Path' },
                { key: 'source', label: 'Source', tag: true },
                { key: 'user_id', label: 'User' },
              ]}
            />
          </>
        )}
        {!data && !error && <p className="muted">Loading…</p>}
      </main>
    </>
  );
}
