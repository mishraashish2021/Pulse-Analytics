// Same-origin request: Vite proxies it to Laravel in dev; in production the built files are
// served by Laravel itself. The browser handles the basic-auth prompt and re-sends credentials.
export async function fetchStats({ from, to, project }, signal) {
  const params = new URLSearchParams({ from, to });
  if (project) params.set('project', project);

  const res = await fetch(`/dashboard/stats?${params}`, {
    headers: { Accept: 'application/json' },
    credentials: 'same-origin',
    signal,
  });

  if (!res.ok) {
    const body = await res.json().catch(() => ({}));
    const message =
      body.error ||
      (body.errors ? Object.values(body.errors)[0][0] : null) ||
      `Request failed (${res.status})`;
    throw new Error(message);
  }
  return res.json();
}
