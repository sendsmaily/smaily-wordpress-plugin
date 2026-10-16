/**
 * Render a server-emitted UTC datetime ("2026-05-21 10:42:17") in the
 * merchant's local timezone — the backfill panels' "Last run" hint and the
 * transactional confirmations' "Last sent" line. We deliberately avoid Intl
 * options the older Node ICU builds in CI choke on — toLocaleString with no
 * arguments is the broadest path. An unparsable value is returned as is.
 */
export function formatUtc(utcDatetime: string): string {
  const iso = utcDatetime.replace(' ', 'T') + 'Z';
  const date = new Date(iso);
  if (isNaN(date.getTime())) {
    return utcDatetime;
  }
  return date.toLocaleString();
}
