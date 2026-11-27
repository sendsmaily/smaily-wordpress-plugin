import { type WizardState } from './types';

/**
 * The main Smaily account (CON-441, CON-446). The account-per-language mode
 * (Mode A) shows no shared account: once the fallback language account's
 * connection works, it is the main account — the one the Connection save
 * stores as the main account, and the one whose connection opens the setup
 * steps and reads as "Smaily connected". Otherwise the shared account — the
 * only one in the other modes, and in Mode A the one saved and verified
 * earlier.
 */
export function mainAccount(state: WizardState): {
  credentials: WizardState['smailyCredentials'];
  connection: WizardState['smailyConnection'];
} {
  const isModeA = state.multilingualMode === 'A' && state.env.detectedLanguages.length > 1;
  const fallback = isModeA
    ? state.perLanguageAccounts.find((a) => a.accountKey === state.defaultFallbackAccountKey)
    : undefined;
  if (fallback !== undefined && fallback.connection.kind === 'success') {
    return { credentials: fallback.credentials, connection: fallback.connection };
  }
  return { credentials: state.smailyCredentials, connection: state.smailyConnection };
}
