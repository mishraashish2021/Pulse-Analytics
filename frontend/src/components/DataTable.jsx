// React escapes all text it renders, so untrusted event names/paths cannot inject HTML.
export default function DataTable({ title, rows, columns }) {
  return (
    <section className="card">
      <h2>{title}</h2>
      <table>
        <thead>
          <tr>
            {columns.map((c) => (
              <th key={c.key} className={c.num ? 'num' : ''}>{c.label}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 && (
            <tr><td className="empty" colSpan={columns.length}>No data in this range</td></tr>
          )}
          {rows.map((row, i) => (
            <tr key={i}>
              {columns.map((c) => {
                const value = row[c.key];
                const text = value === null || value === undefined ? '–' : c.num ? Number(value).toLocaleString() : String(value);
                return (
                  <td key={c.key} className={c.num ? 'num' : ''}>
                    {c.tag ? <span className={`tag ${value}`}>{text}</span> : text}
                  </td>
                );
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  );
}
