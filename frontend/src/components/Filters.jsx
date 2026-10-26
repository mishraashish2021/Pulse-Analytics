const PRESETS = [
  { days: 0, label: 'Today' },
  { days: 6, label: '7 days' },
  { days: 29, label: '30 days' },
];

export default function Filters({ range, onPreset, onCustom, projects, project, onProject, onRefresh, loading, error }) {
  return (
    <header>
      <h1>Pulse Analytics</h1>

      {projects.length > 0 && (
        <label>
          Project
          <select value={project} onChange={(e) => onProject(e.target.value)}>
            {projects.map((p) => (
              <option key={p.id} value={p.id}>{p.name}</option>
            ))}
          </select>
        </label>
      )}

      <label>
        From
        <input type="date" value={range.from} max={range.to} onChange={(e) => e.target.value && onCustom({ from: e.target.value })} />
      </label>
      <label>
        To
        <input type="date" value={range.to} min={range.from} onChange={(e) => e.target.value && onCustom({ to: e.target.value })} />
      </label>

      {PRESETS.map((p) => (
        <button key={p.days} className={range.preset === p.days ? 'active' : ''} onClick={() => onPreset(p.days)}>
          {p.label}
        </button>
      ))}
      <button onClick={onRefresh} disabled={loading}>{loading ? 'Loading…' : 'Refresh'}</button>

      {error && <span className="error" role="alert">{error}</span>}
    </header>
  );
}
