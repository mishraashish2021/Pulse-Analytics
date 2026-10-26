const fmt = (n) => Number(n).toLocaleString();

export default function KpiCards({ totals }) {
  const items = [
    ['Pageviews', totals.pageviews],
    ['Unique visitors', totals.visitors],
    ['Sessions', totals.sessions],
    ['Events (all)', totals.events],
  ];

  return (
    <section className="kpis">
      {items.map(([label, value]) => (
        <div className="card kpi" key={label}>
          <div className="label">{label}</div>
          <div className="value">{fmt(value)}</div>
        </div>
      ))}
    </section>
  );
}
