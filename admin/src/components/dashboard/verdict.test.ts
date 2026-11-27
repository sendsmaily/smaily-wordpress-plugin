import { describe, expect, it } from 'vitest';

import { dashboardVerdict, LOG_FAILED_URL, type VerdictFacts } from './verdict';

const HEALTHY: VerdictFacts = {
  smailyConnected: true,
  smailyPlanBlocked: false,
  engineKeyRejected: false,
  backgroundRunning: true,
  engineRefused: false,
  failed24h: 0,
  engineDown: false,
  unsentNights: 0,
};

/** CON-464: the Dashboard's one sentence, in Magento's order with PrestaShop's background tasks. */
describe('dashboardVerdict', () => {
  it('reads all systems normal when nothing is wrong, with the way to the Log', () => {
    const verdict = dashboardVerdict(HEALTHY);

    expect(verdict.level).toBe('ok');
    expect(verdict.kicker).toBe('All systems normal');
    expect(verdict.text).toBe('Everything is running — deliveries to Smaily are flowing normally.');
    expect(verdict.action).toEqual({ label: 'View full log', href: 'admin.php?page=smaily-connect-log' });
  });

  it('puts a Smaily connection that does not work above everything else', () => {
    const verdict = dashboardVerdict({
      ...HEALTHY,
      smailyConnected: false,
      backgroundRunning: false,
      engineRefused: true,
      failed24h: 4,
    });

    expect(verdict.level).toBe('disconnected');
    expect(verdict.kicker).toBe('Not connected');
    expect(verdict.text).toMatch(/^The Smaily credentials are missing or Smaily has not accepted them/);
    expect(verdict.action?.href).toBe('admin.php?page=smaily-connect-settings#connection');
  });

  it('names a package without API access as the reason Smaily is not connected', () => {
    const verdict = dashboardVerdict({ ...HEALTHY, smailyConnected: false, smailyPlanBlocked: true });

    expect(verdict.text).toMatch(/^Smaily is refusing every request because this account's package/);
  });

  it('puts a rejected Campaign Intelligence key right after the Smaily connection, with the way to reconnect', () => {
    const verdict = dashboardVerdict({ ...HEALTHY, engineKeyRejected: true, backgroundRunning: false, failed24h: 3 });

    expect(verdict.level).toBe('degraded');
    expect(verdict.text).toBe(
      'Campaign Intelligence rejected the stored API key. The key was revoked or rotated. Disconnect and reconnect with a fresh setup link.',
    );
    expect(verdict.action).toEqual({ label: 'Open Settings', href: 'admin.php?page=smaily-connect-settings#recommendations' });
    expect(dashboardVerdict({ ...HEALTHY, engineKeyRejected: true, smailyConnected: false }).level).toBe('disconnected');
  });

  it('puts background tasks that do not run above failures', () => {
    const verdict = dashboardVerdict({ ...HEALTHY, backgroundRunning: false, failed24h: 2 });

    expect(verdict.level).toBe('degraded');
    expect(verdict.text).toBe('Background tasks are not running — nothing goes to Smaily until they run again.');
    expect(verdict.action).toBeNull();
  });

  it('puts a Campaign Intelligence account that is not active above failures', () => {
    const verdict = dashboardVerdict({ ...HEALTHY, engineRefused: true, failed24h: 2 });

    expect(verdict.text).toMatch(/^Campaign Intelligence has stopped accepting data from this store/);
  });

  it('counts the failures in one or many deliveries and leads to the failed rows', () => {
    expect(dashboardVerdict({ ...HEALTHY, failed24h: 1, engineDown: true }).text).toBe(
      'Smaily Connect is running, but 1 delivery failed in the last 24 hours.',
    );
    const verdict = dashboardVerdict({ ...HEALTHY, failed24h: 3 });
    expect(verdict.text).toBe('Smaily Connect is running, but 3 deliveries failed in the last 24 hours.');
    expect(verdict.action).toEqual({ label: 'Review failures', href: LOG_FAILED_URL, danger: true });
    expect(LOG_FAILED_URL).toBe('admin.php?page=smaily-connect-log&status=failed');
  });

  it('puts an unreachable Campaign Intelligence above a missing product list', () => {
    expect(dashboardVerdict({ ...HEALTHY, engineDown: true, unsentNights: 4 }).text).toMatch(
      /Campaign Intelligence is currently unreachable/,
    );
    expect(dashboardVerdict({ ...HEALTHY, unsentNights: 4 }).text).toBe(
      'Smaily Connect is running, but no nightly product list has gone to Campaign Intelligence for 4 nights.',
    );
  });
});
