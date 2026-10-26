import { CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

const LINES = [
  { key: 'pageviews', name: 'Pageviews', color: '#2563eb' },
  { key: 'visitors', name: 'Unique visitors', color: '#059669' },
  { key: 'events', name: 'All events', color: '#d97706' },
];

export default function TrendChart({ series }) {
  const dots = series.length <= 31;

  return (
    <div style={{ height: 280 }}>
      <ResponsiveContainer width="100%" height="100%">
        <LineChart data={series} margin={{ top: 8, right: 16, bottom: 0, left: 0 }}>
          <CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" />
          <XAxis dataKey="day" tickFormatter={(d) => d.slice(5)} minTickGap={16} />
          <YAxis allowDecimals={false} />
          <Tooltip />
          <Legend />
          {LINES.map((l) => (
            <Line key={l.key} type="monotone" dataKey={l.key} name={l.name} stroke={l.color} strokeWidth={2} dot={dots} isAnimationActive={false} />
          ))}
        </LineChart>
      </ResponsiveContainer>
    </div>
  );
}
